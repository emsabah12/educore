import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AuditEntry = {
    id: string;
    created_at: string;
    action: string;
    action_label: string;
    actor: { name: string; username: string | null } | null;
    organization: string | null;
    subject_user: string | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
};

type Option = { value: string; label: string };

type Filters = {
    dari: string;
    sampai: string;
    pelaku: string;
    aksi: string;
};

type Props = {
    logs: {
        data: AuditEntry[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    actions: Option[];
    actors: Option[];
    errors?: Partial<Record<keyof Filters, string>>;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const dateTime = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/**
 * Log audit baca-saja untuk Admin Yayasan (OD-17). Data sudah disaring server.
 */
export default function AuditLog({
    logs,
    filters,
    actions,
    actors,
    errors = {},
}: Props) {
    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const data = new FormData(event.currentTarget);
        const query: Record<string, string> = {};

        for (const [key, value] of data.entries()) {
            if (typeof value === 'string' && value !== '') {
                query[key] = value;
            }
        }

        router.get('/audit', query, { preserveState: true, replace: true });
    };

    const isFiltered = Object.values(filters).some((value) => value !== '');

    return (
        <>
            <Head title="Log audit" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Log audit"
                    description="Jejak aksi penting di yayasan ini. Hanya bisa dibaca, tidak bisa diubah."
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="dari">Dari tanggal</Label>
                        <Input
                            id="dari"
                            name="dari"
                            type="date"
                            defaultValue={filters.dari}
                        />
                        <InputError message={errors.dari} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="sampai">Sampai tanggal</Label>
                        <Input
                            id="sampai"
                            name="sampai"
                            type="date"
                            defaultValue={filters.sampai}
                        />
                        <InputError message={errors.sampai} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="pelaku">Pelaku</Label>
                        <select
                            id="pelaku"
                            name="pelaku"
                            className={selectClass}
                            defaultValue={filters.pelaku}
                        >
                            <option value="">Semua pelaku</option>
                            {actors.map((actor) => (
                                <option key={actor.value} value={actor.value}>
                                    {actor.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.pelaku} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="aksi">Jenis aksi</Label>
                        <select
                            id="aksi"
                            name="aksi"
                            className={selectClass}
                            defaultValue={filters.aksi}
                        >
                            <option value="">Semua aksi</option>
                            {actions.map((action) => (
                                <option key={action.value} value={action.value}>
                                    {action.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.aksi} />
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" className="flex-1">
                            Terapkan
                        </Button>
                        {isFiltered && (
                            <Button variant="outline" asChild>
                                <Link href="/audit">Reset</Link>
                            </Button>
                        )}
                    </div>
                </form>

                {logs.data.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">
                        {isFiltered
                            ? 'Tidak ada catatan yang cocok dengan filter ini.'
                            : 'Belum ada catatan audit.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {logs.data.map((entry) => (
                            <AuditRow key={entry.id} entry={entry} />
                        ))}
                    </ul>
                )}

                <Pagination logs={logs} />
            </div>
        </>
    );
}

AuditLog.layout = {
    breadcrumbs: [{ title: 'Log audit', href: '/audit' }],
};

function AuditRow({ entry }: { entry: AuditEntry }) {
    const changes = entry.changes ? Object.entries(entry.changes) : [];

    return (
        <li className="grid gap-2 p-4 md:grid-cols-[11rem_minmax(0,1fr)] md:gap-4">
            <div className="text-sm text-muted-foreground">
                <time dateTime={entry.created_at}>
                    {dateTime.format(new Date(entry.created_at))}
                </time>
                {entry.ip && (
                    <div className="font-mono text-xs">{entry.ip}</div>
                )}
            </div>

            <div className="space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{entry.action_label}</Badge>
                    {entry.organization && (
                        <span className="text-sm">{entry.organization}</span>
                    )}
                    {entry.subject_user && (
                        <span className="text-sm">
                            Akun: {entry.subject_user}
                        </span>
                    )}
                </div>

                <p className="text-sm text-muted-foreground">
                    Oleh{' '}
                    {entry.actor ? (
                        <span className="text-foreground">
                            {entry.actor.name}
                            {entry.actor.username && (
                                <> ({entry.actor.username})</>
                            )}
                        </span>
                    ) : (
                        'sistem / tamu'
                    )}
                </p>

                {changes.length > 0 && (
                    <dl className="grid gap-x-3 gap-y-0.5 text-xs sm:grid-cols-[auto_minmax(0,1fr)]">
                        {changes.map(([key, value]) => (
                            <div key={key} className="contents">
                                <dt className="font-medium text-muted-foreground">
                                    {key}
                                </dt>
                                <dd className="font-mono break-all">
                                    {describeChange(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>
        </li>
    );
}

function Pagination({ logs }: { logs: Props['logs'] }) {
    if (logs.last_page <= 1) {
        return logs.total > 0 ? (
            <p className="text-sm text-muted-foreground">
                {logs.total} catatan
            </p>
        ) : null;
    }

    return (
        <nav
            aria-label="Halaman log audit"
            className="flex flex-wrap items-center justify-between gap-3"
        >
            <p className="text-sm text-muted-foreground">
                {logs.from}–{logs.to} dari {logs.total} catatan
            </p>
            <div className="flex gap-2">
                <PageLink href={logs.prev_page_url} label="Sebelumnya">
                    <ChevronLeft />
                    Sebelumnya
                </PageLink>
                <PageLink href={logs.next_page_url} label="Berikutnya">
                    Berikutnya
                    <ChevronRight />
                </PageLink>
            </div>
        </nav>
    );
}

function PageLink({
    href,
    label,
    children,
}: {
    href: string | null;
    label: string;
    children: React.ReactNode;
}) {
    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled aria-label={label}>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll aria-label={label}>
                {children}
            </Link>
        </Button>
    );
}

/** Pasangan [lama, baru] ditampilkan "lama → baru"; nilai lain apa adanya. */
function describeChange(value: unknown): string {
    if (Array.isArray(value) && value.length === 2) {
        return `${formatValue(value[0])} → ${formatValue(value[1])}`;
    }

    return formatValue(value);
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '–';
    }

    if (typeof value === 'boolean') {
        return value ? 'ya' : 'tidak';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
