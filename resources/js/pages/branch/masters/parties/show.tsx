import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Role = {
    id: string;
    role: string;
    attributes: Record<string, unknown>;
    is_active: boolean;
    deactivated_at: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: {
        can_edit: boolean;
        can_delete: boolean;
        can_create: boolean;
    };
    party: {
        id: string;
        name: string;
        contact_person: string | null;
        contact_number: string | null;
        email: string | null;
        gstin: string | null;
        pan: string | null;
        state: string | null;
        city: string | null;
        address: string | null;
        scope: string;
        roles: Role[];
        created_at: string | null;
        updated_at: string | null;
    };
    roleOptions: { value: string; label: string }[];
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: {
        hub: string;
        dashboard: string;
        index: string;
        edit: string;
        assign_role: string;
        destroy: string;
        documents: string;
    };
};

export default function PartyShow({
    company,
    branch,
    permissions,
    party,
    roleOptions,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);

    return (
        <>
            <Head title={party.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader title={party.name} />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {permissions.can_edit ? (
                        <Button asChild variant="outline">
                            <Link href={links.edit}>Edit</Link>
                        </Button>
                    ) : null}
                    {documentDrawer ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDocsOpen(true)}
                        >
                            Documents
                        </Button>
                    ) : (
                        <Button asChild variant="outline">
                            <Link href={links.documents}>Documents</Link>
                        </Button>
                    )}
                    {permissions.can_delete ? (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                const remark = window.prompt(
                                    'Remark for soft deletion',
                                );
                                if (!remark) return;
                                router.delete(links.destroy, {
                                    data: { remark },
                                });
                            }}
                        >
                            Soft delete
                        </Button>
                    ) : null}
                </div>

                <dl className="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
                    {[
                        ['Contact person', party.contact_person],
                        ['Contact number', party.contact_number],
                        ['Email', party.email],
                        ['GSTIN', party.gstin],
                        ['PAN', party.pan],
                        ['State', party.state],
                        ['City', party.city],
                        ['Address', party.address],
                    ].map(([label, value]) => (
                        <div key={String(label)}>
                            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                {label}
                            </dt>
                            <dd className="mt-1 text-sm">{value || '—'}</dd>
                        </div>
                    ))}
                </dl>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">Roles</h2>
                    <ul className="space-y-2">
                        {party.roles.map((role) => (
                            <li
                                key={role.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-3"
                            >
                                <div>
                                    <p className="font-medium">{role.role}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {role.is_active
                                            ? 'Active'
                                            : `Removed ${role.deactivated_at}`}
                                    </p>
                                </div>
                                {role.is_active && permissions.can_edit ? (
                                    <Form
                                        action={`/parties/${party.id}/roles/${role.id}/remove`}
                                        method="post"
                                        className="flex items-end gap-2"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <Input
                                                    name="remark"
                                                    placeholder="Remark"
                                                    required
                                                    className="w-48"
                                                />
                                                <InputError
                                                    message={errors.remark}
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Remove
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                ) : null}
                            </li>
                        ))}
                    </ul>

                    {permissions.can_edit ? (
                        <Form
                            action={links.assign_role}
                            method="post"
                            className="grid max-w-xl gap-3 rounded-xl border border-border p-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <h3 className="font-medium">Assign role</h3>
                                    <div className="space-y-2">
                                        <Label htmlFor="role">Role</Label>
                                        <select
                                            id="role"
                                            name="role"
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            required
                                        >
                                            {roleOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.role} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="assign-remark">
                                            Remark
                                        </Label>
                                        <Input
                                            id="assign-remark"
                                            name="remark"
                                            required
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        Assign role
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </section>
            </div>

            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    {...documentDrawer}
                />
            ) : null}
        </>
    );
}
