import { Form, router } from '@inertiajs/react';
import { useEffect, useId, useRef } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type DocumentAttachmentRow = {
    id: string;
    document_type: string | null;
    label: string | null;
    version: number;
    is_current: boolean;
    file: {
        original_name: string | null;
        mime_type: string | null;
        size_bytes: number | null;
        scan_status: string | null;
        is_downloadable: boolean;
    } | null;
    download_url: string | null;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    attachments: DocumentAttachmentRow[];
    uploadUrl: string;
    replaceUrlFor: (id: string) => string;
    destroyUrlFor: (id: string) => string;
    maxUploadBytes: number;
    storage: { file_bytes: number; attachment_count: number };
    canUpload: boolean;
    canDelete: boolean;
    triggerRef?: React.RefObject<HTMLElement | null>;
};

export function DocumentDrawer({
    open,
    onOpenChange,
    title,
    attachments,
    uploadUrl,
    replaceUrlFor,
    destroyUrlFor,
    maxUploadBytes,
    storage,
    canUpload,
    canDelete,
    triggerRef,
}: Props) {
    const titleId = useId();
    const panelRef = useRef<HTMLDivElement>(null);
    const firstFocusRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        const previous = document.activeElement as HTMLElement | null;
        firstFocusRef.current?.focus();
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onOpenChange(false);
            }
            if (event.key !== 'Tab' || !panelRef.current) {
                return;
            }
            const focusable = panelRef.current.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
            );
            if (focusable.length === 0) {
                return;
            }
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
            (triggerRef?.current ?? previous)?.focus?.();
        };
    }, [open, onOpenChange, triggerRef]);

    if (!open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex justify-end"
            role="presentation"
        >
            <button
                type="button"
                className="absolute inset-0 bg-black/40"
                aria-label="Close documents drawer"
                onClick={() => onOpenChange(false)}
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
                        <h2 id={titleId} className="text-lg font-semibold">
                            {title}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Storage counted:{' '}
                            {(storage.file_bytes / (1024 * 1024)).toFixed(2)} MB
                            · {storage.attachment_count} attachments · max{' '}
                            {(maxUploadBytes / (1024 * 1024)).toFixed(0)} MB per
                            file
                        </p>
                    </div>
                    <Button
                        ref={firstFocusRef}
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                </div>

                {canUpload ? (
                    <Form
                        action={uploadUrl}
                        method="post"
                        encType="multipart/form-data"
                        className="space-y-3 rounded-xl border border-border p-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="document-file">
                                        Upload document
                                    </Label>
                                    <Input
                                        id="document-file"
                                        name="file"
                                        type="file"
                                        required
                                    />
                                    <InputError message={errors.file} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="document-label">
                                        Label
                                    </Label>
                                    <Input id="document-label" name="label" />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="document-type">
                                        Document type
                                    </Label>
                                    <Input
                                        id="document-type"
                                        name="document_type"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="document-remark">
                                        Remark
                                    </Label>
                                    <Input
                                        id="document-remark"
                                        name="remark"
                                        defaultValue="Upload document"
                                    />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Uploading…' : 'Upload'}
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
                                            {attachment.file?.scan_status ??
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
                                            <a href={attachment.download_url}>
                                                Download
                                            </a>
                                        </Button>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            Not available until scan passes
                                        </span>
                                    )}
                                </div>
                                {canUpload ? (
                                    <Form
                                        action={replaceUrlFor(attachment.id)}
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
                                                <Input
                                                    type="hidden"
                                                    name="remark"
                                                    value="Replace document"
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={processing}
                                                >
                                                    Replace
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                ) : null}
                                {canDelete ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        onClick={() => {
                                            if (
                                                !confirm(
                                                    'Soft-delete this document?',
                                                )
                                            ) {
                                                return;
                                            }
                                            router.delete(
                                                destroyUrlFor(attachment.id),
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
    );
}
