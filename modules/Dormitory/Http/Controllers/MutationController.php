<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Mutation\ManageMutations;
use Modules\Dormitory\Application\Mutation\MutationDirectory;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Mutation\EmergencyCategory;
use Modules\Dormitory\Domain\Mutation\Mutation;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Mutasi kamar (PRD-008 §18). Hak akses (§18.2 butir 3): pengaju, penyetuju, dan pelaku darurat butuh izinnya di
 * pemilik asrama asal DAN tujuan; wali kamar aktif kamar asal boleh mengajukan mutasi biasa ke asrama dengan
 * pemilik yang sama. Di luar cakupan → 404; dalam cakupan tanpa izin → 403. Aturan isi ada di ManageMutations.
 */
final class MutationController
{
    use HandlesFacilityActions;

    private const TABS = ['menunggu', 'darurat', 'riwayat'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly SupervisorDirectory $supervisors,
    ) {}

    public function index(Request $request, MutationDirectory $directory): Response
    {
        $nodes = $this->mutationNodes();

        if ($nodes === []) {
            abort(403);
        }

        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'menunggu';
        $tenantId = $this->tenantId();
        $actorId = $this->actorId();
        $page = $directory->page($tenantId, $nodes, $tab);

        $rows = [];

        foreach ($page['data'] as $row) {
            $approver = $this->allowsBoth(DormitoryAccess::MUTATIONS_APPROVE, $row['organization_id'], $row['to_organization_id']);
            $open = $row['status'] === 'DIAJUKAN';
            $canApprove = $open && $approver && $row['requested_by'] !== $actorId;
            $rows[] = $row + [
                'can_approve' => $canApprove,
                'can_reject' => $open && $approver,
                'can_cancel' => $open && $row['requested_by'] === $actorId,
                'can_review' => $row['type'] === 'DARURAT' && ! $row['reviewed'] && $approver && $row['requested_by'] !== $actorId,
                'resources' => $canApprove ? $directory->targetResources($row['to_room_id']) : null,
            ];
        }

        return Inertia::render('dormitory/mutations', [
            'tab' => $tab,
            'mutations' => array_merge($page, ['data' => $rows]),
            'counts' => $directory->counts($tenantId, $this->authorization->visibleNodeIds(DormitoryAccess::MUTATIONS_APPROVE)),
            'options' => ['noteMax' => ManageMutations::NOTE_MAX_LENGTH],
        ]);
    }

    /**
     * Pilihan kamar tujuan (JSON) untuk pop-up Mutasi. `jenis=darurat` memakai cakupan izin darurat.
     */
    public function targets(Request $request, string $placement, MutationDirectory $directory): JsonResponse
    {
        $target = $this->placement($placement);
        $emergency = $request->query('jenis') === 'darurat';

        if ($emergency) {
            $this->authorizeAt(DormitoryAccess::MUTATIONS_EMERGENCY, $target->organization_id);
            $owners = $this->authorization->visibleNodeIds(DormitoryAccess::MUTATIONS_EMERGENCY);
        } elseif ($this->authorization->allows(DormitoryAccess::MUTATIONS_REQUEST, $target->organization_id)) {
            $owners = $this->authorization->visibleNodeIds(DormitoryAccess::MUTATIONS_REQUEST);
        } elseif ($this->supervises($target)) {
            $owners = [$target->organization_id];
        } else {
            $this->authorizeAt(DormitoryAccess::MUTATIONS_REQUEST, $target->organization_id);
            $owners = [];
        }

        return response()->json(['targets' => $directory->targets($this->tenantId(), $target, $owners)]);
    }

    public function store(Request $request, string $placement, ManageMutations $mutations): RedirectResponse
    {
        $target = $this->placement($placement);
        $data = $this->validateMove($request);
        $toRoom = $this->toRoom($data['to_room_id']);

        $byPermission = $this->authorization->allows(DormitoryAccess::MUTATIONS_REQUEST, $target->organization_id);

        if ($byPermission) {
            $this->authorizeAt(DormitoryAccess::MUTATIONS_REQUEST, $toRoom->organization_id, 'to_room_id');
        } elseif ($this->supervises($target)) {
            if ($toRoom->organization_id !== $target->organization_id) {
                abort(403, 'Wali kamar hanya bisa mengajukan mutasi ke asrama dengan pemilik yang sama.');
            }
        } else {
            $this->authorizeAt(DormitoryAccess::MUTATIONS_REQUEST, $target->organization_id);
        }

        $this->attempt(fn (): Mutation => $mutations->request($this->tenantId(), $target->id, $toRoom->id, $data['reason'], $this->actorId()));

        return $this->done('Mutasi diajukan. Menunggu persetujuan Kepala Asrama.');
    }

    public function emergency(Request $request, string $placement, ManageMutations $mutations): RedirectResponse
    {
        $target = $this->placement($placement);
        $this->authorizeAt(DormitoryAccess::MUTATIONS_EMERGENCY, $target->organization_id);
        $data = $this->validateMove($request);
        $toRoom = $this->toRoom($data['to_room_id']);
        $this->authorizeAt(DormitoryAccess::MUTATIONS_EMERGENCY, $toRoom->organization_id, 'to_room_id');

        $category = EmergencyCategory::tryFrom((string) $request->input('emergency_category'));

        $this->attempt(fn (): Mutation => $mutations->emergency($this->tenantId(), $target->id, $toRoom->id, $category, $data['reason'], $this->actorId()));

        return $this->done('Mutasi darurat dilaksanakan dan ditandai untuk ditinjau.');
    }

    public function approve(Request $request, string $mutation, ManageMutations $mutations): RedirectResponse
    {
        $target = $this->mutation($mutation, DormitoryAccess::MUTATIONS_APPROVE);
        $request->validate(['bed_id' => ['nullable', 'uuid'], 'locker_id' => ['nullable', 'uuid']]);

        $this->attempt(fn (): Mutation => $mutations->approve(
            $this->tenantId(),
            $target->id,
            self::optional($request->input('bed_id')),
            self::optional($request->input('locker_id')),
            $this->actorId(),
        ));

        return $this->done('Mutasi disetujui dan dilaksanakan.');
    }

    public function reject(Request $request, string $mutation, ManageMutations $mutations): RedirectResponse
    {
        $target = $this->mutation($mutation, DormitoryAccess::MUTATIONS_APPROVE);
        $request->validate(['reason' => ['nullable', 'string', 'max:'.ManageMutations::REASON_MAX_LENGTH]]);

        $this->attempt(fn (): Mutation => $mutations->reject($this->tenantId(), $target->id, self::optional($request->input('reason')), $this->actorId()));

        return $this->done('Pengajuan mutasi ditolak.');
    }

    public function cancel(string $mutation, ManageMutations $mutations): RedirectResponse
    {
        $target = Str::isUuid($mutation) ? Mutation::query()->find($mutation) : null;

        if (! $target instanceof Mutation || $target->requested_by !== $this->actorId()) {
            abort(404);
        }

        $this->attempt(fn (): Mutation => $mutations->cancel($this->tenantId(), $target->id, $this->actorId()));

        return $this->done('Pengajuan mutasi ditarik.');
    }

    public function review(Request $request, string $mutation, ManageMutations $mutations): RedirectResponse
    {
        $target = $this->mutation($mutation, DormitoryAccess::MUTATIONS_APPROVE);
        $request->validate(['note' => ['nullable', 'string', 'max:'.ManageMutations::NOTE_MAX_LENGTH]]);

        $this->attempt(fn (): Mutation => $mutations->review($this->tenantId(), $target->id, self::optional($request->input('note')), $this->actorId()));

        return $this->done('Mutasi darurat ditandai sudah ditinjau.');
    }

    /**
     * @return array{to_room_id: string, reason: string|null}
     */
    private function validateMove(Request $request): array
    {
        $request->validate([
            'to_room_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:'.ManageMutations::REASON_MAX_LENGTH],
        ], [
            'to_room_id.required' => 'Pilih kamar tujuan.',
            'to_room_id.uuid' => 'Pilih kamar tujuan dari daftar.',
            'reason.max' => 'Alasan paling panjang '.ManageMutations::REASON_MAX_LENGTH.' karakter.',
        ]);

        return [
            'to_room_id' => $request->string('to_room_id')->value(),
            'reason' => self::optional($request->input('reason')),
        ];
    }

    /**
     * Penempatan asal: terlihat bila pengguna punya izin mutasi atau lihat penghuni di asrama asal, atau wali kamarnya.
     */
    private function placement(string $placementId): Placement
    {
        $placement = Str::isUuid($placementId) ? Placement::query()->find($placementId) : null;

        if (! $placement instanceof Placement) {
            abort(404);
        }

        $visible = $this->supervises($placement);

        foreach ([DormitoryAccess::RESIDENTS_VIEW, DormitoryAccess::MUTATIONS_REQUEST, DormitoryAccess::MUTATIONS_EMERGENCY] as $permission) {
            $visible = $visible || in_array($placement->organization_id, $this->authorization->visibleNodeIds($permission), true);
        }

        if (! $visible) {
            abort(404);
        }

        return $placement;
    }

    private function toRoom(string $roomId): Room
    {
        $room = Room::query()->find($roomId);

        if (! $room instanceof Room) {
            abort(404);
        }

        return $room;
    }

    /**
     * Mutasi yang diputuskan/ditinjau: izin di asal (Gate: 404/403) dan di tujuan (403).
     */
    private function mutation(string $mutationId, string $permission): Mutation
    {
        $mutation = Str::isUuid($mutationId) ? Mutation::query()->find($mutationId) : null;

        if (! $mutation instanceof Mutation) {
            abort(404);
        }

        Gate::authorize($permission, $mutation);

        if (! $this->authorization->allows($permission, $mutation->to_organization_id)) {
            abort(403);
        }

        return $mutation;
    }

    private function authorizeAt(string $permission, string $organizationId, ?string $context = null): void
    {
        if (! $this->authorization->allows($permission, $organizationId)) {
            abort(403, $context === 'to_room_id' ? 'Anda tidak berwenang atas asrama tujuan.' : '');
        }
    }

    private function allowsBoth(string $permission, string $from, string $to): bool
    {
        return $this->authorization->allows($permission, $from) && $this->authorization->allows($permission, $to);
    }

    private function supervises(Placement $placement): bool
    {
        return in_array($placement->room_id, $this->supervisors->roomIdsOf($this->tenantId(), $this->actorId()), true);
    }

    /**
     * Node tempat pengguna punya salah satu izin mutasi (saringan daftar & menu).
     *
     * @return list<string>
     */
    private function mutationNodes(): array
    {
        $nodes = [];

        foreach ([DormitoryAccess::MUTATIONS_REQUEST, DormitoryAccess::MUTATIONS_APPROVE, DormitoryAccess::MUTATIONS_EMERGENCY] as $permission) {
            array_push($nodes, ...$this->authorization->visibleNodeIds($permission));
        }

        return array_values(array_unique($nodes));
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
