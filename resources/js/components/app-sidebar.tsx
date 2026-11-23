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
    const inOwnerPanel =
        isCustomer &&
        (pathname.startsWith('/management') || pathname.startsWith('/owner'));
    const inOwnerCompanyDetail =
        isCustomer &&
        ownsAnyCompany &&
        pathname.startsWith('/management/company');

    const ownerOverview: NavItem[] = ownsAnyCompany
        ? [
              {
                  title: 'Dashboard',
                  href: '/management',
                  icon: LayoutDashboard,
              },
          ]
        : [];

    const ownerManagement: NavItem[] = ownsAnyCompany
        ? [
              {
                  title: 'Companies',
                  href: '/management/companies',
                  icon: Building2,
              },
              {
                  title: 'Branches',
                  href: '/management/branches',
                  icon: GitBranch,
              },
              {
                  title: 'Users',
                  href: '/management/users',
                  icon: Users,
              },
              {
                  title: 'Subscriptions',
                  href: '/management/subscriptions',
                  icon: CreditCard,
              },
              {
                  title: 'Audit',
                  href: '/management/audit',
                  icon: FileSearch,
              },
          ]
        : [];

    const branchNav: NavItem[] =
        activeCompanyId && activeBranchId
            ? [
                  {
                      title: 'Branch Dashboard',
                      href: '/branch',
                      icon: LayoutDashboard,
                  },
                  {
                      title: 'Masters',
                      href: '/branch/masters',
                      icon: Warehouse,
                  },
                  {
                      title: 'Enquiries',
                      href: '/branch/enquiries',
                      icon: Ticket,
                  },
                  {
                      title: 'Branch Settings',
                      href: '/branch/settings',
                      icon: Settings,
                  },
              ]
            : [];

    const customerGroups =
        inBranchPanel && activeCompanyId && activeBranchId
            ? [
                  { label: 'Overview', items: branchNav.slice(0, 1) },
                  {
                      label: 'Operations',
                      items: branchNav.filter((item) =>
                          ['Enquiries'].includes(item.title),
                      ),
                  },
                  {
                      label: 'Masters',
                      items: branchNav.filter((item) =>
                          ['Masters'].includes(item.title),
                      ),
                  },
                  {
                      label: 'Branch',
                      items: branchNav.filter((item) =>
                          ['Branch Settings'].includes(item.title),
                      ),
                  },
              ]
            : [
                  { label: 'Overview', items: ownerOverview },
                  { label: 'Management', items: ownerManagement },
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

    // Company switcher: Branch Panel operational context only — never Owner Panel.
    const showCompanySwitcher = isCustomer && inBranchPanel && !inOwnerPanel;
    const showReturnToOwner =
        isCustomer &&
        inBranchPanel &&
        ownsAnyCompany &&
        Boolean(activeCompanyId && activeBranchId);

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
                                            ? '/management'
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
                {inOwnerPanel ? (
                    <div className="px-3 pt-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                        {inOwnerCompanyDetail && activeCompany
                            ? `Context: ${activeCompany.name}`
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
                {showReturnToOwner ? (
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                tooltip="Return to Management"
                            >
                                <Link href="/management" prefetch>
                                    <Building2 />
                                    <span>Return to Management</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                ) : null}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
