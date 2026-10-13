<?php

namespace Modules\Core\Application\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Menulis satu baris audit log (PRD-000 §5, OD-15).
 *
 * Pelaku, yayasan aktif, membership, IP, dan user agent diisi otomatis dari request
 * bila tidak diberikan. Dipanggil di dalam transaksi service yang sama, sehingga log
 * ikut batal bila aksinya batal.
 *
 * JANGAN pernah memasukkan kata sandi, token, atau rahasia 2FA ke $changes.
 */
final class AuditLogger
{
    public const SUBJECT_USER = 'user';

    public const SUBJECT_ORGANIZATION = 'organization';

    public const SUBJECT_MEMBERSHIP = 'membership';

    public const SUBJECT_ASSIGNMENT = 'assignment';

    public const SUBJECT_SETTING = 'setting';

    private const USER_AGENT_MAX = 500;

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  mis. ['name' => ['lama', 'baru']]
     * @param  string|null  $tenantId  null = pakai yayasan aktif (bila ada)
     * @param  bool  $global  true = kejadian di luar yayasan mana pun (login, keamanan akun, katalog)
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $changes = [],
        ?string $organizationId = null,
        ?string $tenantId = null,
        ?User $actor = null,
        bool $global = false,
    ): void {
        $context = $this->tenantContext->get();
        $tenantId = $global ? null : ($tenantId ?? $context?->tenantId);

        // Membership hanya dicatat bila log ini memang milik yayasan yang sedang aktif.
        $membershipId = $context !== null && $tenantId !== null && $tenantId === $context->tenantId ? $context->membershipId : null;

        $currentUser = Auth::user();
        $actor ??= $currentUser instanceof User ? $currentUser : null;

        // Di konsol (seeder, perintah terjadwal) Laravel memakai request palsu; IP & user agent-nya
        // tidak bermakna, jadi dikosongkan. Test tetap memakai request HTTP buatan test.
        $fromConsole = app()->runningInConsole() && ! app()->runningUnitTests();
        $request = ! $fromConsole && app()->bound('request') ? app('request') : null;
        $request = $request instanceof Request ? $request : null;
        $userAgent = $request?->userAgent();

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor?->id,
            'actor_membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip' => $request?->ip(),
            'user_agent' => $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX),
            'created_at' => now(),
        ]);
    }
}
