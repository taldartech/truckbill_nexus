import { Form, Head } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { enter } from '@/routes/workspace/branches';
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
            <PageShell
                width="form"
                title="Select a branch"
                description={`Company ${company.name}`}
                badges={<WorkspaceStatusBadge status={company.status} />}
            >
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Step 2 of 2 · Branch
                </p>
                <ul className="space-y-3" role="list">
                    {branches.map((branch) => (
                        <li key={branch.id}>
                            <Form
                                {...enter.form({
                                    company: company.id,
                                    branch: branch.id,
                                })}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="h-auto w-full justify-start gap-3 px-4 py-3 text-left"
                                    >
                                        <MapPin
                                            className="size-5 shrink-0 text-[#2D8CFF]"
                                            aria-hidden
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium">
                                                {branch.name}
                                            </span>
                                            <span className="mt-1 block">
                                                <WorkspaceStatusBadge
                                                    status={branch.status}
                                                />
                                            </span>
                                        </span>
                                    </Button>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            </PageShell>
        </>
    );
}
