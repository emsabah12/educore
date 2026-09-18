import {
    useState,
} from 'react';

import {
    useCreatePositionMutation,
} from '@/modules/hr/api/use-position-mutations';
import {
    usePositionsQuery,
} from '@/modules/hr/api/use-positions-query';
import {
    Badge,
    Button,
    Input,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

const EMPTY_FORM = {
    code: '',
    name: '',
    description: '',
};

export function HrPositionCatalogPage() {
    const positionsQuery =
        usePositionsQuery();

    const createMutation =
        useCreatePositionMutation();

    const [
        form,
        setForm,
    ] = useState(
        EMPTY_FORM,
    );

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

                description:
                    form.description.trim() === ''
                        ? null
                        : form.description,
            },
            {
                onSuccess: () => {
                    setForm(
                        EMPTY_FORM,
                    );
                },
            },
        );
    }

    return (
        <section
            aria-labelledby="hr-position-catalog-heading"
            className="space-y-6"
        >
            <div>
                <h1
                    id="hr-position-catalog-heading"
                    className="text-xl font-semibold"
                >
                    Katalog Jabatan
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Daftar jabatan HR (mis. "Guru Matematika") yang dipakai
                    saat memberi Position Assignment kepada Employment
                    pegawai. Jabatan di sini bukan role otorisasi — akses
                    aplikasi tetap ditentukan lewat Role & Permission
                    terpisah.
                </p>
            </div>

            {
                positionsQuery.status === 'pending'
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
                positionsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat katalog jabatan.
                        </div>
                    )
                    : null
            }

            {
                positionsQuery.status === 'success'
                    ? (
                        positionsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada jabatan terdaftar.
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
                                                Status
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            positionsQuery.data.map(
                                                (
                                                    position,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            position.id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                position.code
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                position.name
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    position.is_active
                                                                        ? 'success'
                                                                        : 'secondary'
                                                                }
                                                            >
                                                                {
                                                                    position.is_active
                                                                        ? 'Aktif'
                                                                        : 'Nonaktif'
                                                                }
                                                            </Badge>
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
                <h2 className="text-sm font-semibold">
                    Tambah Jabatan Baru
                </h2>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="space-y-1">
                        <label
                            htmlFor="position-code"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Kode
                        </label>

                        <Input
                            id="position-code"
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
                            htmlFor="position-name"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Nama
                        </label>

                        <Input
                            id="position-name"
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

                    <div className="space-y-1 sm:col-span-3">
                        <label
                            htmlFor="position-description"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Deskripsi (opsional)
                        </label>

                        <Input
                            id="position-description"
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
                                        ? 'Kode ini sudah dipakai jabatan lain.'
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
