import { router } from '@inertiajs/react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    ChangeReview,
    FieldReview,
} from '@/components/form-confirmation/types';
import {
    TyreLayoutFormFields,
    type TyreAxleDraft,
} from '@/components/masters/tyre-layout-form-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useEffect, useMemo, useState } from 'react';

type LayoutItem = {
    id: string;
    name: string;
    spare_tyre_count: number;
    is_active: boolean;
    axles: Array<{
        sequence: number;
        configuration: 'single' | 'dual';
    }>;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: LayoutItem | null;
    updateUrl: string;
};

type Draft = {
    name: string;
    spareTyreCount: number;
    axles: TyreAxleDraft[];
    is_active: boolean;
    remark: string;
};

function axlesFromItem(item: LayoutItem): TyreAxleDraft[] {
    return [...item.axles]
        .sort((a, b) => a.sequence - b.sequence)
        .map((axle) => ({
            sequence: axle.sequence,
            configuration: axle.configuration,
        }));
}

function axlesSummary(axles: TyreAxleDraft[]): string {
    if (axles.length === 0) {
        return 'No axles';
    }
    return axles
        .map(
            (axle) =>
                `${axle.sequence}: ${axle.configuration === 'dual' ? 'Dual' : 'Single'}`,
        )
        .join(', ');
}

export function EditTyreLayoutSheet({
    open,
    onOpenChange,
    item,
    updateUrl,
}: Props) {
    const [draft, setDraft] = useState<Draft>({
        name: '',
        spareTyreCount: 1,
        axles: [],
        is_active: true,
        remark: '',
    });
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    useEffect(() => {
        if (!item || !open) {
            return;
        }
        setDraft({
            name: item.name,
            spareTyreCount: item.spare_tyre_count,
            axles: axlesFromItem(item),
            is_active: item.is_active,
            remark: '',
        });
        setErrors({});
        setFormError(null);
        setConfirmOpen(false);
        setProcessing(false);
    }, [item, open]);

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            setConfirmOpen(false);
            setProcessing(false);
        }
        onOpenChange(next);
    };

    const changes = useMemo((): ChangeReview[] => {
        if (!item) {
            return [];
        }
        const originalAxles = axlesFromItem(item);
        const rows: ChangeReview[] = [];

        if (draft.name.trim() !== item.name) {
            rows.push({
                label: 'Layout name',
                previous: item.name,
                next: draft.name.trim(),
                changeType: 'modified',
            });
        }
        if (draft.spareTyreCount !== item.spare_tyre_count) {
            rows.push({
                label: 'Spare tyres',
                previous: String(item.spare_tyre_count),
                next: String(draft.spareTyreCount),
                changeType: 'modified',
            });
        }
        if (axlesSummary(originalAxles) !== axlesSummary(draft.axles)) {
            rows.push({
                label: 'Axles',
                previous: axlesSummary(originalAxles),
                next: axlesSummary(draft.axles),
                changeType: 'modified',
            });
        }
        if (draft.is_active !== item.is_active) {
            rows.push({
                label: 'Status',
                previous: item.is_active ? 'Active' : 'Inactive',
                next: draft.is_active ? 'Active' : 'Inactive',
                changeType: 'modified',
            });
        }

        return rows;
    }, [draft, item]);

    const reviewFields = useMemo<FieldReview[]>(
        () => [{ label: 'Remark', value: draft.remark.trim() }],
        [draft.remark],
    );

    const fieldErrors: Record<string, string> = {};
    for (const [key, value] of Object.entries(errors)) {
        fieldErrors[key] = Array.isArray(value) ? value[0] : value;
    }

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});

        if (draft.name.trim() === '') {
            setFormError('Layout name is required.');
            return;
        }
        if (draft.axles.length === 0) {
            setFormError('Add at least one axle.');
            return;
        }
        if (draft.remark.trim() === '') {
            setFormError('Audit remark is required.');
            return;
        }
        if (changes.length === 0) {
            setFormError('Change at least one field before saving.');
            return;
        }
        setConfirmOpen(true);
    };

    const submit = () => {
        if (!item) {
            return;
        }
        setProcessing(true);
        router.patch(
            updateUrl,
            {
                name: draft.name.trim(),
                spare_tyre_count: draft.spareTyreCount,
                axles: draft.axles,
                is_active: draft.is_active,
                remark: draft.remark.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                    handleOpenChange(false);
                },
                onError: (serverErrors) => {
                    setProcessing(false);
                    setConfirmOpen(false);
                    const mapped: Record<string, string[] | string> = {};
                    for (const [key, value] of Object.entries(serverErrors)) {
                        mapped[key] = value;
                    }
                    setErrors(mapped);
                    const first =
                        mapped.remark ??
                        mapped.name ??
                        mapped.axles ??
                        'Could not save tyre layout.';
                    setFormError(
                        Array.isArray(first) ? first[0] : String(first),
                    );
                },
            },
        );
    };

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent className="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg">
                    <SheetHeader className="px-4 pt-4">
                        <SheetTitle>Edit tyre layout</SheetTitle>
                        <SheetDescription>
                            Axle changes may be blocked when the layout is
                            assigned to vehicles. Audit remark is required.
                        </SheetDescription>
                    </SheetHeader>

                    {item ? (
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
                                layoutName={draft.name}
                                spareTyreCount={draft.spareTyreCount}
                                axles={draft.axles}
                                errors={errors}
                                onLayoutNameChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        name: value,
                                    }))
                                }
                                onSpareTyreCountChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        spareTyreCount: value,
                                    }))
                                }
                                onAxlesChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        axles: value,
                                    }))
                                }
                            />

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={draft.is_active}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            is_active: event.target.checked,
                                        }))
                                    }
                                />
                                Active
                            </label>

                            <div className="space-y-1">
                                <Label htmlFor="edit-tyre-remark" required>
                                    Remark
                                </Label>
                                <textarea
                                    id="edit-tyre-remark"
                                    className="min-h-[4.5rem] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    value={draft.remark}
                                    maxLength={1000}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            remark: event.target.value,
                                        }))
                                    }
                                />
                                <InputError message={fieldErrors.remark} />
                            </div>

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
                    ) : null}
                </SheetContent>
            </Sheet>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="edit"
                title="Save tyre layout changes?"
                description="Confirm updates before applying them to the register."
                changes={changes}
                fields={reviewFields}
                processing={processing}
                onConfirm={submit}
                confirmLabel={processing ? 'Saving…' : 'Save'}
            />
        </>
    );
}
