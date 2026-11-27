import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
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

type Account = {
    id: string;
    name: string;
    account_kind: string;
    bank_name: string | null;
    account_number_masked: string | null;
    ifsc: string | null;
    account_holder_name: string | null;
    is_active: boolean;
    branch_id: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_edit: boolean; can_delete: boolean };
    account: Account;
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: {
        hub: string;
        dashboard: string;
        index: string;
        edit: string;
        destroy: string;
        documents: string;
    };
};

export default function BankCashShow({
    permissions,
    account,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);
    return (
        <>
            <Head title={account.name} />
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
                    title={account.name}
                    badges={
                        <WorkspaceStatusBadge
                            status={account.is_active ? 'Active' : 'Inactive'}
                        />
                    }
                    actions={
                        <MasterRecordActions
                            noun="account"
                            editHref={permissions.can_edit ? links.edit : null}
                            onOpenDocuments={
                                documentDrawer
                                    ? () => setDocsOpen(true)
                                    : undefined
                            }
                            documentsHref={links.documents}
                            destroy={
                                permissions.can_delete
                                    ? {
                                          url: links.destroy,
                                          description: `Soft-delete ${account.name}. Existing history is preserved.`,
                                      }
                                    : null
                            }
                        />
                    }
                >
                    <FbInfoGrid>
                        <FbInfoItem label="Kind" value={account.account_kind} />
                        <FbInfoItem label="Bank" value={account.bank_name} />
                        <FbInfoItem
                            label="Account (masked)"
                            value={account.account_number_masked}
                        />
                        <FbInfoItem label="IFSC" value={account.ifsc} />
                        <FbInfoItem
                            label="Holder"
                            value={account.account_holder_name}
                        />
                    </FbInfoGrid>
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
