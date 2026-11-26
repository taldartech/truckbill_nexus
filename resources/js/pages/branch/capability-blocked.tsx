import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CreditCard, ShieldAlert } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';

type CapabilityBlockedProps = {
    company?: { id: string; name: string; status: string } | null;
    branch?: { id: string; name: string; status: string } | null;
    capability?: string;
    message: string;
    isReadOnlyLifecycle?: boolean;
    isOwner: boolean;
    manageSubscriptionUrl: string | null;
    dashboardUrl: string;
};

export default function CapabilityBlocked({
    company,
    branch,
    message,
    isReadOnlyLifecycle = false,
    isOwner,
    manageSubscriptionUrl,
    dashboardUrl,
}: CapabilityBlockedProps) {
    const contextLabel =
        company?.name && branch?.name
            ? `${company.name} · ${branch.name}`
            : (company?.name ?? 'Active Workspace');

    return (
        <>
            <Head title="Feature Unavailable" />
            <PageShell
                title={
                    isReadOnlyLifecycle
                        ? 'Subscription Renewal Required'
                        : 'Module Not Included'
                }
                description={contextLabel}
            >
                <div className="mx-auto max-w-xl rounded-xl border border-amber-200 bg-amber-50/70 p-6 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30">
                    <div className="flex items-start gap-4">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
                            <ShieldAlert className="size-5" />
                        </div>
                        <div className="space-y-3">
                            <p className="text-sm leading-relaxed font-medium text-foreground">
                                {message}
                            </p>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                {isOwner
                                    ? 'Historical records remain preserved and accessible according to your company lifecycle policy. You can review or activate modules from your subscription management screen.'
                                    : 'Historical records remain safe. Please contact your Company Owner if this branch needs access to this workflow.'}
                            </p>
                            <div className="flex flex-wrap gap-2 pt-2">
                                {isOwner && manageSubscriptionUrl ? (
                                    <Button asChild variant="primary" size="sm">
                                        <Link href={manageSubscriptionUrl}>
                                            <CreditCard className="size-4" />
                                            Manage Subscription
                                        </Link>
                                    </Button>
                                ) : null}
                                <Button asChild variant="outline" size="sm">
                                    <Link href={dashboardUrl}>
                                        <ArrowLeft className="size-4" />
                                        Back to Operations
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </PageShell>
        </>
    );
}
