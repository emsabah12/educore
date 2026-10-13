import { Form } from '@inertiajs/react';
import { Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/*
 * Role tingkat Yayasan & penugasan seorang anggota (PRD-000 §4.7 F5b), di dalam bagian
 * "Akses login" detail pegawai. Hanya untuk Admin Yayasan; server memeriksa ulang setiap aksi.
 */

type Option = { value: string; label: string };

export type MemberAccess = {
    membershipActive: boolean;
    tenantRoles: {
        key: string;
        name: string;
        granted: boolean;
        /** Alasan role terpasang ini tidak bisa dicabut dari sini. */
        lockedReason: string | null;
    }[];
    assignments: {
        id: string;
        label: string;
        functional: boolean;
        roles: string[];
    }[];
    options: {
        assignmentRoles: Option[];
        organizations: Option[];
        jenjang: Option[];
    };
};

type Kind = 'STRUKTURAL' | 'FUNGSIONAL';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

export function MemberAccessPanel({
    membershipId,
    member,
}: {
    membershipId: string;
    member: MemberAccess;
}) {
    const base = `/akses/anggota/${membershipId}`;
    const [adding, setAdding] = useState(false);
    // Kunci baris yang sedang meminta konfirmasi cabut: "role:<key>" atau "assignment:<id>".
    const [confirming, setConfirming] = useState<string | null>(null);

    return (
        <div className="space-y-4 border-t pt-4">
            {!member.membershipActive && (
                <p className="text-sm text-muted-foreground">
                    Keanggotaan orang ini di yayasan sudah nonaktif, sehingga
                    role dan penugasan baru tidak bisa ditambahkan.
                </p>
            )}

            <div className="space-y-2">
                <div>
                    <h3 className="text-sm font-medium">
                        Role tingkat Yayasan
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Berlaku di seluruh yayasan, dipakai dengan memilih
                        lembaga kerja "Seluruh Yayasan".
                    </p>
                </div>

                <ul className="divide-y rounded-lg border">
                    {member.tenantRoles.map((role) => {
                        const key = `role:${role.key}`;

                        return (
                            <li key={role.key} className="space-y-2 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {role.name}
                                        </span>
                                        {role.granted && (
                                            <Badge>
                                                <ShieldCheck />
                                                Terpasang
                                            </Badge>
                                        )}
                                    </div>

                                    {role.granted
                                        ? !role.lockedReason &&
                                          confirming !== key && (
                                              <Button
                                                  type="button"
                                                  variant="outline"
                                                  size="sm"
                                                  className="text-destructive"
                                                  onClick={() =>
                                                      setConfirming(key)
                                                  }
                                              >
                                                  Cabut
                                              </Button>
                                          )
                                        : member.membershipActive && (
                                              <RoleForm
                                                  action={`${base}/role-yayasan`}
                                                  role={role.key}
                                                  label="Pasang"
                                              />
                                          )}
                                </div>

                                {role.lockedReason && (
                                    <p className="text-xs text-muted-foreground">
                                        {role.lockedReason}
                                    </p>
                                )}

                                {confirming === key && (
                                    <ConfirmRevoke
                                        action={`${base}/role-yayasan/cabut`}
                                        fields={{ role: role.key }}
                                        message={`Cabut role ${role.name}? Berlaku saat pemiliknya membuka halaman berikutnya.`}
                                        onCancel={() => setConfirming(null)}
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className="space-y-2">
                <div>
                    <h3 className="text-sm font-medium">Penugasan</h3>
                    <p className="text-xs text-muted-foreground">
                        Penugasan memberi hak akses di lembaga tertentu. Jabatan
                        di data pegawai tidak memberi hak akses.
                    </p>
                </div>

                {member.assignments.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada penugasan yang aktif.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {member.assignments.map((assignment) => {
                            const key = `assignment:${assignment.id}`;

                            return (
                                <li
                                    key={assignment.id}
                                    className="space-y-2 p-3"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0 space-y-1">
                                            <p className="text-sm font-medium break-words">
                                                {assignment.label}
                                            </p>
                                            <div className="flex flex-wrap gap-1.5">
                                                {assignment.functional && (
                                                    <Badge variant="outline">
                                                        Fungsional
                                                    </Badge>
                                                )}
                                                {assignment.roles.length ===
                                                0 ? (
                                                    <Badge variant="outline">
                                                        Tanpa role
                                                    </Badge>
                                                ) : (
                                                    assignment.roles.map(
                                                        (role) => (
                                                            <Badge
                                                                key={role}
                                                                variant="secondary"
                                                            >
                                                                {role}
                                                            </Badge>
                                                        ),
                                                    )
                                                )}
                                            </div>
                                        </div>

                                        {confirming !== key && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() =>
                                                    setConfirming(key)
                                                }
                                            >
                                                Cabut
                                            </Button>
                                        )}
                                    </div>

                                    {confirming === key && (
                                        <ConfirmRevoke
                                            action={`${base}/penugasan/${assignment.id}/cabut`}
                                            fields={{}}
                                            message="Cabut penugasan ini beserta role-nya? Datanya tetap tersimpan di riwayat."
                                            onCancel={() => setConfirming(null)}
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {member.membershipActive &&
                    (adding ? (
                        <AddAssignmentForm
                            action={`${base}/penugasan`}
                            options={member.options}
                            onDone={() => setAdding(false)}
                        />
                    ) : (
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 sm:flex">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAdding(true)}
                            >
                                <Plus />
                                Tambah penugasan
                            </Button>
                        </div>
                    ))}
            </div>
        </div>
    );
}

function RoleForm({
    action,
    role,
    label,
}: {
    action: string;
    role: string;
    label: string;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={`tenantRole-${role}`}
            options={{ preserveScroll: true }}
            transform={(data) => ({ ...data, role })}
            className="space-y-1"
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                    >
                        {processing ? 'Memproses…' : label}
                    </Button>
                    <InputError message={errors.role ?? errors.form} />
                </>
            )}
        </Form>
    );
}

function ConfirmRevoke({
    action,
    fields,
    message,
    onCancel,
}: {
    action: string;
    fields: Record<string, string>;
    message: string;
    onCancel: () => void;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag="revokeAccess"
            options={{ preserveScroll: true }}
            transform={(data) => ({ ...data, ...fields })}
            onSuccess={onCancel}
            className="space-y-2 rounded-lg border border-destructive/30 bg-muted/20 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm">{message}</p>
                    <InputError message={errors.role ?? errors.form} />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            variant="destructive"
                            size="sm"
                            disabled={processing}
                        >
                            {processing ? 'Memproses…' : 'Ya, cabut'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={onCancel}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function AddAssignmentForm({
    action,
    options,
    onDone,
}: {
    action: string;
    options: MemberAccess['options'];
    onDone: () => void;
}) {
    const [kind, setKind] = useState<Kind>('STRUKTURAL');

    return (
        <Form
            action={action}
            method="post"
            errorBag="addAssignment"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h4 className="text-base font-medium">
                            Tambah penugasan
                        </h4>
                        <p className="text-sm text-muted-foreground">
                            Struktural: memimpin/bertugas di satu lembaga
                            beserta turunannya. Fungsional: membina semua
                            lembaga berjenjang tertentu di seluruh yayasan, mis.
                            Koordinator MDA.
                        </p>
                    </div>

                    <InputError message={errors.form} />

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Jenis penugasan
                        </legend>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                            {(
                                [
                                    ['STRUKTURAL', 'Struktural (lembaga)'],
                                    ['FUNGSIONAL', 'Fungsional (jenjang)'],
                                ] as const
                            ).map(([value, label]) => (
                                <label
                                    key={value}
                                    className="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm has-[:checked]:border-primary"
                                >
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={value}
                                        checked={kind === value}
                                        onChange={() => setKind(value)}
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.kind} />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {kind === 'STRUKTURAL' ? (
                            <SelectField
                                id="pa-org"
                                name="organization_id"
                                label="Lembaga/unit"
                                options={options.organizations}
                                placeholder="Pilih lembaga"
                                error={errors.organization_id}
                            />
                        ) : (
                            <SelectField
                                id="pa-jenjang"
                                name="jenjang"
                                label="Jenjang yang dibina"
                                options={options.jenjang}
                                placeholder="Pilih jenjang"
                                error={errors.jenjang}
                            />
                        )}
                        <SelectField
                            id="pa-role"
                            name="role"
                            label="Role"
                            options={options.assignmentRoles}
                            placeholder="Pilih role"
                            error={errors.role}
                        />
                    </div>

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan penugasan'}
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

function SelectField({
    id,
    name,
    label,
    options,
    placeholder,
    error,
}: {
    id: string;
    name: string;
    label: string;
    options: Option[];
    placeholder: string;
    error?: string;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>
                {label}
                <span className="text-red-600 dark:text-red-400"> *</span>
            </Label>
            <select
                id={id}
                name={name}
                className={selectClass}
                defaultValue=""
                required
            >
                <option value="" disabled>
                    {placeholder}
                </option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}
