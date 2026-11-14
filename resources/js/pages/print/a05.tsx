import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { useState } from 'react';

type Option = { value: string; label: string };

type A05Form = {
    employee_id: string;
    organization: {
        id: string | null;
        name: string | null;
        npsn: string | null;
        jenjang: string | null;
        ownership: string | null;
        district: string | null;
        regency: string | null;
        province: string | null;
    };
    nik: string | null;
    name: string;
    birth_place: string | null;
    birth: { day: string; month: string; year: string } | null;
    gender: string | null;
    mother_name: string | null;
    education: string | null;
    sd_graduation_year: string | null;
    school_position: string | null;
    ptk_function: string | null;
    civil_servant: string | null;
    certified: boolean | null;
    missing: string[];
};

type Props = {
    forms: A05Form[];
    skipped: number;
    truncated: boolean;
    limit: number;
    positions: Option[];
    jenjangBoxes: string[];
    today: string;
    backUrl: string;
    backLabel: string;
};

/** Isian tanda tangan per lembaga induk — diketik di layar, tidak disimpan (PRD-001 §21.3). */
type Signature = { place: string; date: string; head: string; nip: string };

const EDUCATION_BOXES = [
    'SD',
    'SMP',
    'SMA',
    'SMK',
    'D1',
    'D2',
    'D3',
    'D4',
    'S1',
    'S2',
    'S3',
];

const MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

function longDate(iso: string): string {
    const [year, month, day] = iso.split('-');

    return `${Number(day)} ${MONTHS[Number(month) - 1] ?? ''} ${year}`;
}

/**
 * Formulir A05 Registrasi PTK (VerVal Lv.1) SIMPATIKA (PRD-001 §21.3, HR-10c-2): satu halaman A4 per pegawai,
 * terisi dari data EduCore. Isian kosong dibiarkan untuk diisi tangan. Cetak / simpan PDF lewat dialog browser.
 */
export default function PrintA05({
    forms,
    skipped,
    truncated,
    limit,
    positions,
    jenjangBoxes,
    today,
    backUrl,
    backLabel,
}: Props) {
    // Satu set tanda tangan per lembaga induk (kepala tiap lembaga berbeda).
    const organizations = Array.from(
        new Map(
            forms.map((form) => [
                form.organization.id ?? '-',
                form.organization,
            ]),
        ).entries(),
    );
    const [signatures, setSignatures] = useState<Record<string, Signature>>(
        () =>
            Object.fromEntries(
                organizations.map(([key, organization]) => [
                    key,
                    {
                        place: organization.regency ?? '',
                        date: longDate(today),
                        head: '',
                        nip: '',
                    },
                ]),
            ),
    );
    const incomplete = forms.filter((form) => form.missing.length > 0);

    const update = (key: string, field: keyof Signature, value: string) =>
        setSignatures((current) => ({
            ...current,
            [key]: { ...current[key], [field]: value },
        }));

    return (
        <>
            <Head title="Formulir A05" />
            <style>{'@page { size: A4; margin: 10mm; }'}</style>

            <div className="mx-auto max-w-[210mm] space-y-4 p-4 print:max-w-none print:space-y-0 print:p-0">
                <div className="space-y-4 rounded-lg border border-gray-300 p-4 text-sm print:hidden">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Link
                            href={backUrl}
                            className="inline-flex items-center gap-1 text-gray-600 hover:text-black"
                        >
                            <ArrowLeft className="size-4" />
                            {backLabel}
                        </Link>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            disabled={forms.length === 0}
                            className="inline-flex items-center gap-2 rounded-md bg-black px-4 py-2 font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            <Printer className="size-4" />
                            Cetak / simpan PDF ({forms.length} formulir)
                        </button>
                    </div>

                    <p className="text-gray-700">
                        Isian yang belum ada di EduCore dibiarkan kosong untuk
                        diisi tangan. Nama & NIP kepala diketik di bawah dan
                        tidak disimpan. Formulir memuat NIK & nama ibu kandung —
                        simpan hasil cetak dengan hati-hati.
                    </p>
                    {skipped > 0 && (
                        <p className="rounded-md border border-amber-400 bg-amber-50 p-2 text-amber-900">
                            {skipped} pegawai dilewati karena Anda tidak berhak
                            melihat data pribadinya.
                        </p>
                    )}
                    {truncated && (
                        <p className="rounded-md border border-amber-400 bg-amber-50 p-2 text-amber-900">
                            Hanya {limit} formulir pertama. Pilih satu lembaga
                            di Rekap PTK untuk mencetak sisanya.
                        </p>
                    )}

                    {organizations.map(([key, organization]) => (
                        <fieldset
                            key={key}
                            className="grid gap-2 rounded-md border border-gray-200 p-3 sm:grid-cols-2"
                        >
                            <legend className="px-1 font-medium">
                                Tanda tangan —{' '}
                                {organization.name ?? 'tanpa lembaga induk'}
                            </legend>
                            <SignatureInput
                                label="Tempat"
                                value={signatures[key]?.place ?? ''}
                                onChange={(value) =>
                                    update(key, 'place', value)
                                }
                            />
                            <SignatureInput
                                label="Tanggal"
                                value={signatures[key]?.date ?? ''}
                                onChange={(value) => update(key, 'date', value)}
                            />
                            <SignatureInput
                                label="Nama Kepala Madrasah/Sekolah"
                                value={signatures[key]?.head ?? ''}
                                onChange={(value) => update(key, 'head', value)}
                            />
                            <SignatureInput
                                label="NIP Kepala"
                                value={signatures[key]?.nip ?? ''}
                                onChange={(value) => update(key, 'nip', value)}
                            />
                        </fieldset>
                    ))}

                    {incomplete.length > 0 && (
                        <details className="rounded-md border border-gray-200 p-3">
                            <summary className="cursor-pointer font-medium">
                                {incomplete.length} formulir punya isian kosong
                            </summary>
                            <ul className="mt-2 space-y-1 text-gray-700">
                                {incomplete.map((form) => (
                                    <li key={form.employee_id}>
                                        <span className="font-medium">
                                            {form.name}
                                        </span>
                                        : {form.missing.join(', ')}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </div>

                {forms.length === 0 && (
                    <p className="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
                        Tidak ada formulir untuk dicetak.
                    </p>
                )}

                {forms.map((form) => (
                    <FormPage
                        key={form.employee_id}
                        form={form}
                        positions={positions}
                        jenjangBoxes={jenjangBoxes}
                        signature={
                            signatures[form.organization.id ?? '-'] ?? {
                                place: '',
                                date: '',
                                head: '',
                                nip: '',
                            }
                        }
                    />
                ))}
            </div>
        </>
    );
}

function SignatureInput({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid gap-1">
            <span className="text-xs text-gray-600">{label}</span>
            <input
                className="h-9 rounded-md border border-gray-300 px-2"
                value={value}
                maxLength={100}
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}

function FormPage({
    form,
    positions,
    jenjangBoxes,
    signature,
}: {
    form: A05Form;
    positions: Option[];
    jenjangBoxes: string[];
    signature: Signature;
}) {
    const organization = form.organization;
    // Kolom jabatan mengikuti tata letak A05: 6 kolom × 3 baris, urut ke bawah.
    const positionColumns = [0, 1, 2, 3, 4, 5].map((column) =>
        positions.slice(column * 3, column * 3 + 3),
    );

    return (
        <article className="break-after-page border border-gray-300 bg-white p-5 text-[8.5pt] leading-tight last:break-after-auto print:border-0 print:p-0">
            <header className="flex items-start justify-between gap-4 border-b border-black pb-1.5">
                <div>
                    <p className="text-[7pt]">
                        KEMENTERIAN AGAMA REPUBLIK INDONESIA
                    </p>
                    <p className="text-[11pt] font-bold">
                        DIREKTORAT JENDERAL PENDIDIKAN ISLAM
                    </p>
                </div>
                <p className="text-right text-[7pt]">
                    Formulir SIMPATIKA
                    <br />
                    simpatika.kemenag.go.id
                </p>
            </header>

            <div className="mt-2 flex items-center justify-between border border-black px-2 py-1">
                <div>
                    <p className="text-[11pt] font-bold">
                        FORMULIR REGISTRASI PTK (VerVal Lv.1)
                    </p>
                    <p>
                        SIMPATIKA - DIREKTORAT JENDERAL PENDIDIKAN ISLAM -
                        KEMENAG RI
                    </p>
                </div>
                <p className="text-[18pt] font-bold">A05</p>
            </div>

            <p className="my-2">
                Saya yang beridentitas dan bertanda-tangan di bawah, adalah
                pegawai dari Madrasah/Sekolah Induk berikut:
            </p>

            <Section title="A. MADRASAH/SEKOLAH INDUK *)">
                <Line label="Nama" value={organization.name} />
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span className="flex flex-1 items-center gap-2">
                        <span className="w-20 shrink-0 text-right">NPSN</span>
                        <Boxes value={organization.npsn} count={8} />
                    </span>
                    <span className="flex items-center gap-1">
                        Jenjang
                        {jenjangBoxes.map((box) => (
                            <Check
                                key={box}
                                label={box}
                                checked={organization.jenjang === box}
                            />
                        ))}
                    </span>
                    <span className="flex items-center gap-1">
                        Status
                        <Check
                            label="Negeri"
                            checked={organization.ownership === 'NEGERI'}
                        />
                        <Check
                            label="Swasta"
                            checked={organization.ownership === 'SWASTA'}
                        />
                    </span>
                </div>
                <Line label="Kecamatan" value={organization.district} />
                <Line label="Kota/Kab." value={organization.regency} />
                <Line label="Prov." value={organization.province} />
            </Section>

            <p className="my-2">
                Melalui formulir ini saya mengajukan pemutakhiran Data PTK saya,
                sesuai dengan data yang saya tulis di bawah ini:
            </p>

            <Section title="B. PEMUTAKHIRAN DATA DIRI">
                <Field label="NIK" note="sesuai KK">
                    <Boxes value={form.nik} count={16} />
                </Field>
                <Field label="Nama Lengkap" note="sesuai KK (tanpa gelar)">
                    <Boxes value={form.name} count={30} rows={2} />
                </Field>
                <Field label="Tempat Lahir" note="sesuai KK">
                    <Boxes value={form.birth_place} count={30} />
                </Field>
                <Field label="Tanggal Lahir" note="sesuai KK">
                    <span className="flex flex-wrap items-center gap-2">
                        tgl: <Boxes value={form.birth?.day ?? null} count={2} />
                        bln:{' '}
                        <Boxes value={form.birth?.month ?? null} count={2} />
                        th: <Boxes value={form.birth?.year ?? null} count={4} />
                        <span className="ml-3">J. Kelamin</span>
                        <Check
                            label="Laki-laki"
                            checked={form.gender === 'L'}
                        />
                        <Check
                            label="Perempuan"
                            checked={form.gender === 'P'}
                        />
                    </span>
                </Field>
                <Field label="Nama Ibu kandung" note="sesuai KK (tanpa gelar)">
                    <Boxes value={form.mother_name} count={30} rows={2} />
                </Field>

                <div className="mt-1 grid grid-cols-[1fr_auto] gap-3 border-t border-gray-400 pt-1">
                    <div>
                        <p className="font-semibold">
                            Pendidikan (berijazah) Tertinggi
                        </p>
                        <div className="mt-1 flex flex-wrap gap-x-2 gap-y-1">
                            {EDUCATION_BOXES.map((box) => (
                                <Check
                                    key={box}
                                    label={box}
                                    checked={form.education === box}
                                />
                            ))}
                        </div>
                    </div>
                    <div>
                        <p className="font-semibold">Th. Lulus SD</p>
                        <div className="mt-1">
                            <Boxes value={form.sd_graduation_year} count={4} />
                        </div>
                    </div>
                </div>

                <div className="mt-1 grid grid-cols-[1fr_auto] gap-3 border-t border-gray-400 pt-1">
                    <div>
                        <p className="font-semibold">
                            Jabatan Anda di Madrasah/Sekolah Induk *)
                        </p>
                        <div className="mt-1 grid grid-cols-[repeat(6,auto)] justify-start gap-x-2.5 gap-y-0.5 text-[7.5pt]">
                            {[0, 1, 2].map((row) =>
                                positionColumns.map((column) => {
                                    const option = column[row];

                                    return option ? (
                                        <Check
                                            key={option.value}
                                            label={option.label}
                                            checked={
                                                form.school_position ===
                                                option.value
                                            }
                                        />
                                    ) : null;
                                }),
                            )}
                        </div>
                    </div>
                    <table className="self-start">
                        <tbody>
                            <tr>
                                <td className="pr-2">Fungsi PTK</td>
                                <td className="flex gap-2">
                                    <Check
                                        label="Guru"
                                        checked={form.ptk_function === 'GURU'}
                                    />
                                    <Check
                                        label="Staf"
                                        checked={form.ptk_function === 'STAF'}
                                    />
                                </td>
                            </tr>
                            <tr>
                                <td className="pr-2">Kepegawaian</td>
                                <td className="flex gap-2">
                                    <Check
                                        label="PNS"
                                        checked={form.civil_servant === 'PNS'}
                                    />
                                    <Check
                                        label="NonPNS"
                                        checked={
                                            form.civil_servant === 'NON_PNS'
                                        }
                                    />
                                </td>
                            </tr>
                            <tr>
                                <td className="pr-2">Sertifikasi Guru</td>
                                <td className="flex gap-2">
                                    <Check
                                        label="Belum"
                                        checked={form.certified === false}
                                    />
                                    <Check
                                        label="Sudah"
                                        checked={form.certified === true}
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Section>

            <div className="mt-2 grid grid-cols-[1fr_1.2fr_1.2fr_9rem] gap-3 border border-black p-2">
                <p className="text-[7pt]">
                    *) Madrasah/Sekolah induk adalah Madrasah/Sekolah asal
                    tempat Anda bekerja sekarang.
                    <br />
                    <br />
                    (!) Info lanjut kunjungi: simpatika.kemenag.go.id
                </p>
                <div className="flex flex-col">
                    <p>Menyetujui,</p>
                    <p className="font-semibold">
                        Ka. Madrasah/Sekolah Induk *)
                    </p>
                    <div className="flex-1" />
                    <p className="mt-12 border-b border-dotted border-black text-center">
                        {signature.head || ' '}
                    </p>
                    <p>NIP: {signature.nip}</p>
                </div>
                <div className="flex flex-col">
                    <p>
                        {signature.place || '..................'},{' '}
                        {signature.date || '..................'}
                    </p>
                    <p className="font-semibold">PTK yang mengajukan,</p>
                    <div className="flex-1" />
                    <p className="mt-12 border-b border-dotted border-black text-center">
                        {form.name}
                    </p>
                </div>
                <div className="flex h-[6cm] w-[4cm] items-center justify-center justify-self-end border border-gray-500 text-center text-[7pt] text-gray-600">
                    tempelkan foto 4×6
                    <br />
                    Anda di sini
                </div>
            </div>

            <ul className="mt-2 list-disc space-y-0.5 pl-4 text-[7pt]">
                <li>
                    Tandatangan Kepala Madrasah/Sekolah Induk harus dibubuhi
                    stempel resmi Madrasah/Sekolah Induk.
                </li>
                <li>
                    Lampirkan: 1 pas Foto Berwarna 4×6, 1 Copy Kartu Keluarga
                    (KK), 1 Copy Ijazah SD, 1 Copy Ijazah Pendidikan Tertinggi,
                    1 Copy SK Pengangkatan sebagai Pegawai Madrasah/Sekolah
                    Induk.
                </li>
                <li>
                    Serahkan ke Admin/Operator Madrasah/Sekolah untuk
                    mendapatkan Tanda Bukti VerVal Level 1.
                </li>
                <li>
                    Untuk Guru Agama Sekolah naungan Kemdikbud serahkan ke Admin
                    Kankemenag Kota/Kab.
                </li>
            </ul>
        </article>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="border border-black">
            <h2 className="border-b border-black bg-gray-100 px-2 py-0.5 font-bold print:bg-gray-100">
                {title}
            </h2>
            <div className="space-y-1 p-2">{children}</div>
        </section>
    );
}

function Line({ label, value }: { label: string; value: string | null }) {
    return (
        <p className="flex items-end gap-2">
            <span className="w-20 shrink-0 text-right">{label}</span>
            <span className="min-h-[1.2em] flex-1 border-b border-dotted border-black px-1 font-semibold">
                {value ?? ''}
            </span>
        </p>
    );
}

function Field({
    label,
    note,
    children,
}: {
    label: string;
    note: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-[5.5rem_1fr_4.5rem] items-center gap-2">
            <span className="text-right">{label}</span>
            <span>{children}</span>
            <span className="text-[6.5pt] text-gray-600 italic">{note}</span>
        </div>
    );
}

/** Kotak huruf/angka per karakter; nilai kosong → kotak kosong untuk diisi tangan. */
function Boxes({
    value,
    count,
    rows = 1,
}: {
    value: string | null;
    count: number;
    rows?: number;
}) {
    const characters = Array.from(value ?? '');

    return (
        <span className="inline-flex flex-col gap-0.5">
            {Array.from({ length: rows }, (_, row) => (
                <span key={row} className="inline-flex">
                    {Array.from({ length: count }, (_, index) => (
                        <span
                            key={index}
                            className="-ml-px inline-flex h-[4.6mm] w-[4.2mm] items-center justify-center border border-black font-mono text-[8pt] font-semibold first:ml-0"
                        >
                            {characters[row * count + index] ?? ''}
                        </span>
                    ))}
                </span>
            ))}
        </span>
    );
}

function Check({ label, checked }: { label: string; checked: boolean }) {
    return (
        <span className="inline-flex items-center gap-1 whitespace-nowrap">
            <span
                aria-hidden="true"
                className="inline-flex size-[3.4mm] shrink-0 items-center justify-center rounded-full border border-black text-[7pt] leading-none font-bold"
            >
                {checked ? '✓' : ''}
            </span>
            <span className={checked ? 'font-semibold' : ''}>{label}</span>
            <span className="sr-only">{checked ? '(dipilih)' : ''}</span>
        </span>
    );
}
