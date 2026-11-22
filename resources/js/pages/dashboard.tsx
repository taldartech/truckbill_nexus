import { Head } from '@inertiajs/react';
import ShellEmptyState from '@/components/shell-empty-state';
import { dashboard } from '@/routes';

/**
 * Technical shell dashboard — Internal Team landing (Customer UI uses /workspace).
 */
export default function Dashboard() {
    return (
        <>
            <Head title="Workspace" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        Internal workspace
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Internal Team product surfaces are outside the Phase 2
                        Customer Owner/Branch panel package.
                    </p>
                </div>

                <ShellEmptyState
                    title="Platform administration"
                    description="Use the sidebar for GST rates, TDS rules, and (Super Admin) subscription invoice number approval. Broader Internal Team CRM remains out of the Phase 2 Customer Owner/Branch package."
                />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Workspace',
            href: dashboard(),
        },
    ],
};
