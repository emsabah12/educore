import {
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    RouterProvider,
} from 'react-router/dom';

import {
    ApplicationErrorBoundary,
} from '@/app/ApplicationErrorBoundary';
import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';
import {
    MembershipContextProvider,
} from '@/app/membership/MembershipContextProvider';
import type {
    ApplicationRuntime,
} from '@/app/runtime';

interface AppBootstrapProps {
    runtime:
        ApplicationRuntime;
}

export function AppBootstrap({
    runtime,
}: AppBootstrapProps) {
    return (
        <ApplicationErrorBoundary>
            <BrowserAuthProvider
                runtime={runtime.auth}
            >
                <MembershipContextProvider
                    runtime={
                        runtime.membership
                    }
                >
                    <QueryClientProvider
                        client={
                            runtime.queryClient
                        }
                    >
                        <RouterProvider
                            router={
                                runtime.router
                            }
                        />
                    </QueryClientProvider>
                </MembershipContextProvider>
            </BrowserAuthProvider>
        </ApplicationErrorBoundary>
    );
}
