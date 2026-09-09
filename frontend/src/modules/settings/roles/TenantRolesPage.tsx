import {
    useState,
} from 'react';

import {
    useCreateTenantRoleMutation,
    useUpdateTenantRolePermissionsMutation,
} from '@/modules/settings/api/use-tenant-role-mutations';
import {
    useAssignablePermissionsQuery,
    useTenantRoleQuery,
    useTenantRolesQuery,
    type TenantCustomRoleSummary,
} from '@/modules/settings/api/use-tenant-roles-query';
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

const VISIBILITY_LABEL: Record<
    string,
    {
        label: string;
        variant: 'success' | 'warning' | 'secondary';
    }
> = {
    active: {
        label: 'Aktif',
        variant: 'success',
    },
    locked_readonly: {
        label: 'Masa Tenggang (Baca Saja)',
        variant: 'warning',
    },
    locked_hidden: {
        label: 'Terkunci',
        variant: 'secondary',
    },
};

function VisibilityBadge({
    state,
}: {
    state: string;
}) {
    const config =
        VISIBILITY_LABEL[state]
        ?? {
            label: state,
            variant: 'secondary' as const,
        };

    return (
        <Badge variant={config.variant}>
            {config.label}
        </Badge>
    );
}

function CreateRoleForm() {
    const [
        name,
        setName,
    ] = useState('');

    const [
        displayName,
        setDisplayName,
    ] = useState('');

    const [
        description,
        setDescription,
    ] = useState('');

    const mutation =
        useCreateTenantRoleMutation();

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        mutation.mutate(
            {
                name,
                display_name: displayName,
                description:
                    description === ''
                        ? null
                        : description,
            },
            {
                onSuccess: () => {
                    setName('');
                    setDisplayName('');
                    setDescription('');
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
                Buat Role Kustom Baru
            </h2>

            <div className="grid gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                    <label
                        htmlFor="tenant-role-name"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Nama (identifier)
                    </label>

                    <Input
                        id="tenant-role-name"
                        value={name}
                        placeholder="wali-kelas"
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
                        htmlFor="tenant-role-display-name"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Nama Tampilan
                    </label>

                    <Input
                        id="tenant-role-display-name"
                        value={displayName}
                        placeholder="Wali Kelas"
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setDisplayName(
                                    event.target.value,
                                )
                        }
                    />
                </div>

                <div className="space-y-1">
                    <label
                        htmlFor="tenant-role-description"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Deskripsi (opsional)
                    </label>

                    <Input
                        id="tenant-role-description"
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
                                && mutation.error.error.code === 'CUSTOM_ROLE_FEATURE_NOT_AVAILABLE'
                                    ? 'Tenant Anda saat ini tidak memiliki fitur Custom Role yang aktif.'
                                    : 'Gagal membuat role. Coba lagi.'
                            }
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
                        : 'Buat Role'
                }
            </Button>
        </form>
    );
}

function RolePermissionEditor({
    role,
    onClose,
}: {
    role: TenantCustomRoleSummary;
    onClose: () => void;
}) {
    const detailQuery =
        useTenantRoleQuery(
            role.id,
        );

    const permissionsQuery =
        useAssignablePermissionsQuery();

    const updateMutation =
        useUpdateTenantRolePermissionsMutation();

    const [
        selectedPermissionIds,
        setSelectedPermissionIds,
    ] = useState<
        readonly string[] | null
    >(null);

    const currentSelection =
        selectedPermissionIds
        ?? (
            detailQuery.data?.permissions.map(
                (
                    permission,
                ) =>
                    permission.id,
            )
            ?? []
        );

    function togglePermission(
        permissionId: string,
    ) {
        const isSelected =
            currentSelection.includes(
                permissionId,
            );

        setSelectedPermissionIds(
            isSelected
                ? currentSelection.filter(
                    (
                        id,
                    ) =>
                        id !== permissionId,
                )
                : [
                    ...currentSelection,
                    permissionId,
                ],
        );
    }

    const isEditable =
        detailQuery.data?.visibility_state === 'active';

    const permissionsByModule =
        (
            permissionsQuery.data
            ?? []
        ).reduce<
            Record<
                string,
                typeof permissionsQuery.data
            >
        >(
            (
                groups,
                permission,
            ) => {
                const moduleGroup =
                    groups[permission.module]
                    ?? [];

                return {
                    ...groups,
                    [permission.module]: [
                        ...moduleGroup,
                        permission,
                    ],
                };
            },
            {},
        );

    return (
        <div className="space-y-4 rounded-md border p-4">
            <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold">
                    Kelola Permission —
                    {' '}
                    {
                        role.display_name
                    }
                </h2>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onClose}
                >
                    Tutup
                </Button>
            </div>

            {
                ! isEditable
                && detailQuery.status === 'success'
                    ? (
                        <p className="text-sm text-muted-foreground">
                            Role ini sedang tidak bisa diedit karena fitur Custom Role tidak aktif untuk tenant Anda.
                        </p>
                    )
                    : null
            }

            {
                detailQuery.status === 'pending'
                || permissionsQuery.status === 'pending'
                    ? (
                        <p className="text-sm text-muted-foreground">
                            Memuat…
                        </p>
                    )
                    : null
            }

            {
                detailQuery.status === 'success'
                && permissionsQuery.status === 'success'
                    ? (
                        <div className="space-y-4">
                            {
                                Object.entries(
                                    permissionsByModule,
                                ).map(
                                    ([
                                        moduleName,
                                        permissions,
                                    ]) => (
                                        <div
                                            key={moduleName}
                                            className="space-y-2"
                                        >
                                            <h3 className="text-xs font-semibold uppercase text-muted-foreground">
                                                {
                                                    moduleName
                                                }
                                            </h3>

                                            <div className="grid gap-2 sm:grid-cols-2">
                                                {
                                                    (
                                                        permissions
                                                        ?? []
                                                    ).map(
                                                        (
                                                            permission,
                                                        ) => (
                                                            <label
                                                                key={
                                                                    permission.id
                                                                }
                                                                className="flex items-center gap-2 text-sm"
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    disabled={! isEditable}
                                                                    checked={
                                                                        currentSelection.includes(
                                                                            permission.id,
                                                                        )
                                                                    }
                                                                    onChange={
                                                                        () =>
                                                                            togglePermission(
                                                                                permission.id,
                                                                            )
                                                                    }
                                                                />

                                                                {
                                                                    permission.display_name
                                                                }
                                                            </label>
                                                        ),
                                                    )
                                                }
                                            </div>
                                        </div>
                                    ),
                                )
                            }

                            {
                                isEditable
                                    ? (
                                        <Button
                                            disabled={updateMutation.isPending}
                                            onClick={
                                                () =>
                                                    updateMutation.mutate(
                                                        {
                                                            roleId: role.id,
                                                            permissionIds: currentSelection,
                                                        },
                                                    )
                                            }
                                        >
                                            {
                                                updateMutation.isPending
                                                    ? 'Menyimpan…'
                                                    : 'Simpan Permission'
                                            }
                                        </Button>
                                    )
                                    : null
                            }
                        </div>
                    )
                    : null
            }
        </div>
    );
}

export function TenantRolesPage() {
    const rolesQuery =
        useTenantRolesQuery();

    const [
        selectedRole,
        setSelectedRole,
    ] = useState<
        TenantCustomRoleSummary | null
    >(null);

    return (
        <section
            aria-labelledby="tenant-roles-heading"
            className="space-y-6"
        >
            <div>
                <h1
                    id="tenant-roles-heading"
                    className="text-xl font-semibold"
                >
                    Role Kustom
                </h1>

                <p className="text-sm text-muted-foreground">
                    Buat dan kelola role kustom milik tenant Anda sendiri.
                </p>
            </div>

            <CreateRoleForm />

            {
                rolesQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat daftar role…
                        </p>
                    )
                    : null
            }

            {
                rolesQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat daftar role. Coba muat ulang halaman ini.
                        </div>
                    )
                    : null
            }

            {
                rolesQuery.status === 'success'
                    ? (
                        rolesQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada role kustom yang dibuat.
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
                                                Deskripsi
                                            </TableHead>
                                            <TableHead>
                                                Jumlah Permission
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
                                            rolesQuery.data.map(
                                                (
                                                    role,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            role.id
                                                        }
                                                    >
                                                        <TableCell className="font-medium">
                                                            {
                                                                role.display_name
                                                            }
                                                        </TableCell>

                                                        <TableCell>
                                                            {
                                                                role.description
                                                                ?? (
                                                                    <span className="text-muted-foreground">
                                                                        —
                                                                    </span>
                                                                )
                                                            }
                                                        </TableCell>

                                                        <TableCell>
                                                            {
                                                                role.permission_count
                                                            }
                                                        </TableCell>

                                                        <TableCell>
                                                            <VisibilityBadge
                                                                state={
                                                                    role.visibility_state
                                                                }
                                                            />
                                                        </TableCell>

                                                        <TableCell>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={
                                                                    () =>
                                                                        setSelectedRole(
                                                                            role,
                                                                        )
                                                                }
                                                            >
                                                                Kelola Permission
                                                            </Button>
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

            {
                selectedRole !== null
                    ? (
                        <RolePermissionEditor
                            role={selectedRole}
                            onClose={
                                () =>
                                    setSelectedRole(
                                        null,
                                    )
                            }
                        />
                    )
                    : null
            }
        </section>
    );
}