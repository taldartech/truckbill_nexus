import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { CompanySwitcher } from '@/components/company-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { gstRates, subscriptionInvoices, tdsRules } from '@/routes/admin';
import type { NavItem } from '@/types';
import type { WorkspaceShare } from '@/types/workspace';

type PageProps = {
    auth: {
        user: {
            identity_type?: string | null;
            is_super_admin?: boolean;
        } | null;
    };
    workspace: WorkspaceShare;
};

export function AppSidebar() {
    const { auth, workspace } = usePage<PageProps>().props;
    const isCustomer = auth.user?.identity_type === 'customer';
    const isSuperAdmin = auth.user?.is_super_admin === true;
    const activeCompanyId = workspace?.active_company_id ?? null;
    const activeBranchId = workspace?.active_branch_id ?? null;
    const activeCompany = workspace?.companies.find(
        (c) => c.id === activeCompanyId,
    );
    const isOwnerContext =
        activeCompany?.is_owner === true && activeBranchId === null;

    const mainNavItems: NavItem[] = isCustomer
        ? [
              {
                  title: 'Workspace',
                  href: '/workspace',
                  icon: LayoutGrid,
              },
              ...(isOwnerContext && activeCompanyId
                  ? [
                        {
                            title: 'Owner Dashboard',
                            href: `/owner/companies/${activeCompanyId}`,
                            icon: LayoutGrid,
                        },
                        {
                            title: 'Subscription',
                            href: `/owner/companies/${activeCompanyId}/subscription`,
                            icon: LayoutGrid,
                        },
                        {
                            title: 'Branches',
                            href: `/owner/companies/${activeCompanyId}/branches`,
                            icon: LayoutGrid,
                        },
                    ]
                  : []),
              ...(activeCompanyId && activeBranchId
                  ? [
                        {
                            title: 'Branch Dashboard',
                            href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}`,
                            icon: LayoutGrid,
                        },
                        {
                            title: 'Masters',
                            href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}/masters`,
                            icon: LayoutGrid,
                        },
                        {
                            title: 'Branch Settings',
                            href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}/settings`,
                            icon: LayoutGrid,
                        },
                    ]
                  : []),
          ]
        : [
              {
                  title: 'Dashboard',
                  href: dashboard(),
                  icon: LayoutGrid,
              },
              {
                  title: 'GST rates',
                  href: gstRates.url(),
                  icon: LayoutGrid,
              },
              {
                  title: 'TDS rules',
                  href: tdsRules.url(),
                  icon: LayoutGrid,
              },
              ...(isSuperAdmin
                  ? [
                        {
                            title: 'Subscription invoices',
                            href: subscriptionInvoices.url(),
                            icon: LayoutGrid,
                        },
                    ]
                  : []),
          ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={isCustomer ? '/workspace' : dashboard()}
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {isCustomer ? (
                    <div className="px-2 pt-2 group-data-[collapsible=icon]:hidden">
                        <CompanySwitcher />
                    </div>
                ) : null}
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
