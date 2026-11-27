import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { PageShell } from '@/components/page-shell';
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
import { MobileNumberInput } from '@/components/ui/mobile-number-input';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type BillingProfileData = {
    id: string;
    legal_name: string;
    pan: string;
    logo_url: string | null;
    contact_number: string;
    email: string;
    registered_address: string;
    city_id: string;
    city: string;
    state_id: string;
    state_name: string;
    state_code: string;
    pin_code: string;
    gstin: string;
    bank_name: string;
    bank_account_name: string;
    bank_account_number: string;
    bank_ifsc: string;
    website: string;
    notes_terms: string;
};

type Props = {
    mode: 'create' | 'edit';
    profile: BillingProfileData | null;
    links: {
        index: string;
        show?: string;
        submit: string;
    };
};

type DiffRow = {
    label: string;
    previous: string;
    next: string;
};

export default function BillingProfileForm({ mode, profile, links }: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const form = useForm<{
        legal_name: string;
        pan: string;
        logo: File | null;
        remove_logo: boolean;
        contact_number: string;
        email: string;
        registered_address: string;
        state_id: string;
        state_name: string;
        city_id: string;
        city: string;
        pin_code: string;
        gstin: string;
        bank_name: string;
        bank_account_name: string;
        bank_account_number: string;
        bank_ifsc: string;
        website: string;
        notes_terms: string;
        remark: string;
    }>({
        legal_name: profile?.legal_name ?? '',
        pan: profile?.pan ?? '',
        logo: null,
        remove_logo: false,
        contact_number: profile?.contact_number ?? '',
        email: profile?.email ?? '',
        registered_address: profile?.registered_address ?? '',
        state_id: profile?.state_id ?? '',
        state_name: profile?.state_name ?? '',
        city_id: profile?.city_id ?? '',
        city: profile?.city ?? '',
        pin_code: profile?.pin_code ?? '',
        gstin: profile?.gstin ?? '',
        bank_name: profile?.bank_name ?? '',
        bank_account_name: profile?.bank_account_name ?? '',
        bank_account_number: profile?.bank_account_number ?? '',
        bank_ifsc: profile?.bank_ifsc ?? '',
        website: profile?.website ?? '',
        notes_terms: profile?.notes_terms ?? '',
        remark: '',
    });

    const [confirmEditOpen, setConfirmEditOpen] = useState(false);

    const editDiffs = useMemo<DiffRow[]>(() => {
        if (mode !== 'edit' || !profile) {
            return [];
        }
        const pairs: [string, string, string][] = [
            ['Company Name', profile.legal_name, form.data.legal_name],
            ['PAN', profile.pan, form.data.pan.toUpperCase()],
            [
                'Contact Number',
                profile.contact_number,
                form.data.contact_number,
            ],
            ['Email', profile.email, form.data.email],
            [
                'Registered Address',
                profile.registered_address,
                form.data.registered_address,
            ],
            ['State', profile.state_name, form.data.state_name || '—'],
            ['City', profile.city, form.data.city || '—'],
            ['PIN Code', profile.pin_code, form.data.pin_code],
            [
                'GSTIN',
                profile.gstin || '—',
                form.data.gstin.toUpperCase() || '—',
            ],
            ['Bank Name', profile.bank_name || '—', form.data.bank_name || '—'],
            [
                'Account Holder Name',
                profile.bank_account_name || '—',
                form.data.bank_account_name || '—',
            ],
            [
                'Account Number',
                profile.bank_account_number || '—',
                form.data.bank_account_number || '—',
            ],
            [
                'IFSC Code',
                profile.bank_ifsc || '—',
                form.data.bank_ifsc.toUpperCase() || '—',
            ],
            ['Website', profile.website || '—', form.data.website || '—'],
            [
                'Notes / Terms',
                profile.notes_terms || '—',
                form.data.notes_terms || '—',
            ],
        ];

        const changed = pairs
            .filter(([, prevVal, nextVal]) => prevVal.trim() !== nextVal.trim())
            .map(([label, previous, next]) => ({ label, previous, next }));

        if (form.data.logo) {
            changed.push({
                label: 'Company Logo',
                previous: profile.logo_url ? 'Existing logo' : 'None',
                next: `New upload: ${form.data.logo.name}`,
            });
        } else if (form.data.remove_logo && profile.logo_url) {
            changed.push({
                label: 'Company Logo',
                previous: 'Existing logo',
                next: 'Removed',
            });
        }

        return changed;
    }, [mode, profile, form.data]);

    const submitCreate = () => {
        form.post(links.submit, {
            forceFormData: true,
        });
    };

    const submitEdit = () => {
        if (form.data.remark.trim() === '') {
            return;
        }
        router.post(
            links.submit,
            {
                ...form.data,
                _method: 'put',
            },
            {
                forceFormData: true,
                onFinish: () => {
                    setConfirmEditOpen(false);
                },
            },
        );
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        if (mode === 'create') {
            submitCreate();
            return;
        }
        setConfirmEditOpen(true);
    };

    return (
        <>
            <Head
                title={
                    mode === 'create'
                        ? 'Add Billing Profile'
                        : `Edit ${profile?.legal_name ?? 'Billing Profile'}`
                }
            />
            <PageShell
                title={
                    mode === 'create'
                        ? 'Create Billing Profile'
                        : `Edit Billing Profile · ${profile?.legal_name ?? ''}`
                }
                description={
                    mode === 'create'
                        ? 'Register a TruckBill billing entity for subscription tax invoice issuance.'
                        : 'Changes require confirmation and audit remark. Historical approved invoices remain immutable.'
                }
                actions={
                    <div className="flex flex-wrap gap-2">
                        {links.show ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.show}>Back to Profile</Link>
                            </Button>
                        ) : null}
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.index}>All Billing Profiles</Link>
                        </Button>
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} />

                <form
                    onSubmit={handleSubmit}
                    className="w-full space-y-6 rounded-xl border border-border bg-card p-6 shadow-xs"
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="legal_name" required>
                                Company Name
                            </Label>
                            <Input
                                id="legal_name"
                                required
                                value={form.data.legal_name}
                                onChange={(e) =>
                                    form.setData('legal_name', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="pan" required>
                                PAN
                            </Label>
                            <Input
                                id="pan"
                                required
                                maxLength={10}
                                className="font-mono uppercase"
                                placeholder="AABCT1234F"
                                value={form.data.pan}
                                onChange={(e) =>
                                    form.setData(
                                        'pan',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="logo">Company Logo</Label>
                            <Input
                                id="logo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    form.setData((prev) => ({
                                        ...prev,
                                        logo: file,
                                        remove_logo: false,
                                    }));
                                }}
                            />
                            {mode === 'edit' && profile?.logo_url ? (
                                <div className="flex items-center gap-3 pt-1">
                                    <img
                                        src={profile.logo_url}
                                        alt="Current logo"
                                        className="h-9 max-w-[140px] rounded border border-border bg-background object-contain p-0.5"
                                    />
                                    <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            checked={form.data.remove_logo}
                                            onChange={(e) =>
                                                form.setData(
                                                    'remove_logo',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Remove existing logo
                                    </label>
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="contact_number" required>
                                Contact Number
                            </Label>
                            <MobileNumberInput
                                id="contact_number"
                                required
                                placeholder="9876543210"
                                value={form.data.contact_number}
                                onChange={(e) =>
                                    form.setData(
                                        'contact_number',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="email" required>
                                Email
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="website">Website</Label>
                            <Input
                                id="website"
                                type="url"
                                placeholder="https://truckbill.in"
                                value={form.data.website}
                                onChange={(e) =>
                                    form.setData('website', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5 sm:col-span-2 lg:col-span-3">
                            <Label htmlFor="registered_address" required>
                                Registered Address
                            </Label>
                            <Input
                                id="registered_address"
                                required
                                value={form.data.registered_address}
                                onChange={(e) =>
                                    form.setData(
                                        'registered_address',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>

                        <div className="sm:col-span-2">
                            <StateCityFields
                                valueMode="ids"
                                required
                                stateId={form.data.state_id}
                                cityId={form.data.city_id}
                                stateName={form.data.state_name}
                                cityName={form.data.city}
                                stateError={errors?.state_id}
                                cityError={errors?.city_id}
                                onChange={(geo) =>
                                    form.setData((prev) => ({
                                        ...prev,
                                        state_id: geo.state_id,
                                        city_id: geo.city_id,
                                        state_name: geo.state,
                                        city: geo.city,
                                    }))
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="pin_code" required>
                                PIN Code
                            </Label>
                            <Input
                                id="pin_code"
                                required
                                maxLength={6}
                                placeholder="411001"
                                value={form.data.pin_code}
                                onChange={(e) =>
                                    form.setData('pin_code', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5 sm:col-span-2 lg:col-span-2">
                            <Label htmlFor="gstin">GSTIN</Label>
                            <Input
                                id="gstin"
                                maxLength={15}
                                className="font-mono uppercase"
                                placeholder="27AABCT1234F1Z5"
                                value={form.data.gstin}
                                onChange={(e) =>
                                    form.setData(
                                        'gstin',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="bank_name">Bank Name</Label>
                            <Input
                                id="bank_name"
                                value={form.data.bank_name}
                                onChange={(e) =>
                                    form.setData('bank_name', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="bank_account_name">
                                Account Holder Name
                            </Label>
                            <Input
                                id="bank_account_name"
                                value={form.data.bank_account_name}
                                onChange={(e) =>
                                    form.setData(
                                        'bank_account_name',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="bank_account_number">
                                Account Number
                            </Label>
                            <Input
                                id="bank_account_number"
                                value={form.data.bank_account_number}
                                onChange={(e) =>
                                    form.setData(
                                        'bank_account_number',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="bank_ifsc">IFSC Code</Label>
                            <Input
                                id="bank_ifsc"
                                maxLength={11}
                                className="font-mono uppercase"
                                placeholder="HDFC0001234"
                                value={form.data.bank_ifsc}
                                onChange={(e) =>
                                    form.setData(
                                        'bank_ifsc',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5 sm:col-span-2 lg:col-span-3">
                            <Label htmlFor="notes_terms">Notes / Terms</Label>
                            <textarea
                                id="notes_terms"
                                rows={3}
                                className={textareaClass}
                                placeholder="Invoice footer notes or payment terms"
                                value={form.data.notes_terms}
                                onChange={(e) =>
                                    form.setData('notes_terms', e.target.value)
                                }
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-3 border-t border-border pt-4">
                        <Button asChild type="button" variant="outline">
                            <Link href={links.show ?? links.index}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {mode === 'create'
                                ? form.processing
                                    ? 'Creating…'
                                    : 'Create Billing Profile'
                                : 'Review & Confirm Update'}
                        </Button>
                    </div>
                </form>

                {mode === 'edit' ? (
                    <Dialog
                        open={confirmEditOpen}
                        onOpenChange={setConfirmEditOpen}
                    >
                        <DialogContent className="max-w-2xl">
                            <DialogHeader>
                                <DialogTitle>
                                    Confirm Billing Profile Update
                                </DialogTitle>
                                <DialogDescription>
                                    Review previous and new field values and
                                    enter an edit remark. Historical approved
                                    invoices retain their original snapshot.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="max-h-64 overflow-y-auto rounded-lg border border-border">
                                {editDiffs.length > 0 ? (
                                    <table className="w-full text-left text-xs">
                                        <thead className="border-b border-border bg-muted/40">
                                            <tr>
                                                <th className="px-3 py-2">
                                                    Field
                                                </th>
                                                <th className="px-3 py-2">
                                                    Previous Value
                                                </th>
                                                <th className="px-3 py-2">
                                                    New Value
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {editDiffs.map((diff) => (
                                                <tr
                                                    key={diff.label}
                                                    className="border-b border-border last:border-0"
                                                >
                                                    <td className="px-3 py-2 font-medium">
                                                        {diff.label}
                                                    </td>
                                                    <td className="px-3 py-2 text-muted-foreground">
                                                        {diff.previous}
                                                    </td>
                                                    <td className="px-3 py-2 font-medium">
                                                        {diff.next}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                ) : (
                                    <p className="p-3 text-xs text-muted-foreground">
                                        No business field values changed.
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="edit_remark" required>
                                    Edit Remark
                                </Label>
                                <textarea
                                    id="edit_remark"
                                    required
                                    rows={2}
                                    className={textareaClass}
                                    placeholder="Reason for updating this Billing Profile"
                                    value={form.data.remark}
                                    onChange={(e) =>
                                        form.setData('remark', e.target.value)
                                    }
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConfirmEditOpen(false)}
                                >
                                    Back
                                </Button>
                                <Button
                                    type="button"
                                    disabled={
                                        form.processing ||
                                        form.data.remark.trim() === ''
                                    }
                                    onClick={submitEdit}
                                >
                                    {form.processing
                                        ? 'Saving…'
                                        : 'Confirm & Save Changes'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                ) : null}
            </PageShell>
        </>
    );
}
