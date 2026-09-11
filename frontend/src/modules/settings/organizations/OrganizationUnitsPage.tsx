import {
    useState,
} from 'react';
import {
    Link,
    useParams,
} from 'react-router';

import {
    useCreateOrganizationUnitMutation,
} from '@/modules/settings/organizations/api/use-organization-unit-mutations';
import {
    useOrganizationUnitsQuery,
} from '@/modules/settings/organizations/api/use-organization-units-query';
import {
    useOrganizationsQuery,
} from '@/modules/settings/organizations/api/use-organizations-query';
import type {
    BrowserApiFailure,
} from '@/platform/api';
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

const CREATED_AT_FORMATTER =
    new Intl.DateTimeFormat(
        'id-ID',
        {
            dateStyle:
                'medium',

            timeStyle:
                'short',
        },
    );

function formatCreatedAt(
    value:
        string | undefined,
): string {
    if (
        value === undefined
    ) {
        return '—';
    }

    const parsed =
        new Date(
            value,
        );

    if (
        Number.isNaN(
            parsed.getTime(),
        )
    ) {
        return '—';
    }

    return CREATED_AT_FORMATTER.format(
        parsed,
    );
}

/*
 * Same field-level validation error extraction as
 * OrganizationsPage's CreateOrganizationForm — see that file for
 * the full rationale. Duplicated rather than shared because the
 * two forms validate different fields (code here is unique PER
 * ORGANIZATION, not per tenant) and the duplication is small
 * enough that a shared abstraction would cost more to read than
 * the repetition itself.
 */
function extractFieldErrorMessage(
    error:
        BrowserApiFailure,
    field:
        string,
): string | null {
    if (
        error.kind !== 'response'
    ) {
        return null;
    }

    if (
        ! (
            'errors' in error.error
        )
    ) {
        return null;
    }

    const messages =
        error.error.errors[
            field
        ];

    if (
        ! Array.isArray(
            messages,
        )
        || messages.length === 0
    ) {
        return null;
    }

    return (
        messages[0]
        ?? null
    );
}

function CreateOrganizationUnitForm(
    {
        organizationId,
    }: {
        organizationId:
            string;
    },
) {
    const [
        name,
        setName,
    ] = useState('');

    const [
        code,
        setCode,
    ] = useState('');

    const mutation =
        useCreateOrganizationUnitMutation(
            organizationId,
        );

    const codeErrorMessage =
        mutation.isError
            ? extractFieldErrorMessage(
                mutation.error,
                'code',
            )
            : null;

    function handleSubmit(
        event:
            React.FormEvent,
    ) {
        event.preventDefault();

        mutation.mutate(
            {
                name,
                code:
                    code.trim() === ''
                        ? null
                        : code.trim(),
            },
            {
                onSuccess: () => {
                    setName('');
                    setCode('');
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
                Buat Unit Baru
            </h2>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <label
                        htmlFor="organization-unit-name"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Nama Unit
                    </label>

                    <Input
                        id="organization-unit-name"
                        value={name}
                        placeholder="Fakultas Teknik"
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
                        htmlFor="organization-unit-code"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Kode (opsional)
                    </label>

                    <Input
                        id="organization-unit-code"
                        value={code}
                        placeholder="FT"
                        aria-invalid={
                            codeErrorMessage !== null
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setCode(
                                    event.target.value,
                                )
                        }
                    />

                    {
                        codeErrorMessage !== null
                            ? (
                                <p
                                    role="alert"
                                    className="text-xs text-destructive"
                                >
                                    {
                                        codeErrorMessage
                                    }
                                </p>
                            )
                            : null
                    }
                </div>
            </div>

            {
                mutation.isError
                && codeErrorMessage === null
                    ? (
                        <p
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            Gagal membuat Unit. Coba lagi.
                        </p>
                    )
                    : null
            }

            <Button
                type="submit"
                disabled={mutation.isPending}
            >
                {
                    mutation.isPending
                        ? 'Menyimpan…'
                        : 'Buat Unit'
                }
            </Button>
        </form>
    );
}

export function OrganizationUnitsPage() {
    const {
        organizationId,
    } = useParams<{
        organizationId:
            string;
    }>();

    const resolvedOrganizationId =
        organizationId
        ?? null;

    /*
     * There is no single-Organization GET endpoint on the backend
     * (only index + store) — reusing the already-fetched
     * Organisasi list to resolve this Organization's display name
     * is a deliberate simplification rather than adding a new
     * backend endpoint just for a page heading. The tenant's
     * Organization count is expected to stay small.
     */
    const organizationsQuery =
        useOrganizationsQuery();

    const organization =
        organizationsQuery.data?.find(
            (
                candidate,
            ) =>
                candidate.id === resolvedOrganizationId,
        )
        ?? null;

    const unitsQuery =
        useOrganizationUnitsQuery(
            resolvedOrganizationId,
        );

    return (
        <section
            aria-labelledby="organization-units-heading"
            className="space-y-6"
        >
            <Button
                asChild
                variant="ghost"
                size="sm"
            >
                <Link to="/settings/organizations">
                    ← Kembali ke Daftar Organisasi
                </Link>
            </Button>

            <div>
                <h1
                    id="organization-units-heading"
                    className="text-xl font-semibold"
                >
                    {
                        organization !== null
                            ? `Unit — ${organization.name}`
                            : 'Unit Organisasi'
                    }
                </h1>

                <p className="text-sm text-muted-foreground">
                    Kelola Unit (fakultas/departemen) di bawah Organisasi ini.
                </p>
            </div>

            {
                resolvedOrganizationId !== null
                    ? (
                        <CreateOrganizationUnitForm
                            organizationId={resolvedOrganizationId}
                        />
                    )
                    : null
            }

            {
                unitsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat daftar Unit…
                        </p>
                    )
                    : null
            }

            {
                unitsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            {
                                unitsQuery.error.kind === 'response'
                                && unitsQuery.error.status === 404
                                    ? 'Organisasi tidak ditemukan.'
                                    : 'Gagal memuat daftar Unit. Coba muat ulang halaman ini.'
                            }
                        </div>
                    )
                    : null
            }

            {
                unitsQuery.status === 'success'
                    ? (
                        unitsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada Unit yang dibuat.
                                </p>
                            )
                            : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Nama
                                            </TableHead>
                                            <TableHead>
                                                Kode
                                            </TableHead>
                                            <TableHead>
                                                Status
                                            </TableHead>
                                            <TableHead>
                                                Dibuat Pada
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            unitsQuery.data.map(
                                                (
                                                    unit,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            unit.id
                                                        }
                                                    >
                                                        <TableCell className="font-medium">
                                                            {
                                                                unit.name
                                                            }
                                                        </TableCell>

                                                        <TableCell>
                                                            {
                                                                unit.code
                                                                ?? (
                                                                    <span className="text-muted-foreground">
                                                                        —
                                                                    </span>
                                                                )
                                                            }
                                                        </TableCell>

                                                        <TableCell>
                                                            <Badge
                                                                variant={
                                                                    unit.is_active
                                                                        ? 'success'
                                                                        : 'secondary'
                                                                }
                                                            >
                                                                {
                                                                    unit.is_active
                                                                        ? 'Aktif'
                                                                        : 'Nonaktif'
                                                                }
                                                            </Badge>
                                                        </TableCell>

                                                        <TableCell>
                                                            {
                                                                formatCreatedAt(
                                                                    unit.created_at,
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
        </section>
    );
}
