import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
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
import { useMemo, useState } from 'react';

export type QuickCreatedBankCashAccount = {
    id: string;
    name: string;
    account_kind?: string;
};

type Draft = {
    account_kind: 'bank' | 'cash';
    name: string;
    bank_name: string;
    account_number: string;
    ifsc: string;
    account_holder_name: string;
};

const EMPTY: Draft = {
    account_kind: 'bank',
    name: '',
    bank_name: '',
    account_number: '',
    ifsc: '',
    account_holder_name: '',
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    onCreated: (account: QuickCreatedBankCashAccount) => void;
};

function parseCreatedAccount(
    json: unknown,
): QuickCreatedBankCashAccount | null {
    if (!json || typeof json !== 'object') {
        return null;
    }

    const payload = json as { data?: QuickCreatedBankCashAccount };
    if (!payload.data?.id || !payload.data.name) {
        return null;
    }

    return {
        id: payload.data.id,
        name: payload.data.name,
        account_kind: payload.data.account_kind,
    };
}

/**
 * Branch Bank/Cash account quick-create for voucher and expense forms.
 */
export function QuickCreateBankCashSheet({
    open,
    onOpenChange,
    storeUrl,
    onCreated,
}: Props) {
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields = useMemo<FieldReview[]>(
        () => [
            {
                label: 'Kind',
                value: draft.account_kind === 'bank' ? 'Bank' : 'Cash',
            },
            { label: 'Name', value: draft.name },
            ...(draft.account_kind === 'bank'
                ? [
                      {
                          label: 'Bank name',
                          value:
                              draft.bank_name.trim() !== ''
                                  ? draft.bank_name
                                  : '—',
                      },
                  ]
                : []),
        ],
        [draft],
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

    const update = <K extends keyof Draft>(key: K, value: Draft[K]) => {
        setDraft((current) => ({ ...current, [key]: value }));
    };

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});
        if (draft.name.trim() === '') {
            setFormError('Name is required.');
            return;
        }
        if (
            draft.account_kind === 'bank' &&
            draft.account_number.trim() === ''
        ) {
            setFormError('Bank account number is required.');
            return;
        }
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload: Record<string, string> = {
            account_kind: draft.account_kind,
            name: draft.name.trim(),
            remark: 'Quick create bank/cash account',
        };
        if (draft.account_kind === 'bank') {
            if (draft.bank_name.trim() !== '') {
                payload.bank_name = draft.bank_name.trim();
            }
            if (draft.account_number.trim() !== '') {
                payload.account_number = draft.account_number.trim();
            }
            if (draft.ifsc.trim() !== '') {
                payload.ifsc = draft.ifsc.trim();
            }
            if (draft.account_holder_name.trim() !== '') {
                payload.account_holder_name = draft.account_holder_name.trim();
            }
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
                data?: QuickCreatedBankCashAccount;
            } | null;

            if (!response.ok) {
                if (json?.errors) {
                    setErrors(json.errors);
                }
                setFormError(
                    json?.message ??
                        'Could not create account. Check the form and try again.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            const created = parseCreatedAccount(json);
            if (created === null) {
                setFormError(
                    'Account was created but the response was incomplete.',
                );
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            onCreated(created);
            handleOpenChange(false);
        } catch {
            setFormError('Network error while creating account.');
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
                        <SheetTitle>Add Bank / Cash account</SheetTitle>
                        <SheetDescription>
                            Create an account without leaving this form. Full
                            account numbers are masked after save.
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
                            <Label htmlFor="bank-cash-kind" required>
                                Kind
                            </Label>
                            <select
                                id="bank-cash-kind"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={draft.account_kind}
                                onChange={(event) =>
                                    update(
                                        'account_kind',
                                        event.target.value as 'bank' | 'cash',
                                    )
                                }
                            >
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                            </select>
                            <InputError message={firstError('account_kind')} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="bank-cash-name" required>
                                Name
                            </Label>
                            <Input
                                id="bank-cash-name"
                                value={draft.name}
                                onChange={(event) =>
                                    update('name', event.target.value)
                                }
                                autoFocus
                            />
                            <InputError message={firstError('name')} />
                        </div>

                        {draft.account_kind === 'bank' ? (
                            <>
                                <div className="space-y-1">
                                    <Label htmlFor="bank-cash-bank-name">
                                        Bank name
                                    </Label>
                                    <Input
                                        id="bank-cash-bank-name"
                                        value={draft.bank_name}
                                        onChange={(event) =>
                                            update(
                                                'bank_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={firstError('bank_name')}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="bank-cash-account-number"
                                        required
                                    >
                                        Account number
                                    </Label>
                                    <Input
                                        id="bank-cash-account-number"
                                        value={draft.account_number}
                                        onChange={(event) =>
                                            update(
                                                'account_number',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={firstError('account_number')}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="bank-cash-ifsc">IFSC</Label>
                                    <Input
                                        id="bank-cash-ifsc"
                                        value={draft.ifsc}
                                        onChange={(event) =>
                                            update('ifsc', event.target.value)
                                        }
                                    />
                                    <InputError message={firstError('ifsc')} />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="bank-cash-holder">
                                        Account holder
                                    </Label>
                                    <Input
                                        id="bank-cash-holder"
                                        value={draft.account_holder_name}
                                        onChange={(event) =>
                                            update(
                                                'account_holder_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={firstError(
                                            'account_holder_name',
                                        )}
                                    />
                                </div>
                            </>
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
                title="Confirm Bank / Cash account"
                description="Create this account and select it on the form."
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
