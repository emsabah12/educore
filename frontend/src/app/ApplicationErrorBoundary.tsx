import {
    Component,
    type ErrorInfo,
    type ReactNode,
} from 'react';

interface ApplicationErrorBoundaryProps {
    children: ReactNode;
}

interface ApplicationErrorBoundaryState {
    hasError: boolean;
}

export class ApplicationErrorBoundary extends Component<
    ApplicationErrorBoundaryProps,
    ApplicationErrorBoundaryState
> {
    public override state: ApplicationErrorBoundaryState = {
        hasError: false,
    };

    public static getDerivedStateFromError(): ApplicationErrorBoundaryState {
        return {
            hasError: true,
        };
    }

    public override componentDidCatch(
        _error: unknown,
        errorInfo: ErrorInfo,
    ): void {
        console.error(
            'EduCore application render failed.',
            {
                componentStack: errorInfo.componentStack,
            },
        );
    }

    public override render(): ReactNode {
        if (this.state.hasError) {
            return (
                <main className="min-h-screen bg-slate-950 text-slate-100">
                    <section className="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
                        <div className="space-y-4">
                            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-rose-300">
                                EduCore
                            </p>

                            <h1 className="text-3xl font-semibold tracking-tight">
                                Aplikasi tidak dapat dimuat
                            </h1>

                            <p className="max-w-xl leading-7 text-slate-300">
                                Terjadi kegagalan pada aplikasi.
                                Silakan muat ulang halaman.
                            </p>
                        </div>
                    </section>
                </main>
            );
        }

        return this.props.children;
    }
}
