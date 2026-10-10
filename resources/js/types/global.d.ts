import type { Auth } from '@/types/auth';
import type { WorkContext } from '@/types/context';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            context: WorkContext | null;
            /** Petunjuk menu saja; setiap aksi tetap dicek di server. */
            permissions: string[];
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
