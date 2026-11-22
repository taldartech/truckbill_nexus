import { Form, Head, Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { enter } from '@/routes/workspace/branches';
import type { WorkspaceBranch } from '@/types/workspace';

type OwnerBranchesProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canEnterBranch: boolean;
    branches: WorkspaceBranch[];
    deactivatedMessage: string | null;
};

export default function OwnerBranches({
    company,
    ownerPanelScope,
    canEnterBranch,
    branches,
    deactivatedMessage,
}: OwnerBranchesProps) {
    return (
        <>
            <Head title={`Branches · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Owner Panel · Branches
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

                {deactivatedMessage ? (
                    <ShellEmptyState
                        title="Branch Panel unavailable"
                        description={deactivatedMessage}
                    />
                ) : (
                    <ul className="space-y-3" role="list">
                        {branches.map((branch) => (
                            <li
                                key={branch.id}
                                className="flex flex-col gap-3 rounded-xl border border-border p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">{branch.name}</p>
                                    <WorkspaceStatusBadge status={branch.status} />
                                </div>
                                {canEnterBranch ? (
                                    <Form
                                        {...enter.form({
                                            company: company.id,
                                            branch: branch.id,
                                        })}
                                    >
                                        {({ processing }) => (
                                            <Button type="submit" disabled={processing}>
                                                Enter Branch Panel
                                            </Button>
                                        )}
                                    </Form>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}

                <Button asChild variant="outline" className="w-fit">
                    <Link href={`/owner/companies/${company.id}`}>
                        Back to Owner Dashboard
                    </Link>
                </Button>
            </div>
        </>
    );
}
