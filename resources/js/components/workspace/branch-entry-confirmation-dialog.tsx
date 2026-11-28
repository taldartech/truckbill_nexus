import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyName: string;
    branchName: string;
    branchLocation?: string | null;
    processing?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
};

export function BranchEntryConfirmationDialog({
    open,
    onOpenChange,
    companyName,
    branchName,
    branchLocation,
    processing,
    onConfirm,
    onCancel,
}: Props) {
    return (
        <FormConfirmationDialog
            open={open}
            onOpenChange={onOpenChange}
            size="compact"
            mode="create"
            title="Enter this branch?"
            description="You will switch to the operational branch workspace."
            confirmLabel="Enter branch"
            processing={processing}
            onConfirm={onConfirm}
            onCancel={onCancel}
            sections={[
                {
                    title: 'Workspace',
                    tone: 'business',
                    fields: [
                        { label: 'Company', value: companyName },
                        { label: 'Branch', value: branchName },
                        ...(branchLocation
                            ? [{ label: 'Location', value: branchLocation }]
                            : []),
                    ],
                },
            ]}
        />
    );
}
