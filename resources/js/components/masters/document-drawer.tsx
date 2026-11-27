import { Form, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import InputError from '@/components/input-error';
import { MasterAuditRemarkDialog } from '@/components/masters/master-audit-remark-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDisplayDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

export type DocumentExpiryStatus = {
    value: 'valid' | 'expiring_soon' | 'expired';
    label: string;
};

export type DocumentTypeOption = {
    value: string;
    label: string;
    requires_expiry: boolean;
    tracks_validity: boolean;
};

export type DocumentSection = { key: string; title: string };

export type DocumentDrawerAttachment = {
    id: string;
    label: string | null;
    document_type: string | null;
    document_type_label: string;
    section: string;
    document_number: string | null;
    issued_on: string | null;
    expires_on: string | null;
    expiry_status: DocumentExpiryStatus | null;
    version: number;
    is_current: boolean;
    download_url: string | null;
    replace_url?: string | null;
    destroy_url?: string | null;
    file: {
        original_name: string | null;
        scan_status: string | null;
        size_bytes: number | null;
        is_downloadable: boolean;
    } | null;
};

export type DocumentDrawerProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    parent: { type: string; id: string };
    title: string;
    retired: boolean;
    sections: DocumentSection[];
    document_types: DocumentTypeOption[];
    storage: { file_bytes: number; attachment_count: number };
    max_upload_bytes: number;
    attachments: DocumentDrawerAttachment[];
    permissions: {
        can_create: boolean;
        can_edit: boolean;
        can_delete: boolean;
    };
    links: {
        manager: string;
        upload: string;
    };
};

const ACCEPTED_UPLOAD_TYPES = '.pdf,.jpg,.jpeg,.png,.webp';

const expiryTone: Record<DocumentExpiryStatus['value'], string> = {
    valid: 'border-[#2FB36F]/30 bg-[#2FB36F]/10 text-[#1f6b45]',
    expiring_soon: 'border-[#FFC107]/40 bg-[#FFC107]/15 text-[#8a6a00]',
    expired: 'border-destructive/30 bg-destructive/10 text-destructive',
};

export function DocumentExpiryBadge({
    status,
}: {
    status: DocumentExpiryStatus;
}) {
    return (
        <Badge variant="outline" className={cn(expiryTone[status.value])}>
            {status.label}
        </Badge>
    );
}

function attachmentName(attachment: DocumentDrawerAttachment): string {
    return (
        attachment.label ||
        attachment.file?.original_name ||
        attachment.document_type_label
    );
}

function DocumentAttachmentSummary({
    attachment,
}: {
    attachment: DocumentDrawerAttachment;
}) {
    return (
        <div className="min-w-0 space-y-1">
            <p className="font-medium break-words">
                {attachmentName(attachment)}
            </p>
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                <span>{attachment.document_type_label}</span>
                <span aria-hidden>·</span>
                <span>
                    v{attachment.version}
                    {attachment.is_current ? ' · current' : ' · historical'}
                </span>
                {attachment.expiry_status ? (
                    <DocumentExpiryBadge status={attachment.expiry_status} />
                ) : null}
            </p>
            {attachment.document_number ||
            attachment.issued_on ||
            attachment.expires_on ? (
                <dl className="grid grid-cols-1 gap-x-3 gap-y-0.5 text-xs sm:grid-cols-3">
                    <div>
                        <dt className="text-muted-foreground">
                            Document Number
                        </dt>
                        <dd>{attachment.document_number ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Issue Date</dt>
                        <dd>
                            {attachment.issued_on
                                ? formatDisplayDate(attachment.issued_on)
                                : '—'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Expiry Date</dt>
                        <dd>
                            {attachment.expires_on
                                ? formatDisplayDate(attachment.expires_on)
                                : '—'}
                        </dd>
                    </div>
                </dl>
            ) : null}
        </div>
    );
}

function DownloadAction({
    attachment,
}: {
    attachment: DocumentDrawerAttachment;
}) {
    if (attachment.download_url && attachment.file?.is_downloadable) {
        return (
            <Button asChild variant="outline" size="sm">
                <a href={attachment.download_url}>Download</a>
            </Button>
        );
    }

    return (
        <span className="text-xs text-muted-foreground">
            Blocked until scan permits access
        </span>
    );
}

/**
 * Attachments grouped by server-provided sections (e.g. POD vs Supporting
 * Documents). `renderActions` adds per-row controls such as replace/delete.
 */
export function DocumentSectionList({
    sections,
    attachments,
    renderActions,
}: {
    sections: DocumentSection[];
    attachments: DocumentDrawerAttachment[];
    renderActions?: (attachment: DocumentDrawerAttachment) => ReactNode;
}) {
    return (
        <div className="space-y-4">
            {sections.map((section) => {
                const rows = attachments.filter(
                    (attachment) => attachment.section === section.key,
                );

                return (
                    <section
                        key={section.key}
                        aria-label={section.title}
                        className="space-y-2"
                    >
                        {sections.length > 1 ? (
                            <h3 className="text-sm font-semibold">
                                {section.title}
                            </h3>
                        ) : null}
                        <ul className="divide-y divide-border rounded-lg border border-border text-sm">
                            {rows.length === 0 ? (
                                <li className="px-3 py-2 text-muted-foreground">
                                    No {section.title} uploaded yet.
                                </li>
                            ) : (
                                rows.map((attachment) => (
                                    <li
                                        key={attachment.id}
                                        className="space-y-2 px-3 py-2"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <DocumentAttachmentSummary
                                                attachment={attachment}
                                            />
                                            <DownloadAction
                                                attachment={attachment}
                                            />
                                        </div>
                                        {renderActions?.(attachment)}
                                    </li>
                                ))
                            )}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}

function DocumentUploadForm({
    action,
    documentTypes,
}: {
    action: string;
    documentTypes: DocumentTypeOption[];
}) {
    const [selectedType, setSelectedType] = useState('');
    const selected = documentTypes.find(
        (option) => option.value === selectedType,
    );

    return (
        <Form
            action={action}
            method="post"
            encType="multipart/form-data"
            resetOnSuccess
            onSuccess={() => setSelectedType('')}
            className="space-y-3 rounded-xl border border-border p-3"
        >
            {({ errors, processing }) => (
                <>
                    {documentTypes.length > 0 ? (
                        <div className="space-y-2">
                            <Label htmlFor="drawer-document-type">
                                Document type
                            </Label>
                            <select
                                id="drawer-document-type"
                                name="document_type"
                                required
                                value={selectedType}
                                onChange={(event) =>
                                    setSelectedType(event.target.value)
                                }
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="" disabled>
                                    Select document type
                                </option>
                                {documentTypes.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.document_type} />
                        </div>
                    ) : null}
                    <div className="space-y-2">
                        <Label htmlFor="drawer-file">File (PDF or image)</Label>
                        <Input
                            id="drawer-file"
                            name="file"
                            type="file"
                            accept={ACCEPTED_UPLOAD_TYPES}
                            required
                        />
                        <InputError message={errors.file} />
                    </div>
                    {selected?.tracks_validity ? (
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-2">
                                <Label htmlFor="drawer-document-number">
                                    Document Number
                                </Label>
                                <Input
                                    id="drawer-document-number"
                                    name="document_number"
                                    maxLength={64}
                                />
                                <InputError message={errors.document_number} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="drawer-issued-on">
                                    Issue Date
                                </Label>
                                <Input
                                    id="drawer-issued-on"
                                    name="issued_on"
                                    type="date"
                                />
                                <InputError message={errors.issued_on} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="drawer-expires-on">
                                    Expiry Date
                                    {selected.requires_expiry
                                        ? ' (required)'
                                        : ' (optional)'}
                                </Label>
                                <Input
                                    id="drawer-expires-on"
                                    name="expires_on"
                                    type="date"
                                    required={selected.requires_expiry}
                                />
                                <InputError message={errors.expires_on} />
                            </div>
                        </div>
                    ) : null}
                    <div className="space-y-2">
                        <Label htmlFor="drawer-label">Label</Label>
                        <Input id="drawer-label" name="label" maxLength={128} />
                        <InputError message={errors.label} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="drawer-remark">Remark</Label>
                        <Input
                            id="drawer-remark"
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
    );
}

export function DocumentDrawer({
    open,
    onOpenChange,
    title,
    retired,
    sections,
    document_types,
    storage,
    max_upload_bytes,
    attachments,
    permissions,
    links,
}: DocumentDrawerProps) {
    const storageMb = (storage.file_bytes / (1024 * 1024)).toFixed(2);
    const maxMb = (max_upload_bytes / (1024 * 1024)).toFixed(0);
    const [pendingDelete, setPendingDelete] =
        useState<DocumentDrawerAttachment | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const canUpload = permissions.can_create && !retired;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border p-4 text-left">
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>
                        {storageMb} MB counted · {storage.attachment_count}{' '}
                        files · PDF or image, max {maxMb} MB per upload
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-col gap-4 p-4">
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.manager}>Open full manager</Link>
                        </Button>
                    </div>

                    {retired ? (
                        <p
                            role="note"
                            className="rounded-xl border border-border bg-muted/40 p-3 text-sm"
                        >
                            Documents are no longer collected for this record
                            type. Existing files remain available read-only.
                        </p>
                    ) : null}

                    {canUpload ? (
                        <DocumentUploadForm
                            action={links.upload}
                            documentTypes={document_types}
                        />
                    ) : null}

                    <DocumentSectionList
                        sections={sections}
                        attachments={attachments}
                        renderActions={(attachment) => (
                            <>
                                {permissions.can_edit &&
                                attachment.replace_url ? (
                                    <Form
                                        action={attachment.replace_url}
                                        method="post"
                                        encType="multipart/form-data"
                                        className="flex flex-wrap items-end gap-2"
                                    >
                                        {({ processing }) => (
                                            <>
                                                <Input
                                                    name="file"
                                                    type="file"
                                                    accept={
                                                        ACCEPTED_UPLOAD_TYPES
                                                    }
                                                    required
                                                    className="max-w-xs"
                                                    aria-label={`Replace ${attachmentName(attachment)}`}
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
                                                    disabled={processing}
                                                >
                                                    Replace
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                ) : null}
                                {permissions.can_delete &&
                                attachment.destroy_url ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        onClick={() =>
                                            setPendingDelete(attachment)
                                        }
                                    >
                                        Delete
                                    </Button>
                                ) : null}
                            </>
                        )}
                    />
                </div>
                <MasterAuditRemarkDialog
                    open={pendingDelete !== null}
                    onOpenChange={(next) => {
                        if (!next) {
                            setPendingDelete(null);
                        }
                    }}
                    title="Delete document?"
                    description={
                        pendingDelete
                            ? `Soft-delete ${attachmentName(pendingDelete)}. The file stays retained for audit and storage accounting.`
                            : ''
                    }
                    confirmLabel="Delete"
                    processing={deleteProcessing}
                    onConfirm={(remark) => {
                        if (!pendingDelete?.destroy_url) {
                            return;
                        }
                        setDeleteProcessing(true);
                        router.delete(pendingDelete.destroy_url, {
                            data: { remark },
                            preserveScroll: true,
                            onFinish: () => {
                                setDeleteProcessing(false);
                                setPendingDelete(null);
                            },
                        });
                    }}
                />
            </SheetContent>
        </Sheet>
    );
}
