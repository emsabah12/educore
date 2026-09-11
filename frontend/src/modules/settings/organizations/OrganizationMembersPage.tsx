import {
    useEffect,
    useState,
} from 'react';
import {
    Link,
    useParams,
} from 'react-router';

import {
    useMembershipCandidatesQuery,
    type MembershipCandidateResource,
} from '@/modules/settings/organizations/api/use-membership-candidates-query';
import {
    useCreateOrganizationalAssignmentMutation,
    useDeactivateOrganizationalAssignmentMutation,
} from '@/modules/settings/organizations/api/use-organizational-assignment-mutations';
import {
    useOrganizationalAssignmentsQuery,
    type OrganizationalAssignmentResource,
} from '@/modules/settings/organizations/api/use-organizational-assignments-query';
import {
    useOrganizationUnitsQuery,
} from '@/modules/settings/organizations/api/use-organization-units-query';
import {
    useOrganizationsQuery,
} from '@/modules/settings/organizations/api/use-organizations-query';
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

const ORGANIZATION_LEVEL_UNIT_VALUE =
    '';

/*
 * Small local debounce — no debounce helper exists elsewhere in
 * this codebase yet, and pulling in a dependency for one 6-line
 * hook would cost more than it saves. Delays re-firing the
 * candidate search until the person pauses typing for 300ms.
 */
function useDebouncedValue(
    value:
        string,
    delayMs:
        number,
): string {
    const [
        debounced,
        setDebounced,
    ] = useState(value);

    useEffect(
        () => {
            const timer =
                setTimeout(
                    () =>
                        setDebounced(
                            value,
                        ),
                    delayMs,
                );

            return () =>
                clearTimeout(
                    timer,
                );
        },
        [
            value,
            delayMs,
        ],
    );

    return debounced;
}

function MembershipPicker(
    {
        organizationId,
        selected,
        onSelect,
    }: {
        organizationId:
            string;
        selected:
            MembershipCandidateResource | null;
        onSelect:
            (
                candidate:
                    MembershipCandidateResource | null,
            ) => void;
    },
) {
    const [
        query,
        setQuery,
    ] = useState('');

    const debouncedQuery =
        useDebouncedValue(
            query,
            300,
        );

    const candidatesQuery =
        useMembershipCandidatesQuery(
            organizationId,
            debouncedQuery,
        );

    if (selected !== null) {
        return (
            <div className="flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm">
                <span className="font-medium">
                    {
                        selected.name
                    }
                </span>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={
                        () => {
                            onSelect(
                                null,
                            );

                            setQuery(
                                '',
                            );
                        }
                    }
                >
                    Ganti
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <Input
                id="assign-member-search"
                value={query}
                placeholder="Ketik nama orang… (minimal 2 huruf)"
                autoComplete="off"
                onChange={
                    (
                        event,
                    ) =>
                        setQuery(
                            event.target.value,
                        )
                }
            />

            {
                candidatesQuery.status === 'success'
                && candidatesQuery.data.length > 0
                    ? (
                        <ul className="max-h-48 space-y-1 overflow-y-auto rounded-md border p-1">
                            {
                                candidatesQuery.data.map(
                                    (
                                        candidate,
                                    ) => (
                                        <li
                                            key={
                                                candidate.membership_id
                                            }
                                        >
                                            <button
                                                type="button"
                                                className="w-full rounded-sm px-2 py-1 text-left text-sm hover:bg-accent"
                                                onClick={
                                                    () =>
                                                        onSelect(
                                                            candidate,
                                                        )
                                                }
                                            >
                                                {
                                                    candidate.name
                                                }
                                            </button>
                                        </li>
                                    ),
                                )
                            }
                        </ul>
                    )
                    : null
            }

            {
                candidatesQuery.status === 'success'
                && candidatesQuery.data.length === 0
                && debouncedQuery.trim().length >= 2
                    ? (
                        <p className="text-xs text-muted-foreground">
                            Tidak ada orang ditemukan.
                        </p>
                    )
                    : null
            }
        </div>
    );
}

function AssignMemberForm(
    {
        organizationId,
        units,
    }: {
        organizationId:
            string;
        units:
            readonly {
                readonly id:
                    string;
                readonly name:
                    string;
            }[];
    },
) {
    const [
        selectedCandidate,
        setSelectedCandidate,
    ] = useState<MembershipCandidateResource | null>(
        null,
    );

    const [
        unitId,
        setUnitId,
    ] = useState(
        ORGANIZATION_LEVEL_UNIT_VALUE,
    );

    /*
     * Bumped on every successful submit so <MembershipPicker> below
     * remounts with fresh internal state (its search query) —
     * the idiomatic React way to reset a child's own state from an
     * event, rather than reaching for a state-syncing effect.
     */
    const [
        pickerResetToken,
        setPickerResetToken,
    ] = useState(0);

    const mutation =
        useCreateOrganizationalAssignmentMutation(
            organizationId,
        );

    function handleSubmit(
        event:
            React.FormEvent,
    ) {
        event.preventDefault();

        if (selectedCandidate === null) {
            return;
        }

        mutation.mutate(
            {
                membership_id:
                    selectedCandidate.membership_id,

                organization_unit_id:
                    unitId === ORGANIZATION_LEVEL_UNIT_VALUE
                        ? null
                        : unitId,
            },
            {
                onSuccess: () => {
                    setSelectedCandidate(
                        null,
                    );

                    setUnitId(
                        ORGANIZATION_LEVEL_UNIT_VALUE,
                    );

                    setPickerResetToken(
                        (
                            current,
                        ) =>
                            current + 1,
                    );
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
                Tempatkan Anggota
            </h2>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <label
                        htmlFor="assign-member-search"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Orang
                    </label>

                    <MembershipPicker
                        key={pickerResetToken}
                        organizationId={organizationId}
                        selected={selectedCandidate}
                        onSelect={setSelectedCandidate}
                    />
                </div>

                <div className="space-y-1">
                    <label
                        htmlFor="assign-member-unit"
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Tempatkan di
                    </label>

                    <Select
                        id="assign-member-unit"
                        value={unitId}
                        onChange={
                            (
                                event,
                            ) =>
                                setUnitId(
                                    event.target.value,
                                )
                        }
                    >
                        <option
                            value={
                                ORGANIZATION_LEVEL_UNIT_VALUE
                            }
                        >
                            Langsung di Organisasi
                        </option>

                        {
                            units.map(
                                (
                                    unit,
                                ) => (
                                    <option
                                        key={
                                            unit.id
                                        }
                                        value={
                                            unit.id
                                        }
                                    >
                                        {
                                            unit.name
                                        }
                                    </option>
                                ),
                            )
                        }
                    </Select>
                </div>
            </div>

            {
                mutation.isError
                    ? (
                        <p
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            Gagal menempatkan anggota. Coba lagi.
                        </p>
                    )
                    : null
            }

            <Button
                type="submit"
                disabled={
                    selectedCandidate === null
                    || mutation.isPending
                }
            >
                {
                    mutation.isPending
                        ? 'Menyimpan…'
                        : 'Tempatkan'
                }
            </Button>
        </form>
    );
}

export function OrganizationMembersPage() {
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
     * Same deliberate simplification as OrganizationUnitsPage —
     * no single-Organization GET endpoint exists, so the display
     * name is resolved from the already-fetched Organisasi list.
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

    const assignmentsQuery =
        useOrganizationalAssignmentsQuery(
            resolvedOrganizationId,
        );

    const deactivateMutation =
        useDeactivateOrganizationalAssignmentMutation(
            resolvedOrganizationId
            ?? '',
        );

    return (
        <section
            aria-labelledby="organization-members-heading"
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
                    id="organization-members-heading"
                    className="text-xl font-semibold"
                >
                    {
                        organization !== null
                            ? `Anggota — ${organization.name}`
                            : 'Anggota Organisasi'
                    }
                </h1>

                <p className="text-sm text-muted-foreground">
                    Tempatkan Membership langsung di Organisasi ini,
                    atau di salah satu Unit-nya.
                </p>
            </div>

            {
                resolvedOrganizationId !== null
                    ? (
                        <AssignMemberForm
                            organizationId={resolvedOrganizationId}
                            units={
                                unitsQuery.data
                                ?? []
                            }
                        />
                    )
                    : null
            }

            {
                assignmentsQuery.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat daftar anggota…
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
                            {
                                assignmentsQuery.error.kind === 'response'
                                && assignmentsQuery.error.status === 404
                                    ? 'Organisasi tidak ditemukan.'
                                    : 'Gagal memuat daftar anggota. Coba muat ulang halaman ini.'
                            }
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
                                    Belum ada anggota yang ditempatkan.
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
                                                Ditempatkan di
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
                                            assignmentsQuery.data.map(
                                                (
                                                    assignment,
                                                ) => (
                                                    <AssignmentRow
                                                        key={
                                                            assignment.id
                                                        }
                                                        assignment={
                                                            assignment
                                                        }
                                                        onDeactivate={
                                                            (
                                                                assignmentId,
                                                            ) =>
                                                                deactivateMutation.mutate(
                                                                    {
                                                                        assignmentId,
                                                                    },
                                                                )
                                                        }
                                                        isDeactivating={
                                                            deactivateMutation.isPending
                                                        }
                                                    />
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

function AssignmentRow(
    {
        assignment,
        onDeactivate,
        isDeactivating,
    }: {
        assignment:
            OrganizationalAssignmentResource;
        onDeactivate:
            (
                assignmentId:
                    string,
            ) => void;
        isDeactivating:
            boolean;
    },
) {
    return (
        <TableRow>
            <TableCell className="font-medium">
                {
                    assignment.membership_name
                }
            </TableCell>

            <TableCell>
                {
                    assignment.organization_unit_name
                    ?? (
                        <span className="text-muted-foreground">
                            Level Organisasi
                        </span>
                    )
                }
            </TableCell>

            <TableCell>
                <Badge
                    variant={
                        assignment.status === 'ACTIVE'
                            ? 'success'
                            : 'secondary'
                    }
                >
                    {
                        assignment.status === 'ACTIVE'
                            ? 'Aktif'
                            : 'Nonaktif'
                    }
                </Badge>
            </TableCell>

            <TableCell>
                {
                    assignment.status === 'ACTIVE'
                        ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={isDeactivating}
                                onClick={
                                    () =>
                                        onDeactivate(
                                            assignment.id,
                                        )
                                }
                            >
                                Nonaktifkan
                            </Button>
                        )
                        : (
                            <span className="text-xs text-muted-foreground">
                                —
                            </span>
                        )
                }
            </TableCell>
        </TableRow>
    );
}
