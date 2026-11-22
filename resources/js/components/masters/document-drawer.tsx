import { Form, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

export type DocumentDrawerAttachment = {
    id: string;
    label: string | null;
    document_type: string | null;
    version: number;
    is_current: boolean;
    download_url: string | null;
    replace_url?: string;
    destroy_url?: string;
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
    title?: string;
};

export function DocumentDrawer({
    open,
    onOpenChange,
    parent,
    storage,
    max_upload_bytes,
    attachments,
    permissions,
    links,
    title = 'Documents',
}: DocumentDrawerProps) {
    const storageMb = (storage.file_bytes / (1024 * 1024)).toFixed(2);
    const maxMb = (max_upload_bytes / (1024 * 1024)).toFixed(0);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border p-4 text-left">
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>
                        {parent.type.replaceAll('_', ' ')} attachments ·{' '}
                        {storageMb} MB counted · {storage.attachment_count}{' '}
                        files · max {maxMb} MB upload
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-col gap-4 p-4">
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.manager}>Open full manager</Link>
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
                                        <Label htmlFor="drawer-file">
                                            Upload
                                        </Label>
                                        <Input
                                            id="drawer-file"
                                            name="file"
                                            type="file"
                                            required
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="drawer-label">
                                            Label
                                        </Label>
                                        <Input id="drawer-label" name="label" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="drawer-remark">
                                            Remark
                                        </Label>
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
                                                    : ' · historical'}{' '}
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
                                                        required
                                                        className="max-w-xs"
                                                        aria-label={`Replace ${attachment.label || attachment.file?.original_name || 'attachment'}`}
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
                                            onClick={() => {
                                                const remark = window.prompt(
                                                    'Remark for soft deletion',
                                                    'Soft-delete document',
                                                );
                                                if (!remark) return;
                                                router.delete(
                                                    attachment.destroy_url!,
                                                    {
                                                        data: { remark },
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
            </SheetContent>
        </Sheet>
    );
}
