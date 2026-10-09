import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

/**
 * Akun aktif tetapi belum terdaftar di yayasan mana pun (PRD-000 §6).
 */
export default function Unregistered() {
    return (
        <>
            <Head title="Belum terdaftar" />

            <div className="space-y-6 text-center">
                <p className="text-sm text-muted-foreground">
                    Hubungi admin yayasan Anda agar akun ini didaftarkan.
                    Setelah didaftarkan, silakan masuk kembali.
                </p>

                <Button variant="outline" className="w-full" asChild>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                    >
                        Keluar
                    </Link>
                </Button>
            </div>
        </>
    );
}

Unregistered.layout = {
    title: 'Akun belum terdaftar',
    description: 'Akun Anda belum terhubung ke yayasan mana pun.',
};
