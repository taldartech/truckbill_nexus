import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import {
    TyreLayoutFormFields,
    type TyreAxleDraft,
} from '@/components/masters/tyre-layout-form-fields';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { getCsrfToken } from '@/lib/csrf';
import { useMemo, useState } from 'react';

export type QuickCreatedTyreLayout = {
    id: string;
    name: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (layout: QuickCreatedTyreLayout) => void;
};

const defaultAxles = (): TyreAxleDraft[] => [
    { sequence: 1, configuration: 'single' },
    { sequence: 2, configuration: 'dual' },
    { sequence: 3, configuration: 'dual' },
];

export function QuickCreateTyreLayoutSheet({
    open,
    onOpenChange,
    storeUrl,
    onCreated,
}: Props) {
    const [name, setName] = useState('');
    const [spareTyreCount, setSpareTyreCount] = useState(1);
    const [axles, setAxles] = useState<TyreAxleDraft[]>(defaultAxles);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(
        () => [
            { label: 'Layout name', value: name },
            { label: 'Axles', value: String(axles.length) },
            { label: 'Spare tyres', value: String(spareTyreCount) },
        ],
        [name, axles.length, spareTyreCount],
    );

    const reset = () => {
        setName('');
        setSpareTyreCount(1);
        setAxles(defaultAxles());
        setErrors({});
        setFormError(null);
        setConfirmOpen(false);
        setProcessing(false);
    };

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            reset();
        }
        onOpenChange(next);
    };

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});
        if (name.trim() === '') {
            setFormError('Layout name is required.');
            return;
        }
        if (axles.length === 0) {
            setFormError('Add at least one axle.');
            return;
        }
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        try {
            const response = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: name.trim(),
                    spare_tyre_count: spareTyreCount,
                    axles,
                }),
            });

            const json = (await response.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[] | string>;
                data?: QuickCreatedTyreLayout;
            } | null;

            if (!response.ok) {
                if (json?.errors) {
                    setErrors(json.errors);
                }
                setFormError(
                    json?.message ??
                        'Could not create tyre layout. Check the form and try again.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            if (!json?.data?.id || !json.data.name) {
                setFormError(
                    'Tyre layout was created but the response was incomplete.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            onCreated(json.data);
            handleOpenChange(false);
        } catch {
            setFormError('Network error while creating tyre layout.');
            setConfirmOpen(false);
            setProcessing(false);
        }
    };

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent className="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg">
                    <SheetHeader className="px-4 pt-4">
                        <SheetTitle>Add tyre layout</SheetTitle>
                        <SheetDescription>
                            Configure axles and spare tyres. Position IDs are
                            derived automatically.
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        className="mt-4 space-y-4 px-4 pb-4"
                        onSubmit={openConfirm}
                    >
                        {formError ? (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {formError}
                            </p>
                        ) : null}

                        <TyreLayoutFormFields
                            layoutName={name}
                            spareTyreCount={spareTyreCount}
                            axles={axles}
                            errors={errors}
                            onLayoutNameChange={setName}
                            onSpareTyreCountChange={setSpareTyreCount}
                            onAxlesChange={setAxles}
                        />

                        <SheetFooter className="gap-2 sm:justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Review
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="create"
                title="Create tyre layout?"
                description="Confirm layout name and axle configuration."
                fields={reviewFields}
                processing={processing}
                onConfirm={() => {
                    void submit();
                }}
                confirmLabel={processing ? 'Saving…' : 'Create'}
            />
        </>
    );
}
