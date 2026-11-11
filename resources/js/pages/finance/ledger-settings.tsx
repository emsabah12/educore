import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    Lock,
    LockOpen,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import { formatDateTime } from '@/components/finance/online-order';
import {
    formatMonth,
    formatNumber,
    formatRupiah,
    selectClass,
} from '@/components/finance/ledger';
import type { LedgerSettings, Option } from '@/components/finance/ledger';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Group = 'ASET' | 'LIABILITAS' | 'ASET_NETO' | 'PENDAPATAN' | 'BEBAN';

type Account = {
    id: string;
    code: string;
    name: string;
    group: Group;
    is_header: boolean;
    parent_id: string | null;
    normal_balance: 'DEBIT' | 'KREDIT';
    is_cash: boolean;
    is_restricted: boolean;
    cash_flow_activity: string | null;
    system_key: string | null;
    is_locked: boolean;
    is_active: boolean;
};

type GroupOption = Option & { prefix: string; normal: 'DEBIT' | 'KREDIT' };

type MappingRow = {
    id: string;
    name: string;
    group: string;
    kind: string | null;
    is_active: boolean;
    account_id: string | null;
    default_account_id: string | null;
    mappable: boolean;
};

type MappingKind = 'rekening' | 'kategori' | 'jenis-biaya';

type Props = {
    settings: LedgerSettings;
    accounts: Account[];
    groups: GroupOption[];
    activities: Option[];
    mappings: Record<MappingKind, MappingRow[]>;
    targets: Record<
        'rekening' | 'kategori-MASUK' | 'kategori-KELUAR' | 'jenis-biaya',
        string[]
    >;
    months: Option[];
    nameMax: number;
    openingScopes: Option[];
    openingScope: string;
    opening: {
        description: string | null;
        lines: {
            account_id: string;
            cash_account: string | null;
            debit: number;
            credit: number;
        }[];
        items: OpeningItem[];
    };
    openingAccounts: Option[];
    noteMax: number;
    lock: PeriodLock;
};

/** Kunci periode / tutup buku (PB-4). */
type PeriodLock = {
    /** Tanggal terakhir yang terkunci (akhir bulan), null = belum ada kunci. */
    through: string | null;
    through_label: string | null;
    month_label: string | null;
    lock_options: Option[];
    /** Nilai '' = buka semua. */
    unlock_options: Option[];
    reason_min: number;
    reason_max: number;
};

type OpeningItem = {
    id: string;
    account_id: string;
    side: 'DEBIT' | 'KREDIT';
    amount: number;
    note: string | null;
};

const BASE = '/keuangan/pembukuan';

const TABS = ['akun', 'pemetaan', 'saldo-awal', 'periode'] as const;

const RESTRICTABLE: Group[] = ['PENDAPATAN', 'ASET_NETO'];

/**
 * Pengaturan pembukuan (PRD-007 §3, §5, §9; PB-1): COA, pemetaan akun untuk jurnal otomatis, periode & susun ulang,
 * kunci periode / tutup buku (PB-4, §19).
 */
export default function LedgerSettingsPage(props: Props) {
    const { settings, accounts } = props;
    const [tab, setTab] = usePageTab(TABS, 'akun');
    const byId = new Map(accounts.map((account) => [account.id, account]));

    return (
        <>
            <Head title="Pengaturan pembukuan" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pengaturan pembukuan"
                    description="Daftar akun (COA), akun yang dipakai jurnal otomatis, dan periode pembukuan seluruh yayasan."
                />

                <RebuildBanner
                    settings={settings}
                    onOpen={() => setTab('periode')}
                />

                <PageTabs
                    label="Bagian pengaturan"
                    tabs={[
                        { value: 'akun', label: 'Akun' },
                        { value: 'pemetaan', label: 'Pemetaan' },
                        { value: 'saldo-awal', label: 'Saldo awal' },
                        { value: 'periode', label: 'Periode' },
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {tab === 'akun' && <AccountsTab {...props} />}
                {tab === 'pemetaan' && <MappingsTab {...props} byId={byId} />}
                {tab === 'saldo-awal' && <OpeningTab {...props} byId={byId} />}
                {tab === 'periode' && <PeriodTab {...props} />}
            </div>
        </>
    );
}

function RebuildBanner({
    settings,
    onOpen,
}: {
    settings: LedgerSettings;
    onOpen: () => void;
}) {
    if (!settings.active) {
        return (
            <p className="flex items-start gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                Pembukuan belum aktif. Atur tanggal mulai di tab Periode;
                sesudah itu semua transaksi sejak tanggal tersebut otomatis
                dijurnal.
            </p>
        );
    }

    if (!settings.needs_rebuild && !settings.rebuilding) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={onOpen}
            className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-left text-sm text-amber-950 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
                {settings.rebuilding
                    ? `Jurnal sedang disusun ulang (${settings.rebuild_done} dari ${settings.rebuild_total} transaksi).`
                    : 'Tanggal mulai atau pemetaan berubah. Transaksi lama masih memakai pengaturan sebelumnya sampai jurnal disusun ulang.'}{' '}
                Buka tab Periode.
            </span>
        </button>
    );
}

/* ----------------------------------------------------------------------------------------------------------------- */

function AccountsTab({ accounts, groups, activities, nameMax }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">
                    Akun tidak dihapus; nonaktifkan bila tidak dipakai lagi.
                    Akun bertanda <strong>Otomatis</strong> dipakai jurnal
                    otomatis.
                </p>
                <Button type="button" onClick={() => setCreating(true)}>
                    <Plus />
                    Tambah akun
                </Button>
            </div>

            {groups.map((group) => {
                const rows = accounts.filter(
                    (account) => account.group === group.value,
                );

                return (
                    <div key={group.value} className="space-y-2">
                        <h2 className="text-sm font-medium">{group.label}</h2>
                        {rows.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                                Belum ada akun.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {rows.map((account) => (
                                    <AccountItem
                                        key={account.id}
                                        account={account}
                                        group={group}
                                        accounts={accounts}
                                        activities={activities}
                                        nameMax={nameMax}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                );
            })}

            <FormDialog
                open={creating}
                onOpenChange={setCreating}
                title="Tambah akun"
            >
                <CreateAccountForm
                    accounts={accounts}
                    groups={groups}
                    activities={activities}
                    nameMax={nameMax}
                    onDone={() => setCreating(false)}
                />
            </FormDialog>
        </section>
    );
}

function AccountItem({
    account,
    group,
    accounts,
    activities,
    nameMax,
}: {
    account: Account;
    group: GroupOption;
    accounts: Account[];
    activities: Option[];
    nameMax: number;
}) {
    const [editing, setEditing] = useState(false);
    const url = `${BASE}/akun/${account.id}`;
    const contra =
        !account.is_header && account.normal_balance !== group.normal;
    const activity = activities.find(
        (option) => option.value === account.cash_flow_activity,
    );

    return (
        <li
            className={`flex flex-wrap items-start justify-between gap-2 p-3 text-sm ${account.is_header ? 'bg-muted/40' : ''}`}
        >
            <div
                className={`min-w-0 space-y-1 ${account.parent_id ? 'pl-4' : ''}`}
            >
                <p>
                    <span className="font-mono text-xs text-muted-foreground">
                        {account.code}
                    </span>{' '}
                    <span
                        className={`${account.is_header ? 'font-semibold' : 'font-medium'} ${account.is_active ? '' : 'text-muted-foreground line-through'}`}
                    >
                        {account.name}
                    </span>
                </p>
                <div className="flex flex-wrap gap-1">
                    {account.is_header && (
                        <Badge variant="outline">Judul</Badge>
                    )}
                    {account.is_cash && (
                        <Badge variant="secondary">Kas/bank</Badge>
                    )}
                    {contra && <Badge variant="outline">Kontra</Badge>}
                    {account.is_restricted && (
                        <Badge variant="outline">Dengan pembatasan</Badge>
                    )}
                    {account.system_key && (
                        <Badge variant="secondary">
                            {account.is_locked
                                ? 'Otomatis · terkunci'
                                : 'Otomatis'}
                        </Badge>
                    )}
                    {activity && (
                        <span className="text-xs text-muted-foreground">
                            Arus kas: {activity.label}
                        </span>
                    )}
                    {!account.is_active && (
                        <Badge variant="outline">Nonaktif</Badge>
                    )}
                </div>
            </div>
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setEditing(true)}
                >
                    Ubah
                </Button>
                {!account.system_key && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                `${url}/${account.is_active ? 'nonaktifkan' : 'aktifkan'}`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {account.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                    </Button>
                )}
            </div>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title={`Ubah akun ${account.code}`}
            >
                <Form
                    action={url}
                    method="patch"
                    errorBag={`account-${account.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`name-${account.id}`}>
                                    Nama
                                </Label>
                                <Input
                                    id={`name-${account.id}`}
                                    name="name"
                                    defaultValue={account.name}
                                    maxLength={nameMax}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            {!account.is_header && (
                                <ParentSelect
                                    id={`parent-${account.id}`}
                                    accounts={accounts}
                                    group={account.group}
                                    defaultValue={account.parent_id ?? ''}
                                    error={errors.parent_id}
                                />
                            )}
                            {!account.is_header &&
                                RESTRICTABLE.includes(account.group) && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="is_restricted"
                                            value="1"
                                            defaultChecked={
                                                account.is_restricted
                                            }
                                            disabled={
                                                account.system_key !== null
                                            }
                                        />
                                        Dengan pembatasan (ISAK 35)
                                    </label>
                                )}
                            {!account.is_header && !account.is_cash && (
                                <ActivitySelect
                                    id={`activity-${account.id}`}
                                    activities={activities}
                                    defaultValue={
                                        account.cash_flow_activity ?? ''
                                    }
                                />
                            )}
                            <p className="text-xs text-muted-foreground">
                                Kode, golongan, saldo normal, dan tanda kas/bank
                                tidak bisa diubah.
                            </p>
                            <InputError message={errors.form} />
                            <Button type="submit" disabled={processing}>
                                Simpan
                            </Button>
                        </>
                    )}
                </Form>
            </FormDialog>
        </li>
    );
}

function CreateAccountForm({
    accounts,
    groups,
    activities,
    nameMax,
    onDone,
}: {
    accounts: Account[];
    groups: GroupOption[];
    activities: Option[];
    nameMax: number;
    onDone: () => void;
}) {
    const [group, setGroup] = useState<Group>('BEBAN');
    const [header, setHeader] = useState(false);
    const [cash, setCash] = useState(false);
    const current = groups.find((option) => option.value === group);

    return (
        <Form
            action={`${BASE}/akun`}
            method="post"
            errorBag="createAccount"
            resetOnSuccess
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="acc-group">Golongan</Label>
                        <select
                            id="acc-group"
                            name="group"
                            className={selectClass}
                            value={group}
                            onChange={(event) => {
                                setGroup(event.target.value as Group);
                                setCash(false);
                            }}
                        >
                            {groups.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.group} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="acc-code">Kode</Label>
                        <Input
                            id="acc-code"
                            name="code"
                            placeholder={`mis. ${current?.prefix ?? '5'}-1800`}
                            maxLength={20}
                            required
                        />
                        <InputError message={errors.code} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="acc-name">Nama</Label>
                        <Input
                            id="acc-name"
                            name="name"
                            maxLength={nameMax}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="is_header"
                            value="1"
                            checked={header}
                            onChange={(event) =>
                                setHeader(event.target.checked)
                            }
                        />
                        Akun judul (hanya pengelompokan, tidak diisi jurnal)
                    </label>
                    {!header && (
                        <>
                            <ParentSelect
                                id="acc-parent"
                                accounts={accounts}
                                group={group}
                                defaultValue=""
                                error={errors.parent_id}
                            />
                            {group === 'ASET' && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="is_cash"
                                        value="1"
                                        checked={cash}
                                        onChange={(event) =>
                                            setCash(event.target.checked)
                                        }
                                    />
                                    Akun kas/bank (bisa dipetakan ke rekening
                                    kas)
                                </label>
                            )}
                            <InputError message={errors.is_cash} />
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="contra"
                                    value="1"
                                />
                                Akun kontra (saldo normal{' '}
                                {current?.normal === 'DEBIT'
                                    ? 'kredit'
                                    : 'debit'}
                                , mis. akumulasi penyusutan)
                            </label>
                            {RESTRICTABLE.includes(group) && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="is_restricted"
                                        value="1"
                                    />
                                    Dengan pembatasan (ISAK 35)
                                </label>
                            )}
                            {!cash && (
                                <ActivitySelect
                                    id="acc-activity"
                                    activities={activities}
                                    defaultValue={
                                        group === 'PENDAPATAN' ||
                                        group === 'BEBAN'
                                            ? 'OPERASI'
                                            : ''
                                    }
                                />
                            )}
                        </>
                    )}
                    <InputError message={errors.form} />
                    <Button type="submit" disabled={processing}>
                        Simpan akun
                    </Button>
                </>
            )}
        </Form>
    );
}

function ParentSelect({
    id,
    accounts,
    group,
    defaultValue,
    error,
}: {
    id: string;
    accounts: Account[];
    group: Group;
    defaultValue: string;
    error?: string;
}) {
    const headers = accounts.filter(
        (account) =>
            account.is_header && account.is_active && account.group === group,
    );

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>Akun judul (induk)</Label>
            <select
                key={group}
                id={id}
                name="parent_id"
                className={selectClass}
                defaultValue={defaultValue}
            >
                <option value="">Tanpa induk</option>
                {headers.map((account) => (
                    <option key={account.id} value={account.id}>
                        {account.code} · {account.name}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

function ActivitySelect({
    id,
    activities,
    defaultValue,
}: {
    id: string;
    activities: Option[];
    defaultValue: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>Aktivitas arus kas</Label>
            <select
                key={defaultValue}
                id={id}
                name="cash_flow_activity"
                className={selectClass}
                defaultValue={defaultValue}
            >
                <option value="">Tidak ada</option>
                {activities.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

/* ----------------------------------------------------------------------------------------------------------------- */

const MAPPING_SECTIONS: { kind: MappingKind; title: string; hint: string }[] = [
    {
        kind: 'rekening',
        title: 'Rekening kas',
        hint: 'Akun kas/bank untuk setiap rekening di buku kas. Rekening Titipan tabungan selalu memakai akun Kas titipan tabungan siswa.',
    },
    {
        kind: 'kategori',
        title: 'Kategori kas',
        hint: 'Akun lawan untuk pemasukan manual (pendapatan) dan realisasi pengeluaran (beban atau aset, mis. pembelian peralatan). Kategori otomatis (pembayaran siswa, tabungan, mutasi) diatur jurnal otomatis.',
    },
    {
        kind: 'jenis-biaya',
        title: 'Jenis biaya',
        hint: 'Akun pendapatan saat tagihan terbit. Piutang siswa dan potongan & beasiswa selalu memakai akunnya sendiri.',
    },
];

function MappingsTab({
    mappings,
    targets,
    accounts,
    byId,
}: Props & { byId: Map<string, Account> }) {
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [saving, setSaving] = useState<string | null>(null);
    const optionsFor = (kind: MappingKind, row: MappingRow): Account[] => {
        const key =
            kind === 'kategori'
                ? (`kategori-${row.kind}` as
                      | 'kategori-MASUK'
                      | 'kategori-KELUAR')
                : kind;

        return targets[key]
            .map((id) => byId.get(id))
            .filter((account): account is Account => account !== undefined);
    };
    const label = (id: string | null) => {
        const account = id ? byId.get(id) : undefined;

        return account ? `${account.code} · ${account.name}` : '-';
    };

    return (
        <section className="space-y-6">
            <p className="text-sm text-muted-foreground">
                Perubahan pemetaan berlaku untuk transaksi baru. Untuk
                menerapkannya ke transaksi lama, susun ulang jurnal di tab
                Periode.
            </p>
            <InputError message={errors?.account_id ?? errors?.form} />

            {accounts.length === 0 && (
                <p className="text-sm text-muted-foreground">Belum ada akun.</p>
            )}

            {MAPPING_SECTIONS.map((section) => {
                const rows = mappings[section.kind];

                return (
                    <div key={section.kind} className="space-y-2">
                        <h2 className="text-sm font-medium">{section.title}</h2>
                        <p className="text-xs text-muted-foreground">
                            {section.hint}
                        </p>
                        {rows.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                                Belum ada data.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {rows.map((row) => (
                                    <li
                                        key={row.id}
                                        className="grid gap-2 p-3 text-sm sm:grid-cols-[1fr_minmax(0,22rem)] sm:items-center"
                                    >
                                        <div className="min-w-0">
                                            <span
                                                className={
                                                    row.is_active
                                                        ? 'font-medium'
                                                        : 'font-medium text-muted-foreground line-through'
                                                }
                                            >
                                                {row.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                · {row.group}
                                            </span>
                                        </div>
                                        {row.mappable ? (
                                            <select
                                                aria-label={`Akun untuk ${row.name}`}
                                                className={selectClass}
                                                value={row.account_id ?? ''}
                                                disabled={saving === row.id}
                                                onChange={(event) =>
                                                    router.post(
                                                        `${BASE}/pemetaan`,
                                                        {
                                                            kind: section.kind,
                                                            item_id: row.id,
                                                            account_id:
                                                                event.target
                                                                    .value,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onStart: () =>
                                                                setSaving(
                                                                    row.id,
                                                                ),
                                                            onFinish: () =>
                                                                setSaving(null),
                                                        },
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Bawaan:{' '}
                                                    {label(
                                                        row.default_account_id,
                                                    )}
                                                </option>
                                                {optionsFor(
                                                    section.kind,
                                                    row,
                                                ).map((account) => (
                                                    <option
                                                        key={account.id}
                                                        value={account.id}
                                                    >
                                                        {account.code} ·{' '}
                                                        {account.name}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {label(row.default_account_id)}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                );
            })}
        </section>
    );
}

/* ----------------------------------------------------------------------------------------------------------------- */

function PeriodTab({ settings, months, lock }: Props) {
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [start, setStart] = useState(settings.start_date?.slice(0, 7) ?? '');
    const [month, setMonth] = useState(String(settings.fiscal_start_month));
    const [saving, setSaving] = useState(false);
    const [running, setRunning] = useState(false);

    const save = () =>
        router.post(
            `${BASE}/periode`,
            {
                start_date: start === '' ? '' : `${start}-01`,
                fiscal_start_month: month,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );

    // Susun ulang bertahap: tiap permintaan ±5 detik, diulang sampai server menyatakan selesai.
    const rebuild = (force: boolean) => {
        setRunning(true);
        router.post(`${BASE}/susun-ulang`, force ? { mulai: '1' } : {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const next = (page.props as unknown as Props).settings;

                if (next.rebuilding) {
                    rebuild(false);
                } else {
                    setRunning(false);
                }
            },
            onError: () => setRunning(false),
            onCancel: () => setRunning(false),
        });
    };

    const progress =
        settings.rebuild_total > 0
            ? Math.round((settings.rebuild_done / settings.rebuild_total) * 100)
            : 0;

    return (
        <section className="space-y-6">
            <div className="space-y-3 rounded-xl border p-4">
                <h2 className="text-sm font-medium">Periode pembukuan</h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="ledger-start">Mulai pembukuan</Label>
                        <Input
                            id="ledger-start"
                            type="month"
                            value={start}
                            onChange={(event) => setStart(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Jurnal otomatis dibuat untuk transaksi sejak tanggal
                            1 bulan ini.
                        </p>
                        <InputError message={errors?.start_date} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ledger-fiscal">Awal tahun buku</Label>
                        <select
                            id="ledger-fiscal"
                            className={selectClass}
                            value={month}
                            onChange={(event) => setMonth(event.target.value)}
                        >
                            {months.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-muted-foreground">
                            Bawaan Januari (tahun buku Januari–Desember).
                        </p>
                        <InputError message={errors?.fiscal_start_month} />
                    </div>
                </div>
                {lock.through !== null && (
                    <p className="text-xs text-muted-foreground">
                        Tanggal mulai & awal tahun buku tidak bisa diubah selama
                        ada periode yang ditutup buku.
                    </p>
                )}
                <Button
                    type="button"
                    onClick={save}
                    disabled={saving || start === '' || lock.through !== null}
                >
                    {settings.active ? 'Simpan periode' : 'Aktifkan pembukuan'}
                </Button>
            </div>

            {settings.active && (
                <PeriodLockSection lock={lock} settings={settings} />
            )}

            <div className="space-y-3 rounded-xl border p-4 text-sm">
                <h2 className="font-medium">Susun ulang jurnal otomatis</h2>
                {!settings.active ? (
                    <p className="text-muted-foreground">
                        Tersedia setelah pembukuan aktif.
                    </p>
                ) : (
                    <>
                        <p className="text-muted-foreground">
                            {settings.start_date &&
                                `Pembukuan mulai ${formatMonth(settings.start_date)}. `}
                            Menyusun ulang semua jurnal otomatis dengan pemetaan
                            terbaru. Nomor jurnal yang sudah ada tetap.
                            Transaksi baru tetap dijurnal otomatis selama proses
                            berjalan.
                        </p>
                        {(settings.rebuilding || running) && (
                            <div
                                className="h-2 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                aria-valuenow={progress}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label="Kemajuan susun ulang"
                            >
                                <div
                                    className="h-full bg-primary transition-all"
                                    style={{ width: `${progress}%` }}
                                />
                            </div>
                        )}
                        {settings.rebuilt_at && !settings.rebuilding && (
                            <p className="text-xs text-muted-foreground">
                                Terakhir disusun:{' '}
                                {formatDateTime(settings.rebuilt_at)}
                            </p>
                        )}
                        <InputError message={errors?.form} />
                        <Button
                            type="button"
                            variant={
                                settings.needs_rebuild ? 'default' : 'outline'
                            }
                            disabled={running}
                            onClick={() => rebuild(!settings.rebuilding)}
                        >
                            <RefreshCw
                                className={running ? 'animate-spin' : ''}
                            />
                            {running
                                ? `Menyusun… ${progress}%`
                                : settings.rebuilding
                                  ? 'Lanjutkan susun ulang'
                                  : 'Susun ulang jurnal'}
                        </Button>
                    </>
                )}
            </div>
        </section>
    );
}

/**
 * Tutup buku (PRD-007 §19, PB-4): kunci sampai akhir bulan yang sudah berakhir; buka kunci (mundurkan) dengan alasan.
 */
function PeriodLockSection({
    lock,
    settings,
}: {
    lock: PeriodLock;
    settings: LedgerSettings;
}) {
    const [unlocking, setUnlocking] = useState(false);
    const blocked = settings.needs_rebuild || settings.rebuilding;

    return (
        <div className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-medium">Tutup buku (kunci periode)</h2>
                {lock.through === null ? (
                    <Badge variant="outline">Belum ada yang dikunci</Badge>
                ) : (
                    <Badge>
                        <Lock className="size-3" />
                        Terkunci sampai {lock.through_label}
                    </Badge>
                )}
            </div>
            <p className="text-muted-foreground">
                Periode yang dikunci tidak bisa diubah lagi: kasir, kas,
                pengeluaran, tabungan, tagihan, jurnal manual, dan saldo awal
                menolak transaksi atau pembatalan bertanggal sampai tanggal
                kunci. Pembayaran tagihan lama tetap bisa, dengan tanggal hari
                ini. Hanya bulan yang sudah berakhir yang bisa dikunci.
            </p>

            {lock.lock_options.length === 0 ? (
                <p className="text-muted-foreground">
                    Belum ada bulan lain yang bisa dikunci. Bulan berjalan baru
                    bisa dikunci setelah berakhir.
                </p>
            ) : (
                <Form
                    action={`${BASE}/kunci-periode`}
                    method="post"
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid min-w-56 flex-1 gap-1.5">
                                <Label htmlFor="lock-through">
                                    Kunci sampai akhir bulan
                                </Label>
                                <select
                                    id="lock-through"
                                    name="locked_through"
                                    className={selectClass}
                                    defaultValue={
                                        lock.lock_options[
                                            lock.lock_options.length - 1
                                        ].value
                                    }
                                >
                                    {lock.lock_options.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.locked_through} />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing || blocked}
                            >
                                <Lock />
                                {processing ? 'Mengunci…' : 'Kunci periode'}
                            </Button>
                            {blocked && (
                                <p className="w-full text-xs text-muted-foreground">
                                    Selesaikan susun ulang jurnal dulu sebelum
                                    mengunci.
                                </p>
                            )}
                        </>
                    )}
                </Form>
            )}

            {lock.through !== null && (
                <div className="space-y-3 border-t pt-3">
                    {!unlocking ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setUnlocking(true)}
                        >
                            <LockOpen />
                            Buka kunci…
                        </Button>
                    ) : (
                        <Form
                            action={`${BASE}/buka-kunci-periode`}
                            method="post"
                            options={{ preserveScroll: true }}
                            onSuccess={() => setUnlocking(false)}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <p className="text-muted-foreground">
                                        Buka kunci hanya untuk koreksi yang
                                        perlu. Laporan yang sudah dilaporkan
                                        bisa berubah; alasan dicatat di log
                                        aktivitas.
                                    </p>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="unlock-through">
                                                Tetap terkunci sampai
                                            </Label>
                                            <select
                                                id="unlock-through"
                                                name="unlocked_through"
                                                className={selectClass}
                                                defaultValue={
                                                    lock.unlock_options[1]
                                                        ?.value ?? ''
                                                }
                                            >
                                                {lock.unlock_options.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={
                                                    errors.unlocked_through
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="unlock-reason">
                                                Alasan
                                            </Label>
                                            <Input
                                                id="unlock-reason"
                                                name="reason"
                                                minLength={lock.reason_min}
                                                maxLength={lock.reason_max}
                                                placeholder="Mis. koreksi kuitansi salah tanggal"
                                                required
                                            />
                                            <InputError
                                                message={errors.reason}
                                            />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            <LockOpen />
                                            {processing
                                                ? 'Membuka…'
                                                : 'Buka kunci'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setUnlocking(false)}
                                        >
                                            Batal
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}

/* ----------------------------------------------------------------------------------------------------------------- */

/**
 * Saldo awal per lembaga (PRD-007 §5, PB-2a): kas/bank, piutang, titipan dihitung otomatis dari aplikasi; akuntan
 * menambah baris lain; selisihnya ke Aset neto tanpa pembatasan.
 */
function OpeningTab({
    settings,
    openingScopes,
    openingScope,
    opening,
    openingAccounts,
    noteMax,
    byId,
}: Props & { byId: Map<string, Account> }) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<OpeningItem | null>(null);
    const debit = opening.lines.reduce((sum, line) => sum + line.debit, 0);
    const credit = opening.lines.reduce((sum, line) => sum + line.credit, 0);
    const label = (id: string) => {
        const account = byId.get(id);

        return account ? `${account.code} · ${account.name}` : '-';
    };

    if (!settings.active) {
        return (
            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                Atur tanggal mulai pembukuan di tab Periode dulu.
            </p>
        );
    }

    return (
        <section className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Saldo awal disusun otomatis per{' '}
                {settings.start_date && formatMonth(settings.start_date)}: kas
                &amp; bank dari buku kas, piutang dari tagihan yang belum
                dibayar, titipan dari tabungan siswa. Tambahkan saldo lain
                (tanah, bangunan, peralatan, utang); selisihnya otomatis masuk
                Aset neto tanpa pembatasan.
            </p>

            <div className="flex flex-wrap items-end justify-between gap-2">
                <div className="grid min-w-0 gap-1.5 sm:w-72">
                    <Label htmlFor="opening-scope">Kas</Label>
                    <select
                        id="opening-scope"
                        className={selectClass}
                        value={openingScope}
                        onChange={(event) =>
                            router.get(
                                `${BASE}/pengaturan`,
                                {
                                    tab: 'saldo-awal',
                                    lingkup: event.target.value,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {openingScopes.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <Button type="button" onClick={() => setCreating(true)}>
                    <Plus />
                    Tambah saldo awal
                </Button>
            </div>

            <div className="space-y-2">
                <h2 className="text-sm font-medium">Saldo tambahan</h2>
                {opening.items.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                        Belum ada saldo tambahan untuk kas ini.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {opening.items.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {label(item.account_id)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {item.side === 'DEBIT'
                                            ? 'Debit'
                                            : 'Kredit'}{' '}
                                        {formatRupiah(item.amount)}
                                        {item.note && ` · ${item.note}`}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditing(item)}
                                    >
                                        <Pencil />
                                        Ubah
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                `${BASE}/saldo-awal/${item.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                        Hapus
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="space-y-2">
                <h2 className="text-sm font-medium">Jurnal saldo awal</h2>
                {opening.lines.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
                        Semua saldo awal kas ini nol, jadi tidak ada jurnal
                        saldo awal.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[32rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="p-2 font-medium">Akun</th>
                                    <th className="p-2 text-right font-medium">
                                        Debit
                                    </th>
                                    <th className="p-2 text-right font-medium">
                                        Kredit
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {opening.lines.map((line, index) => (
                                    <tr key={index}>
                                        <td
                                            className={`p-2 ${line.credit > 0 ? 'pl-8' : ''}`}
                                        >
                                            {label(line.account_id)}
                                            {line.cash_account && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Rekening:{' '}
                                                    {line.cash_account}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {line.debit > 0
                                                ? formatNumber(line.debit)
                                                : ''}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {line.credit > 0
                                                ? formatNumber(line.credit)
                                                : ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t bg-muted/30 font-medium">
                                <tr>
                                    <td className="p-2">Total</td>
                                    <td className="p-2 text-right tabular-nums">
                                        {formatNumber(debit)}
                                    </td>
                                    <td className="p-2 text-right tabular-nums">
                                        {formatNumber(credit)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    Rancangan ini dihitung ulang dari data terbaru. Jurnalnya di
                    daftar Jurnal ikut diperbarui otomatis (paling lambat 1
                    menit, atau saat halaman Jurnal dibuka).
                </p>
            </div>

            <FormDialog
                open={creating}
                onOpenChange={setCreating}
                title="Tambah saldo awal"
            >
                <OpeningForm
                    action={`${BASE}/saldo-awal`}
                    method="post"
                    errorBag="createOpening"
                    scope={openingScope}
                    accounts={openingAccounts}
                    noteMax={noteMax}
                    onDone={() => setCreating(false)}
                />
            </FormDialog>

            <FormDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                title="Ubah saldo awal"
            >
                {editing && (
                    <OpeningForm
                        action={`${BASE}/saldo-awal/${editing.id}`}
                        method="patch"
                        errorBag={`opening-${editing.id}`}
                        scope={openingScope}
                        accounts={openingAccounts}
                        noteMax={noteMax}
                        item={editing}
                        onDone={() => setEditing(null)}
                    />
                )}
            </FormDialog>
        </section>
    );
}

function OpeningForm({
    action,
    method,
    errorBag,
    scope,
    accounts,
    noteMax,
    item,
    onDone,
}: {
    action: string;
    method: 'post' | 'patch';
    errorBag: string;
    scope: string;
    accounts: Option[];
    noteMax: number;
    item?: OpeningItem;
    onDone: () => void;
}) {
    return (
        <Form
            action={action}
            method={method}
            errorBag={errorBag}
            resetOnSuccess
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="lingkup" value={scope} />
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${errorBag}-account`}>Akun</Label>
                        <select
                            id={`${errorBag}-account`}
                            name="account_id"
                            className={selectClass}
                            defaultValue={item?.account_id ?? ''}
                            required
                        >
                            <option value="" disabled>
                                Pilih akun
                            </option>
                            {accounts.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.account_id} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-side`}>Posisi</Label>
                            <select
                                id={`${errorBag}-side`}
                                name="side"
                                className={selectClass}
                                defaultValue={item?.side ?? 'DEBIT'}
                            >
                                <option value="DEBIT">
                                    Debit (aset, beban)
                                </option>
                                <option value="KREDIT">
                                    Kredit (utang, aset neto, akumulasi)
                                </option>
                            </select>
                            <InputError message={errors.side} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-amount`}>
                                Nominal
                            </Label>
                            <Input
                                id={`${errorBag}-amount`}
                                name="amount"
                                inputMode="numeric"
                                defaultValue={item ? String(item.amount) : ''}
                                placeholder="mis. 250000000"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${errorBag}-note`}>
                            Keterangan (opsional)
                        </Label>
                        <Input
                            id={`${errorBag}-note`}
                            name="note"
                            maxLength={noteMax}
                            defaultValue={item?.note ?? ''}
                            placeholder="mis. Gedung sekolah, nilai buku per 31 Des"
                        />
                        <InputError message={errors.note} />
                    </div>
                    <InputError
                        message={errors.organization_id ?? errors.form}
                    />
                    <Button type="submit" disabled={processing}>
                        Simpan
                    </Button>
                </>
            )}
        </Form>
    );
}
