import type { ReactNode } from 'react';
import { AppCardHeader } from '@/components/app-card-header';

type Props = {
    title: string;
    description?: string | null;
    actions?: ReactNode;
};

/**
 * Compact page chrome: title + optional actions.
 * Do not pass company/branch/status here — use AppTopbar context.
 */
export function PageHeader({
    title,
    description = null,
    actions = null,
}: Props) {
    return (
        <AppCardHeader
            title={title}
            titleAs="h1"
            titleClassName="text-2xl font-semibold tracking-tight"
            meta={description}
            actions={actions}
            actionsClassName="gap-2"
        />
    );
}
