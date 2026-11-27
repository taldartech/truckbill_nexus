import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

type FlashProps = {
    success?: string | null;
    error?: string | null;
    toast?: FlashToast | null;
};

type PageWithFlash = {
    props?: {
        flash?: FlashProps;
    };
};

/**
 * Surface success/error feedback from shared Inertia flash props and
 * Inertia::flash('toast') events. Also announce common HTTP failures.
 *
 * Must not call usePage() — Toaster is mounted via withApp() outside the
 * Inertia page context.
 */
export function useFlashToast(): void {
    const lastShown = useRef<string>('');

    useEffect(() => {
        const showSharedFlash = (flash: FlashProps | undefined): void => {
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
        };

        const pageFromRouter = (router as unknown as { page?: PageWithFlash })
            .page;
        showSharedFlash(pageFromRouter?.props?.flash);

        const offSuccess = router.on('success', (event) => {
            const page = (event as CustomEvent<{ page?: PageWithFlash }>).detail
                ?.page;
            showSharedFlash(page?.props?.flash);
        });

        const offFlash = router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                | FlashProps
                | undefined;

            if (flash?.toast?.message) {
                toast[flash.toast.type](flash.toast.message);

                return;
            }

            showSharedFlash(flash);
        });

        const offHttp = router.on('httpException', (event) => {
            const detail = (event as CustomEvent).detail as {
                response?: { status?: number };
            };
            const message = httpFailureMessage(detail.response?.status);

            if (message !== null) {
                event.preventDefault();
                toast.error(message);
            }
        });

        const offValidation = router.on('error', (event) => {
            const errors = (event as CustomEvent).detail?.errors as
                | Record<string, string>
                | undefined;

            if (errors && Object.keys(errors).length > 0) {
                toast.error(
                    'The form was not saved. Review the highlighted fields.',
                    { id: 'form-validation-failed' },
                );
            }
        });

        const offNetwork = router.on('networkError', () => {
            toast.error('Connection error. Check your network and try again.');
        });

        return () => {
            offSuccess();
            offFlash();
            offHttp();
            offValidation();
            offNetwork();
        };
    }, []);
}

function httpFailureMessage(status: number | undefined): string | null {
    if (status === 409) {
        return 'Workspace context changed in another tab. Reload the page and try again.';
    }
    if (status === 403) {
        return 'You do not have permission to perform this action.';
    }
    if (status === 401) {
        return 'Your session has expired. Please sign in again.';
    }
    if (status === 419) {
        return 'Your session expired. Refresh the page and try again.';
    }
    if (status === 404) {
        return 'This record is no longer available.';
    }
    if (status !== undefined && status >= 500) {
        return 'Something went wrong. Please try again.';
    }

    return null;
}
