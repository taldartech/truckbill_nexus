import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import {
    DocumentDrawer,
    type DocumentDrawerAttachment,
} from '@/components/masters/document-drawer';
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
            <AppIndexPage>
                <AppIndexListCard title="Document management">
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}
                    <div className="space-y-3 p-4">
                        <p className="text-sm text-slate-500">
                            Parent: {parent.type.replaceAll('_', ' ')} /{' '}
                            {parent.id}
                        </p>
                        <Button
                            type="button"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            onClick={() => setDrawerOpen(true)}
                        >
                            Open document drawer
                        </Button>
                        <ul className="space-y-2 md:hidden">
                            {attachments.map((a) => (
                                <li
                                    key={a.id}
                                    className="rounded-lg border border-[#e5e7eb] p-3 text-sm"
                                >
                                    <p className="font-medium">
                                        {a.label ||
                                            a.file?.original_name ||
                                            'Attachment'}
                                    </p>
                                    <p className="text-slate-500">
                                        v{a.version} · {a.file?.scan_status}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
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
