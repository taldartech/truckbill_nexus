import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import {
    SearchablePartySelect,
    type PartyOption,
} from '@/components/searchable-party-select';
import type { VehicleSelectOption } from '@/components/searchable-vehicle-select';
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

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (vehicle: VehicleSelectOption) => void;
    partiesOptionsUrl?: string;
    partiesStoreUrl?: string;
};

type Draft = {
    registration_number: string;
    vehicle_type: string;
    capacity_mt: string;
    owner_party_id: string;
};

const EMPTY: Draft = {
    registration_number: '',
    vehicle_type: '',
    capacity_mt: '',
    owner_party_id: '',
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

/**
 * Inline Vehicle master create for operational selectors (legacy quick-add truck).
 */
export function QuickCreateVehicleSheet({
    open,
    onOpenChange,
    storeUrl,
    onCreated,
    partiesOptionsUrl,
    partiesStoreUrl,
}: Props) {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [ownerParty, setOwnerParty] = useState<PartyOption | null>(null);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(
        () => [
            {
                label: 'Registration number',
                value: draft.registration_number,
            },
            {
                label: 'Ownership',
                value: 'Market',
            },
            {
                label: 'Truck owner',
                value: ownerParty?.name ?? (draft.owner_party_id || '—'),
            },
            { label: 'Vehicle type', value: draft.vehicle_type || '—' },
            { label: 'Capacity (MT)', value: draft.capacity_mt || '—' },
        ],
        [draft, ownerParty],
    );

    const reset = () => {
        setDraft(EMPTY);
        setOwnerParty(null);
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
        if (draft.owner_party_id === '') {
            setFormError(
                'Truck Owner (Transporter) is required for Market vehicles.',
            );
            return;
        }
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload = {
            registration_number: draft.registration_number.trim().toUpperCase(),
            ownership: 'market',
            vehicle_type: draft.vehicle_type.trim() || null,
            capacity_mt: draft.capacity_mt.trim() || null,
            owner_party_id: draft.owner_party_id,
            is_active: true,
            remark: 'Quick create vehicle',
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
                data?: VehicleSelectOption;
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
                            : 'Unable to create vehicle.'),
                );
                setConfirmOpen(false);
                return;
            }

            const created = json?.data;
            if (!created?.id) {
                setFormError('Vehicle created but response was incomplete.');
                setConfirmOpen(false);
                return;
            }

            onCreated(created);
            handleOpenChange(false);
        } catch {
            setFormError('Unable to create vehicle.');
            setConfirmOpen(false);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg"
                >
                    <SheetHeader className="border-b border-border p-4 text-left">
                        <SheetTitle>Add Truck</SheetTitle>
                        <SheetDescription>
                            Create a branch-visible vehicle and return to the
                            current form with it selected.
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
                                <Label htmlFor="quick-vehicle-reg">
                                    Registration number *
                                </Label>
                                <Input
                                    id="quick-vehicle-reg"
                                    value={draft.registration_number}
                                    onChange={(event) =>
                                        update(
                                            'registration_number',
                                            event.target.value,
                                        )
                                    }
                                    className="uppercase"
                                    required
                                    autoFocus
                                />
                                <InputError
                                    message={firstError(
                                        errors,
                                        'registration_number',
                                    )}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-vehicle-ownership">
                                    Ownership
                                </Label>
                                <input
                                    type="hidden"
                                    name="ownership"
                                    value="market"
                                />
                                <div className="flex h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm font-medium">
                                    Market
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Own-fleet vehicles require Truck Type and
                                    Tyre Layout — use the full Vehicle form.
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-vehicle-type">
                                    Vehicle type
                                </Label>
                                <Input
                                    id="quick-vehicle-type"
                                    value={draft.vehicle_type}
                                    onChange={(event) =>
                                        update(
                                            'vehicle_type',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <SearchablePartySelect
                                    id="quick-vehicle-owner"
                                    name="quick_vehicle_owner"
                                    label="Truck Owner"
                                    options={ownerParty ? [ownerParty] : []}
                                    searchUrl={partiesOptionsUrl}
                                    role="transporter"
                                    createStoreUrl={partiesStoreUrl}
                                    createLabel="Add"
                                    createRole="transporter"
                                    required
                                    value={draft.owner_party_id}
                                    onValueChange={(value) =>
                                        update('owner_party_id', value)
                                    }
                                    onPartyChange={setOwnerParty}
                                    emptyMessage="Search eligible transporter owners…"
                                    error={firstError(errors, 'owner_party_id')}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-vehicle-capacity">
                                    Capacity (MT)
                                </Label>
                                <Input
                                    id="quick-vehicle-capacity"
                                    type="number"
                                    min="0"
                                    step="0.001"
                                    value={draft.capacity_mt}
                                    onChange={(event) =>
                                        update(
                                            'capacity_mt',
                                            event.target.value,
                                        )
                                    }
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
                title="Confirm create truck"
                description="Review the new vehicle before saving. The Bilty form stays open."
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
