import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

export default function AuthCardLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    name?: string;
    title?: string;
    description?: string;
}>) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-[var(--brand-surface-muted)] p-6 md:p-10">
            <div className="flex w-full max-w-md flex-col gap-6">
                <Link
                    href={home()}
                    className="flex items-center gap-2 self-center font-medium"
                >
                    <div className="flex h-12 w-12 items-center justify-center rounded-md bg-white shadow-xs">
                        <img
                            src="/brand/truckbill-logo.svg"
                            alt="TruckBill"
                            className="size-12 object-contain"
                        />
                    </div>
                </Link>

                <div className="flex flex-col gap-6">
                    <Card className="overflow-hidden rounded-xl border-border shadow-xs">
                        <BrandAccentBar />
                        <CardHeader className="px-8 pt-6 pb-0 text-center">
                            <CardTitle className="text-xl tracking-tight">
                                {title}
                            </CardTitle>
                            <CardDescription>{description}</CardDescription>
                        </CardHeader>
                        <CardContent className="px-8 py-8">
                            {children}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}

function BrandAccentBar() {
    return (
        <div
            className="h-1 w-full"
            style={{ backgroundImage: 'var(--brand-gradient)' }}
            aria-hidden
        />
    );
}
