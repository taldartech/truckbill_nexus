import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { PageShell } from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateSmart } from '@/lib/dates';

type Cell = {
    id: string;
    code: string;
    name: string;
    grantable: boolean;
};

type MatrixGroup = {
    key: string;
    label: string;
    rows: {
        resource_key: string;
        resource_label: string;
        cells: Record<string, Cell>;
    }[];
};

type AuditEntry = {
    id: string;
    action: string;
    remark: string | null;
    before: { permissions?: string[] } | null;
    after: { permissions?: string[] } | null;
    actor_name: string;
    created_at: string | null;
};

type Props = {
    role: {
        id: string;
        domain: 'internal' | 'customer';
        name: string;
        slug: string;
        description: string | null;
        is_system: boolean;
        is_active: boolean;
    };
    matrix: {
        actions: { key: string; label: string }[];
        groups: MatrixGroup[];
        assigned_permission_ids: string[];
    };
    auditHistory: AuditEntry[];
    canManage: boolean;
    links: {
        index: string;
        update: string;
        sync_permissions: string;
        toggle: string;
    };
};

export default function AdminRoleEdit({
    role,
    matrix,
    auditHistory,
    canManage,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const initialSet = useMemo(
        () => new Set(matrix.assigned_permission_ids),
        [matrix.assigned_permission_ids],
    );
    const [selectedIds, setSelectedIds] = useState<string[]>(
        matrix.assigned_permission_ids,
    );
    const [search, setSearch] = useState('');
    const [selectedGroup, setSelectedGroup] = useState('all');
    const [remark, setRemark] = useState('');
    const [saving, setSaving] = useState(false);

    const permissionLookup = useMemo(() => {
        const map = new Map<string, Cell>();
        for (const group of matrix.groups) {
            for (const row of group.rows) {
                for (const cell of Object.values(row.cells)) {
                    map.set(cell.id, cell);
                }
            }
        }
        return map;
    }, [matrix.groups]);

    const filteredGroups = useMemo(() => {
        const q = search.trim().toLowerCase();
        return matrix.groups
            .filter((g) => selectedGroup === 'all' || g.key === selectedGroup)
            .map((group) => ({
                ...group,
                rows: group.rows.filter((row) => {
                    if (!q) {
                        return true;
                    }
                    if (
                        row.resource_label.toLowerCase().includes(q) ||
                        group.label.toLowerCase().includes(q)
                    ) {
                        return true;
                    }
                    return Object.values(row.cells).some(
                        (c) =>
                            c.code.toLowerCase().includes(q) ||
                            c.name.toLowerCase().includes(q),
                    );
                }),
            }))
            .filter((group) => group.rows.length > 0);
    }, [matrix.groups, search, selectedGroup]);

    const diff = useMemo(() => {
        const currentSet = new Set(selectedIds);
        const added = selectedIds
            .filter((id) => !initialSet.has(id))
            .map((id) => permissionLookup.get(id)?.code ?? id);
        const removed = matrix.assigned_permission_ids
            .filter((id) => !currentSet.has(id))
            .map((id) => permissionLookup.get(id)?.code ?? id);
        return { added, removed };
    }, [
        selectedIds,
        initialSet,
        matrix.assigned_permission_ids,
        permissionLookup,
    ]);

    const togglePermission = (cell: Cell) => {
        if (!canManage || !cell.grantable || !role.is_active) {
            return;
        }
        setSelectedIds((prev) =>
            prev.includes(cell.id)
                ? prev.filter((id) => id !== cell.id)
                : [...prev, cell.id],
        );
    };

    const selectGroup = (group: MatrixGroup) => {
        if (!canManage || !role.is_active) {
            return;
        }
        const addIds: string[] = [];
        for (const row of group.rows) {
            for (const cell of Object.values(row.cells)) {
                if (cell.grantable) {
                    addIds.push(cell.id);
                }
            }
        }
        setSelectedIds((prev) => Array.from(new Set([...prev, ...addIds])));
    };

    const clearGroup = (group: MatrixGroup) => {
        if (!canManage || !role.is_active) {
            return;
        }
        const removeSet = new Set<string>();
        for (const row of group.rows) {
            for (const cell of Object.values(row.cells)) {
                if (cell.grantable) {
                    removeSet.add(cell.id);
                }
            }
        }
        setSelectedIds((prev) => prev.filter((id) => !removeSet.has(id)));
    };

    const saveMatrix = (e: React.FormEvent) => {
        e.preventDefault();
        if (!canManage || !remark.trim() || saving) {
            return;
        }
        setSaving(true);
        router.put(
            links.sync_permissions,
            {
                permission_ids: selectedIds,
                remark,
            },
            {
                preserveScroll: true,
                onSuccess: () => setRemark(''),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <>
            <Head title={`Permissions · ${role.name}`} />
            <PageShell
                title={`${role.name} · Permissions`}
                description={`Domain: ${role.domain.toUpperCase()} · Slug: ${role.slug}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge
                            status={role.is_active ? 'Active' : 'Deactivated'}
                        />
                        <Badge variant="outline" className="capitalize">
                            {role.domain} role
                        </Badge>
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.index}>Back to Roles</Link>
                        </Button>
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['permissions', 'role', 'remark']}
                />

                {!role.is_system && canManage ? (
                    <Form
                        action={links.toggle}
                        method="post"
                        className="flex flex-wrap items-end gap-2 rounded-xl border border-border bg-card p-3"
                    >
                        <div className="flex-1 space-y-1">
                            <Label htmlFor="toggle_remark" required>
                                Status Change Remark
                            </Label>
                            <Input
                                id="toggle_remark"
                                name="remark"
                                required
                                placeholder="Reason for activating/deactivating custom role"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant={role.is_active ? 'destructive' : 'default'}
                            size="sm"
                        >
                            {role.is_active
                                ? 'Deactivate Role'
                                : 'Activate Role'}
                        </Button>
                    </Form>
                ) : null}

                {/* Compact Filter Toolbar */}
                <div className="grid gap-3 rounded-xl border border-border bg-card p-3 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="perm_search">Search Permissions</Label>
                        <Input
                            id="perm_search"
                            placeholder="Search resource, group, or code…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="perm_group">Filter Group</Label>
                        <select
                            id="perm_group"
                            value={selectedGroup}
                            onChange={(e) => setSelectedGroup(e.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="all">
                                All Functional Groups ({matrix.groups.length})
                            </option>
                            {matrix.groups.map((g) => (
                                <option key={g.key} value={g.key}>
                                    {g.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Permission Matrix */}
                <form onSubmit={saveMatrix} className="space-y-4">
                    {filteredGroups.map((group) => (
                        <section
                            key={group.key}
                            className="overflow-hidden rounded-xl border border-border bg-card shadow-xs"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-muted/30 px-4 py-2.5">
                                <h2 className="text-sm font-semibold">
                                    {group.label}
                                </h2>
                                {canManage && role.is_active ? (
                                    <div className="flex gap-1.5">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="h-7 px-2 text-xs"
                                            onClick={() => selectGroup(group)}
                                        >
                                            Select Group
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 px-2 text-xs"
                                            onClick={() => clearGroup(group)}
                                        >
                                            Clear Group
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-left text-xs">
                                    <thead className="border-b border-border text-muted-foreground">
                                        <tr>
                                            <th className="min-w-[180px] px-3 py-2 font-medium">
                                                Resource
                                            </th>
                                            {matrix.actions.map((action) => (
                                                <th
                                                    key={action.key}
                                                    className="px-2 py-2 text-center font-medium"
                                                >
                                                    {action.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {group.rows.map((row) => (
                                            <tr
                                                key={row.resource_key}
                                                className="border-b border-border/60 last:border-0"
                                            >
                                                <td className="px-3 py-2 font-medium">
                                                    {row.resource_label}
                                                </td>
                                                {matrix.actions.map(
                                                    (action) => {
                                                        const cell =
                                                            row.cells[
                                                                action.key
                                                            ];
                                                        if (!cell) {
                                                            return (
                                                                <td
                                                                    key={
                                                                        action.key
                                                                    }
                                                                    className="px-2 py-2 text-center text-muted-foreground/40"
                                                                >
                                                                    —
                                                                </td>
                                                            );
                                                        }
                                                        const checked =
                                                            selectedIds.includes(
                                                                cell.id,
                                                            );
                                                        return (
                                                            <td
                                                                key={action.key}
                                                                className="px-2 py-2 text-center"
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        checked
                                                                    }
                                                                    disabled={
                                                                        !canManage ||
                                                                        !cell.grantable ||
                                                                        !role.is_active
                                                                    }
                                                                    title={`${cell.name} (${cell.code})`}
                                                                    aria-label={`${row.resource_label} ${action.label}`}
                                                                    onChange={() =>
                                                                        togglePermission(
                                                                            cell,
                                                                        )
                                                                    }
                                                                    className="h-4 w-4 rounded border-input accent-primary"
                                                                />
                                                            </td>
                                                        );
                                                    },
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))}

                    {/* Previous vs New Diff Preview + Mandatory Edit Remark */}
                    {canManage && role.is_active ? (
                        <section className="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs">
                            <h3 className="text-sm font-semibold">
                                Review Changes Before Saving
                            </h3>
                            <div className="grid gap-3 text-xs sm:grid-cols-2">
                                <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3">
                                    <p className="font-semibold text-emerald-700 dark:text-emerald-400">
                                        Permissions Added ({diff.added.length})
                                    </p>
                                    {diff.added.length === 0 ? (
                                        <p className="mt-1 text-muted-foreground">
                                            None
                                        </p>
                                    ) : (
                                        <ul className="mt-1 space-y-0.5 font-mono">
                                            {diff.added.map((c) => (
                                                <li key={c}>+ {c}</li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                                    <p className="font-semibold text-destructive">
                                        Permissions Removed (
                                        {diff.removed.length})
                                    </p>
                                    {diff.removed.length === 0 ? (
                                        <p className="mt-1 text-muted-foreground">
                                            None
                                        </p>
                                    ) : (
                                        <ul className="mt-1 space-y-0.5 font-mono">
                                            {diff.removed.map((c) => (
                                                <li key={c}>- {c}</li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>

                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1 space-y-1">
                                    <Label htmlFor="sync_remark" required>
                                        Mandatory Edit Remark
                                    </Label>
                                    <Input
                                        id="sync_remark"
                                        required
                                        placeholder="State business reason for role permission update"
                                        value={remark}
                                        onChange={(e) =>
                                            setRemark(e.target.value)
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={saving || !remark.trim()}
                                >
                                    {saving ? 'Saving…' : 'Save Permissions'}
                                </Button>
                            </div>
                        </section>
                    ) : null}
                </form>

                {/* Role Audit History */}
                <section className="space-y-2 rounded-xl border border-border bg-card p-4 shadow-xs">
                    <h2 className="text-sm font-semibold">
                        Role Audit History
                    </h2>
                    {auditHistory.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            No changes logged for this role yet.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-xs">
                            {auditHistory.map((item) => (
                                <li
                                    key={item.id}
                                    className="rounded-lg border border-border/60 p-2.5"
                                >
                                    <div className="flex justify-between gap-2">
                                        <span>
                                            <strong>{item.actor_name}</strong> ·{' '}
                                            {item.action}
                                            {item.remark
                                                ? ` — "${item.remark}"`
                                                : ''}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDisplayDateSmart(
                                                item.created_at,
                                            )}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </PageShell>
        </>
    );
}
