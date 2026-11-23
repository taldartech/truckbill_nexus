import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Party = {
    id: string;
    name: string;
    contact_person: string | null;
    contact_number: string | null;
    email: string | null;
    gstin: string | null;
    pan: string | null;
    is_gst_applicable: boolean;
    state: string | null;
    city: string | null;
    address: string | null;
    scope: string;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    mode: 'create' | 'edit';
    party: Party | null;
    dataVisibility?: { mode: string; label: string; message: string };
    roleOptions: { value: string; label: string }[];
    links: {
        hub: string;
        dashboard: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

export default function PartyForm({
    company,
    branch,
    mode,
    party,
    dataVisibility,
    roleOptions,
    links,
}: Props) {
    const [selectedRoles, setSelectedRoles] = useState<string[]>(
        mode === 'edit' ? [] : ['customer'],
    );
    const [driverKind, setDriverKind] = useState('permanent');

    const toggleRole = (value: string) => {
        setSelectedRoles((current) =>
            current.includes(value)
                ? current.filter((role) => role !== value)
                : [...current, value],
        );
    };

    const action = mode === 'create' ? links.store! : links.update!;
    const method = mode === 'create' ? 'post' : 'patch';

    return (
        <>
            <Head
                title={
                    mode === 'create' ? 'Create party' : `Edit ${party?.name}`
                }
            />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Party Master"
                    title={
                        mode === 'create'
                            ? 'Create party'
                            : `Edit ${party?.name}`
                    }
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Branch', href: links.dashboard },
                        { label: 'Parties', href: links.index },
                        { label: mode === 'create' ? 'Create' : 'Edit' },
                    ]}
                />
                <Form
                    action={action}
                    method={method}
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
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={party?.name ?? ''}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="contact_person">
                                        Contact person
                                    </Label>
                                    <Input
                                        id="contact_person"
                                        name="contact_person"
                                        defaultValue={
                                            party?.contact_person ?? ''
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="contact_number">
                                        Contact number
                                    </Label>
                                    <Input
                                        id="contact_number"
                                        name="contact_number"
                                        defaultValue={
                                            party?.contact_number ?? ''
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={party?.email ?? ''}
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="gstin">GSTIN</Label>
                                    <Input
                                        id="gstin"
                                        name="gstin"
                                        defaultValue={party?.gstin ?? ''}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="pan">PAN</Label>
                                    <Input
                                        id="pan"
                                        name="pan"
                                        defaultValue={party?.pan ?? ''}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="state">State</Label>
                                    <Input
                                        id="state"
                                        name="state"
                                        defaultValue={party?.state ?? ''}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="city">City</Label>
                                    <Input
                                        id="city"
                                        name="city"
                                        defaultValue={party?.city ?? ''}
                                    />
                                </div>
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="address">Address</Label>
                                    <Input
                                        id="address"
                                        name="address"
                                        defaultValue={party?.address ?? ''}
                                    />
                                </div>
                            </div>

                            {mode === 'create' ? (
                                <fieldset className="space-y-3 rounded-xl border border-border p-4">
                                    <legend className="px-1 text-sm font-medium">
                                        Roles
                                    </legend>
                                    <InputError message={errors.roles} />
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {roleOptions.map((option) => {
                                            const checked =
                                                selectedRoles.includes(
                                                    option.value,
                                                );
                                            return (
                                                <label
                                                    key={option.value}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() =>
                                                            toggleRole(
                                                                option.value,
                                                            )
                                                        }
                                                    />
                                                    {option.label}
                                                    {checked ? (
                                                        <input
                                                            type="hidden"
                                                            name={`roles[${selectedRoles.indexOf(option.value)}][role]`}
                                                            value={option.value}
                                                        />
                                                    ) : null}
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {selectedRoles.includes('driver') ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="driver_kind">
                                                Driver kind
                                            </Label>
                                            <select
                                                id="driver_kind"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={driverKind}
                                                onChange={(e) =>
                                                    setDriverKind(
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="permanent">
                                                    Permanent
                                                </option>
                                                <option value="market">
                                                    Market
                                                </option>
                                            </select>
                                            <input
                                                type="hidden"
                                                name={`roles[${selectedRoles.indexOf('driver')}][attributes][driver_kind]`}
                                                value={driverKind}
                                            />
                                        </div>
                                    ) : null}
                                    {selectedRoles.includes('transporter') ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="transport_licence">
                                                Transport licence
                                            </Label>
                                            <Input
                                                id="transport_licence"
                                                name={`roles[${selectedRoles.indexOf('transporter')}][attributes][transport_licence]`}
                                            />
                                        </div>
                                    ) : null}
                                </fieldset>
                            ) : null}

                            <div className="space-y-2">
                                <Label htmlFor="remark">Remark</Label>
                                <Input
                                    id="remark"
                                    name="remark"
                                    required={mode === 'edit'}
                                    defaultValue={
                                        mode === 'create' ? 'Create party' : ''
                                    }
                                />
                                <InputError message={errors.remark} />
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        (mode === 'create' &&
                                            selectedRoles.length === 0)
                                    }
                                >
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
