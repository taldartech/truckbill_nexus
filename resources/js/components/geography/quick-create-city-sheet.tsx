import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
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
import { useEffect, useMemo, useState } from 'react';

export type QuickCreatedCity = {
    id: string;
    name: string;
    state_id: string;
    state_name?: string;
};

type GeographyOption = {
    id: string;
    name: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    statesSearchUrl?: string;
    initialStateId?: string;
    initialStateName?: string;
    onCreated: (city: QuickCreatedCity) => void;
    nested?: boolean;
};

type Draft = {
    state_id: string;
    state_name: string;
    name: string;
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

function mapStates(json: unknown): ComboboxOption[] {
    const data = (json as { data?: GeographyOption[] })?.data ?? [];
    return data.map((row) => ({ id: row.id, label: row.name }));
}

function parseCreatedCity(json: unknown): QuickCreatedCity | null {
    if (typeof json !== 'object' || json === null) {
        return null;
    }
    const record = json as {
        data?: QuickCreatedCity;
        id?: string;
        name?: string;
        state_id?: string;
        state_name?: string;
    };
    const row = record.data ?? record;
    if (
        typeof row.id !== 'string' ||
        typeof row.name !== 'string' ||
        typeof row.state_id !== 'string'
    ) {
        return null;
    }
    return {
        id: row.id,
        name: row.name,
        state_id: row.state_id,
        state_name:
            typeof row.state_name === 'string' ? row.state_name : undefined,
    };
}

/**
 * Platform City quick-create for geography selectors and admin register.
 */
export function QuickCreateCitySheet({
    open,
    onOpenChange,
    storeUrl,
    statesSearchUrl = '/api/geography/states',
    initialStateId = '',
    initialStateName = '',
    onCreated,
    nested = false,
}: Props) {
    const [draft, setDraft] = useState<Draft>({
        state_id: initialStateId,
        state_name: initialStateName,
        name: '',
    });
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }
        setDraft((current) => ({
            ...current,
            state_id: initialStateId || current.state_id,
            state_name: initialStateName || current.state_name,
        }));
    }, [open, initialStateId, initialStateName]);

    const stateOptions: ComboboxOption[] = useMemo(() => {
        if (!draft.state_id || !draft.state_name) {
            return [];
        }
        return [{ id: draft.state_id, label: draft.state_name }];
    }, [draft.state_id, draft.state_name]);

    const reviewFields = useMemo<FieldReview[]>(
        () => [
            { label: 'State', value: draft.state_name || '—' },
            { label: 'City name', value: draft.name },
        ],
        [draft.name, draft.state_name],
    );

    const reset = () => {
        setDraft({
            state_id: '',
            state_name: '',
            name: '',
        });
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
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload = {
            state_id: draft.state_id.trim(),
            name: draft.name.trim(),
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
                            : 'Unable to create city.'),
                );
                setConfirmOpen(false);
                return;
            }

            const created = parseCreatedCity(json);
            if (!created) {
                setFormError('City created but response was incomplete.');
                setConfirmOpen(false);
                return;
            }

            onCreated(created);
            handleOpenChange(false);
        } catch {
            setFormError('Unable to create city.');
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
                        <SheetTitle>Add city</SheetTitle>
                        <SheetDescription>
                            Create a city under the selected state. It becomes
                            available in geography selectors immediately.
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

                        <div className="grid gap-3">
                            <SearchableCombobox
                                id="quick-city-state_id"
                                name="state_id"
                                label="State"
                                options={stateOptions}
                                value={draft.state_id}
                                required
                                error={firstError(errors, 'state_id')}
                                searchUrl={statesSearchUrl}
                                mapResponse={mapStates}
                                emptyMessage="No states found."
                                placeholder="Search state…"
                                onValueChange={(id, option) => {
                                    setDraft((current) => ({
                                        ...current,
                                        state_id: id,
                                        state_name: option?.label ?? '',
                                    }));
                                }}
                            />
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-city-name" required>
                                    City name
                                </Label>
                                <Input
                                    id="quick-city-name"
                                    value={draft.name}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            name: event.target.value,
                                        }))
                                    }
                                    required
                                    maxLength={120}
                                />
                                <InputError
                                    message={firstError(errors, 'name')}
                                />
                            </div>
                        </div>

                        <SheetFooter className="mt-auto gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    draft.state_id === '' ||
                                    draft.name.trim() === ''
                                }
                            >
                                Review & create
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Confirm create city"
                description="Review the new city before saving."
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
