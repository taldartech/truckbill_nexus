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

export type QuickCreatedState = {
    id: string;
    name: string;
    code: string;
    gst_code: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (state: QuickCreatedState) => void;
    /** Raise stacking order when opened above another sheet. */
    nested?: boolean;
    /**
     * Customer/operator mode: name only; backend resolves approved India catalogue
     * GST jurisdiction codes. Internal freeform keeps code + GST fields.
     */
    catalogueOnly?: boolean;
};

type Draft = {
    name: string;
    code: string;
    gst_code: string;
    country_code: string;
    is_active: boolean;
};

const EMPTY: Draft = {
    name: '',
    code: '',
    gst_code: '',
    country_code: 'IN',
    is_active: true,
};

function firstError(
    errors: Record<string, string[] | string> | undefined,
    key: string,
): string | undefined {
    const value = errors?.[key];
    if (Array.isArray(value)) {
        return value[0];
    }
    return typeof value === 'string' ? value : undefined;
}

function parseCreatedState(json: unknown): QuickCreatedState | null {
    if (typeof json !== 'object' || json === null) {
        return null;
    }
    const record = json as {
        data?: QuickCreatedState;
        id?: string;
        name?: string;
        code?: string;
        gst_code?: string;
    };
    const row = record.data ?? record;
    if (
        typeof row.id !== 'string' ||
        typeof row.name !== 'string' ||
        typeof row.code !== 'string' ||
        typeof row.gst_code !== 'string'
    ) {
        return null;
    }
    return {
        id: row.id,
        name: row.name,
        code: row.code,
        gst_code: row.gst_code,
    };
}

/**
 * Platform State quick-create for geography selectors and admin register.
 */
export function QuickCreateStateSheet({
    open,
    onOpenChange,
    storeUrl,
    onCreated,
    nested = false,
    catalogueOnly = false,
}: Props) {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(() => {
        const fields: FieldReview[] = [
            { label: 'State name', value: draft.name },
        ];
        if (!catalogueOnly) {
            fields.push(
                { label: 'State code', value: draft.code },
                { label: 'GST state code', value: draft.gst_code },
                { label: 'Country', value: draft.country_code },
                {
                    label: 'Active',
                    value: draft.is_active ? 'Yes' : 'No',
                },
            );
        }
        return fields;
    }, [catalogueOnly, draft]);

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
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload = catalogueOnly
            ? { name: draft.name.trim() }
            : {
                  name: draft.name.trim(),
                  code: draft.code.trim(),
                  gst_code: draft.gst_code.trim(),
                  country_code: draft.country_code.trim() || 'IN',
                  is_active: draft.is_active,
              };

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
            } | null;

            if (!response.ok) {
                if (json?.errors) {
                    setErrors(json.errors);
                }
                setFormError(
                    json?.message ??
                        (response.status === 422
                            ? 'Please correct the highlighted fields.'
                            : 'Unable to create state.'),
                );
                setConfirmOpen(false);
                return;
            }

            const created = parseCreatedState(json);
            if (!created) {
                setFormError('State created but response was incomplete.');
                setConfirmOpen(false);
                return;
            }

            onCreated(created);
            handleOpenChange(false);
        } catch {
            setFormError('Unable to create state.');
            setConfirmOpen(false);
        } finally {
            setProcessing(false);
        }
    };

    const sheetSurface = nested
        ? 'z-[80] flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg'
        : 'flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg';

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent side="right" className={sheetSurface}>
                    <SheetHeader className="border-b border-border p-4 text-left">
                        <SheetTitle>Add state</SheetTitle>
                        <SheetDescription>
                            {catalogueOnly
                                ? 'Add an approved Indian State or Union Territory. GST jurisdiction codes are assigned from the official catalogue.'
                                : 'Create a platform state record. It becomes available in geography selectors immediately.'}
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        className="flex flex-1 flex-col gap-4 p-4"
                        onSubmit={openConfirm}
                        noValidate
                    >
                        {formError ? (
                            <p
                                className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                                role="alert"
                            >
                                {formError}
                            </p>
                        ) : null}

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="quick-state-name">
                                    State name *
                                </Label>
                                <Input
                                    id="quick-state-name"
                                    value={draft.name}
                                    onChange={(event) =>
                                        update('name', event.target.value)
                                    }
                                    required
                                    maxLength={120}
                                    autoFocus
                                />
                                <InputError
                                    message={firstError(errors, 'name')}
                                />
                            </div>
                            {!catalogueOnly ? (
                                <>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-state-code">
                                            State code *
                                        </Label>
                                        <Input
                                            id="quick-state-code"
                                            value={draft.code}
                                            onChange={(event) =>
                                                update(
                                                    'code',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            maxLength={16}
                                            className="font-mono uppercase"
                                        />
                                        <InputError
                                            message={firstError(errors, 'code')}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-state-gst">
                                            GST state code *
                                        </Label>
                                        <Input
                                            id="quick-state-gst"
                                            value={draft.gst_code}
                                            onChange={(event) =>
                                                update(
                                                    'gst_code',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                            maxLength={8}
                                            className="font-mono"
                                            placeholder="24"
                                        />
                                        <InputError
                                            message={firstError(
                                                errors,
                                                'gst_code',
                                            )}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-state-country">
                                            Country
                                        </Label>
                                        <Input
                                            id="quick-state-country"
                                            value={draft.country_code}
                                            onChange={(event) =>
                                                update(
                                                    'country_code',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={2}
                                            className="font-mono uppercase"
                                        />
                                        <InputError
                                            message={firstError(
                                                errors,
                                                'country_code',
                                            )}
                                        />
                                    </div>
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="checkbox"
                                            checked={draft.is_active}
                                            onChange={(event) =>
                                                update(
                                                    'is_active',
                                                    event.target.checked,
                                                )
                                            }
                                            className="size-4 rounded border border-input"
                                        />
                                        Active
                                    </label>
                                </>
                            ) : null}
                        </div>

                        <SheetFooter className="mt-auto gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Review & create
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Confirm create state"
                description="Review the new state before saving."
                mode="create"
                fields={reviewFields}
                processing={processing}
                onConfirm={() => {
                    void submit();
                }}
            />
        </>
    );
}
