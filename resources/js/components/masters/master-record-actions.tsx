import { router } from '@inertiajs/react';
import { FileText, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { MasterAuditRemarkDialog } from '@/components/masters/master-audit-remark-dialog';

type Props = {
    /** Singular record noun used in labels, e.g. "vehicle". */
    noun: string;
    editHref?: string | null;
    /** Opens the document drawer when provided; otherwise `documentsHref` is linked. */
    onOpenDocuments?: () => void;
    documentsHref?: string | null;
    destroy?: { url: string; description: string } | null;
};

/**
 * Standard icon-only header actions for master records (Rules §26): Edit, Documents and
 * remark-confirmed soft delete. Pass only actions the viewer is authorized to use.
 */
export function MasterRecordActions({
    noun,
    editHref,
    onOpenDocuments,
    documentsHref,
    destroy,
}: Props) {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    return (
        <>
            {editHref ? (
                <AppIconAction
                    icon={Pencil}
                    label={`Edit ${noun}`}
                    href={editHref}
                    variant="warning"
                    size="sm"
                />
            ) : null}
            {onOpenDocuments || documentsHref ? (
                <AppIconAction
                    icon={FileText}
                    label="Documents"
                    onClick={onOpenDocuments}
                    href={
                        onOpenDocuments
                            ? undefined
                            : (documentsHref ?? undefined)
                    }
                    variant="info"
                    size="sm"
                />
            ) : null}
            {destroy ? (
                <>
                    <AppIconAction
                        icon={Trash2}
                        label={`Delete ${noun}`}
                        variant="destructive"
                        size="sm"
                        onClick={() => setDeleteOpen(true)}
                    />
                    <MasterAuditRemarkDialog
                        open={deleteOpen}
                        onOpenChange={setDeleteOpen}
                        title={`Delete ${noun}?`}
                        description={destroy.description}
                        confirmLabel="Delete"
                        processing={deleteProcessing}
                        onConfirm={(remark) => {
                            setDeleteProcessing(true);
                            router.delete(destroy.url, {
                                data: { remark },
                                onFinish: () => {
                                    setDeleteProcessing(false);
                                    setDeleteOpen(false);
                                },
                            });
                        }}
                    />
                </>
            ) : null}
        </>
    );
}
