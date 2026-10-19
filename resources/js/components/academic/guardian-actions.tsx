import { Form } from '@inertiajs/react';
import { Search, Star } from 'lucide-react';
import { useState } from 'react';
import type { StudentManageData } from '@/components/academic/student-actions';
import {
    ActionForm,
    Field,
    SelectOptions,
} from '@/components/academic/student-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/*
 * Form orang tua/wali di detail siswa (AC-1c-b2, PRD-002 §4.5). Semua aturan & hak akses tetap
 * dicek server; pencarian HP hanya menampilkan nama orang tua/wali yang sudah tercatat.
 */

export type GuardianRow = {
    id: string;
    name: string;
    relation: string;
    relation_code: string;
    gender: string | null;
    gender_code: string | null;
    phone: string | null;
    is_primary: boolean;
    locked: boolean;
    other_students: number;
};

type SearchResult = {
    membership_id: string;
    name: string;
    already_linked: boolean;
};

const checkboxClass = 'size-4 rounded border-input accent-primary';

// ── Tambah ───────────────────────────────────────────────────────────────────

export function AddGuardianForm({
    studentId,
    manage,
    onDone,
}: {
    studentId: string;
    manage: StudentManageData;
    onDone: () => void;
}) {
    const [mode, setMode] = useState<'baru' | 'ada'>('baru');

    return (
        <ActionForm
            title="Tambah orang tua/wali"
            action={`/siswa/${studentId}/orang-tua`}
            errorBag="addGuardian"
            submitLabel="Simpan orang tua/wali"
            onDone={onDone}
            note="Orang tua/wali tidak mendapat akun login maupun hak akses apa pun."
        >
            {(errors) => (
                <>
                    <input type="hidden" name="mode" value={mode} />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:col-span-2">
                        <Button
                            type="button"
                            variant={mode === 'baru' ? 'default' : 'outline'}
                            onClick={() => setMode('baru')}
                            aria-pressed={mode === 'baru'}
                        >
                            Orang baru
                        </Button>
                        <Button
                            type="button"
                            variant={mode === 'ada' ? 'default' : 'outline'}
                            onClick={() => setMode('ada')}
                            aria-pressed={mode === 'ada'}
                        >
                            <Search />
                            Cari lewat nomor HP
                        </Button>
                    </div>

                    {mode === 'baru' ? (
                        <>
                            <Field
                                id="ag-name"
                                label="Nama orang tua/wali"
                                required
                                error={errors.name}
                            >
                                <Input
                                    id="ag-name"
                                    name="name"
                                    maxLength={200}
                                    required
                                />
                            </Field>
                            <Field
                                id="ag-gender"
                                label="Jenis kelamin"
                                error={errors.gender}
                            >
                                <SelectOptions
                                    id="ag-gender"
                                    name="gender"
                                    options={manage.options.genders}
                                    placeholder="Tidak diisi"
                                />
                            </Field>
                            <Field
                                id="ag-phone"
                                label="Nomor HP"
                                error={errors.phone}
                            >
                                <Input
                                    id="ag-phone"
                                    name="phone"
                                    type="tel"
                                    inputMode="tel"
                                    maxLength={25}
                                />
                            </Field>
                        </>
                    ) : (
                        <PhoneSearch studentId={studentId} errors={errors} />
                    )}

                    <Field
                        id="ag-relation"
                        label="Hubungan dengan siswa"
                        required
                        error={errors.relation}
                    >
                        <SelectOptions
                            id="ag-relation"
                            name="relation"
                            options={manage.options.relations}
                            placeholder="Pilih hubungan"
                            required
                        />
                    </Field>
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <input
                            type="checkbox"
                            name="make_primary"
                            value="1"
                            className={checkboxClass}
                        />
                        Jadikan kontak utama (bila siswa belum punya kontak
                        utama, otomatis menjadi kontak utama)
                    </label>
                </>
            )}
        </ActionForm>
    );
}

/**
 * Cari orang tua/wali yang sudah tercatat. Nomor HP yang dicari ikut dikirim agar server bisa
 * memeriksa ulang pilihan orangnya.
 */
function PhoneSearch({
    studentId,
    errors,
}: {
    studentId: string;
    errors: Record<string, string | undefined>;
}) {
    const [phone, setPhone] = useState('');
    const [searchedPhone, setSearchedPhone] = useState('');
    const [results, setResults] = useState<SearchResult[] | null>(null);
    const [selected, setSelected] = useState('');
    const [searching, setSearching] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const search = async () => {
        setSearching(true);
        setMessage(null);
        setSelected('');

        try {
            const response = await fetch(
                `/siswa/${studentId}/orang-tua/cari?hp=${encodeURIComponent(phone)}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (response.status === 429) {
                setResults(null);
                setMessage(
                    'Terlalu banyak pencarian. Tunggu sebentar lalu coba lagi.',
                );

                return;
            }

            const data = (await response.json()) as {
                message?: string;
                phone?: string;
                results: SearchResult[];
            };

            if (!response.ok) {
                setResults(null);
                setMessage(data.message ?? 'Pencarian gagal. Coba lagi.');

                return;
            }

            setSearchedPhone(data.phone ?? '');
            setResults(data.results);
        } catch {
            setResults(null);
            setMessage(
                'Tidak bisa terhubung ke server. Periksa koneksi lalu coba lagi.',
            );
        } finally {
            setSearching(false);
        }
    };

    return (
        <div className="grid gap-3 sm:col-span-2">
            <Field
                id="ag-search"
                label="Nomor HP orang tua/wali (harus lengkap)"
                error={message ?? errors.phone}
            >
                <div className="grid grid-cols-[1fr_auto] gap-2">
                    <Input
                        id="ag-search"
                        type="tel"
                        inputMode="tel"
                        value={phone}
                        maxLength={25}
                        onChange={(event) => setPhone(event.target.value)}
                        onKeyDown={(event) => {
                            // Enter mencari, bukan menyimpan form.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                void search();
                            }
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={searching || phone.trim() === ''}
                        onClick={() => void search()}
                    >
                        {searching ? 'Mencari…' : 'Cari'}
                    </Button>
                </div>
            </Field>

            <input type="hidden" name="phone" value={searchedPhone} />
            <input type="hidden" name="membership_id" value={selected} />

            {results !== null &&
                (results.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada orang tua/wali tercatat dengan nomor ini.
                        Pilih <span className="font-medium">Orang baru</span>{' '}
                        untuk menambahkannya.
                    </p>
                ) : (
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Pilih orangnya
                        </legend>
                        {results.map((result) => (
                            <label
                                key={result.membership_id}
                                className="flex items-start gap-2 rounded-md border p-3 text-sm has-[:disabled]:opacity-60"
                            >
                                <input
                                    type="radio"
                                    name="guardian_choice"
                                    value={result.membership_id}
                                    checked={selected === result.membership_id}
                                    disabled={result.already_linked}
                                    onChange={() =>
                                        setSelected(result.membership_id)
                                    }
                                    className="mt-0.5"
                                />
                                <span>
                                    <span className="font-medium">
                                        {result.name}
                                    </span>
                                    <span className="block text-muted-foreground">
                                        {result.already_linked
                                            ? 'Sudah tertaut ke siswa ini'
                                            : 'Sudah tercatat sebagai orang tua/wali'}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                ))}
            <InputError message={errors.membership_id} />
        </div>
    );
}

// ── Ubah ─────────────────────────────────────────────────────────────────────

export function EditGuardianForm({
    studentId,
    guardian,
    manage,
    onDone,
}: {
    studentId: string;
    guardian: GuardianRow;
    manage: StudentManageData;
    onDone: () => void;
}) {
    const note = guardian.locked
        ? 'Orang ini juga tercatat sebagai pegawai atau siswa, jadi data dirinya diubah dari menu Pegawai/Siswa. Di sini hanya hubungannya yang bisa diubah.'
        : guardian.other_students > 0
          ? `Orang ini juga orang tua/wali ${guardian.other_students} siswa lain. Perubahan nama, jenis kelamin, dan HP ikut berlaku di sana.`
          : 'Kolom yang dikosongkan berarti datanya dihapus.';

    return (
        <ActionForm
            title={`Ubah ${guardian.name}`}
            action={`/siswa/${studentId}/orang-tua/${guardian.id}`}
            method="patch"
            errorBag="editGuardian"
            submitLabel="Simpan"
            onDone={onDone}
            note={note}
        >
            {(errors) => (
                <>
                    <Field
                        id="eg-relation"
                        label="Hubungan dengan siswa"
                        required
                        error={errors.relation}
                    >
                        <SelectOptions
                            id="eg-relation"
                            name="relation"
                            options={manage.options.relations}
                            placeholder="Pilih hubungan"
                            defaultValue={guardian.relation_code}
                            required
                        />
                    </Field>
                    {!guardian.locked && (
                        <>
                            <Field
                                id="eg-name"
                                label="Nama"
                                required
                                error={errors.name}
                            >
                                <Input
                                    id="eg-name"
                                    name="name"
                                    defaultValue={guardian.name}
                                    maxLength={200}
                                    required
                                />
                            </Field>
                            <Field
                                id="eg-gender"
                                label="Jenis kelamin"
                                error={errors.gender}
                            >
                                <SelectOptions
                                    id="eg-gender"
                                    name="gender"
                                    options={manage.options.genders}
                                    placeholder="Tidak diisi"
                                    defaultValue={guardian.gender_code ?? ''}
                                />
                            </Field>
                            <Field
                                id="eg-phone"
                                label="Nomor HP"
                                error={errors.phone}
                            >
                                <Input
                                    id="eg-phone"
                                    name="phone"
                                    type="tel"
                                    inputMode="tel"
                                    defaultValue={guardian.phone ?? ''}
                                    maxLength={25}
                                />
                            </Field>
                        </>
                    )}
                </>
            )}
        </ActionForm>
    );
}

// ── Lepas ────────────────────────────────────────────────────────────────────

export function UnlinkGuardianForm({
    studentId,
    guardian,
    others,
    onDone,
}: {
    studentId: string;
    guardian: GuardianRow;
    others: GuardianRow[];
    onDone: () => void;
}) {
    const needsReplacement = guardian.is_primary && others.length > 0;

    return (
        <ActionForm
            title={`Lepas ${guardian.name} dari siswa ini`}
            action={`/siswa/${studentId}/orang-tua/${guardian.id}`}
            method="delete"
            errorBag="unlinkGuardian"
            submitLabel="Lepas"
            destructive
            onDone={onDone}
            note="Hanya tautannya ke siswa ini yang dihapus. Data orangnya tetap tersimpan (mis. bila juga orang tua siswa lain)."
        >
            {(errors) =>
                needsReplacement ? (
                    <Field
                        id="ug-primary"
                        label="Kontak utama pengganti"
                        required
                        error={errors.new_primary_id}
                        className="sm:col-span-2"
                    >
                        <SelectOptions
                            id="ug-primary"
                            name="new_primary_id"
                            options={others.map((other) => ({
                                value: other.id,
                                label: `${other.name} (${other.relation})`,
                            }))}
                            placeholder="Pilih pengganti"
                            defaultValue={
                                others.length === 1 ? others[0].id : ''
                            }
                            required
                        />
                    </Field>
                ) : (
                    <p className="text-sm sm:col-span-2">
                        {guardian.is_primary
                            ? 'Siswa ini tidak akan punya kontak utama sampai orang tua/wali baru ditambahkan.'
                            : 'Kontak utama siswa tidak berubah.'}
                    </p>
                )
            }
        </ActionForm>
    );
}

// ── Kontak utama ─────────────────────────────────────────────────────────────

export function MakePrimaryButton({
    studentId,
    guardian,
}: {
    studentId: string;
    guardian: GuardianRow;
}) {
    return (
        <Form
            action={`/siswa/${studentId}/orang-tua/${guardian.id}/kontak-utama`}
            method="post"
            errorBag="primaryGuardian"
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        className="w-full"
                    >
                        <Star />
                        Jadikan kontak utama
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}
