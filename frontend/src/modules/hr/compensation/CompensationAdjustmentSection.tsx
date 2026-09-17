import {
    useState,
} from 'react';

import {
    useApproveCompensationAdjustmentMutation,
    useCancelCompensationAdjustmentMutation,
    useCreateCompensationAdjustmentMutation,
    useRejectCompensationAdjustmentMutation,
    useSubmitCompensationAdjustmentMutation,
} from '@/modules/hr/compensation/api/use-compensation-adjustment-mutations';
import {
    useCompensationAdjustmentsQuery,
    type CompensationAdjustmentResource,
} from '@/modules/hr/compensation/api/use-compensation-adjustments-query';
import {
    useCompensationComponentsQuery,
} from '@/modules/hr/compensation/api/use-compensation-components-query';
import {
    Badge,
    Button,
    Input,
    Select,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

const STATUS_LABEL: Record<string, string> = {
    DRAFT: 'Draf',
    SUBMITTED: 'Diajukan',
    APPROVED: 'Disetujui',
    REJECTED: 'Ditolak',
    CANCELLED: 'Dibatalkan',
};

const STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary' | 'destructive'
> = {
    DRAFT: 'secondary',
    SUBMITTED: 'warning',
    APPROVED: 'success',
    REJECTED: 'destructive',
    CANCELLED: 'secondary',
};

const ADJUSTMENT_TYPE_LABEL: Record<string, string> = {
    ONE_TIME_EARNING: 'Penghasilan Sekali Bayar',
    COMPENSATION_CORRECTION: 'Koreksi Kompensasi',
};

function createIdempotencyKey(): string {
    if (
        typeof crypto !== 'undefined'
        && typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return `adj-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function emptyDraftForm() {
    return {
        compensationComponentId: '',

        adjustmentType:
            'ONE_TIME_EARNING' as CompensationAdjustmentResource['adjustment_type'],

        amount: '',
        currencyCode: 'IDR',
        targetPeriodStart: '',
        targetPeriodEnd: '',
        reason: '',

        idempotencyKey:
            createIdempotencyKey(),
    };
}

export function CompensationAdjustmentSection({
    employmentId,
}: {
    employmentId: string;
}) {
    const adjustmentsQuery =
        useCompensationAdjustmentsQuery(
            employmentId,
        );

    const componentsQuery =
        useCompensationComponentsQuery();

    const createMutation =
        useCreateCompensationAdjustmentMutation(
            employmentId,
        );

    const submitMutation =
        useSubmitCompensationAdjustmentMutation(
            employmentId,
        );

    const cancelMutation =
        useCancelCompensationAdjustmentMutation(
            employmentId,
        );

    const approveMutation =
        useApproveCompensationAdjustmentMutation(
            employmentId,
        );

    const rejectMutation =
        useRejectCompensationAdjustmentMutation(
            employmentId,
        );

    const [
        form,
        setForm,
    ] = useState(
        emptyDraftForm(),
    );

    const componentNameById =
        new Map(
            componentsQuery.status === 'success'
                ? componentsQuery.data.map(
                    (
                        component,
                    ) => [
                        component.id,
                        component.name,
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
                compensation_component_id:
                    form.compensationComponentId.trim() === ''
                        ? null
                        : form.compensationComponentId,

                adjustment_type:
                    form.adjustmentType,

                amount:
                    Number(
                        form.amount,
                    ),

                currency_code:
                    form.currencyCode,

                target_period_start:
                    form.targetPeriodStart,

                target_period_end:
                    form.targetPeriodEnd,

                reason:
                    form.reason,

                idempotency_key:
                    form.idempotencyKey,
            },
            {
                onSuccess: () => {
                    setForm(
                        emptyDraftForm(),
                    );
                },
            },
        );
    }

    function renderActions(
        adjustment: CompensationAdjustmentResource,
    ) {
        return (
            <div className="flex flex-wrap gap-2">
                {
                    adjustment.status === 'DRAFT'
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
                                            adjustment.id,
                                        )
                                }
                            >
                                Ajukan
                            </Button>
                        )
                        : null
                }

                {
                    adjustment.status === 'DRAFT'
                        ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    cancelMutation.isPending
                                }
                                onClick={
                                    () =>
                                        cancelMutation.mutate(
                                            adjustment.id,
                                        )
                                }
                            >
                                Batalkan
                            </Button>
                        )
                        : null
                }

                {
                    adjustment.status === 'SUBMITTED'
                        ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={
                                    approveMutation.isPending
                                }
                                onClick={
                                    () =>
                                        approveMutation.mutate(
                                            adjustment.id,
                                        )
                                }
                            >
                                Setujui
                            </Button>
                        )
                        : null
                }

                {
                    adjustment.status === 'SUBMITTED'
                        ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    rejectMutation.isPending
                                }
                                onClick={
                                    () =>
                                        rejectMutation.mutate(
                                            adjustment.id,
                                        )
                                }
                            >
                                Tolak
                            </Button>
                        )
                        : null
                }
            </div>
        );
    }

    return (
        <section
            aria-labelledby="compensation-adjustment-heading"
            className="space-y-4"
        >
            <h2
                id="compensation-adjustment-heading"
                className="text-lg font-semibold"
            >
                Penyesuaian Kompensasi
            </h2>

            {
                adjustmentsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat pengajuan…
                        </p>
                    )
                    : null
            }

            {
                adjustmentsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat penyesuaian kompensasi.
                        </div>
                    )
                    : null
            }

            {
                adjustmentsQuery.status === 'success'
                    ? (
                        adjustmentsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada pengajuan penyesuaian kompensasi.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Jenis
                                            </TableHead>
                                            <TableHead>
                                                Komponen
                                            </TableHead>
                                            <TableHead>
                                                Nominal
                                            </TableHead>
                                            <TableHead>
                                                Periode Target
                                            </TableHead>
                                            <TableHead>
                                                Status
                                            </TableHead>
                                            <TableHead>
                                                Aksi
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            adjustmentsQuery.data.map(
                                                (
                                                    adjustment,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            adjustment.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                ADJUSTMENT_TYPE_LABEL[
                                                                    adjustment.adjustment_type
                                                                ]
                                                                ?? adjustment.adjustment_type
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                adjustment.compensation_component_id === null
                                                                    ? '—'
                                                                    : (
                                                                        componentNameById.get(
                                                                            adjustment.compensation_component_id,
                                                                        )
                                                                        ?? adjustment.compensation_component_id
                                                                    )
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                adjustment.amount
                                                            }
                                                            {
                                                                ' '
                                                            }
                                                            {
                                                                adjustment.currency_code
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                adjustment.target_period_start
                                                            }
                                                            {
                                                                ' – '
                                                            }
                                                            {
                                                                adjustment.target_period_end
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    STATUS_VARIANT[
                                                                        adjustment.status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    STATUS_LABEL[
                                                                        adjustment.status
                                                                    ]
                                                                    ?? adjustment.status
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                renderActions(
                                                                    adjustment,
                                                                )
                                                            }
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )
                                        }
                                    </TableBody>
                                </Table>
                            )
                    )
                    : null
            }

            <form
                onSubmit={handleSubmit}
                className="space-y-3 rounded-md border p-4"
            >
                <h3 className="text-sm font-semibold">
                    Ajukan Penyesuaian Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="adjustment-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis
                        </label>

                        <Select
                            id="adjustment-type"
                            value={
                                form.adjustmentType
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            adjustmentType:
                                                event.target.value as CompensationAdjustmentResource['adjustment_type'],
                                        },
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    ADJUSTMENT_TYPE_LABEL,
                                ).map(
                                    (
                                        [
                                            value,
                                            label,
                                        ],
                                    ) => (
                                        <option
                                            key={value}
                                            value={value}
                                        >
                                            {label}
                                        </option>
                                    ),
                                )
                            }
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="adjustment-component"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Komponen (opsional)
                        </label>

                        <Select
                            id="adjustment-component"
                            value={
                                form.compensationComponentId
                            }
                            disabled={
                                componentsQuery.status !== 'success'
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            compensationComponentId:
                                                event.target.value,
                                        },
                                    )
                            }
                        >
                            <option value="">
                                {
                                    componentsQuery.status === 'pending'
                                        ? 'Memuat…'
                                        : 'Tidak terkait komponen tertentu'
                                }
                            </option>

                            {
                                componentsQuery.status === 'success'
                                    ? componentsQuery.data.map(
                                        (
                                            component,
                                        ) => (
                                            <option
                                                key={
                                                    component.id
                                                }
                                                value={
                                                    component.id
                                                }
                                            >
                                                {
                                                    component.name
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
                            htmlFor="adjustment-amount"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nominal
                        </label>

                        <Input
                            id="adjustment-amount"
                            type="number"
                            step="0.01"
                            value={
                                form.amount
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            amount:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="adjustment-currency"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mata Uang
                        </label>

                        <Input
                            id="adjustment-currency"
                            value={
                                form.currencyCode
                            }
                            required
                            maxLength={3}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            currencyCode:
                                                event.target.value.toUpperCase(),
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="adjustment-period-start"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Periode Target — Mulai
                        </label>

                        <Input
                            id="adjustment-period-start"
                            type="date"
                            value={
                                form.targetPeriodStart
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            targetPeriodStart:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="adjustment-period-end"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Periode Target — Selesai
                        </label>

                        <Input
                            id="adjustment-period-end"
                            type="date"
                            value={
                                form.targetPeriodEnd
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            targetPeriodEnd:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="adjustment-reason"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Alasan
                        </label>

                        <Input
                            id="adjustment-reason"
                            value={
                                form.reason
                            }
                            required
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
                                        ? 'Ditolak: kemungkinan pengajuan duplikat. Coba lagi.'
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
                            : 'Buat Draf'
                    }
                </Button>
            </form>
        </section>
    );
}
