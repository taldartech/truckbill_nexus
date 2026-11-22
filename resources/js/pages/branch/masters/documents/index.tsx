import { Form, Head, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Attachment = {
    id: string;
    label: string | null;
    document_type: string | null;
    version: number;
    is_current: boolean;
    download_url: string | null;
    file: {
        original_name: string | null;
        scan_status: string | null;
        size_bytes: number | null;
        is_downloadable: boolean;
    } | null;
};

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
    attachments: Attachment[];
    links: { hub: string; upload: string };
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
    const titleId = useId();
    const panelRef = useRef<HTMLDivElement>(null);
    const closeRef = useRef<HTMLButtonElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!drawerOpen) return;
        closeRef.current?.focus();
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setDrawerOpen(false);
            }
            if (event.key !== 'Tab' || !panelRef.current) return;
            const focusable = panelRef.current.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
            );
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            triggerRef.current?.focus();
        };
    }, [drawerOpen]);

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
                    Parent: {parent.type} / {parent.id}
                </p>
                <Button
                    ref={triggerRef}
                    type="button"
                    onClick={() => setDrawerOpen(true)}
                >
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
            {drawerOpen ? (
                <div className="fixed inset-0 z-50 flex justify-end">
                    <button
                        type="button"
                        className="absolute inset-0 bg-black/40"
                        aria-label="Close documents drawer"
                        onClick={() => setDrawerOpen(false)}
                    />
                    <div
                        ref={panelRef}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby={titleId}
                        className="relative flex h-full w-full max-w-lg flex-col gap-4 overflow-y-auto border-l border-border bg-background p-4 shadow-xl"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2
                                    id={titleId}
                                    className="text-lg font-semibold"
                                >
                                    Documents
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {(
                                        storage.file_bytes /
                                        (1024 * 1024)
                                    ).toFixed(2)}{' '}
                                    MB counted · {storage.attachment_count}{' '}
                                    files · max{' '}
                                    {(max_upload_bytes / (1024 * 1024)).toFixed(
                                        0,
                                    )}{' '}
                                    MB
                                </p>
                            </div>
                            <Button
                                ref={closeRef}
                                type="button"
                                variant="outline"
                                onClick={() => setDrawerOpen(false)}
                            >
                                Close
                            </Button>
                        </div>
                        {permissions.can_create ? (
                            <Form
                                action={links.upload}
                                method="post"
                                encType="multipart/form-data"
                                className="space-y-3 rounded-xl border border-border p-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="file">Upload</Label>
                                            <Input
                                                id="file"
                                                name="file"
                                                type="file"
                                                required
                                            />
                                            <InputError message={errors.file} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="label">Label</Label>
                                            <Input id="label" name="label" />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="remark">
                                                Remark
                                            </Label>
                                            <Input
                                                id="remark"
                                                name="remark"
                                                defaultValue="Upload document"
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing
                                                ? 'Uploading…'
                                                : 'Upload'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : null}
                        <ul className="space-y-3">
                            {attachments.length === 0 ? (
                                <li className="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                                    No documents yet.
                                </li>
                            ) : (
                                attachments.map((attachment) => (
                                    <li
                                        key={attachment.id}
                                        className="space-y-2 rounded-xl border border-border p-3"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p className="font-medium">
                                                    {attachment.label ||
                                                        attachment.file
                                                            ?.original_name ||
                                                        'Attachment'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    v{attachment.version}
                                                    {attachment.is_current
                                                        ? ' · current'
                                                        : ''}{' '}
                                                    · scan:{' '}
                                                    {attachment.file
                                                        ?.scan_status ??
                                                        'unknown'}
                                                </p>
                                            </div>
                                            {attachment.download_url &&
                                            attachment.file?.is_downloadable ? (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <a
                                                        href={
                                                            attachment.download_url
                                                        }
                                                    >
                                                        Download
                                                    </a>
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    Blocked until scan permits
                                                    access
                                                </span>
                                            )}
                                        </div>
                                        {permissions.can_edit ? (
                                            <Form
                                                action={`/branch/companies/${company.id}/branches/${branch.id}/documents/attachments/${attachment.id}/replace`}
                                                method="post"
                                                encType="multipart/form-data"
                                                className="flex flex-wrap items-end gap-2"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <Input
                                                            name="file"
                                                            type="file"
                                                            required
                                                            className="max-w-xs"
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="remark"
                                                            value="Replace document"
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="secondary"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Replace
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        ) : null}
                                        {permissions.can_delete ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="destructive"
                                                onClick={() => {
                                                    if (
                                                        !confirm(
                                                            'Soft-delete this document?',
                                                        )
                                                    )
                                                        return;
                                                    router.delete(
                                                        `/branch/companies/${company.id}/branches/${branch.id}/documents/attachments/${attachment.id}`,
                                                        {
                                                            data: {
                                                                remark: 'Soft-delete document',
                                                            },
                                                        },
                                                    );
                                                }}
                                            >
                                                Delete
                                            </Button>
                                        ) : null}
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>
                </div>
            ) : null}
        </>
    );
}
