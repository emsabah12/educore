import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { ConfirmAction } from '@/components/confirm-action';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CatalogKind = {
    value: string;
    label: string;
    description: string;
    count: number;
};

type CatalogItem = {
    id: string;
    code: string;
    name: string;
    is_active: boolean;
    /** Pilihan ya/tidak katalog (masa berlaku / honor per JP); null bila katalog tidak punya. */
    flag: boolean | null;
};

/** Pilihan ya/tidak katalog aktif: jenis dokumen (HR-2a), jenis pegawai & status kepegawaian (HR-5b). */
type CatalogFlag = { label: string; badge: string } | null;

type Props = {
    kinds: CatalogKind[];
    activeKind: string;
    flag: CatalogFlag;
    items: CatalogItem[];
};

const BASE_URL = '/pegawai/katalog';

/**
 * Halaman Katalog SDM (PRD-001 §6): jenis pegawai, status kepegawaian, jabatan, jenis dokumen (§15).
 * Hak akses sudah dicek server; item tidak pernah dihapus, hanya dinonaktifkan.
 */
export default function HrCatalog({ kinds, activeKind, flag, items }: Props) {
    const kind = kinds.find((candidate) => candidate.value === activeKind);
    const label = kind?.label ?? 'Katalog';
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Katalog SDM" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Katalog SDM"
                    description="Pilihan yang dipakai di data pegawai seluruh yayasan."
                />

                {/* Grid otomatis: tab yang tidak muat di satu baris pindah ke baris berikutnya (HP). */}
                <nav
                    aria-label="Jenis katalog"
                    className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 sm:flex sm:flex-wrap"
                >
                    {kinds.map((candidate) => {
                        const active = candidate.value === activeKind;

                        return (
                            <Link
                                key={candidate.value}
                                href={`${BASE_URL}?jenis=${candidate.value}`}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={
                                    'flex min-h-10 items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm transition-colors ' +
                                    (active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'hover:bg-muted')
                                }
                            >
                                {candidate.label}
                                <span
                                    className={
                                        'rounded px-1.5 text-xs ' +
                                        (active
                                            ? 'bg-background/20'
                                            : 'bg-muted')
                                    }
                                >
                                    {candidate.count}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    {kind && (
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            {kind.description}
                        </p>
                    )}
                    <Button type="button" onClick={() => setCreating(true)}>
                        <Plus />
                        Tambah {label.toLowerCase()}
                    </Button>
                </div>

                <FormDialog
                    open={creating}
                    onOpenChange={setCreating}
                    title={`Tambah ${label.toLowerCase()}`}
                >
                    <CreateForm
                        kind={activeKind}
                        flag={flag}
                        onDone={() => setCreating(false)}
                    />
                </FormDialog>

                <div>
                    <section
                        aria-label={`Daftar ${label.toLowerCase()}`}
                        className="rounded-xl border"
                    >
                        {items.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada {label.toLowerCase()}. Tambahkan yang
                                pertama lewat tombol Tambah.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {items.map((item) => (
                                    <CatalogRow
                                        key={item.id}
                                        item={item}
                                        kind={activeKind}
                                        flag={flag}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

function CreateForm({
    kind,
    flag,
    onDone,
}: {
    kind: string;
    flag: CatalogFlag;
    onDone: () => void;
}) {
    return (
        <Form
            key={kind}
            action={`${BASE_URL}/${kind}`}
            method="post"
            errorBag="create"
            resetOnSuccess
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="catalog-code">Kode</Label>
                        <Input
                            id="catalog-code"
                            name="code"
                            placeholder="mis. GURU-BK"
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
                        <Label htmlFor="catalog-name">Nama</Label>
                        <Input
                            id="catalog-name"
                            name="name"
                            placeholder="mis. Guru Bimbingan Konseling"
                            maxLength={100}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    {flag && (
                        <FlagCheckbox
                            id="catalog-flag"
                            label={flag.label}
                            defaultChecked={false}
                        />
                    )}

                    <Button type="submit" disabled={processing}>
                        <Plus />
                        Tambah
                    </Button>
                </>
            )}
        </Form>
    );
}

function CatalogRow({
    item,
    kind,
    flag,
}: {
    item: CatalogItem;
    kind: string;
    flag: CatalogFlag;
}) {
    const [editing, setEditing] = useState(false);
    const itemUrl = `${BASE_URL}/${kind}/${item.id}`;

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p
                        className={
                            'font-medium break-words ' +
                            (item.is_active ? '' : 'text-muted-foreground')
                        }
                    >
                        {item.name}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="font-mono text-xs text-muted-foreground">
                            {item.code}
                        </span>
                        {flag && item.flag && (
                            <Badge variant="secondary">{flag.badge}</Badge>
                        )}
                        {!item.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        {flag ? 'Ubah' : 'Ubah nama'}
                    </Button>

                    {item.is_active ? (
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                >
                                    Nonaktifkan
                                </Button>
                            }
                            title={`Nonaktifkan ${item.name}?`}
                            description="Pilihan ini tidak bisa dipakai untuk data baru, tetapi data lama yang memakainya tetap tersimpan."
                            action={`${itemUrl}/nonaktifkan`}
                            confirmLabel="Ya, nonaktifkan"
                            errorBag={`status-${item.id}`}
                        />
                    ) : (
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                >
                                    Aktifkan kembali
                                </Button>
                            }
                            title={`Aktifkan kembali ${item.name}?`}
                            description="Pilihan ini bisa dipakai lagi untuk data baru."
                            action={`${itemUrl}/aktifkan`}
                            confirmLabel="Aktifkan"
                            destructive={false}
                            errorBag={`status-${item.id}`}
                        />
                    )}
                </div>
            </div>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title={flag ? `Ubah ${item.code}` : `Ubah nama ${item.code}`}
                description="Kode tidak bisa diubah."
            >
                <Form
                    action={itemUrl}
                    method="patch"
                    errorBag={`rename-${item.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`rename-${item.id}`}>
                                    Nama
                                </Label>
                                <Input
                                    id={`rename-${item.id}`}
                                    name="name"
                                    defaultValue={item.name}
                                    maxLength={100}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>
                            {flag && (
                                <FlagCheckbox
                                    id={`flag-${item.id}`}
                                    label={flag.label}
                                    defaultChecked={item.flag ?? false}
                                />
                            )}
                            <InputError message={errors.flag} />
                            <div className="flex flex-wrap gap-2">
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
                        </>
                    )}
                </Form>
            </FormDialog>
        </li>
    );
}

/**
 * Pilihan ya/tidak katalog. Nilai dikirim lewat input tersembunyi agar "tidak dicentang" juga
 * terkirim (checkbox kosong tidak ikut terkirim).
 */
function FlagCheckbox({
    id,
    label,
    defaultChecked,
}: {
    id: string;
    label: string;
    defaultChecked: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    return (
        <div className="space-y-1">
            <input type="hidden" name="flag" value={checked ? '1' : '0'} />
            <label
                htmlFor={id}
                className="flex cursor-pointer items-start gap-2 text-sm"
            >
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => setChecked(event.target.checked)}
                    className="mt-0.5 size-4 accent-foreground"
                />
                {label}
            </label>
        </div>
    );
}
