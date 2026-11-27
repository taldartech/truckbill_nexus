import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { StateCityFields } from '@/components/geography/state-city-fields';
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
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';
import { usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export type QuickCreatedStation = {
    id: string;
    name: string;
};

type Draft = {
    name: string;
    state_id: string;
    city_id: string;
    state: string;
    city: string;
    is_active: boolean;
};

const EMPTY: Draft = {
    name: '',
    state_id: '',
    city_id: '',
    state: '',
    city: '',
    is_active: true,
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (station: QuickCreatedStation) => void;
    /** Show active toggle (station register). Operational quick-add keeps default active. */
    showActiveToggle?: boolean;
};

function parseCreatedStation(json: unknown): QuickCreatedStation | null {
    if (!json || typeof json !== 'object') {
        return null;
    }

    const payload = json as { data?: QuickCreatedStation };
    if (!payload.data?.id || !payload.data.name) {
        return null;
    }

    return {
        id: payload.data.id,
        name: payload.data.name,
    };
}

export function QuickCreateStationSheet({
    open,
    onOpenChange,
    storeUrl,
    onCreated,
    showActiveToggle = false,
}: Props) {
    const canQuickCreateGeography = readCanQuickCreateGeography(
        usePage().props,
    );
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(
        () => [
            { label: 'Name', value: draft.name },
            { label: 'State', value: draft.state || '—' },
            { label: 'City', value: draft.city || '—' },
            ...(showActiveToggle
                ? [
                      {
                          label: 'Status',
                          value: draft.is_active ? 'Active' : 'Inactive',
                      },
                  ]
                : []),
        ],
        [draft, showActiveToggle],
    );

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

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});
        if (draft.name.trim() === '') {
            setFormError('Station name is required.');
            return;
        }
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload: Record<string, string | boolean> = {
            name: draft.name.trim(),
            is_active: showActiveToggle ? draft.is_active : true,
        };
        if (draft.state_id !== '') {
            payload.state_id = draft.state_id;
        }
        if (draft.city_id !== '') {
            payload.city_id = draft.city_id;
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
            } | null;

            if (!response.ok) {
                if (json?.errors) {
                    setErrors(json.errors);
                }
                setFormError(
                    json?.message ??
                        'Could not create station. Check the form and try again.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            const created = parseCreatedStation(json);
            if (created === null) {
                setFormError(
                    'Station was created but the response was incomplete.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            onCreated(created);
            handleOpenChange(false);
        } catch {
            setFormError('Network error while creating station.');
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
                        <SheetTitle>Add Station</SheetTitle>
                        <SheetDescription>
                            Create a station with name and geography. Station ID
                            is assigned automatically.
                        </SheetDescription>
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
                            <Label htmlFor="station-name" required>
                                Name
                            </Label>
                            <Input
                                id="station-name"
                                value={draft.name}
                                onChange={(event) =>
                                    setDraft((current) => ({
                                        ...current,
                                        name: event.target.value,
                                    }))
                                }
                                autoFocus
                            />
                            <InputError message={firstError('name')} />
                        </div>

                        <StateCityFields
                            required
                            canQuickCreate={canQuickCreateGeography}
                            stateId={draft.state_id}
                            cityId={draft.city_id}
                            stateName={draft.state}
                            cityName={draft.city}
                            stateError={firstError('state_id')}
                            cityError={firstError('city_id')}
                            onChange={(next) =>
                                setDraft((current) => ({
                                    ...current,
                                    state_id: next.state_id,
                                    city_id: next.city_id,
                                    state: next.state,
                                    city: next.city,
                                }))
                            }
                        />

                        {showActiveToggle ? (
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
                title="Confirm Station"
                description="Create this station and select it on the form."
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
