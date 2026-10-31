import { Form, Head, router } from '@inertiajs/react';
import { Paperclip, Plus } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type AccountOption = Option & { balance: number };

type Status =
    | 'DIAJUKAN'
    | 'MENUNGGU_YAYASAN'
    | 'DISETUJUI'
    | 'DITOLAK'
    | 'DIBATALKAN'
    | 'DIREALISASI';

type Expense = {
    id: string;
    book: string;
    book_key: string;
    category: string;
    description: string;
    amount: number;
    approved_amount: number | null;
    needed_on: string | null;
    status: Status;
    status_label: string;
    requested_by: string | null;
    requested_at: string;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    first_decision: {
        by: string | null;
        at: string;
        amount: number;
        note: string | null;
    } | null;
    attachment_name: string | null;
    proof_name: string | null;
    realized: { account: string; date: string; amount: number } | null;
    is_own: boolean;
    can_cancel: boolean;
    can_decide: boolean;
    can_realize: boolean;
    can_void: boolean;
};

type Limits = { description: number; note: number; fileKb: number };

type Props = {
    books: Option[];
    manageBooks: Option[];
    filters: { kas: string; status: string | null };
    statuses: Option[];
    expenses: {
        rows: Expense[];
        total: number;
        page: number;
        last_page: number;
    };
    accounts: Record<string, AccountOption[]>;
    expenseCategories: Option[];
    openRequestFor: string | null;
    today: string;
    limits: Limits;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const statusVariant: Record<
    Status,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    DIAJUKAN: 'default',
    MENUNGGU_YAYASAN: 'default',
    DISETUJUI: 'secondary',
    DITOLAK: 'destructive',
    DIBATALKAN: 'outline',
    DIREALISASI: 'outline',
};

/**
 * Pengeluaran (PRD-004 §4, KS-3): ajukan → disetujui/ditolak (bukan oleh pengaju) → direalisasi dari rekening kas
 * (saldo berkurang). Server tetap memeriksa ulang setiap aksi.
 */
export default function Expenses({
    books,
    manageBooks,
    filters,
    statuses,
    expenses,
    accounts,
    expenseCategories,
    openRequestFor,
    today,
    limits,
}: Props) {
    const [requesting, setRequesting] = useState(openRequestFor !== null);

    const visit = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            kas: filters.kas,
            status: filters.status,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        router.get('/keuangan/pengeluaran', clean, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Pengeluaran" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pengeluaran"
                    description="Setiap pengeluaran diajukan bendahara, disetujui Kepala Lembaga (kas lembaga) atau Pimpinan Yayasan (kas Yayasan), lalu direalisasi saat uang benar-benar keluar."
                />

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        id="exp-book"
                        label="Kas"
                        value={filters.kas}
                        options={[
                            { value: 'semua', label: 'Semua kas' },
                            ...books,
                        ]}
                        onChange={(value) =>
                            visit({ kas: value, halaman: null })
                        }
                    />
                    <SelectField
                        id="exp-status"
                        label="Status"
                        value={filters.status ?? ''}
                        options={[
                            { value: '', label: 'Semua status' },
                            ...statuses,
                        ]}
                        onChange={(value) =>
                            visit({ status: value || null, halaman: null })
                        }
                    />
                </div>

                {manageBooks.length > 0 && (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex">
                            <Button
                                type="button"
                                onClick={() => setRequesting(true)}
                            >
                                <Plus />
                                Ajukan pengeluaran
                            </Button>
                        </div>
                        <FormDialog
                            open={requesting}
                            onOpenChange={setRequesting}
                            title="Ajukan pengeluaran"
                            wide
                        >
                            <RequestForm
                                books={manageBooks}
                                defaultBook={
                                    openRequestFor ?? manageBooks[0].value
                                }
                                categories={expenseCategories}
                                today={today}
                                limits={limits}
                                onDone={() => setRequesting(false)}
                            />
                        </FormDialog>
                    </>
                )}

                {expenses.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada pengajuan pengeluaran
                        {filters.status ? ' dengan status ini' : ''}.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {expenses.rows.map((expense) => (
                            <ExpenseCard
                                key={expense.id}
                                expense={expense}
                                accounts={accounts[expense.book_key] ?? []}
                                today={today}
                                limits={limits}
                            />
                        ))}
                    </ul>
                )}

                {expenses.last_page > 1 && (
                    <nav
                        aria-label="Halaman"
                        className="flex flex-wrap items-center justify-between gap-2 text-sm"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={expenses.page <= 1}
                            onClick={() =>
                                visit({ halaman: String(expenses.page - 1) })
                            }
                        >
                            Sebelumnya
                        </Button>
                        <span className="text-muted-foreground">
                            Halaman {expenses.page} dari {expenses.last_page}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={expenses.page >= expenses.last_page}
                            onClick={() =>
                                visit({ halaman: String(expenses.page + 1) })
                            }
                        >
                            Berikutnya
                        </Button>
                    </nav>
                )}
            </div>
        </>
    );
}

function RequestForm({
    books,
    defaultBook,
    categories,
    today,
    limits,
    onDone,
}: {
    books: Option[];
    defaultBook: string;
    categories: Option[];
    today: string;
    limits: Limits;
    onDone: () => void;
}) {
    return (
        <Form
            action="/keuangan/pengeluaran"
            method="post"
            errorBag="requestExpense"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="req-book">Dari kas</Label>
                            <select
                                id="req-book"
                                name="kas"
                                className={selectClass}
                                defaultValue={defaultBook}
                            >
                                {books.map((book) => (
                                    <option key={book.value} value={book.value}>
                                        {book.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="req-category">Kategori</Label>
                            <select
                                id="req-category"
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
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="req-description">Uraian</Label>
                        <Input
                            id="req-description"
                            name="description"
                            placeholder="mis. Beli kertas HVS 10 rim untuk ujian"
                            maxLength={limits.description}
                            required
                        />
                        <InputError message={errors.description} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="req-amount">Jumlah (Rp)</Label>
                            <Input
                                id="req-amount"
                                name="amount"
                                inputMode="numeric"
                                placeholder="mis. 450.000"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="req-needed">
                                Rencana tanggal (opsional)
                            </Label>
                            <Input
                                id="req-needed"
                                name="needed_on"
                                type="date"
                                min={today}
                            />
                            <InputError message={errors.needed_on} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="req-attachment">
                            Lampiran (opsional): nota/penawaran, PDF/JPG/PNG
                            maks. {limits.fileKb / 1024} MB
                        </Label>
                        <Input
                            id="req-attachment"
                            name="attachment"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                        />
                        <InputError message={errors.attachment} />
                    </div>
                    <InputError message={errors.form} />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            Kirim pengajuan
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

function ExpenseCard({
    expense,
    accounts,
    today,
    limits,
}: {
    expense: Expense;
    accounts: AccountOption[];
    today: string;
    limits: Limits;
}) {
    const [panel, setPanel] = useState<
        'approve' | 'reject' | 'realize' | 'void' | null
    >(null);
    const base = `/keuangan/pengeluaran/${expense.id}`;

    return (
        <li className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium">{expense.description}</p>
                    <p className="text-muted-foreground">
                        {[
                            expense.book,
                            expense.category,
                            expense.requested_by &&
                                `diajukan ${expense.is_own ? 'Anda' : expense.requested_by}`,
                            formatDate(expense.requested_at),
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {expense.needed_on && (
                        <p className="text-muted-foreground">
                            Rencana: {formatDate(expense.needed_on)}
                        </p>
                    )}
                </div>
                <div className="text-right">
                    <Badge variant={statusVariant[expense.status]}>
                        {expense.status_label}
                    </Badge>
                    <p className="mt-1 font-semibold tabular-nums">
                        {formatRupiah(
                            expense.approved_amount ?? expense.amount,
                        )}
                    </p>
                    {expense.approved_amount !== null &&
                        expense.approved_amount !== expense.amount && (
                            <p className="text-xs text-muted-foreground line-through">
                                diajukan {formatRupiah(expense.amount)}
                            </p>
                        )}
                </div>
            </div>

            {expense.first_decision && (
                <p className="text-muted-foreground">
                    Tahap 1 disetujui{' '}
                    {formatRupiah(expense.first_decision.amount)}
                    {expense.first_decision.by &&
                        ` oleh ${expense.first_decision.by}`}{' '}
                    ({formatDate(expense.first_decision.at)})
                    {expense.first_decision.note &&
                        `: ${expense.first_decision.note}`}
                    {expense.status === 'MENUNGGU_YAYASAN' &&
                        ' — di atas batas, menunggu persetujuan Yayasan.'}
                </p>
            )}

            {(expense.decided_by || expense.decision_note) && (
                <p
                    className={
                        expense.status === 'DITOLAK'
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    }
                >
                    {expense.status === 'DITOLAK' ? 'Ditolak' : 'Disetujui'}
                    {expense.decided_by && ` oleh ${expense.decided_by}`}
                    {expense.decided_at &&
                        ` (${formatDate(expense.decided_at)})`}
                    {expense.decision_note && `: ${expense.decision_note}`}
                </p>
            )}

            {expense.realized && (
                <p className="text-muted-foreground">
                    Keluar {formatRupiah(expense.realized.amount)} dari{' '}
                    {expense.realized.account} pada{' '}
                    {formatDate(expense.realized.date)}
                </p>
            )}

            {(expense.attachment_name || expense.proof_name) && (
                <div className="flex flex-wrap gap-3">
                    {expense.attachment_name && (
                        <a
                            href={`${base}/lampiran`}
                            className="inline-flex items-center gap-1 underline underline-offset-4"
                        >
                            <Paperclip className="size-3.5" />
                            Lampiran: {expense.attachment_name}
                        </a>
                    )}
                    {expense.proof_name && (
                        <a
                            href={`${base}/bukti`}
                            className="inline-flex items-center gap-1 underline underline-offset-4"
                        >
                            <Paperclip className="size-3.5" />
                            Bukti: {expense.proof_name}
                        </a>
                    )}
                </div>
            )}

            {expense.is_own &&
                (expense.status === 'DIAJUKAN' ||
                    expense.status === 'MENUNGGU_YAYASAN') &&
                !expense.can_decide && (
                    <p className="text-xs text-muted-foreground">
                        Menunggu persetujuan pihak lain (pengaju tidak bisa
                        menyetujui pengajuannya sendiri).
                    </p>
                )}

            {panel === null && (
                <div className="flex flex-wrap gap-2">
                    {expense.can_decide && (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setPanel('approve')}
                            >
                                Setujui
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setPanel('reject')}
                            >
                                Tolak
                            </Button>
                        </>
                    )}
                    {expense.can_realize && (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => setPanel('realize')}
                        >
                            Realisasi (uang keluar)
                        </Button>
                    )}
                    {expense.can_cancel && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `${base}/batalkan`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Batalkan pengajuan
                        </Button>
                    )}
                    {expense.can_void && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setPanel('void')}
                        >
                            Batalkan realisasi
                        </Button>
                    )}
                </div>
            )}

            <FormDialog
                open={panel === 'approve'}
                onOpenChange={(open) => !open && setPanel(null)}
                title="Setujui pengeluaran"
                description={expense.description}
                wide={false}
            >
                <ActionForm
                    action={`${base}/setujui`}
                    bag={`approve-${expense.id}`}
                    submit="Setujui"
                    onDone={() => setPanel(null)}
                >
                    {(errors) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`appr-${expense.id}`}>
                                    Jumlah disetujui (Rp, maks.{' '}
                                    {formatRupiah(
                                        expense.approved_amount ??
                                            expense.amount,
                                    )}
                                    )
                                </Label>
                                <Input
                                    id={`appr-${expense.id}`}
                                    name="approved_amount"
                                    inputMode="numeric"
                                    defaultValue={String(
                                        expense.approved_amount ??
                                            expense.amount,
                                    )}
                                    required
                                />
                                <InputError message={errors.approved_amount} />
                            </div>
                            <NoteField
                                id={`appr-note-${expense.id}`}
                                label="Catatan (opsional)"
                                max={limits.note}
                                required={false}
                                error={errors.decision_note}
                            />
                        </>
                    )}
                </ActionForm>
            </FormDialog>

            <FormDialog
                open={panel === 'reject'}
                onOpenChange={(open) => !open && setPanel(null)}
                title="Tolak pengajuan"
                description={expense.description}
                wide={false}
            >
                <ActionForm
                    action={`${base}/tolak`}
                    bag={`reject-${expense.id}`}
                    submit="Tolak pengajuan"
                    destructive
                    onDone={() => setPanel(null)}
                >
                    {(errors) => (
                        <NoteField
                            id={`rej-note-${expense.id}`}
                            label="Alasan penolakan"
                            max={limits.note}
                            required
                            error={errors.decision_note}
                        />
                    )}
                </ActionForm>
            </FormDialog>

            <FormDialog
                open={panel === 'realize'}
                onOpenChange={(open) => !open && setPanel(null)}
                title="Realisasi (uang keluar)"
                description={expense.description}
                wide={true}
            >
                <ActionForm
                    action={`${base}/realisasi`}
                    bag={`realize-${expense.id}`}
                    submit="Catat uang keluar"
                    onDone={() => setPanel(null)}
                >
                    {(errors) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`real-acc-${expense.id}`}>
                                        Dari rekening
                                    </Label>
                                    <select
                                        id={`real-acc-${expense.id}`}
                                        name="account_id"
                                        className={selectClass}
                                        required
                                    >
                                        {accounts.map((account) => (
                                            <option
                                                key={account.value}
                                                value={account.value}
                                            >
                                                {account.label} — saldo{' '}
                                                {formatRupiah(account.balance)}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.account_id} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`real-date-${expense.id}`}>
                                        Tanggal keluar
                                    </Label>
                                    <Input
                                        id={`real-date-${expense.id}`}
                                        name="entry_date"
                                        type="date"
                                        max={today}
                                        defaultValue={today}
                                        required
                                    />
                                    <InputError message={errors.entry_date} />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`real-amount-${expense.id}`}>
                                    Jumlah keluar (Rp, maks.{' '}
                                    {formatRupiah(
                                        expense.approved_amount ??
                                            expense.amount,
                                    )}
                                    )
                                </Label>
                                <Input
                                    id={`real-amount-${expense.id}`}
                                    name="amount"
                                    inputMode="numeric"
                                    defaultValue={String(
                                        expense.approved_amount ??
                                            expense.amount,
                                    )}
                                    required
                                />
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`real-proof-${expense.id}`}>
                                    Bukti/nota (disarankan, PDF/JPG/PNG maks.{' '}
                                    {limits.fileKb / 1024} MB)
                                </Label>
                                <Input
                                    id={`real-proof-${expense.id}`}
                                    name="proof"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                />
                                <InputError message={errors.proof} />
                            </div>
                        </>
                    )}
                </ActionForm>
            </FormDialog>

            <FormDialog
                open={panel === 'void'}
                onOpenChange={(open) => !open && setPanel(null)}
                title="Batalkan realisasi"
                description={expense.description}
                wide={false}
            >
                <ActionForm
                    action={`${base}/batalkan-realisasi`}
                    bag={`void-${expense.id}`}
                    submit="Batalkan realisasi"
                    destructive
                    onDone={() => setPanel(null)}
                >
                    {(errors) => (
                        <NoteField
                            id={`void-${expense.id}`}
                            name="reason"
                            label="Alasan pembatalan"
                            max={limits.note}
                            required
                            error={errors.reason}
                        />
                    )}
                </ActionForm>
            </FormDialog>
        </li>
    );
}

function ActionForm({
    action,
    bag,
    submit,
    destructive = false,
    onDone,
    children,
}: {
    action: string;
    bag: string;
    submit: string;
    destructive?: boolean;
    onDone: () => void;
    children: (errors: Record<string, string | undefined>) => React.ReactNode;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={bag}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    {children(errors)}
                    <InputError message={errors.form} />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {submit}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={onDone}
                        >
                            Tutup
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function NoteField({
    id,
    name = 'decision_note',
    label,
    max,
    required,
    error,
}: {
    id: string;
    name?: string;
    label: string;
    max: number;
    required: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} name={name} maxLength={max} required={required} />
            <InputError message={error} />
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
