import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftFromLine,
    Building2,
    ClipboardList,
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
    const inOwnerPanel =
        isCustomer &&
        (pathname.startsWith('/management') || pathname.startsWith('/owner'));
    const opsRoots = [
        '/operations',
        '/enquiries',
        '/parties',
        '/vehicles',
        '/bank-cash',
        '/stations',
        '/goods-types',
        '/truck-types',
        '/tyre-layouts',
        '/document-types',
        '/expense-categories',
        '/package-types',
        '/invoices',
        '/documents',
        '/settings/branch',
        '/lorry-receipts',
        '/trips',
        '/trip-invoices',
        '/payments',
        '/broker',
    ];
    const inOpsPath =
        opsRoots.some(
            (root) => pathname === root || pathname.startsWith(`${root}/`),
        ) || pathname.startsWith('/branch');
    const inBranchPanel =
        (Boolean(activeCompanyId && activeBranchId) && !inOwnerPanel) ||
        inOpsPath;
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
            title: 'Dashboard',
            href: '/operations',
            icon: LayoutDashboard,
        },
    ];

    const branchOperations: NavItem[] = [
        {
            title: 'Enquiries',
            href: '/enquiries',
            icon: Ticket,
        },
        {
            title: 'Unified LR',
            href: '/lorry-receipts',
            icon: Receipt,
        },
        {
            title: 'Partload Bilty',
            href: '/lorry-receipts/create?context=partload',
            icon: Receipt,
        },
        {
            title: 'OwnFleet Trips',
            href: '/trips',
            icon: Truck,
        },
        {
            title: 'Invoices',
            href: '/invoices',
            icon: Receipt,
        },
        {
            title: 'Customer Invoice Receipts',
            href: '/payments',
            icon: CreditCard,
        },
        {
            title: 'Broker Registers',
            href: '/broker/registers',
            icon: ClipboardList,
        },
        {
            title: 'Lorry Hires',
            href: '/broker/lorry-hires',
            icon: Truck,
        },
        {
            title: 'Delivery / Memo',
            href: '/broker/deliveries',
            icon: Truck,
        },
        {
            title: 'Linked Bilties',
            href: '/broker/linked-bilties',
            icon: Truck,
        },
        {
            title: 'Transporter Voucher Payments',
            href: '/broker/vouchers',
            icon: CreditCard,
        },
        {
            title: 'Daily Payments',
            href: '/broker/daily-payments',
            icon: CreditCard,
        },
        {
            title: 'Transporter Balances',
            href: '/broker/transporter-balances',
            icon: Landmark,
        },
    ];

    const branchMasters: NavItem[] = [
        {
            title: 'Party Master',
            href: '/parties',
            icon: Users,
        },
        {
            title: 'Vehicle Master',
            href: '/vehicles',
            icon: Truck,
        },
        {
            title: 'Bank/Cash Accounts',
            href: '/bank-cash',
            icon: Landmark,
        },
        {
            title: 'Station Master',
            href: '/stations',
            icon: MapPin,
        },
        {
            title: 'Goods Types',
            href: '/goods-types',
            icon: Package,
        },
        {
            title: 'Truck Types',
            href: '/truck-types',
            icon: Truck,
        },
        {
            title: 'Tyre Layouts',
            href: '/tyre-layouts',
            icon: Truck,
        },
        {
            title: 'Document Types',
            href: '/document-types',
            icon: FileSearch,
        },
        {
            title: 'Expense Categories',
            href: '/expense-categories',
            icon: Percent,
        },
        {
            title: 'Package Types',
            href: '/package-types',
            icon: Package,
        },
    ];

    const branchSettings: NavItem[] = [
        {
            title: 'Branch Settings',
            href: '/settings/branch',
            icon: Settings,
        },
    ];

    const customerGroups =
        inBranchPanel && activeCompanyId && activeBranchId
            ? [
                  { label: 'Overview', items: branchOverview },
                  { label: 'Operations', items: branchOperations },
                  { label: 'Masters', items: branchMasters },
                  { label: 'Settings', items: branchSettings },
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
                              title: 'CRM Dashboard',
                              href: '/admin/crm',
                              icon: LifeBuoy,
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

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="border-b border-sidebar-border/80 bg-gradient-to-r from-[#2FB36F]/10 to-[#2D8CFF]/10">
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
                {inOwnerPanel ? (
                    <div className="px-3 pt-2 text-xs font-medium text-muted-foreground group-data-[collapsible=icon]:hidden">
                        {inOwnerCompanyDetail && activeCompany
                            ? activeCompany.name
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
