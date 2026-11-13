import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';

type Result = {
    period: string;
    start_date: string;
    end_date: string;
    assessment: {
        group: string;
        scores: { name: string; weight: number; score: number }[];
        final_score: number;
        predicate: string;
        predicate_label: string;
        strengths: string | null;
        suggestions: string | null;
        assessed_by: string | null;
        assessed_on: string;
    };
};

type Props = {
    linked: boolean;
    results: Result[];
};

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });
const scoreFormat = new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Kinerja saya (PRD-001 §19.2, HR-8b-2; OD-HR-17): pegawai melihat hasil penilaian kinerjanya setelah periode
 * dikunci Admin SDM.
 */
export default function MyPerformance({ linked, results }: Props) {
    return (
        <>
            <Head title="Kinerja saya" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Kinerja saya"
                    description="Hasil penilaian kinerja Anda. Hasil tampil setelah periode penilaian dikunci Admin SDM."
                />

                {!linked ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Akun ini belum tertaut ke data pegawai. Hubungi Admin
                        SDM bila Anda pegawai yayasan.
                    </p>
                ) : results.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada hasil penilaian kinerja yang bisa dilihat.
                    </p>
                ) : (
                    <ul className="space-y-4">
                        {results.map((result) => (
                            <li
                                key={result.period}
                                className="space-y-3 rounded-xl border p-4 text-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0 space-y-0.5">
                                        <h2 className="text-base font-medium break-words">
                                            {result.period}
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(result.start_date)} –{' '}
                                            {formatDate(result.end_date)}
                                        </p>
                                    </div>
                                    <Badge>
                                        {scoreFormat.format(
                                            result.assessment.final_score,
                                        )}{' '}
                                        · {result.assessment.predicate_label}
                                    </Badge>
                                </div>

                                <ul className="divide-y rounded-lg border">
                                    {result.assessment.scores.map((score) => (
                                        <li
                                            key={score.name}
                                            className="flex items-center justify-between gap-2 px-3 py-2"
                                        >
                                            <span className="break-words">
                                                {score.name}{' '}
                                                <span className="text-muted-foreground">
                                                    ({score.weight}%)
                                                </span>
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {score.score}
                                            </span>
                                        </li>
                                    ))}
                                </ul>

                                {result.assessment.strengths && (
                                    <p className="break-words">
                                        <span className="font-medium">
                                            Kekuatan:
                                        </span>{' '}
                                        {result.assessment.strengths}
                                    </p>
                                )}
                                {result.assessment.suggestions && (
                                    <p className="break-words">
                                        <span className="font-medium">
                                            Saran pengembangan:
                                        </span>{' '}
                                        {result.assessment.suggestions}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Dinilai{' '}
                                    {formatDate(result.assessment.assessed_on)}
                                    {result.assessment.assessed_by &&
                                        ` oleh ${result.assessment.assessed_by}`}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
