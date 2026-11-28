import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import {
    QuickCreateCatalogueSheet,
    type QuickCreatedCatalogueItem,
} from '@/components/masters/quick-create-catalogue-sheet';
import {
    QuickCreateTyreLayoutSheet,
    type QuickCreatedTyreLayout,
} from '@/components/masters/quick-create-tyre-layout-sheet';
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
import { usePage } from '@inertiajs/react';
import { getCsrfToken } from '@/lib/csrf';
import type { WorkspaceShare } from '@/types/workspace';
import { useEffect, useMemo, useState } from 'react';

export type VehicleQuickCreateCatalogues = {
    /** Truck Types catalogue entitlement — Market vehicles show Truck Type only when available. */
    truckTypesAvailable?: boolean;
    truckTypes: Array<{ id: string; name: string }>;
    tyreLayouts: Array<{ id: string; name: string }>;
    drivers: Array<{ id: string; name: string }>;
    truckTypesStoreUrl?: string;
    tyreLayoutsStoreUrl?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (vehicle: VehicleSelectOption) => void;
    partiesOptionsUrl?: string;
    partiesStoreUrl?: string | null;
    catalogues?: VehicleQuickCreateCatalogues;
    defaultOwnership?: 'market' | 'own';
    allowOwn?: boolean;
    allowMarket?: boolean;
};

type Draft = {
    registration_number: string;
    registration_date: string;
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
    registration_date: '',
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
    const page = usePage<{ workspace?: WorkspaceShare }>();
    const workspaceCaps = page.props.workspace?.capabilities;
    const allowedFromWorkspace = workspaceCaps?.allowed_vehicle_ownerships;
    const effectiveAllowOwn =
        allowOwn &&
        (allowedFromWorkspace === undefined ||
            allowedFromWorkspace.includes('own'));
    const effectiveAllowMarket =
        allowMarket &&
        (allowedFromWorkspace === undefined ||
            allowedFromWorkspace.includes('market'));
    const driversEnabled = workspaceCaps?.ownfleet_drivers_enabled !== false;

    const initialOwnership: 'market' | 'own' =
        defaultOwnership === 'own' && effectiveAllowOwn
            ? 'own'
            : effectiveAllowMarket
              ? 'market'
              : 'own';

    const onlyOneOwnership =
        (effectiveAllowOwn && !effectiveAllowMarket) ||
        (!effectiveAllowOwn && effectiveAllowMarket);

    const [draft, setDraft] = useState<Draft>({
        ...EMPTY,
        ownership: initialOwnership,
    });
    useEffect(() => {
        if (open) {
            setDraft((current) => {
                if (
                    (current.ownership === 'own' && !effectiveAllowOwn) ||
                    (current.ownership === 'market' && !effectiveAllowMarket)
                ) {
                    return { ...current, ownership: initialOwnership };
                }
                return current;
            });
        }
    }, [open, effectiveAllowOwn, effectiveAllowMarket, initialOwnership]);
    const [ownerParty, setOwnerParty] = useState<PartyOption | null>(null);
    const [driverParty, setDriverParty] = useState<PartyOption | null>(null);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const [truckTypes, setTruckTypes] = useState(
        () => catalogues?.truckTypes ?? [],
    );
    const [tyreLayouts, setTyreLayouts] = useState(
        () => catalogues?.tyreLayouts ?? [],
    );
    const drivers = catalogues?.drivers ?? [];
    const truckTypesStoreUrl = catalogues?.truckTypesStoreUrl;
    const tyreLayoutsStoreUrl = catalogues?.tyreLayoutsStoreUrl;
    const [catalogueQuickCreate, setCatalogueQuickCreate] = useState<
        'truck' | 'tyre' | null
    >(null);
    const isOwn = draft.ownership === 'own';
    const isMarket = draft.ownership === 'market';
    const showTruckType = isOwn || catalogues?.truckTypesAvailable === true;
    const showDriver = isMarket || driversEnabled;

    const reviewFields = useMemo<FieldReview[]>(() => {
        const driverName =
            driverParty?.name ??
            drivers.find((item) => item.id === draft.primary_driver_party_id)
                ?.name ??
            '';
        const candidates: FieldReview[] = [
            { label: 'Truck Number', value: draft.registration_number.trim() },
            { label: 'Ownership', value: isOwn ? 'Own' : 'Market' },
            {
                label: 'Truck Owner',
                value: isMarket ? (ownerParty?.name ?? '') : '',
            },
            {
                label: 'Truck Type',
                value:
                    truckTypes.find((item) => item.id === draft.truck_type_id)
                        ?.name ?? '',
            },
            {
                label: 'Tyre Layout',
                value: isOwn
                    ? (tyreLayouts.find(
                          (item) => item.id === draft.tyre_layout_id,
                      )?.name ?? '')
                    : '',
            },
            {
                label: isMarket ? 'Driver' : 'Primary Driver',
                value: driverName,
            },
            { label: 'Registration Date', value: draft.registration_date },
            {
                label: 'Vehicle type',
                value: isOwn ? draft.vehicle_type.trim() : '',
            },
            {
                label: 'Capacity (MT)',
                value: isOwn ? draft.capacity_mt.trim() : '',
            },
        ];

        return candidates.filter((field) => field.value !== '');
    }, [
        draft,
        driverParty,
        drivers,
        isMarket,
        isOwn,
        ownerParty,
        truckTypes,
        tyreLayouts,
    ]);

    const reset = () => {
        setDraft({ ...EMPTY, ownership: initialOwnership });
        setOwnerParty(null);
        setDriverParty(null);
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

        const common = {
            registration_number: draft.registration_number.trim().toUpperCase(),
            registration_date: draft.registration_date || null,
            truck_type_id: draft.truck_type_id || null,
            primary_driver_party_id: draft.primary_driver_party_id || null,
            is_active: true,
        };
        const payload = isMarket
            ? {
                  ...common,
                  ownership: 'market',
                  owner_party_id: draft.owner_party_id || null,
                  remark: 'Quick create market vehicle',
              }
            : {
                  ...common,
                  ownership: 'own',
                  tyre_layout_id: draft.tyre_layout_id,
                  vehicle_type: draft.vehicle_type.trim() || null,
                  capacity_mt: draft.capacity_mt.trim() || null,
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
                                <Label htmlFor="quick-vehicle-reg" required>
                                    Truck Number
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
                                <Label
                                    htmlFor="quick-vehicle-ownership"
                                    required
                                >
                                    Ownership
                                </Label>
                                {onlyOneOwnership ? (
                                    <div
                                        id="quick-vehicle-ownership"
                                        className="flex h-9 w-full items-center rounded-md border border-input bg-muted/40 px-3 text-sm font-medium text-foreground"
                                    >
                                        {draft.ownership === 'own'
                                            ? 'Own'
                                            : 'Market'}
                                    </div>
                                ) : (
                                    <select
                                        id="quick-vehicle-ownership"
                                        required
                                        className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        value={draft.ownership}
                                        onChange={(event) => {
                                            const next = event.target.value as
                                                | 'market'
                                                | 'own';
                                            update('ownership', next);
                                            setOwnerParty(null);
                                            setDriverParty(null);
                                            update('owner_party_id', '');
                                            update('truck_type_id', '');
                                            update('tyre_layout_id', '');
                                            update(
                                                'primary_driver_party_id',
                                                '',
                                            );
                                        }}
                                    >
                                        {effectiveAllowMarket ? (
                                            <option value="market">
                                                Market
                                            </option>
                                        ) : null}
                                        {effectiveAllowOwn ? (
                                            <option value="own">Own</option>
                                        ) : null}
                                    </select>
                                )}
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

                            {showTruckType ? (
                                <div className="space-y-1.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <Label
                                            htmlFor="quick-truck-type"
                                            required={isOwn}
                                        >
                                            Truck type
                                        </Label>
                                        {truckTypesStoreUrl ? (
                                            <button
                                                type="button"
                                                className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                onClick={() =>
                                                    setCatalogueQuickCreate(
                                                        'truck',
                                                    )
                                                }
                                            >
                                                + Add
                                            </button>
                                        ) : null}
                                    </div>
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
                                        required={isOwn}
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
                            ) : null}

                            {isOwn ? (
                                <>
                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between gap-2">
                                            <Label
                                                htmlFor="quick-tyre-layout"
                                                required
                                            >
                                                Tyre layout
                                            </Label>
                                            {tyreLayoutsStoreUrl ? (
                                                <button
                                                    type="button"
                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                    onClick={() =>
                                                        setCatalogueQuickCreate(
                                                            'tyre',
                                                        )
                                                    }
                                                >
                                                    + Add
                                                </button>
                                            ) : null}
                                        </div>
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
                                </>
                            ) : null}

                            {showDriver ? (
                                <div className="space-y-1.5 sm:col-span-2">
                                    <SearchablePartySelect
                                        id="quick-primary-driver"
                                        name="quick_primary_driver"
                                        label={
                                            isMarket
                                                ? 'Driver'
                                                : 'Primary Driver'
                                        }
                                        options={
                                            driverParty
                                                ? [
                                                      driverParty,
                                                      ...drivers.filter(
                                                          (d) =>
                                                              d.id !==
                                                              driverParty.id,
                                                      ),
                                                  ]
                                                : drivers
                                        }
                                        searchUrl={partiesOptionsUrl}
                                        role="driver"
                                        createStoreUrl={partiesStoreUrl}
                                        createLabel="Add"
                                        createRole="driver"
                                        value={draft.primary_driver_party_id}
                                        onValueChange={(value) =>
                                            update(
                                                'primary_driver_party_id',
                                                value,
                                            )
                                        }
                                        onPartyChange={setDriverParty}
                                        emptyMessage="Search eligible Driver parties…"
                                        error={firstError(
                                            errors,
                                            'primary_driver_party_id',
                                        )}
                                    />
                                </div>
                            ) : null}

                            <div className="space-y-1.5">
                                <Label htmlFor="quick-vehicle-registration-date">
                                    Registration Date
                                </Label>
                                <Input
                                    id="quick-vehicle-registration-date"
                                    type="date"
                                    value={draft.registration_date}
                                    onChange={(event) =>
                                        update(
                                            'registration_date',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={firstError(
                                        errors,
                                        'registration_date',
                                    )}
                                />
                            </div>

                            {isOwn ? (
                                <>
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
                title="Confirm create truck"
                description="Review the new vehicle before saving. The Bilty form stays open."
                mode="create"
                fields={reviewFields}
                processing={processing}
                onConfirm={() => {
                    void submit();
                }}
            />

            {catalogueQuickCreate === 'truck' ? (
                <QuickCreateCatalogueSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setCatalogueQuickCreate(null);
                        }
                    }}
                    title="Truck Type"
                    storeUrl={truckTypesStoreUrl ?? ''}
                    onCreated={(item: QuickCreatedCatalogueItem) => {
                        const option = { id: item.id, name: item.name };
                        setTruckTypes((current) =>
                            current.some((entry) => entry.id === item.id)
                                ? current
                                : [...current, option],
                        );
                        update('truck_type_id', item.id);
                        setCatalogueQuickCreate(null);
                    }}
                />
            ) : null}

            {catalogueQuickCreate === 'tyre' ? (
                <QuickCreateTyreLayoutSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setCatalogueQuickCreate(null);
                        }
                    }}
                    storeUrl={tyreLayoutsStoreUrl ?? ''}
                    onCreated={(item: QuickCreatedTyreLayout) => {
                        const option = { id: item.id, name: item.name };
                        setTyreLayouts((current) =>
                            current.some((entry) => entry.id === item.id)
                                ? current
                                : [...current, option],
                        );
                        update('tyre_layout_id', item.id);
                        setCatalogueQuickCreate(null);
                    }}
                />
            ) : null}
        </>
    );
}
