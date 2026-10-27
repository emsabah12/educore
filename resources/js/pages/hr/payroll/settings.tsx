import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type PayComponent = {
    id: string;
    code: string;
    name: string;
    kind: 'PENDAPATAN' | 'POTONGAN';
    kind_label: string;
    basis: 'TETAP' | 'PER_HARI_HADIR' | 'MENURUT_JABATAN';
    basis_label: string;
    is_active: boolean;
    /** Jumlah pegawai yang punya nominal komponen ini (komponen per pegawai). */
    employees: number;
    /** Tarif jabatan (hanya komponen Menurut jabatan). */
    rates: PositionRate[];
};

type PositionRate = {
    id: string;
    position_id: string;
    organization_id: string | null;
    position: string;
    organization: string | null;
    amount: number;
};

type Props = {
    components: PayComponent[];
    kinds: Option[];
    bases: Option[];
    positions: Option[];
    organizations: Option[];
};

const rupiah = new Intl.NumberFormat('id-ID');

const BASE_URL = '/pegawai/gaji/komponen';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Pengaturan gaji (PRD-001 §16, HR-5a): komponen gaji yayasan. Nominal per pegawai diisi di detail
 * pegawai. Hanya Admin SDM / Admin Yayasan; server tetap memeriksa ulang.
 */
export default function PayrollSettings({
    components,
    kinds,
    bases,
    positions,
    organizations,
}: Props) {
    return (
        <>
            <Head title="Pengaturan gaji" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pengaturan gaji"
                    description="Komponen gaji yayasan. Komponen Menurut jabatan memakai tarif jabatan di bawah; komponen lain diisi per pegawai di menu Gaji pegawai. Perhitungan pajak & pembayaran tetap di Keuangan."
                />

                <Link
                    href="/pegawai/gaji"
                    className="self-start text-sm font-medium underline underline-offset-4"
                >
                    Buka Gaji pegawai →
                </Link>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <section
                        aria-label="Daftar komponen gaji"
                        className="rounded-xl border"
                    >
                        {components.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada komponen gaji. Tambahkan yang pertama
                                lewat formulir di samping.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {components.map((component) => (
                                    <ComponentRow
                                        key={component.id}
                                        component={component}
                                        positions={positions}
                                        organizations={organizations}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-label="Tambah komponen gaji"
                        className="h-fit rounded-xl border p-4"
                    >
                        <CreateForm kinds={kinds} bases={bases} />
                    </section>
                </div>
            </div>
        </>
    );
}

function CreateForm({ kinds, bases }: { kinds: Option[]; bases: Option[] }) {
    return (
        <Form
            action={BASE_URL}
            method="post"
            errorBag="create"
            resetOnSuccess
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">Tambah komponen</h3>

                    <div className="grid gap-1.5">
                        <Label htmlFor="pay-code">Kode</Label>
                        <Input
                            id="pay-code"
                            name="code"
                            placeholder="mis. TUNJ-KELUARGA"
                            maxLength={30}
                            className="uppercase"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Huruf, angka, dan tanda &quot;-&quot;. Kode tidak
                            bisa diubah setelah disimpan.
                        </p>
                        <InputError message={errors.code} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="pay-name">Nama</Label>
                        <Input
                            id="pay-name"
                            name="name"
                            placeholder="mis. Tunjangan keluarga"
                            maxLength={100}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="pay-kind">Jenis</Label>
                        <select
                            id="pay-kind"
                            name="kind"
                            className={selectClass}
                            defaultValue="PENDAPATAN"
                        >
                            {kinds.map((kind) => (
                                <option key={kind.value} value={kind.value}>
                                    {kind.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.kind} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="pay-basis">Cara hitung</Label>
                        <select
                            id="pay-basis"
                            name="basis"
                            className={selectClass}
                            defaultValue="TETAP"
                        >
                            {bases.map((basis) => (
                                <option key={basis.value} value={basis.value}>
                                    {basis.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-muted-foreground">
                            Per hari hadir = nominal × jumlah hari Hadir & Dinas
                            luar di rekap presensi bulan itu. Menurut jabatan =
                            nominal dari tarif jabatan yang sedang dipegang
                            pegawai (selain mengajar; honor mengajar diatur
                            lewat tarif JP). Jenis & cara hitung tidak bisa
                            diubah setelah disimpan.
                        </p>
                        <InputError message={errors.basis} />
                    </div>

                    <Button type="submit" disabled={processing}>
                        <Plus />
                        Tambah
                    </Button>
                </>
            )}
        </Form>
    );
}

function ComponentRow({
    component,
    positions,
    organizations,
}: {
    component: PayComponent;
    positions: Option[];
    organizations: Option[];
}) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const itemUrl = `${BASE_URL}/${component.id}`;

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p
                        className={
                            'font-medium break-words ' +
                            (component.is_active ? '' : 'text-muted-foreground')
                        }
                    >
                        {component.name}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="font-mono">{component.code}</span>
                        <Badge
                            variant={
                                component.kind === 'POTONGAN'
                                    ? 'outline'
                                    : 'secondary'
                            }
                        >
                            {component.kind_label}
                        </Badge>
                        <Badge variant="outline">{component.basis_label}</Badge>
                        {!component.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                        <span>
                            {component.basis === 'MENURUT_JABATAN'
                                ? `${component.rates.length} tarif jabatan`
                                : `${component.employees} pegawai`}
                        </span>
                    </div>
                </div>

                {!editing && !confirming && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(true)}
                        >
                            <Pencil />
                            Ubah nama
                        </Button>
                        {component.is_active ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirming(true)}
                            >
                                Nonaktifkan
                            </Button>
                        ) : (
                            <StatusButton
                                action={`${itemUrl}/aktifkan`}
                                errorBag={`status-${component.id}`}
                            >
                                Aktifkan kembali
                            </StatusButton>
                        )}
                    </div>
                )}
            </div>

            {editing && (
                <Form
                    action={itemUrl}
                    method="patch"
                    errorBag={`rename-${component.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label
                                htmlFor={`rename-${component.id}`}
                                className="sr-only"
                            >
                                Nama baru
                            </Label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id={`rename-${component.id}`}
                                    name="name"
                                    defaultValue={component.name}
                                    maxLength={100}
                                    required
                                    autoFocus
                                />
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Simpan
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditing(false)}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </div>
                            <InputError message={errors.name ?? errors.form} />
                        </>
                    )}
                </Form>
            )}

            {component.basis === 'MENURUT_JABATAN' && (
                <RateEditor
                    component={component}
                    positions={positions}
                    organizations={organizations}
                />
            )}

            {confirming && (
                <div className="space-y-2 rounded-md border border-destructive/40 p-3">
                    <p className="text-sm">
                        Nonaktifkan {component.name}? Komponen tidak bisa diisi
                        dan tidak ikut rekap gaji baru. Nominal & tarif yang
                        sudah tercatat tetap tersimpan.
                    </p>
                    <div className="flex gap-2">
                        <StatusButton
                            action={`${itemUrl}/nonaktifkan`}
                            errorBag={`status-${component.id}`}
                            variant="destructive"
                            onSuccess={() => setConfirming(false)}
                        >
                            Ya, nonaktifkan
                        </StatusButton>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setConfirming(false)}
                        >
                            Batal
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}

/**
 * Tarif komponen Menurut jabatan (HR-5a-2). Tarif lembaga tertentu didahulukan daripada tarif
 * "semua lembaga". Menyimpan jabatan × lembaga yang sudah ada = mengubah nominalnya.
 */
function RateEditor({
    component,
    positions,
    organizations,
}: {
    component: PayComponent;
    positions: Option[];
    organizations: Option[];
}) {
    const baseUrl = `${BASE_URL}/${component.id}/tarif`;

    return (
        <div className="space-y-3 rounded-lg border bg-muted/20 p-3">
            <p className="text-sm font-medium">Tarif jabatan</p>
            {component.rates.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada tarif. Pegawai belum menerima komponen ini.
                </p>
            ) : (
                <ul className="divide-y rounded-md border bg-background">
                    {component.rates.map((rate) => (
                        <li
                            key={rate.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm"
                        >
                            <span className="min-w-0 break-words">
                                {rate.position}
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {rate.organization ?? 'Semua lembaga'}
                                </span>
                            </span>
                            <span className="flex items-center gap-2">
                                <span className="font-medium tabular-nums">
                                    Rp {rupiah.format(rate.amount)}
                                </span>
                                {component.is_active && (
                                    <Form
                                        action={`${baseUrl}/${rate.id}`}
                                        method="delete"
                                        errorBag={`rate-remove-${rate.id}`}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="icon"
                                                disabled={processing}
                                                aria-label={`Hapus tarif ${rate.position}`}
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {component.is_active && (
                <Form
                    action={baseUrl}
                    method="post"
                    errorBag={`rate-${component.id}`}
                    resetOnSuccess={['amount']}
                    options={{ preserveScroll: true }}
                    className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_9rem_auto] sm:items-start"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1">
                                <Label
                                    htmlFor={`rate-position-${component.id}`}
                                    className="text-xs"
                                >
                                    Jabatan
                                </Label>
                                <select
                                    id={`rate-position-${component.id}`}
                                    name="position_id"
                                    className={selectClass}
                                    defaultValue=""
                                    required
                                >
                                    <option value="" disabled>
                                        Pilih jabatan
                                    </option>
                                    {positions.map((position) => (
                                        <option
                                            key={position.value}
                                            value={position.value}
                                        >
                                            {position.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.position_id} />
                            </div>
                            <div className="grid gap-1">
                                <Label
                                    htmlFor={`rate-org-${component.id}`}
                                    className="text-xs"
                                >
                                    Lembaga
                                </Label>
                                <select
                                    id={`rate-org-${component.id}`}
                                    name="organization_id"
                                    className={selectClass}
                                    defaultValue=""
                                >
                                    <option value="">Semua lembaga</option>
                                    {organizations.map((organization) => (
                                        <option
                                            key={organization.value}
                                            value={organization.value}
                                        >
                                            {organization.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.organization_id} />
                            </div>
                            <div className="grid gap-1">
                                <Label
                                    htmlFor={`rate-amount-${component.id}`}
                                    className="text-xs"
                                >
                                    Nominal / bulan
                                </Label>
                                <Input
                                    id={`rate-amount-${component.id}`}
                                    name="amount"
                                    inputMode="numeric"
                                    placeholder="1.000.000"
                                    maxLength={20}
                                    required
                                />
                                <InputError message={errors.amount} />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="sm:mt-5"
                            >
                                Simpan tarif
                            </Button>
                            <div className="sm:col-span-4">
                                <InputError message={errors.form} />
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function StatusButton({
    action,
    errorBag,
    variant = 'outline',
    onSuccess,
    children,
}: {
    action: string;
    errorBag: string;
    variant?: 'outline' | 'destructive';
    onSuccess?: () => void;
    children: React.ReactNode;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant={variant}
                        size="sm"
                        disabled={processing}
                    >
                        {children}
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}
