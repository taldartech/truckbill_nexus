import type { Auth } from '@/types/auth';
import type { WorkspaceShare } from '@/types/workspace';

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
            sidebarOpen: boolean;
            workspace: WorkspaceShare;
            [key: string]: unknown;
        };
    }
}
