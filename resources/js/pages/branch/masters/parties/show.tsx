import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { FileText, Pencil, Trash2 } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { formatDisplayDate } from '@/lib/dates';
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
            <PageShell
                title={party.name}
                description="Party profile, roles, and documents."
                actions={
                    <>
                        <AppIconAction
                            icon={Pencil}
                            label="Edit party"
                            href={links.edit}
                            variant="warning"
                            visible={permissions.can_edit}
                        />
                        <AppIconAction
                            icon={FileText}
                            label="Documents"
                            href={links.documents}
                            variant="info"
                            visible={!documentDrawer}
                        />
                        {permissions.can_delete ? (
                            <AppIconAction
                                icon={Trash2}
                                label="Soft delete"
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
                            />
                        ) : null}
                    </>
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {documentDrawer ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="gap-2"
                        onClick={() => setDocsOpen(true)}
                    >
                        <FileText className="size-4" />
                        Documents
                    </Button>
                ) : null}

                <FormSection title="Contact & tax" columns={2}>
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
                    <div>
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Created
                        </dt>
                        <dd className="mt-1 text-sm">
                            {formatDisplayDate(party.created_at)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Updated
                        </dt>
                        <dd className="mt-1 text-sm">
                            {formatDisplayDate(party.updated_at)}
                        </dd>
                    </div>
                </FormSection>

                <FormSection title="Roles" columns={1}>
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
                                            : `Removed ${formatDisplayDate(role.deactivated_at)}`}
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
                </FormSection>
            </PageShell>

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
