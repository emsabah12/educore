import { Form, router } from '@inertiajs/react';
import { KeyRound, Lock, UserCheck, UserPlus, UserX } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Bagian "Akses login" di detail pegawai (PRD-000 §4.7 F5a). Hanya dikirim server untuk
 * Admin Yayasan; setiap aksi tetap dicek ulang di server.
 */

export type LoginAccess = {
    membershipId: string;
    account: {
        username: string | null;
        email: string | null;
        login: string;
        active: boolean;
        mustChangePassword: boolean;
    } | null;
    /** Alasan akun ini tidak bisa diatur dari yayasan ini (Superadmin / yayasan lain). */
    restriction: string | null;
    isSelf: boolean;
};

type IssuedPassword = { login: string; password: string };

type AccessAction = 'create' | 'reset' | 'deactivate' | 'activate';

const ERROR_BAG = 'loginAccess';

export function LoginAccessSection({ access }: { access: LoginAccess }) {
    const [action, setAction] = useState<AccessAction | null>(null);
    const issued = useIssuedPassword();
    const base = `/akses/anggota/${access.membershipId}/akun`;
    const account = access.account;

    const toggle = (next: AccessAction) =>
        setAction((current) => (current === next ? null : next));
    const close = () => setAction(null);

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-medium">Akses login</h2>
                {account && (
                    <div className="flex flex-wrap gap-1.5">
                        <Badge variant={account.active ? 'default' : 'outline'}>
                            {account.active ? 'Aktif' : 'Nonaktif'}
                        </Badge>
                        {account.mustChangePassword && (
                            <Badge variant="secondary">
                                Belum ganti sandi sementara
                            </Badge>
                        )}
                    </div>
                )}
            </div>

            {account ? (
                <dl className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-4">
                    <Item label="Username" value={account.username} mono />
                    <Item label="Email" value={account.email} />
                </dl>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Pegawai ini belum punya akun login.
                </p>
            )}

            {access.restriction ? (
                <p className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Lock className="mt-0.5 size-4 shrink-0" />
                    {access.restriction}
                </p>
            ) : (
                <>
                    {/* Grid otomatis: tombol turun ke baris berikutnya di layar sempit. */}
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2">
                        {!account && (
                            <ToggleButton
                                active={action === 'create'}
                                onClick={() => toggle('create')}
                                icon={<UserPlus />}
                                label="Buat akun login"
                            />
                        )}
                        {account && (
                            <ToggleButton
                                active={action === 'reset'}
                                onClick={() => toggle('reset')}
                                icon={<KeyRound />}
                                label="Atur ulang sandi"
                            />
                        )}
                        {account && account.active && !access.isSelf && (
                            <ToggleButton
                                active={action === 'deactivate'}
                                onClick={() => toggle('deactivate')}
                                icon={<UserX />}
                                label="Nonaktifkan akun"
                                destructive
                            />
                        )}
                        {account && !account.active && (
                            <ToggleButton
                                active={action === 'activate'}
                                onClick={() => toggle('activate')}
                                icon={<UserCheck />}
                                label="Aktifkan akun"
                            />
                        )}
                    </div>

                    {action === 'create' && (
                        <AccessForm
                            title="Buat akun login"
                            action={base}
                            submitLabel="Buat akun"
                            onDone={close}
                            note="Sistem membuat kata sandi sementara yang tampil satu kali. Pemilik akun wajib menggantinya saat pertama masuk."
                        >
                            {(errors) => (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid content-start gap-1.5">
                                        <Label htmlFor="la-username">
                                            Username
                                            <span className="text-red-600 dark:text-red-400">
                                                {' '}
                                                *
                                            </span>
                                        </Label>
                                        <Input
                                            id="la-username"
                                            name="username"
                                            required
                                            minLength={3}
                                            maxLength={50}
                                            pattern="[a-z0-9._\-]{3,50}"
                                            autoCapitalize="none"
                                            autoComplete="off"
                                            spellCheck={false}
                                            placeholder="mis. ani.rahma"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Huruf kecil, angka, titik, garis
                                            bawah, atau tanda "-".
                                        </p>
                                        <InputError message={errors.username} />
                                    </div>
                                    <div className="grid content-start gap-1.5">
                                        <Label htmlFor="la-email">
                                            Email{' '}
                                            <span className="font-normal text-muted-foreground">
                                                (opsional)
                                            </span>
                                        </Label>
                                        <Input
                                            id="la-email"
                                            name="email"
                                            type="email"
                                            maxLength={255}
                                            autoComplete="off"
                                            placeholder="nama@contoh.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </div>
                            )}
                        </AccessForm>
                    )}

                    {action === 'reset' && account && (
                        <AccessForm
                            title="Atur ulang kata sandi"
                            action={`${base}/atur-ulang-sandi`}
                            submitLabel="Ya, atur ulang"
                            onDone={close}
                            note={
                                access.isSelf
                                    ? 'Ini akun Anda sendiri. Anda akan diminta mengganti kata sandi sementara di halaman berikutnya.'
                                    : `Kata sandi lama ${account.login} tidak berlaku lagi dan semua sesi loginnya diakhiri. Kata sandi sementara baru tampil satu kali.`
                            }
                        />
                    )}

                    {action === 'deactivate' && account && (
                        <AccessForm
                            title="Nonaktifkan akun login"
                            action={`${base}/nonaktifkan`}
                            submitLabel="Ya, nonaktifkan"
                            destructive
                            onDone={close}
                            note="Pemilik akun langsung keluar dan tidak bisa masuk lagi. Data pegawai dan riwayatnya tetap tersimpan."
                        />
                    )}

                    {action === 'activate' && account && (
                        <AccessForm
                            title="Aktifkan akun login"
                            action={`${base}/aktifkan`}
                            submitLabel="Ya, aktifkan"
                            onDone={close}
                            note="Pemilik akun bisa masuk lagi dengan kata sandi terakhirnya. Bila lupa, atur ulang sandinya."
                        />
                    )}
                </>
            )}

            <IssuedPasswordDialog
                issued={issued.value}
                onClose={issued.clear}
            />
        </section>
    );
}

/**
 * Kata sandi sementara datang lewat flash Inertia (sekali kirim, tidak tersimpan di riwayat
 * browser) dan hanya disimpan di memori komponen sampai dialog ditutup.
 */
function useIssuedPassword(): {
    value: IssuedPassword | null;
    clear: () => void;
} {
    const [value, setValue] = useState<IssuedPassword | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.issuedPassword as IssuedPassword | undefined;

            if (data?.login && data.password) {
                setValue(data);
            }
        });
    }, []);

    return { value, clear: () => setValue(null) };
}

function IssuedPasswordDialog({
    issued,
    onClose,
}: {
    issued: IssuedPassword | null;
    onClose: () => void;
}) {
    const [copy, setCopy] = useState<'idle' | 'done' | 'failed'>('idle');

    const copyToClipboard = async () => {
        if (!issued) {
            return;
        }

        try {
            // Clipboard hanya tersedia di HTTPS/localhost; selain itu tampilkan petunjuk salin manual.
            await navigator.clipboard.writeText(
                `Username: ${issued.login}\nKata sandi sementara: ${issued.password}`,
            );
            setCopy('done');
        } catch {
            setCopy('failed');
        }
    };

    return (
        <Dialog
            open={issued !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setCopy('idle');
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Kata sandi sementara</DialogTitle>
                    <DialogDescription>
                        Catat atau salin sekarang, lalu serahkan langsung kepada
                        pemilik akun. Kata sandi ini hanya tampil satu kali dan
                        wajib diganti saat pertama masuk.
                    </DialogDescription>
                </DialogHeader>

                {issued && (
                    <dl className="grid gap-3 rounded-lg border bg-muted/30 p-4">
                        <div className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                Username
                            </dt>
                            <dd className="font-mono break-all select-all">
                                {issued.login}
                            </dd>
                        </div>
                        <div className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                Kata sandi sementara
                            </dt>
                            <dd className="font-mono text-lg tracking-wider break-all select-all">
                                {issued.password}
                            </dd>
                        </div>
                    </dl>
                )}

                {copy === 'done' && (
                    <p className="text-sm text-muted-foreground">
                        Tersalin ke clipboard.
                    </p>
                )}
                {copy === 'failed' && (
                    <p className="text-sm text-muted-foreground">
                        Tidak bisa menyalin otomatis di sini. Pilih teksnya lalu
                        salin manual.
                    </p>
                )}

                <DialogFooter className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={copyToClipboard}
                    >
                        Salin
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            setCopy('idle');
                            onClose();
                        }}
                    >
                        Sudah saya catat
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AccessForm({
    title,
    action,
    submitLabel,
    destructive = false,
    note,
    onDone,
    children,
}: {
    title: string;
    action: string;
    submitLabel: string;
    destructive?: boolean;
    note: string;
    onDone: () => void;
    children?: (errors: Record<string, string | undefined>) => React.ReactNode;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={ERROR_BAG}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h3 className="text-base font-medium">{title}</h3>
                        <p className="text-sm text-muted-foreground">{note}</p>
                    </div>

                    <InputError message={errors.form} />

                    {children?.(errors)}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {processing ? 'Memproses…' : submitLabel}
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

function ToggleButton({
    active,
    onClick,
    icon,
    label,
    destructive = false,
}: {
    active: boolean;
    onClick: () => void;
    icon: React.ReactNode;
    label: string;
    destructive?: boolean;
}) {
    return (
        <Button
            type="button"
            variant={active ? 'default' : 'outline'}
            onClick={onClick}
            aria-expanded={active}
            className={destructive && !active ? 'text-destructive' : undefined}
        >
            {icon}
            {label}
        </Button>
    );
}

function Item({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={
                    'text-sm break-words ' + (mono && value ? 'font-mono' : '')
                }
            >
                {value ?? '–'}
            </dd>
        </div>
    );
}
