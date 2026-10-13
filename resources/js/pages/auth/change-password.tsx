import { Form, Head, Link, router } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

type Props = {
    passwordRules: string;
};

/**
 * Wajib ganti kata sandi sementara dari admin sebelum membuka halaman lain (PRD-000 §4.7 F5a).
 */
export default function ChangePassword({ passwordRules }: Props) {
    return (
        <>
            <Head title="Ganti kata sandi" />

            <Form
                action="/akun/ganti-sandi"
                method="put"
                resetOnError={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">Kata sandi baru</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                required
                                placeholder="Kata sandi baru"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Konfirmasi kata sandi
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                required
                                placeholder="Ulangi kata sandi baru"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="change-password-button"
                        >
                            {processing && <Spinner />}
                            Simpan kata sandi baru
                        </Button>
                    </div>
                )}
            </Form>

            {/* Di luar form agar tombol ini tidak ikut mengirim form. */}
            <Button variant="outline" className="mt-4 w-full" asChild>
                <Link
                    href={logout()}
                    as="button"
                    onClick={() => router.flushAll()}
                >
                    Keluar
                </Link>
            </Button>
        </>
    );
}

ChangePassword.layout = {
    title: 'Ganti kata sandi',
    description:
        'Anda masuk dengan kata sandi sementara dari admin. Buat kata sandi baru yang hanya Anda ketahui untuk melanjutkan.',
};
