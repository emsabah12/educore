import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, Download } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type RecapRow = {
    id: string;
    name: string;
    organization_id: string | null;
    organization: string;
    missing: string[];
    complete: number;
    nuptk_missing: boolean;
};

type OrganizationRow = {
    id: string;
    name: string;
    employees: number;
    missing: string[];
};

type Props = {
    recap: {
        total: number;
        complete: number;
        truncated: boolean;
        rows: RecapRow[];
        organizations: OrganizationRow[];
    };
    checked: string[];
    organization: string | null;
    organizations: Option[];
    canEditOrganizations: boolean;
    today: string;
};

/**
 * Rekap PTK Simpatika (PRD-001 §21.2, HR-10b): kelengkapan isian PTK per pegawai aktif & data resmi lembaga,
 * plus unduhan .xlsx. Mengikuti cakupan lihat pengguna.
 */
export default function PtkRecap({
    recap,
    checked,
    organization,
    organizations,
    canEditOrganizations,
    today,
}: Props) {
    const [onlyIncomplete, setOnlyIncomplete] = useState(true);
    const query = organization ? `?lembaga=${organization}` : '';
    const incomplete = recap.total - recap.complete;
    const rows = onlyIncomplete
        ? recap.rows.filter((row) => row.missing.length > 0)
        : recap.rows;
    const orgGaps = recap.organizations.filter(
        (item) => item.missing.length > 0,
    );

    return (
        <>
            <Head title="Rekap PTK" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link href={`/pegawai/laporan${query}`}>
                        <ArrowLeft />
                        Laporan pegawai
                    </Link>
                </Button>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Rekap PTK"
                        description={`Kelengkapan data PTK untuk Simpatika per ${today}: pegawai aktif menurut lembaga penempatan utama, hanya yang bisa Anda lihat.`}
                    />
                    <Button variant="outline" asChild>
                        <a href={`/pegawai/rekap-ptk/unduh${query}`}>
                            <Download />
                            Unduh .xlsx
                        </a>
                    </Button>
                </div>

                <p className="max-w-3xl text-sm text-muted-foreground">
                    File unduhan memuat NIK, nama ibu kandung, tanggal lahir,
                    HP, dan alamat untuk pegawai yang boleh Anda lihat data
                    pribadinya. Setiap unduhan dicatat di audit; simpan file
                    dengan hati-hati.
                </p>

                <div className="grid max-w-md gap-1.5">
                    <Label htmlFor="lembaga">Lembaga</Label>
                    <select
                        id="lembaga"
                        className={selectClass}
                        value={organization ?? ''}
                        onChange={(event) =>
                            router.get(
                                '/pegawai/rekap-ptk',
                                { lembaga: event.target.value || undefined },
                                { preserveState: false, replace: true },
                            )
                        }
                    >
                        <option value="">Semua lembaga yang terlihat</option>
                        {organizations.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                    <Stat label="Pegawai aktif" value={recap.total} />
                    <Stat label="Data lengkap" value={recap.complete} />
                    <Stat label="Belum lengkap" value={incomplete} />
                </div>

                {recap.truncated && (
                    <p className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        Hanya 5.000 pegawai pertama yang ditampilkan. Pilih satu
                        lembaga untuk melihat semuanya.
                    </p>
                )}

                {orgGaps.length > 0 && (
                    <section
                        aria-label="Data resmi lembaga belum lengkap"
                        className="space-y-2 rounded-xl border border-amber-300 p-4 dark:border-amber-800"
                    >
                        <h2 className="flex items-center gap-2 text-sm font-medium">
                            <AlertTriangle className="size-4 text-amber-600" />
                            Data resmi lembaga belum lengkap
                        </h2>
                        <ul className="space-y-1 text-sm">
                            {orgGaps.map((item) => (
                                <li key={item.id} className="break-words">
                                    <span className="font-medium">
                                        {item.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        — belum ada {item.missing.join(', ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-muted-foreground">
                            {canEditOrganizations ? (
                                <>
                                    Lengkapi lewat{' '}
                                    <Link
                                        href="/lembaga"
                                        className="underline underline-offset-2"
                                    >
                                        halaman Lembaga
                                    </Link>{' '}
                                    → pilih lembaga → Data resmi.
                                </>
                            ) : (
                                'Minta Admin Yayasan melengkapi lewat halaman Lembaga → Data resmi.'
                            )}
                        </p>
                    </section>
                )}

                <section aria-label="Kelengkapan pegawai" className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-medium">
                            Kelengkapan per pegawai
                        </h2>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={onlyIncomplete}
                                onChange={(event) =>
                                    setOnlyIncomplete(event.target.checked)
                                }
                            />
                            Hanya yang belum lengkap
                        </label>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Dicek {checked.length} isian: {checked.join(', ')}.
                        NUPTK hanya ditandai karena pegawai baru wajar belum
                        punya.
                    </p>

                    {recap.total === 0 ? (
                        <Empty text="Tidak ada pegawai aktif di cakupan ini." />
                    ) : rows.length === 0 ? (
                        <Empty
                            text="Semua data PTK sudah lengkap."
                            success
                        />
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {rows.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <Link
                                            href={`/pegawai/${row.id}?tab=ptk`}
                                            className="font-medium break-words underline-offset-2 hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        <p className="text-sm break-words text-muted-foreground">
                                            {row.organization}
                                        </p>
                                        <div className="flex flex-wrap gap-1">
                                            {row.missing.map((item) => (
                                                <Badge
                                                    key={item}
                                                    variant="outline"
                                                    className="border-amber-500/50 text-amber-800 dark:text-amber-200"
                                                >
                                                    {item}
                                                </Badge>
                                            ))}
                                            {row.nuptk_missing && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-dashed text-muted-foreground"
                                                >
                                                    Belum ada NUPTK
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                    <span
                                        className={
                                            'shrink-0 text-sm tabular-nums ' +
                                            (row.missing.length === 0
                                                ? 'text-emerald-700 dark:text-emerald-300'
                                                : 'text-muted-foreground')
                                        }
                                    >
                                        Lengkap {row.complete} dari{' '}
                                        {checked.length}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-3xl font-semibold tabular-nums">{value}</p>
        </div>
    );
}

function Empty({ text, success = false }: { text: string; success?: boolean }) {
    return (
        <p className="flex items-center justify-center gap-2 rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {success && <CheckCircle2 className="size-4 text-emerald-600" />}
            {text}
        </p>
    );
}
