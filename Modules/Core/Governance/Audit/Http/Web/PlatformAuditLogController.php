<?php

declare(strict_types=1);

namespace Modules\Core\Governance\Audit\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Halaman "log sederhana" LINTAS TENANT untuk superadmin — beda dari
 * riwayat aktivitas per-tenant di `PlatformTenantController::show()`.
 * Read-only, tanpa aksi tulis apa pun, jadi tidak perlu audit-trail-
 * kan dirinya sendiri.
 */
final class PlatformAuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $eventType = $request->string('event_type')->trim()->value();
        $tenantId = $request->string('tenant_id')->trim()->value();

        $query = DB::table('audit_logs')
            ->leftJoin('tenants', 'tenants.id', '=', 'audit_logs.tenant_id')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->select([
                'audit_logs.id',
                'audit_logs.event_type',
                'audit_logs.description',
                'audit_logs.tenant_id',
                'audit_logs.created_at',
                'tenants.name as tenant_name',
                'users.email as actor_email',
            ])
            ->orderByDesc('audit_logs.created_at');

        if ($eventType !== '') {
            $query->where('audit_logs.event_type', $eventType);
        }

        if ($tenantId !== '') {
            $query->where('audit_logs.tenant_id', $tenantId);
        }

        $logs = $query->paginate(self::PER_PAGE)->withQueryString();

        $eventTypes = DB::table('audit_logs')
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        return view('platform.audit-logs.index', [
            'logs' => $logs,
            'eventTypes' => $eventTypes,
            'selectedEventType' => $eventType,
            'selectedTenantId' => $tenantId,
        ]);
    }
}
