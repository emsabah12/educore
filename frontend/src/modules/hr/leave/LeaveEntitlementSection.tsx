import {
    useState,
} from 'react';

import {
    useAdjustLeaveEntitlementMutation,
    useGenerateLeaveEntitlementMutation,
} from '@/modules/hr/api/use-leave-entitlement-mutations';
import {
    useLeaveEntitlementsQuery,
} from '@/modules/hr/api/use-leave-entitlements-query';
import {
    useLeaveTypesQuery,
} from '@/modules/hr/api/use-leave-types-query';
import {
    Badge,
    Button,
    Input,
    Select,
} from '@/shared/ui';

const STATUS_VARIANT: Record<
    string,
    'success' | 'secondary'
> = {
    ACTIVE: 'success',
    CLOSED: 'secondary',
    CANCELLED: 'secondary',
};

const STATUS_LABEL: Record<string, string> = {
    ACTIVE: 'Aktif',
    CLOSED: 'Ditutup',
    CANCELLED: 'Dibatalkan',
};

function AdjustEntitlementForm({
    entitlementId,
}: {
    entitlementId: string;
}) {
    const adjustMutation =
        useAdjustLeaveEntitlementMutation();

    const [
        unitsDelta,
        setUnitsDelta,
    ] = useState('');

    const [
        reason,
        setReason,
    ] = useState('');

    const [
        isOpen,
        setIsOpen,
    ] = useState(false);

    if (! isOpen) {
        return (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={
                    () =>
                        setIsOpen(
                            true,
                        )
                }
            >
                Sesuaikan Saldo
            </Button>
        );
    }

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        adjustMutation.mutate(
            {
                entitlementId,

                unitsDelta:
                    Number(
                        unitsDelta,
                    ),

                reason:
                    reason.trim() === ''
                        ? null
                        : reason,

                idempotencyKey:
                    crypto.randomUUID(),
            },
            {
                onSuccess: () => {
                    setUnitsDelta('');
                    setReason('');
                },
            },
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="flex flex-wrap items-end gap-2"
        >
            <div className="space-y-1">
                <label
                    htmlFor={
                        `adjust-units-${entitlementId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Perubahan (+/-)
                </label>

                <Input
                    id={
                        `adjust-units-${entitlementId}`
                    }
                    type="number"
                    step="0.01"
                    value={
                        unitsDelta
                    }
                    required
                    className="w-28"
                    onChange={
                        (
                            event,
                        ) =>
                            setUnitsDelta(
                                event.target.value,
                            )
                    }
                />
            </div>

            <div className="space-y-1">
                <label
                    htmlFor={
                        `adjust-reason-${entitlementId}`
                    }
                    className="text-xs font-medium text-muted-foreground"
                >
                    Alasan (opsional)
                </label>

                <Input
                    id={
                        `adjust-reason-${entitlementId}`
                    }
                    value={
                        reason
                    }
                    onChange={
                        (
                            event,
                        ) =>
                            setReason(
                                event.target.value,
                            )
                    }
                />
            </div>

            <Button
                type="submit"
                size="sm"
                disabled={
                    adjustMutation.isPending
                }
            >
                {
                    adjustMutation.isPending
                        ? 'Menyimpan…'
                        : 'Simpan'
                }
            </Button>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={
                    () =>
                        setIsOpen(
                            false,
                        )
                }
            >
                Batal
            </Button>

            {
                adjustMutation.isSuccess
                    ? (
                        <p className="w-full text-xs text-muted-foreground">
                            Saldo sekarang: {adjustMutation.data.balance}
                        </p>
                    )
                    : null
            }

            {
                adjustMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="w-full text-xs text-destructive"
                        >
                            Gagal menyesuaikan saldo.
                        </p>
                    )
                    : null
            }
        </form>
    );
}

export function LeaveEntitlementSection({
    employmentId,
}: {
    employmentId: string;
}) {
    const entitlementsQuery =
        useLeaveEntitlementsQuery(
            employmentId,
        );

    const leaveTypesQuery =
        useLeaveTypesQuery();

    const generateMutation =
        useGenerateLeaveEntitlementMutation();

    const [
        form,
        setForm,
    ] = useState({
        leaveTypeId: '',
        periodStart: '',
        periodEnd: '',
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

        generateMutation.mutate(
            {
                employmentId,

                leaveTypeId:
                    form.leaveTypeId,

                periodStart:
                    form.periodStart,

                periodEnd:
                    form.periodEnd,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            leaveTypeId: '',
                            periodStart: '',
                            periodEnd: '',
                        },
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="leave-entitlement-section-heading"
            className="space-y-4"
        >
            <h2
                id="leave-entitlement-section-heading"
                className="text-lg font-semibold"
            >
                Entitlement & Saldo Cuti
            </h2>

            {
                entitlementsQuery.status === 'success'
                    ? (
                        entitlementsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Entitlement untuk Employment ini.
                                </p>
                            )
                            : (
                                <ul className="space-y-2">
                                    {
                                        entitlementsQuery.data.map(
                                            (
                                                entitlement,
                                            ) => (
                                                <li
                                                    key={
                                                        entitlement.id
                                                    }
                                                    className="space-y-2 rounded-md border p-3 text-sm"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">
                                                                {
                                                                    leaveTypeNameById.get(
                                                                        entitlement.leave_type_id,
                                                                    )
                                                                    ?? entitlement.leave_type_id
                                                                }
                                                            </span>

                                                            <span className="text-muted-foreground">
                                                                {
                                                                    entitlement.period_start
                                                                }
                                                                {
                                                                    ' – '
                                                                }
                                                                {
                                                                    entitlement.period_end
                                                                }
                                                            </span>

                                                            <Badge
                                                                variant={
                                                                    STATUS_VARIANT[
                                                                        entitlement.status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    STATUS_LABEL[
                                                                        entitlement.status
                                                                    ]
                                                                    ?? entitlement.status
                                                                }
                                                            </Badge>
                                                        </div>

                                                        <AdjustEntitlementForm
                                                            entitlementId={
                                                                entitlement.id
                                                            }
                                                        />
                                                    </div>
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
                    Generate Entitlement Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="entitlement-generate-leave-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis Cuti
                        </label>

                        <Select
                            id="entitlement-generate-leave-type"
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
                                    ? leaveTypesQuery.data
                                        .filter(
                                            (
                                                leaveType,
                                            ) =>
                                                leaveType.balance_mode === 'BALANCE',
                                        )
                                        .map(
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
                            htmlFor="entitlement-generate-period-start"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mulai Periode
                        </label>

                        <Input
                            id="entitlement-generate-period-start"
                            type="date"
                            value={
                                form.periodStart
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            periodStart:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="entitlement-generate-period-end"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Akhir Periode
                        </label>

                        <Input
                            id="entitlement-generate-period-end"
                            type="date"
                            value={
                                form.periodEnd
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            periodEnd:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>
                </div>

                {
                    generateMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {
                                    generateMutation.error.kind === 'response'
                                    && generateMutation.error.status === 409
                                        ? 'Sudah ada Entitlement yang tumpang tindih untuk periode ini.'
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
                        generateMutation.isPending
                    }
                >
                    {
                        generateMutation.isPending
                            ? 'Menyimpan…'
                            : 'Generate'
                    }
                </Button>
            </form>
        </section>
    );
}
