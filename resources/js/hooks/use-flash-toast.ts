import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

type FlashProps = {
    success?: string | null;
    error?: string | null;
    toast?: FlashToast | null;
};

/**
 * Surface success/error feedback from shared Inertia flash props and
 * Inertia::flash('toast') events. Also announce common HTTP failures.
 */
export function useFlashToast(): void {
    const page = usePage<{ flash?: FlashProps }>();
    const lastShown = useRef<string>('');

    useEffect(() => {
        const flash = page.props.flash;
        if (!flash) {
            return;
        }

        const key = JSON.stringify({
            s: flash.success ?? null,
            e: flash.error ?? null,
            t: flash.toast ?? null,
        });

        if (
            key === lastShown.current ||
            key === '{"s":null,"e":null,"t":null}'
        ) {
            return;
        }

        lastShown.current = key;

        if (flash.toast?.message) {
            toast[flash.toast.type](flash.toast.message);

            return;
        }

        if (flash.success) {
            toast.success(flash.success);
        }

        if (flash.error) {
            toast.error(flash.error);
        }
    }, [page.props.flash]);

    useEffect(() => {
        const offFlash = router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data?.message) {
                return;
            }

            toast[data.type](data.message);
        });

        const offHttp = router.on('httpException', (event) => {
            const detail = (event as CustomEvent).detail as {
                response?: Response;
                status?: number;
            };
            const status =
                detail.status ??
                (detail.response instanceof Response
                    ? detail.response.status
                    : undefined);

            if (status === 409) {
                toast.error(
                    'Workspace context changed in another tab. Reload the page and try again.',
                );
            } else if (status === 403) {
                toast.error(
                    'You do not have permission to perform this action.',
                );
            } else if (status === 401) {
                toast.error('Your session has expired. Please sign in again.');
            } else if (status === 419) {
                toast.error(
                    'Your session expired. Refresh the page and try again.',
                );
            } else if (status === 404) {
                toast.error('This record is no longer available.');
            } else if (status !== undefined && status >= 500) {
                toast.error('Something went wrong. Please try again.');
            }
        });

        const offNetwork = router.on('networkError', () => {
            toast.error('Connection error. Check your network and try again.');
        });

        return () => {
            offFlash();
            offHttp();
            offNetwork();
        };
    }, []);
}
