<?php

namespace Modules\Core\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Domain\Tenancy\TenantContext;
use stdClass;

/**
 * Halaman baca-saja log audit untuk Admin Yayasan (OD-17).
 *
 * Butuh `core.audit.view` di tingkat Yayasan (role tenant-wide, lembaga kerja Seluruh Yayasan),
 * karena log mencakup seluruh yayasan. Yang ditampilkan:
 * - log milik yayasan aktif, dan
 * - log keamanan akun (login, kata sandi, 2FA) milik anggota yayasan ini.
 */
final class AuditLogController
{
    /** Batas baris per halaman (PRD-000 §9: maks 100). */
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorization->inspectTarget(CoreAccess::AUDIT_VIEW, null, null)->authorize();

        $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'pelaku' => ['nullable', 'uuid'],
            // Format kode aksi, bukan daftar tetap: aksi dari modul lain (mis. hr.*) juga bisa difilter.
            'aksi' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
        ], [], [
            'dari' => 'tanggal mulai',
            'sampai' => 'tanggal akhir',
            'pelaku' => 'pelaku',
            'aksi' => 'jenis aksi',
        ]);

        $tenantId = $this->tenantContext->require()->tenantId;
        $from = $request->string('dari')->value();
        $until = $request->string('sampai')->value();
        $actorId = $request->string('pelaku')->value();
        $action = $request->string('aksi')->value();

        $logs = $this->visibleLogs($tenantId)
            ->leftJoin('users as actor', 'actor.id', '=', 'audit.actor_user_id')
            ->leftJoin('persons as actor_person', 'actor_person.id', '=', 'actor.person_id')
            ->leftJoin('organizations as org', 'org.id', '=', 'audit.organization_id')
            ->leftJoin('users as subject_user', function (JoinClause $join): void {
                $join->on(DB::raw('subject_user.id::text'), '=', 'audit.subject_id')
                    ->where('audit.subject_type', '=', AuditLogger::SUBJECT_USER);
            })
            ->leftJoin('persons as subject_person', 'subject_person.id', '=', 'subject_user.person_id')
            ->when($from !== '', fn (Builder $query) => $query->where('audit.created_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($until !== '', fn (Builder $query) => $query->where('audit.created_at', '<=', CarbonImmutable::parse($until)->endOfDay()))
            ->when($actorId !== '', fn (Builder $query) => $query->where('audit.actor_user_id', $actorId))
            ->when($action !== '', fn (Builder $query) => $query->where('audit.action', $action))
            ->orderByDesc('audit.created_at')
            ->orderByDesc('audit.id')
            ->select([
                'audit.id',
                'audit.created_at',
                'audit.action',
                'audit.subject_type',
                'audit.changes',
                'audit.ip',
                'actor_person.name as actor_name',
                'actor.username as actor_username',
                'org.name as organization_name',
                'subject_person.name as subject_user_name',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('audit/index', [
            'logs' => [
                'data' => array_values(array_map(fn (mixed $row): array => $this->present((object) $row), $logs->items())),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'prev_page_url' => $logs->previousPageUrl(),
                'next_page_url' => $logs->nextPageUrl(),
            ],
            'filters' => [
                'dari' => $from,
                'sampai' => $until,
                'pelaku' => $actorId,
                'aksi' => $action,
            ],
            'actions' => collect(AuditAction::labels())
                ->except(AuditAction::PLATFORM_ONLY)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'actors' => $this->actorOptions($tenantId),
        ]);
    }

    /**
     * Log yang boleh dilihat admin yayasan ini.
     */
    private function visibleLogs(string $tenantId): Builder
    {
        $memberUserIds = DB::table('users')
            ->join('memberships', 'memberships.person_id', '=', 'users.person_id')
            ->where('memberships.tenant_id', $tenantId)
            ->selectRaw('users.id::text');

        return DB::table('audit_logs as audit')
            ->where(function (Builder $query) use ($tenantId, $memberUserIds): void {
                $query->where('audit.tenant_id', $tenantId)
                    ->orWhere(function (Builder $global) use ($memberUserIds): void {
                        $global->whereNull('audit.tenant_id')
                            ->where('audit.subject_type', AuditLogger::SUBJECT_USER)
                            ->whereIn('audit.subject_id', $memberUserIds);
                    });
            });
    }

    /**
     * Daftar pelaku yang pernah muncul di log yayasan ini, untuk filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function actorOptions(string $tenantId): array
    {
        return $this->visibleLogs($tenantId)
            ->join('users as actor', 'actor.id', '=', 'audit.actor_user_id')
            ->join('persons as actor_person', 'actor_person.id', '=', 'actor.person_id')
            ->distinct()
            ->orderBy('actor_person.name')
            ->get(['actor.id', 'actor_person.name', 'actor.username'])
            ->map(fn (stdClass $row): array => [
                'value' => (string) $row->id,
                'label' => $row->username === null ? (string) $row->name : "{$row->name} ({$row->username})",
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(stdClass $row): array
    {
        $changes = is_string($row->changes) ? json_decode($row->changes, true) : null;

        return [
            'id' => (string) $row->id,
            'created_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            'action' => (string) $row->action,
            'action_label' => AuditAction::label((string) $row->action),
            'actor' => $row->actor_name === null ? null : [
                'name' => (string) $row->actor_name,
                'username' => $row->actor_username === null ? null : (string) $row->actor_username,
            ],
            'organization' => $row->organization_name === null ? null : (string) $row->organization_name,
            'subject_user' => $row->subject_user_name === null ? null : (string) $row->subject_user_name,
            'changes' => is_array($changes) ? $changes : null,
            'ip' => $row->ip === null ? null : (string) $row->ip,
        ];
    }
}
