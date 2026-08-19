import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router/dom';

import { ApplicationErrorBoundary } from '@/app/ApplicationErrorBoundary';
import type { ApplicationRuntime } from '@/app/runtime';

interface AppBootstrapProps {
    runtime: ApplicationRuntime;
}

export function AppBootstrap({
    runtime,
}: AppBootstrapProps) {
    return (
        <ApplicationErrorBoundary>
            <QueryClientProvider client={runtime.queryClient}>
                <RouterProvider router={runtime.router} />
            </QueryClientProvider>
        </ApplicationErrorBoundary>
    );
}
