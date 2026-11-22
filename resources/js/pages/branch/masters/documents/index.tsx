import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    DocumentDrawer,
    type DocumentDrawerAttachment,
} from '@/components/masters/document-drawer';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: {
        can_create: boolean;
        can_edit: boolean;
        can_delete: boolean;
    };
    parent: { type: string; id: string };
    storage: { file_bytes: number; attachment_count: number };
    max_upload_bytes: number;
    attachments: DocumentDrawerAttachment[];
    links: { hub: string; upload: string; manager: string };
};

export default function DocumentsIndex({
    company,
    branch,
    permissions,
    parent,
    storage,
    max_upload_bytes,
    attachments,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [drawerOpen, setDrawerOpen] = useState(true);

    return (
        <>
            <Head title={`Documents · ${parent.type}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Documents"
                    title="Document management"
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Documents' },
                    ]}
                />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <p className="text-sm text-muted-foreground">
                    Parent: {parent.type.replaceAll('_', ' ')} / {parent.id}
                </p>
                <Button type="button" onClick={() => setDrawerOpen(true)}>
                    Open document drawer
                </Button>
                <ul className="space-y-2 md:hidden">
                    {attachments.map((a) => (
                        <li
                            key={a.id}
                            className="rounded-xl border border-border p-3 text-sm"
                        >
                            <p className="font-medium">
                                {a.label ||
                                    a.file?.original_name ||
                                    'Attachment'}
                            </p>
                            <p className="text-muted-foreground">
                                v{a.version} · {a.file?.scan_status}
                            </p>
                        </li>
                    ))}
                </ul>
            </div>
            <DocumentDrawer
                open={drawerOpen}
                onOpenChange={setDrawerOpen}
                parent={parent}
                storage={storage}
                max_upload_bytes={max_upload_bytes}
                attachments={attachments}
                permissions={permissions}
                links={{ manager: links.manager, upload: links.upload }}
            />
        </>
    );
}
