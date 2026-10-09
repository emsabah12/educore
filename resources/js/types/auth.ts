/**
 * Data user yang dibagikan server lewat HandleInertiaRequests::sharedUser().
 * `name` berasal dari Person (PRD-000 §5).
 */
export type User = {
    id: string;
    name: string;
    email: string;
    username: string | null;
    avatar?: string;
    is_superadmin: boolean;
    two_factor_enabled?: boolean;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
