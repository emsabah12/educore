export function LoginPage() {
    return (
        <main className="min-h-screen bg-slate-950 text-slate-100">
            <section className="mx-auto flex min-h-screen max-w-lg items-center px-6 py-16">
                <div className="w-full space-y-5">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">
                        EduCore
                    </p>

                    <h1 className="text-3xl font-semibold tracking-tight">
                        Masuk ke EduCore
                    </h1>

                    <p className="leading-7 text-slate-300">
                        Halaman ini adalah boundary autentikasi publik EduCore.
                        Form autentikasi akan dihubungkan ke Browser Session
                        runtime pada langkah implementasi berikutnya.
                    </p>
                </div>
            </section>
        </main>
    );
}
