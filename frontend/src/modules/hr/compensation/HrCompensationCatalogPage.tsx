import {
    useState,
} from 'react';
import {
    Link,
} from 'react-router';

import {
    useCreateBenefitProgramMutation,
} from '@/modules/hr/compensation/api/use-benefit-program-mutations';
import {
    useBenefitProgramsQuery,
    type BenefitProgramResource,
} from '@/modules/hr/compensation/api/use-benefit-programs-query';
import {
    useCreateCompensationComponentMutation,
} from '@/modules/hr/compensation/api/use-compensation-component-mutations';
import {
    useCompensationComponentsQuery,
    type CompensationComponentResource,
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

const COMPONENT_CATEGORY_LABEL: Record<string, string> = {
    BASE_PAY: 'Gaji Pokok',
    ALLOWANCE: 'Tunjangan',
    RATE: 'Tarif',
    OTHER_EARNING_INPUT: 'Input Penghasilan Lain',
};

const VALUE_MODE_LABEL: Record<string, string> = {
    FIXED_AMOUNT: 'Nominal Tetap',
    RATE_PER_UNIT: 'Tarif per Unit',
};

const PERIODICITY_LABEL: Record<string, string> = {
    MONTHLY: 'Bulanan',
    DAILY: 'Harian',
    PER_UNIT: 'Per Unit',
    ONE_TIME: 'Sekali Bayar',
    OTHER: 'Lainnya',
};

const PROGRAM_CATEGORY_LABEL: Record<string, string> = {
    STATUTORY: 'Wajib (Statutori)',
    GOVERNMENT: 'Pemerintah',
    INSTITUTIONAL: 'Institusional',
    OTHER: 'Lainnya',
};

const BENEFICIARY_SCOPE_LABEL: Record<string, string> = {
    EMPLOYEE: 'Pegawai',
    DEPENDENT: 'Tanggungan',
    EITHER: 'Pegawai/Tanggungan',
};

const PAYROLL_RELEVANCE_LABEL: Record<string, string> = {
    NONE: 'Tidak Terkait Payroll',
    ELIGIBILITY_INPUT: 'Input Kelayakan',
    EXTERNAL_PAYMENT_TRACKING: 'Pelacakan Pembayaran Eksternal',
};

function CompensationComponentSection() {
    const query =
        useCompensationComponentsQuery();

    const mutation =
        useCreateCompensationComponentMutation();

    const [
        code,
        setCode,
    ] = useState('');

    const [
        name,
        setName,
    ] = useState('');

    const [
        category,
        setCategory,
    ] = useState<
        CompensationComponentResource['category']
    >('BASE_PAY');

    const [
        valueMode,
        setValueMode,
    ] = useState<
        CompensationComponentResource['value_mode']
    >('FIXED_AMOUNT');

    const [
        unitCode,
        setUnitCode,
    ] = useState('');

    const [
        periodicity,
        setPeriodicity,
    ] = useState<
        CompensationComponentResource['periodicity']
    >('MONTHLY');

    const [
        description,
        setDescription,
    ] = useState('');

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        mutation.mutate(
            {
                code,
                name,
                category,
                value_mode:
                    valueMode,

                unit_code:
                    valueMode === 'RATE_PER_UNIT'
                    && unitCode.trim() !== ''
                        ? unitCode
                        : null,

                periodicity,

                description:
                    description.trim() === ''
                        ? null
                        : description,
            },
            {
                onSuccess: () => {
                    setCode('');
                    setName('');
                    setCategory('BASE_PAY');
                    setValueMode('FIXED_AMOUNT');
                    setUnitCode('');
                    setPeriodicity('MONTHLY');
                    setDescription('');
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="hr-catalog-compensation-components-heading"
            className="space-y-4"
        >
            <h2
                id="hr-catalog-compensation-components-heading"
                className="text-lg font-semibold"
            >
                Komponen Kompensasi
            </h2>

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat katalog…
                        </p>
                    )
                    : null
            }

            {
                query.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat katalog komponen kompensasi.
                        </div>
                    )
                    : null
            }

            {
                query.status === 'success'
                    ? (
                        query.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada komponen kompensasi.
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
                                                Mode Nilai
                                            </TableHead>
                                            <TableHead>
                                                Periodisitas
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            query.data.map(
                                                (
                                                    component,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            component.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                component.code
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                component.name
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant="secondary">
                                                                {
                                                                    COMPONENT_CATEGORY_LABEL[
                                                                        component.category
                                                                    ]
                                                                    ?? component.category
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                VALUE_MODE_LABEL[
                                                                    component.value_mode
                                                                ]
                                                                ?? component.value_mode
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                PERIODICITY_LABEL[
                                                                    component.periodicity
                                                                ]
                                                                ?? component.periodicity
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
                    Tambah Komponen Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-component-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode
                        </label>

                        <Input
                            id="catalog-component-code"
                            value={code}
                            placeholder="BASE_SALARY"
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setCode(
                                        event.target.value,
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-component-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="catalog-component-name"
                            value={name}
                            placeholder="Gaji Pokok"
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setName(
                                        event.target.value,
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-component-category"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kategori
                        </label>

                        <Select
                            id="catalog-component-category"
                            value={category}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setCategory(
                                        event.target.value as CompensationComponentResource['category'],
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    COMPONENT_CATEGORY_LABEL,
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
                            htmlFor="catalog-component-value-mode"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Mode Nilai
                        </label>

                        <Select
                            id="catalog-component-value-mode"
                            value={valueMode}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setValueMode(
                                        event.target.value as CompensationComponentResource['value_mode'],
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    VALUE_MODE_LABEL,
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
                        valueMode === 'RATE_PER_UNIT'
                            ? (
                                <div className="space-y-1">
                                    <label
                                        htmlFor="catalog-component-unit-code"
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Kode Unit
                                    </label>

                                    <Input
                                        id="catalog-component-unit-code"
                                        value={unitCode}
                                        placeholder="JAM"
                                        required
                                        onChange={
                                            (
                                                event,
                                            ) =>
                                                setUnitCode(
                                                    event.target.value,
                                                )
                                        }
                                    />
                                </div>
                            )
                            : null
                    }

                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-component-periodicity"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Periodisitas
                        </label>

                        <Select
                            id="catalog-component-periodicity"
                            value={periodicity}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setPeriodicity(
                                        event.target.value as CompensationComponentResource['periodicity'],
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    PERIODICITY_LABEL,
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

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="catalog-component-description"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Deskripsi (opsional)
                        </label>

                        <Input
                            id="catalog-component-description"
                            value={description}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setDescription(
                                        event.target.value,
                                    )
                            }
                        />
                    </div>
                </div>

                {
                    mutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {
                                    mutation.error.kind === 'response'
                                    && mutation.error.status === 422
                                        ? 'Kode ini sudah dipakai komponen lain, atau kombinasi mode nilai/kode unit tidak valid.'
                                        : 'Gagal membuat komponen kompensasi. Coba lagi.'
                                }
                            </p>
                        )
                        : null
                }

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        mutation.isPending
                    }
                >
                    {
                        mutation.isPending
                            ? 'Menyimpan…'
                            : 'Tambah Komponen'
                    }
                </Button>
            </form>
        </section>
    );
}

function BenefitProgramSection() {
    const query =
        useBenefitProgramsQuery();

    const mutation =
        useCreateBenefitProgramMutation();

    const [
        code,
        setCode,
    ] = useState('');

    const [
        name,
        setName,
    ] = useState('');

    const [
        category,
        setCategory,
    ] = useState<
        BenefitProgramResource['category']
    >('STATUTORY');

    const [
        beneficiaryScope,
        setBeneficiaryScope,
    ] = useState<
        BenefitProgramResource['beneficiary_scope']
    >('EMPLOYEE');

    const [
        payrollRelevance,
        setPayrollRelevance,
    ] = useState<
        BenefitProgramResource['payroll_relevance']
    >('NONE');

    const [
        description,
        setDescription,
    ] = useState('');

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        mutation.mutate(
            {
                code,
                name,
                category,

                beneficiary_scope:
                    beneficiaryScope,

                payroll_relevance:
                    payrollRelevance,

                description:
                    description.trim() === ''
                        ? null
                        : description,
            },
            {
                onSuccess: () => {
                    setCode('');
                    setName('');
                    setCategory('STATUTORY');
                    setBeneficiaryScope('EMPLOYEE');
                    setPayrollRelevance('NONE');
                    setDescription('');
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="hr-catalog-benefit-programs-heading"
            className="space-y-4"
        >
            <h2
                id="hr-catalog-benefit-programs-heading"
                className="text-lg font-semibold"
            >
                Program Benefit
            </h2>

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat katalog…
                        </p>
                    )
                    : null
            }

            {
                query.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat katalog program benefit.
                        </div>
                    )
                    : null
            }

            {
                query.status === 'success'
                    ? (
                        query.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada program benefit.
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
                                                Cakupan
                                            </TableHead>
                                            <TableHead>
                                                Relevansi Payroll
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            query.data.map(
                                                (
                                                    program,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            program.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                program.code
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                program.name
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant="secondary">
                                                                {
                                                                    PROGRAM_CATEGORY_LABEL[
                                                                        program.category
                                                                    ]
                                                                    ?? program.category
                                                                }
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                BENEFICIARY_SCOPE_LABEL[
                                                                    program.beneficiary_scope
                                                                ]
                                                                ?? program.beneficiary_scope
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                PAYROLL_RELEVANCE_LABEL[
                                                                    program.payroll_relevance
                                                                ]
                                                                ?? program.payroll_relevance
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
                    Tambah Program Baru
                </h3>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-program-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode
                        </label>

                        <Input
                            id="catalog-program-code"
                            value={code}
                            placeholder="BPJS_KESEHATAN"
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setCode(
                                        event.target.value,
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-program-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="catalog-program-name"
                            value={name}
                            placeholder="BPJS Kesehatan"
                            required
                            onChange={
                                (
                                    event,
                                ) =>
                                    setName(
                                        event.target.value,
                                    )
                            }
                        />
                    </div>

                    <div className="space-y-1">
                        <label
                            htmlFor="catalog-program-category"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kategori
                        </label>

                        <Select
                            id="catalog-program-category"
                            value={category}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setCategory(
                                        event.target.value as BenefitProgramResource['category'],
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    PROGRAM_CATEGORY_LABEL,
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
                            htmlFor="catalog-program-beneficiary-scope"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Cakupan Penerima
                        </label>

                        <Select
                            id="catalog-program-beneficiary-scope"
                            value={beneficiaryScope}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setBeneficiaryScope(
                                        event.target.value as BenefitProgramResource['beneficiary_scope'],
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    BENEFICIARY_SCOPE_LABEL,
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
                            htmlFor="catalog-program-payroll-relevance"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Relevansi Payroll
                        </label>

                        <Select
                            id="catalog-program-payroll-relevance"
                            value={payrollRelevance}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setPayrollRelevance(
                                        event.target.value as BenefitProgramResource['payroll_relevance'],
                                    )
                            }
                        >
                            {
                                Object.entries(
                                    PAYROLL_RELEVANCE_LABEL,
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

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="catalog-program-description"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Deskripsi (opsional)
                        </label>

                        <Input
                            id="catalog-program-description"
                            value={description}
                            onChange={
                                (
                                    event,
                                ) =>
                                    setDescription(
                                        event.target.value,
                                    )
                            }
                        />
                    </div>
                </div>

                {
                    mutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {
                                    mutation.error.kind === 'response'
                                    && mutation.error.status === 422
                                        ? 'Kode ini sudah dipakai program lain.'
                                        : 'Gagal membuat program benefit. Coba lagi.'
                                }
                            </p>
                        )
                        : null
                }

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        mutation.isPending
                    }
                >
                    {
                        mutation.isPending
                            ? 'Menyimpan…'
                            : 'Tambah Program'
                    }
                </Button>
            </form>
        </section>
    );
}

export function HrCompensationCatalogPage() {
    return (
        <div className="space-y-8">
            <Button
                asChild
                variant="ghost"
                size="sm"
            >
                <Link to="/hr/compensation">
                    ← Kembali ke Pencarian Pegawai
                </Link>
            </Button>

            <div>
                <h1 className="text-xl font-semibold">
                    Katalog Kompensasi & Benefit
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Kelola daftar referensi Komponen Kompensasi dan Program
                    Benefit tenant Anda.
                </p>
            </div>

            <CompensationComponentSection />

            <BenefitProgramSection />
        </div>
    );
}
