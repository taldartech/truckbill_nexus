import { Form, Link, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { enter } from '@/routes/workspace/companies';
import type { WorkspaceCompany, WorkspaceShare } from '@/types/workspace';

type PageProps = {
    workspace: WorkspaceShare;
};

export function CompanySwitcher() {
    const { workspace } = usePage<PageProps>().props;

    if (!workspace) {
        return null;
    }

    const canCreateCompany = workspace.can_create_company === true;
    const createCompanyUrl = workspace.create_company_url ?? null;
    const companies = workspace.companies;

    if (companies.length === 0 && !canCreateCompany) {
        return null;
    }

    const active =
        companies.find((c) => c.id === workspace.active_company_id) ?? null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className="h-auto w-full justify-between gap-2 px-3 py-2 text-left"
                    aria-label={
                        active
                            ? `Current company ${active.name}. Open company menu`
                            : 'Open company menu'
                    }
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <Building2 className="size-4 shrink-0" aria-hidden />
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {active?.name ??
                                    (canCreateCompany
                                        ? 'Create company'
                                        : 'Select company')}
                            </span>
                            {active ? (
                                <span className="block truncate text-xs text-muted-foreground">
                                    {active.relationship} · {active.status}
                                </span>
                            ) : canCreateCompany ? (
                                <span className="block truncate text-xs text-muted-foreground">
                                    No companies yet
                                </span>
                            ) : null}
                        </span>
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-72">
                <DropdownMenuLabel>Companies</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {companies.length === 0 ? (
                    <p className="px-2 py-1.5 text-sm text-muted-foreground">
                        No companies yet
                    </p>
                ) : (
                    <div className="flex flex-col gap-1 p-1">
                        {companies.map((company: WorkspaceCompany) => (
                            <Form
                                key={company.id}
                                {...enter.form(company.id)}
                                className="w-full"
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        disabled={processing}
                                        className="h-auto w-full justify-start px-2 py-2 text-left"
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-medium">
                                                {company.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {company.relationship} ·{' '}
                                                {company.status}
                                            </span>
                                        </span>
                                    </Button>
                                )}
                            </Form>
                        ))}
                    </div>
                )}
                {canCreateCompany && createCompanyUrl ? (
                    <>
                        <DropdownMenuSeparator />
                        <Button
                            variant="ghost"
                            className="h-auto w-full justify-start gap-2 px-2 py-2 text-left"
                            asChild
                        >
                            <Link
                                href={createCompanyUrl}
                                aria-label="Create new company from switcher"
                            >
                                <Plus className="size-4 shrink-0" aria-hidden />
                                <span>Create new company</span>
                            </Link>
                        </Button>
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
