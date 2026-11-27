import { ArrowLeft } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { workspace } from '@/routes/branch/accounting';

type Props = {
    /** Parent screen; defaults to the Accounting workspace. */
    href?: string;
    label?: string;
};

/**
 * Compact header Back action for Accounting screens (Rules §26) — replaces
 * per-page report navigation bars with a single route back to the gateway.
 */
export function AccountingBackAction({
    href = workspace.url(),
    label = 'Back to Accounting',
}: Props) {
    return (
        <AppIconAction icon={ArrowLeft} label={label} href={href} size="sm" />
    );
}
