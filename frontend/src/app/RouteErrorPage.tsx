import {
    useLocation,
} from 'react-router';

export function RouteErrorPage() {
    const location =
        useLocation();

    const recoveryDestination =
        `${location.pathname}${location.search}${location.hash}`;

    return (
        <main className="min-h-screen bg-slate-950 text-slate-100">
            <section className="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
                <div className="space-y-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-rose-300">
                        EduCore
                    </p>

                    <h1 className="text-3xl font-semibold tracking-tight">
                        Halaman tidak dapat dimuat
                    </h1>

                    <p className="max-w-xl leading-7 text-slate-300">
                        Terjadi kegagalan saat memproses halaman ini.
                        Silakan muat ulang aplikasi.
                    </p>

                    <a
                        href={
                            recoveryDestination
                        }
                        className="inline-flex min-h-11 items-center justify-center rounded-md border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-medium text-slate-100 transition hover:border-slate-500 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 focus:ring-offset-slate-950"
                    >
                        Muat ulang aplikasi
                    </a>
                </div>
            </section>
        </main>
    );
}
