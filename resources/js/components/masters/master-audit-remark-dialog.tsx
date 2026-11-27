import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    /** What happens when the action is confirmed. */
    consequences?: string[];
    confirmLabel?: string;
    processing?: boolean;
    errorMessage?: string | null;
    onConfirm: (remark: string) => void;
};

/**
 * Destructive / irreversible master action with a mandatory audit remark.
 */
export function MasterAuditRemarkDialog({
    open,
    onOpenChange,
    title,
    description,
    consequences,
    confirmLabel = 'Confirm',
    processing = false,
    errorMessage,
    onConfirm,
}: Props) {
    return (
        <FormConfirmationDialog
            open={open}
            onOpenChange={onOpenChange}
            mode="destructive"
            title={title}
            description={description}
            consequences={consequences}
            reason={{ label: 'Audit remark', error: errorMessage }}
            processing={processing}
            confirmLabel={confirmLabel}
            onConfirm={(remark) => onConfirm(remark ?? '')}
        />
    );
}
