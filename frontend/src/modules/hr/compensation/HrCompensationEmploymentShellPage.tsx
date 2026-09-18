import {
    Link,
    useLocation,
    useParams,
} from 'react-router';

import {
    BenefitParticipationSection,
} from '@/modules/hr/compensation/BenefitParticipationSection';
import {
    CompensationAdjustmentSection,
} from '@/modules/hr/compensation/CompensationAdjustmentSection';
import {
    CompensationAssignmentSection,
} from '@/modules/hr/compensation/CompensationAssignmentSection';
import {
    LeaveRequestSection,
} from '@/modules/hr/leave/LeaveRequestSection';
import {
    Badge,
    Button,
} from '@/shared/ui';

const EMPLOYMENT_STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary'
> = {
    ACTIVE: 'success',
    PLANNED: 'warning',
    ENDED: 'secondary',
    CANCELLED: 'secondary',
};

const EMPLOYMENT_STATUS_LABEL: Record<string, string> = {
    ACTIVE: 'Aktif',
    PLANNED: 'Direncanakan',
    ENDED: 'Berakhir',
    CANCELLED: 'Dibatalkan',
};

interface EmploymentSelectionNavigationState {
    readonly employeeName?: string;
    readonly employmentStatus?: string;
    readonly employmentStartDate?: string;
    readonly employmentEndDate?: string | null;
}

/*
 * Shell halaman Compensation & Benefit untuk satu Employment
 * terpilih (M1 bagian 2). Compensation Assignment (M3), Benefit
 * Participation/Identifier (M4), dan Compensation Adjustment (M5)
 * semuanya sudah terisi — ini menuntaskan seluruh modul Compensation
 * & Benefit sesuai rencana milestone.
 */
export function HrCompensationEmploymentShellPage() {
    const {
        employmentId,
    } = useParams<{
        employmentId:
            string;
    }>();

    const location =
        useLocation();

    const navigationState =
        location.state as
            | EmploymentSelectionNavigationState
            | null;

    const employeeName =
        navigationState?.employeeName
        ?? null;

    const employmentStatus =
        navigationState?.employmentStatus
        ?? null;

    return (
        <section
            aria-labelledby="hr-compensation-employment-shell-heading"
            className="space-y-6"
        >
            <Button
                asChild
                variant="ghost"
                size="sm"
            >
                <Link to="/hr/compensation">
                    ← Kembali ke Pencarian Pegawai
                </Link>
            </Button>

            <div className="flex flex-wrap items-center gap-3">
                <h1
                    id="hr-compensation-employment-shell-heading"
                    className="text-xl font-semibold"
                >
                    {
                        employeeName
                        ?? 'Kompensasi & Benefit Employment'
                    }
                </h1>

                {
                    employmentStatus !== null
                        ? (
                            <Badge
                                variant={
                                    EMPLOYMENT_STATUS_VARIANT[
                                        employmentStatus
                                    ]
                                }
                            >
                                {
                                    EMPLOYMENT_STATUS_LABEL[
                                        employmentStatus
                                    ]
                                    ?? employmentStatus
                                }
                            </Badge>
                        )
                        : null
                }
            </div>

            {
                navigationState?.employmentStartDate
                    ? (
                        <p className="text-sm text-muted-foreground">
                            {
                                navigationState.employmentStartDate
                            }
                            {
                                ' – '
                            }
                            {
                                navigationState.employmentEndDate
                                ?? 'sekarang'
                            }
                        </p>
                    )
                    : null
            }

            {
                employmentId !== undefined
                    ? (
                        <CompensationAssignmentSection
                            employmentId={
                                employmentId
                            }
                        />
                    )
                    : null
            }

            {
                employmentId !== undefined
                    ? (
                        <BenefitParticipationSection
                            employmentId={
                                employmentId
                            }
                        />
                    )
                    : null
            }

            {
                employmentId !== undefined
                    ? (
                        <CompensationAdjustmentSection
                            employmentId={
                                employmentId
                            }
                        />
                    )
                    : null
            }

            {
                employmentId !== undefined
                    ? (
                        <LeaveRequestSection
                            employmentId={
                                employmentId
                            }
                        />
                    )
                    : null
            }

            <p className="text-xs text-muted-foreground">
                ID Employment: {employmentId}
            </p>
        </section>
    );
}
