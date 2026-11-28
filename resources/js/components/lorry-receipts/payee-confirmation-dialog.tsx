import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    truckNumber: string;
    payToType: 'owner' | 'agency';
    payeeName: string;
    payeeContact?: string | null;
    onConfirm: () => void;
    onCancel: () => void;
};

/** Compact Pay To confirmation — independent of final Bilty submit confirmation. */
export function PayeeConfirmationDialog({
    open,
    onOpenChange,
    truckNumber,
    payToType,
    payeeName,
    payeeContact,
    onConfirm,
    onCancel,
}: Props) {
    const payToLabel = payToType === 'owner' ? 'Truck Owner' : 'Agency';

    return (
        <FormConfirmationDialog
            open={open}
            onOpenChange={onOpenChange}
            size="compact"
            mode="create"
            title="Confirm Pay To?"
            description="This Payee will be used for Lorry Hire and transporter payments on this Bilty."
            confirmLabel="Confirm Payee"
            onConfirm={onConfirm}
            onCancel={onCancel}
            sections={[
                {
                    title: 'Truck & Payee',
                    tone: 'route',
                    fields: [
                        { label: 'Truck Number', value: truckNumber },
                        { label: 'Pay To', value: payToLabel },
                        { label: 'Payee', value: payeeName },
                        ...(payeeContact
                            ? [{ label: 'Contact', value: payeeContact }]
                            : []),
                    ],
                },
            ]}
        />
    );
}
