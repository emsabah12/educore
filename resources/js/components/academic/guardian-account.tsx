import { KeyRound, UserCheck, UserPlus, UserX } from 'lucide-react';
import type { GuardianAccount } from '@/components/academic/student-actions';
import { ConfirmAction } from '@/components/confirm-action';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/*
 * Akun login orang tua/wali di detail siswa (PRD-002 §21.1, AC-7a). Username = nomor HP; kata sandi
 * sementara tampil sekali lewat dialog di GuardianList. Semua aturan tetap dicek server.
 */

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
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            Username memakai nomor HP orang tua/wali ini. Kata
                            sandi sementara tampil sekali dan wajib diganti saat
                            pertama masuk.
                        </p>
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    size="sm"
                                    className="w-full sm:w-auto"
                                >
                                    <UserPlus />
                                    Buat akun login
                                </Button>
                            }
                            title="Buat akun login orang tua/wali?"
                            description="Username = nomor HP. Kata sandi sementara tampil sekali sesudah akun dibuat; catat atau kirimkan ke orang tua/wali."
                            action={base}
                            confirmLabel="Buat akun"
                            destructive={false}
                            errorBag={ERROR_BAG}
                            onConfirm={onIssue}
                        />
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Isi nomor HP orang tua/wali ini (tombol Ubah) untuk
                        membuat akun login; nomor HP dipakai sebagai username.
                    </p>
                )
            ) : (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                    <ConfirmAction
                        trigger={
                            <Button type="button" size="sm" variant="outline">
                                <KeyRound />
                                Atur ulang sandi
                            </Button>
                        }
                        title="Atur ulang kata sandi?"
                        description="Kata sandi lama tidak berlaku lagi dan sesi login orang tua/wali ini diakhiri. Kata sandi sementara baru tampil sekali."
                        action={`${base}/atur-ulang`}
                        confirmLabel="Atur ulang sandi"
                        destructive={false}
                        errorBag={ERROR_BAG}
                        onConfirm={onIssue}
                    />
                    {account.status === 'ACTIVE' ? (
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                >
                                    <UserX />
                                    Nonaktifkan
                                </Button>
                            }
                            title="Nonaktifkan akun login?"
                            description="Orang tua/wali ini tidak bisa login sampai akunnya diaktifkan kembali. Data tidak dihapus."
                            action={`${base}/nonaktifkan`}
                            confirmLabel="Nonaktifkan akun"
                            errorBag={ERROR_BAG}
                        />
                    ) : (
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                >
                                    <UserCheck />
                                    Aktifkan
                                </Button>
                            }
                            title="Aktifkan kembali akun login?"
                            description="Orang tua/wali ini bisa login lagi."
                            action={`${base}/aktifkan`}
                            confirmLabel="Aktifkan"
                            destructive={false}
                            errorBag={ERROR_BAG}
                        />
                    )}
                </div>
            )}
        </div>
    );
}
