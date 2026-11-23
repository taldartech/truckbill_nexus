import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CreditCard,
    FileSearch,
    GitBranch,
    LayoutDashboard,
    LifeBuoy,
    Percent,
    Receipt,
    Settings,
    Ticket,
    UserCog,
    Users,
    Warehouse,
} from 'lucide-react';
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
import admin, {
    gstRates,
    subscriptionInvoices,
    tdsRules,
} from '@/routes/admin';
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

function isOwnerGlobalPath(pathname: string): boolean {
    if (!pathname.startsWith('/owner')) {
        return false;
    }
    // Company-specific Owner routes: /owner/companies/{uuid}/...
    return !/^\/owner\/companies\/(?!create$)[^/]+/.test(pathname);
}

export function AppSidebar() {
    const page = usePage<PageProps>();
    const { auth, workspace } = page.props;
    const pathname = (page.url || '').split('?')[0];
    const isCustomer = auth.user?.identity_type === 'customer';
    const isSuperAdmin = auth.user?.is_super_admin === true;
    const activeCompanyId = workspace?.active_company_id ?? null;
    const activeBranchId = workspace?.active_branch_id ?? null;
    const activeCompany = workspace?.companies.find(
        (c) => c.id === activeCompanyId,
    );
    const ownsAnyCompany =
        workspace?.companies.some((company) => company.is_owner) === true;
    const inBranchPanel =
        Boolean(activeCompanyId && activeBranchId) ||
        pathname.startsWith('/branch/');
    const inOwnerGlobal =
        isCustomer && ownsAnyCompany && isOwnerGlobalPath(pathname);
    const inOwnerCompanyDetail =
        isCustomer &&
        ownsAnyCompany &&
        /^\/owner\/companies\/(?!create$)[^/]+/.test(pathname);

    const ownerOverview: NavItem[] = ownsAnyCompany
        ? [
              {
                  title: 'Dashboard',
                  href: '/owner',
                  icon: LayoutDashboard,
              },
          ]
        : [];

    const ownerManagement: NavItem[] = ownsAnyCompany
        ? [
              {
                  title: 'Companies',
                  href: '/owner/companies',
                  icon: Building2,
              },
              {
                  title: 'Branches',
                  href: '/owner/branches',
                  icon: GitBranch,
              },
              {
                  title: 'Users',
                  href: '/owner/users',
                  icon: Users,
              },
              {
                  title: 'Subscriptions',
                  href: '/owner/subscriptions',
                  icon: CreditCard,
              },
              {
                  title: 'Audit',
                  href: '/owner/audit',
                  icon: FileSearch,
              },
          ]
        : [];

    const ownerCompanyAdmin: NavItem[] =
        inOwnerCompanyDetail && activeCompanyId && activeCompany?.is_owner
            ? [
                  {
                      title: 'Company home',
                      href: `/owner/companies/${activeCompanyId}`,
                      icon: Building2,
                  },
                  {
                      title: 'Company users',
                      href: `/owner/companies/${activeCompanyId}/users`,
                      icon: UserCog,
                  },
                  {
                      title: 'Settings',
                      href: `/owner/companies/${activeCompanyId}/settings`,
                      icon: Settings,
                  },
                  {
                      title: 'Company audit',
                      href: `/owner/companies/${activeCompanyId}/audit`,
                      icon: FileSearch,
                  },
              ]
            : [];

    const branchNav: NavItem[] =
        activeCompanyId && activeBranchId
            ? [
                  {
                      title: 'Branch Dashboard',
                      href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}`,
                      icon: LayoutDashboard,
                  },
                  {
                      title: 'Masters',
                      href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}/masters`,
                      icon: Warehouse,
                  },
                  {
                      title: 'Enquiries',
                      href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}/enquiries`,
                      icon: Ticket,
                  },
                  {
                      title: 'Branch Settings',
                      href: `/branch/companies/${activeCompanyId}/branches/${activeBranchId}/settings`,
                      icon: Settings,
                  },
                  ...(ownsAnyCompany
                      ? [
                            {
                                title: 'Owner Panel',
                                href: '/owner',
                                icon: Building2,
                            },
                        ]
                      : []),
              ]
            : [];

    const customerGroups =
        inBranchPanel && activeCompanyId && activeBranchId
            ? [
                  { label: 'Operations', items: branchNav },
                  ...(ownsAnyCompany
                      ? [{ label: 'Owner', items: ownerOverview }]
                      : []),
              ]
            : [
                  { label: 'Overview', items: ownerOverview },
                  { label: 'Management', items: ownerManagement },
                  ...(ownerCompanyAdmin.length > 0
                      ? [
                            {
                                label: 'This company',
                                items: ownerCompanyAdmin,
                            },
                        ]
                      : []),
                  ...(!ownsAnyCompany
                      ? [
                            {
                                label: 'Workspace',
                                items: [
                                    {
                                        title: 'Workspace',
                                        href: '/workspace',
                                        icon: LayoutDashboard,
                                    },
                                ] as NavItem[],
                            },
                        ]
                      : []),
              ];

    const internalGroups = [
        {
            label: 'Overview',
            items: [
                {
                    title: 'Dashboard',
                    href: dashboard(),
                    icon: LayoutDashboard,
                },
            ] as NavItem[],
        },
        {
            label: 'Tax',
            items: [
                {
                    title: 'GST rates',
                    href: gstRates.url(),
                    icon: Percent,
                },
                {
                    title: 'TDS rules',
                    href: tdsRules.url(),
                    icon: Receipt,
                },
            ] as NavItem[],
        },
        ...(isSuperAdmin
            ? [
                  {
                      label: 'Administration',
                      items: [
                          {
                              title: 'Companies',
                              href: admin.companies.index.url(),
                              icon: Building2,
                          },
                          {
                              title: 'Identities',
                              href: admin.identities.index.url(),
                              icon: Users,
                          },
                          {
                              title: 'Internal users',
                              href: admin.internalUsers.index.url(),
                              icon: UserCog,
                          },
                          {
                              title: 'CRM Leads',
                              href: '/admin/crm/leads',
                              icon: LifeBuoy,
                          },
                          {
                              title: 'Support tickets',
                              href: '/admin/crm/tickets',
                              icon: Ticket,
                          },
                          {
                              title: 'Subscription invoices',
                              href: subscriptionInvoices.url(),
                              icon: CreditCard,
                          },
                      ] as NavItem[],
                  },
              ]
            : []),
    ];

    const showCompanySwitcher = isCustomer && inBranchPanel;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={
                                    isCustomer
                                        ? ownsAnyCompany
                                            ? '/owner'
                                            : '/workspace'
                                        : dashboard()
                                }
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {showCompanySwitcher ? (
                    <div className="px-2 pt-2 group-data-[collapsible=icon]:hidden">
                        <CompanySwitcher />
                    </div>
                ) : null}
                {inOwnerGlobal || inOwnerCompanyDetail ? (
                    <div className="px-3 pt-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                        {inOwnerCompanyDetail && activeCompany
                            ? `Managing ${activeCompany.name}`
                            : 'All owned companies'}
                    </div>
                ) : null}
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    groups={isCustomer ? customerGroups : internalGroups}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
