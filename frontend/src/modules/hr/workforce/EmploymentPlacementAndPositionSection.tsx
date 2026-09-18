import {
    useState,
} from 'react';

import {
    useEmployeeOrganizationalAssignmentsQuery,
} from '@/modules/hr/api/use-employee-organizational-assignments-query';
import {
    useCreatePlacementMutation,
} from '@/modules/hr/api/use-employment-placement-mutations';
import {
    useEmploymentPlacementsQuery,
} from '@/modules/hr/api/use-employment-placements-query';
import {
    useCreatePositionAssignmentMutation,
} from '@/modules/hr/api/use-employment-position-assignment-mutations';
import {
    useEmploymentPositionAssignmentsQuery,
} from '@/modules/hr/api/use-employment-position-assignments-query';
import {
    usePositionsQuery,
} from '@/modules/hr/api/use-positions-query';
import {
    Badge,
    Button,
    Input,
    Select,
} from '@/shared/ui';

function PlacementSubsection({
    employeeId,
    employmentId,
}: {
    employeeId: string;
    employmentId: string;
}) {
    const placementsQuery =
        useEmploymentPlacementsQuery(
            employmentId,
            true,
        );

    const assignmentsQuery =
        useEmployeeOrganizationalAssignmentsQuery(
            employeeId,
            true,
        );

    const createMutation =
        useCreatePlacementMutation();

    const [
        assignmentId,
        setAssignmentId,
    ] = useState('');

    const [
        effectiveFrom,
        setEffectiveFrom,
    ] = useState('');

    function handleSubmit(
        event: React.FormEvent,
    ) {
        event.preventDefault();

        createMutation.mutate(
            {
                employmentId,

                organizationalAssignmentId:
                    assignmentId,

                effectiveFrom,
                isPrimary: true,
            },
            {
                onSuccess: () => {
                    setAssignmentId('');
                    setEffectiveFrom('');
                },
            },
        );
    }

    return (
        <div className="space-y-2">
            <h4 className="text-xs font-semibold text-muted-foreground">
                Placement
            </h4>

            {
                placementsQuery.status === 'success'
                    ? (
                        placementsQuery.data.length === 0
                            ? (
                                <p className="text-xs text-muted-foreground">
                                    Belum ada Placement.
                                </p>
                            )
                            : (
                                <ul className="space-y-1 text-xs">
                                    {
                                        placementsQuery.data.map(
                                            (
                                                placement,
                                            ) => (
                                                <li
                                                    key={
                                                        placement.id
                                                    }
                                                    className="flex items-center gap-2"
                                                >
                                                    <span>
                                                        {
                                                            placement.effective_from
                                                        }
                                                        {
                                                            ' – '
                                                        }
                                                        {
                                                            placement.effective_to
                                                            ?? 'sekarang'
                                                        }
                                                    </span>

                                                    {
                                                        placement.is_primary
                                                            ? (
                                                                <Badge variant="secondary">
                                                                    Utama
                                                                </Badge>
                                                            )
                                                            : null
                                                    }
                                                </li>
                                            ),
                                        )
                                    }
                                </ul>
                            )
                    )
                    : null
            }

            <form
                onSubmit={handleSubmit}
                className="flex flex-wrap items-end gap-2"
            >
                <div className="space-y-1">
                    <label
                        htmlFor={
                            `placement-assignment-${employmentId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Organizational Assignment
                    </label>

                    <Select
                        id={
                            `placement-assignment-${employmentId}`
                        }
                        value={assignmentId}
                        required
                        disabled={
                            assignmentsQuery.status !== 'success'
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setAssignmentId(
                                    event.target.value,
                                )
                        }
                        className="h-8 text-xs"
                    >
                        <option value="">
                            {
                                assignmentsQuery.status === 'pending'
                                    ? 'Memuat…'
                                    : 'Pilih…'
                            }
                        </option>

                        {
                            assignmentsQuery.status === 'success'
                                ? assignmentsQuery.data.map(
                                    (
                                        assignment,
                                    ) => (
                                        <option
                                            key={
                                                assignment.id
                                            }
                                            value={
                                                assignment.id
                                            }
                                        >
                                            {
                                                assignment.organization_name
                                            }
                                            {
                                                assignment.organization_unit_name !== null
                                                    ? ` — ${assignment.organization_unit_name}`
                                                    : ''
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
                        htmlFor={
                            `placement-effective-from-${employmentId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Berlaku Sejak
                    </label>

                    <Input
                        id={
                            `placement-effective-from-${employmentId}`
                        }
                        type="date"
                        value={effectiveFrom}
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setEffectiveFrom(
                                    event.target.value,
                                )
                        }
                        className="h-8 text-xs"
                    />
                </div>

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
                            : 'Tambah Placement'
                    }
                </Button>
            </form>

            {
                createMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            Gagal menambah Placement. Pastikan Employment
                            berstatus Aktif.
                        </p>
                    )
                    : null
            }
        </div>
    );
}

function PositionAssignmentSubsection({
    employmentId,
}: {
    employmentId: string;
}) {
    const assignmentsQuery =
        useEmploymentPositionAssignmentsQuery(
            employmentId,
            true,
        );

    const placementsQuery =
        useEmploymentPlacementsQuery(
            employmentId,
            true,
        );

    const positionsQuery =
        usePositionsQuery();

    const createMutation =
        useCreatePositionAssignmentMutation();

    const [
        positionId,
        setPositionId,
    ] = useState('');

    const [
        placementId,
        setPlacementId,
    ] = useState('');

    const [
        effectiveFrom,
        setEffectiveFrom,
    ] = useState('');

    const positionNameById =
        new Map(
            positionsQuery.status === 'success'
                ? positionsQuery.data.map(
                    (
                        position,
                    ) => [
                        position.id,
                        position.name,
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
                employmentId,

                positionId,

                employmentPlacementId:
                    placementId.trim() === ''
                        ? null
                        : placementId,

                effectiveFrom,
                isPrimary: true,
            },
            {
                onSuccess: () => {
                    setPositionId('');
                    setPlacementId('');
                    setEffectiveFrom('');
                },
            },
        );
    }

    return (
        <div className="space-y-2">
            <h4 className="text-xs font-semibold text-muted-foreground">
                Position Assignment
            </h4>

            {
                assignmentsQuery.status === 'success'
                    ? (
                        assignmentsQuery.data.length === 0
                            ? (
                                <p className="text-xs text-muted-foreground">
                                    Belum ada Position Assignment.
                                </p>
                            )
                            : (
                                <ul className="space-y-1 text-xs">
                                    {
                                        assignmentsQuery.data.map(
                                            (
                                                assignment,
                                            ) => (
                                                <li
                                                    key={
                                                        assignment.id
                                                    }
                                                    className="flex items-center gap-2"
                                                >
                                                    <span>
                                                        {
                                                            positionNameById.get(
                                                                assignment.position_id,
                                                            )
                                                            ?? assignment.position_id
                                                        }
                                                    </span>

                                                    <span className="text-muted-foreground">
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
                                                    </span>

                                                    {
                                                        assignment.is_primary
                                                            ? (
                                                                <Badge variant="secondary">
                                                                    Utama
                                                                </Badge>
                                                            )
                                                            : null
                                                    }
                                                </li>
                                            ),
                                        )
                                    }
                                </ul>
                            )
                    )
                    : null
            }

            <form
                onSubmit={handleSubmit}
                className="flex flex-wrap items-end gap-2"
            >
                <div className="space-y-1">
                    <label
                        htmlFor={
                            `position-assignment-position-${employmentId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Jabatan
                    </label>

                    <Select
                        id={
                            `position-assignment-position-${employmentId}`
                        }
                        value={positionId}
                        required
                        disabled={
                            positionsQuery.status !== 'success'
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setPositionId(
                                    event.target.value,
                                )
                        }
                        className="h-8 text-xs"
                    >
                        <option value="">
                            {
                                positionsQuery.status === 'pending'
                                    ? 'Memuat…'
                                    : 'Pilih…'
                            }
                        </option>

                        {
                            positionsQuery.status === 'success'
                                ? positionsQuery.data.map(
                                    (
                                        position,
                                    ) => (
                                        <option
                                            key={
                                                position.id
                                            }
                                            value={
                                                position.id
                                            }
                                        >
                                            {
                                                position.name
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
                        htmlFor={
                            `position-assignment-placement-${employmentId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Placement (opsional)
                    </label>

                    <Select
                        id={
                            `position-assignment-placement-${employmentId}`
                        }
                        value={placementId}
                        disabled={
                            placementsQuery.status !== 'success'
                        }
                        onChange={
                            (
                                event,
                            ) =>
                                setPlacementId(
                                    event.target.value,
                                )
                        }
                        className="h-8 text-xs"
                    >
                        <option value="">
                            {
                                placementsQuery.status === 'pending'
                                    ? 'Memuat…'
                                    : 'Tidak terkait Placement tertentu'
                            }
                        </option>

                        {
                            placementsQuery.status === 'success'
                                ? placementsQuery.data.map(
                                    (
                                        placement,
                                    ) => (
                                        <option
                                            key={
                                                placement.id
                                            }
                                            value={
                                                placement.id
                                            }
                                        >
                                            {
                                                placement.effective_from
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
                        htmlFor={
                            `position-assignment-effective-from-${employmentId}`
                        }
                        className="text-xs font-medium text-muted-foreground"
                    >
                        Berlaku Sejak
                    </label>

                    <Input
                        id={
                            `position-assignment-effective-from-${employmentId}`
                        }
                        type="date"
                        value={effectiveFrom}
                        required
                        onChange={
                            (
                                event,
                            ) =>
                                setEffectiveFrom(
                                    event.target.value,
                                )
                        }
                        className="h-8 text-xs"
                    />
                </div>

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
                            : 'Tambah Position Assignment'
                    }
                </Button>
            </form>

            {
                createMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            Gagal menambah Position Assignment.
                        </p>
                    )
                    : null
            }
        </div>
    );
}

export function EmploymentPlacementAndPositionSection({
    employeeId,
    employmentId,
}: {
    employeeId: string;
    employmentId: string;
}) {
    const [
        isOpen,
        setIsOpen,
    ] = useState(false);

    if (! isOpen) {
        return (
            <Button
                variant="ghost"
                size="sm"
                onClick={
                    () =>
                        setIsOpen(
                            true,
                        )
                }
            >
                Kelola Penempatan
            </Button>
        );
    }

    return (
        <div className="space-y-4 rounded-md bg-muted/30 p-3">
            <div className="flex items-center justify-between">
                <span className="text-xs font-semibold">
                    Penempatan Employment Ini
                </span>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={
                        () =>
                            setIsOpen(
                                false,
                            )
                    }
                >
                    Tutup
                </Button>
            </div>

            <PlacementSubsection
                employeeId={
                    employeeId
                }
                employmentId={
                    employmentId
                }
            />

            <PositionAssignmentSubsection
                employmentId={
                    employmentId
                }
            />
        </div>
    );
}
