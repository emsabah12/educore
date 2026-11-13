<?php

namespace Modules\HR\Application\Attendance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Attendance\Exceptions\AttendanceException;

/**
 * Titik lokasi absen per lembaga & pemeriksaan lokasi absen mandiri (PRD-001 §14.6, HR-4d; OD-HR-22, OD-HR-23).
 *
 * - Titik: lintang/bujur 6 desimal + radius 50–1.000 m, diatur Admin SDM / Admin Yayasan (dicek pemanggil).
 * - Pemeriksaan: jarak haversine dari posisi HP ke titik semua lembaga penempatan berjalan pegawai; yang dipakai
 *   lembaga yang paling memenuhi (selisih jarak − radius terkecil). Hasil DI_DALAM (jarak ≤ radius) / DI_LUAR / TIDAK_TERSEDIA. Koordinat HP tidak disimpan & tidak dicatat.
 * - Aturan `hr.attendance_location` lembaga penempatan utama: TIDAK → tidak diperiksa; CATAT → hasil dicatat;
 *   WAJIB → di luar radius, akurasi > 100 m, lokasi tidak tersedia, atau titik lembaga belum diatur = ditolak.
 *
 * @phpstan-type LocationCheck array{result: string, distance: int|null, accuracy: int|null, organization_id: string|null}
 * @phpstan-type LocationRow array{organization_id: string, organization: string, code: string, latitude: float|null, longitude: float|null, radius: int|null}
 */
final class WorkLocations
{
    public const RADIUS_MIN = 50;

    public const RADIUS_MAX = 1000;

    public const RADIUS_DEFAULT = 150;

    /** Akurasi HP (meter) terburuk yang masih dianggap cukup [ASUMSI]. */
    public const ACCURACY_MAX = 100;

    public const INSIDE = 'DI_DALAM';

    public const OUTSIDE = 'DI_LUAR';

    public const UNAVAILABLE = 'TIDAK_TERSEDIA';

    private const EARTH_RADIUS_METERS = 6371000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttendanceLocationSetting $setting,
    ) {}

    /**
     * Semua lembaga aktif beserta titiknya (null bila belum diatur), urut kode.
     *
     * @return list<LocationRow>
     */
    public function all(string $tenantId): array
    {
        $rows = [];

        foreach (DB::table('organizations as o')
            ->leftJoin('hr_work_locations as l', 'l.organization_id', '=', 'o.id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.status', OrganizationStatus::Active->value)
            ->orderBy('o.code')
            ->get(['o.id', 'o.name', 'o.code', 'l.latitude', 'l.longitude', 'l.radius']) as $row) {
            $rows[] = [
                'organization_id' => (string) $row->id,
                'organization' => (string) $row->name,
                'code' => (string) $row->code,
                'latitude' => $row->latitude === null ? null : (float) $row->latitude,
                'longitude' => $row->longitude === null ? null : (float) $row->longitude,
                'radius' => $row->radius === null ? null : (int) $row->radius,
            ];
        }

        return $rows;
    }

    /**
     * @throws AttendanceException
     */
    public function set(string $tenantId, string $organizationId, string $latitude, string $longitude, string $radius, string $membershipId): void
    {
        if (! Str::isUuid($organizationId) || DB::table('organizations')->where('tenant_id', $tenantId)->where('id', $organizationId)->where('status', OrganizationStatus::Active->value)->doesntExist()) {
            throw AttendanceException::make('organization_id', 'Pilih lembaga yang aktif.');
        }

        $lat = self::coordinate($latitude, 90);
        $lng = self::coordinate($longitude, 180);

        if ($lat === null) {
            throw AttendanceException::make('latitude', 'Garis lintang harus angka -90 s.d. 90, mis. -6.914744.');
        }

        if ($lng === null) {
            throw AttendanceException::make('longitude', 'Garis bujur harus angka -180 s.d. 180, mis. 107.609810.');
        }

        if (preg_match('/^\d{1,4}$/', trim($radius)) !== 1 || (int) $radius < self::RADIUS_MIN || (int) $radius > self::RADIUS_MAX) {
            throw AttendanceException::make('radius', 'Radius '.self::RADIUS_MIN.'–'.self::RADIUS_MAX.' meter.');
        }

        DB::transaction(function () use ($tenantId, $organizationId, $lat, $lng, $radius, $membershipId): void {
            DB::table('hr_work_locations')->upsert([[
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'latitude' => $lat,
                'longitude' => $lng,
                'radius' => (int) $radius,
                'updated_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['tenant_id', 'organization_id'], ['latitude', 'longitude', 'radius', 'updated_by_membership_id', 'updated_at']);

            $this->audit->record(
                HrAuditAction::WORK_LOCATION_SET,
                HrAuditAction::SUBJECT_WORK_LOCATION,
                $organizationId,
                ['lembaga' => $organizationId, 'radius' => (int) $radius],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );
        });
    }

    public function remove(string $tenantId, string $organizationId): void
    {
        DB::transaction(function () use ($tenantId, $organizationId): void {
            $deleted = Str::isUuid($organizationId)
                ? DB::table('hr_work_locations')->where('tenant_id', $tenantId)->where('organization_id', $organizationId)->delete()
                : 0;

            if ($deleted > 0) {
                $this->audit->record(HrAuditAction::WORK_LOCATION_REMOVED, HrAuditAction::SUBJECT_WORK_LOCATION, $organizationId, ['lembaga' => $organizationId], organizationId: $organizationId, tenantId: $tenantId);
            }
        });
    }

    /**
     * Aturan lokasi untuk lembaga penempatan utama pegawai.
     */
    public function mode(string $tenantId, string $primaryOrganizationId): string
    {
        return $this->setting->for($tenantId, $primaryOrganizationId);
    }

    /**
     * Memeriksa posisi HP untuk absen mandiri. Null bila aturan "Tidak memakai lokasi".
     *
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy?: mixed}  $position  posisi dari browser (boleh kosong)
     * @return LocationCheck|null
     *
     * @throws AttendanceException bila aturan WAJIB tidak terpenuhi
     */
    public function check(string $tenantId, string $employeeId, string $primaryOrganizationId, array $position): ?array
    {
        $mode = $this->mode($tenantId, $primaryOrganizationId);

        if ($mode === AttendanceLocationSetting::OFF) {
            return null;
        }

        $required = $mode === AttendanceLocationSetting::REQUIRED;
        $lat = self::coordinate(is_scalar($position['latitude'] ?? null) ? (string) $position['latitude'] : '', 90);
        $lng = self::coordinate(is_scalar($position['longitude'] ?? null) ? (string) $position['longitude'] : '', 180);
        $accuracyRaw = $position['accuracy'] ?? null;
        $accuracy = is_numeric($accuracyRaw) && (float) $accuracyRaw >= 0 ? (int) round((float) $accuracyRaw) : null;

        if ($lat === null || $lng === null) {
            if ($required) {
                throw AttendanceException::make('location', 'Lokasi HP tidak terbaca. Izinkan akses lokasi di browser lalu coba lagi, atau minta petugas presensi mencatatkan.');
            }

            return ['result' => self::UNAVAILABLE, 'distance' => null, 'accuracy' => null, 'organization_id' => null];
        }

        $points = DB::table('hr_work_locations as l')
            ->join('hr_placements as p', 'p.organization_id', '=', 'l.organization_id')
            ->join('organizations as o', 'o.id', '=', 'l.organization_id')
            ->where('l.tenant_id', $tenantId)
            ->where('p.employee_id', $employeeId)
            ->whereNull('p.end_date')
            ->distinct()
            ->get(['l.organization_id', 'l.latitude', 'l.longitude', 'l.radius', 'o.name']);

        if ($points->isEmpty()) {
            if ($required) {
                throw AttendanceException::make('location', 'Titik lokasi lembaga Anda belum diatur. Hubungi Admin SDM.');
            }

            return ['result' => self::UNAVAILABLE, 'distance' => null, 'accuracy' => $accuracy, 'organization_id' => null];
        }

        $nearest = null;

        foreach ($points as $point) {
            $distance = (int) round(self::distance($lat, $lng, (float) $point->latitude, (float) $point->longitude));

            if ($nearest === null || $distance - (int) $point->radius < $nearest['distance'] - $nearest['radius']) {
                $nearest = ['distance' => $distance, 'radius' => (int) $point->radius, 'organization_id' => (string) $point->organization_id, 'name' => (string) $point->name];
            }
        }

        $inside = $nearest['distance'] <= $nearest['radius'];

        if ($required) {
            if ($accuracy === null || $accuracy > self::ACCURACY_MAX) {
                throw AttendanceException::make('location', 'Sinyal lokasi kurang akurat'.($accuracy === null ? '' : " (±{$accuracy} m)").'. Coba di tempat terbuka atau nyalakan GPS, lalu ulangi.');
            }

            if (! $inside) {
                throw AttendanceException::make('location', "Anda berada sekitar {$nearest['distance']} m dari {$nearest['name']}, di luar radius {$nearest['radius']} m. Mendekatlah, atau minta petugas presensi mencatatkan (mis. dinas luar).");
            }
        }

        return [
            'result' => $inside ? self::INSIDE : self::OUTSIDE,
            'distance' => $nearest['distance'],
            'accuracy' => $accuracy,
            'organization_id' => $nearest['organization_id'],
        ];
    }

    /**
     * Jarak dua titik di permukaan bumi (meter), rumus haversine.
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }

    private static function coordinate(string $value, int $limit): ?float
    {
        $value = str_replace(',', '.', trim($value));

        if (preg_match('/^-?\d{1,3}(\.\d+)?$/', $value) !== 1 || abs((float) $value) > $limit) {
            return null;
        }

        return round((float) $value, 6);
    }
}
