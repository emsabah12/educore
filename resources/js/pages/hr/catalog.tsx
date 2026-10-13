import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
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
};

type Props = {
    kinds: CatalogKind[];
    activeKind: string;
    items: CatalogItem[];
};

const BASE_URL = '/pegawai/katalog';

/**
 * Halaman Katalog SDM (PRD-001 §6): jenis pegawai, status kepegawaian, jabatan.
 * Hak akses sudah dicek server; item tidak pernah dihapus, hanya dinonaktifkan.
 */
export default function HrCatalog({ kinds, activeKind, items }: Props) {
    const kind = kinds.find((candidate) => candidate.value === activeKind);
    const label = kind?.label ?? 'Katalog';

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
                                            ? 'bg-primary-foreground/20'
                                            : 'bg-muted')
                                    }
                                >
                                    {candidate.count}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                {kind && (
                    <p className="text-sm text-muted-foreground">
                        {kind.description}
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <section
                        aria-label={`Daftar ${label.toLowerCase()}`}
                        className="rounded-xl border"
                    >
                        {items.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada {label.toLowerCase()}. Tambahkan yang
                                pertama lewat formulir di samping.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {items.map((item) => (
                                    <CatalogRow
                                        key={item.id}
                                        item={item}
                                        kind={activeKind}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-label={`Tambah ${label.toLowerCase()}`}
                        className="h-fit rounded-xl border p-4"
                    >
                        <CreateForm kind={activeKind} label={label} />
                    </section>
                </div>
            </div>
        </>
    );
}

function CreateForm({ kind, label }: { kind: string; label: string }) {
    return (
        <Form
            key={kind}
            action={`${BASE_URL}/${kind}`}
            method="post"
            errorBag="create"
            resetOnSuccess
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">
                        Tambah {label.toLowerCase()}
                    </h3>

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

                    <Button type="submit" disabled={processing}>
                        <Plus />
                        Tambah
                    </Button>
                </>
            )}
        </Form>
    );
}

function CatalogRow({ item, kind }: { item: CatalogItem; kind: string }) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
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
                        {!item.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
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

                        {item.is_active ? (
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
                                errorBag={`status-${item.id}`}
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
                    errorBag={`rename-${item.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label
                                htmlFor={`rename-${item.id}`}
                                className="sr-only"
                            >
                                Nama baru
                            </Label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id={`rename-${item.id}`}
                                    name="name"
                                    defaultValue={item.name}
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
                            <InputError message={errors.name} />
                        </>
                    )}
                </Form>
            )}

            {confirming && (
                <div className="space-y-2 rounded-md border border-destructive/40 p-3">
                    <p className="text-sm">
                        Nonaktifkan {item.name}? Pilihan ini tidak bisa dipakai
                        untuk data baru, tetapi data lama yang memakainya tetap
                        tersimpan.
                    </p>
                    <div className="flex gap-2">
                        <StatusButton
                            action={`${itemUrl}/nonaktifkan`}
                            errorBag={`status-${item.id}`}
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
