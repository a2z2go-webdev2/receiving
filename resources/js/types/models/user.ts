import type { UserStatus } from '@/types/enums/user-status';

export type UserModel = {
    id: number;
    name: string;
    email: string;
    status: UserStatus;
    email_verified_at: string | null;
    roles: string[];
    permissions: string[];
    created_at: string;
    updated_at: string;
};
