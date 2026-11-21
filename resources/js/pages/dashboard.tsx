import { Head } from '@inertiajs/react';
import ShellEmptyState from '@/components/shell-empty-state';
import { dashboard } from '@/routes';

export default function Dashboard() {
    return (
        <>
            <Head title="Workspace" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        Workspace
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Technical application shell. Product modules are not
                        available in Phase 1.
                    </p>
                </div>

                <ShellEmptyState
                    title="No product modules yet"
                    description="Business workflows are intentionally unavailable until Phase 1 engineering foundation certification is complete. This screen is a technical shell only."
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
