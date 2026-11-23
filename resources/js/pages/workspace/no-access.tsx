import { Form, Head, Link } from '@inertiajs/react';
import { Building2, LogOut, Plus } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import { logout } from '@/routes';

type NoAccessProps = {
    userName: string;
    supportHint: string;
    canCreateCompany: boolean;
    links: {
        createCompany: string | null;
    };
};

export default function WorkspaceNoAccess({
    userName,
    supportHint,
    canCreateCompany,
    links,
}: NoAccessProps) {
    const showCreate = canCreateCompany && Boolean(links.createCompany);

    return (
        <>
            <Head title="No workspace access" />
            <PageShell
                width="form"
                title="No workspace access"
                description={`Signed in as ${userName}`}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <MetricCard
                        label="Status"
                        value="No company"
                        hint={supportHint}
                        tone="amber"
                        icon={Building2}
                    />
                </div>

                <ShellEmptyState
                    title="No accessible company"
                    description={
                        showCreate
                            ? 'You do not have access to a company yet. Create your company to continue as Owner of that workspace.'
                            : supportHint
                    }
                />

                <div className="flex flex-wrap gap-2">
                    {showCreate && links.createCompany ? (
                        <Button asChild>
                            <Link href={links.createCompany}>
                                <Plus className="size-4" />
                                Create company
                            </Link>
                        </Button>
                    ) : null}
                    <Form {...logout.form()}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                <LogOut className="size-4" />
                                Sign out
                            </Button>
                        )}
                    </Form>
                </div>
            </PageShell>
        </>
    );
}
