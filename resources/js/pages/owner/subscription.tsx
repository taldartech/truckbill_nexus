import { Head, Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type OwnerSubscriptionProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    subscription: {
        presentation: string;
        message: string;
        company_status: string;
        actions_allowed: boolean;
    };
};

export default function OwnerSubscription({
    company,
    ownerPanelScope,
    subscription,
}: OwnerSubscriptionProps) {
    return (
        <>
            <Head title={`Subscription · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Owner Panel · Subscription
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <WorkspaceStatusBadge status={company.status} />
                            <span className="text-muted-foreground">
                                Scope: {ownerPanelScope}
                            </span>
                        </div>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>

                <ShellEmptyState
                    title="Subscription data unavailable"
                    description={subscription.message}
                    action={
                        <Button asChild variant="outline">
                            <Link href={`/owner/companies/${company.id}`}>
                                Back to Owner Dashboard
                            </Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}
