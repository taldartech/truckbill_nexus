import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import {
    DocumentDrawer,
    DocumentSectionList,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { Button } from '@/components/ui/button';

type Props = Omit<DocumentDrawerProps, 'open' | 'onOpenChange' | 'links'> & {
    links: { hub: string; upload: string; manager: string };
};

export default function DocumentsIndex({ links, ...drawer }: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [drawerOpen, setDrawerOpen] = useState(!drawer.retired);

    return (
        <>
            <Head title={drawer.title} />
            <AppIndexPage>
                <AppIndexListCard title={drawer.title}>
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
                            Parent: {drawer.parent.type.replaceAll('_', ' ')} /{' '}
                            {drawer.parent.id}
                        </p>
                        {drawer.retired ? (
                            <p
                                role="note"
                                className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                            >
                                Documents are no longer collected for this
                                record type. Existing files remain available
                                read-only.
                            </p>
                        ) : (
                            <Button
                                type="button"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                onClick={() => setDrawerOpen(true)}
                            >
                                Open document drawer
                            </Button>
                        )}
                        <DocumentSectionList
                            sections={drawer.sections}
                            attachments={drawer.attachments}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
            <DocumentDrawer
                {...drawer}
                open={drawerOpen}
                onOpenChange={setDrawerOpen}
                links={{ manager: links.manager, upload: links.upload }}
            />
        </>
    );
}
