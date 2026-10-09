import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';

/**
 * Panel platform untuk Superadmin (PRD-000 §3). Isinya dibangun di tahap berikutnya.
 */
export default function PlatformHome() {
    return (
        <>
            <Head title="Panel platform" />

            <div className="p-4">
                <Heading
                    title="Panel platform"
                    description="Pengelolaan yayasan dan katalog hak akses akan tersedia di sini."
                />

                <div className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                    Belum ada menu. Fitur panel platform dibangun setelah RBAC
                    (F3) selesai.
                </div>
            </div>
        </>
    );
}

PlatformHome.layout = {
    breadcrumbs: [
        {
            title: 'Panel platform',
            href: '/platform',
        },
    ],
};
