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

export type VehicleQuickCreateCatalogues = {
    truckTypes: Array<{ id: string; name: string }>;
    tyreLayouts: Array<{ id: string; name: string }>;
    drivers: Array<{ id: string; name: string }>;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (vehicle: VehicleSelectOption) => void;
    partiesOptionsUrl?: string;
    partiesStoreUrl?: string;
    catalogues?: VehicleQuickCreateCatalogues;
    defaultOwnership?: 'market' | 'own';
    allowOwn?: boolean;
    allowMarket?: boolean;
};

type Draft = {
    registration_number: string;
    ownership: 'market' | 'own';
    vehicle_type: string;
    capacity_mt: string;
    owner_party_id: string;
    truck_type_id: string;
    tyre_layout_id: string;
    primary_driver_party_id: string;
};

const EMPTY: Draft = {
    registration_number: '',
    ownership: 'market',
    vehicle_type: '',
    capacity_mt: '',
    owner_party_id: '',
    truck_type_id: '',
    tyre_layout_id: '',
    primary_driver_party_id: '',
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
 * Inline Vehicle master create for operational selectors (Market + Own).
 */
export function QuickCreateVehicleSheet({
    open,
    onOpenChange,
    storeUrl,
    onCreated,
    partiesOptionsUrl,
    partiesStoreUrl,
    catalogues,
    defaultOwnership = 'market',
    allowOwn = true,
    allowMarket = true,
}: Props) {
    const initialOwnership =
        defaultOwnership === 'own' && allowOwn
            ? 'own'
            : allowMarket
              ? 'market'
              : 'own';

    const [draft, setDraft] = useState<Draft>({
        ...EMPTY,
        ownership: initialOwnership,
    });
    const [ownerParty, setOwnerParty] = useState<PartyOption | null>(null);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const truckTypes = catalogues?.truckTypes ?? [];
    const tyreLayouts = catalogues?.tyreLayouts ?? [];
    const drivers = catalogues?.drivers ?? [];
    const isOwn = draft.ownership === 'own';
    const isMarket = draft.ownership === 'market';

    const reviewFields = useMemo<FieldReview[]>(() => {
        const fields: FieldReview[] = [
            {
                label: 'Registration number',
                value: draft.registration_number,
            },
            {
                label: 'Ownership',
                value: isOwn ? 'Own' : 'Market',
            },
        ];
        if (isMarket) {
            fields.push({
                label: 'Truck owner',
                value: ownerParty?.name ?? (draft.owner_party_id || '—'),
            });
        }
        if (isOwn) {
            fields.push({
                label: 'Truck type',
                value:
                    truckTypes.find((item) => item.id === draft.truck_type_id)
                        ?.name ?? '—',
            });
            fields.push({
                label: 'Tyre layout',
                value:
                    tyreLayouts.find((item) => item.id === draft.tyre_layout_id)
                        ?.name ?? '—',
            });
            fields.push({
                label: 'Primary driver',
                value:
                    drivers.find(
                        (item) => item.id === draft.primary_driver_party_id,
                    )?.name ?? '—',
            });
        }
        fields.push({
            label: 'Vehicle type',
            value: draft.vehicle_type || '—',
        });
        fields.push({
            label: 'Capacity (MT)',
            value: draft.capacity_mt || '—',
        });
        return fields;
    }, [draft, drivers, isMarket, isOwn, ownerParty, truckTypes, tyreLayouts]);

    const reset = () => {
        setDraft({ ...EMPTY, ownership: initialOwnership });
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
        if (isMarket && draft.owner_party_id === '') {
            setFormError(
                'Truck Owner (Transporter) is required for Market vehicles.',
            );
            return;
        }
        if (
            isOwn &&
            (draft.truck_type_id === '' || draft.tyre_layout_id === '')
        ) {
            setFormError(
                'Truck Type and Tyre Layout are required for Own vehicles.',
            );
            return;
        }
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload =
            draft.ownership === 'market'
                ? {
                      registration_number: draft.registration_number
                          .trim()
                          .toUpperCase(),
                      ownership: 'market',
                      vehicle_type: draft.vehicle_type.trim() || null,
                      capacity_mt: draft.capacity_mt.trim() || null,
                      owner_party_id: draft.owner_party_id,
                      is_active: true,
                      remark: 'Quick create market vehicle',
                  }
                : {
                      registration_number: draft.registration_number
                          .trim()
                          .toUpperCase(),
                      ownership: 'own',
                      vehicle_type: draft.vehicle_type.trim() || null,
                      capacity_mt: draft.capacity_mt.trim() || null,
                      truck_type_id: draft.truck_type_id,
                      tyre_layout_id: draft.tyre_layout_id,
                      primary_driver_party_id:
                          draft.primary_driver_party_id || null,
                      is_active: true,
                      remark: 'Quick create own vehicle',
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
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="quick-vehicle-ownership">
                                    Ownership *
                                </Label>
                                <select
                                    id="quick-vehicle-ownership"
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    value={draft.ownership}
                                    onChange={(event) => {
                                        const next = event.target.value as
                                            | 'market'
                                            | 'own';
                                        update('ownership', next);
                                        setOwnerParty(null);
                                        update('owner_party_id', '');
                                        update('truck_type_id', '');
                                        update('tyre_layout_id', '');
                                        update('primary_driver_party_id', '');
                                    }}
                                >
                                    {allowMarket ? (
                                        <option value="market">Market</option>
                                    ) : null}
                                    {allowOwn ? (
                                        <option value="own">Own</option>
                                    ) : null}
                                </select>
                            </div>

                            {isMarket ? (
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
                                        error={firstError(
                                            errors,
                                            'owner_party_id',
                                        )}
                                    />
                                </div>
                            ) : null}

                            {isOwn ? (
                                <>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-truck-type">
                                            Truck type *
                                        </Label>
                                        <select
                                            id="quick-truck-type"
                                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            value={draft.truck_type_id}
                                            onChange={(event) =>
                                                update(
                                                    'truck_type_id',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">Select</option>
                                            {truckTypes.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={firstError(
                                                errors,
                                                'truck_type_id',
                                            )}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-tyre-layout">
                                            Tyre layout *
                                        </Label>
                                        <select
                                            id="quick-tyre-layout"
                                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            value={draft.tyre_layout_id}
                                            onChange={(event) =>
                                                update(
                                                    'tyre_layout_id',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">Select</option>
                                            {tyreLayouts.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={firstError(
                                                errors,
                                                'tyre_layout_id',
                                            )}
                                        />
                                    </div>
                                    <div className="space-y-1.5 sm:col-span-2">
                                        <Label htmlFor="quick-primary-driver">
                                            Primary driver
                                        </Label>
                                        <select
                                            id="quick-primary-driver"
                                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            value={
                                                draft.primary_driver_party_id
                                            }
                                            onChange={(event) =>
                                                update(
                                                    'primary_driver_party_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">None</option>
                                            {drivers.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={firstError(
                                                errors,
                                                'primary_driver_party_id',
                                            )}
                                        />
                                    </div>
                                </>
                            ) : null}

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
