export function App() {
    return (
        <section
            className="space-y-5"
            aria-labelledby="frontend-foundation-title"
        >
            <div className="space-y-2">
                <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">
                    Application
                </p>

                <h1
                    className="text-3xl font-semibold tracking-tight sm:text-4xl"
                    id="frontend-foundation-title"
                >
                    Frontend Foundation
                </h1>
            </div>

            <p className="max-w-2xl text-base leading-7 text-slate-300">
                React, TypeScript, Vite, and Tailwind are ready.
            </p>
        </section>
    );
}
