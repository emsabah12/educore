import { Head, Link, router } from '@inertiajs/react';
import { CircleAlert, Search } from 'lucide-react';
import { useState } from 'react';
import {
    formatDate,
    formatRupiah,
    selectClass,
} from '@/components/finance/ledger';
import type { LedgerSettings, Option } from '@/components/finance/ledger';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type JournalRow = {
    id: string;
    number: string;
    date: string;
    description: string;
    source: string;
    source_label: string;
    amount: number;
    status: 'SAH' | 'BATAL';
};

type Props = {
    settings: LedgerSettings & { pending: number };
    canManage: boolean;
    scopes: Option[];
    scope: string | null;
    months: Option[];
    month: string | null;
    sources: Option[];
    source: string | null;
    status: string | null;
    search: string;
    searchMax: number;
    journals: {
        rows: JournalRow[];
        total: number;
        page: number;
        last_page: number;
    };
};

const URL = '/keuangan/pembukuan/jurnal';

/**
 * Daftar jurnal (PRD-007 §7, §9; PB-1): jurnal otomatis dari tagihan, kas, mutasi, saldo rekening — saring lingkup,
 * bulan, sumber, status, cari nomor/uraian. Hanya baca.
 */
export default function Journals({
    settings,
    canManage,
    scopes,
    scope,
    months,
    month,
    sources,
    source,
    status,
    search,
    searchMax,
    journals,
}: Props) {
    const [query, setQuery] = useState(search);

    const visit = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            lingkup: scope,
            bulan: month,
            sumber: source,
            status,
            cari: search || null,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        router.get(URL, clean, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Jurnal" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Jurnal"
                    description="Jurnal berpasangan yang disusun otomatis dari tagihan, kas, tabungan, dan mutasi. Jurnal manual menyusul."
                />

                {!settings.active ? (
                    <div className="space-y-3 rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p>Pembukuan belum aktif.</p>
                        {canManage ? (
                            <Button asChild>
                                <Link href="/keuangan/pembukuan/pengaturan?tab=periode">
                                    Atur tanggal mulai pembukuan
                                </Link>
                            </Button>
                        ) : (
                            <p>Hubungi Admin Keuangan untuk mengaktifkannya.</p>
                        )}
                    </div>
                ) : (
                    <>
                        <Notices settings={settings} canManage={canManage} />

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <SelectField
                                id="jr-scope"
                                label="Lingkup"
                                value={scope ?? ''}
                                options={scopes}
                                onChange={(value) =>
                                    visit({ lingkup: value, halaman: null })
                                }
                            />
                            <SelectField
                                id="jr-month"
                                label="Bulan"
                                value={month ?? ''}
                                options={[
                                    { value: '', label: 'Semua bulan' },
                                    ...months,
                                ]}
                                onChange={(value) =>
                                    visit({
                                        bulan: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                            <SelectField
                                id="jr-source"
                                label="Sumber"
                                value={source ?? ''}
                                options={[
                                    { value: '', label: 'Semua sumber' },
                                    ...sources,
                                ]}
                                onChange={(value) =>
                                    visit({
                                        sumber: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                            <SelectField
                                id="jr-status"
                                label="Status"
                                value={status ?? ''}
                                options={[
                                    { value: '', label: 'Semua status' },
                                    { value: 'SAH', label: 'Sah' },
                                    { value: 'BATAL', label: 'Batal' },
                                ]}
                                onChange={(value) =>
                                    visit({
                                        status: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                        </div>

                        <form
                            className="flex gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                visit({
                                    cari: query.trim() || null,
                                    halaman: null,
                                });
                            }}
                        >
                            <Label htmlFor="jr-search" className="sr-only">
                                Cari nomor atau uraian
                            </Label>
                            <Input
                                id="jr-search"
                                value={query}
                                maxLength={searchMax}
                                placeholder="Cari nomor jurnal atau uraian"
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                            <Button type="submit" variant="outline">
                                <Search />
                                Cari
                            </Button>
                        </form>

                        <p className="text-sm text-muted-foreground">
                            {journals.total} jurnal
                        </p>

                        {journals.rows.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Belum ada jurnal yang cocok dengan saringan.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {journals.rows.map((row) => (
                                    <li key={row.id}>
                                        <Link
                                            href={`${URL}/${row.id}`}
                                            className="flex flex-wrap items-start justify-between gap-3 p-4 text-sm hover:bg-muted/40"
                                        >
                                            <div className="min-w-0 space-y-0.5">
                                                <p
                                                    className={
                                                        row.status === 'BATAL'
                                                            ? 'font-medium text-muted-foreground line-through'
                                                            : 'font-medium'
                                                    }
                                                >
                                                    {row.description}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    <span className="font-mono">
                                                        {row.number}
                                                    </span>{' '}
                                                    · {formatDate(row.date)} ·{' '}
                                                    {row.source_label}
                                                </p>
                                            </div>
                                            <div className="flex flex-col items-end gap-1">
                                                <span className="font-semibold tabular-nums">
                                                    {formatRupiah(row.amount)}
                                                </span>
                                                {row.status === 'BATAL' && (
                                                    <Badge variant="outline">
                                                        Batal
                                                    </Badge>
                                                )}
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {journals.last_page > 1 && (
                            <nav
                                aria-label="Halaman"
                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={journals.page <= 1}
                                    onClick={() =>
                                        visit({
                                            halaman: String(journals.page - 1),
                                        })
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <span className="text-muted-foreground">
                                    Halaman {journals.page} dari{' '}
                                    {journals.last_page}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        journals.page >= journals.last_page
                                    }
                                    onClick={() =>
                                        visit({
                                            halaman: String(journals.page + 1),
                                        })
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function Notices({
    settings,
    canManage,
}: {
    settings: Props['settings'];
    canManage: boolean;
}) {
    const messages: string[] = [];

    if (settings.rebuilding) {
        messages.push(
            `Jurnal sedang disusun ulang (${settings.rebuild_done} dari ${settings.rebuild_total} transaksi); daftar ini belum lengkap.`,
        );
    } else if (settings.needs_rebuild) {
        messages.push(
            'Pengaturan pembukuan berubah; sebagian jurnal lama belum disusun ulang.',
        );
    }

    if (settings.pending > 0) {
        messages.push(
            `${settings.pending} transaksi masih menunggu dijurnal. Muat ulang halaman sebentar lagi.`,
        );
    }

    if (messages.length === 0) {
        return null;
    }

    return (
        <div className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-1">
                {messages.map((message) => (
                    <p key={message}>{message}</p>
                ))}
                {canManage &&
                    (settings.rebuilding || settings.needs_rebuild) && (
                        <Link
                            href="/keuangan/pembukuan/pengaturan?tab=periode"
                            className="font-medium underline underline-offset-4"
                        >
                            Buka pengaturan periode
                        </Link>
                    )}
            </div>
        </div>
    );
}

function SelectField({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid min-w-0 gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className={selectClass}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}
