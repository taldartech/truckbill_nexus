import { Form, Head, usePage } from '@inertiajs/react';
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
import { MasterRecordActions } from '@/components/masters/master-record-actions';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    canManageOwnerStations: boolean;
    permissions: { can_edit: boolean; can_delete: boolean };
    station: {
        id: string;
        name: string;
        state: string | null;
        city: string | null;
        is_active: boolean;
        default_transporter_party_id: string | null;
    };
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: {
        hub: string;
        index: string;
        edit: string;
        destroy: string;
        set_default_transporter: string;
        documents: string;
    };
};
export default function StationShow({
    canManageOwnerStations,
    permissions,
    station,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);
    return (
        <>
            <Head title={station.name} />
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
                    title={station.name}
                    badges={
                        <WorkspaceStatusBadge
                            status={station.is_active ? 'Active' : 'Inactive'}
                        />
                    }
                    actions={
                        <MasterRecordActions
                            noun="station"
                            editHref={
                                canManageOwnerStations ? links.edit : null
                            }
                            onOpenDocuments={
                                documentDrawer
                                    ? () => setDocsOpen(true)
                                    : undefined
                            }
                            documentsHref={links.documents}
                            destroy={
                                canManageOwnerStations && permissions.can_delete
                                    ? {
                                          url: links.destroy,
                                          description:
                                              'Soft-delete this station from the shared directory.',
                                      }
                                    : null
                            }
                        />
                    }
                >
                    <FbInfoGrid>
                        <FbInfoItem
                            label="State"
                            value={station.state}
                            accent
                        />
                        <FbInfoItem label="City" value={station.city} />
                        <FbInfoItem
                            label="Default transporter"
                            value={station.default_transporter_party_id}
                        />
                    </FbInfoGrid>
                </FbDetailCard>

                {canManageOwnerStations ? (
                    <FbDetailCard title="Default transporter">
                        <Form
                            action={links.set_default_transporter}
                            method="post"
                            className="grid max-w-xl gap-3 px-4 py-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="transporter_party_id">
                                            Transporter party ID
                                        </Label>
                                        <Input
                                            id="transporter_party_id"
                                            name="transporter_party_id"
                                            defaultValue={
                                                station.default_transporter_party_id ??
                                                ''
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors.transporter_party_id
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="scope">Scope</Label>
                                        <select
                                            id="scope"
                                            name="scope"
                                            defaultValue="branch"
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="branch">
                                                This branch
                                            </option>
                                            <option value="company">
                                                Company default
                                            </option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="remark">Remark</Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required
                                            defaultValue="Set default transporter"
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        Save default transporter
                                    </Button>
                                </>
                            )}
                        </Form>
                    </FbDetailCard>
                ) : null}
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
