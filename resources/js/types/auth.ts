/** The signed-in user, exactly as shared by HandleInertiaRequests. */
export type User = {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    timezone: string;
    locale: string;
    has_password: boolean;
    two_factor_enabled: boolean;
    is_super_admin: boolean;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
