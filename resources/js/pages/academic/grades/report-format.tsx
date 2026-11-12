import { Form, Head, router } from '@inertiajs/react';
import { ImageUp, Pencil, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ReportHeader } from '@/components/academic/report-header';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import { ConfirmAction } from '@/components/confirm-action';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Format = {
    header_lines: (string | null)[];
    title: string;
    student_term: 'SISWA' | 'SANTRI' | 'MURID';
    place: string | null;
    show_hijri: boolean;
    principal_title: string;
    principal_name: string | null;
    witness_title: string | null;
    witness_name: string | null;
    show_rank: boolean;
    has_logo: boolean;
    configured: boolean;
};

/** Butir ekstrakurikuler/kepribadian (AC-8d). */
type ReportItem = {
    id: string;
    kind: 'EKSKUL' | 'KEPRIBADIAN';
    name: string;
    sort_order: number;
    descriptions: Record<string, string>;
    is_active: boolean;
    used: boolean;
};

type Level = { letter: string; label: string | null; min: number };

type Props = {
    filters: { lembaga: string | null };
    options: { organizations: Option[]; terms: Option[] };
    format: Format | null;
    /** Logo tersimpan sebagai data URI (tanpa URL publik). */
    logo: string | null;
    limits: {
        line: number;
        title: number;
        place: number;
        role: number;
        name: number;
        logoKilobytes: number;
        itemName: number;
        itemDescription: number;
    };
    items: ReportItem[];
    /** Skala predikat lembaga (huruf untuk keterangan kepribadian). */
    predicates: Level[];
};

const BASE_URL = '/akademik/format-rapor';

/**
 * Format rapor per lembaga (PRD-002 §22.5 AC-8c): kop berlogo, judul, sebutan peserta didik,
 * tempat & tanggal Hijriah, penanda tangan. Pratinjau kop berubah langsung saat diketik; semua
 * aturan tetap dicek server. AC-8d: peringkat kelas, daftar ekstrakurikuler & kepribadian (dengan
 * keterangan otomatis per huruf).
 */
export default function ReportFormatPage({
    filters,
    options,
    format,
    logo,
    limits,
    items,
    predicates,
}: Props) {
    return (
        <>
            <Head title="Format rapor" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Format rapor"
                    description="Kop, judul, dan penanda tangan rapor per lembaga. Lembaga yang belum diatur memakai format bawaan."
                />

                {options.organizations.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada lembaga yang bisa Anda atur dari lembaga kerja
                        ini.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-1.5 rounded-xl border p-4">
                            <Label htmlFor="f-lembaga">Lembaga</Label>
                            <select
                                id="f-lembaga"
                                className={selectClass}
                                value={filters.lembaga ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        BASE_URL,
                                        { lembaga: event.target.value },
                                        { preserveState: false, replace: true },
                                    )
                                }
                            >
                                {options.organizations.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {format && filters.lembaga && (
                            <FormatForm
                                // Data tersimpan berubah → form dimuat ulang dari server.
                                key={`${filters.lembaga}-${JSON.stringify(format)}-${logo?.length ?? 0}`}
                                organizationId={filters.lembaga}
                                format={format}
                                logo={logo}
                                terms={options.terms}
                                limits={limits}
                            />
                        )}

                        {format && filters.lembaga && (
                            <>
                                <ItemsSection
                                    kind="EKSKUL"
                                    organizationId={filters.lembaga}
                                    items={items}
                                    predicates={predicates}
                                    limits={limits}
                                />
                                <ItemsSection
                                    kind="KEPRIBADIAN"
                                    organizationId={filters.lembaga}
                                    items={items}
                                    predicates={predicates}
                                    limits={limits}
                                />
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function FormatForm({
    organizationId,
    format,
    logo,
    terms,
    limits,
}: {
    organizationId: string;
    format: Format;
    logo: string | null;
    terms: Option[];
    limits: Props['limits'];
}) {
    const [lines, setLines] = useState<string[]>(() =>
        [0, 1, 2, 3].map((index) => format.header_lines[index] ?? ''),
    );
    const [title, setTitle] = useState(format.title);
    const [preview, setPreview] = useState<string | null>(logo);
    const [removeLogo, setRemoveLogo] = useState(false);

    // Lepas URL pratinjau berkas lokal saat diganti/ditutup.
    useEffect(
        () => () => {
            if (preview?.startsWith('blob:')) {
                URL.revokeObjectURL(preview);
            }
        },
        [preview],
    );

    return (
        <Form
            action={`${BASE_URL}/${organizationId}`}
            method="post"
            errorBag="reportFormat"
            options={{ preserveScroll: true }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="text-base font-medium">Pratinjau kop</h2>
                        <KopPreview
                            lines={lines}
                            title={title}
                            logo={removeLogo ? null : preview}
                        />
                    </section>

                    <section className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                        <h2 className="text-base font-medium sm:col-span-2">
                            Kop rapor
                        </h2>
                        {[
                            'Baris 1 (mis. nama pondok/yayasan)',
                            'Baris 2 (nama lembaga, ditulis paling besar)',
                            'Baris 3 (alamat)',
                            'Baris 4 (kode pos, telepon)',
                        ].map((label, index) => (
                            <div key={label} className="grid gap-1.5">
                                <Label htmlFor={`kop-${index}`}>{label}</Label>
                                <Input
                                    id={`kop-${index}`}
                                    name={`header_lines[${index}]`}
                                    value={lines[index]}
                                    maxLength={limits.line}
                                    onChange={(event) =>
                                        setLines((current) =>
                                            current.map((line, position) =>
                                                position === index
                                                    ? event.target.value
                                                    : line,
                                            ),
                                        )
                                    }
                                />
                                <InputError
                                    message={errors[`header_lines.${index}`]}
                                />
                            </div>
                        ))}

                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="kop-logo">
                                Logo (PNG/JPG, maks.{' '}
                                {limits.logoKilobytes / 1024} MB)
                            </Label>
                            <div className="flex flex-wrap items-center gap-3">
                                <label
                                    htmlFor="kop-logo"
                                    className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-md border border-primary/60 px-4 text-sm font-medium tracking-[0.03em] text-primary uppercase hover:bg-accent"
                                >
                                    <ImageUp className="size-4" />
                                    Pilih logo
                                </label>
                                <input
                                    id="kop-logo"
                                    name="logo"
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="sr-only"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0];

                                        if (file) {
                                            setPreview(
                                                URL.createObjectURL(file),
                                            );
                                            setRemoveLogo(false);
                                        }
                                    }}
                                />
                                {(format.has_logo || preview) && (
                                    <label className="flex min-h-10 cursor-pointer items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="remove_logo"
                                            value="1"
                                            checked={removeLogo}
                                            onChange={(event) =>
                                                setRemoveLogo(
                                                    event.target.checked,
                                                )
                                            }
                                            className="size-4"
                                        />
                                        Hapus logo
                                    </label>
                                )}
                            </div>
                            <InputError message={errors.logo} />
                        </div>
                    </section>

                    <section className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                        <h2 className="text-base font-medium sm:col-span-2">
                            Judul & tanggal
                        </h2>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="f-title">Judul rapor</Label>
                            <Input
                                id="f-title"
                                name="title"
                                value={title}
                                maxLength={limits.title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                placeholder="LAPORAN HASIL BELAJAR"
                            />
                            <InputError message={errors.title} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="f-term">
                                Sebutan peserta didik
                            </Label>
                            <select
                                id="f-term"
                                name="student_term"
                                className={selectClass}
                                defaultValue={format.student_term}
                            >
                                {terms.map((term) => (
                                    <option key={term.value} value={term.value}>
                                        {term.label}
                                    </option>
                                ))}
                            </select>
                            <p className="text-xs text-muted-foreground">
                                Dipakai di tanda tangan, mis. "Wali Santri".
                            </p>
                            <InputError message={errors.student_term} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="f-place">Tempat</Label>
                            <Input
                                id="f-place"
                                name="place"
                                defaultValue={format.place ?? ''}
                                maxLength={limits.place}
                                placeholder="Mis. Karawang"
                            />
                            <InputError message={errors.place} />
                        </div>
                        <label className="flex min-h-10 cursor-pointer items-center gap-2 text-sm sm:col-span-2">
                            <input
                                type="checkbox"
                                name="show_hijri"
                                value="1"
                                defaultChecked={format.show_hijri}
                                className="size-4"
                            />
                            Tampilkan tanggal Hijriah di atas tanggal Masehi
                            (mis. "5 Muharam 1448 H")
                        </label>
                        <label className="flex min-h-10 cursor-pointer items-center gap-2 text-sm sm:col-span-2">
                            <input
                                type="checkbox"
                                name="show_rank"
                                value="1"
                                defaultChecked={format.show_rank}
                                className="size-4"
                            />
                            Tampilkan peringkat kelas (dari rata-rata rapor,
                            mis. "1 dari 12 Santri")
                        </label>
                    </section>

                    <section className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                        <h2 className="text-base font-medium sm:col-span-2">
                            Penanda tangan
                        </h2>
                        <div className="grid gap-1.5">
                            <Label htmlFor="f-principal-title">
                                Jabatan kepala lembaga
                            </Label>
                            <Input
                                id="f-principal-title"
                                name="principal_title"
                                defaultValue={format.principal_title}
                                maxLength={limits.role}
                                placeholder="Mis. Kepala Madrasah"
                            />
                            <InputError message={errors.principal_title} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="f-principal-name">
                                Nama kepala lembaga
                            </Label>
                            <Input
                                id="f-principal-name"
                                name="principal_name"
                                defaultValue={format.principal_name ?? ''}
                                maxLength={limits.name}
                                placeholder="Opsional; bisa diganti saat cetak"
                            />
                            <InputError message={errors.principal_name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="f-witness-title">
                                Jabatan "Mengetahui" (opsional)
                            </Label>
                            <Input
                                id="f-witness-title"
                                name="witness_title"
                                defaultValue={format.witness_title ?? ''}
                                maxLength={limits.role}
                                placeholder="Mis. Khodimul Ma'had"
                            />
                            <InputError message={errors.witness_title} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="f-witness-name">
                                Nama "Mengetahui"
                            </Label>
                            <Input
                                id="f-witness-name"
                                name="witness_name"
                                defaultValue={format.witness_name ?? ''}
                                maxLength={limits.name}
                            />
                            <InputError message={errors.witness_name} />
                        </div>
                    </section>

                    <InputError message={errors.form} />

                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-full sm:w-auto"
                    >
                        <Save />
                        {processing ? 'Menyimpan…' : 'Simpan format rapor'}
                    </Button>
                </>
            )}
        </Form>
    );
}

/** Kop seperti di rapor cetak: logo kiri, teks tengah, garis ganda. */
function KopPreview({
    lines,
    title,
    logo,
}: {
    lines: string[];
    title: string;
    logo: string | null;
}) {
    return (
        <div className="overflow-hidden rounded-md border bg-white p-4 text-black">
            <ReportHeader lines={lines} logo={logo} />
            <p className="mt-3 text-center font-bold">
                {title.trim() || 'LAPORAN HASIL BELAJAR'}
            </p>
        </div>
    );
}

/**
 * Daftar ekstrakurikuler atau kepribadian lembaga (AC-8d): tambah, ubah (nama, urutan, aktif,
 * keterangan per huruf untuk kepribadian), hapus bila belum dinilai.
 */
function ItemsSection({
    kind,
    organizationId,
    items,
    predicates,
    limits,
}: {
    kind: ReportItem['kind'];
    organizationId: string;
    items: ReportItem[];
    predicates: Level[];
    limits: Props['limits'];
}) {
    const mine = items.filter((item) => item.kind === kind);
    const [open, setOpen] = useState<string | null>(null);
    const personality = kind === 'KEPRIBADIAN';

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-1">
                    <h2 className="text-base font-medium">
                        {personality ? 'Kepribadian' : 'Ekstrakurikuler'}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {personality
                            ? 'Aspek kepribadian di rapor. Keterangan tampil otomatis menurut huruf yang dipilih wali kelas.'
                            : 'Kegiatan ekstrakurikuler di rapor. Siswa yang tidak dinilai tertulis "-" (tidak ikut).'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {personality && (
                        <ConfirmAction
                            trigger={
                                <Button type="button" size="sm" variant="ghost">
                                    Isi contoh 6 aspek
                                </Button>
                            }
                            title="Isi contoh 6 aspek kepribadian?"
                            description="Aspek contoh beserta keterangannya ditambahkan ke daftar dan bisa diubah sesudahnya."
                            action={`${BASE_URL}/${organizationId}/butir/contoh`}
                            confirmLabel="Isi contoh"
                            destructive={false}
                        />
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen('new')}
                    >
                        <Plus />
                        Tambah
                    </Button>
                </div>
            </div>

            {open === 'new' && (
                <ItemForm
                    kind={kind}
                    organizationId={organizationId}
                    predicates={predicates}
                    limits={limits}
                    onDone={() => setOpen(null)}
                />
            )}

            {mine.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada. Bagian ini tidak tampil di rapor.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {mine.map((item, index) => (
                        <li key={item.id} className="space-y-3 px-3 py-2.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {index + 1}. {item.name}
                                        {!item.is_active && (
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                (nonaktif)
                                            </span>
                                        )}
                                    </p>
                                    {personality && (
                                        <p className="text-xs text-muted-foreground">
                                            {Object.keys(item.descriptions)
                                                .length === 0
                                                ? 'Belum ada keterangan'
                                                : Object.entries(
                                                      item.descriptions,
                                                  )
                                                      .map(
                                                          ([letter, text]) =>
                                                              `${letter}: ${text}`,
                                                      )
                                                      .join(' · ')}
                                        </p>
                                    )}
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setOpen(item.id)}
                                    >
                                        <Pencil />
                                        Ubah
                                    </Button>
                                    {!item.used && (
                                        <ConfirmAction
                                            trigger={
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    aria-label={`Hapus ${item.name}`}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            }
                                            title={`Hapus ${item.name}?`}
                                            description="Butir ini belum pernah dinilai, jadi bisa dihapus."
                                            action={`${BASE_URL}/butir/${item.id}`}
                                            method="delete"
                                            confirmLabel="Hapus"
                                        />
                                    )}
                                </div>
                            </div>
                            {open === item.id && (
                                <ItemForm
                                    kind={kind}
                                    item={item}
                                    organizationId={organizationId}
                                    predicates={predicates}
                                    limits={limits}
                                    onDone={() => setOpen(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Tambah butir (tanpa `item`) atau ubah. */
function ItemForm({
    kind,
    item,
    organizationId,
    predicates,
    limits,
    onDone,
}: {
    kind: ReportItem['kind'];
    item?: ReportItem;
    organizationId: string;
    predicates: Level[];
    limits: Props['limits'];
    onDone: () => void;
}) {
    const key = item?.id ?? `new-${kind}`;
    const noun =
        kind === 'KEPRIBADIAN' ? 'aspek kepribadian' : 'ekstrakurikuler';

    return (
        <FormDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onDone();
                }
            }}
            title={item ? `Ubah ${item.name}` : `Tambah ${noun}`}
        >
            <Form
                action={
                    item
                        ? `${BASE_URL}/butir/${item.id}`
                        : `${BASE_URL}/${organizationId}/butir`
                }
                method={item ? 'patch' : 'post'}
                options={{ preserveScroll: true }}
                onSuccess={onDone}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        {!item && (
                            <input type="hidden" name="kind" value={kind} />
                        )}
                        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_7rem]">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`ri-name-${key}`}>Nama</Label>
                                <Input
                                    id={`ri-name-${key}`}
                                    name="name"
                                    defaultValue={item?.name ?? ''}
                                    maxLength={limits.itemName}
                                    placeholder={
                                        kind === 'KEPRIBADIAN'
                                            ? 'Mis. Kelakuan'
                                            : 'Mis. Pramuka'
                                    }
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`ri-order-${key}`}>
                                    Urutan
                                </Label>
                                <Input
                                    id={`ri-order-${key}`}
                                    name="sort_order"
                                    type="number"
                                    min={0}
                                    max={999}
                                    defaultValue={item?.sort_order ?? 1}
                                />
                            </div>
                        </div>

                        {kind === 'KEPRIBADIAN' && (
                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    Keterangan otomatis per huruf
                                </legend>
                                {predicates.map((level) => (
                                    <div
                                        key={level.letter}
                                        className="grid grid-cols-[3rem_minmax(0,1fr)] items-center gap-2"
                                    >
                                        <Label
                                            htmlFor={`ri-desc-${key}-${level.letter}`}
                                            className="text-sm"
                                            title={level.label ?? undefined}
                                        >
                                            {level.letter}
                                        </Label>
                                        <Input
                                            id={`ri-desc-${key}-${level.letter}`}
                                            name={`descriptions[${level.letter}]`}
                                            defaultValue={
                                                item?.descriptions[
                                                    level.letter
                                                ] ?? ''
                                            }
                                            maxLength={limits.itemDescription}
                                            placeholder={
                                                level.label
                                                    ? `Keterangan untuk ${level.label}`
                                                    : 'Keterangan'
                                            }
                                        />
                                    </div>
                                ))}
                                <InputError message={errors.descriptions} />
                            </fieldset>
                        )}

                        {item && (
                            <label className="flex min-h-10 cursor-pointer items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    defaultChecked={item.is_active}
                                    className="size-4"
                                />
                                Aktif (bisa dinilai)
                            </label>
                        )}

                        <InputError message={errors.form} />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                <Save />
                                {processing ? 'Menyimpan…' : 'Simpan'}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={onDone}
                            >
                                Batal
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </FormDialog>
    );
}
