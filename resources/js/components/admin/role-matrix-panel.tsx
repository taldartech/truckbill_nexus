import { Link, useForm } from '@inertiajs/react';
import { Shield, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

export type RoleRow = {
    id: string;
    domain: 'internal' | 'customer';
    name: string;
    slug: string;
    description: string | null;
    is_system: boolean;
    is_default: boolean;
    is_active: boolean;
    assigned_user_count: number;
    permission_count: number;
    edit_url: string;
    toggle_url: string;
};

type Props = {
    domain: 'internal' | 'customer';
    title: string;
    description: string;
    roles: RoleRow[];
    canManage: boolean;
    storeUrl: string;
};

export function RoleMatrixPanel({
    domain,
    title,
    description,
    roles,
    canManage,
    storeUrl,
}: Props) {
    const createForm = useForm({
        domain,
        name: '',
        description: '',
    });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => createForm.reset('name', 'description'),
        });
    };

    return (
        <section className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-xs">
            <div className="flex flex-col gap-2 border-b border-border pb-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-base font-semibold">
                        <Shield className="h-4 w-4 text-primary" />
                        {title}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
                <Badge variant="outline">
                    {roles.length} {roles.length === 1 ? 'role' : 'roles'}
                </Badge>
            </div>

            {canManage ? (
                <form
                    onSubmit={submitCreate}
                    className="grid gap-3 rounded-lg border border-border/80 bg-muted/20 p-3 sm:grid-cols-12 sm:items-end"
                >
                    <div className="space-y-1 sm:col-span-4">
                        <Label htmlFor={`role_name_${domain}`} required>
                            New{' '}
                            {domain === 'internal' ? 'Internal' : 'Customer'}{' '}
                            Role Name
                        </Label>
                        <Input
                            id={`role_name_${domain}`}
                            required
                            placeholder={
                                domain === 'internal'
                                    ? 'e.g. Finance Reviewer'
                                    : 'e.g. Branch Dispatcher'
                            }
                            value={createForm.data.name}
                            onChange={(e) =>
                                createForm.setData('name', e.target.value)
                            }
                        />
                    </div>
                    <div className="space-y-1 sm:col-span-6">
                        <Label htmlFor={`role_desc_${domain}`}>
                            Description
                        </Label>
                        <Input
                            id={`role_desc_${domain}`}
                            placeholder="Optional role scope summary"
                            value={createForm.data.description}
                            onChange={(e) =>
                                createForm.setData(
                                    'description',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            className="w-full"
                            disabled={createForm.processing}
                        >
                            Create Role
                        </Button>
                    </div>
                </form>
            ) : null}

            <div className="overflow-x-auto">
                <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-border text-xs text-muted-foreground">
                        <tr>
                            <th className="py-2 pr-3 font-medium">Role</th>
                            <th className="px-3 py-2 font-medium">Type</th>
                            <th className="px-3 py-2 text-right font-medium">
                                Assigned Users
                            </th>
                            <th className="px-3 py-2 text-right font-medium">
                                Permissions
                            </th>
                            <th className="px-3 py-2 font-medium">Status</th>
                            <th className="py-2 pl-3 text-right font-medium">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {roles.map((role) => (
                            <tr
                                key={role.id}
                                className="border-b border-border/70 last:border-0"
                            >
                                <td className="py-2.5 pr-3">
                                    <p className="font-medium">{role.name}</p>
                                    <p className="font-mono text-[11px] text-muted-foreground">
                                        {role.slug}
                                    </p>
                                </td>
                                <td className="px-3 py-2.5 text-xs">
                                    {role.is_system ? (
                                        <Badge variant="secondary">
                                            System
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">Custom</Badge>
                                    )}
                                </td>
                                <td className="px-3 py-2.5 text-right text-xs">
                                    <span className="inline-flex items-center gap-1">
                                        <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                        {role.assigned_user_count}
                                    </span>
                                </td>
                                <td className="px-3 py-2.5 text-right font-medium">
                                    {role.permission_count}
                                </td>
                                <td className="px-3 py-2.5">
                                    <WorkspaceStatusBadge
                                        status={
                                            role.is_active
                                                ? 'Active'
                                                : 'Deactivated'
                                        }
                                    />
                                </td>
                                <td className="py-2.5 pl-3 text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={role.edit_url}>
                                            {canManage
                                                ? 'Configure Permissions'
                                                : 'View Matrix'}
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
