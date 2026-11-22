import { Head, router } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import type { WorkspaceBranch } from '@/types/workspace';

type SelectBranchProps = {
    company: {
        id: string;
        name: string;
        status: string;
        is_owner: boolean;
    };
    branches: WorkspaceBranch[];
};

export default function SelectBranch({ company, branches }: SelectBranchProps) {
    return (
        <>
            <Head title="Select branch" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-6">
                <div className="space-y-1">
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Step 2 of 2 · Branch
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Select a branch
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Company {company.name}{' '}
                        <WorkspaceStatusBadge status={company.status} />
                    </p>
                </div>

                <ul className="space-y-3" role="list">
                    {branches.map((branch) => (
                        <li key={branch.id}>
                            <Button
                                type="button"
                                variant="outline"
                                className="h-auto w-full justify-start gap-3 px-4 py-3 text-left"
                                onClick={() =>
                                    router.post(
                                        `/workspace/companies/${company.id}/branches/${branch.id}/enter`,
                                    )
                                }
                            >
                                <MapPin className="size-5 shrink-0" aria-hidden />
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">
                                        {branch.name}
                                    </span>
                                    <span className="mt-1 block">
                                        <WorkspaceStatusBadge status={branch.status} />
                                    </span>
                                </span>
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
