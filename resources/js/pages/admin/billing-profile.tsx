import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type StateOption = {
    id: string;
    name: string;
    gst_state_code: string | null;
};

type BillingProfileData = {
    id: string;
    legal_name: string;
    trade_name: string | null;
    registered_address: string;
    state_id: string | null;
    state_name: string;
    state_code: string | null;
    city: string;
    pin_code: string;
    gstin: string | null;
    pan: string | null;
    contact_number: string;
    email: string;
    logo_url: string | null;
    bank_name: string | null;
    bank_account_name: string | null;
    bank_account_number: string | null;
    bank_ifsc: string | null;
    bank_branch: string | null;
    gst_rate_percent: string;
    sac_code: string;
    hsn_code: string | null;
    invoice_prefix: string;
    invoice_next_sequence: number;
    credit_note_prefix: string;
    credit_note_next_sequence: number;
};

type Props = {
    profile: BillingProfileData | null;
    states: StateOption[];
    canManage: boolean;
    links: { save: string; invoices: string };
};

export default function AdminBillingProfile({
    profile,
    states,
    canManage,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const form = useForm<{
        legal_name: string;
        trade_name: string;
        registered_address: string;
        state_id: string;
        state_name: string;
        state_code: string;
        city: string;
        pin_code: string;
        gstin: string;
        pan: string;
        contact_number: string;
        email: string;
        bank_name: string;
        bank_account_name: string;
        bank_account_number: string;
        bank_ifsc: string;
        bank_branch: string;
        gst_rate_percent: string;
        sac_code: string;
        hsn_code: string;
        invoice_prefix: string;
        invoice_next_sequence: number;
        credit_note_prefix: string;
        credit_note_next_sequence: number;
        logo: File | null;
        remark: string;
    }>({
        legal_name: profile?.legal_name ?? '',
        trade_name: profile?.trade_name ?? '',
        registered_address: profile?.registered_address ?? '',
        state_id: profile?.state_id ?? '',
        state_name: profile?.state_name ?? '',
        state_code: profile?.state_code ?? '',
        city: profile?.city ?? '',
        pin_code: profile?.pin_code ?? '',
        gstin: profile?.gstin ?? '',
        pan: profile?.pan ?? '',
        contact_number: profile?.contact_number ?? '',
        email: profile?.email ?? '',
        bank_name: profile?.bank_name ?? '',
        bank_account_name: profile?.bank_account_name ?? '',
        bank_account_number: profile?.bank_account_number ?? '',
        bank_ifsc: profile?.bank_ifsc ?? '',
        bank_branch: profile?.bank_branch ?? '',
        gst_rate_percent: profile?.gst_rate_percent ?? '18.00',
        sac_code: profile?.sac_code ?? '998314',
        hsn_code: profile?.hsn_code ?? '',
        invoice_prefix: profile?.invoice_prefix ?? 'TB',
        invoice_next_sequence: profile?.invoice_next_sequence ?? 1,
        credit_note_prefix: profile?.credit_note_prefix ?? 'TBCN',
        credit_note_next_sequence: profile?.credit_note_next_sequence ?? 1,
        logo: null,
        remark: '',
    });

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(links.save, {
            forceFormData: true,
        });
    };

    return (
        <>
            <Head title="Platform Billing Profile" />
            <PageShell
                title="Platform Billing Profile"
                description="Configure the authorized service provider legal entity, GST, bank, and invoice numbering details used for subscription invoices."
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={links.invoices}>Subscription Invoices</Link>
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={[
                        'billing_profile',
                        'legal_name',
                        'gstin',
                        'gst_rate_percent',
                        'remark',
                    ]}
                />

                <form
                    onSubmit={handleSubmit}
                    className="space-y-6 rounded-xl border border-border bg-card p-5 shadow-xs"
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="legal_name" required>
                                Legal Company Name
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
                            <Label htmlFor="trade_name">Trade Name</Label>
                            <Input
                                id="trade_name"
                                value={form.data.trade_name}
                                onChange={(e) =>
                                    form.setData('trade_name', e.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="email" required>
                                Billing Email
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

                        <div className="space-y-1.5">
                            <Label htmlFor="state_id" required>
                                State
                            </Label>
                            <select
                                id="state_id"
                                required
                                value={form.data.state_id}
                                onChange={(e) => {
                                    const st = states.find(
                                        (s) => s.id === e.target.value,
                                    );
                                    form.setData((prev) => ({
                                        ...prev,
                                        state_id: e.target.value,
                                        state_name: st?.name ?? prev.state_name,
                                        state_code:
                                            st?.gst_state_code ??
                                            prev.state_code,
                                    }));
                                }}
                                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">Select state</option>
                                {states.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                        {s.gst_state_code
                                            ? ` (${s.gst_state_code})`
                                            : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="city" required>
                                City
                            </Label>
                            <Input
                                id="city"
                                required
                                value={form.data.city}
                                onChange={(e) =>
                                    form.setData('city', e.target.value)
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
                                value={form.data.pin_code}
                                onChange={(e) =>
                                    form.setData('pin_code', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="gstin">GSTIN</Label>
                            <Input
                                id="gstin"
                                maxLength={15}
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
                            <Label htmlFor="pan">PAN</Label>
                            <Input
                                id="pan"
                                maxLength={10}
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
                            <Label htmlFor="contact_number" required>
                                Contact Number
                            </Label>
                            <Input
                                id="contact_number"
                                required
                                value={form.data.contact_number}
                                onChange={(e) =>
                                    form.setData(
                                        'contact_number',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>

                    <div className="border-t border-border pt-4">
                        <h2 className="mb-3 text-sm font-semibold">
                            Tax, SAC/HSN & Invoice Numbering
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="gst_rate_percent" required>
                                    GST Rate (%)
                                </Label>
                                <Input
                                    id="gst_rate_percent"
                                    type="number"
                                    step="0.01"
                                    required
                                    value={form.data.gst_rate_percent}
                                    onChange={(e) =>
                                        form.setData(
                                            'gst_rate_percent',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="sac_code" required>
                                    SAC Code
                                </Label>
                                <Input
                                    id="sac_code"
                                    required
                                    value={form.data.sac_code}
                                    onChange={(e) =>
                                        form.setData('sac_code', e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="invoice_prefix" required>
                                    Invoice Prefix
                                </Label>
                                <Input
                                    id="invoice_prefix"
                                    required
                                    value={form.data.invoice_prefix}
                                    onChange={(e) =>
                                        form.setData(
                                            'invoice_prefix',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="invoice_next_sequence" required>
                                    Next Invoice Seq
                                </Label>
                                <Input
                                    id="invoice_next_sequence"
                                    type="number"
                                    min={1}
                                    required
                                    value={form.data.invoice_next_sequence}
                                    onChange={(e) =>
                                        form.setData(
                                            'invoice_next_sequence',
                                            Number(e.target.value) || 1,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="credit_note_prefix" required>
                                    Credit Note Prefix
                                </Label>
                                <Input
                                    id="credit_note_prefix"
                                    required
                                    value={form.data.credit_note_prefix}
                                    onChange={(e) =>
                                        form.setData(
                                            'credit_note_prefix',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="credit_note_next_sequence"
                                    required
                                >
                                    Next Credit Note Seq
                                </Label>
                                <Input
                                    id="credit_note_next_sequence"
                                    type="number"
                                    min={1}
                                    required
                                    value={form.data.credit_note_next_sequence}
                                    onChange={(e) =>
                                        form.setData(
                                            'credit_note_next_sequence',
                                            Number(e.target.value) || 1,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="logo">Company Logo</Label>
                                <Input
                                    id="logo"
                                    type="file"
                                    accept="image/*"
                                    onChange={(e) =>
                                        form.setData(
                                            'logo',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                {profile?.logo_url ? (
                                    <img
                                        src={profile.logo_url}
                                        alt="Platform Logo"
                                        className="mt-1 h-10 object-contain"
                                    />
                                ) : null}
                            </div>
                        </div>
                    </div>

                    <div className="border-t border-border pt-4">
                        <h2 className="mb-3 text-sm font-semibold">
                            Bank Details
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="bank_name">Bank Name</Label>
                                <Input
                                    id="bank_name"
                                    value={form.data.bank_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'bank_name',
                                            e.target.value,
                                        )
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
                                <Label htmlFor="bank_ifsc">IFSC</Label>
                                <Input
                                    id="bank_ifsc"
                                    value={form.data.bank_ifsc}
                                    onChange={(e) =>
                                        form.setData(
                                            'bank_ifsc',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="bank_branch">Branch</Label>
                                <Input
                                    id="bank_branch"
                                    value={form.data.bank_branch}
                                    onChange={(e) =>
                                        form.setData(
                                            'bank_branch',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </div>

                    {canManage ? (
                        <div className="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-end">
                            <div className="flex-1 space-y-1.5">
                                <Label htmlFor="remark" required>
                                    Audit Remark
                                </Label>
                                <Input
                                    id="remark"
                                    required
                                    placeholder="Describe billing configuration change"
                                    value={form.data.remark}
                                    onChange={(e) =>
                                        form.setData('remark', e.target.value)
                                    }
                                />
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Saving…'
                                    : 'Save Billing Profile'}
                            </Button>
                        </div>
                    ) : null}
                </form>
            </PageShell>
        </>
    );
}
