import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { useState } from 'react';
import { formatScore } from '@/components/academic/grading';

type Line = {
    name: string;
    kkm: number;
    final: number | null;
    predicate: string | null;
    label: string | null;
    description: string | null;
    aspects: Record<
        string,
        { score: number | null; predicate: string | null; label: string | null }
    >;
    grade_type: 'NUMBER' | 'LETTER';
    group: string | null;
    counts: boolean;
};

/** Baris tabel nilai: judul kelompok bernomor, atau mapel bernomor/bersub-huruf. */
type TableRow =
    | { kind: 'group'; number: number; name: string }
    | { kind: 'subject'; mark: string; line: Line };

type Aspect = { code: string; name: string };

type Card = {
    enrollment_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    left: boolean;
    subjects: Line[];
    total: number | null;
    average: number | null;
    aspect_totals: Record<
        string,
        { total: number | null; average: number | null }
    >;
    attendance: { S: number; I: number; A: number };
    note: string | null;
};

type Props = {
    class: {
        id: string;
        name: string;
        organization: string;
        year: string;
        grade: number;
    };
    term: { value: string; label: string; start: string; end: string };
    report: {
        cards: Card[];
        pending: string[];
        final_subjects: number;
        attendance_kind: 'hari' | 'pertemuan';
        homeroom: string | null;
        aspects: Aspect[];
        multi_aspect: boolean;
        grouped: boolean;
        predicates: { letter: string; label: string | null; min: number }[];
        labels: boolean;
    };
    today: string;
    backUrl: string;
};

const longDate = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

/**
 * Susun baris tabel (AC-8b): lembaga berkelompok → "1 PELAJARAN MADRASAH" lalu a, b, c; mapel tanpa
 * kelompok di bawah "Lainnya". Tanpa kelompok → nomor urut 1, 2, 3.
 */
function tableRows(lines: Line[], grouped: boolean): TableRow[] {
    if (!grouped) {
        return lines.map((line, index) => ({
            kind: 'subject',
            mark: String(index + 1),
            line,
        }));
    }

    const rows: TableRow[] = [];
    let current: string | null | undefined;
    let groupNumber = 0;
    let letter = 0;

    for (const line of lines) {
        if (line.group !== current) {
            current = line.group;
            groupNumber++;
            letter = 0;
            rows.push({
                kind: 'group',
                number: groupNumber,
                name: line.group ?? 'Lainnya',
            });
        }

        rows.push({
            kind: 'subject',
            mark: String.fromCharCode(97 + (letter++ % 26)),
            line,
        });
    }

    return rows;
}

/** Predikat untuk rapor: sebutan bila ada (mis. "Sangat Baik"), selain itu hurufnya. */
function predicateText(predicate: string | null, label: string | null): string {
    return label ?? predicate ?? '–';
}

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return longDate.format(new Date(year, month - 1, day));
}

/**
 * Halaman cetak rapor (PRD-002 §19 AC-5d): satu halaman A4 per siswa. Nama kepala lembaga dan
 * tempat/tanggal diketik di sini (tidak disimpan); cetak lewat dialog cetak browser. Lembaga
 * beraspek (§22 AC-8a): kolom angka & predikat per aspek + rata-rata; deskripsi di bawah tabel.
 * AC-8b: kelompok mapel bernomor, sebutan predikat + keterangan predikat, nilai huruf tanpa angka.
 */
export default function PrintReportCards({
    class: item,
    term,
    report,
    today,
    backUrl,
}: Props) {
    const [place, setPlace] = useState('');
    const [date, setDate] = useState(formatDate(today));
    const [principal, setPrincipal] = useState('');

    return (
        <>
            <Head title={`Rapor ${item.name} ${term.label}`} />
            <style>{'@page { size: A4; margin: 15mm; }'}</style>

            <div className="mx-auto max-w-[210mm] space-y-4 p-4 print:max-w-none print:space-y-0 print:p-0">
                <div className="space-y-3 rounded-lg border border-gray-300 p-4 text-sm print:hidden">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Link
                            href={backUrl}
                            className="inline-flex items-center gap-1 text-gray-600 hover:text-black"
                        >
                            <ArrowLeft className="size-4" />
                            Kembali ke daftar rapor
                        </Link>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="inline-flex items-center gap-2 rounded-md bg-black px-4 py-2 font-medium text-white hover:bg-gray-800"
                        >
                            <Printer className="size-4" />
                            Cetak ({report.cards.length} rapor)
                        </button>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2">
                        <label className="grid gap-1">
                            <span className="text-xs text-gray-600">
                                Tempat
                            </span>
                            <input
                                value={place}
                                onChange={(event) =>
                                    setPlace(event.target.value)
                                }
                                placeholder="mis. Bandung"
                                className="rounded-md border border-gray-300 px-2 py-1.5"
                            />
                        </label>
                        <label className="grid gap-1">
                            <span className="text-xs text-gray-600">
                                Tanggal rapor
                            </span>
                            <input
                                value={date}
                                onChange={(event) =>
                                    setDate(event.target.value)
                                }
                                className="rounded-md border border-gray-300 px-2 py-1.5"
                            />
                        </label>
                        <label className="grid gap-1">
                            <span className="text-xs text-gray-600">
                                Nama kepala lembaga
                            </span>
                            <input
                                value={principal}
                                onChange={(event) =>
                                    setPrincipal(event.target.value)
                                }
                                placeholder="Diketik untuk tanda tangan"
                                className="rounded-md border border-gray-300 px-2 py-1.5"
                            />
                        </label>
                    </div>
                    {report.pending.length > 0 && (
                        <p className="rounded-md border border-amber-500 bg-amber-50 p-2 text-amber-900">
                            Belum masuk rapor karena nilainya belum final:{' '}
                            {report.pending.join(', ')}.
                        </p>
                    )}
                    <p className="text-xs text-gray-600">
                        Isian di atas tidak disimpan. Atur kertas A4 pada dialog
                        cetak; matikan "Header and footer" bila perlu.
                    </p>
                </div>

                {report.cards.map((card) => (
                    <article
                        key={card.enrollment_id}
                        className="break-after-page space-y-4 border border-gray-300 bg-white p-6 text-[11pt] leading-snug print:border-0 print:p-0"
                    >
                        <header className="space-y-1 border-b-2 border-black pb-2 text-center">
                            <p className="text-base font-bold uppercase">
                                Laporan Hasil Belajar
                            </p>
                            <p className="font-semibold">{item.organization}</p>
                        </header>

                        <table className="w-full text-left">
                            <tbody>
                                <Identity label="Nama" value={card.name} />
                                <Identity
                                    label="NISN"
                                    value={card.nisn ?? '—'}
                                />
                                <Identity
                                    label="Nomor induk"
                                    value={card.student_number ?? '—'}
                                />
                                <Identity
                                    label="Kelas"
                                    value={`${item.name} (tingkat ${item.grade})`}
                                />
                                <Identity label="Semester" value={term.label} />
                                <Identity
                                    label="Tahun ajaran"
                                    value={item.year}
                                />
                            </tbody>
                        </table>

                        {report.multi_aspect ? (
                            <AspectTable
                                card={card}
                                aspects={report.aspects}
                                grouped={report.grouped}
                            />
                        ) : (
                            <table className="w-full border-collapse text-left">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <Th className="w-8 text-center">No</Th>
                                        <Th>Mata pelajaran</Th>
                                        <Th className="w-12 text-center">
                                            KKM
                                        </Th>
                                        <Th className="w-14 text-center">
                                            Nilai
                                        </Th>
                                        <Th className="w-16 text-center">
                                            Predikat
                                        </Th>
                                        <Th>Deskripsi</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {card.subjects.length === 0 ? (
                                        <tr>
                                            <Td
                                                className="text-center"
                                                colSpan={6}
                                            >
                                                Belum ada nilai final.
                                            </Td>
                                        </tr>
                                    ) : (
                                        tableRows(
                                            card.subjects,
                                            report.grouped,
                                        ).map((row) =>
                                            row.kind === 'group' ? (
                                                <GroupRow
                                                    key={`g-${row.number}`}
                                                    row={row}
                                                    span={5}
                                                />
                                            ) : (
                                                <tr key={row.line.name}>
                                                    <Td className="text-center">
                                                        {row.mark}
                                                    </Td>
                                                    <Td>{row.line.name}</Td>
                                                    <Td className="text-center">
                                                        {row.line.grade_type ===
                                                        'LETTER'
                                                            ? '–'
                                                            : row.line.kkm}
                                                    </Td>
                                                    <Td className="text-center font-semibold">
                                                        {row.line.final === null
                                                            ? (row.line
                                                                  .predicate ??
                                                              '—')
                                                            : formatScore(
                                                                  row.line
                                                                      .final,
                                                              )}
                                                    </Td>
                                                    <Td className="text-center">
                                                        {predicateText(
                                                            row.line.predicate,
                                                            row.line.label,
                                                        )}
                                                    </Td>
                                                    <Td className="text-[10pt]">
                                                        {row.line.description ??
                                                            ''}
                                                    </Td>
                                                </tr>
                                            ),
                                        )
                                    )}
                                </tbody>
                                {card.total !== null && (
                                    <tfoot>
                                        <tr className="font-semibold">
                                            <Td colSpan={3}>Jumlah</Td>
                                            <Td className="text-center">
                                                {formatScore(card.total)}
                                            </Td>
                                            <Td colSpan={2} />
                                        </tr>
                                        <tr className="font-semibold">
                                            <Td colSpan={3}>Rata-rata</Td>
                                            <Td className="text-center">
                                                {formatScore(card.average)}
                                            </Td>
                                            <Td colSpan={2} />
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        )}

                        {report.labels && (
                            <p className="text-[10pt]">
                                <span className="font-semibold">
                                    Keterangan predikat:
                                </span>{' '}
                                {report.predicates
                                    .map((level) =>
                                        level.label
                                            ? `${level.letter} = ${level.label}`
                                            : level.letter,
                                    )
                                    .join(' · ')}
                            </p>
                        )}

                        <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-4">
                            <table className="h-fit w-full border-collapse text-left">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <Th colSpan={2}>Ketidakhadiran</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(
                                        [
                                            ['Sakit', card.attendance.S],
                                            ['Izin', card.attendance.I],
                                            [
                                                'Tanpa keterangan',
                                                card.attendance.A,
                                            ],
                                        ] as const
                                    ).map(([label, value]) => (
                                        <tr key={label}>
                                            <Td>{label}</Td>
                                            <Td className="text-right whitespace-nowrap">
                                                {value} {report.attendance_kind}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="border border-black">
                                <p className="border-b border-black bg-gray-100 px-2 py-1 font-semibold">
                                    Catatan wali kelas
                                </p>
                                <p className="min-h-16 px-2 py-1 whitespace-pre-line">
                                    {card.note ?? ''}
                                </p>
                            </div>
                        </div>

                        <p className="text-right">
                            {[place, date].filter(Boolean).join(', ')}
                        </p>
                        <div className="grid grid-cols-3 gap-4 text-center">
                            <Signature role="Orang tua/wali" name="" />
                            <Signature
                                role="Wali kelas"
                                name={report.homeroom ?? ''}
                            />
                            <Signature role="Kepala lembaga" name={principal} />
                        </div>
                    </article>
                ))}
            </div>
        </>
    );
}

/** Tabel nilai per aspek: Angka & Predikat tiap aspek, rata-rata; aspek tak dinilai = "–". */
function AspectTable({
    card,
    aspects,
    grouped,
}: {
    card: Card;
    aspects: Aspect[];
    grouped: boolean;
}) {
    const described = card.subjects.filter((line) => line.description);

    return (
        <div className="space-y-2">
            <table className="w-full border-collapse text-left text-[10pt]">
                <thead>
                    <tr className="bg-gray-100">
                        <Th className="w-7 text-center" rowSpan={2}>
                            No
                        </Th>
                        <Th rowSpan={2}>Mata pelajaran</Th>
                        <Th className="w-10 text-center" rowSpan={2}>
                            KKM
                        </Th>
                        {aspects.map((aspect) => (
                            <Th
                                key={aspect.code}
                                className="text-center"
                                colSpan={2}
                            >
                                {aspect.name}
                            </Th>
                        ))}
                        <Th className="w-14 text-center" rowSpan={2}>
                            Rata-rata
                        </Th>
                    </tr>
                    <tr className="bg-gray-100">
                        {aspects.map((aspect) => (
                            <AspectHead key={aspect.code} />
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {card.subjects.length === 0 ? (
                        <tr>
                            <Td
                                className="text-center"
                                colSpan={4 + aspects.length * 2}
                            >
                                Belum ada nilai final.
                            </Td>
                        </tr>
                    ) : (
                        tableRows(card.subjects, grouped).map((row) => {
                            if (row.kind === 'group') {
                                return (
                                    <GroupRow
                                        key={`g-${row.number}`}
                                        row={row}
                                        span={2 + aspects.length * 2 + 1}
                                    />
                                );
                            }

                            const { line } = row;
                            const letter = line.grade_type === 'LETTER';

                            return (
                                <tr key={line.name}>
                                    <Td className="text-center">{row.mark}</Td>
                                    <Td>{line.name}</Td>
                                    <Td className="text-center">
                                        {letter ? '–' : line.kkm}
                                    </Td>
                                    {aspects.map((aspect) => {
                                        const grade = line.aspects[aspect.code];

                                        return (
                                            <AspectCells
                                                key={aspect.code}
                                                value={
                                                    grade === undefined
                                                        ? null
                                                        : letter
                                                          ? grade.predicate
                                                          : grade.score
                                                }
                                                predicate={
                                                    grade === undefined ||
                                                    (grade.score === null &&
                                                        !letter)
                                                        ? null
                                                        : predicateText(
                                                              grade.predicate,
                                                              grade.label,
                                                          )
                                                }
                                            />
                                        );
                                    })}
                                    <Td className="text-center font-semibold">
                                        {line.final === null || !line.counts
                                            ? '–'
                                            : formatScore(line.final, 1)}
                                    </Td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
                {card.total !== null && (
                    <tfoot>
                        <tr className="font-semibold">
                            <Td colSpan={3}>Jumlah</Td>
                            {aspects.map((aspect) => (
                                <FootCells
                                    key={aspect.code}
                                    value={formatScore(
                                        card.aspect_totals[aspect.code]
                                            ?.total ?? null,
                                    )}
                                />
                            ))}
                            <Td className="text-center">
                                {formatScore(card.total, 1)}
                            </Td>
                        </tr>
                        <tr className="font-semibold">
                            <Td colSpan={3}>Rata-rata</Td>
                            {aspects.map((aspect) => (
                                <FootCells
                                    key={aspect.code}
                                    value={formatScore(
                                        card.aspect_totals[aspect.code]
                                            ?.average ?? null,
                                        1,
                                    )}
                                />
                            ))}
                            <Td className="text-center">
                                {formatScore(card.average, 1)}
                            </Td>
                        </tr>
                    </tfoot>
                )}
            </table>

            {described.length > 0 && (
                <div className="text-[10pt]">
                    <p className="font-semibold">Deskripsi capaian</p>
                    <ul className="list-disc pl-5">
                        {described.map((line) => (
                            <li key={line.name}>
                                <span className="font-medium">
                                    {line.name}:
                                </span>{' '}
                                {line.description}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function AspectHead() {
    return (
        <>
            <Th className="w-11 text-center">Angka</Th>
            <Th className="text-center">Predikat</Th>
        </>
    );
}

function AspectCells({
    value,
    predicate,
}: {
    value: number | string | null;
    predicate: string | null;
}) {
    return (
        <>
            <Td className="text-center">{value ?? '–'}</Td>
            <Td className="text-center">{predicate ?? '–'}</Td>
        </>
    );
}

/** Judul kelompok mapel: nomor + nama huruf kapital. */
function GroupRow({
    row,
    span,
}: {
    row: { number: number; name: string };
    span: number;
}) {
    return (
        <tr className="bg-gray-50">
            <Td className="text-center font-semibold">{row.number}</Td>
            <Td className="font-semibold uppercase" colSpan={span}>
                {row.name}
            </Td>
        </tr>
    );
}

function FootCells({ value }: { value: string }) {
    return (
        <>
            <Td className="text-center">{value || '–'}</Td>
            <Td className="bg-gray-100" />
        </>
    );
}

function Identity({ label, value }: { label: string; value: string }) {
    return (
        <tr>
            <td className="w-36 py-0.5 align-top">{label}</td>
            <td className="w-3 py-0.5 align-top">:</td>
            <td className="py-0.5 font-medium">{value}</td>
        </tr>
    );
}

function Th({
    children,
    className = '',
    colSpan,
    rowSpan,
}: {
    children: React.ReactNode;
    className?: string;
    colSpan?: number;
    rowSpan?: number;
}) {
    return (
        <th
            colSpan={colSpan}
            rowSpan={rowSpan}
            className={
                'border border-black px-2 py-1 font-semibold ' + className
            }
        >
            {children}
        </th>
    );
}

function Td({
    children,
    className = '',
    colSpan,
}: {
    children?: React.ReactNode;
    className?: string;
    colSpan?: number;
}) {
    return (
        <td
            colSpan={colSpan}
            className={'border border-black px-2 py-1 align-top ' + className}
        >
            {children}
        </td>
    );
}

function Signature({ role, name }: { role: string; name: string }) {
    return (
        <div className="space-y-14">
            <p>{role}</p>
            <p className="border-t border-black pt-1 font-medium">
                {name || ' '}
            </p>
        </div>
    );
}
