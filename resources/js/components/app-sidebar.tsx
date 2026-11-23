import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftFromLine,
    Building2,
    CreditCard,
    FileSearch,
    GitBranch,
    Landmark,
    LayoutDashboard,
    LifeBuoy,
    MapPin,
    Package,
    Percent,
    Receipt,
    Settings,
    Ticket,
    Truck,
    UserCog,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
    const ownsAnyCompany =
        workspace?.companies.some((company) => company.is_owner) === true;
    const inBranchPanel =
        Boolean(activeCompanyId && activeBranchId) ||
        pathname === '/branch' ||
        pathname.startsWith('/branch/');
    const inOwnerPanel =
        isCustomer &&
        (pathname.startsWith('/management') || pathname.startsWith('/owner'));
    const inOwnerCompanyDetail =
        isCustomer &&
        ownsAnyCompany &&
        pathname.startsWith('/management/company');
    const activeCompany =
        workspace?.companies.find((c) => c.id === activeCompanyId) ?? null;

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

    const branchOverview: NavItem[] = [
        {
            title: 'Branch Dashboard',
            href: '/branch',
            icon: LayoutDashboard,
        },
    ];

    const branchOperations: NavItem[] = [
        {
            title: 'Enquiries',
            href: '/branch/enquiries',
            icon: Ticket,
        },
    ];

    const branchMasters: NavItem[] = [
        {
            title: 'Party Master',
            href: '/branch/parties',
            icon: Users,
        },
        {
            title: 'Vehicle Master',
            href: '/branch/vehicles',
            icon: Truck,
        },
        {
            title: 'Bank/Cash Accounts',
            href: '/branch/bank-cash',
            icon: Landmark,
        },
        {
            title: 'Station Master',
            href: '/branch/stations',
            icon: MapPin,
        },
        {
            title: 'Supporting Catalogues',
            href: '/branch/catalogues',
            icon: Package,
        },
    ];

    const branchSettings: NavItem[] = [
        {
            title: 'Branch Settings',
            href: '/branch/settings',
            icon: Settings,
        },
    ];

    const customerGroups =
        inBranchPanel && activeCompanyId && activeBranchId
            ? [
                  { label: 'Overview', items: branchOverview },
                  { label: 'Operations', items: branchOperations },
                  { label: 'Masters', items: branchMasters },
                  { label: 'Branch', items: branchSettings },
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

    const showReturnToOwnerPortal =
        isCustomer &&
        inBranchPanel &&
        ownsAnyCompany &&
        Boolean(activeCompanyId && activeBranchId);

    const showBranchContext =
        isCustomer &&
        inBranchPanel &&
        Boolean(activeCompanyId && activeBranchId) &&
        Boolean(workspace?.active_company_name || activeCompany?.name);

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
                {showBranchContext ? (
                    <div
                        className="space-y-0.5 px-3 pt-2 group-data-[collapsible=icon]:hidden"
                        data-testid="branch-context-readonly"
                    >
                        <p className="truncate text-sm font-medium text-foreground">
                            {workspace?.active_company_name ??
                                activeCompany?.name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {workspace?.active_branch_name ?? 'Branch'}
                        </p>
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
                {showReturnToOwnerPortal ? (
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                tooltip="Return to Owner Portal"
                            >
                                <Link href="/management" prefetch>
                                    <ArrowLeftFromLine />
                                    <span>Return to Owner Portal</span>
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
