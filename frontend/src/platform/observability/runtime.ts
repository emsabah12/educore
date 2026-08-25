import {
    createObservabilityPort,
    type ObservabilityAdapter,
    type ObservabilityPort,
} from '@/platform/observability/port';

const noopObservabilityAdapter:
    ObservabilityAdapter = {
        capture(): void {
            /*
             * Foundation v1 has no configured telemetry
             * provider yet.
             *
             * Keep the default adapter intentionally silent.
             * Application callers still use the canonical
             * observability port so privacy filtering,
             * exception normalization, and provider failure
             * isolation remain centralized.
             */
        },
    };

export function createNoopObservabilityPort():
    ObservabilityPort {
    return createObservabilityPort(
        noopObservabilityAdapter,
    );
}

export interface BrowserRuntimeObservabilityCoordinator {
    dispose():
        void;
}

export function createBrowserRuntimeObservabilityCoordinator(
    target:
        Window,
    observability:
        ObservabilityPort,
    metadata:
        Readonly<
            Record<
                string,
                unknown
            >
        > = {},
): BrowserRuntimeObservabilityCoordinator {
    /*
     * Browser-global failures belong to the browser realm,
     * not to React component lifecycle.
     *
     * This primitive deliberately owns only listener
     * registration and disposal. Application composition
     * decides whether and when one coordinator is created.
     */
    const handleRuntimeError =
        (
            event:
                ErrorEvent,
        ): void => {
            observability.captureException(
                'browser_runtime_error',
                event.error,
                metadata,
            );
        };

    const handleUnhandledRejection =
        (
            event:
                PromiseRejectionEvent,
        ): void => {
            observability.captureException(
                'browser_unhandled_rejection',
                event.reason,
                metadata,
            );
        };

    target.addEventListener(
        'error',
        handleRuntimeError,
    );

    target.addEventListener(
        'unhandledrejection',
        handleUnhandledRejection,
    );

    let disposed =
        false;

    return {
        dispose(): void {
            if (
                disposed
            ) {
                return;
            }

            disposed =
                true;

            target.removeEventListener(
                'error',
                handleRuntimeError,
            );

            target.removeEventListener(
                'unhandledrejection',
                handleUnhandledRejection,
            );
        },
    };
}
