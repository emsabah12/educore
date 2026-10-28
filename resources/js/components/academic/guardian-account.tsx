import { Form } from '@inertiajs/react';
import { KeyRound, UserCheck, UserPlus, UserX } from 'lucide-react';
import { useState } from 'react';
import type { GuardianAccount } from '@/components/academic/student-actions';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/*
 * Akun login orang tua/wali di detail siswa (PRD-002 §21.1, AC-7a). Username = nomor HP; kata sandi
 * sementara tampil sekali lewat dialog di GuardianList. Semua aturan tetap dicek server.
 */

type Confirm = 'reset' | 'deactivate' | null;

const ERROR_BAG = 'guardianAccount';

export function GuardianAccountPanel({
    studentId,
    linkId,
    hasPhone,
    account,
    onIssue,
}: {
    studentId: string;
    linkId: string;
    hasPhone: boolean;
    account: GuardianAccount | undefined;
    /** Dipanggil sebelum aksi yang menghasilkan kata sandi sementara (untuk kartu akun). */
    onIssue: () => void;
}) {
    const [confirm, setConfirm] = useState<Confirm>(null);
    const base = `/siswa/${studentId}/orang-tua/${linkId}/akun`;

    if (!account) {
        return null;
    }

    return (
        <div className="space-y-2 rounded-lg border bg-muted/20 p-3">
            <div className="flex flex-wrap items-center gap-1.5 text-sm">
                <span className="text-muted-foreground">Akun login:</span>
                {account.status === 'NONE' ? (
                    <span>belum ada</span>
                ) : (
                    <>
                        <span className="font-mono break-all">
                            {account.login}
                        </span>
                        <Badge
                            variant={
                                account.status === 'ACTIVE'
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {account.status === 'ACTIVE' ? 'Aktif' : 'Nonaktif'}
                        </Badge>
                        {account.must_change && (
                            <Badge variant="secondary">
                                Belum ganti sandi sementara
                            </Badge>
                        )}
                    </>
                )}
            </div>

            {!account.manageable ? (
                <p className="text-sm text-muted-foreground">
                    {account.reason}
                </p>
            ) : account.status === 'NONE' ? (
                hasPhone ? (
                    <Form
                        action={base}
                        method="post"
                        errorBag={ERROR_BAG}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    Username memakai nomor HP orang tua/wali
                                    ini. Kata sandi sementara tampil sekali dan
                                    wajib diganti saat pertama masuk.
                                </p>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                    onClick={onIssue}
                                    className="w-full sm:w-auto"
                                >
                                    <UserPlus />
                                    {processing
                                        ? 'Membuat…'
                                        : 'Buat akun login'}
                                </Button>
                                <InputError message={errors.account} />
                            </div>
                        )}
                    </Form>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Isi nomor HP orang tua/wali ini (tombol Ubah) untuk
                        membuat akun login; nomor HP dipakai sebagai username.
                    </p>
                )
            ) : (
                <>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                confirm === 'reset' ? 'default' : 'outline'
                            }
                            aria-expanded={confirm === 'reset'}
                            onClick={() =>
                                setConfirm(confirm === 'reset' ? null : 'reset')
                            }
                        >
                            <KeyRound />
                            Atur ulang sandi
                        </Button>
                        {account.status === 'ACTIVE' ? (
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    confirm === 'deactivate'
                                        ? 'destructive'
                                        : 'outline'
                                }
                                aria-expanded={confirm === 'deactivate'}
                                onClick={() =>
                                    setConfirm(
                                        confirm === 'deactivate'
                                            ? null
                                            : 'deactivate',
                                    )
                                }
                            >
                                <UserX />
                                Nonaktifkan
                            </Button>
                        ) : (
                            <Form
                                action={`${base}/aktifkan`}
                                method="post"
                                errorBag={ERROR_BAG}
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
                                            <UserCheck />
                                            Aktifkan
                                        </Button>
                                        <InputError message={errors.account} />
                                    </>
                                )}
                            </Form>
                        )}
                    </div>

                    {confirm !== null && (
                        <Form
                            action={
                                confirm === 'reset'
                                    ? `${base}/atur-ulang`
                                    : `${base}/nonaktifkan`
                            }
                            method="post"
                            errorBag={ERROR_BAG}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setConfirm(null)}
                            className="space-y-2 rounded-lg border border-primary/30 p-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        {confirm === 'reset'
                                            ? 'Kata sandi lama tidak berlaku lagi dan sesi login orang tua/wali ini diakhiri. Kata sandi sementara baru tampil sekali.'
                                            : 'Orang tua/wali ini tidak bisa login sampai akunnya diaktifkan kembali. Data tidak dihapus.'}
                                    </p>
                                    <InputError message={errors.account} />
                                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant={
                                                confirm === 'reset'
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                            disabled={processing}
                                            onClick={
                                                confirm === 'reset'
                                                    ? onIssue
                                                    : undefined
                                            }
                                        >
                                            {processing
                                                ? 'Memproses…'
                                                : confirm === 'reset'
                                                  ? 'Atur ulang sandi'
                                                  : 'Nonaktifkan akun'}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setConfirm(null)}
                                        >
                                            Batal
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </>
            )}
        </div>
    );
}
