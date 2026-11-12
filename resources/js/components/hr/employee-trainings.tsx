import { Form } from '@inertiajs/react';
import { Ban, Check, Download, GraduationCap, Undo2, X } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Riwayat pelatihan & sertifikasi (PRD-001 §19.1, HR-8a; OD-HR-16). Dipakai di detail pegawai (Admin SDM mencatat,
 * menerima, menolak, membatalkan) dan Pelatihan saya (pegawai mengajukan & menarik). Server tetap memeriksa ulang.
 */

export type TrainingStatus = 'MENUNGGU' | 'DITERIMA' | 'DITOLAK' | 'DIBATALKAN';

export type TrainingRecord = {
    id: string;
    kind: string;
    kind_label: string;
    title: string;
    organizer: string | null;
    start_date: string;
    end_date: string;
    hours: number | null;
    certificate_number: string | null;
    valid_until: string | null;
    expired: boolean;
    status: TrainingStatus;
    status_label: string;
    decision_note: string | null;
    file_name: string | null;
};

export type TrainingFormOptions = {
    today: string;
    kinds: { value: string; label: string }[];
    titleMax: number;
    organizerMax: number;
    certificateMax: number;
    reasonMax: number;
    fileMaxMb: number;
};

/** admin = detail pegawai (Admin SDM); self = Pelatihan saya; view = hanya melihat. */
export type TrainingMode = 'admin' | 'self' | 'view';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

export function formatTrainingDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

export function TrainingStatusBadge({ status }: { status: TrainingStatus }) {
    switch (status) {
        case 'MENUNGGU':
            return (
                <Badge className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                    Menunggu verifikasi
                </Badge>
            );
        case 'DITERIMA':
            return <Badge>Diterima</Badge>;
        case 'DITOLAK':
            return <Badge variant="destructive">Ditolak</Badge>;
        default:
            return <Badge variant="outline">Dibatalkan</Badge>;
    }
}

function period(record: { start_date: string; end_date: string }): string {
    return record.start_date === record.end_date
        ? formatTrainingDate(record.start_date)
        : `${formatTrainingDate(record.start_date)} – ${formatTrainingDate(record.end_date)}`;
}

export function TrainingSection({
    title = 'Pelatihan & sertifikasi',
    records,
    mode,
    form,
    storeUrl,
    actionUrl,
    downloadUrl,
}: {
    title?: string;
    records: TrainingRecord[];
    mode: TrainingMode;
    /** Bahan form tambah (null = tidak bisa menambah). */
    form: TrainingFormOptions | null;
    storeUrl: string;
    /** Alamat aksi per catatan, mis. `/pegawai/{id}/pelatihan/{training}`. */
    actionUrl: (record: TrainingRecord) => string;
    /** null = lampiran tidak boleh diunduh pengguna ini. */
    downloadUrl: ((record: TrainingRecord) => string) | null;
}) {
    const [adding, setAdding] = useState(false);
    const accepted = records.filter((record) => record.status === 'DITERIMA');
    const totalHours = accepted.reduce(
        (sum, record) => sum + (record.hours ?? 0),
        0,
    );

    return (
        <section aria-label={title} className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-base font-medium">{title}</h2>
                    <p className="text-xs text-muted-foreground">
                        {accepted.length} kegiatan diterima
                        {totalHours > 0 && ` · ${totalHours} jam`}
                    </p>
                </div>
                {form && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setAdding(true)}
                    >
                        <GraduationCap />
                        {mode === 'self'
                            ? 'Ajukan pelatihan'
                            : 'Catat pelatihan'}
                    </Button>
                )}
            </div>

            {form && (
                <FormDialog
                    open={adding}
                    onOpenChange={setAdding}
                    title={
                        mode === 'self' ? 'Ajukan pelatihan' : 'Catat pelatihan'
                    }
                    description={
                        mode === 'self'
                            ? 'Pengajuan diperiksa Admin SDM dulu. Lampirkan sertifikat agar cepat diverifikasi.'
                            : 'Catatan oleh Admin langsung diterima. Isian tidak bisa diubah; bila salah, batalkan lalu catat ulang.'
                    }
                    wide
                >
                    <TrainingForm
                        form={form}
                        action={storeUrl}
                        bySelf={mode === 'self'}
                        onDone={() => setAdding(false)}
                    />
                </FormDialog>
            )}

            {records.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada riwayat pelatihan.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {records.map((record) => (
                        <TrainingRow
                            key={record.id}
                            record={record}
                            mode={mode}
                            reasonMax={form?.reasonMax ?? 300}
                            actionUrl={actionUrl(record)}
                            downloadUrl={
                                downloadUrl && record.file_name
                                    ? downloadUrl(record)
                                    : null
                            }
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function TrainingRow({
    record,
    mode,
    reasonMax,
    actionUrl,
    downloadUrl,
}: {
    record: TrainingRecord;
    mode: TrainingMode;
    reasonMax: number;
    actionUrl: string;
    downloadUrl: string | null;
}) {
    const inactive =
        record.status === 'DITOLAK' || record.status === 'DIBATALKAN';

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p
                        className={
                            'font-medium break-words ' +
                            (inactive
                                ? 'text-muted-foreground line-through'
                                : '')
                        }
                    >
                        {record.title}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span>{record.kind_label}</span>
                        <TrainingStatusBadge status={record.status} />
                        {record.status === 'DITERIMA' && record.expired && (
                            <Badge variant="destructive">
                                Masa berlaku habis
                            </Badge>
                        )}
                    </div>
                </div>
                {downloadUrl && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={downloadUrl}>
                            <Download />
                            Sertifikat
                        </a>
                    </Button>
                )}
            </div>

            <dl className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 text-xs">
                <div>
                    <dt className="text-muted-foreground">Tanggal</dt>
                    <dd>{period(record)}</dd>
                </div>
                {record.organizer && (
                    <div className="min-w-0">
                        <dt className="text-muted-foreground">Penyelenggara</dt>
                        <dd className="break-words">{record.organizer}</dd>
                    </div>
                )}
                {record.hours !== null && (
                    <div>
                        <dt className="text-muted-foreground">Jumlah jam</dt>
                        <dd>{record.hours} jam</dd>
                    </div>
                )}
                {record.certificate_number && (
                    <div className="min-w-0">
                        <dt className="text-muted-foreground">
                            No. sertifikat
                        </dt>
                        <dd className="break-all">
                            {record.certificate_number}
                        </dd>
                    </div>
                )}
                {record.valid_until && (
                    <div>
                        <dt className="text-muted-foreground">
                            Berlaku sampai
                        </dt>
                        <dd>{formatTrainingDate(record.valid_until)}</dd>
                    </div>
                )}
            </dl>

            {record.decision_note && (
                <p className="text-xs break-words text-muted-foreground">
                    {record.status === 'DITOLAK'
                        ? 'Alasan ditolak'
                        : 'Alasan dibatalkan'}
                    : {record.decision_note}
                </p>
            )}

            {mode === 'admin' && record.status === 'MENUNGGU' && (
                <div className="flex flex-wrap gap-2">
                    <ConfirmAction
                        trigger={
                            <Button type="button" size="sm">
                                <Check />
                                Terima
                            </Button>
                        }
                        title="Terima pengajuan pelatihan?"
                        description={`${record.title} masuk riwayat resmi dan rekap jam pegawai ini.`}
                        action={`${actionUrl}/terima`}
                        confirmLabel="Terima"
                        destructive={false}
                        errorBag={`verify-${record.id}`}
                    />
                    <ConfirmAction
                        trigger={
                            <Button type="button" size="sm" variant="outline">
                                <X />
                                Tolak
                            </Button>
                        }
                        title="Tolak pengajuan pelatihan?"
                        description="Alasan penolakan terlihat oleh pegawai."
                        action={`${actionUrl}/tolak`}
                        confirmLabel="Tolak pengajuan"
                        reason={{
                            label: 'Alasan penolakan',
                            max: reasonMax,
                            placeholder: 'mis. Sertifikat belum dilampirkan',
                        }}
                        errorBag={`reject-${record.id}`}
                    />
                </div>
            )}

            {mode === 'admin' && record.status === 'DITERIMA' && (
                <ConfirmAction
                    trigger={
                        <Button type="button" size="sm" variant="outline">
                            <Ban />
                            Batalkan
                        </Button>
                    }
                    title="Batalkan catatan pelatihan?"
                    description="Catatan tetap tersimpan sebagai riwayat dengan status Dibatalkan dan keluar dari rekap jam."
                    action={`${actionUrl}/batalkan`}
                    confirmLabel="Batalkan catatan"
                    reason={{
                        label: 'Alasan pembatalan',
                        max: reasonMax,
                        placeholder: 'mis. Salah pegawai',
                    }}
                    errorBag={`cancel-${record.id}`}
                />
            )}

            {mode === 'self' && record.status === 'MENUNGGU' && (
                <ConfirmAction
                    trigger={
                        <Button type="button" size="sm" variant="outline">
                            <Undo2 />
                            Tarik pengajuan
                        </Button>
                    }
                    title="Tarik pengajuan ini?"
                    description="Pengajuan dan lampirannya dihapus. Anda bisa mengajukan ulang kapan saja."
                    action={`${actionUrl}/tarik`}
                    confirmLabel="Tarik pengajuan"
                    errorBag={`withdraw-${record.id}`}
                />
            )}
        </li>
    );
}

function TrainingForm({
    form,
    action,
    bySelf,
    onDone,
}: {
    form: TrainingFormOptions;
    action: string;
    bySelf: boolean;
    onDone: () => void;
}) {
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');

    return (
        <Form
            action={action}
            method="post"
            errorBag="training-new"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <InputError message={errors.form} />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-kind">Jenis</Label>
                            <select
                                id="training-kind"
                                name="kind"
                                className={selectClass}
                                defaultValue=""
                                required
                            >
                                <option value="" disabled>
                                    Pilih jenis
                                </option>
                                {form.kinds.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.kind} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-organizer">
                                Penyelenggara (opsional)
                            </Label>
                            <Input
                                id="training-organizer"
                                name="organizer"
                                maxLength={form.organizerMax}
                                placeholder="mis. Kemenag Kab. Karawang"
                            />
                            <InputError message={errors.organizer} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="training-title">
                                Nama kegiatan
                            </Label>
                            <Input
                                id="training-title"
                                name="title"
                                maxLength={form.titleMax}
                                placeholder="mis. Diklat Kurikulum Merdeka"
                                required
                            />
                            <InputError message={errors.title} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-start">
                                Tanggal mulai
                            </Label>
                            <Input
                                id="training-start"
                                name="start_date"
                                type="date"
                                max={form.today}
                                value={start}
                                onChange={(event) => {
                                    setStart(event.target.value);

                                    if (!end || end < event.target.value) {
                                        setEnd(event.target.value);
                                    }
                                }}
                                required
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-end">
                                Tanggal selesai
                            </Label>
                            <Input
                                id="training-end"
                                name="end_date"
                                type="date"
                                min={start || undefined}
                                max={form.today}
                                value={end}
                                onChange={(event) => setEnd(event.target.value)}
                                required
                            />
                            <InputError message={errors.end_date} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-hours">
                                Jumlah jam (opsional)
                            </Label>
                            <Input
                                id="training-hours"
                                name="hours"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={999}
                            />
                            <InputError message={errors.hours} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-certificate">
                                No. sertifikat (opsional)
                            </Label>
                            <Input
                                id="training-certificate"
                                name="certificate_number"
                                maxLength={form.certificateMax}
                            />
                            <InputError message={errors.certificate_number} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-valid">
                                Berlaku sampai (opsional)
                            </Label>
                            <Input
                                id="training-valid"
                                name="valid_until"
                                type="date"
                                min={end || undefined}
                            />
                            <p className="text-xs text-muted-foreground">
                                Untuk sertifikasi yang punya masa berlaku.
                            </p>
                            <InputError message={errors.valid_until} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="training-file">
                                Sertifikat (opsional)
                            </Label>
                            <Input
                                id="training-file"
                                name="file"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                            />
                            <p className="text-xs text-muted-foreground">
                                PDF, JPG, atau PNG, maksimal {form.fileMaxMb}{' '}
                                MB.
                            </p>
                            <InputError message={errors.file} />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Menyimpan…'
                                : bySelf
                                  ? 'Kirim pengajuan'
                                  : 'Simpan'}
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
