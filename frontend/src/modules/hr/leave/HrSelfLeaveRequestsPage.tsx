import {
    useState,
} from 'react';

import {
    useCreateSelfLeaveRequestMutation,
    useSubmitSelfLeaveRequestMutation,
    useWithdrawSelfLeaveRequestMutation,
} from '@/modules/hr/api/use-self-leave-request-mutations';
import {
    useSelfLeaveBalancesQuery,
} from '@/modules/hr/api/use-self-leave-balances-query';
import {
    useSelfLeaveRequestsQuery,
} from '@/modules/hr/api/use-self-leave-requests-query';
import {
    useLeaveTypesQuery,
} from '@/modules/hr/api/use-leave-types-query';
import {
    Badge,
    Button,
    Input,
    Select,
} from '@/shared/ui';

/**
 * §Perbaikan bug tanggal Selesai — backend menyimpan starts_at/ends_at
 * sebagai rentang setengah-terbuka `[starts_at, ends_at)` (awal
 * inklusif, akhir EKSKLUSIF — lihat INV-HR-LEAVE-013 dan
 * `tstzrange(..., '[)')` di migrasi leave_requests). Input tanggal
 * "Selesai" di form ini INKLUSIF dari sudut pandang pengguna (mereka
 * pilih hari TERAKHIR cuti mereka, bukan hari pertama SETELAH cuti
 * selesai). Fungsi ini menerjemahkan tanggal inklusif pengguna ke
 * konvensi eksklusif backend dengan menambah 1 hari — tanpa ini,
 * cuti 1 hari (Mulai=Selesai) akan selalu ditolak backend
 * (ends_at > starts_at gagal), dan cuti multi-hari akan kehilangan
 * hari terakhirnya.
 *
 * Pakai UTC secara eksplisit (bukan Date setDate lokal) supaya tidak
 * bergeser sehari akibat timezone browser pengguna.
 */
function toExclusiveEndDate(
    inclusiveDateOnly: string,
): string {
    const date =
        new Date(`${inclusiveDateOnly}T00:00:00Z`);

    date.setUTCDate(
        date.getUTCDate() + 1,
    );

    return date.toISOString().slice(0, 10);
}

const STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary' | 'destructive'
> = {
    DRAFT: 'secondary',
    SUBMITTED: 'warning',
    IN_REVIEW: 'warning',
    APPROVED: 'success',
    REJECTED: 'destructive',
    WITHDRAWN: 'secondary',
    CANCELLED: 'secondary',
};

const STATUS_LABEL: Record<string, string> = {
    DRAFT: 'Draf',
    SUBMITTED: 'Diajukan',
    IN_REVIEW: 'Dalam Tinjauan',
    APPROVED: 'Disetujui',
    REJECTED: 'Ditolak',
    WITHDRAWN: 'Ditarik',
    CANCELLED: 'Dibatalkan',
};

export function HrSelfLeaveRequestsPage() {
    const requestsQuery =
        useSelfLeaveRequestsQuery();

    const balancesQuery =
        useSelfLeaveBalancesQuery();

    const leaveTypesQuery =
        useLeaveTypesQuery();

    const createMutation =
        useCreateSelfLeaveRequestMutation();

    const submitMutation =
        useSubmitSelfLeaveRequestMutation();

    const withdrawMutation =
        useWithdrawSelfLeaveRequestMutation();

    const [
        form,
        setForm,
    ] = useState({
        leaveTypeId: '',
        startsAt: '',
        endsAt: '',
        requestedUnits: '',
        reason: '',
    });

    const leaveTypeNameById =
        new Map(
            leaveTypesQuery.status === 'success'
                ? leaveTypesQuery.data.map(
                    (
                        leaveType,
                    ) => [
                        leaveType.id,
                        leaveType.name,
                    ] as const,
                )
                : [],
        );

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                leaveTypeId:
                    form.leaveTypeId,

                startsAt:
                    form.startsAt,

                endsAt:
                    toExclusiveEndDate(
                        form.endsAt,
                    ),

                requestTimezone:
                    Intl.DateTimeFormat().resolvedOptions().timeZone,

                requestedUnits:
                    Number(
                        form.requestedUnits,
                    ),

                reason:
                    form.reason.trim() === ''
                        ? null
                        : form.reason,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            leaveTypeId: '',
                            startsAt: '',
                            endsAt: '',
                            requestedUnits: '',
                            reason: '',
                        },
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="hr-self-leave-requests-heading"
            className="space-y-6"
        >
            <div>
                <h1
                    id="hr-self-leave-requests-heading"
                    className="text-xl font-semibold"
                >
                    Pengajuan Cuti Saya
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Riwayat dan pengajuan cuti/izin Anda sendiri.
                </p>
            </div>

            <section
                aria-labelledby="self-leave-balances-heading"
                className="space-y-2"
            >
                <h2
                    id="self-leave-balances-heading"
                    className="text-sm font-semibold"
                >
                    Saldo Cuti Saya
                </h2>

                {
                    balancesQuery.status === 'success'
                        ? (
                            balancesQuery.data.length === 0
                                ? (
                                    <p className="text-sm text-muted-foreground">
                                        Belum ada saldo cuti.
                                    </p>
                                )
                                : (
                                    <ul className="space-y-1 text-sm">
                                        {
                                            balancesQuery.data.map(
                                                (
                                                    entry,
                                                ) => (
                                                    <li
                                                        key={
                                                            entry.entitlement_id
                                                        }
                                                        className="flex items-center justify-between rounded-md border px-3 py-2"
                                                    >
                                                        <span>
                                                            {
                                                                leaveTypeNameById.get(
                                                                    entry.leave_type_id,
                                                                )
                                                                ?? entry.leave_type_id
                                                            }
                                                            {
                                                                ' '
                                                            }
                                                            <span className="text-muted-foreground">
                                                                (
                                                                {
                                                                    entry.period_start
                                                                }
                                                                {
                                                                    ' – '
                                                                }
                                                                {
                                                                    entry.period_end
                                                                }
                                                                )
                                                            </span>
                                                        </span>

                                                        <span className="font-medium">
                                                            {
                                                                entry.balance
                                                            }
                                                        </span>
                                                    </li>
                                                ),
                                            )
                                        }
                                    </ul>
                                )
                        )
                        : null
                }
            </section>

            {
                requestsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat…
                        </p>
                    )
                    : null
            }

            {
                requestsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat riwayat cuti Anda.
                        </div>
                    )
                    : null
            }

            {
                requestsQuery.status === 'success'
                    ? (
                        requestsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada pengajuan cuti.
                                </p>
                            )
                            : (
                                <ul className="space-y-2">
                                    {
                                        requestsQuery.data.map(
                                            (
                                                leaveRequest,
                                            ) => (
                                                <li
                                                    key={
                                                        leaveRequest.id
                                                    }
                                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm"
                                                >
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">
                                                                {
                                                                    leaveTypeNameById.get(
                                                                        leaveRequest.leave_type_id,
                                                                    )
                                                                    ?? leaveRequest.leave_type_id
                                                                }
                                                            </span>

                                                            <Badge
                                                                variant={
                                                                    STATUS_VARIANT[
                                                                        leaveRequest.status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    STATUS_LABEL[
                                                                        leaveRequest.status
                                                                    ]
                                                                    ?? leaveRequest.status
                                                                }
                                                            </Badge>
                                                        </div>

                                                        <p className="text-muted-foreground">
                                                            {
                                                                leaveRequest.starts_at
                                                            }
                                                            {
                                                                ' – '
                                                            }
                                                            {
                                                                leaveRequest.ends_at
                                                            }
                                                            {
                                                                ' · '
                                                            }
                                                            {
                                                                leaveRequest.requested_units
                                                            }
                                                            {
                                                                ' '
                                                            }
                                                            {
                                                                leaveRequest.unit
                                                                ?? ''
                                                            }
                                                        </p>
                                                    </div>

                                                    {
                                                        leaveRequest.status === 'DRAFT'
                                                        || leaveRequest.status === 'SUBMITTED'
                                                        || leaveRequest.status === 'IN_REVIEW'
                                                            ? (
                                                                <div className="flex gap-2">
                                                                    {
                                                                        leaveRequest.status === 'DRAFT'
                                                                            ? (
                                                                                <Button
                                                                                    type="button"
                                                                                    size="sm"
                                                                                    disabled={
                                                                                        submitMutation.isPending
                                                                                    }
                                                                                    onClick={
                                                                                        () =>
                                                                                            submitMutation.mutate(
                                                                                                {
                                                                                                    leaveRequestId:
                                                                                                        leaveRequest.id,
                                                                                                },
                                                                                            )
                                                                                    }
                                                                                >
                                                                                    Ajukan
                                                                                </Button>
                                                                            )
                                                                            : null
                                                                    }

                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="sm"
                                                                        disabled={
                                                                            withdrawMutation.isPending
                                                                        }
                                                                        onClick={
                                                                            () =>
                                                                                withdrawMutation.mutate(
                                                                                    {
                                                                                        leaveRequestId:
                                                                                            leaveRequest.id,
                                                                                    },
                                                                                )
                                                                        }
                                                                    >
                                                                        Tarik
                                                                    </Button>
                                                                </div>
                                                            )
                                                            : null
                                                    }
                                                </li>
                                            ),
                                        )
                                    }
                                </ul>
                            )
                    )
                    : null
            }

            <form
                onSubmit={handleSubmit}
                className="space-y-3 rounded-md border p-4"
            >
                <h2 className="text-sm font-semibold">
                    Ajukan Cuti Baru
                </h2>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="self-leave-request-leave-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis Cuti
                        </label>

                        <Select
                            id="self-leave-request-leave-type"
                            value={
                                form.leaveTypeId
                            }
                            required
                            disabled={
                                leaveTypesQuery.status !== 'success'
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            leaveTypeId:
                                                event.target.value,
                                        },
                                    )
                            }
                        >
                            <option value="">
                                {
                                    leaveTypesQuery.status === 'pending'
                                        ? 'Memuat…'
                                        : 'Pilih…'
                                }
                            </option>

                            {
                                leaveTypesQuery.status === 'success'
                                    ? leaveTypesQuery.data.map(
                                        (
                                            leaveType,
                                        ) => (
                                            <option
                                                key={
                                                    leaveType.id
                                                }
                                                value={
                                                    leaveType.id
                                                }
                                            >
                                                {
                                                    leaveType.name
                                                }
                                            </option>
                                        ),
                                    )
                                    : null
                            }
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="self-leave-request-starts-at"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mulai
                        </label>

                        <Input
                            id="self-leave-request-starts-at"
                            type="date"
                            value={
                                form.startsAt
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            startsAt:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="self-leave-request-ends-at"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Selesai
                        </label>

                        <Input
                            id="self-leave-request-ends-at"
                            type="date"
                            value={
                                form.endsAt
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            endsAt:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="self-leave-request-units"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jumlah Diajukan
                        </label>

                        <Input
                            id="self-leave-request-units"
                            type="number"
                            step="0.01"
                            value={
                                form.requestedUnits
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            requestedUnits:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-2">
                        <label
                            htmlFor="self-leave-request-reason"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Alasan (opsional)
                        </label>

                        <Input
                            id="self-leave-request-reason"
                            value={
                                form.reason
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            reason:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>
                </div>

                {
                    createMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {
                                    createMutation.error.kind === 'response'
                                    && createMutation.error.status === 409
                                        ? 'Anda tidak memiliki Employment Aktif untuk mengajukan cuti.'
                                        : 'Gagal menyimpan. Coba lagi.'
                                }
                            </p>
                        )
                        : null
                }

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        createMutation.isPending
                    }
                >
                    {
                        createMutation.isPending
                            ? 'Menyimpan…'
                            : 'Simpan sebagai Draf'
                    }
                </Button>
            </form>
        </section>
    );
}
