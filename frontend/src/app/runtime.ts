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
import {
    createMembershipContextOperations,
    createMembershipContextRuntime,
    type MembershipContextRuntime,
} from '@/platform/membership';

export interface ApplicationRuntime {
    apiClient: BrowserApiClient;
    auth: BrowserAuthRuntime;
    membership:
        MembershipContextRuntime;
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

    const membership =
        createMembershipContextRuntime(
            createMembershipContextOperations(
                apiClient,
            ),
            auth,
        );

    return {
        apiClient,
        auth,
        membership,
        queryClient:
            createAppQueryClient(),
        router:
            createAppRouter(),
    };
}
