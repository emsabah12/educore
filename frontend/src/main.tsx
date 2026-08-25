import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { AppBootstrap } from '@/app/AppBootstrap';
import { createApplicationRuntime } from '@/app/runtime';
import {
    createBrowserRuntimeObservabilityCoordinator,
} from '@/platform/observability/runtime';

import './styles.css';

interface FrontendEntrypointRoot {
    unmount():
        void;
}

interface FrontendEntrypointDisposable {
    dispose():
        void;
}

export function disposeFrontendEntrypointResources(
    root:
        FrontendEntrypointRoot,
    browserObservabilityCoordinator:
        FrontendEntrypointDisposable,
    runtime:
        FrontendEntrypointDisposable,
): void {
    /*
     * React owns Provider Effect cleanup and must therefore
     * unmount before the long-lived application runtimes are
     * released.
     *
     * Browser-global observability listeners are detached
     * next so an HMR replacement cannot leave the previous
     * module listening to the same browser realm.
     *
     * Aggregate Application runtime disposal happens last.
     */
    root.unmount();

    browserObservabilityCoordinator
        .dispose();

    runtime.dispose();
}

const rootElement = document.getElementById('root');

if (rootElement === null) {
    throw new Error(
        'EduCore frontend root element was not found.',
    );
}

const runtime = createApplicationRuntime();

const browserObservabilityCoordinator =
    createBrowserRuntimeObservabilityCoordinator(
        window,
        runtime.observability,
        {
            module:
                'application',
        },
    );

const root =
    createRoot(
        rootElement,
    );

root.render(
    <StrictMode>
        <AppBootstrap
            runtime={runtime}
        />
    </StrictMode>,
);

/*
 * Normal browser document teardown releases the complete
 * JavaScript realm, so no unload/pagehide handler is
 * required.
 *
 * Vite HMR is different: this module can be disposed while
 * the document itself remains alive. Explicitly unmount
 * React first so Provider Effects abort their work, then
 * release long-lived platform subscriptions.
 */
if (
    import.meta.hot
) {
    import.meta.hot.dispose(
        () => {
            disposeFrontendEntrypointResources(
                root,
                browserObservabilityCoordinator,
                runtime,
            );
        },
    );
}
