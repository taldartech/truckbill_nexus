import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
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
import { getCsrfToken } from '@/lib/csrf';
import { useMemo, useState } from 'react';

export type QuickCreatedCatalogueItem = {
    id: string;
    name: string;
    code?: string | null;
};

type Draft = {
    name: string;
    code: string;
};

const EMPTY: Draft = {
    name: '',
    code: '',
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    storeUrl: string;
    usesCode?: boolean;
    sheetDescription?: string;
    onCreated: (item: QuickCreatedCatalogueItem) => void;
};

function parseCreatedItem(json: unknown): QuickCreatedCatalogueItem | null {
    if (!json || typeof json !== 'object') {
        return null;
    }

    const payload = json as {
        data?: QuickCreatedCatalogueItem;
    };

    if (!payload.data?.id || !payload.data.name) {
        return null;
    }

    return {
        id: payload.data.id,
        name: payload.data.name,
        code: payload.data.code ?? null,
    };
}

/**
 * Owner catalogue quick-create (Goods Type, Package Type, Truck Type, Document Type, etc.).
 */
export function QuickCreateCatalogueSheet({
    open,
    onOpenChange,
    title,
    storeUrl,
    usesCode = false,
    sheetDescription = 'Create a catalogue item without leaving this form.',
    onCreated,
}: Props) {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(() => {
        const fields: FieldReview[] = [{ label: 'Name', value: draft.name }];
        if (usesCode) {
            fields.push({ label: 'Code', value: draft.code.trim() });
        }

        return fields;
    }, [draft, usesCode]);

    const reset = () => {
        setDraft(EMPTY);
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

    const update = <K extends keyof Draft>(key: K, value: Draft[K]) => {
        setDraft((current) => ({ ...current, [key]: value }));
    };

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});
        if (draft.name.trim() === '') {
            setFormError('Name is required.');
            return;
        }
        if (usesCode && draft.code.trim() === '') {
            setFormError('Code is required.');
            return;
        }
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload: Record<string, string> = {
            name: draft.name.trim(),
        };
        if (usesCode) {
            payload.code = draft.code.trim();
        }

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
                body: JSON.stringify(payload),
            });

            const json = (await response.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[] | string>;
                data?: QuickCreatedCatalogueItem;
            } | null;

            if (!response.ok) {
                const fieldErrors = json?.errors ?? {};
                setErrors(fieldErrors);
                const unmapped = Object.entries(fieldErrors)
                    .filter(
                        ([key]) =>
                            key !== 'name' && !(usesCode && key === 'code'),
                    )
                    .flatMap(([, value]) =>
                        Array.isArray(value) ? value : [value],
                    );
                setFormError(
                    unmapped.length > 0
                        ? unmapped.join(' ')
                        : Object.keys(fieldErrors).length > 0
                          ? null
                          : (json?.message ??
                            'Could not create catalogue item. Check the form and try again.'),
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            const created = parseCreatedItem(json);
            if (created === null) {
                setFormError(
                    'Catalogue item was created but the response was incomplete.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            onCreated(created);
            handleOpenChange(false);
        } catch {
            setFormError('Network error while creating catalogue item.');
            setConfirmOpen(false);
            setProcessing(false);
        }
    };

    const firstError = (key: string): string | undefined => {
        const value = errors[key];
        if (Array.isArray(value)) {
            return value[0];
        }
        return typeof value === 'string' ? value : undefined;
    };

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent className="sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>Add {title}</SheetTitle>
                        <SheetDescription>{sheetDescription}</SheetDescription>
                    </SheetHeader>

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

                        <div className="space-y-1">
                            <Label htmlFor="catalogue-name" required>
                                Name
                            </Label>
                            <Input
                                id="catalogue-name"
                                value={draft.name}
                                onChange={(event) =>
                                    update('name', event.target.value)
                                }
                                autoFocus
                            />
                            <InputError message={firstError('name')} />
                        </div>

                        {usesCode ? (
                            <div className="space-y-1">
                                <Label htmlFor="catalogue-code" required>
                                    Code
                                </Label>
                                <Input
                                    id="catalogue-code"
                                    value={draft.code}
                                    onChange={(event) =>
                                        update('code', event.target.value)
                                    }
                                />
                                <InputError message={firstError('code')} />
                            </div>
                        ) : null}

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
                title={`Confirm ${title}`}
                description="Create this catalogue item and select it on the form."
                mode="create"
                fields={reviewFields}
                confirmLabel="Create"
                processing={processing}
                onConfirm={() => {
                    void submit();
                }}
            />
        </>
    );
}
