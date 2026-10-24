import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Download,
    FileUp,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Status = 'SIAP' | 'PERINGATAN' | 'SALAH';

type PreviewRow = {
    line: number;
    status: Status;
    mode: 'BARU' | 'LAMA';
    name: string | null;
    birthDate: string | null;
    nisn: string | null;
    studentNumber: string | null;
    className: string | null;
    guardians: number;
    errors: { column: string; label: string; message: string }[];
    warnings: string[];
};

type Preview = {
    total: number;
    ready: number;
    warning: number;
    error: number;
    existing: number;
    canSave: boolean;
    rows: PreviewRow[];
};

type Column = {
    key: string;
    label: string;
    required: boolean;
    text: boolean;
    hint: string;
};

type Option = { value: string; label: string };

type Pending = { name: string; organization: Option };

type Props = {
    pending: Pending | null;
    preview: Preview | null;
    organizations: Option[];
    columns: Column[];
    limits: { rows: number; kilobytes: number };
};

type Filter = 'ALL' | Status;

const PAGE_SIZE = 100;

const STATUS_LABEL: Record<Status, string> = {
    SIAP: 'Siap',
    PERINGATAN: 'Peringatan',
    SALAH: 'Salah',
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Impor siswa dari Excel/CSV (PRD-002 AC-1e): pilih lembaga → template → unggah → pratinjau.
 * Satu file = satu lembaga. Semua pemeriksaan dilakukan server; halaman ini hanya menampilkan hasilnya.
 */
export default function StudentImport({
    pending,
    preview,
    organizations,
    columns,
    limits,
}: Props) {
    const [organizationId, setOrganizationId] = useState(
        pending?.organization.value ??
            (organizations.length === 1 ? organizations[0].value : ''),
    );

    return (
        <>
            <Head title="Impor siswa" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/siswa"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar siswa
                    </Link>
                </div>

                <Heading
                    title="Impor siswa"
                    description={`Daftarkan banyak siswa sekaligus dari file Excel atau CSV ke satu lembaga. Maksimal ${limits.rows} baris per file.`}
                />

                {organizations.length === 0 ? (
                    <Notice tone="warning">
                        Tidak ada lembaga aktif yang bisa Anda kelola dari
                        lembaga kerja ini.
                    </Notice>
                ) : (
                    <Step number={1} title="Pilih lembaga & unduh template">
                        <div className="grid gap-1.5 sm:max-w-sm">
                            <Label htmlFor="import-organization">
                                Lembaga tujuan
                            </Label>
                            <select
                                id="import-organization"
                                className={selectClass}
                                value={organizationId}
                                onChange={(event) =>
                                    setOrganizationId(event.target.value)
                                }
                            >
                                <option value="" disabled>
                                    Pilih lembaga
                                </option>
                                {organizations.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Template berisi lembar <strong>Siswa</strong> untuk
                            diisi dan <strong>Petunjuk</strong> (arti kolom,
                            kode, dan daftar kelas aktif lembaga ini). Kolom
                            NISN, NIK, dan nomor HP sudah berformat teks. Siswa
                            yang sudah tercatat di yayasan cukup diisi NISN atau
                            NIK-nya. NIK ayah/ibu/wali (opsional) yang sudah
                            tercatat membuat orang tuanya dipakai ulang.
                        </p>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex">
                            {organizationId === '' ? (
                                <Button variant="outline" disabled>
                                    <Download />
                                    Unduh template .xlsx
                                </Button>
                            ) : (
                                <Button variant="outline" asChild>
                                    <a
                                        href={`/siswa/impor/template?lembaga=${organizationId}`}
                                    >
                                        <Download />
                                        Unduh template .xlsx
                                    </a>
                                </Button>
                            )}
                        </div>
                        <details className="rounded-lg border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">
                                Daftar kolom
                            </summary>
                            <ul className="mt-2 space-y-1">
                                {columns.map((column) => (
                                    <li key={column.key}>
                                        <span className="font-mono">
                                            {column.key}
                                        </span>
                                        {column.required && (
                                            <span className="text-red-600 dark:text-red-400">
                                                {' '}
                                                *
                                            </span>
                                        )}{' '}
                                        — {column.label}. {column.hint}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    </Step>
                )}

                <Step number={2} title="Unggah file">
                    <Form
                        action="/siswa/impor"
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="organization_id"
                                    value={organizationId}
                                />
                                <InputError message={errors.organization_id} />
                                <div className="grid gap-1.5">
                                    <Label htmlFor="import-file">
                                        File .xlsx atau .csv (maks.{' '}
                                        {limits.kilobytes / 1024} MB)
                                    </Label>
                                    <input
                                        id="import-file"
                                        name="file"
                                        type="file"
                                        accept=".xlsx,.csv"
                                        required
                                        className="block w-full text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                                    />
                                    <InputError message={errors.file} />
                                </div>
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex">
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || organizationId === ''
                                        }
                                    >
                                        <FileUp />
                                        {processing
                                            ? 'Memeriksa…'
                                            : pending
                                              ? 'Ganti file & periksa'
                                              : 'Unggah & periksa'}
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Belum ada data yang disimpan pada langkah
                                    ini.
                                </p>
                            </>
                        )}
                    </Form>
                </Step>

                {pending && preview && (
                    <PreviewSection
                        // Ganti file → saringan & jumlah baris tampil kembali ke awal.
                        key={`${pending.name}-${preview.total}-${preview.error}-${preview.warning}`}
                        pending={pending}
                        preview={preview}
                    />
                )}
            </div>
        </>
    );
}

function PreviewSection({
    pending,
    preview,
}: {
    pending: Pending;
    preview: Preview;
}) {
    const [filter, setFilter] = useState<Filter>(
        preview.error > 0 ? 'SALAH' : 'ALL',
    );
    const [limit, setLimit] = useState(PAGE_SIZE);

    const rows =
        filter === 'ALL'
            ? preview.rows
            : preview.rows.filter((row) => row.status === filter);
    const visible = rows.slice(0, limit);

    const filters: { value: Filter; label: string; count: number }[] = [
        { value: 'ALL', label: 'Semua', count: preview.total },
        { value: 'SALAH', label: 'Salah', count: preview.error },
        { value: 'PERINGATAN', label: 'Peringatan', count: preview.warning },
        { value: 'SIAP', label: 'Siap', count: preview.ready },
    ];

    return (
        <Step number={3} title="Pratinjau">
            <p className="text-sm break-words text-muted-foreground">
                File: <span className="font-medium">{pending.name}</span> —{' '}
                {preview.total} baris siswa untuk{' '}
                <span className="font-medium">
                    {pending.organization.label}
                </span>
                {preview.existing > 0 &&
                    ` (${preview.existing} di antaranya siswa yang sudah tercatat di yayasan)`}
                .
            </p>

            {preview.error > 0 ? (
                <Notice tone="error">
                    Ada {preview.error} baris yang salah. Perbaiki di file, lalu
                    unggah ulang. Simpan hanya bisa bila semua baris benar.
                </Notice>
            ) : preview.warning > 0 ? (
                <Notice tone="warning">
                    Semua baris bisa disimpan. Periksa {preview.warning} baris
                    bertanda peringatan: mungkin orangnya sudah terdaftar (isi
                    NISN-nya bila orangnya sama).
                </Notice>
            ) : (
                <Notice tone="ok">Semua baris siap disimpan.</Notice>
            )}

            {/* Grid otomatis: tab saringan turun ke baris berikutnya di layar sempit. */}
            <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                {filters.map((item) => (
                    <Button
                        key={item.value}
                        type="button"
                        size="sm"
                        variant={filter === item.value ? 'default' : 'outline'}
                        onClick={() => {
                            setFilter(item.value);
                            setLimit(PAGE_SIZE);
                        }}
                        aria-pressed={filter === item.value}
                    >
                        {item.label} ({item.count})
                    </Button>
                ))}
            </div>

            {visible.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Tidak ada baris dengan status ini.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {visible.map((row) => (
                        <PreviewItem key={row.line} row={row} />
                    ))}
                </ul>
            )}

            {rows.length > visible.length && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setLimit((current) => current + PAGE_SIZE)}
                >
                    Tampilkan{' '}
                    {Math.min(PAGE_SIZE, rows.length - visible.length)} baris
                    berikutnya
                </Button>
            )}

            <div className="space-y-3 border-t pt-4">
                <SaveForm preview={preview} pending={pending} />

                {/* Form terpisah (bukan di dalam form simpan): form HTML tidak boleh bersarang. */}
                <Form
                    action="/siswa/impor/batal"
                    method="post"
                    options={{ preserveScroll: true }}
                    className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex"
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Batalkan impor
                        </Button>
                    )}
                </Form>
            </div>
        </Step>
    );
}

/**
 * Simpan semua baris sekaligus (pola OD-HR-05): hanya bila tidak ada baris Salah, dan peringatan
 * orang ganda harus dikonfirmasi. Server membaca dan memeriksa ulang file sebelum menyimpan.
 */
function SaveForm({
    preview,
    pending,
}: {
    preview: Preview;
    pending: Pending;
}) {
    const [confirmed, setConfirmed] = useState(false);
    const needsConfirmation = preview.warning > 0;
    const blocked = !preview.canSave || (needsConfirmation && !confirmed);

    return (
        <Form
            action="/siswa/impor/simpan"
            method="post"
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    {needsConfirmation && preview.canSave && (
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="confirm_warnings"
                                value="1"
                                checked={confirmed}
                                onChange={(event) =>
                                    setConfirmed(event.target.checked)
                                }
                                className="mt-0.5 size-4 shrink-0"
                            />
                            <span>
                                Saya sudah memeriksa {preview.warning} baris
                                bertanda peringatan; semua orang ini berbeda dan
                                boleh ditambahkan sebagai siswa baru.
                            </span>
                        </label>
                    )}

                    <InputError
                        message={errors.save ?? errors.organization_id}
                    />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={blocked || processing}>
                            {processing
                                ? 'Menyimpan…'
                                : `Simpan ${preview.total} siswa ke ${pending.organization.label}`}
                        </Button>
                    </div>

                    <p className="text-xs text-muted-foreground">
                        {preview.canSave
                            ? 'Semua baris disimpan sekaligus. Bila satu baris gagal, tidak ada yang disimpan. Siswa dan orang tua/wali tidak mendapat akun login.'
                            : 'Simpan tersedia setelah semua baris benar.'}
                    </p>
                </>
            )}
        </Form>
    );
}

function PreviewItem({ row }: { row: PreviewRow }) {
    const Icon =
        row.status === 'SALAH'
            ? XCircle
            : row.status === 'PERINGATAN'
              ? AlertTriangle
              : CheckCircle2;

    return (
        <li className="space-y-2 p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">
                        {row.name ?? (
                            <span className="italic">(tanpa nama)</span>
                        )}
                    </p>
                    <p className="text-sm break-words text-muted-foreground">
                        {[
                            row.nisn ? `NISN ${row.nisn}` : null,
                            row.studentNumber
                                ? `No. induk ${row.studentNumber}`
                                : null,
                            row.className ? `Kelas ${row.className}` : null,
                            row.guardians > 0
                                ? `${row.guardians} orang tua/wali`
                                : null,
                            row.birthDate ? `lahir ${row.birthDate}` : null,
                        ]
                            .filter(Boolean)
                            .join(' · ') || '–'}
                    </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-1.5">
                    <span className="text-xs text-muted-foreground">
                        Baris {row.line}
                    </span>
                    {row.mode === 'LAMA' && (
                        <Badge variant="outline">Siswa lama</Badge>
                    )}
                    <Badge
                        variant={
                            row.status === 'SALAH'
                                ? 'destructive'
                                : row.status === 'PERINGATAN'
                                  ? 'secondary'
                                  : 'outline'
                        }
                    >
                        <Icon />
                        {STATUS_LABEL[row.status]}
                    </Badge>
                </div>
            </div>

            {row.errors.length > 0 && (
                <ul className="space-y-0.5 text-sm text-red-600 dark:text-red-400">
                    {row.errors.map((error, index) => (
                        <li key={index}>
                            <span className="font-medium">{error.label}:</span>{' '}
                            {error.message}
                        </li>
                    ))}
                </ul>
            )}

            {row.warnings.length > 0 && (
                <ul className="space-y-0.5 text-sm text-amber-700 dark:text-amber-400">
                    {row.warnings.map((warning, index) => (
                        <li key={index}>{warning}</li>
                    ))}
                </ul>
            )}
        </li>
    );
}

function Step({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <h2 className="flex items-center gap-2 text-base font-medium">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">
                    {number}
                </span>
                {title}
            </h2>
            {children}
        </section>
    );
}

function Notice({
    tone,
    children,
}: {
    tone: 'ok' | 'warning' | 'error';
    children: React.ReactNode;
}) {
    const className = {
        ok: 'border-emerald-600/30 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200',
        warning:
            'border-amber-600/30 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
        error: 'border-red-600/30 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-200',
    }[tone];

    return (
        <p className={`rounded-lg border px-3 py-2 text-sm ${className}`}>
            {children}
        </p>
    );
}
