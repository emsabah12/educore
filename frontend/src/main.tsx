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

createRoot(rootElement).render(
    <StrictMode>
        <AppBootstrap runtime={runtime} />
    </StrictMode>,
);
