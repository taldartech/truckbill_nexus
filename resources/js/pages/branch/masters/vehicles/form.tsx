import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Vehicle = {
    id: string;
    registration_number: string;
    vehicle_type: string | null;
    make: string | null;
    model: string | null;
    manufacture_year: number | null;
    chassis_number: string | null;
    engine_number: string | null;
    capacity_mt: string | number | null;
    is_active: boolean;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    mode: 'create' | 'edit';
    vehicle: Vehicle | null;
    dataVisibility?: { mode: string; label: string; message: string };
    links: {
        hub: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

export default function VehicleForm({
    company,
    branch,
    mode,
    vehicle,
    dataVisibility,
    links,
}: Props) {
    const action = mode === 'create' ? links.store! : links.update!;
    return (
        <>
            <Head
                title={
                    mode === 'create'
                        ? 'Create vehicle'
                        : `Edit ${vehicle?.registration_number}`
                }
            />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Vehicle Master"
                    title={
                        mode === 'create'
                            ? 'Create vehicle'
                            : `Edit ${vehicle?.registration_number}`
                    }
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Vehicles', href: links.index },
                        { label: mode },
                    ]}
                />
                <Form
                    action={action}
                    method={mode === 'create' ? 'post' : 'patch'}
                    className="mx-auto grid w-full max-w-3xl gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {mode === 'create' && dataVisibility ? (
                                <p
                                    role="status"
                                    className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                                >
                                    {dataVisibility.message}
                                </p>
                            ) : null}
                            <div className="grid gap-4 sm:grid-cols-2">
                                {[
                                    [
                                        'registration_number',
                                        'Registration number',
                                        vehicle?.registration_number ?? '',
                                    ],
                                    [
                                        'vehicle_type',
                                        'Vehicle type',
                                        vehicle?.vehicle_type ?? '',
                                    ],
                                    ['make', 'Make', vehicle?.make ?? ''],
                                    ['model', 'Model', vehicle?.model ?? ''],
                                    [
                                        'manufacture_year',
                                        'Manufacture year',
                                        vehicle?.manufacture_year?.toString() ??
                                            '',
                                    ],
                                    [
                                        'chassis_number',
                                        'Chassis number',
                                        vehicle?.chassis_number ?? '',
                                    ],
                                    [
                                        'engine_number',
                                        'Engine number',
                                        vehicle?.engine_number ?? '',
                                    ],
                                    [
                                        'capacity_mt',
                                        'Capacity (MT)',
                                        vehicle?.capacity_mt?.toString() ?? '',
                                    ],
                                ].map(([name, label, value]) => (
                                    <div key={name} className="space-y-2">
                                        <Label htmlFor={name}>{label}</Label>
                                        <Input
                                            id={name}
                                            name={name}
                                            defaultValue={value}
                                            required={
                                                name === 'registration_number'
                                            }
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    defaultChecked={vehicle?.is_active ?? true}
                                />
                                Active
                            </label>
                            <div className="space-y-2">
                                <Label htmlFor="remark">Remark</Label>
                                <Input
                                    id="remark"
                                    name="remark"
                                    required={mode === 'edit'}
                                    defaultValue={
                                        mode === 'create'
                                            ? 'Create vehicle'
                                            : ''
                                    }
                                />
                                <InputError message={errors.remark} />
                            </div>
                            <div className="flex gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : 'Save'}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={links.show ?? links.index}>
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
