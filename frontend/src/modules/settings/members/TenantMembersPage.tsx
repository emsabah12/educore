import {
    useState,
} from 'react';

import {
    useAssignMembershipRoleMutation,
} from '@/modules/settings/members/api/use-assign-membership-role-mutation';
import {
    useRoleCatalogQuery,
    type RoleSummary,
} from '@/modules/settings/members/api/use-role-catalog-query';
import {
    useTenantMembershipsQuery,
    type TenantMembershipSummary,
} from '@/modules/settings/members/api/use-tenant-memberships-query';
import {
    useTenantRolesQuery,
} from '@/modules/settings/api/use-tenant-roles-query';
import {
    Badge,
    Button,
    Select,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

interface AssignableRole {
    readonly id: string;
    readonly displayName: string;
}

function AssignRoleControl({
    membership,
    assignableRoles,
}: {
    membership: TenantMembershipSummary;
    assignableRoles: readonly AssignableRole[];
}) {
    const [
        selectedRoleId,
        setSelectedRoleId,
    ] = useState('');

    const mutation =
        useAssignMembershipRoleMutation();

    const alreadyAssignedIds =
        new Set(
            membership.roles.map(
                (role) => role.id,
            ),
        );

    const selectableRoles =
        assignableRoles.filter(
            (role) =>
                ! alreadyAssignedIds.has(
                    role.id,
                ),
        );

    function handleAssign() {
        if (selectedRoleId === '') {
            return;
        }

        mutation.mutate(
            {
                targetMembershipId:
                    membership.membership_id,

                roleId:
                    selectedRoleId,
            },
            {
                onSuccess: () => {
                    setSelectedRoleId('');
                },
            },
        );
    }

    if (selectableRoles.length === 0) {
        return (
            <span className="text-xs text-muted-foreground">
                Semua role sudah ditetapkan
            </span>
        );
    }

    return (
        <div className="flex items-center gap-2">
            <Select
                aria-label={
                    `Pilih role untuk ${membership.person_name ?? membership.membership_id}`
                }
                value={selectedRoleId}
                onChange={
                    (
                        event,
                    ) =>
                        setSelectedRoleId(
                            event.target.value,
                        )
                }
            >
                <option value="">
                    Pilih role…
                </option>

                {
                    selectableRoles.map(
                        (role) => (
                            <option
                                key={
                                    role.id
                                }
                                value={
                                    role.id
                                }
                            >
                                {
                                    role.displayName
                                }
                            </option>
                        ),
                    )
                }
            </Select>

            <Button
                type="button"
                size="sm"
                disabled={
                    mutation.isPending
                    || selectedRoleId === ''
                }
                onClick={
                    handleAssign
                }
            >
                {
                    mutation.isPending
                        ? 'Menetapkan…'
                        : 'Tetapkan'
                }
            </Button>

            {
                mutation.isError
                    ? (
                        <span
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            Gagal menetapkan role. Coba lagi.
                        </span>
                    )
                    : null
            }
        </div>
    );
}

export function TenantMembersPage() {
    const membershipsQuery =
        useTenantMembershipsQuery();

    const roleCatalogQuery =
        useRoleCatalogQuery();

    const tenantRolesQuery =
        useTenantRolesQuery();

    const assignableRoles: readonly AssignableRole[] =
        [
            ...(
                roleCatalogQuery.status === 'success'
                    ? roleCatalogQuery.data.map(
                        (
                            role: RoleSummary,
                        ) => ({
                            id: role.id,
                            displayName:
                                role.display_name,
                        }),
                    )
                    : []
            ),

            ...(
                tenantRolesQuery.status === 'success'
                    ? tenantRolesQuery.data.map(
                        (role) => ({
                            id: role.id,
                            displayName:
                                `${role.display_name} (kustom)`,
                        }),
                    )
                    : []
            ),
        ];

    return (
        <section
            aria-labelledby="settings-tenant-members-heading"
            className="space-y-4"
        >
            <div>
                <h1
                    id="settings-tenant-members-heading"
                    className="text-xl font-semibold"
                >
                    Kelola Anggota & Role
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Lihat anggota tenant Anda dan tetapkan role kepada
                    mereka.
                </p>
            </div>

            {
                membershipsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat anggota…
                        </p>
                    )
                    : null
            }

            {
                membershipsQuery.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            Gagal memuat daftar anggota tenant.
                        </div>
                    )
                    : null
            }

            {
                membershipsQuery.status === 'success'
                    ? (
                        membershipsQuery.data.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada anggota aktif di tenant ini.
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
                                                Email
                                            </TableHead>
                                            <TableHead>
                                                Role Saat Ini
                                            </TableHead>
                                            <TableHead>
                                                Tetapkan Role
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>

                                    <TableBody>
                                        {
                                            membershipsQuery.data.map(
                                                (
                                                    membership,
                                                ) => (
                                                    <TableRow
                                                        key={
                                                            membership.membership_id
                                                        }
                                                    >
                                                        <TableCell>
                                                            {
                                                                membership.person_name
                                                                ?? '—'
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                membership.email
                                                                ?? '—'
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                membership.roles.length === 0
                                                                    ? (
                                                                        <span className="text-xs text-muted-foreground">
                                                                            Belum ada role
                                                                        </span>
                                                                    )
                                                                    : (
                                                                        <div className="flex flex-wrap gap-1">
                                                                            {
                                                                                membership.roles.map(
                                                                                    (
                                                                                        role,
                                                                                    ) => (
                                                                                        <Badge
                                                                                            key={
                                                                                                role.id
                                                                                            }
                                                                                            variant="secondary"
                                                                                        >
                                                                                            {
                                                                                                role.display_name
                                                                                            }
                                                                                        </Badge>
                                                                                    ),
                                                                                )
                                                                            }
                                                                        </div>
                                                                    )
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            <AssignRoleControl
                                                                membership={
                                                                    membership
                                                                }
                                                                assignableRoles={
                                                                    assignableRoles
                                                                }
                                                            />
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
