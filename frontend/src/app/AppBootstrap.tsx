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
    ObservabilityContextProvider,
} from '@/app/observability/ObservabilityContextProvider';
import {
    CapabilityContextProvider,
} from '@/app/authorization/CapabilityContextProvider';
import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';
import {
    MembershipContextProvider,
} from '@/app/membership/MembershipContextProvider';
import type {
    ApplicationRuntime,
} from '@/app/runtime';
import {
    WorkspaceContextProvider,
} from '@/app/workspace/WorkspaceContextProvider';

interface AppBootstrapProps {
    runtime:
        ApplicationRuntime;
}

export function AppBootstrap({
    runtime,
}: AppBootstrapProps) {
    return (
        <ApplicationErrorBoundary
            observability={
                runtime.observability
            }
        >
            <ObservabilityContextProvider
                observability={
                    runtime.observability
                }
            >
                <BrowserAuthProvider
                    runtime={runtime.auth}
                >
                    <MembershipContextProvider
                        runtime={
                            runtime.membership
                        }
                    >
                        <WorkspaceContextProvider
                            runtime={
                                runtime.workspace
                            }
                            activateLifecycle={
                                false
                            }
                        >
                            <CapabilityContextProvider
                                runtime={
                                    runtime.capabilities
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
                            </CapabilityContextProvider>
                        </WorkspaceContextProvider>
                    </MembershipContextProvider>
                </BrowserAuthProvider>
            </ObservabilityContextProvider>
        </ApplicationErrorBoundary>
    );
}
