import {
    useState,
} from 'react';

import {
    useApproveLeaveRequestMutation,
    useCancelLeaveRequestMutation,
    useCreateLeaveRequestMutation,
    useRejectLeaveRequestMutation,
    useSubmitLeaveRequestMutation,
    useWithdrawLeaveRequestMutation,
} from '@/modules/hr/api/use-leave-request-mutations';
import {
    useLeaveRequestsQuery,
    type LeaveRequestResource,
} from '@/modules/hr/api/use-leave-requests-query';
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
 * §Perbaikan bug tanggal Selesai — lihat penjelasan lengkap di
 * fungsi identik pada HrSelfLeaveRequestsPage.tsx. Backend memakai
 * rentang setengah-terbuka `[starts_at, ends_at)` (INV-HR-LEAVE-013),
 * sementara tanggal "Selesai" di form ini INKLUSIF dari sudut
 * pandang pengguna -- perlu ditambah 1 hari sebelum dikirim.
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

/**
 * §Perbaikan tampilan tanggal — lihat penjelasan lengkap di fungsi
 * identik pada HrSelfLeaveRequestsPage.tsx. Backend mengembalikan
 * timestamp lengkap; dipotong ke tahun-bulan-hari saja untuk
 * ditampilkan.
 */
function formatDisplayDate(
    isoTimestamp: string,
): string {
    return isoTimestamp.slice(0, 10);
}

/**
 * §Perbaikan tampilan tanggal — KEBALIKAN dari toExclusiveEndDate di
 * atas. ends_at tersimpan sebagai batas eksklusif (h+1), jadi untuk
 * ditampilkan harus dikurangi 1 hari dulu supaya kembali ke tanggal
 * terakhir yang inklusif.
 */
function formatInclusiveEndDisplayDate(
    isoTimestamp: string,
): string {
    const date =
        new Date(isoTimestamp);

    date.setUTCDate(
        date.getUTCDate() - 1,
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

function LeaveRequestActions({
    employmentId,
    leaveRequest,
}: {
    employmentId: string;
    leaveRequest: LeaveRequestResource;
}) {
    const submitMutation =
        useSubmitLeaveRequestMutation(
            employmentId,
        );

    const withdrawMutation =
        useWithdrawLeaveRequestMutation(
            employmentId,
        );

    const cancelMutation =
        useCancelLeaveRequestMutation(
            employmentId,
        );

    const approveMutation =
        useApproveLeaveRequestMutation(
            employmentId,
        );

    const rejectMutation =
        useRejectLeaveRequestMutation(
            employmentId,
        );

    const isPending =
        submitMutation.isPending
        || withdrawMutation.isPending
        || cancelMutation.isPending
        || approveMutation.isPending
        || rejectMutation.isPending;

    if (leaveRequest.status === 'DRAFT') {
        return (
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={isPending}
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

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isPending}
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
        );
    }

    if (
        leaveRequest.status === 'SUBMITTED'
        || leaveRequest.status === 'IN_REVIEW'
    ) {
        return (
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            approveMutation.mutate(
                                {
                                    leaveRequestId:
                                        leaveRequest.id,
                                },
                            )
                    }
                >
                    Setujui
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isPending}
                    onClick={
                        () =>
                            rejectMutation.mutate(
                                {
                                    leaveRequestId:
                                        leaveRequest.id,
                                },
                            )
                    }
                >
                    Tolak
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isPending}
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
        );
    }

    if (leaveRequest.status === 'APPROVED') {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={isPending}
                onClick={
                    () =>
                        cancelMutation.mutate(
                            {
                                leaveRequestId:
                                    leaveRequest.id,
                            },
                        )
                }
            >
                Batalkan
            </Button>
        );
    }

    return null;
}

export function LeaveRequestSection({
    employmentId,
}: {
    employmentId: string;
}) {
    const requestsQuery =
        useLeaveRequestsQuery(
            employmentId,
        );

    const leaveTypesQuery =
        useLeaveTypesQuery();

    const createMutation =
        useCreateLeaveRequestMutation(
            employmentId,
        );

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
                employmentId,

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
            aria-labelledby="leave-request-section-heading"
            className="space-y-4"
        >
            <h2
                id="leave-request-section-heading"
                className="text-lg font-semibold"
            >
                Pengajuan Cuti
            </h2>

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
                requestsQuery.status === 'success'
                    ? (
                        requestsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada pengajuan cuti untuk Employment ini.
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
                                                                formatDisplayDate(
                                                                    leaveRequest.starts_at,
                                                                )
                                                            }
                                                            {
                                                                ' – '
                                                            }
                                                            {
                                                                formatInclusiveEndDisplayDate(
                                                                    leaveRequest.ends_at,
                                                                )
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

                                                    <LeaveRequestActions
                                                        employmentId={
                                                            employmentId
                                                        }
                                                        leaveRequest={
                                                            leaveRequest
                                                        }
                                                    />
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
                <h3 className="text-sm font-semibold">
                    Ajukan Cuti Baru (Draf)
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="leave-request-leave-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis Cuti
                        </label>

                        <Select
                            id="leave-request-leave-type"
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
                            htmlFor="leave-request-starts-at"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mulai
                        </label>

                        <Input
                            id="leave-request-starts-at"
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
                            htmlFor="leave-request-ends-at"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Selesai
                        </label>

                        <Input
                            id="leave-request-ends-at"
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
                            htmlFor="leave-request-units"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jumlah Diajukan
                        </label>

                        <Input
                            id="leave-request-units"
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
                            htmlFor="leave-request-reason"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Alasan (opsional)
                        </label>

                        <Input
                            id="leave-request-reason"
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
                                Gagal menyimpan. Coba lagi.
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
