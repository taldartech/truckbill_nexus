import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import {
    RoleMatrixPanel,
    type RoleRow,
} from '@/components/admin/role-matrix-panel';
import { PageShell } from '@/components/page-shell';
import { formatDisplayDateSmart } from '@/lib/dates';

type AuditEntry = {
    id: string;
    action: string;
    subject_type: string;
    remark: string | null;
    actor_name: string;
    created_at: string | null;
};

type Props = {
    internalRoles: RoleRow[];
    customerRoles: RoleRow[];
    auditHistory: AuditEntry[];
    canManage: boolean;
    links: { store: string };
};

export default function AdminRolesIndex({
    internalRoles,
    customerRoles,
    auditHistory,
    canManage,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;
    const [activeSection, setActiveSection] = useState<'internal' | 'customer'>(
        'internal',
    );

    return (
        <>
            <Head title="Roles & Permissions" />
            <PageShell
                title="Roles & Permissions Management"
                description="Centralized permission configuration with strict domain isolation between Internal Team roles and Customer Company roles."
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} keys={['role', 'name']} />

                <div className="inline-flex w-fit rounded-lg border border-border bg-muted/40 p-1">
                    <button
                        type="button"
                        onClick={() => setActiveSection('internal')}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                            activeSection === 'internal'
                                ? 'bg-background shadow-xs'
                                : 'text-muted-foreground'
                        }`}
                    >
                        Internal Team Roles ({internalRoles.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveSection('customer')}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                            activeSection === 'customer'
                                ? 'bg-background shadow-xs'
                                : 'text-muted-foreground'
                        }`}
                    >
                        Customer Roles ({customerRoles.length})
                    </button>
                </div>

                {activeSection === 'internal' ? (
                    <RoleMatrixPanel
                        domain="internal"
                        title="Internal Team Roles"
                        description="Manage Internal Admin, Sales Executive, Support Executive, and custom Internal roles. Super Admin bypass remains protected."
                        roles={internalRoles}
                        canManage={canManage}
                        storeUrl={links.store}
                    />
                ) : (
                    <RoleMatrixPanel
                        domain="customer"
                        title="Customer Roles"
                        description="Manage centrally approved Customer roles (Admin, Operator, Viewer, No-Freight variants, and custom Customer roles). Assigned by Owners to branch users."
                        roles={customerRoles}
                        canManage={canManage}
                        storeUrl={links.store}
                    />
                )}

                <section className="space-y-2 rounded-xl border border-border bg-card p-4 shadow-xs">
                    <h2 className="text-sm font-semibold">
                        Recent RBAC Audit History
                    </h2>
                    {auditHistory.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            No role or permission changes logged yet.
                        </p>
                    ) : (
                        <ul className="space-y-1.5 text-xs">
                            {auditHistory.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 py-1.5 last:border-0"
                                >
                                    <span>
                                        <strong>{entry.actor_name}</strong> ·{' '}
                                        {entry.action} ({entry.subject_type})
                                        {entry.remark
                                            ? ` — "${entry.remark}"`
                                            : ''}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDisplayDateSmart(
                                            entry.created_at,
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </PageShell>
        </>
    );
}
