import { Form } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { BranchEntryConfirmationDialog } from '@/components/workspace/branch-entry-confirmation-dialog';
import { Button } from '@/components/ui/button';
import { branch as enterBranchContext } from '@/routes/workspace/context';

type BranchLike = {
    id: string;
    name: string;
    city?: string | null;
    state?: string | null;
};

type Props = {
    company: { id: string; name: string };
    branch: BranchLike;
    redirect?: string;
    label?: string;
    className?: string;
    buttonVariant?: 'default' | 'outline';
};

/** Owner / admin branch entry with confirmation before workspace context POST. */
export function BranchContextEnterControl({
    company,
    branch,
    redirect = '/operations',
    label = 'Enter Branch Panel',
    className,
    buttonVariant = 'default',
}: Props) {
    const [open, setOpen] = useState(false);
    const formId = useMemo(
        () => `owner-branch-enter-${company.id}-${branch.id}`,
        [company.id, branch.id],
    );

    const branchLocation =
        [branch.city, branch.state].filter(Boolean).join(', ') || null;

    return (
        <>
            <Form
                id={formId}
                {...enterBranchContext.form({
                    company: company.id,
                    branch: branch.id,
                })}
            >
                {({ processing }) => (
                    <>
                        <input type="hidden" name="redirect" value={redirect} />
                        <Button
                            type="button"
                            variant={buttonVariant}
                            disabled={processing}
                            className={className}
                            onClick={() => setOpen(true)}
                        >
                            {label}
                        </Button>
                    </>
                )}
            </Form>
            <BranchEntryConfirmationDialog
                open={open}
                onOpenChange={setOpen}
                companyName={company.name}
                branchName={branch.name}
                branchLocation={branchLocation}
                onConfirm={() => {
                    setOpen(false);
                    (
                        document.getElementById(
                            formId,
                        ) as HTMLFormElement | null
                    )?.requestSubmit();
                }}
                onCancel={() => setOpen(false)}
            />
        </>
    );
}
