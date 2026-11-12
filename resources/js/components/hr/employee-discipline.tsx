import { Form } from '@inertiajs/react';
import { Ban, Download, ShieldAlert, TriangleAlert } from 'lucide-react';
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
 * Catatan disiplin pegawai (PRD-001 §18.1, HR-7a). Daftar tampil bagi pemegang hr.discipline.view atas
 * pegawai ini; catat & cabut hanya Admin SDM / Admin Yayasan (OD-HR-13). Server tetap memeriksa ulang.
 */

export type DisciplineStatus =
    | 'BERLAKU'
    | 'KEDALUWARSA'
    | 'TERCATAT'
    | 'DICABUT';

export type DisciplineRecord = {
    id: string;
    kind: string;
    kind_label: string;
    issued_on: string;
    valid_until: string | null;
    violation: string;
    follow_up: string | null;
    status: DisciplineStatus;
    revoke_reason: string | null;
    revoked_on: string | null;
    file_name: string | null;
    recorded_by: string | null;
};

export type DisciplineManage = {
    today: string;
    kinds: { value: string; label: string }[];
    validMonths: number;
    textMax: number;
    reasonMax: number;
    fileMaxMb: number;
};

const SP_KINDS = ['SP1', 'SP2', 'SP3'];

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3.5 py-2.5 text-base outline-none transition-colors placeholder:text-muted-foreground hover:border-foreground/60 focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary md:text-sm';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

/** "2026-11-11" → "11 Nov 2026" tanpa geser zona waktu. */
export function formatDisciplineDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/** Bawaan "berlaku sampai": N bulan sejak tanggal catatan, dikurangi sehari (sama dengan server). */
function defaultUntil(issuedOn: string, months: number): string {
    const [year, month, day] = issuedOn.split('-').map(Number);

    if (!year || !month || !day) {
        return '';
    }

    const targetMonth = month - 1 + months;
    const lastDay = new Date(year, targetMonth + 1, 0).getDate();
    const date = new Date(year, targetMonth, Math.min(day, lastDay));
    date.setDate(date.getDate() - 1);

    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function DisciplineStatusBadge({
    status,
}: {
    status: DisciplineStatus;
}) {
    switch (status) {
        case 'BERLAKU':
            return <Badge variant="destructive">Berlaku</Badge>;
        case 'KEDALUWARSA':
            return <Badge variant="secondary">Kedaluwarsa</Badge>;
        case 'TERCATAT':
            return <Badge variant="secondary">Tercatat</Badge>;
        default:
            return <Badge variant="outline">Dicabut</Badge>;
    }
}

export function DisciplineSection({
    employeeId,
    records,
    manage,
    downloadUrl,
    title = 'Catatan disiplin',
}: {
    employeeId: string;
    records: DisciplineRecord[];
    manage: DisciplineManage | null;
    /** Alamat unduh lampiran; bawaan lewat detail pegawai. Dokumen saya memakai `/dokumen-saya/disiplin/…`. */
    downloadUrl?: (record: DisciplineRecord) => string;
    title?: string;
}) {
    const [adding, setAdding] = useState(false);
    const active = records.filter((record) => record.status === 'BERLAKU');

    return (
        <section aria-label={title} className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-medium">{title}</h2>
                {manage && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setAdding(true)}
                    >
                        <ShieldAlert />
                        Catat disiplin
                    </Button>
                )}
            </div>

            {manage && (
                <FormDialog
                    open={adding}
                    onOpenChange={setAdding}
                    title="Catat disiplin"
                    description="Catatan tidak bisa diubah atau dihapus; bila salah, cabut lalu catat ulang. Isi pelanggaran hanya terlihat oleh pengelola, kepala lembaganya, dan pegawai itu sendiri."
                    wide
                >
                    <DisciplineForm
                        employeeId={employeeId}
                        manage={manage}
                        active={active}
                        onDone={() => setAdding(false)}
                    />
                </FormDialog>
            )}

            {records.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada catatan disiplin.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {records.map((record) => (
                        <DisciplineRow
                            key={record.id}
                            employeeId={employeeId}
                            record={record}
                            manage={manage}
                            downloadUrl={downloadUrl}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function DisciplineRow({
    employeeId,
    record,
    manage,
    downloadUrl,
}: {
    employeeId: string;
    record: DisciplineRecord;
    manage: DisciplineManage | null;
    downloadUrl?: (record: DisciplineRecord) => string;
}) {
    const baseUrl = `/pegawai/${employeeId}/disiplin/${record.id}`;

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span
                            className={
                                'font-medium ' +
                                (record.status === 'DICABUT'
                                    ? 'text-muted-foreground line-through'
                                    : '')
                            }
                        >
                            {record.kind_label}
                        </span>
                        <DisciplineStatusBadge status={record.status} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {formatDisciplineDate(record.issued_on)}
                        {record.valid_until &&
                            ` · berlaku sampai ${formatDisciplineDate(record.valid_until)}`}
                        {record.recorded_by &&
                            ` · dicatat ${record.recorded_by}`}
                    </p>
                </div>
                {record.file_name && (
                    <Button variant="outline" size="sm" asChild>
                        <a
                            href={
                                downloadUrl
                                    ? downloadUrl(record)
                                    : `${baseUrl}/unduh`
                            }
                        >
                            <Download />
                            Lampiran
                        </a>
                    </Button>
                )}
            </div>

            <p className="break-words whitespace-pre-line">
                {record.violation}
            </p>
            {record.follow_up && (
                <p className="text-xs break-words whitespace-pre-line text-muted-foreground">
                    Tindak lanjut: {record.follow_up}
                </p>
            )}
            {record.status === 'DICABUT' && (
                <p className="text-xs break-words text-muted-foreground">
                    Dicabut {formatDisciplineDate(record.revoked_on)}:{' '}
                    {record.revoke_reason}
                </p>
            )}

            {manage && record.status !== 'DICABUT' && (
                <ConfirmAction
                    trigger={
                        <Button type="button" variant="outline" size="sm">
                            <Ban />
                            Cabut
                        </Button>
                    }
                    title={`Cabut ${record.kind_label}?`}
                    description="Catatan tetap tersimpan sebagai riwayat dengan status Dicabut beserta alasannya."
                    action={`${baseUrl}/cabut`}
                    confirmLabel="Cabut catatan"
                    reason={{
                        name: 'revoke_reason',
                        label: 'Alasan pencabutan',
                        max: manage.reasonMax,
                        placeholder: 'mis. Salah input pegawai',
                    }}
                    errorBag={`revoke-${record.id}`}
                />
            )}
        </li>
    );
}

function DisciplineForm({
    employeeId,
    manage,
    active,
    onDone,
}: {
    employeeId: string;
    manage: DisciplineManage;
    active: DisciplineRecord[];
    onDone: () => void;
}) {
    const [kind, setKind] = useState('');
    const [issuedOn, setIssuedOn] = useState(manage.today);
    const [until, setUntil] = useState('');
    const [untilTouched, setUntilTouched] = useState(false);
    const isLetter = SP_KINDS.includes(kind);
    const shownUntil =
        untilTouched || !isLetter
            ? until
            : defaultUntil(issuedOn, manage.validMonths);
    const activeSp3 = active.some((record) => record.kind === 'SP3');

    return (
        <Form
            action={`/pegawai/${employeeId}/disiplin`}
            method="post"
            errorBag="discipline-new"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    {active.length > 0 && (
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                            <p className="flex items-center gap-1.5 font-medium">
                                <TriangleAlert className="size-4" />
                                Masih berlaku:{' '}
                                {active
                                    .map(
                                        (record) =>
                                            `${record.kind_label} (s.d. ${formatDisciplineDate(record.valid_until)})`,
                                    )
                                    .join(', ')}
                            </p>
                            {activeSp3 && (
                                <p className="mt-1">
                                    Pegawai ini masih punya SP3 yang berlaku.
                                    Pertimbangkan pemberhentian lewat tombol
                                    Berhentikan.
                                </p>
                            )}
                        </div>
                    )}

                    <InputError message={errors.form} />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="discipline-kind">Jenis</Label>
                            <select
                                id="discipline-kind"
                                name="kind"
                                className={selectClass}
                                value={kind}
                                onChange={(event) =>
                                    setKind(event.target.value)
                                }
                                required
                            >
                                <option value="" disabled>
                                    Pilih jenis
                                </option>
                                {manage.kinds.map((option) => (
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
                            <Label htmlFor="discipline-date">Tanggal</Label>
                            <Input
                                id="discipline-date"
                                name="issued_on"
                                type="date"
                                max={manage.today}
                                value={issuedOn}
                                onChange={(event) =>
                                    setIssuedOn(event.target.value)
                                }
                                required
                            />
                            <InputError message={errors.issued_on} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="discipline-violation">
                                Uraian pelanggaran
                            </Label>
                            <textarea
                                id="discipline-violation"
                                name="violation"
                                className={textareaClass}
                                maxLength={manage.textMax}
                                required
                            />
                            <InputError message={errors.violation} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="discipline-follow-up">
                                Tindak lanjut (opsional)
                            </Label>
                            <textarea
                                id="discipline-follow-up"
                                name="follow_up"
                                className={textareaClass}
                                maxLength={manage.textMax}
                                placeholder="mis. Pembinaan oleh kepala madrasah"
                            />
                            <InputError message={errors.follow_up} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="discipline-until">
                                Berlaku sampai{isLetter ? '' : ' (opsional)'}
                            </Label>
                            <Input
                                id="discipline-until"
                                name="valid_until"
                                type="date"
                                min={issuedOn}
                                value={shownUntil}
                                onChange={(event) => {
                                    setUntilTouched(true);
                                    setUntil(event.target.value);
                                }}
                                required={isLetter}
                            />
                            <p className="text-xs text-muted-foreground">
                                {isLetter
                                    ? `Bawaan ${manage.validMonths} bulan; boleh diubah.`
                                    : 'Kosongkan bila teguran tidak punya masa berlaku.'}
                            </p>
                            <InputError message={errors.valid_until} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="discipline-file">
                                Lampiran surat (opsional)
                            </Label>
                            <Input
                                id="discipline-file"
                                name="file"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                            />
                            <p className="text-xs text-muted-foreground">
                                PDF, JPG, atau PNG, maksimal {manage.fileMaxMb}{' '}
                                MB.
                            </p>
                            <InputError message={errors.file} />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan catatan'}
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
