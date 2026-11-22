import { Form, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown } from 'lucide-react';
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

    if (!workspace || workspace.companies.length === 0) {
        return null;
    }

    const active =
        workspace.companies.find((c) => c.id === workspace.active_company_id) ??
        null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className="h-auto w-full justify-between gap-2 px-3 py-2 text-left"
                    aria-label="Switch company"
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <Building2 className="size-4 shrink-0" aria-hidden />
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {active?.name ?? 'Select company'}
                            </span>
                            {active ? (
                                <span className="block truncate text-xs text-muted-foreground">
                                    {active.relationship} · {active.status}
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
                <div className="flex flex-col gap-1 p-1">
                    {workspace.companies.map((company: WorkspaceCompany) => (
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
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
