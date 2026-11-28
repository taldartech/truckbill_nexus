import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    truckNumber: string;
    payToType: 'owner' | 'agency';
    payeeName: string;
    payeeContact?: string | null;
    declarationMissing?: boolean;
    partyEditUrl?: string | null;
    onRefreshPayee?: () => void;
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
    declarationMissing = false,
    partyEditUrl,
    onRefreshPayee,
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
            secondaryConfirmLabel={
                declarationMissing && partyEditUrl
                    ? 'Update declaration'
                    : undefined
            }
            onSecondaryConfirm={
                declarationMissing && partyEditUrl
                    ? () => {
                          const popup = window.open(
                              partyEditUrl,
                              'truckbill-party-edit',
                              'noopener',
                          );
                          if (!popup) {
                              window.location.assign(partyEditUrl);
                              return;
                          }
                          const timer = window.setInterval(() => {
                              if (popup.closed) {
                                  window.clearInterval(timer);
                                  onRefreshPayee?.();
                              }
                          }, 600);
                      }
                    : undefined
            }
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
                ...(declarationMissing
                    ? [
                          {
                              title: 'TDS declaration',
                              tone: 'destructive' as const,
                              layout: 'full' as const,
                              fields: [
                                  {
                                      label: 'Declaration received',
                                      value: 'No',
                                  },
                                  {
                                      label: 'Notice',
                                      value: 'TDS declaration has not been received from this transporter. Please update the declaration details if available. You may continue after confirming the Payee.',
                                  },
                              ],
                          },
                      ]
                    : []),
            ]}
        />
    );
}
