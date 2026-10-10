import { Form, Head, router } from '@inertiajs/react';
import {
    Building2,
    ChevronDown,
    ChevronRight,
    Landmark,
    Plus,
    School,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OrganizationNode = {
    id: string;
    /** Induk yang ikut tampil; null bila induknya di luar cakupan atau langsung di bawah Yayasan. */
    parent_id: string | null;
    /** Jalur induk yang tidak tampil, mis. "Pondok Pesantren › Unit 1". */
    path: string | null;
    code: string;
    name: string;
    type: 'LEMBAGA' | 'UNIT' | 'BIRO';
    type_label: string;
    category_label: string | null;
    jenjang_label: string | null;
    is_active: boolean;
    can_manage: boolean;
};

type Option = { value: string; label: string };

type Props = {
    nodes: OrganizationNode[];
    showInactive: boolean;
    canCreateRoot: boolean;
    options: {
        types: Option[];
        categories: Option[];
        jenjangs: Option[];
    };
};

const ROOT = '__root__';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const TYPE_ICONS = {
    LEMBAGA: School,
    UNIT: Building2,
    BIRO: Landmark,
} as const;

/**
 * Halaman admin pohon lembaga (PRD-000 §4.6, OD-14).
 * Data & hak kelola sudah disaring server; tombol di sini hanya petunjuk (§7.1).
 */
export default function Organizations({
    nodes,
    showInactive,
    canCreateRoot,
    options,
}: Props) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

    const childrenOf = useMemo(() => {
        const map = new Map<string, OrganizationNode[]>();

        for (const node of nodes) {
            const key = node.parent_id ?? ROOT;
            map.set(key, [...(map.get(key) ?? []), node]);
        }

        return map;
    }, [nodes]);

    const selected = nodes.find((node) => node.id === selectedId) ?? null;
    const detailRef = useRef<HTMLElement>(null);

    // Di layar sempit panel detail ada di bawah pohon; gulir ke sana setelah memilih.
    const select = (id: string | null) => {
        setSelectedId(id);

        if (window.matchMedia('(max-width: 1023px)').matches) {
            requestAnimationFrame(() =>
                detailRef.current?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                }),
            );
        }
    };

    const toggleCollapsed = (id: string) => {
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const toggleInactive = () => {
        router.get('/lembaga', showInactive ? {} : { nonaktif: 1 }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Lembaga" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Lembaga"
                        description="Pohon lembaga dan unit yang bisa Anda lihat di lembaga kerja ini."
                    />

                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex cursor-pointer items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4 accent-primary"
                                checked={showInactive}
                                onChange={toggleInactive}
                            />
                            Tampilkan yang nonaktif
                        </label>

                        {canCreateRoot && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => select(null)}
                            >
                                <Plus />
                                Tambah di tingkat Yayasan
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <section
                        aria-label="Pohon lembaga"
                        className="rounded-xl border p-2"
                    >
                        {nodes.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada lembaga yang bisa Anda lihat di
                                lembaga kerja ini.
                            </p>
                        ) : (
                            <TreeList
                                items={childrenOf.get(ROOT) ?? []}
                                childrenOf={childrenOf}
                                collapsed={collapsed}
                                selectedId={selectedId}
                                onToggle={toggleCollapsed}
                                onSelect={select}
                                depth={0}
                            />
                        )}
                    </section>

                    <section
                        ref={detailRef}
                        aria-label="Detail lembaga"
                        className="scroll-mt-4 rounded-xl border p-4"
                    >
                        {selectedId !== null && !selected ? (
                            <p className="text-sm text-muted-foreground">
                                Lembaga yang dipilih tidak lagi ditampilkan
                                (mungkin baru dinonaktifkan). Centang
                                &quot;Tampilkan yang nonaktif&quot; untuk
                                melihatnya, atau pilih lembaga lain.
                            </p>
                        ) : selected ? (
                            <NodeDetail
                                key={selected.id}
                                node={selected}
                                nodes={nodes}
                                childrenOf={childrenOf}
                                canCreateRoot={canCreateRoot}
                                options={options}
                            />
                        ) : canCreateRoot ? (
                            <CreateForm
                                parentId={null}
                                title="Tambah langsung di bawah Yayasan"
                                options={options}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Pilih lembaga di pohon untuk melihat detailnya.
                            </p>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

Organizations.layout = {
    breadcrumbs: [{ title: 'Lembaga', href: '/lembaga' }],
};

// ── Pohon ────────────────────────────────────────────────────────────────────

function TreeList({
    items,
    childrenOf,
    collapsed,
    selectedId,
    onToggle,
    onSelect,
    depth,
}: {
    items: OrganizationNode[];
    childrenOf: Map<string, OrganizationNode[]>;
    collapsed: Set<string>;
    selectedId: string | null;
    onToggle: (id: string) => void;
    onSelect: (id: string) => void;
    depth: number;
}) {
    return (
        <ul className={depth === 0 ? 'space-y-0.5' : 'mt-0.5 space-y-0.5'}>
            {items.map((node) => {
                const children = childrenOf.get(node.id) ?? [];
                const isOpen = !collapsed.has(node.id);
                const Icon = TYPE_ICONS[node.type];

                return (
                    <li key={node.id}>
                        <div
                            className="flex items-center gap-1"
                            style={{ paddingLeft: `${depth * 1.25}rem` }}
                        >
                            {children.length > 0 ? (
                                <button
                                    type="button"
                                    className="flex size-7 shrink-0 items-center justify-center rounded-md hover:bg-accent"
                                    onClick={() => onToggle(node.id)}
                                    aria-label={
                                        isOpen
                                            ? `Tutup ${node.name}`
                                            : `Buka ${node.name}`
                                    }
                                    aria-expanded={isOpen}
                                >
                                    {isOpen ? (
                                        <ChevronDown className="size-4" />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                </button>
                            ) : (
                                <span className="size-7 shrink-0" />
                            )}

                            <button
                                type="button"
                                onClick={() => onSelect(node.id)}
                                aria-pressed={node.id === selectedId}
                                className={`flex min-h-9 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent ${
                                    node.id === selectedId
                                        ? 'bg-accent font-medium'
                                        : ''
                                } ${node.is_active ? '' : 'text-muted-foreground'}`}
                            >
                                <Icon className="size-4 shrink-0" />
                                <span className="flex flex-1 flex-col">
                                    <span>{node.name}</span>
                                    {node.path && (
                                        <span className="text-xs text-muted-foreground">
                                            {node.path}
                                        </span>
                                    )}
                                </span>
                                {!node.is_active && (
                                    <Badge variant="outline">Nonaktif</Badge>
                                )}
                            </button>
                        </div>

                        {children.length > 0 && isOpen && (
                            <TreeList
                                items={children}
                                childrenOf={childrenOf}
                                collapsed={collapsed}
                                selectedId={selectedId}
                                onToggle={onToggle}
                                onSelect={onSelect}
                                depth={depth + 1}
                            />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

// ── Detail & aksi ────────────────────────────────────────────────────────────

function NodeDetail({
    node,
    nodes,
    childrenOf,
    canCreateRoot,
    options,
}: {
    node: OrganizationNode;
    nodes: OrganizationNode[];
    childrenOf: Map<string, OrganizationNode[]>;
    canCreateRoot: boolean;
    options: Props['options'];
}) {
    return (
        <div className="space-y-6">
            <div className="space-y-2">
                <h2 className="text-lg font-semibold">{node.name}</h2>
                <div className="flex flex-wrap gap-1.5">
                    <Badge variant="secondary">{node.type_label}</Badge>
                    {node.category_label && (
                        <Badge variant="secondary">{node.category_label}</Badge>
                    )}
                    {node.jenjang_label && (
                        <Badge variant="secondary">{node.jenjang_label}</Badge>
                    )}
                    <Badge variant={node.is_active ? 'default' : 'outline'}>
                        {node.is_active ? 'Aktif' : 'Nonaktif'}
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    Kode: <span className="font-mono">{node.code}</span>
                    {node.path && <> · Di bawah {node.path}</>}
                </p>
            </div>

            {node.can_manage ? (
                <>
                    <RenameForm node={node} />
                    {node.is_active && (
                        <CreateForm
                            parentId={node.id}
                            title={`Tambah di bawah ${node.name}`}
                            options={options}
                        />
                    )}
                    <MoveForm
                        node={node}
                        nodes={nodes}
                        childrenOf={childrenOf}
                        canCreateRoot={canCreateRoot}
                    />
                    <StatusForm node={node} />
                </>
            ) : (
                <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                    Anda hanya dapat melihat lembaga ini.
                </p>
            )}
        </div>
    );
}

function FormSection({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-3 border-t pt-4">
            <h3 className="text-sm font-medium">{title}</h3>
            {children}
        </div>
    );
}

function RenameForm({ node }: { node: OrganizationNode }) {
    return (
        <FormSection title="Ubah nama">
            <Form
                action={`/lembaga/${node.id}`}
                method="patch"
                errorBag="rename"
                options={{ preserveScroll: true }}
                className="space-y-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor="rename-name" className="sr-only">
                            Nama
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id="rename-name"
                                name="name"
                                defaultValue={node.name}
                                maxLength={200}
                                required
                            />
                            <Button type="submit" disabled={processing}>
                                Simpan
                            </Button>
                        </div>
                        <InputError message={errors.name ?? errors.form} />
                    </>
                )}
            </Form>
        </FormSection>
    );
}

function CreateForm({
    parentId,
    title,
    options,
}: {
    parentId: string | null;
    title: string;
    options: Props['options'];
}) {
    const [type, setType] = useState('LEMBAGA');
    const isLembaga = type === 'LEMBAGA';

    return (
        <FormSection title={title}>
            <Form
                action="/lembaga"
                method="post"
                errorBag="create"
                resetOnSuccess
                options={{ preserveScroll: true }}
                onSuccess={() => setType('LEMBAGA')}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        {parentId && (
                            <input
                                type="hidden"
                                name="parent_id"
                                value={parentId}
                            />
                        )}

                        <InputError message={errors.form} />

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`type-${parentId ?? ROOT}`}>
                                    Jenis
                                </Label>
                                <select
                                    id={`type-${parentId ?? ROOT}`}
                                    name="type"
                                    className={selectClass}
                                    value={type}
                                    onChange={(event) =>
                                        setType(event.target.value)
                                    }
                                >
                                    {options.types.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.type} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor={`code-${parentId ?? ROOT}`}>
                                    Kode
                                </Label>
                                <Input
                                    id={`code-${parentId ?? ROOT}`}
                                    name="code"
                                    placeholder="mis. U2-MDA"
                                    maxLength={50}
                                    className="uppercase"
                                    required
                                />
                                <InputError message={errors.code} />
                            </div>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`name-${parentId ?? ROOT}`}>
                                Nama
                            </Label>
                            <Input
                                id={`name-${parentId ?? ROOT}`}
                                name="name"
                                placeholder="mis. MDA Unit 2"
                                maxLength={200}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>

                        {isLembaga && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`category-${parentId ?? ROOT}`}
                                    >
                                        Kategori
                                    </Label>
                                    <select
                                        id={`category-${parentId ?? ROOT}`}
                                        name="category"
                                        className={selectClass}
                                        defaultValue=""
                                        required
                                    >
                                        <option value="" disabled>
                                            Pilih kategori
                                        </option>
                                        {options.categories.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.category} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`jenjang-${parentId ?? ROOT}`}
                                    >
                                        Jenjang
                                    </Label>
                                    <select
                                        id={`jenjang-${parentId ?? ROOT}`}
                                        name="jenjang"
                                        className={selectClass}
                                        defaultValue=""
                                        required
                                    >
                                        <option value="" disabled>
                                            Pilih jenjang
                                        </option>
                                        {options.jenjangs.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.jenjang} />
                                </div>
                            </div>
                        )}

                        <Button type="submit" disabled={processing}>
                            <Plus />
                            Tambah
                        </Button>
                    </>
                )}
            </Form>
        </FormSection>
    );
}

function MoveForm({
    node,
    nodes,
    childrenOf,
    canCreateRoot,
}: {
    node: OrganizationNode;
    nodes: OrganizationNode[];
    childrenOf: Map<string, OrganizationNode[]>;
    canCreateRoot: boolean;
}) {
    // Tujuan yang masuk akal: node aktif yang boleh dikelola, bukan node ini sendiri,
    // bukan turunannya, dan bukan induknya sekarang. Server tetap memeriksa ulang.
    const targets = useMemo(() => {
        const excluded = new Set<string>([node.id]);
        const stack = [...(childrenOf.get(node.id) ?? [])];

        while (stack.length > 0) {
            const child = stack.pop()!;
            excluded.add(child.id);
            stack.push(...(childrenOf.get(child.id) ?? []));
        }

        return nodes.filter(
            (candidate) =>
                candidate.can_manage &&
                candidate.is_active &&
                !excluded.has(candidate.id) &&
                candidate.id !== node.parent_id,
        );
    }, [node, nodes, childrenOf]);

    const canMoveToRoot = canCreateRoot && node.parent_id !== null;

    if (targets.length === 0 && !canMoveToRoot) {
        return null;
    }

    return (
        <FormSection title="Pindahkan ke induk lain">
            <Form
                action={`/lembaga/${node.id}/induk`}
                method="patch"
                errorBag="move"
                options={{ preserveScroll: true }}
                className="space-y-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor="move-parent" className="sr-only">
                            Induk baru
                        </Label>
                        <div className="flex gap-2">
                            <select
                                id="move-parent"
                                name="parent_id"
                                className={selectClass}
                                defaultValue={
                                    canMoveToRoot ? '' : targets[0]?.id
                                }
                            >
                                {canMoveToRoot && (
                                    <option value="">
                                        Langsung di bawah Yayasan
                                    </option>
                                )}
                                {targets.map((target) => (
                                    <option key={target.id} value={target.id}>
                                        {target.name} ({target.code})
                                    </option>
                                ))}
                            </select>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                Pindahkan
                            </Button>
                        </div>
                        <InputError message={errors.parent_id ?? errors.form} />
                    </>
                )}
            </Form>
        </FormSection>
    );
}

function StatusForm({ node }: { node: OrganizationNode }) {
    const [confirming, setConfirming] = useState(false);

    if (!node.is_active) {
        return (
            <FormSection title="Status">
                <Form
                    action={`/lembaga/${node.id}/aktifkan`}
                    method="post"
                    errorBag="status"
                    options={{ preserveScroll: true }}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Button type="submit" disabled={processing}>
                                Aktifkan kembali
                            </Button>
                            <InputError message={errors.form} />
                        </>
                    )}
                </Form>
            </FormSection>
        );
    }

    return (
        <FormSection title="Status">
            <Form
                action={`/lembaga/${node.id}/nonaktifkan`}
                method="post"
                errorBag="status"
                options={{ preserveScroll: true }}
                onSuccess={() => setConfirming(false)}
                className="space-y-2"
            >
                {({ processing, errors }) => (
                    <>
                        {confirming ? (
                            <div className="space-y-2 rounded-md border border-destructive/40 p-3">
                                <p className="text-sm">
                                    Nonaktifkan {node.name}? Data lama tetap
                                    tersimpan dan node bisa diaktifkan kembali.
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Ya, nonaktifkan
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setConfirming(false)}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConfirming(true)}
                            >
                                Nonaktifkan
                            </Button>
                        )}
                        <InputError message={errors.form} />
                    </>
                )}
            </Form>
        </FormSection>
    );
}
