export function RouteErrorPage() {
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
                </div>
            </section>
        </main>
    );
}
