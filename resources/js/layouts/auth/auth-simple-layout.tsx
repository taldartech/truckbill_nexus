import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-[var(--brand-surface)] p-6 md:p-10">
            <div className="w-full max-w-sm rounded-2xl border border-border/80 bg-white p-6 shadow-sm md:p-8">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex h-14 w-14 items-center justify-center rounded-xl bg-white ring-1 ring-border/60">
                                <img
                                    src="/brand/truckbill-logo.svg"
                                    alt="TruckBill"
                                    className="size-12 object-contain"
                                />
                            </div>
                            <span className="text-lg font-semibold tracking-tight text-[var(--brand-ink)]">
                                TruckBill
                            </span>
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Transport. Billing. Simplified.
                            </span>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
