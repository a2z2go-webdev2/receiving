import '@inertiajs/core';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface PageProps {
        name: string;
        auth: Auth;
        sidebarOpen: boolean;
        flash: { status?: string; sessionExpired?: string | null };
        [key: string]: unknown;
    }
}
