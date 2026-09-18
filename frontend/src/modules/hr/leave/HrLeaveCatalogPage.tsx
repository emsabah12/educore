import {
    useState,
} from 'react';

import {
    useCreateLeaveApprovalPolicyMutation,
    useDeactivateLeaveApprovalPolicyMutation,
    type CreateLeaveApprovalPolicyStepInput,
} from '@/modules/hr/api/use-leave-approval-policy-mutations';
import {
    useLeaveApprovalPoliciesQuery,
} from '@/modules/hr/api/use-leave-approval-policies-query';
import {
    useCreateLeaveEntitlementPolicyMutation,
    useDeactivateLeaveEntitlementPolicyMutation,
} from '@/modules/hr/api/use-leave-entitlement-policy-mutations';
import {
    useLeaveEntitlementPoliciesQuery,
} from '@/modules/hr/api/use-leave-entitlement-policies-query';
import {
    useCreateLeaveTypeMutation,
    useDeactivateLeaveTypeMutation,
} from '@/modules/hr/api/use-leave-type-mutations';
import {
    useLeaveTypesQuery,
} from '@/modules/hr/api/use-leave-types-query';
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

const LEAVE_CATEGORY_LABEL: Record<string, string> = {
    LEAVE: 'Cuti',
    PERMIT: 'Izin',
};

const BALANCE_MODE_LABEL: Record<string, string> = {
    BALANCE: 'Punya Saldo',
    NONE: 'Tanpa Saldo',
};

const UNIT_LABEL: Record<string, string> = {
    DAY: 'Hari',
    HOUR: 'Jam',
};

const PERIOD_BASIS_LABEL: Record<string, string> = {
    CALENDAR_YEAR: 'Tahun Kalender',
    EMPLOYMENT_ANNIVERSARY: 'Ulang Tahun Kerja',
    MANUAL: 'Manual',
};

const CARRYOVER_MODE_LABEL: Record<string, string> = {
    NONE: 'Tidak Ada',
    LIMITED: 'Terbatas',
};

const DECISION_MODE_LABEL: Record<string, string> = {
    SEQUENTIAL: 'Berurutan',
    AUTO: 'Otomatis',
};

const SCOPE_STRATEGY_LABEL: Record<string, string> = {
    REQUEST_PLACEMENT: 'Penempatan Pengaju',
    ORGANIZATION: 'Organisasi',
    TENANT: 'Seluruh Tenant',
};

function LeaveTypeSection() {
    const leaveTypesQuery =
        useLeaveTypesQuery();

    const createMutation =
        useCreateLeaveTypeMutation();

    const deactivateMutation =
        useDeactivateLeaveTypeMutation();

    const [
        form,
        setForm,
    ] = useState<{
        code: string;
        name: string;
        category: 'LEAVE' | 'PERMIT';
        balanceMode: 'BALANCE' | 'NONE';
        unit: 'DAY' | 'HOUR';
        description: string;
    }>({
        code: '',
        name: '',
        category: 'LEAVE',
        balanceMode: 'BALANCE',
        unit: 'DAY',
        description: '',
    });

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                code:
                    form.code,

                name:
                    form.name,

                category:
                    form.category,

                balance_mode:
                    form.balanceMode,

                unit:
                    form.unit,

                description:
                    form.description.trim() === ''
                        ? null
                        : form.description,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            code: '',
                            name: '',
                            category: 'LEAVE',
                            balanceMode: 'BALANCE',
                            unit: 'DAY',
                            description: '',
                        },
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="leave-type-heading"
            className="space-y-4"
        >
            <h2
                id="leave-type-heading"
                className="text-lg font-semibold"
            >
                Jenis Cuti
            </h2>

            {
                leaveTypesQuery.status === 'pending'
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
                leaveTypesQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat Jenis Cuti.
                        </div>
                    )
                    : null
            }

            {
                leaveTypesQuery.status === 'success'
                    ? (
                        leaveTypesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Jenis Cuti terdaftar.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Kode
                                            </TableHead>
                                            <TableHead>
                                                Nama
                                            </TableHead>
                                            <TableHead>
                                                Kategori
                                            </TableHead>
                                            <TableHead>
                                                Saldo
                                            </TableHead>
                                            <TableHead>
                                                Satuan
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
                                            leaveTypesQuery.data.map(
                                                (
                                                    leaveType,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            leaveType.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                leaveType.code
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                leaveType.name
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                LEAVE_CATEGORY_LABEL[
                                                                    leaveType.category
                                                                ]
                                                                ?? leaveType.category
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                BALANCE_MODE_LABEL[
                                                                    leaveType.balance_mode
                                                                ]
                                                                ?? leaveType.balance_mode
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                UNIT_LABEL[
                                                                    leaveType.unit
                                                                ]
                                                                ?? leaveType.unit
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    leaveType.is_active
                                                                        ? 'success'
                                                                        : 'secondary'
                                                                }
                                                            >
                                                                {
                                                                    leaveType.is_active
                                                                        ? 'Aktif'
                                                                        : 'Nonaktif'
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                leaveType.is_active
                                                                    ? (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={
                                                                                deactivateMutation.isPending
                                                                            }
                                                                            onClick={
                                                                                () =>
                                                                                    deactivateMutation.mutate(
                                                                                        leaveType.id,
                                                                                    )
                                                                            }
                                                                        >
                                                                            Nonaktifkan
                                                                        </Button>
                                                                    )
                                                                    : null
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
                    Tambah Jenis Cuti Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="leave-type-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode
                        </label>

                        <Input
                            id="leave-type-code"
                            value={
                                form.code
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            code:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-2">
                        <label
                            htmlFor="leave-type-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="leave-type-name"
                            value={
                                form.name
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            name:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="leave-type-category"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kategori
                        </label>

                        <Select
                            id="leave-type-category"
                            value={
                                form.category
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            category:
                                                event.target.value as 'LEAVE' | 'PERMIT',
                                        },
                                    )
                            }
                        >
                            <option value="LEAVE">
                                Cuti
                            </option>
                            <option value="PERMIT">
                                Izin
                            </option>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="leave-type-balance-mode"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mode Saldo
                        </label>

                        <Select
                            id="leave-type-balance-mode"
                            value={
                                form.balanceMode
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            balanceMode:
                                                event.target.value as 'BALANCE' | 'NONE',
                                        },
                                    )
                            }
                        >
                            <option value="BALANCE">
                                Punya Saldo
                            </option>
                            <option value="NONE">
                                Tanpa Saldo
                            </option>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="leave-type-unit"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Satuan
                        </label>

                        <Select
                            id="leave-type-unit"
                            value={
                                form.unit
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            unit:
                                                event.target.value as 'DAY' | 'HOUR',
                                        },
                                    )
                            }
                        >
                            <option value="DAY">
                                Hari
                            </option>
                            <option value="HOUR">
                                Jam
                            </option>
                        </Select>
                    </div>

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="leave-type-description"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Deskripsi (opsional)
                        </label>

                        <Input
                            id="leave-type-description"
                            value={
                                form.description
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            description:
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
                                    && createMutation.error.status === 422
                                        ? 'Kode ini sudah dipakai jenis cuti lain.'
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
                            : 'Simpan'
                    }
                </Button>
            </form>
        </section>
    );
}

function LeaveEntitlementPolicySection() {
    const policiesQuery =
        useLeaveEntitlementPoliciesQuery();

    const leaveTypesQuery =
        useLeaveTypesQuery();

    const createMutation =
        useCreateLeaveEntitlementPolicyMutation();

    const deactivateMutation =
        useDeactivateLeaveEntitlementPolicyMutation();

    const [
        form,
        setForm,
    ] = useState<{
        leaveTypeId: string;
        periodBasis: 'CALENDAR_YEAR' | 'EMPLOYMENT_ANNIVERSARY' | 'MANUAL';
        grantUnits: string;
        carryoverMode: 'NONE' | 'LIMITED';
        carryoverLimitUnits: string;
        effectiveFrom: string;
    }>({
        leaveTypeId: '',
        periodBasis: 'CALENDAR_YEAR',
        grantUnits: '',
        carryoverMode: 'NONE',
        carryoverLimitUnits: '',
        effectiveFrom: '',
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
                leave_type_id:
                    form.leaveTypeId,

                period_basis:
                    form.periodBasis,

                grant_units:
                    Number(
                        form.grantUnits,
                    ),

                carryover_mode:
                    form.carryoverMode,

                carryover_limit_units:
                    form.carryoverMode === 'NONE'
                    || form.carryoverLimitUnits.trim() === ''
                        ? null
                        : Number(
                            form.carryoverLimitUnits,
                        ),

                effective_from:
                    form.effectiveFrom,

                effective_to:
                    null,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            leaveTypeId: '',
                            periodBasis: 'CALENDAR_YEAR',
                            grantUnits: '',
                            carryoverMode: 'NONE',
                            carryoverLimitUnits: '',
                            effectiveFrom: '',
                        },
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="leave-entitlement-policy-heading"
            className="space-y-4"
        >
            <h2
                id="leave-entitlement-policy-heading"
                className="text-lg font-semibold"
            >
                Kebijakan Hak Cuti
            </h2>

            {
                policiesQuery.status === 'success'
                    ? (
                        policiesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Kebijakan Hak Cuti.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Jenis Cuti
                                            </TableHead>
                                            <TableHead>
                                                Basis Periode
                                            </TableHead>
                                            <TableHead>
                                                Hak
                                            </TableHead>
                                            <TableHead>
                                                Carry-over
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
                                            policiesQuery.data.map(
                                                (
                                                    policy,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            policy.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                leaveTypeNameById.get(
                                                                    policy.leave_type_id,
                                                                )
                                                                ?? policy.leave_type_id
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                PERIOD_BASIS_LABEL[
                                                                    policy.period_basis
                                                                ]
                                                                ?? policy.period_basis
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                policy.grant_units
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                CARRYOVER_MODE_LABEL[
                                                                    policy.carryover_mode
                                                                ]
                                                                ?? policy.carryover_mode
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    policy.is_active
                                                                        ? 'success'
                                                                        : 'secondary'
                                                                }
                                                            >
                                                                {
                                                                    policy.is_active
                                                                        ? 'Aktif'
                                                                        : 'Nonaktif'
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                policy.is_active
                                                                    ? (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={
                                                                                deactivateMutation.isPending
                                                                            }
                                                                            onClick={
                                                                                () =>
                                                                                    deactivateMutation.mutate(
                                                                                        policy.id,
                                                                                    )
                                                                            }
                                                                        >
                                                                            Nonaktifkan
                                                                        </Button>
                                                                    )
                                                                    : null
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
                    Tambah Kebijakan Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="entitlement-leave-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis Cuti
                        </label>

                        <Select
                            id="entitlement-leave-type"
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
                            htmlFor="entitlement-period-basis"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Basis Periode
                        </label>

                        <Select
                            id="entitlement-period-basis"
                            value={
                                form.periodBasis
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            periodBasis:
                                                event.target.value as typeof form.periodBasis,
                                        },
                                    )
                            }
                        >
                            <option value="CALENDAR_YEAR">
                                Tahun Kalender
                            </option>
                            <option value="EMPLOYMENT_ANNIVERSARY">
                                Ulang Tahun Kerja
                            </option>
                            <option value="MANUAL">
                                Manual
                            </option>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="entitlement-grant-units"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jumlah Hak
                        </label>

                        <Input
                            id="entitlement-grant-units"
                            type="number"
                            step="0.01"
                            value={
                                form.grantUnits
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            grantUnits:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="entitlement-carryover-mode"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Carry-over
                        </label>

                        <Select
                            id="entitlement-carryover-mode"
                            value={
                                form.carryoverMode
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            carryoverMode:
                                                event.target.value as 'NONE' | 'LIMITED',
                                        },
                                    )
                            }
                        >
                            <option value="NONE">
                                Tidak Ada
                            </option>
                            <option value="LIMITED">
                                Terbatas
                            </option>
                        </Select>
                    </div>

                    {
                        form.carryoverMode === 'LIMITED'
                            ? (
                                <div className="space-y-1">
                                    <label
                                        htmlFor="entitlement-carryover-limit"
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Batas Carry-over
                                    </label>

                                    <Input
                                        id="entitlement-carryover-limit"
                                        type="number"
                                        step="0.01"
                                        value={
                                            form.carryoverLimitUnits
                                        }
                                        onChange={
                                            (
                                                event,
                                            ) =>
                                                setForm(
                                                    {
                                                        ...form,

                                                        carryoverLimitUnits:
                                                            event.target.value,
                                                    },
                                                )
                                        }
                                    />
                                </div>
                            )
                            : null
                    }

                    <div className="space-y-1">
                        <label
                            htmlFor="entitlement-effective-from"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Berlaku Sejak
                        </label>

                        <Input
                            id="entitlement-effective-from"
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
                                        ? 'Ditolak: Jenis Cuti ini mungkin tidak punya mode saldo.'
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
                            : 'Simpan'
                    }
                </Button>
            </form>
        </section>
    );
}

function emptyStep(): CreateLeaveApprovalPolicyStepInput {
    return {
        step_order: 1,
        required_permission: '',
        scope_strategy: 'ORGANIZATION',
        independent_approver: false,
    };
}

function LeaveApprovalPolicySection() {
    const policiesQuery =
        useLeaveApprovalPoliciesQuery();

    const leaveTypesQuery =
        useLeaveTypesQuery();

    const createMutation =
        useCreateLeaveApprovalPolicyMutation();

    const deactivateMutation =
        useDeactivateLeaveApprovalPolicyMutation();

    const [
        form,
        setForm,
    ] = useState<{
        policyCode: string;
        name: string;
        leaveTypeId: string;
        decisionMode: 'SEQUENTIAL' | 'AUTO';
        effectiveFrom: string;
    }>({
        policyCode: '',
        name: '',
        leaveTypeId: '',
        decisionMode: 'SEQUENTIAL',
        effectiveFrom: '',
    });

    const [
        steps,
        setSteps,
    ] = useState<
        readonly CreateLeaveApprovalPolicyStepInput[]
    >(
        [
            emptyStep(),
        ],
    );

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

    function updateStep(
        index: number,
        patch: Partial<CreateLeaveApprovalPolicyStepInput>,
    ) {
        setSteps(
            steps.map(
                (
                    step,
                    stepIndex,
                ) =>
                    stepIndex === index
                        ? {
                            ...step,
                            ...patch,
                        }
                        : step,
            ),
        );
    }

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                policy_code:
                    form.policyCode,

                name:
                    form.name,

                leave_type_id:
                    form.leaveTypeId.trim() === ''
                        ? null
                        : form.leaveTypeId,

                decision_mode:
                    form.decisionMode,

                effective_from:
                    form.effectiveFrom,

                effective_to:
                    null,

                steps:
                    form.decisionMode === 'AUTO'
                        ? []
                        : steps,
            },
            {
                onSuccess: () => {
                    setForm(
                        {
                            policyCode: '',
                            name: '',
                            leaveTypeId: '',
                            decisionMode: 'SEQUENTIAL',
                            effectiveFrom: '',
                        },
                    );

                    setSteps(
                        [
                            emptyStep(),
                        ],
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="leave-approval-policy-heading"
            className="space-y-4"
        >
            <h2
                id="leave-approval-policy-heading"
                className="text-lg font-semibold"
            >
                Kebijakan Persetujuan Cuti
            </h2>

            {
                policiesQuery.status === 'success'
                    ? (
                        policiesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Kebijakan Persetujuan.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Kode
                                            </TableHead>
                                            <TableHead>
                                                Nama
                                            </TableHead>
                                            <TableHead>
                                                Jenis Cuti
                                            </TableHead>
                                            <TableHead>
                                                Mode Keputusan
                                            </TableHead>
                                            <TableHead>
                                                Jumlah Step
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
                                            policiesQuery.data.map(
                                                (
                                                    policy,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            policy.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                policy.policy_code
                                                            }
                                                            {
                                                                ' '
                                                            }
                                                            <span className="text-muted-foreground">
                                                                v{policy.version_no}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                policy.name
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                policy.leave_type_id === null
                                                                    ? 'Semua Jenis'
                                                                    : (
                                                                        leaveTypeNameById.get(
                                                                            policy.leave_type_id,
                                                                        )
                                                                        ?? policy.leave_type_id
                                                                    )
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                DECISION_MODE_LABEL[
                                                                    policy.decision_mode
                                                                ]
                                                                ?? policy.decision_mode
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                policy.steps.length
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    policy.is_active
                                                                        ? 'success'
                                                                        : 'secondary'
                                                                }
                                                            >
                                                                {
                                                                    policy.is_active
                                                                        ? 'Aktif'
                                                                        : 'Nonaktif'
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                policy.is_active
                                                                    ? (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={
                                                                                deactivateMutation.isPending
                                                                            }
                                                                            onClick={
                                                                                () =>
                                                                                    deactivateMutation.mutate(
                                                                                        policy.id,
                                                                                    )
                                                                            }
                                                                        >
                                                                            Nonaktifkan
                                                                        </Button>
                                                                    )
                                                                    : null
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
                    Tambah Kebijakan Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="approval-policy-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode Kebijakan
                        </label>

                        <Input
                            id="approval-policy-code"
                            value={
                                form.policyCode
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            policyCode:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1 sm:col-span-2">
                        <label
                            htmlFor="approval-policy-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="approval-policy-name"
                            value={
                                form.name
                            }
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            name:
                                                event.target.value,
                                        },
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="approval-policy-leave-type"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Jenis Cuti (opsional)
                        </label>

                        <Select
                            id="approval-policy-leave-type"
                            value={
                                form.leaveTypeId
                            }
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
                                Semua jenis
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
                            htmlFor="approval-policy-decision-mode"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mode Keputusan
                        </label>

                        <Select
                            id="approval-policy-decision-mode"
                            value={
                                form.decisionMode
                            }
                            onChange={
                                (
                                    event,
                                ) =>
                                    setForm(
                                        {
                                            ...form,

                                            decisionMode:
                                                event.target.value as 'SEQUENTIAL' | 'AUTO',
                                        },
                                    )
                            }
                        >
                            <option value="SEQUENTIAL">
                                Berurutan
                            </option>
                            <option value="AUTO">
                                Otomatis
                            </option>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="approval-policy-effective-from"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Berlaku Sejak
                        </label>

                        <Input
                            id="approval-policy-effective-from"
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
                </div>

                {
                    form.decisionMode === 'SEQUENTIAL'
                        ? (
                            <div className="space-y-2 rounded-md bg-muted/30 p-3">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-xs font-semibold">
                                        Step Persetujuan
                                    </h4>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={
                                            () =>
                                                setSteps(
                                                    [
                                                        ...steps,
                                                        {
                                                            ...emptyStep(),

                                                            step_order:
                                                                steps.length + 1,
                                                        },
                                                    ],
                                                )
                                        }
                                    >
                                        + Tambah Step
                                    </Button>
                                </div>

                                {
                                    steps.map(
                                        (
                                            step,
                                            index,
                                        ) => (
                                            <div
                                                key={
                                                    index
                                                }
                                                className="flex flex-wrap items-end gap-2"
                                            >
                                                <div className="space-y-1">
                                                    <label
                                                        htmlFor={
                                                            `step-order-${index}`
                                                        }
                                                        className="text-xs font-medium text-muted-foreground"
                                                    >
                                                        Urutan
                                                    </label>

                                                    <Input
                                                        id={
                                                            `step-order-${index}`
                                                        }
                                                        type="number"
                                                        min={1}
                                                        value={
                                                            step.step_order
                                                        }
                                                        className="w-20"
                                                        onChange={
                                                            (
                                                                event,
                                                            ) =>
                                                                updateStep(
                                                                    index,
                                                                    {
                                                                        step_order:
                                                                            Number(
                                                                                event.target.value,
                                                                            ),
                                                                    },
                                                                )
                                                        }
                                                    />
                                                </div>

                                                <div className="space-y-1">
                                                    <label
                                                        htmlFor={
                                                            `step-permission-${index}`
                                                        }
                                                        className="text-xs font-medium text-muted-foreground"
                                                    >
                                                        Permission
                                                    </label>

                                                    <Input
                                                        id={
                                                            `step-permission-${index}`
                                                        }
                                                        placeholder="hr.leave.approve"
                                                        value={
                                                            step.required_permission
                                                        }
                                                        onChange={
                                                            (
                                                                event,
                                                            ) =>
                                                                updateStep(
                                                                    index,
                                                                    {
                                                                        required_permission:
                                                                            event.target.value,
                                                                    },
                                                                )
                                                        }
                                                    />
                                                </div>

                                                <div className="space-y-1">
                                                    <label
                                                        htmlFor={
                                                            `step-scope-${index}`
                                                        }
                                                        className="text-xs font-medium text-muted-foreground"
                                                    >
                                                        Scope
                                                    </label>

                                                    <Select
                                                        id={
                                                            `step-scope-${index}`
                                                        }
                                                        value={
                                                            step.scope_strategy
                                                        }
                                                        onChange={
                                                            (
                                                                event,
                                                            ) =>
                                                                updateStep(
                                                                    index,
                                                                    {
                                                                        scope_strategy:
                                                                            event.target.value as CreateLeaveApprovalPolicyStepInput['scope_strategy'],
                                                                    },
                                                                )
                                                        }
                                                    >
                                                        {
                                                            Object.entries(
                                                                SCOPE_STRATEGY_LABEL,
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

                                                {
                                                    steps.length > 1
                                                        ? (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={
                                                                    () =>
                                                                        setSteps(
                                                                            steps.filter(
                                                                                (
                                                                                    _step,
                                                                                    stepIndex,
                                                                                ) =>
                                                                                    stepIndex !== index,
                                                                            ),
                                                                        )
                                                                }
                                                            >
                                                                Hapus
                                                            </Button>
                                                        )
                                                        : null
                                                }
                                            </div>
                                        ),
                                    )
                                }
                            </div>
                        )
                        : null
                }

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
                            : 'Simpan'
                    }
                </Button>
            </form>
        </section>
    );
}

export function HrLeaveCatalogPage() {
    return (
        <div
            aria-labelledby="hr-leave-catalog-heading"
            className="space-y-10"
        >
            <h1
                id="hr-leave-catalog-heading"
                className="text-xl font-semibold"
            >
                Katalog & Kebijakan Cuti
            </h1>

            <LeaveTypeSection />
            <LeaveEntitlementPolicySection />
            <LeaveApprovalPolicySection />
        </div>
    );
}
