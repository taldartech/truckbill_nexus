import { router } from '@inertiajs/react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    ChangeReview,
    FieldReview,
} from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { singularMasterLabel } from '@/lib/master-labels';
import { useEffect, useMemo, useState } from 'react';

type CatalogueItem = {
    id: string;
    name: string;
    code?: string | null;
    is_active: boolean;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pageTitle: string;
    item: CatalogueItem | null;
    updateUrl: string;
    usesCode?: boolean;
};

type Draft = {
    name: string;
    is_active: boolean;
    remark: string;
};

export function EditOwnerCatalogueSheet({
    open,
    onOpenChange,
    pageTitle,
    item,
    updateUrl,
    usesCode = false,
}: Props) {
    const label = singularMasterLabel(pageTitle);
    const [draft, setDraft] = useState<Draft>({
        name: '',
        is_active: true,
        remark: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    useEffect(() => {
        if (!item || !open) {
            return;
        }
        setDraft({
            name: item.name,
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

        const rows: ChangeReview[] = [];
        if (draft.name.trim() !== item.name) {
            rows.push({
                label: 'Name',
                previous: item.name,
                next: draft.name.trim(),
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

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});

        const nextErrors: Record<string, string> = {};
        if (draft.name.trim() === '') {
            nextErrors.name = 'Name is required.';
        }
        if (draft.remark.trim() === '') {
            nextErrors.remark = 'Audit remark is required.';
        }
        if (Object.keys(nextErrors).length > 0) {
            setErrors(nextErrors);
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
                    const mapped: Record<string, string> = {};
                    for (const [key, value] of Object.entries(serverErrors)) {
                        mapped[key] = Array.isArray(value)
                            ? value[0]
                            : String(value);
                    }
                    setErrors(mapped);
                    setFormError(
                        mapped.remark ??
                            mapped.name ??
                            'Could not save changes.',
                    );
                },
            },
        );
    };

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent className="sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle className="capitalize">
                            Edit {label}
                        </SheetTitle>
                        <SheetDescription>
                            Update the catalogue item. Audit remark is required.
                        </SheetDescription>
                    </SheetHeader>

                    {item ? (
                        <form
                            className="mt-4 space-y-4 px-1"
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

                            {usesCode && item.code ? (
                                <div className="space-y-1">
                                    <Label>Code</Label>
                                    <p className="font-mono text-sm text-slate-700">
                                        {item.code}
                                    </p>
                                </div>
                            ) : null}

                            <div className="space-y-1">
                                <Label htmlFor="edit-catalogue-name" required>
                                    Name
                                </Label>
                                <Input
                                    id="edit-catalogue-name"
                                    value={draft.name}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            name: event.target.value,
                                        }))
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>

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
                                <Label htmlFor="edit-catalogue-remark" required>
                                    Remark
                                </Label>
                                <textarea
                                    id="edit-catalogue-remark"
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
                                <InputError message={errors.remark} />
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
                title={`Save ${label} changes?`}
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
