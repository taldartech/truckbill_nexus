import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { enter } from '@/routes/workspace/companies';
import type { WorkspaceCompany } from '@/types/workspace';

type SelectCompanyProps = {
    companies: WorkspaceCompany[];
    mode: 'owned' | 'unified';
    heading: string;
    description: string;
    canCreateCompany?: boolean;
    links?: { createCompany?: string };
};

export default function SelectCompany({
    companies,
    mode,
    heading,
    description,
    canCreateCompany = false,
    links,
}: SelectCompanyProps) {
    return (
        <>
            <Head title={heading} />
            <PageShell
                width="form"
                title={heading}
                description={description}
                actions={
                    canCreateCompany && links?.createCompany ? (
                        <Button asChild>
                            <Link href={links.createCompany}>
                                <Plus className="size-4" />
                                Create company
                            </Link>
                        </Button>
                    ) : null
                }
            >
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {mode === 'owned' ? 'Owner Panel landing' : 'Workspace'}
                </p>
                <ul className="space-y-3" role="list">
                    {companies.map((company) => (
                        <li key={company.id}>
                            <Form {...enter.form(company.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="h-auto w-full justify-start gap-3 px-4 py-3 text-left"
                                    >
                                        <Building2
                                            className="size-5 shrink-0 text-[#2FB36F]"
                                            aria-hidden
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium">
                                                {company.name}
                                            </span>
                                            <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    {company.relationship}
                                                </span>
                                                <WorkspaceStatusBadge
                                                    status={company.status}
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
