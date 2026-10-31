import { Form, Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Category = {
    id: string;
    code: string;
    name: string;
    direction: 'MASUK' | 'KELUAR';
    is_system: boolean;
    is_active: boolean;
};

type Props = {
    categories: Category[];
    directions: Option[];
    nameMax: number;
};

const URL = '/keuangan/kategori-kas';

const TABS = ['MASUK', 'KELUAR'] as const;

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Kategori kas (PRD-004 §3, KS-1): pemasukan & pengeluaran per yayasan. Kategori sistem tidak bisa diubah.
 */
export default function CashCategories({
    categories,
    directions,
    nameMax,
}: Props) {
    const [tab, setTab] = usePageTab(TABS, 'MASUK');
    const [creating, setCreating] = useState(false);
    const current = directions.find((direction) => direction.value === tab);

    return (
        <>
            <Head title="Kategori kas" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Kategori kas"
                    description="Pengelompokan pemasukan dan pengeluaran di buku kas seluruh lembaga & Yayasan. Kategori tidak dihapus; nonaktifkan bila tidak dipakai lagi."
                />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <PageTabs
                        label="Jenis kategori"
                        tabs={directions.map((direction) => ({
                            value: direction.value as (typeof TABS)[number],
                            label: direction.label,
                        }))}
                        value={tab}
                        onChange={setTab}
                    />
                    <Button type="button" onClick={() => setCreating(true)}>
                        <Plus />
                        Tambah kategori
                    </Button>
                </div>

                <FormDialog
                    open={creating}
                    onOpenChange={setCreating}
                    title="Tambah kategori kas"
                >
                    <Form
                        action={URL}
                        method="post"
                        errorBag="createCategory"
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        onSuccess={() => setCreating(false)}
                        className="grid gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cat-code">Kode</Label>
                                    <Input
                                        id="cat-code"
                                        name="code"
                                        placeholder="mis. ATK"
                                        maxLength={30}
                                        className="uppercase"
                                        required
                                    />
                                    <InputError message={errors.code} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cat-name">Nama</Label>
                                    <Input
                                        id="cat-name"
                                        name="name"
                                        placeholder="mis. ATK & perlengkapan"
                                        maxLength={nameMax}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cat-direction">Jenis</Label>
                                    <select
                                        id="cat-direction"
                                        name="direction"
                                        className={selectClass}
                                        defaultValue={tab}
                                    >
                                        {directions.map((direction) => (
                                            <option
                                                key={direction.value}
                                                value={direction.value}
                                            >
                                                {direction.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.direction} />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Simpan kategori
                                </Button>
                            </>
                        )}
                    </Form>
                </FormDialog>

                <section aria-label={current?.label} className="space-y-2">
                    <ul className="divide-y rounded-xl border">
                        {categories
                            .filter((category) => category.direction === tab)
                            .map((category) => (
                                <CategoryItem
                                    key={category.id}
                                    category={category}
                                    nameMax={nameMax}
                                />
                            ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

function CategoryItem({
    category,
    nameMax,
}: {
    category: Category;
    nameMax: number;
}) {
    const [editing, setEditing] = useState(false);
    const itemUrl = `${URL}/${category.id}`;

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <span
                        className={
                            category.is_active
                                ? 'font-medium'
                                : 'font-medium text-muted-foreground line-through'
                        }
                    >
                        {category.name}
                    </span>{' '}
                    <span className="font-mono text-xs text-muted-foreground">
                        {category.code}
                    </span>
                    {category.is_system && (
                        <Badge variant="secondary" className="ml-2">
                            Otomatis
                        </Badge>
                    )}
                    {!category.is_active && (
                        <Badge variant="outline" className="ml-2">
                            Nonaktif
                        </Badge>
                    )}
                </div>
                {!category.is_system && !editing && (
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(true)}
                        >
                            Ubah nama
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `${itemUrl}/${category.is_active ? 'nonaktifkan' : 'aktifkan'}`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {category.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </Button>
                    </div>
                )}
            </div>
            {category.is_system && (
                <p className="text-xs text-muted-foreground">
                    Diisi otomatis dari penerimaan kasir; tidak bisa dipilih
                    manual.
                </p>
            )}
            {editing && (
                <Form
                    action={itemUrl}
                    method="patch"
                    errorBag={`rename-${category.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label
                                htmlFor={`rename-${category.id}`}
                                className="sr-only"
                            >
                                Nama baru
                            </Label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id={`rename-${category.id}`}
                                    name="name"
                                    defaultValue={category.name}
                                    maxLength={nameMax}
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
        </li>
    );
}
