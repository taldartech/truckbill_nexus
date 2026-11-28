import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps } from 'react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import type { Button } from '@/components/ui/button';
import { BranchEntryConfirmationDialog } from '@/components/workspace/branch-entry-confirmation-dialog';

type ButtonVariant = ComponentProps<typeof Button>['variant'];

type Props = {
    contextUrl: string;
    redirect: string;
    icon: LucideIcon;
    label: string;
    disabled?: boolean;
    /** Semantic action colour — forwarded to AppIconAction. */
    variant?: ButtonVariant;
    /** When set, confirm before entering branch workspace context. */
    branchEntryConfirm?: {
        companyName: string;
        branchName: string;
        location?: string | null;
    };
};

/**
 * POST an authorized workspace context switch (route-bound company/branch),
 * then redirect to a clean URL. Never puts tenant IDs in the request body.
 */
export function WorkspaceContextAction({
    contextUrl,
    redirect,
    icon,
    label,
    disabled = false,
    variant = 'outline',
    branchEntryConfirm,
}: Props) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const formId = useMemo(
        () =>
            `workspace-context-${contextUrl.replace(/[^a-zA-Z0-9]/g, '').slice(-24)}`,
        [contextUrl],
    );

    return (
        <Form id={formId} action={contextUrl} method="post" className="inline">
            {({ processing }) => (
                <>
                    <input type="hidden" name="redirect" value={redirect} />
                    <AppIconAction
                        icon={icon}
                        label={label}
                        type={branchEntryConfirm ? 'button' : 'submit'}
                        variant={variant}
                        disabled={disabled || processing}
                        loading={processing}
                        onClick={
                            branchEntryConfirm
                                ? () => setConfirmOpen(true)
                                : undefined
                        }
                    />
                    {branchEntryConfirm ? (
                        <BranchEntryConfirmationDialog
                            open={confirmOpen}
                            onOpenChange={setConfirmOpen}
                            companyName={branchEntryConfirm.companyName}
                            branchName={branchEntryConfirm.branchName}
                            branchLocation={branchEntryConfirm.location}
                            onConfirm={() => {
                                setConfirmOpen(false);
                                (
                                    document.getElementById(
                                        formId,
                                    ) as HTMLFormElement | null
                                )?.requestSubmit();
                            }}
                            onCancel={() => setConfirmOpen(false)}
                        />
                    ) : null}
                </>
            )}
        </Form>
    );
}
