<?php

namespace Modules\Finance\Application\Cash;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Domain\Cash\ExpenseStatus;

/**
 * Daftar pengajuan pengeluaran (PRD-004 §6, KS-3) untuk sekumpulan kas. Hak akses dicek pemanggil.
 * Urut: menunggu persetujuan, siap direalisasi, lalu yang lain; terbaru dulu.
 *
 * @phpstan-type ExpenseRow array{id: string, organization_id: string|null, category: string, description: string, amount: int, approved_amount: int|null, needed_on: string|null, status: string, status_label: string, requested_by: string|null, requested_by_id: string|null, requested_at: string, decided_by: string|null, decided_at: string|null, decision_note: string|null, first_decision: array{by: string|null, by_id: string|null, at: string, amount: int, note: string|null}|null, attachment_name: string|null, proof_name: string|null, realized: array{account: string, date: string, amount: int}|null}
 */
final class ExpenseList
{
    public const PER_PAGE = 30;

    /**
     * @param  list<string>  $organizationIds  kas lembaga
     * @param  bool  $includeYayasan  ikut kas Yayasan
     * @return array{rows: list<ExpenseRow>, total: int, page: int, last_page: int}
     */
    public function list(string $tenantId, array $organizationIds, bool $includeYayasan, ?string $status, int $page): array
    {
        $query = DB::table('fn_expense_requests as r')
            ->join('fn_cash_categories as c', 'c.id', '=', 'r.category_id')
            ->leftJoin('memberships as rm', 'rm.id', '=', 'r.requested_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'rm.person_id')
            ->leftJoin('memberships as dm', 'dm.id', '=', 'r.decided_by_membership_id')
            ->leftJoin('persons as dp', 'dp.id', '=', 'dm.person_id')
            ->leftJoin('memberships as fm', 'fm.id', '=', 'r.first_decided_by_membership_id')
            ->leftJoin('persons as fp', 'fp.id', '=', 'fm.person_id')
            ->leftJoin('fn_cash_entries as e', 'e.id', '=', 'r.realized_entry_id')
            ->leftJoin('fn_cash_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('r.tenant_id', $tenantId)
            ->where(function (Builder $where) use ($organizationIds, $includeYayasan): void {
                $where->whereIn('r.organization_id', $organizationIds);

                if ($includeYayasan) {
                    $where->orWhereNull('r.organization_id');
                }
            })
            ->when($status !== null, fn (Builder $query) => $query->where('r.status', $status));

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $rows = [];

        foreach ($query
            ->orderByRaw("CASE r.status WHEN 'DIAJUKAN' THEN 0 WHEN 'MENUNGGU_YAYASAN' THEN 0 WHEN 'DISETUJUI' THEN 1 ELSE 2 END")
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get([
                'r.id', 'r.organization_id', 'r.description', 'r.amount', 'r.approved_amount', 'r.needed_on', 'r.status', 'r.created_at',
                'r.decided_at', 'r.decision_note', 'r.attachment_name', 'r.proof_name', 'r.requested_by_membership_id',
                'r.first_decided_by_membership_id', 'r.first_decided_at', 'r.first_approved_amount', 'r.first_decision_note',
                'c.name as category', 'rp.name as requested_by', 'dp.name as decided_by', 'fp.name as first_decided_by',
                'e.entry_date as realized_date', 'e.amount as realized_amount', 'a.name as realized_account',
            ]) as $row) {
            $status = ExpenseStatus::tryFrom((string) $row->status) ?? ExpenseStatus::Requested;

            $rows[] = [
                'id' => (string) $row->id,
                'organization_id' => $row->organization_id === null ? null : (string) $row->organization_id,
                'category' => (string) $row->category,
                'description' => (string) $row->description,
                'amount' => (int) $row->amount,
                'approved_amount' => $row->approved_amount === null ? null : (int) $row->approved_amount,
                'needed_on' => $row->needed_on === null ? null : substr((string) $row->needed_on, 0, 10),
                'status' => $status->value,
                'status_label' => $status->label(),
                'requested_by' => $row->requested_by === null ? null : (string) $row->requested_by,
                'requested_by_id' => $row->requested_by_membership_id === null ? null : (string) $row->requested_by_membership_id,
                'requested_at' => self::day((string) $row->created_at),
                'decided_by' => $row->decided_by === null ? null : (string) $row->decided_by,
                'decided_at' => $row->decided_at === null ? null : self::day((string) $row->decided_at),
                'decision_note' => $row->decision_note === null ? null : (string) $row->decision_note,
                'first_decision' => $row->first_decided_at === null ? null : [
                    'by' => $row->first_decided_by === null ? null : (string) $row->first_decided_by,
                    'by_id' => $row->first_decided_by_membership_id === null ? null : (string) $row->first_decided_by_membership_id,
                    'at' => self::day((string) $row->first_decided_at),
                    'amount' => (int) $row->first_approved_amount,
                    'note' => $row->first_decision_note === null ? null : (string) $row->first_decision_note,
                ],
                'attachment_name' => $row->attachment_name === null ? null : (string) $row->attachment_name,
                'proof_name' => $row->proof_name === null ? null : (string) $row->proof_name,
                'realized' => $row->realized_date === null ? null : [
                    'account' => (string) $row->realized_account,
                    'date' => substr((string) $row->realized_date, 0, 10),
                    'amount' => (int) $row->realized_amount,
                ],
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'last_page' => $lastPage];
    }

    /**
     * Pengajuan kas lembaga yang menunggu persetujuan kedua tingkat Yayasan (KS-5), tanpa pengajuan $membershipId
     * sendiri dan tanpa yang tahap pertamanya ia setujui (Beranda penyetuju tingkat Yayasan).
     */
    public function countAwaitingFoundation(string $tenantId, string $membershipId): int
    {
        return DB::table('fn_expense_requests')
            ->where('tenant_id', $tenantId)
            ->where('status', ExpenseStatus::AwaitingFoundation->value)
            ->where(fn (Builder $where) => $where->whereNull('requested_by_membership_id')->orWhere('requested_by_membership_id', '!=', $membershipId))
            ->where(fn (Builder $where) => $where->whereNull('first_decided_by_membership_id')->orWhere('first_decided_by_membership_id', '!=', $membershipId))
            ->count();
    }

    /**
     * Tanggal (zona waktu bisnis) dari stempel waktu database.
     */
    private static function day(string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp)->setTimezone(BusinessClock::timezone())->toDateString();
    }

    /**
     * Jumlah pengajuan berstatus $status di kas tersebut, opsional tanpa pengajuan milik $exceptRequester (Beranda).
     *
     * @param  list<string>  $organizationIds
     */
    public function count(string $tenantId, array $organizationIds, bool $includeYayasan, ExpenseStatus $status, ?string $exceptRequester = null): int
    {
        return DB::table('fn_expense_requests')
            ->where('tenant_id', $tenantId)
            ->where('status', $status->value)
            ->where(function (Builder $where) use ($organizationIds, $includeYayasan): void {
                $where->whereIn('organization_id', $organizationIds);

                if ($includeYayasan) {
                    $where->orWhereNull('organization_id');
                }
            })
            ->when($exceptRequester !== null, fn (Builder $query) => $query->where(fn (Builder $where) => $where
                ->whereNull('requested_by_membership_id')
                ->orWhere('requested_by_membership_id', '!=', $exceptRequester)))
            ->count();
    }
}
