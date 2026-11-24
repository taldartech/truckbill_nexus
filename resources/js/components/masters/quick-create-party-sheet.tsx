import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import type { PartyOption } from '@/components/searchable-party-select';
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

export type QuickCreatePartyRole =
    | 'customer'
    | 'consignor'
    | 'consignee'
    | 'transporter';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    role: QuickCreatePartyRole;
    onCreated: (party: PartyOption) => void;
};

const ROLE_TITLES: Record<QuickCreatePartyRole, string> = {
    customer: 'Customer',
    consignor: 'Consignor',
    consignee: 'Consignee',
    transporter: 'Agency / Transporter',
};

type Draft = {
    name: string;
    contact_number: string;
    email: string;
    state: string;
    city: string;
    address: string;
    gstin: string;
    pan: string;
    pan_status: string;
    declaration: boolean;
    declaration_type: string;
};

const EMPTY: Draft = {
    name: '',
    contact_number: '',
    email: '',
    state: '',
    city: '',
    address: '',
    gstin: '',
    pan: '',
    pan_status: 'active',
    declaration: true,
    declaration_type: 'agency',
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
 * Inline Party master create for Bilty / Hire selectors (legacy quick-add modal equivalent).
 * Posts JSON so the parent operational form stays mounted.
 */
export function QuickCreatePartySheet({
    open,
    onOpenChange,
    storeUrl,
    role,
    onCreated,
}: Props) {
    const title = ROLE_TITLES[role];
    const isTransporter = role === 'transporter';
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(() => {
        const fields: FieldReview[] = [
            { label: 'Name', value: draft.name },
            { label: 'Contact number', value: draft.contact_number },
            { label: 'State', value: draft.state },
            { label: 'City', value: draft.city },
            { label: 'Role', value: title },
        ];
        if (draft.email) {
            fields.push({ label: 'Email', value: draft.email });
        }
        if (draft.gstin) {
            fields.push({ label: 'GSTIN', value: draft.gstin });
        }
        if (draft.pan || isTransporter) {
            fields.push({ label: 'PAN', value: draft.pan });
        }
        if (isTransporter) {
            fields.push({ label: 'PAN status', value: draft.pan_status });
            fields.push({
                label: 'Declaration',
                value: draft.declaration ? 'Yes' : 'No',
            });
        }
        return fields;
    }, [draft, isTransporter, title]);

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

    const update = <K extends keyof Draft>(key: K, value: Draft[K]) => {
        setDraft((current) => ({ ...current, [key]: value }));
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

        const roles: Array<{
            role: string;
            attributes: Record<string, unknown>;
        }> = [
            {
                role,
                attributes: isTransporter
                    ? {
                          pan_status: draft.pan_status,
                          declaration: draft.declaration,
                          declaration_type: draft.declaration_type,
                      }
                    : {},
            },
        ];

        const payload = {
            name: draft.name.trim(),
            contact_number: draft.contact_number.trim(),
            email: draft.email.trim() || null,
            state: draft.state.trim(),
            city: draft.city.trim(),
            address: draft.address.trim() || null,
            gstin: draft.gstin.trim() || null,
            pan: draft.pan.trim() || null,
            roles,
            remark: `Quick create ${title}`,
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
                data?: PartyOption;
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
                            : 'Unable to create party.'),
                );
                setConfirmOpen(false);
                return;
            }

            const created = json?.data;
            if (!created?.id) {
                setFormError('Party created but response was incomplete.');
                setConfirmOpen(false);
                return;
            }

            onCreated({
                id: created.id,
                name: created.name,
                city: created.city ?? null,
                state: created.state ?? null,
                contact_number: created.contact_number ?? null,
            });
            handleOpenChange(false);
        } catch {
            setFormError('Unable to create party.');
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
                        <SheetTitle>Add {title}</SheetTitle>
                        <SheetDescription>
                            Create a branch-visible {title.toLowerCase()} and
                            return to the current form with it selected.
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
                                <Label htmlFor="quick-party-name">Name *</Label>
                                <Input
                                    id="quick-party-name"
                                    value={draft.name}
                                    onChange={(event) =>
                                        update('name', event.target.value)
                                    }
                                    required
                                    autoFocus
                                />
                                <InputError
                                    message={firstError(errors, 'name')}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-party-contact">
                                    Contact number *
                                </Label>
                                <Input
                                    id="quick-party-contact"
                                    value={draft.contact_number}
                                    onChange={(event) =>
                                        update(
                                            'contact_number',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    message={firstError(
                                        errors,
                                        'contact_number',
                                    )}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-party-email">Email</Label>
                                <Input
                                    id="quick-party-email"
                                    type="email"
                                    value={draft.email}
                                    onChange={(event) =>
                                        update('email', event.target.value)
                                    }
                                />
                                <InputError
                                    message={firstError(errors, 'email')}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-party-state">
                                    State *
                                </Label>
                                <Input
                                    id="quick-party-state"
                                    value={draft.state}
                                    onChange={(event) =>
                                        update('state', event.target.value)
                                    }
                                    required
                                />
                                <InputError
                                    message={firstError(errors, 'state')}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-party-city">City *</Label>
                                <Input
                                    id="quick-party-city"
                                    value={draft.city}
                                    onChange={(event) =>
                                        update('city', event.target.value)
                                    }
                                    required
                                />
                                <InputError
                                    message={firstError(errors, 'city')}
                                />
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="quick-party-address">
                                    Address
                                </Label>
                                <Input
                                    id="quick-party-address"
                                    value={draft.address}
                                    onChange={(event) =>
                                        update('address', event.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-party-gstin">GSTIN</Label>
                                <Input
                                    id="quick-party-gstin"
                                    value={draft.gstin}
                                    onChange={(event) =>
                                        update('gstin', event.target.value)
                                    }
                                    className="uppercase"
                                />
                                <InputError
                                    message={firstError(errors, 'gstin')}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="quick-party-pan">
                                    PAN{isTransporter ? ' *' : ''}
                                </Label>
                                <Input
                                    id="quick-party-pan"
                                    value={draft.pan}
                                    onChange={(event) =>
                                        update('pan', event.target.value)
                                    }
                                    className="uppercase"
                                    required={isTransporter}
                                    maxLength={10}
                                />
                                <InputError
                                    message={firstError(errors, 'pan')}
                                />
                            </div>
                        </div>

                        {isTransporter ? (
                            <div className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="quick-party-pan-status">
                                        PAN status *
                                    </Label>
                                    <select
                                        id="quick-party-pan-status"
                                        className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        value={draft.pan_status}
                                        onChange={(event) =>
                                            update(
                                                'pan_status',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="active">Active</option>
                                        <option value="valid">Valid</option>
                                        <option value="inactive">
                                            Inactive
                                        </option>
                                        <option value="invalid">Invalid</option>
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="quick-party-declaration-type">
                                        Declaration type
                                    </Label>
                                    <select
                                        id="quick-party-declaration-type"
                                        className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        value={draft.declaration_type}
                                        onChange={(event) =>
                                            update(
                                                'declaration_type',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="agency">Agency</option>
                                        <option value="owner">Owner</option>
                                    </select>
                                </div>
                                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                    <input
                                        type="checkbox"
                                        checked={draft.declaration}
                                        onChange={(event) =>
                                            update(
                                                'declaration',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Transporter declaration confirmed
                                </label>
                            </div>
                        ) : null}

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
                title={`Confirm create ${title}`}
                description="Review the new master before saving. The Bilty form stays open."
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
