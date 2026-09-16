import {
    Link,
    useLocation,
    useParams,
} from 'react-router';

import {
    CompensationAssignmentSection,
} from '@/modules/hr/compensation/CompensationAssignmentSection';
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
 * terpilih (M1 bagian 2). Compensation Assignment (M3) sudah terisi
 * lewat CompensationAssignmentSection. Benefit Participation/
 * Identifier (M4) dan Compensation Adjustment (M5) MEMANG belum ada
 * di sini — dibangun bertahap di milestone berikutnya, bukan
 * sesuatu yang terlewat.
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

            <div className="rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                <p className="font-medium text-foreground">
                    Modul berikut akan tersedia di sini secara bertahap:
                </p>

                <ul className="mt-3 list-disc space-y-1 pl-5">
                    <li>
                        Kepesertaan benefit — BPJS, asuransi (Benefit
                        Participation)
                    </li>
                    <li>
                        Pengajuan penyesuaian kompensasi (Compensation
                        Adjustment)
                    </li>
                </ul>
            </div>

            <p className="text-xs text-muted-foreground">
                ID Employment: {employmentId}
            </p>
        </section>
    );
}
