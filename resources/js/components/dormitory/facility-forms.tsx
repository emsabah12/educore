import { Form } from '@inertiajs/react';
import { Power, PowerOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/components/native-select';
import type {
    Composition,
    Option,
    ResourceRow,
    RoomOptions,
    RoomRow,
} from '@/components/dormitory/types';

/**
 * Form fasilitas asrama di pop-up (pedoman tampilan #3, UX-3). Semua aturan tetap diperiksa server; pesan
 * kesalahannya tampil di isian terkait.
 */
export function FacilityDialog({
    open,
    onClose,
    title,
    description,
    action,
    method = 'post',
    errorBag,
    submitLabel,
    children,
    footer,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    action: string;
    method?: 'post' | 'patch';
    errorBag: string;
    submitLabel: string;
    children: (errors: Record<string, string | undefined>) => ReactNode;
    /** Isi tambahan di bawah form (mis. tombol nonaktifkan/hapus dengan konfirmasinya sendiri). */
    footer?: ReactNode;
}) {
    return (
        <FormDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
            title={title}
            description={description}
        >
            <Form
                action={action}
                method={method}
                errorBag={errorBag}
                options={{ preserveScroll: true }}
                onSuccess={onClose}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.form} />
                        <div className="grid gap-4">{children(errors)}</div>
                        <div className="grid grid-cols-2 gap-2 sm:flex">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Menyimpan…' : submitLabel}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Batal
                            </Button>
                        </div>
                    </>
                )}
            </Form>
            {footer}
        </FormDialog>
    );
}

export function Field({
    id,
    label,
    hint,
    error,
    required = false,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>
                {label}
                {required && <span className="text-destructive"> *</span>}
            </Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

export function Select({
    id,
    name,
    options,
    defaultValue,
    value,
    onChange,
    placeholder,
}: {
    id: string;
    name: string;
    options: Option[];
    defaultValue?: string;
    value?: string;
    onChange?: (value: string) => void;
    placeholder?: string;
}) {
    return (
        <select
            id={id}
            name={name}
            className={selectClass}
            defaultValue={value === undefined ? defaultValue : undefined}
            value={value}
            onChange={(event) => onChange?.(event.target.value)}
            required={placeholder !== undefined}
        >
            {placeholder !== undefined && (
                <option value="" disabled>
                    {placeholder}
                </option>
            )}
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

/** Kode & nama — dipakai asrama dan gedung. */
export function CodeNameFields({
    prefix,
    errors,
    code,
    name,
    nameLabel,
    codeHint,
    nameRequired = true,
}: {
    prefix: string;
    errors: Record<string, string | undefined>;
    code?: string;
    name?: string | null;
    nameLabel: string;
    codeHint: string;
    nameRequired?: boolean;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
            <Field
                id={`${prefix}-code`}
                label="Kode"
                hint={codeHint}
                error={errors.code}
                required
            >
                <Input
                    id={`${prefix}-code`}
                    name="code"
                    defaultValue={code}
                    maxLength={40}
                    required
                    autoFocus
                    className="uppercase"
                />
            </Field>
            <Field
                id={`${prefix}-name`}
                label={nameLabel}
                error={errors.name}
                required={nameRequired}
            >
                <Input
                    id={`${prefix}-name`}
                    name="name"
                    defaultValue={name ?? ''}
                    maxLength={100}
                    required={nameRequired}
                />
            </Field>
        </div>
    );
}

/** Isian kamar: dasar kapasitas, komposisi + slot pengawas, kebijakan akhir tahun. */
export function RoomFields({
    prefix,
    errors,
    options,
    room,
}: {
    prefix: string;
    errors: Record<string, string | undefined>;
    options: RoomOptions;
    room?: RoomRow;
}) {
    const [composition, setComposition] = useState<Composition>(
        room?.composition ?? 'KHUSUS_SANTRI',
    );

    return (
        <>
            <CodeNameFields
                prefix={prefix}
                errors={errors}
                code={room?.code}
                name={room?.name}
                nameLabel="Nama kamar (opsional)"
                codeHint="Mis. A-101"
                nameRequired={false}
            />
            <Field
                id={`${prefix}-basis`}
                label="Dasar kapasitas"
                hint="Bed + loker: tiap penghuni butuh satu bed dan satu loker, kapasitas = yang paling sedikit."
                error={errors.capacity_basis}
                required
            >
                <Select
                    id={`${prefix}-basis`}
                    name="capacity_basis"
                    options={options.bases}
                    defaultValue={room?.capacity_basis ?? 'BED'}
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id={`${prefix}-composition`}
                    label="Komposisi"
                    error={errors.composition}
                    required
                >
                    <Select
                        id={`${prefix}-composition`}
                        name="composition"
                        options={options.compositions}
                        value={composition}
                        onChange={(value) =>
                            setComposition(value as Composition)
                        }
                    />
                </Field>
                {composition === 'SANTRI_PENGAWAS' ? (
                    <Field
                        id={`${prefix}-slots`}
                        label="Slot pengawas"
                        hint="Bagian dari kapasitas, bukan tambahan."
                        error={errors.supervisor_slots}
                        required
                    >
                        <Input
                            id={`${prefix}-slots`}
                            name="supervisor_slots"
                            type="number"
                            min={1}
                            max={options.supervisorSlotsMax}
                            defaultValue={Math.max(
                                1,
                                room?.supervisor_slots ?? 1,
                            )}
                            required
                        />
                    </Field>
                ) : (
                    <input type="hidden" name="supervisor_slots" value="0" />
                )}
            </div>
            <Field
                id={`${prefix}-policy`}
                label="Akhir tahun ajaran"
                hint="Dikosongkan: penghuni diakhiri saat proses akhir tahun. Tetap: penghuni tetap di kamarnya."
                error={errors.year_end_policy}
            >
                <Select
                    id={`${prefix}-policy`}
                    name="year_end_policy"
                    options={
                        room
                            ? options.policies
                            : [
                                  {
                                      value: '',
                                      label: 'Ikut bawaan asrama',
                                  },
                                  ...options.policies,
                              ]
                    }
                    defaultValue={room?.year_end_policy ?? ''}
                />
            </Field>
        </>
    );
}

/** Ubah kode & status satu bed/loker. */
export function ResourceFields({
    prefix,
    errors,
    resource,
    reasons,
    noteMax,
}: {
    prefix: string;
    errors: Record<string, string | undefined>;
    resource: ResourceRow;
    reasons: Option[];
    noteMax: number;
}) {
    const [status, setStatus] = useState(resource.status);
    const [reason, setReason] = useState(resource.unavailable_reason ?? '');

    return (
        <>
            <Field
                id={`${prefix}-code`}
                label="Kode"
                error={errors.code}
                required
            >
                <Input
                    id={`${prefix}-code`}
                    name="code"
                    defaultValue={resource.code}
                    maxLength={40}
                    required
                    className="uppercase"
                />
            </Field>
            <Field
                id={`${prefix}-status`}
                label="Status"
                hint="Tidak tersedia = tidak dihitung dalam kapasitas kamar."
                error={errors.status}
                required
            >
                <Select
                    id={`${prefix}-status`}
                    name="status"
                    options={[
                        { value: 'TERSEDIA', label: 'Tersedia' },
                        { value: 'TIDAK_TERSEDIA', label: 'Tidak tersedia' },
                    ]}
                    value={status}
                    onChange={(value) =>
                        setStatus(value as ResourceRow['status'])
                    }
                />
            </Field>
            {status === 'TIDAK_TERSEDIA' && (
                <>
                    <Field
                        id={`${prefix}-reason`}
                        label="Alasan"
                        error={errors.unavailable_reason}
                        required
                    >
                        <Select
                            id={`${prefix}-reason`}
                            name="unavailable_reason"
                            options={reasons}
                            value={reason}
                            onChange={setReason}
                            placeholder="Pilih alasan"
                        />
                    </Field>
                    <Field
                        id={`${prefix}-note`}
                        label={
                            reason === 'LAINNYA'
                                ? 'Keterangan'
                                : 'Keterangan (opsional)'
                        }
                        error={errors.unavailable_note}
                        required={reason === 'LAINNYA'}
                    >
                        <Input
                            id={`${prefix}-note`}
                            name="unavailable_note"
                            defaultValue={resource.unavailable_note ?? ''}
                            maxLength={noteMax}
                            required={reason === 'LAINNYA'}
                        />
                    </Field>
                </>
            )}
        </>
    );
}

/** Tombol Nonaktifkan / Aktifkan kembali dengan konfirmasi (ConfirmAction). */
export function StatusToggle({
    active,
    action,
    subject,
    deactivateNote,
    errorBag,
    onSuccess,
}: {
    active: boolean;
    action: string;
    subject: string;
    deactivateNote: string;
    errorBag: string;
    onSuccess?: () => void;
}) {
    return (
        <ConfirmAction
            trigger={
                <Button type="button" variant="outline" size="sm">
                    {active ? <PowerOff /> : <Power />}
                    {active ? 'Nonaktifkan' : 'Aktifkan'}
                </Button>
            }
            title={active ? `Nonaktifkan ${subject}?` : `Aktifkan ${subject}?`}
            description={
                active
                    ? deactivateNote
                    : 'Induknya harus aktif. Data dan riwayatnya tetap sama.'
            }
            action={action}
            confirmLabel={active ? 'Ya, nonaktifkan' : 'Ya, aktifkan'}
            destructive={active}
            hidden={{ active: active ? '0' : '1' }}
            errorBag={errorBag}
            onSuccess={onSuccess}
        />
    );
}

/**
 * Kalimat tambahan pop-up Nonaktifkan asrama/gedung/kamar (PRD-008 §16.5): penghuni tidak dikeluarkan otomatis.
 */
export function residentsStayNote(active: number, planned: number): string {
    if (active === 0 && planned === 0) {
        return '';
    }

    const parts = [
        active > 0 ? `${active} penghuni masih tinggal` : null,
        planned > 0 ? `${planned} rencana penempatan` : null,
    ].filter(Boolean);

    return ` ${parts.join(' dan ')} di sini tidak dikeluarkan atau dibatalkan. Check-out, mutasi, atau batalkan satu per satu bila perlu.`;
}

export function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
