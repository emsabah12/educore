import { Form } from '@inertiajs/react';
import { Ban, Download, FilePlus2, Pencil } from 'lucide-react';
import { useState } from 'react';
import type { DocumentTypeOption } from '@/components/hr/employee-actions';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Dokumen & SK di detail pegawai (PRD-001 §15, HR-2a). Daftar tampil bagi yang boleh melihat data
 * pribadi; tambah/ubah/batalkan hanya Admin SDM / Admin Yayasan. Server tetap memeriksa ulang.
 */

export type EmployeeDocument = {
    id: string;
    type_id: string;
    type: string;
    number: string | null;
    title: string;
    issued_on: string | null;
    valid_from: string | null;
    valid_until: string | null;
    note: string | null;
    file_name: string;
    file_size: number;
    expiry: 'HABIS' | 'SEGERA' | null;
    voided: boolean;
    void_reason: string | null;
    voided_at: string | null;
    uploaded_at: string | null;
};

export type DocumentManage = {
    today: string;
    types: DocumentTypeOption[];
    maxMb: number;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

/** "2026-10-24" → "24 Okt 2026" tanpa geser zona waktu. */
function formatDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

export function DocumentSection({
    employeeId,
    documents,
    manage,
    downloadUrl,
}: {
    employeeId: string;
    documents: EmployeeDocument[];
    manage: DocumentManage | null;
    /** Alamat unduh; bawaan lewat detail pegawai. Dokumen saya (HR-2b) memakai `/dokumen-saya/…`. */
    downloadUrl?: (document: EmployeeDocument) => string;
}) {
    const [adding, setAdding] = useState(false);
    const [showVoided, setShowVoided] = useState(false);
    const active = documents.filter((document) => !document.voided);
    const voided = documents.filter((document) => document.voided);
    const shown = showVoided ? documents : active;

    return (
        <section
            aria-label="Dokumen & SK"
            className="space-y-3 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-medium">Dokumen &amp; SK</h2>
                {manage && !adding && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setAdding(true)}
                    >
                        <FilePlus2 />
                        Tambah dokumen
                    </Button>
                )}
            </div>

            {manage && adding && (
                <DocumentForm
                    employeeId={employeeId}
                    manage={manage}
                    onDone={() => setAdding(false)}
                />
            )}

            {shown.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                    {voided.length > 0
                        ? 'Tidak ada dokumen yang berlaku.'
                        : 'Belum ada dokumen.'}
                    {manage &&
                        ' Unggah SK, kontrak, atau ijazah lewat tombol Tambah dokumen.'}
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {shown.map((document) => (
                        <DocumentRow
                            key={document.id}
                            employeeId={employeeId}
                            document={document}
                            manage={manage}
                            downloadUrl={downloadUrl}
                        />
                    ))}
                </ul>
            )}

            {voided.length > 0 && (
                <label className="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
                    <input
                        type="checkbox"
                        checked={showVoided}
                        onChange={(event) =>
                            setShowVoided(event.target.checked)
                        }
                        className="size-4 accent-primary"
                    />
                    Tampilkan dokumen yang dibatalkan ({voided.length})
                </label>
            )}
        </section>
    );
}

function DocumentRow({
    employeeId,
    document,
    manage,
    downloadUrl,
}: {
    employeeId: string;
    document: EmployeeDocument;
    manage: DocumentManage | null;
    downloadUrl?: (document: EmployeeDocument) => string;
}) {
    const [mode, setMode] = useState<'view' | 'edit' | 'void'>('view');
    const baseUrl = `/pegawai/${employeeId}/dokumen/${document.id}`;
    const validity =
        document.valid_from || document.valid_until
            ? `${formatDate(document.valid_from)} – ${document.valid_until ? formatDate(document.valid_until) : 'seterusnya'}`
            : null;

    return (
        <li className="space-y-3 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p
                        className={
                            'font-medium break-words ' +
                            (document.voided
                                ? 'text-muted-foreground line-through'
                                : '')
                        }
                    >
                        {document.title}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span>{document.type}</span>
                        {document.number && (
                            <span className="break-all">
                                · No. {document.number}
                            </span>
                        )}
                        {document.expiry === 'HABIS' && (
                            <Badge variant="destructive">
                                Masa berlaku habis
                            </Badge>
                        )}
                        {document.expiry === 'SEGERA' && (
                            <Badge className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                Segera habis
                            </Badge>
                        )}
                        {document.voided && (
                            <Badge variant="outline">Dibatalkan</Badge>
                        )}
                    </div>
                </div>
                <Button variant="outline" size="sm" asChild>
                    <a
                        href={
                            downloadUrl
                                ? downloadUrl(document)
                                : `${baseUrl}/unduh`
                        }
                    >
                        <Download />
                        Unduh
                    </a>
                </Button>
            </div>

            <dl className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 text-xs">
                <div>
                    <dt className="text-muted-foreground">Tanggal terbit</dt>
                    <dd>{formatDate(document.issued_on)}</dd>
                </div>
                {validity && (
                    <div>
                        <dt className="text-muted-foreground">Masa berlaku</dt>
                        <dd>{validity}</dd>
                    </div>
                )}
                <div className="min-w-0">
                    <dt className="text-muted-foreground">File</dt>
                    <dd className="break-all">
                        {document.file_name} ({formatSize(document.file_size)})
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Diunggah</dt>
                    <dd>{formatDate(document.uploaded_at)}</dd>
                </div>
            </dl>

            {document.note && (
                <p className="text-xs break-words whitespace-pre-line text-muted-foreground">
                    {document.note}
                </p>
            )}

            {document.voided && (
                <p className="text-xs break-words text-muted-foreground">
                    Dibatalkan {formatDate(document.voided_at)}:{' '}
                    {document.void_reason}
                </p>
            )}

            {manage && !document.voided && mode === 'view' && (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setMode('edit')}
                    >
                        <Pencil />
                        Ubah isian
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setMode('void')}
                    >
                        <Ban />
                        Batalkan
                    </Button>
                </div>
            )}

            {manage && mode === 'edit' && (
                <DocumentForm
                    employeeId={employeeId}
                    manage={manage}
                    document={document}
                    onDone={() => setMode('view')}
                />
            )}

            {manage && mode === 'void' && (
                <VoidForm
                    action={`${baseUrl}/batalkan`}
                    errorBag={`void-${document.id}`}
                    onDone={() => setMode('view')}
                />
            )}
        </li>
    );
}

function DocumentForm({
    employeeId,
    manage,
    document,
    onDone,
}: {
    employeeId: string;
    manage: DocumentManage;
    document?: EmployeeDocument;
    onDone: () => void;
}) {
    const prefix = document ? `doc-${document.id}` : 'doc-new';
    // Jenis nonaktif hanya muncul bila sedang dipakai dokumen yang diubah.
    const types = manage.types.filter(
        (type) => type.is_active || type.value === document?.type_id,
    );
    const [typeId, setTypeId] = useState(document?.type_id ?? '');
    const [validFrom, setValidFrom] = useState(document?.valid_from ?? '');
    const selected = types.find((type) => type.value === typeId);

    return (
        <Form
            action={
                document
                    ? `/pegawai/${employeeId}/dokumen/${document.id}`
                    : `/pegawai/${employeeId}/dokumen`
            }
            method={document ? 'patch' : 'post'}
            errorBag={document ? `doc-${document.id}` : 'doc-new'}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors, progress }) => (
                <>
                    <div className="space-y-1">
                        <h3 className="text-base font-medium">
                            {document ? 'Ubah isian dokumen' : 'Tambah dokumen'}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {document
                                ? 'File tidak bisa diganti. Bila file-nya salah, batalkan dokumen ini lalu unggah dokumen baru.'
                                : `File PDF, JPG, atau PNG, maksimal ${manage.maxMb} MB. Hanya bisa diunduh oleh yang berhak melihat data pribadi pegawai.`}
                        </p>
                    </div>

                    <InputError message={errors.form} />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${prefix}-type`}
                            label="Jenis dokumen"
                            required
                            error={errors.document_type_id}
                        >
                            <select
                                id={`${prefix}-type`}
                                name="document_type_id"
                                className={selectClass}
                                value={typeId}
                                onChange={(event) =>
                                    setTypeId(event.target.value)
                                }
                                required
                            >
                                <option value="" disabled>
                                    Pilih jenis
                                </option>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field
                            id={`${prefix}-number`}
                            label="Nomor dokumen"
                            error={errors.number}
                        >
                            <Input
                                id={`${prefix}-number`}
                                name="number"
                                defaultValue={document?.number ?? ''}
                                maxLength={100}
                                placeholder="mis. 012/SK/YYS/VII/2026"
                            />
                        </Field>
                        <Field
                            id={`${prefix}-title`}
                            label="Judul"
                            required
                            error={errors.title}
                            className="sm:col-span-2"
                        >
                            <Input
                                id={`${prefix}-title`}
                                name="title"
                                defaultValue={document?.title ?? ''}
                                maxLength={150}
                                placeholder="mis. SK Pengangkatan Guru Tetap Yayasan"
                                required
                            />
                        </Field>
                        <Field
                            id={`${prefix}-issued`}
                            label="Tanggal terbit"
                            error={errors.issued_on}
                        >
                            <Input
                                id={`${prefix}-issued`}
                                name="issued_on"
                                type="date"
                                max={manage.today}
                                defaultValue={document?.issued_on ?? ''}
                            />
                        </Field>
                        <Field
                            id={`${prefix}-from`}
                            label="Berlaku mulai"
                            error={errors.valid_from}
                        >
                            <Input
                                id={`${prefix}-from`}
                                name="valid_from"
                                type="date"
                                value={validFrom}
                                onChange={(event) =>
                                    setValidFrom(event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id={`${prefix}-until`}
                            label="Berlaku sampai"
                            required={selected?.has_expiry ?? false}
                            error={errors.valid_until}
                        >
                            <Input
                                id={`${prefix}-until`}
                                name="valid_until"
                                type="date"
                                min={validFrom || undefined}
                                defaultValue={document?.valid_until ?? ''}
                                required={selected?.has_expiry ?? false}
                            />
                            {selected?.has_expiry && (
                                <p className="text-xs text-muted-foreground">
                                    Jenis ini punya masa berlaku; dokumen masuk
                                    pengingat sebelum habis.
                                </p>
                            )}
                        </Field>
                        {!document && (
                            <Field
                                id={`${prefix}-file`}
                                label="File"
                                required
                                error={errors.file}
                            >
                                <Input
                                    id={`${prefix}-file`}
                                    name="file"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    required
                                />
                            </Field>
                        )}
                        <Field
                            id={`${prefix}-note`}
                            label="Catatan"
                            error={errors.note}
                            className="sm:col-span-2"
                        >
                            <textarea
                                id={`${prefix}-note`}
                                name="note"
                                defaultValue={document?.note ?? ''}
                                maxLength={500}
                                className={textareaClass}
                            />
                        </Field>
                    </div>

                    {progress && (
                        <p className="text-sm text-muted-foreground">
                            Mengunggah… {progress.percentage ?? 0}%
                        </p>
                    )}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Menyimpan…'
                                : document
                                  ? 'Simpan isian'
                                  : 'Unggah dokumen'}
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

function VoidForm({
    action,
    errorBag,
    onDone,
}: {
    action: string;
    errorBag: string;
    onDone: () => void;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-md border border-destructive/40 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm">
                        Batalkan dokumen ini? Dokumen dan file-nya tetap
                        tersimpan sebagai riwayat, tetapi tidak lagi dihitung
                        berlaku dan tidak bisa diubah.
                    </p>
                    <Field
                        id={`${errorBag}-reason`}
                        label="Alasan pembatalan"
                        required
                        error={errors.void_reason ?? errors.form}
                    >
                        <Input
                            id={`${errorBag}-reason`}
                            name="void_reason"
                            maxLength={300}
                            placeholder="mis. Salah unggah file"
                            required
                        />
                    </Field>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            variant="destructive"
                            size="sm"
                            disabled={processing}
                        >
                            Ya, batalkan
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={onDone}
                        >
                            Kembali
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Field({
    id,
    label,
    required = false,
    error,
    className = '',
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={'grid content-start gap-1.5 ' + className}>
            <Label htmlFor={id}>
                {label}
                {required && (
                    <span className="text-red-600 dark:text-red-400"> *</span>
                )}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
