import {
    Link,
    useLocation,
    useParams,
} from 'react-router';

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
 * terpilih (M1 bagian 2). Konten sub-resource-nya (Compensation
 * Assignment, Benefit Participation/Identifier, Compensation
 * Adjustment) MEMANG belum ada di sini — itu cakupan M3–M5,
 * dibangun bertahap di milestone berikutnya, bukan sesuatu yang
 * terlewat. Halaman ini tetap "hidup" (bukan dead-end): pegawai
 * dan konteks Employment yang dipilih sudah tampil, kerangka
 * navigasi sudah terpasang.
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

            <div className="rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                <p className="font-medium text-foreground">
                    Modul kompensasi &amp; benefit untuk employment ini akan
                    tersedia di sini secara bertahap:
                </p>

                <ul className="mt-3 list-disc space-y-1 pl-5">
                    <li>
                        Riwayat gaji &amp; tunjangan (Compensation Assignment)
                    </li>
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
