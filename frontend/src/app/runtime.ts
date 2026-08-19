import { createAppQueryClient } from '@/app/query-client';
import { createAppRouter } from '@/app/router';

export interface ApplicationRuntime {
    queryClient: ReturnType<typeof createAppQueryClient>;
    router: ReturnType<typeof createAppRouter>;
}

export function createApplicationRuntime(): ApplicationRuntime {
    return {
        queryClient: createAppQueryClient(),
        router: createAppRouter(),
    };
}
