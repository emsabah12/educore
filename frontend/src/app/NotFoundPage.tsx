export function NotFoundPage() {
    return (
        <main className="min-h-screen bg-slate-950 text-slate-100">
            <section className="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
                <div className="space-y-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">
                        404
                    </p>

                    <h1 className="text-3xl font-semibold tracking-tight">
                        Halaman tidak ditemukan
                    </h1>

                    <p className="max-w-xl leading-7 text-slate-300">
                        Alamat yang Anda buka tidak tersedia di EduCore.
                    </p>
                </div>
            </section>
        </main>
    );
}
