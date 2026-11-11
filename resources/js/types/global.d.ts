import type { Auth } from '@/types/auth';
import type { CurrentFamily } from '@/types/family';

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
            currentFamily: CurrentFamily | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
