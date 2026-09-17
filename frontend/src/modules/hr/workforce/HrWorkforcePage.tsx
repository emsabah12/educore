import {
    useState,
} from 'react';
import {
    Link,
} from 'react-router';

import {
    useCreateEmployeeAccountMutation,
} from '@/modules/hr/api/use-employee-account-mutation';
import {
    useCreateWorkspaceEmployeeMutation,
} from '@/modules/hr/api/use-employee-mutations';
import {
    useCreateEmploymentTypeMutation,
} from '@/modules/hr/api/use-employment-type-mutations';
import {
    useEmploymentTypesQuery,
} from '@/modules/hr/api/use-employment-types-query';
import {
    useWorkspaceEmployeesQuery,
} from '@/modules/hr/api/use-workspace-employees-query';
import {
    Badge,
    Button,
    Input,
    Pagination,
    Select,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

const JABATAN_LABEL: Record<string, string> = {
    GURU: 'Guru',
    KEPALA_SEKOLAH: 'Kepala Sekolah',
    STAFF: 'Staf',
};

function jabatanLabel(
    jabatan: string,
): string {
    return (
        JABATAN_LABEL[jabatan]
        ?? jabatan
    );
}

function CreateEmploymentTypeInlineForm({
    onCreated,
}: {
    onCreated: (
        newEmploymentTypeId: string,
    ) => void;
}) {
    const [
        isOpen,
        setIsOpen,
    ] = useState(false);

    const [
        code,
        setCode,
    ] = useState('');

    const [
        name,
        setName,
    ] = useState('');

    const mutation =
        useCreateEmploymentTypeMutation();

    function handleSave() {
        mutation.mutate(
            {
                code,
                name,
                description:
                    null,
            },
            {
                onSuccess: (
                    createdEmploymentType,
                ) => {
                    setCode('');
                    setName('');
                    setIsOpen(false);
                    onCreated(
                        createdEmploymentType.id,
                    );
                },
            },
        );
    }

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
                + Jenis Baru
            </Button>
        );
    }

    return (
        <div className="space-y-2 rounded-md border border-dashed p-3">
            <div className="grid gap-2 sm:grid-cols-2">
                <div className="space-y-1">
                    <label
                        htmlFor="workforce-employment-type-code"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Kode
                    </label>

                    <Input
                        id="workforce-employment-type-code"
                        value={code}
                        placeholder="TETAP"
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
                        htmlFor="workforce-employment-type-name"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Nama
                    </label>

                    <Input
                        id="workforce-employment-type-name"
                        value={name}
                        placeholder="Tetap"
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
                                    ? 'Kode ini sudah dipakai jenis employment lain.'
                                    : 'Gagal membuat jenis employment. Coba lagi.'
                            }
                        </p>
                    )
                    : null
            }

            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={
                        mutation.isPending
                        || code === ''
                        || name === ''
                    }
                    onClick={
                        handleSave
                    }
                >
                    {
                        mutation.isPending
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
            </div>
        </div>
    );
}

function CreateEmployeeForm() {
    const [
        nama,
        setNama,
    ] = useState('');

    const [
        nip,
        setNip,
    ] = useState('');

    const [
        jabatan,
        setJabatan,
    ] = useState('GURU');

    const [
        employmentTypeId,
        setEmploymentTypeId,
    ] = useState('');

    const employmentTypesQuery =
        useEmploymentTypesQuery();

    const mutation =
        useCreateWorkspaceEmployeeMutation();

    const activeEmploymentTypes =
        employmentTypesQuery.status === 'success'
            ? employmentTypesQuery.data.filter(
                (
                    employmentType,
                ) =>
                    employmentType.is_active,
            )
            : [];

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        if (employmentTypeId === '') {
            return;
        }

        mutation.mutate(
            {
                nama,
                nip,
                jabatan,
                employment_type_id:
                    employmentTypeId,
            },
            {
                onSuccess: () => {
                    setNama('');
                    setNip('');
                    setJabatan('GURU');
                    setEmploymentTypeId('');
                },
            },
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="space-y-3 rounded-md border p-4"
        >
            <h2 className="text-sm font-semibold">
                Tambah Pegawai Baru
            </h2>

            <div className="grid gap-3 sm:grid-cols-4">
                <div className="space-y-1">
                    <label
                        htmlFor="workforce-employee-nama"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Nama
                    </label>

                    <Input
                        id="workforce-employee-nama"
                        value={nama}
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setNama(
                                    event.target.value,
                                )
                        }
                    />
                </div>

                <div className="space-y-1">
                    <label
                        htmlFor="workforce-employee-nip"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        NIP
                    </label>

                    <Input
                        id="workforce-employee-nip"
                        value={nip}
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setNip(
                                    event.target.value,
                                )
                        }
                    />
                </div>

                <div className="space-y-1">
                    <label
                        htmlFor="workforce-employee-jabatan"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Jabatan
                    </label>

                    <Select
                        id="workforce-employee-jabatan"
                        value={jabatan}
                        onChange={
                            (
                                event,
                            ) =>
                                setJabatan(
                                    event.target.value,
                                )
                        }
                    >
                        {
                            Object.entries(
                                JABATAN_LABEL,
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
                        htmlFor="workforce-employee-employment-type"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Jenis Employment
                    </label>

                    <Select
                        id="workforce-employee-employment-type"
                        value={employmentTypeId}
                        required
                        disabled={
                            employmentTypesQuery.status !== 'success'
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setEmploymentTypeId(
                                    event.target.value,
                                )
                        }
                    >
                        <option value="">
                            {
                                employmentTypesQuery.status === 'pending'
                                    ? 'Memuat…'
                                    : 'Pilih jenis employment'
                            }
                        </option>

                        {
                            activeEmploymentTypes.map(
                                (
                                    employmentType,
                                ) => (
                                    <option
                                        key={
                                            employmentType.id
                                        }
                                        value={
                                            employmentType.id
                                        }
                                    >
                                        {
                                            employmentType.name
                                        }
                                    </option>
                                ),
                            )
                        }
                    </Select>

                    <CreateEmploymentTypeInlineForm
                        onCreated={
                            setEmploymentTypeId
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
                                && mutation.error.error.code === 'WORKSPACE_EMPLOYEE_PROVISIONING_CONFLICT'
                                    ? 'NIP ini sudah dipakai pegawai lain di tenant Anda.'
                                    : 'Gagal menambahkan pegawai. Periksa isian dan coba lagi.'
                            }
                        </p>
                    )
                    : null
            }

            <Button
                type="submit"
                disabled={
                    mutation.isPending
                    || employmentTypesQuery.status !== 'success'
                }
            >
                {
                    mutation.isPending
                        ? 'Menyimpan…'
                        : 'Tambah Pegawai'
                }
            </Button>
        </form>
    );
}

function CreateAccountControl({
    employeeId,
}: {
    employeeId: string;
}) {
    const [
        mode,
        setMode,
    ] = useState<
        'idle' | 'form' | 'result'
    >('idle');

    const [
        email,
        setEmail,
    ] = useState('');

    const mutation =
        useCreateEmployeeAccountMutation();

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        mutation.mutate(
            {
                employeeId,
                email,
            },
            {
                onSuccess: () => {
                    setMode('result');
                },
            },
        );
    }

    if (mode === 'result' && mutation.data !== undefined) {
        return (
            <div className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs">
                <p className="font-semibold text-foreground">
                    Akun berhasil dibuat — catat sekarang, password
                    tidak akan ditampilkan lagi.
                </p>

                <p>
                    Email:
                    {' '}
                    <span className="font-mono">
                        {
                            mutation.data.email
                        }
                    </span>
                </p>

                <p>
                    Password:
                    {' '}
                    <span className="font-mono">
                        {
                            mutation.data.generated_password
                        }
                    </span>
                </p>

                <Button
                    type="button"
                    size="sm"
                    onClick={
                        () => {
                            setMode('idle');
                            setEmail('');
                            mutation.reset();
                        }
                    }
                >
                    Sudah Dicatat, Tutup
                </Button>
            </div>
        );
    }

    if (mode === 'form') {
        return (
            <form
                onSubmit={handleSubmit}
                className="flex flex-wrap items-end gap-2"
            >
                <div className="space-y-1">
                    <label
                        htmlFor={
                            `create-account-email-${employeeId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Email
                    </label>

                    <Input
                        id={
                            `create-account-email-${employeeId}`
                        }
                        type="email"
                        value={email}
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setEmail(
                                    event.target.value,
                                )
                        }
                    />
                </div>

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        mutation.isPending
                    }
                >
                    {
                        mutation.isPending
                            ? 'Membuat…'
                            : 'Buat'
                    }
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={
                        () =>
                            setMode('idle')
                    }
                >
                    Batal
                </Button>

                {
                    mutation.isError
                        ? (
                            <p
                                role="alert"
                                className="w-full text-xs text-destructive"
                            >
                                {
                                    mutation.error.kind === 'response'
                                    && mutation.error.status === 409
                                        ? 'Pegawai ini sudah punya akun login.'
                                        : 'Gagal membuat akun. Coba lagi.'
                                }
                            </p>
                        )
                        : null
                }
            </form>
        );
    }

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={
                () =>
                    setMode('form')
            }
        >
            Buat Akun Login
        </Button>
    );
}

export function HrWorkforcePage() {
    const [
        page,
        setPage,
    ] = useState(1);

    const query =
        useWorkspaceEmployeesQuery(
            {
                page,
            },
        );

    return (
        <section
            aria-labelledby="hr-workforce-heading"
            className="space-y-4"
        >
            <div>
                <h1
                    id="hr-workforce-heading"
                    className="text-xl font-semibold"
                >
                    Daftar Pegawai
                </h1>

                <p className="text-sm text-muted-foreground">
                    Pegawai yang terlihat pada workspace organisasi Anda saat ini.
                </p>
            </div>

            <CreateEmployeeForm />

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat daftar pegawai…
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
                            Gagal memuat daftar pegawai. Coba muat ulang halaman ini.
                        </div>
                    )
                    : null
            }

            {
                query.status === 'success'
                    ? (
                        query.data.employees.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada pegawai yang terlihat di workspace ini.
                                </p>
                            )
                            : (
                                <>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    Nama
                                                </TableHead>
                                                <TableHead>
                                                    NIP
                                                </TableHead>
                                                <TableHead>
                                                    Jabatan
                                                </TableHead>
                                                <TableHead>
                                                    Aksi
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>

                                        <TableBody>
                                            {
                                                query.data.employees.map(
                                                    (
                                                        employee,
                                                    ) => (
                                                        <TableRow
                                                            key={
                                                                employee.id
                                                            }
                                                        >
                                                            <TableCell className="font-medium">
                                                                {
                                                                    employee.nama
                                                                }
                                                            </TableCell>

                                                            <TableCell>
                                                                {
                                                                    employee.nip
                                                                    ?? (
                                                                        <span className="text-muted-foreground">
                                                                            —
                                                                        </span>
                                                                    )
                                                                }
                                                            </TableCell>

                                                            <TableCell>
                                                                <Badge variant="secondary">
                                                                    {
                                                                        jabatanLabel(
                                                                            employee.jabatan,
                                                                        )
                                                                    }
                                                                </Badge>
                                                            </TableCell>

                                                            <TableCell>
                                                                <div className="flex flex-wrap items-start gap-2">
                                                                    <Button
                                                                        asChild
                                                                        variant="outline"
                                                                        size="sm"
                                                                    >
                                                                        <Link
                                                                            to={
                                                                                `/hr/workforce/${employee.id}`
                                                                            }
                                                                        >
                                                                            Lihat Detail
                                                                        </Link>
                                                                    </Button>

                                                                    <CreateAccountControl
                                                                        employeeId={
                                                                            employee.id
                                                                        }
                                                                    />
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    ),
                                                )
                                            }
                                        </TableBody>
                                    </Table>

                                    <div className="flex items-center justify-between pt-2">
                                        <p className="text-sm text-muted-foreground">
                                            Halaman
                                            {' '}
                                            {
                                                query.data.currentPage
                                            }
                                            {' '}
                                            dari
                                            {' '}
                                            {
                                                query.data.lastPage
                                            }
                                            {' '}
                                            ({
                                                query.data.total
                                            }
                                            {' '}
                                            pegawai)
                                        </p>

                                        <Pagination
                                            currentPage={
                                                query.data.currentPage
                                            }
                                            lastPage={
                                                query.data.lastPage
                                            }
                                            onPageChange={
                                                setPage
                                            }
                                        />
                                    </div>
                                </>
                            )
                    )
                    : null
            }
        </section>
    );
}
