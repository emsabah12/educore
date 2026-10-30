import { Form, Head, Link, router } from '@inertiajs/react';
import { Landmark, Plus, ReceiptText, Wallet } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Account = {
    id: string;
    kind: 'TUNAI' | 'BANK';
    name: string;
    bank_name: string | null;
    account_number: string | null;
    opening_balance: number;
    opening_date: string;
    is_active: boolean;
    receives_transfers: boolean;
    balance: number;
    has_entries: boolean;
};

type LedgerRow = {
    id: string;
    type: 'opening' | 'entry';
    date: string;
    description: string;
    category: string | null;
    account: string;
    direction: 'MASUK' | 'KELUAR';
    amount: number;
    status: 'SAH' | 'BATAL';
    void_reason: string | null;
    source: string | null;
    source_id: string | null;
    recorded_by: string | null;
    balance: number | null;
};

type Props = {
    books: Option[];
    bookKey: string;
    months: Option[];
    month: string;
    accountId: string | null;
    accounts: Account[];
    ledger: {
        opening: number;
        rows: LedgerRow[];
        total_in: number;
        total_out: number;
        closing: number;
    };
    incomeCategories: Option[];
    canManage: boolean;
    receivesPayments: boolean;
    today: string;
    limits: { name: number; bank: number; description: number; reason: number };
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) =>
    `${amount < 0 ? '−' : ''}Rp ${rupiah.format(Math.abs(amount))}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Kas umum (PRD-004 §6, KS-1): saldo per rekening & buku kas bulanan per kas (lembaga / Yayasan); bendahara
 * menambah rekening bank, mengisi saldo awal, mencatat pemasukan, dan membatalkan transaksi manual. KS-2: kuitansi
 * kasir masuk otomatis (bertanda "Otomatis", dibatalkan dari Kasir); rekening bank penerima transfer siswa.
 * Pengeluaran dicatat lewat pengajuan & persetujuan (KS-3).
 */
export default function Cash({
    books,
    bookKey,
    months,
    month,
    accountId,
    accounts,
    ledger,
    incomeCategories,
    canManage,
    receivesPayments,
    today,
    limits,
}: Props) {
    const [panel, setPanel] = useState<'income' | 'account' | null>(null);
    const activeAccounts = accounts.filter((account) => account.is_active);
    const total = accounts.reduce((sum, account) => sum + account.balance, 0);
    const monthLabel =
        months.find((option) => option.value === month)?.label ?? month;

    const visit = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            kas: bookKey,
            bulan: month,
            rekening: accountId,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        router.get('/keuangan/kas', clean, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Kas" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Kas"
                    description="Saldo setiap rekening dan buku kas bulanan. Saldo dihitung dari saldo awal dan transaksi yang sah."
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <SelectField
                        id="cash-book"
                        label="Kas"
                        value={bookKey}
                        options={books}
                        onChange={(value) =>
                            visit({ kas: value, rekening: null })
                        }
                    />
                    <SelectField
                        id="cash-month"
                        label="Bulan"
                        value={month}
                        options={months}
                        onChange={(value) => visit({ bulan: value })}
                    />
                    <SelectField
                        id="cash-account"
                        label="Rekening"
                        value={accountId ?? ''}
                        options={[
                            { value: '', label: 'Semua rekening' },
                            ...accounts.map((account) => ({
                                value: account.id,
                                label: account.name,
                            })),
                        ]}
                        onChange={(value) => visit({ rekening: value || null })}
                    />
                </div>

                <section className="space-y-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-sm font-medium">Saldo rekening</h2>
                        <p className="text-sm">
                            Total{' '}
                            <span className="font-semibold tabular-nums">
                                {formatRupiah(total)}
                            </span>
                        </p>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3">
                        {accounts.map((account) => (
                            <AccountCard
                                key={account.id}
                                account={account}
                                canManage={canManage}
                                canReceive={receivesPayments}
                                today={today}
                                limits={limits}
                            />
                        ))}
                    </div>
                    {receivesPayments &&
                        !accounts.some(
                            (account) => account.receives_transfers,
                        ) && (
                            <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                Pembayaran siswa via transfer saat ini dicatat
                                ke Kas tunai karena belum ada rekening bank
                                penerima transfer.
                                {canManage &&
                                    ' Tambah atau pilih rekening bank, lalu tekan "Jadikan penerima transfer siswa".'}
                            </p>
                        )}
                </section>

                {canManage && (
                    <>
                        {panel === null && (
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex">
                                <Button
                                    type="button"
                                    onClick={() => setPanel('income')}
                                >
                                    <Plus />
                                    Catat pemasukan
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/keuangan/pengeluaran?${new URLSearchParams({ kas: bookKey, ajukan: bookKey })}`}
                                    >
                                        <ReceiptText />
                                        Ajukan pengeluaran
                                    </Link>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setPanel('account')}
                                >
                                    <Landmark />
                                    Tambah rekening bank
                                </Button>
                            </div>
                        )}
                        {panel === 'income' && (
                            <IncomeForm
                                bookKey={bookKey}
                                accounts={activeAccounts}
                                categories={incomeCategories}
                                today={today}
                                limits={limits}
                                onDone={() => setPanel(null)}
                            />
                        )}
                        {panel === 'account' && (
                            <AccountForm
                                bookKey={bookKey}
                                today={today}
                                limits={limits}
                                onDone={() => setPanel(null)}
                            />
                        )}
                    </>
                )}

                <section className="space-y-2">
                    <h2 className="text-sm font-medium">
                        Buku kas {monthLabel}
                    </h2>
                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
                        <Stat
                            label="Saldo awal bulan"
                            value={formatRupiah(ledger.opening)}
                        />
                        <Stat
                            label="Pemasukan"
                            value={formatRupiah(ledger.total_in)}
                        />
                        <Stat
                            label="Pengeluaran"
                            value={formatRupiah(ledger.total_out)}
                        />
                        <Stat
                            label="Saldo akhir"
                            value={formatRupiah(ledger.closing)}
                        />
                    </dl>

                    {ledger.rows.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            Belum ada transaksi pada {monthLabel}.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {ledger.rows.map((row) => (
                                <LedgerItem
                                    key={row.id}
                                    row={row}
                                    canManage={canManage}
                                    reasonMax={limits.reason}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

function AccountCard({
    account,
    canManage,
    canReceive,
    today,
    limits,
}: {
    account: Account;
    canManage: boolean;
    canReceive: boolean;
    today: string;
    limits: Props['limits'];
}) {
    const [editing, setEditing] = useState(false);
    const Icon = account.kind === 'TUNAI' ? Wallet : Landmark;

    return (
        <div className="space-y-2 rounded-xl border p-3">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 font-medium">
                        <Icon className="size-4 shrink-0 text-muted-foreground" />
                        <span className="truncate">{account.name}</span>
                    </p>
                    {account.kind === 'BANK' && (
                        <p className="text-xs text-muted-foreground">
                            {[account.bank_name, account.account_number]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    )}
                </div>
                {canManage && !editing && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        Ubah
                    </Button>
                )}
            </div>
            <p
                className={`text-xl font-semibold tabular-nums ${account.balance < 0 ? 'text-destructive' : ''}`}
            >
                {formatRupiah(account.balance)}
            </p>
            <p className="text-xs text-muted-foreground">
                Saldo awal {formatRupiah(account.opening_balance)} per{' '}
                {formatDate(account.opening_date)}
            </p>
            {account.receives_transfers && (
                <Badge variant="secondary">Penerima transfer siswa</Badge>
            )}
            {canManage &&
                canReceive &&
                account.kind === 'BANK' &&
                account.is_active &&
                !editing && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full"
                        onClick={() =>
                            router.post(
                                `/keuangan/kas/rekening/${account.id}/penerima-transfer`,
                                { receives: !account.receives_transfers },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {account.receives_transfers
                            ? 'Lepas penerima transfer'
                            : 'Jadikan penerima transfer siswa'}
                    </Button>
                )}

            {editing && (
                <Form
                    action={`/keuangan/kas/rekening/${account.id}`}
                    method="patch"
                    errorBag={`account-${account.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2 border-t pt-2"
                >
                    {({ processing, errors }) => (
                        <>
                            {account.kind === 'BANK' && (
                                <BankFields
                                    idPrefix={`acc-${account.id}`}
                                    account={account}
                                    limits={limits}
                                    errors={errors}
                                />
                            )}
                            <OpeningFields
                                idPrefix={`acc-${account.id}`}
                                defaultAmount={String(account.opening_balance)}
                                defaultDate={account.opening_date}
                                today={today}
                                locked={account.has_entries}
                                errors={errors}
                            />
                            <InputError message={errors.form} />
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Simpan
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditing(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function BankFields({
    idPrefix,
    account,
    limits,
    errors,
}: {
    idPrefix: string;
    account?: Account;
    limits: Props['limits'];
    errors: Record<string, string | undefined>;
}) {
    return (
        <>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-name`}>Nama rekening</Label>
                <Input
                    id={`${idPrefix}-name`}
                    name="name"
                    defaultValue={account?.name}
                    placeholder="mis. BSI operasional"
                    maxLength={limits.name}
                    required
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-bank`}>Bank</Label>
                <Input
                    id={`${idPrefix}-bank`}
                    name="bank_name"
                    defaultValue={account?.bank_name ?? ''}
                    placeholder="mis. Bank Syariah Indonesia"
                    maxLength={limits.bank}
                    required
                />
                <InputError message={errors.bank_name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-number`}>
                    Nomor rekening (opsional)
                </Label>
                <Input
                    id={`${idPrefix}-number`}
                    name="account_number"
                    defaultValue={account?.account_number ?? ''}
                    inputMode="numeric"
                    maxLength={40}
                />
                <InputError message={errors.account_number} />
            </div>
        </>
    );
}

function OpeningFields({
    idPrefix,
    defaultAmount,
    defaultDate,
    today,
    locked,
    errors,
}: {
    idPrefix: string;
    defaultAmount: string;
    defaultDate: string;
    today: string;
    locked: boolean;
    errors: Record<string, string | undefined>;
}) {
    return (
        <>
            <div className="grid grid-cols-2 gap-2">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-opening`}>Saldo awal</Label>
                    <Input
                        id={`${idPrefix}-opening`}
                        name="opening_balance"
                        defaultValue={defaultAmount}
                        inputMode="numeric"
                        readOnly={locked}
                        required
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-opening-date`}>
                        Per tanggal
                    </Label>
                    <Input
                        id={`${idPrefix}-opening-date`}
                        name="opening_date"
                        type="date"
                        max={today}
                        defaultValue={defaultDate}
                        readOnly={locked}
                        required
                    />
                </div>
            </div>
            {locked && (
                <p className="text-xs text-muted-foreground">
                    Saldo awal tidak bisa diubah karena rekening sudah punya
                    transaksi.
                </p>
            )}
            <InputError
                message={errors.opening_balance ?? errors.opening_date}
            />
        </>
    );
}

function AccountForm({
    bookKey,
    today,
    limits,
    onDone,
}: {
    bookKey: string;
    today: string;
    limits: Props['limits'];
    onDone: () => void;
}) {
    return (
        <Form
            action="/keuangan/kas/rekening"
            method="post"
            errorBag="createAccount"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-xl border p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="font-medium">Tambah rekening bank</h3>
                    <input type="hidden" name="kas" value={bookKey} />
                    <BankFields
                        idPrefix="new-acc"
                        limits={limits}
                        errors={errors}
                    />
                    <OpeningFields
                        idPrefix="new-acc"
                        defaultAmount="0"
                        defaultDate={today}
                        today={today}
                        locked={false}
                        errors={errors}
                    />
                    <InputError message={errors.form} />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            Simpan rekening
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function IncomeForm({
    bookKey,
    accounts,
    categories,
    today,
    limits,
    onDone,
}: {
    bookKey: string;
    accounts: Account[];
    categories: Option[];
    today: string;
    limits: Props['limits'];
    onDone: () => void;
}) {
    return (
        <Form
            action="/keuangan/kas/pemasukan"
            method="post"
            errorBag="income"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-xl border p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="font-medium">Catat pemasukan</h3>
                    <p className="text-sm text-muted-foreground">
                        Untuk uang masuk di luar pembayaran siswa (mis. dana
                        BOS, donasi). Pengeluaran dicatat lewat pengajuan yang
                        disetujui.
                    </p>
                    <input type="hidden" name="kas" value={bookKey} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="inc-account">
                                Masuk ke rekening
                            </Label>
                            <select
                                id="inc-account"
                                name="account_id"
                                className={selectClass}
                                required
                            >
                                {accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.account_id} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="inc-date">Tanggal</Label>
                            <Input
                                id="inc-date"
                                name="entry_date"
                                type="date"
                                max={today}
                                defaultValue={today}
                                required
                            />
                            <InputError message={errors.entry_date} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="inc-category">Kategori</Label>
                            <select
                                id="inc-category"
                                name="category_id"
                                className={selectClass}
                                required
                            >
                                {categories.map((category) => (
                                    <option
                                        key={category.value}
                                        value={category.value}
                                    >
                                        {category.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.category_id} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="inc-amount">Jumlah (Rp)</Label>
                            <Input
                                id="inc-amount"
                                name="amount"
                                inputMode="numeric"
                                placeholder="mis. 2.500.000"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="inc-description">Uraian</Label>
                        <Input
                            id="inc-description"
                            name="description"
                            placeholder="mis. Dana BOS tahap 2"
                            maxLength={limits.description}
                            required
                        />
                        <InputError message={errors.description} />
                    </div>
                    <InputError message={errors.form} />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            Simpan pemasukan
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function LedgerItem({
    row,
    canManage,
    reasonMax,
}: {
    row: LedgerRow;
    canManage: boolean;
    reasonMax: number;
}) {
    const [voiding, setVoiding] = useState(false);
    const voided = row.status === 'BATAL';
    const incoming = row.direction === 'MASUK';
    const canVoid =
        canManage && row.type === 'entry' && !voided && row.source === 'MANUAL';

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className={voided ? 'line-through' : 'font-medium'}>
                        {row.description}
                        {row.source === 'PEMBAYARAN' && (
                            <Badge variant="secondary" className="ml-2">
                                Otomatis
                            </Badge>
                        )}
                        {voided && (
                            <Badge variant="outline" className="ml-2">
                                Batal
                            </Badge>
                        )}
                    </p>
                    <p className="text-muted-foreground">
                        {[
                            formatDate(row.date),
                            row.category,
                            row.account,
                            row.recorded_by && `oleh ${row.recorded_by}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {row.void_reason && (
                        <p className="text-destructive">
                            Dibatalkan: {row.void_reason}
                        </p>
                    )}
                </div>
                <div className="text-right">
                    <p
                        className={`font-semibold tabular-nums ${voided ? 'text-muted-foreground line-through' : incoming ? 'text-emerald-700 dark:text-emerald-400' : 'text-destructive'}`}
                    >
                        {incoming ? '+' : '−'}
                        {formatRupiah(row.amount)}
                    </p>
                    {row.balance !== null && (
                        <p className="text-xs text-muted-foreground tabular-nums">
                            Saldo {formatRupiah(row.balance)}
                        </p>
                    )}
                    {row.source === 'PEMBAYARAN' && row.source_id && (
                        <Link
                            href={`/keuangan/kuitansi/${row.source_id}`}
                            className="text-xs underline underline-offset-4"
                        >
                            Kuitansi
                        </Link>
                    )}
                    {canVoid && !voiding && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setVoiding(true)}
                        >
                            Batalkan
                        </Button>
                    )}
                </div>
            </div>
            {voiding && (
                <Form
                    action={`/keuangan/kas/transaksi/${row.id}/batalkan`}
                    method="post"
                    errorBag={`void-${row.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setVoiding(false)}
                    className="space-y-2 rounded-lg border p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor={`void-${row.id}`}>
                                Alasan pembatalan
                            </Label>
                            <Input
                                id={`void-${row.id}`}
                                name="reason"
                                maxLength={reasonMax}
                                required
                                autoFocus
                            />
                            <InputError
                                message={errors.reason ?? errors.form}
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Batalkan transaksi
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setVoiding(false)}
                                >
                                    Tutup
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
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

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums">{value}</dd>
        </div>
    );
}
