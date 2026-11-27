import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import {
    SearchablePartySelect,
    type PartyOption,
} from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type DriverAssignmentVehicleOption = {
    id: string;
    registration_number: string;
    primary_driver_party_id?: string | null;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignBaseUrl?: string;
    fixedVehicle?: {
        id: string;
        registration_number: string;
        current_driver_name?: string | null;
    } | null;
    vehicleOptions?: DriverAssignmentVehicleOption[];
    driverOptions: PartyOption[];
    partiesOptionsUrl?: string;
    partiesStoreUrl?: string;
    canCreateParty?: boolean;
    defaultDate: string;
    mode?: 'assign' | 'change';
};

export function VehicleDriverAssignmentModal({
    open,
    onOpenChange,
    assignBaseUrl = '/vehicle-drivers',
    fixedVehicle = null,
    vehicleOptions = [],
    driverOptions,
    partiesOptionsUrl,
    partiesStoreUrl,
    canCreateParty = false,
    defaultDate,
    mode = 'assign',
}: Props) {
    const [vehicleId, setVehicleId] = useState(
        fixedVehicle?.id ?? vehicleOptions[0]?.id ?? '',
    );
    const [driverPartyId, setDriverPartyId] = useState('');
    const [assignedFrom, setAssignedFrom] = useState(defaultDate);
    const [assignedTo, setAssignedTo] = useState('');
    const [remarks, setRemarks] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (open) {
            setVehicleId(fixedVehicle?.id ?? vehicleOptions[0]?.id ?? '');
            setDriverPartyId('');
            setAssignedFrom(defaultDate);
            setAssignedTo('');
            setRemarks('');
            setErrors({});
        }
    }, [open, fixedVehicle?.id, vehicleOptions, defaultDate]);

    const vehicleComboboxOptions: ComboboxOption[] = vehicleOptions.map(
        (v) => ({
            id: v.id,
            label: v.registration_number,
        }),
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const targetVehicleId = fixedVehicle?.id ?? vehicleId;
        if (!targetVehicleId) {
            setErrors({ vehicle_id: 'Select an Own Vehicle.' });
            return;
        }
        if (!driverPartyId) {
            setErrors({ driver_party_id: 'Select a Driver.' });
            return;
        }

        setProcessing(true);
        setErrors({});

        router.post(
            `${assignBaseUrl}/${targetVehicleId}/assign`,
            {
                driver_party_id: driverPartyId,
                assigned_from: assignedFrom,
                assigned_to: assignedTo || null,
                remarks: remarks || null,
            },
            {
                preserveScroll: true,
                onError: (serverErrors) => {
                    setErrors(serverErrors as Record<string, string>);
                },
                onSuccess: () => {
                    onOpenChange(false);
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    };

    const title =
        mode === 'change' ? 'Change Primary Driver' : 'Assign Primary Driver';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {fixedVehicle
                            ? `Assign or replace the Primary Driver for ${fixedVehicle.registration_number}. Prior assignment history and recorded Trips are preserved.`
                            : 'Select an eligible Own Vehicle and Driver-role Party. Any active primary driver on this vehicle will be closed one day before the new effective date.'}
                    </DialogDescription>
                </DialogHeader>

                <form className="space-y-4" onSubmit={submit} noValidate>
                    {fixedVehicle ? (
                        <div className="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm">
                            <div className="font-medium">
                                {fixedVehicle.registration_number}
                            </div>
                            {fixedVehicle.current_driver_name ? (
                                <div className="text-xs text-muted-foreground">
                                    Current primary driver:{' '}
                                    {fixedVehicle.current_driver_name}
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <div className="space-y-1.5">
                            <SearchableCombobox
                                id="assign_vehicle_id"
                                name="vehicle_id"
                                label="Own Vehicle"
                                options={vehicleComboboxOptions}
                                value={vehicleId}
                                onValueChange={setVehicleId}
                                required
                                clearable={false}
                                emptyMessage="No active Own Vehicles available."
                                error={errors.vehicle_id}
                            />
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <SearchablePartySelect
                            id="assign_driver_party_id"
                            name="driver_party_id"
                            label="Driver"
                            options={driverOptions}
                            searchUrl={partiesOptionsUrl}
                            role="driver"
                            createStoreUrl={
                                canCreateParty ? partiesStoreUrl : undefined
                            }
                            createLabel="Add"
                            createRole="driver"
                            value={driverPartyId}
                            onValueChange={setDriverPartyId}
                            required
                            emptyMessage="No active Driver-role Parties found."
                            error={errors.driver_party_id}
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="assign_from_date" required>
                                Effective Assignment Date
                            </Label>
                            <Input
                                id="assign_from_date"
                                type="date"
                                value={assignedFrom}
                                onChange={(e) =>
                                    setAssignedFrom(e.target.value)
                                }
                                required
                            />
                            <InputError message={errors.assigned_from} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="assign_to_date">
                                Effective To Date
                            </Label>
                            <Input
                                id="assign_to_date"
                                type="date"
                                value={assignedTo}
                                onChange={(e) => setAssignedTo(e.target.value)}
                            />
                            <InputError message={errors.assigned_to} />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="assign_remarks">Remarks</Label>
                        <Input
                            id="assign_remarks"
                            value={remarks}
                            onChange={(e) => setRemarks(e.target.value)}
                            placeholder="Optional assignment handover note"
                        />
                        <InputError message={errors.remarks} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing}
                        >
                            {processing ? 'Saving…' : 'Save Assignment'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function VehicleDriverRemoveModal({
    open,
    onOpenChange,
    removeUrl,
    registrationNumber,
    driverName,
    defaultDate,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    removeUrl: string;
    registrationNumber: string;
    driverName: string;
    defaultDate: string;
}) {
    const [effectiveDate, setEffectiveDate] = useState(defaultDate);
    const [remarks, setRemarks] = useState('');
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setEffectiveDate(defaultDate);
            setRemarks('');
        }
    }, [open, defaultDate]);

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        router.post(
            removeUrl,
            {
                effective_date: effectiveDate,
                remarks: remarks.trim() || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Remove Primary Driver</DialogTitle>
                    <DialogDescription>
                        End the active assignment for{' '}
                        <strong>{driverName}</strong> on{' '}
                        <strong>{registrationNumber}</strong>. Assignment
                        history and historical Trips remain intact.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-4" onSubmit={handleSubmit}>
                    <div className="space-y-1.5">
                        <Label htmlFor="modal_remove_effective_date" required>
                            Effective End Date
                        </Label>
                        <Input
                            id="modal_remove_effective_date"
                            type="date"
                            value={effectiveDate}
                            onChange={(e) => setEffectiveDate(e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="modal_remove_remarks">Remarks</Label>
                        <Input
                            id="modal_remove_remarks"
                            value={remarks}
                            onChange={(e) => setRemarks(e.target.value)}
                            placeholder="Reason for driver removal"
                        />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            {processing ? 'Removing…' : 'Confirm Remove'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
