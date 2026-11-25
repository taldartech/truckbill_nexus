import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    FbDetailCard,
    FbDetailPage,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { FileText, Pencil, Trash2 } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
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
    bookBalance?: {
        available_balance: string;
        statement_url: string;
    } | null;
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
    bookBalance = null,
    roleOptions,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);

    return (
        <>
            <Head title={party.name} />
            <FbDetailPage>
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FbDetailCard
                    title={party.name}
                    actions={
                        <>
                            <AppIconAction
                                icon={Pencil}
                                label="Edit party"
                                href={links.edit}
                                variant="warning"
                                size="sm"
                                visible={permissions.can_edit}
                            />
                            {documentDrawer ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-2"
                                    onClick={() => setDocsOpen(true)}
                                >
                                    <FileText className="size-4" />
                                    Documents
                                </Button>
                            ) : (
                                <AppIconAction
                                    icon={FileText}
                                    label="Documents"
                                    href={links.documents}
                                    variant="info"
                                    size="sm"
                                />
                            )}
                            {permissions.can_delete ? (
                                <AppIconAction
                                    icon={Trash2}
                                    label="Soft delete"
                                    variant="destructive"
                                    size="sm"
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
                    <p className="border-b border-[#e2e8f0] px-4 py-2 text-xs text-slate-500">
                        Party profile, roles, and documents.
                    </p>
                    <FbInfoGrid>
                        <FbInfoItem
                            label="Contact person"
                            value={party.contact_person}
                        />
                        <FbInfoItem
                            label="Contact number"
                            value={party.contact_number}
                        />
                        <FbInfoItem label="Email" value={party.email} />
                        <FbInfoItem label="GSTIN" value={party.gstin} />
                        <FbInfoItem label="PAN" value={party.pan} />
                        <FbInfoItem label="State" value={party.state} />
                        <FbInfoItem label="City" value={party.city} />
                        <FbInfoItem
                            label="Address"
                            value={party.address}
                            full
                        />
                        <FbInfoItem
                            label="Created"
                            value={formatDisplayDate(party.created_at)}
                        />
                        <FbInfoItem
                            label="Updated"
                            value={formatDisplayDate(party.updated_at)}
                        />
                    </FbInfoGrid>
                </FbDetailCard>

                {bookBalance ? (
                    <FbDetailCard title="Transporter Book Balance">
                        <FbInfoGrid>
                            <FbInfoItem
                                label="Available Advance Balance"
                                value={bookBalance.available_balance}
                            />
                            <FbInfoItem
                                label="Statement"
                                value={
                                    <a
                                        href={bookBalance.statement_url}
                                        className="text-sm font-medium text-[#0f766e] underline"
                                    >
                                        View Book Balance detail
                                    </a>
                                }
                            />
                        </FbInfoGrid>
                    </FbDetailCard>
                ) : null}

                <FbDetailCard title="Roles">
                    <div className="space-y-3 px-4 py-3">
                        <ul className="space-y-2">
                            {party.roles.map((role) => (
                                <li
                                    key={role.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-3"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {role.role}
                                        </p>
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
                                        <h3 className="font-medium">
                                            Assign role
                                        </h3>
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
                                            <InputError
                                                message={errors.remark}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Assign role
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : null}
                    </div>
                </FbDetailCard>
            </FbDetailPage>

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
