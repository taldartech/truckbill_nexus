import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';
import type { WorkspaceShare } from '@/types/workspace';

type PageProps = {
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
            avatar?: string;
            identity_type?: string | null;
        } | null;
    };
    workspace: WorkspaceShare;
};

/**
 * Compact application top bar: sidebar trigger, portal/branch context once,
 * optional breadcrumbs, and user menu. Do not repeat context in page bodies.
 */
export function AppTopbar({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const page = usePage<PageProps>();
    const { auth, workspace } = page.props;
    const pathname = (page.url || '').split('?')[0];
    const getInitials = useInitials();
    const user = auth.user;

    const isCustomer = user?.identity_type === 'customer';
    const isInternal = user?.identity_type === 'internal';
    const inOwnerPanel =
        isCustomer &&
        (pathname.startsWith('/management') || pathname.startsWith('/owner'));
    const inBranchOps =
        isCustomer &&
        Boolean(workspace?.active_company_id && workspace?.active_branch_id) &&
        !inOwnerPanel;

    let contextLabel = '';
    if (inBranchOps) {
        const company = workspace?.active_company_name ?? 'Company';
        const branch = workspace?.active_branch_name ?? 'Branch';
        contextLabel = `${company} · ${branch}`;
    } else if (inOwnerPanel) {
        contextLabel = 'Owner Portal';
    } else if (isInternal) {
        contextLabel = 'Internal Portal';
    }

    return (
        <header
            className="flex h-12 shrink-0 items-center gap-3 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear md:px-4"
            data-testid="app-topbar"
        >
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {contextLabel ? (
                    <p
                        className="truncate text-sm font-medium text-foreground"
                        data-testid="app-topbar-context"
                    >
                        {contextLabel}
                    </p>
                ) : null}
                {breadcrumbs.length > 0 ? (
                    <div className="hidden min-w-0 md:block">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                ) : null}
            </div>

            {user ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex items-center gap-2 rounded-md px-1.5 py-1 text-sm outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Open user menu"
                        >
                            <Avatar className="size-8 overflow-hidden rounded-full">
                                <AvatarImage
                                    src={user.avatar}
                                    alt={user.name}
                                />
                                <AvatarFallback className="rounded-lg bg-muted text-xs font-medium">
                                    {getInitials(user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="hidden max-w-32 truncate text-sm font-medium sm:inline">
                                {user.name}
                            </span>
                            <ChevronsUpDown className="hidden size-4 opacity-60 sm:inline" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56">
                        <UserMenuContent user={user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : (
                <Link href="/login" className="text-sm font-medium">
                    Log in
                </Link>
            )}
        </header>
    );
}
