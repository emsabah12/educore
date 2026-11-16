import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleCheck,
    CircleX,
    Download,
    FileUp,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Column = {
    key: string;
    label: string;
    required: boolean;
    hint: string;
};

type ResultRow = {
    line: number;
    identifier: string;
    name: string | null;
    room: string;
    bed: string | null;
    locker: string | null;
    mode: string;
    date: string | null;
    error: string | null;
};

type Preview = {
    rows: ResultRow[];
    ready: number;
    errors: number;
    rooms: Record<string, number>;
};

type Props = {
    dormitory: { id: string; code: string; name: string };
    pending: { name: string } | null;
    preview: Preview | null;
    columns: Column[];
    limits: { rows: number; kilobytes: number };
};

type Filter = 'ALL' | 'SALAH' | 'SIAP';

const PAGE_SIZE = 50;

/**
 * Impor penempatan santri (PRD-008 §20): 1) unduh template asrama, 2) unggah, 3) pratinjau Siap/Salah, simpan semua
 * atau tidak. Pemeriksaan memakai aturan yang sama dengan pop-up Tempatkan, berurutan di dalam file.
 */
export default function PlacementImport({
    dormitory,
    pending,
    preview,
    columns,
    limits,
}: Props) {
    const base = `/asrama/${dormitory.id}`;

    return (
        <>
            <Head title={`Impor penempatan · ${dormitory.name}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Link
                    href={`${base}?tab=penghuni`}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    {dormitory.name}
                </Link>

                <Heading
                    title="Impor penempatan"
                    description={`Tempatkan banyak santri ke kamar ${dormitory.name} dari file Excel/CSV. Pengawas tetap lewat halaman kamar.`}
                />

                <Step number={1} title="Unduh template">
                    <p className="text-sm text-muted-foreground">
                        Template berisi daftar gedung & kamar asrama ini beserta
                        sisa slot dan bed/loker yang kosong. Maks. {limits.rows}{' '}
                        baris.
                    </p>
                    <Button variant="outline" asChild>
                        <a href={`${base}/impor/template`}>
                            <Download />
                            Unduh template .xlsx
                        </a>
                    </Button>
                    <details className="text-sm">
                        <summary className="cursor-pointer text-muted-foreground">
                            Arti kolom
                        </summary>
                        <ul className="mt-2 space-y-1">
                            {columns.map((column) => (
                                <li key={column.key}>
                                    <span className="font-mono text-xs">
                                        {column.key}
                                    </span>
                                    {column.required && (
                                        <span className="text-destructive">
                                            {' '}
                                            *
                                        </span>
                                    )}{' '}
                                    — {column.hint}
                                </li>
                            ))}
                        </ul>
                    </details>
                </Step>

                <Step number={2} title="Unggah file">
                    <Form
                        action={`${base}/impor`}
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
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
                                <Button type="submit" disabled={processing}>
                                    <FileUp />
                                    {processing
                                        ? 'Memeriksa…'
                                        : pending
                                          ? 'Ganti file & periksa'
                                          : 'Unggah & periksa'}
                                </Button>
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
                        key={`${pending.name}-${preview.ready}-${preview.errors}`}
                        base={base}
                        dormitoryName={dormitory.name}
                        pending={pending}
                        preview={preview}
                    />
                )}
            </div>
        </>
    );
}

function PreviewSection({
    base,
    dormitoryName,
    pending,
    preview,
}: {
    base: string;
    dormitoryName: string;
    pending: { name: string };
    preview: Preview;
}) {
    const [filter, setFilter] = useState<Filter>(
        preview.errors > 0 ? 'SALAH' : 'ALL',
    );
    const [limit, setLimit] = useState(PAGE_SIZE);
    const rows = preview.rows.filter(
        (row) =>
            filter === 'ALL' ||
            (filter === 'SALAH' ? row.error !== null : row.error === null),
    );
    const visible = rows.slice(0, limit);
    const total = preview.ready + preview.errors;
    const canSave = preview.errors === 0 && preview.ready > 0;

    return (
        <Step number={3} title="Pratinjau">
            <p className="text-sm break-words text-muted-foreground">
                File: <span className="font-medium">{pending.name}</span> —{' '}
                {total} baris.
                {Object.keys(preview.rooms).length > 0 &&
                    ` Siap per kamar: ${Object.entries(preview.rooms)
                        .map(([room, count]) => `${room} (${count})`)
                        .join(', ')}.`}
            </p>

            {preview.errors > 0 ? (
                <Notice tone="error">
                    Ada {preview.errors} baris yang salah. Perbaiki di file,
                    lalu unggah ulang. Simpan hanya bisa bila semua baris benar.
                </Notice>
            ) : (
                <Notice tone="ok">Semua baris siap disimpan.</Notice>
            )}

            <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                {(
                    [
                        { value: 'ALL', label: 'Semua', count: total },
                        {
                            value: 'SALAH',
                            label: 'Salah',
                            count: preview.errors,
                        },
                        { value: 'SIAP', label: 'Siap', count: preview.ready },
                    ] as const
                ).map((item) => (
                    <Button
                        key={item.value}
                        type="button"
                        size="sm"
                        variant={filter === item.value ? 'default' : 'outline'}
                        aria-pressed={filter === item.value}
                        onClick={() => {
                            setFilter(item.value);
                            setLimit(PAGE_SIZE);
                        }}
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
                <Form
                    action={`${base}/impor/simpan`}
                    method="post"
                    options={{ preserveScroll: true }}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.save} />
                            <Button
                                type="submit"
                                disabled={!canSave || processing}
                                className="h-auto w-full py-2 whitespace-normal sm:w-auto"
                            >
                                {processing
                                    ? 'Menyimpan…'
                                    : `Simpan ${preview.ready} penempatan`}
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                {canSave
                                    ? `Semua baris disimpan sekaligus ke ${dormitoryName}. Bila satu baris gagal, tidak ada yang disimpan.`
                                    : 'Simpan tersedia setelah semua baris benar.'}
                            </p>
                        </>
                    )}
                </Form>

                {/* Form terpisah: form HTML tidak boleh bersarang. */}
                <Form
                    action={`${base}/impor/batal`}
                    method="post"
                    options={{ preserveScroll: true }}
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

function PreviewItem({ row }: { row: ResultRow }) {
    const ok = row.error === null;
    const resources = [
        row.bed && `Bed ${row.bed}`,
        row.locker && `Loker ${row.locker}`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className="flex gap-3 px-3 py-2.5">
            {ok ? (
                <CircleCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" />
            ) : (
                <CircleX className="mt-0.5 size-4 shrink-0 text-destructive" />
            )}
            <div className="min-w-0 flex-1 space-y-0.5">
                <p className="text-sm">
                    <span className="text-muted-foreground">
                        Baris {row.line} ·
                    </span>{' '}
                    <span className="font-medium break-words">
                        {row.name ?? row.identifier}
                    </span>
                    {row.name && (
                        <span className="text-xs text-muted-foreground">
                            {' '}
                            ({row.identifier})
                        </span>
                    )}
                </p>
                <p className="text-xs text-muted-foreground">
                    {row.room || '–'}
                    {resources && ` · ${resources}`} · {row.mode || '–'}
                    {row.date && ` · ${row.date}`}
                </p>
                {row.error && (
                    <p className="text-sm break-words text-destructive">
                        {row.error}
                    </p>
                )}
            </div>
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
    children: ReactNode;
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
    tone: 'ok' | 'error';
    children: ReactNode;
}) {
    const className = {
        ok: 'border-emerald-600/30 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200',
        error: 'border-red-600/30 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-200',
    }[tone];

    return (
        <p className={`rounded-lg border px-3 py-2 text-sm ${className}`}>
            {children}
        </p>
    );
}
