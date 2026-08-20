import { createAppQueryClient } from '@/app/query-client';
import { createAppRouter } from '@/app/router';
import {
    createBrowserApiClient,
    type BrowserApiClient,
} from '@/platform/api';
import {
    createBrowserAuthOperations,
    createBrowserAuthRuntime,
    type BrowserAuthRuntime,
} from '@/platform/auth';

export interface ApplicationRuntime {
    apiClient: BrowserApiClient;
    auth: BrowserAuthRuntime;
    queryClient: ReturnType<
        typeof createAppQueryClient
    >;
    router: ReturnType<
        typeof createAppRouter
    >;
}

export function createApplicationRuntime():
    ApplicationRuntime {
    const apiClient =
        createBrowserApiClient();

    const auth =
        createBrowserAuthRuntime(
            createBrowserAuthOperations(
                apiClient,
            ),
        );

    return {
        apiClient,
        auth,
        queryClient:
            createAppQueryClient(),
        router:
            createAppRouter(),
    };
}
