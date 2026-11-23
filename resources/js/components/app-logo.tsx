import { usePage } from '@inertiajs/react';

export default function AppLogo() {
    const { name } = usePage().props;
    const brand = String(name || 'TruckBill');

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-white ring-1 ring-[#2FB36F]/20">
                <img
                    src="/brand/truckbill-logo.svg"
                    alt={`${brand} logo`}
                    className="size-8 object-contain"
                />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm group-data-[collapsible=icon]:hidden">
                <span className="truncate leading-tight font-semibold text-foreground">
                    {brand}
                </span>
                <span className="truncate text-[10px] leading-tight text-muted-foreground">
                    Transport. Billing. Simplified.
                </span>
            </div>
        </>
    );
}
