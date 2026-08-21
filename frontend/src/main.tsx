import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { AppBootstrap } from '@/app/AppBootstrap';
import { createApplicationRuntime } from '@/app/runtime';

import './styles.css';

const rootElement = document.getElementById('root');

if (rootElement === null) {
    throw new Error(
        'EduCore frontend root element was not found.',
    );
}

const runtime = createApplicationRuntime();

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
            root.unmount();

            runtime.dispose();
        },
    );
}
