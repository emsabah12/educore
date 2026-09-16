import {
    useState,
} from 'react';

import {
    useApproveCompensationAssignmentMutation,
    useCorrectCompensationAssignmentMutation,
    useCreateCompensationAssignmentMutation,
    useEndCompensationAssignmentMutation,
    type CompensationAssignmentDraftInput,
} from '@/modules/hr/compensation/api/use-compensation-assignment-mutations';
import {
    useCompensationAssignmentsQuery,
    type CompensationAssignmentResource,
} from '@/modules/hr/compensation/api/use-compensation-assignments-query';
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
    APPROVED: 'Disetujui',
    ENDED: 'Berakhir',
    CANCELLED: 'Dibatalkan',
    SUPERSEDED: 'Digantikan',
};

const STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary'
> = {
    DRAFT: 'warning',
    APPROVED: 'success',
    ENDED: 'secondary',
    CANCELLED: 'secondary',
    SUPERSEDED: 'secondary',
};

const EMPTY_DRAFT_FORM = {
    compensationComponentId: '',
    amount: '',
    rate: '',
    currencyCode: 'IDR',
    effectiveFrom: '',
    effectiveTo: '',
    reason: '',
};

function EndAssignmentInlineForm({
    assignmentId,
    onCancel,
    mutation,
}: {
    assignmentId: string;
    onCancel: () => void;
    mutation: ReturnType<
        typeof useEndCompensationAssignmentMutation
    >;
}) {
    const [
        endDate,
        setEndDate,
    ] = useState('');

    return (
        <div className="flex items-center gap-2">
            <Input
                type="date"
                aria-label="Tanggal berakhir"
                value={endDate}
                onChange={
                    (
                        event,
                    ) =>
                        setEndDate(
                            event.target.value,
                        )
                }
            />

            <Button
                type="button"
                size="sm"
                disabled={
                    mutation.isPending
                    || endDate === ''
                }
                onClick={
                    () =>
                        mutation.mutate(
                            {
                                assignmentId,
                                endDate,
                            },
                            {
                                onSuccess:
                                    onCancel,
                            },
                        )
                }
            >
                {
                    mutation.isPending
                        ? 'Menyimpan…'
                        : 'Konfirmasi'
                }
            </Button>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onCancel}
            >
                Batal
            </Button>
        </div>
    );
}

export function CompensationAssignmentSection({
    employmentId,
}: {
    employmentId: string;
}) {
    const assignmentsQuery =
        useCompensationAssignmentsQuery(
            employmentId,
        );

    const componentsQuery =
        useCompensationComponentsQuery();

    const createMutation =
        useCreateCompensationAssignmentMutation(
            employmentId,
        );

    const approveMutation =
        useApproveCompensationAssignmentMutation(
            employmentId,
        );

    const endMutation =
        useEndCompensationAssignmentMutation(
            employmentId,
        );

    const correctMutation =
        useCorrectCompensationAssignmentMutation(
            employmentId,
        );

    const [
        form,
        setForm,
    ] = useState(
        EMPTY_DRAFT_FORM,
    );

    /*
     * null = mode "buat draf baru". Non-null = mode "koreksi",
     * form di atas dipakai ulang tapi submit-nya memanggil
     * correctMutation dengan assignmentId ini, bukan createMutation.
     */
    const [
        correctingAssignmentId,
        setCorrectingAssignmentId,
    ] = useState<string | null>(
        null,
    );

    const [
        endingAssignmentId,
        setEndingAssignmentId,
    ] = useState<string | null>(
        null,
    );

    const selectedComponent =
        componentsQuery.status === 'success'
            ? componentsQuery.data.find(
                (
                    component,
                ) =>
                    component.id === form.compensationComponentId,
            )
            ?? null
            : null;

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

    function resetForm() {
        setForm(
            EMPTY_DRAFT_FORM,
        );

        setCorrectingAssignmentId(
            null,
        );
    }

    function startCorrecting(
        assignment: CompensationAssignmentResource,
    ) {
        setForm(
            {
                compensationComponentId:
                    assignment.compensation_component_id,

                amount:
                    assignment.amount
                    ?? '',

                rate:
                    assignment.rate
                    ?? '',

                currencyCode:
                    assignment.currency_code,

                effectiveFrom:
                    assignment.effective_from,

                effectiveTo:
                    assignment.effective_to
                    ?? '',

                reason:
                    assignment.reason
                    ?? '',
            },
        );

        setCorrectingAssignmentId(
            assignment.id,
        );
    }

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        const payload: CompensationAssignmentDraftInput = {
            compensation_component_id:
                form.compensationComponentId,

            employment_position_assignment_id:
                null,

            amount:
                form.amount.trim() === ''
                    ? null
                    : Number(
                        form.amount,
                    ),

            rate:
                form.rate.trim() === ''
                    ? null
                    : Number(
                        form.rate,
                    ),

            currency_code:
                form.currencyCode,

            effective_from:
                form.effectiveFrom,

            effective_to:
                form.effectiveTo.trim() === ''
                    ? null
                    : form.effectiveTo,

            reason:
                form.reason.trim() === ''
                    ? null
                    : form.reason,
        };

        if (correctingAssignmentId !== null) {
            correctMutation.mutate(
                {
                    assignmentId:
                        correctingAssignmentId,

                    data:
                        payload,
                },
                {
                    onSuccess:
                        resetForm,
                },
            );

            return;
        }

        createMutation.mutate(
            payload,
            {
                onSuccess:
                    resetForm,
            },
        );
    }

    const activeMutation =
        correctingAssignmentId !== null
            ? correctMutation
            : createMutation;

    return (
        <section
            aria-labelledby="compensation-assignment-heading"
            className="space-y-4"
        >
            <h2
                id="compensation-assignment-heading"
                className="text-lg font-semibold"
            >
                Riwayat Gaji &amp; Tunjangan
            </h2>

            {
                assignmentsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat riwayat…
                        </p>
                    )
                    : null
            }

            {
                assignmentsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat riwayat kompensasi.
                        </div>
                    )
                    : null
            }

            {
                assignmentsQuery.status === 'success'
                    ? (
                        assignmentsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada riwayat gaji/tunjangan.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Komponen
                                            </TableHead>
                                            <TableHead>
                                                Status
                                            </TableHead>
                                            <TableHead>
                                                Nominal/Tarif
                                            </TableHead>
                                            <TableHead>
                                                Periode
                                            </TableHead>
                                            <TableHead>
                                                Aksi
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            assignmentsQuery.data.map(
                                                (
                                                    assignment,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            assignment.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                componentNameById.get(
                                                                    assignment.compensation_component_id,
                                                                )
                                                                ?? assignment.compensation_component_id
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    STATUS_VARIANT[
                                                                        assignment.status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    STATUS_LABEL[
                                                                        assignment.status
                                                                    ]
                                                                    ?? assignment.status
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                assignment.amount
                                                                ?? assignment.rate
                                                                ?? '—'
                                                            }
                                                            {
                                                                ' '
                                                            }
                                                            {
                                                                assignment.currency_code
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                assignment.effective_from
                                                            }
                                                            {
                                                                ' – '
                                                            }
                                                            {
                                                                assignment.effective_to
                                                                ?? 'sekarang'
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                endingAssignmentId === assignment.id
                                                                    ? (
                                                                        <EndAssignmentInlineForm
                                                                            assignmentId={
                                                                                assignment.id
                                                                            }
                                                                            mutation={
                                                                                endMutation
                                                                            }
                                                                            onCancel={
                                                                                () =>
                                                                                    setEndingAssignmentId(
                                                                                        null,
                                                                                    )
                                                                            }
                                                                        />
                                                                    )
                                                                    : (
                                                                        <div className="flex gap-2">
                                                                            {
                                                                                assignment.status === 'DRAFT'
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
                                                                                                        assignment.id,
                                                                                                    )
                                                                                            }
                                                                                        >
                                                                                            Setujui
                                                                                        </Button>
                                                                                    )
                                                                                    : null
                                                                            }

                                                                            {
                                                                                assignment.status === 'APPROVED'
                                                                                    ? (
                                                                                        <Button
                                                                                            type="button"
                                                                                            variant="outline"
                                                                                            size="sm"
                                                                                            onClick={
                                                                                                () =>
                                                                                                    setEndingAssignmentId(
                                                                                                        assignment.id,
                                                                                                    )
                                                                                            }
                                                                                        >
                                                                                            Akhiri
                                                                                        </Button>
                                                                                    )
                                                                                    : null
                                                                            }

                                                                            {
                                                                                assignment.status === 'DRAFT'
                                                                                || assignment.status === 'APPROVED'
                                                                                    ? (
                                                                                        <Button
                                                                                            type="button"
                                                                                            variant="outline"
                                                                                            size="sm"
                                                                                            onClick={
                                                                                                () =>
                                                                                                    startCorrecting(
                                                                                                        assignment,
                                                                                                    )
                                                                                            }
                                                                                        >
                                                                                            Koreksi
                                                                                        </Button>
                                                                                    )
                                                                                    : null
                                                                            }
                                                                        </div>
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
                    {
                        correctingAssignmentId !== null
                            ? 'Koreksi Riwayat'
                            : 'Tambah Draf Baru'
                    }
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="assignment-component"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Komponen
                        </label>

                        <Select
                            id="assignment-component"
                            value={
                                form.compensationComponentId
                            }
                            required
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
                                        : 'Pilih komponen'
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

                    {
                        selectedComponent === null
                        || selectedComponent.value_mode === 'FIXED_AMOUNT'
                            ? (
                                <div className="space-y-1">
                                    <label
                                        htmlFor="assignment-amount"
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Nominal
                                    </label>

                                    <Input
                                        id="assignment-amount"
                                        type="number"
                                        step="0.01"
                                        value={
                                            form.amount
                                        }
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
                            )
                            : (
                                <div className="space-y-1">
                                    <label
                                        htmlFor="assignment-rate"
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Tarif
                                    </label>

                                    <Input
                                        id="assignment-rate"
                                        type="number"
                                        step="0.01"
                                        value={
                                            form.rate
                                        }
                                        onChange={
                                            (
                                                event,
                                            ) =>
                                                setForm(
                                                    {
                                                        ...form,

                                                        rate:
                                                            event.target.value,
                                                    },
                                                )
                                        }
                                    />
                                </div>
                            )
                    }

                    <div className="space-y-1">
                        <label
                            htmlFor="assignment-currency"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mata Uang
                        </label>

                        <Input
                            id="assignment-currency"
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
                            htmlFor="assignment-effective-from"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Berlaku Sejak
                        </label>

                        <Input
                            id="assignment-effective-from"
                            type="date"
                            value={
                                form.effectiveFrom
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            effectiveFrom:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="assignment-effective-to"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Berlaku Sampai (opsional)
                        </label>

                        <Input
                            id="assignment-effective-to"
                            type="date"
                            value={
                                form.effectiveTo
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            effectiveTo:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="assignment-reason"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Alasan (opsional)
                        </label>

                        <Input
                            id="assignment-reason"
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
                    activeMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {
                                    activeMutation.error.kind === 'response'
                                    && activeMutation.error.status === 409
                                        ? 'Ditolak: periksa kombinasi nominal/tarif dengan mode nilai komponen, atau status Employment.'
                                        : 'Gagal menyimpan. Coba lagi.'
                                }
                            </p>
                        )
                        : null
                }

                <div className="flex gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            activeMutation.isPending
                        }
                    >
                        {
                            activeMutation.isPending
                                ? 'Menyimpan…'
                                : correctingAssignmentId !== null
                                    ? 'Simpan Koreksi'
                                    : 'Buat Draf'
                        }
                    </Button>

                    {
                        correctingAssignmentId !== null
                            ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={
                                        resetForm
                                    }
                                >
                                    Batal
                                </Button>
                            )
                            : null
                    }
                </div>
            </form>
        </section>
    );
}
