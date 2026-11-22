import { Head, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import type { WorkspaceCompany } from '@/types/workspace';

type SelectCompanyProps = {
    companies: WorkspaceCompany[];
    mode: 'owned' | 'unified';
    heading: string;
    description: string;
};

export default function SelectCompany({
    companies,
    mode,
    heading,
    description,
}: SelectCompanyProps) {
    return (
        <>
            <Head title={heading} />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-6">
                <div className="space-y-1">
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        {mode === 'owned' ? 'Owner Panel landing' : 'Workspace'}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {heading}
                    </h1>
                    <p className="text-sm text-muted-foreground">{description}</p>
                </div>

                <ul className="space-y-3" role="list">
                    {companies.map((company) => (
                        <li key={company.id}>
                            <Button
                                type="button"
                                variant="outline"
                                className="h-auto w-full justify-start gap-3 px-4 py-3 text-left"
                                onClick={() =>
                                    router.post(
                                        `/workspace/companies/${company.id}/enter`,
                                    )
                                }
                            >
                                <Building2 className="size-5 shrink-0" aria-hidden />
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">
                                        {company.name}
                                    </span>
                                    <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <span>{company.relationship}</span>
                                        <WorkspaceStatusBadge status={company.status} />
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
