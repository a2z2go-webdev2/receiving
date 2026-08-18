export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    status: 'active' | 'inactive' | 'banned';
    email_verified_at: string | null;
    roles: string[];
    permissions: string[];
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
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
