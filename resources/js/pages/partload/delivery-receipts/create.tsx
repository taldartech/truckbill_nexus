import { Form, Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import {
    QuickCreateBankCashSheet,
    type QuickCreatedBankCashAccount,
} from '@/components/masters/quick-create-bank-cash-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type PartyOption = {
    id: string;
    name: string;
    city?: string | null;
    state?: string | null;
    contact_number?: string | null;
};

type Props = {
    lorryReceipt: {
        id: string;
        lr_number: string | null;
        freight_total: string;
        consignee_name: string | null;
        billing_customer_name?: string | null;
    };
    partload_inward_line_id: string | null;
    paymentModeOptions: Array<{ value: string; label: string }>;
    bankCashAccounts: Array<{ id: string; name: string }>;
    dueFromParties: PartyOption[];
    permissions?: {
        can_create_bank_cash?: boolean;
        can_create_party?: boolean;
    };
    urls: {
        store: string;
        bank_cash_store?: string;
        parties_options?: string;
        parties_store?: string;
    };
};

export default function PartloadDeliveryReceiptCreate({
    lorryReceipt,
    partload_inward_line_id,
    paymentModeOptions,
    bankCashAccounts: initialBankCashAccounts,
    dueFromParties,
    permissions,
    urls,
}: Props) {
    const [paymentMode, setPaymentMode] = useState(
        paymentModeOptions[0]?.value ?? 'due',
    );
    const [bankCashAccounts, setBankCashAccounts] = useState(
        initialBankCashAccounts,
    );
    const [bankCashAccountId, setBankCashAccountId] = useState('');
    const [dueFromPartyId, setDueFromPartyId] = useState('');
    const [bankCashQuickCreateOpen, setBankCashQuickCreateOpen] =
        useState(false);

    const needsBankCash = paymentMode === 'cash' || paymentMode === 'bank';
    const needsDueFrom = paymentMode === 'due';
    const canQuickCreateBankCash =
        Boolean(permissions?.can_create_bank_cash) &&
        Boolean(urls.bank_cash_store);

    const accountOptions: ComboboxOption[] = useMemo(
        () =>
            bankCashAccounts.map((account) => ({
                id: account.id,
                label: account.name,
            })),
        [bankCashAccounts],
    );

    return (
        <>
            <Head title={`DR for ${lorryReceipt.lr_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Delivery Receipt · ${lorryReceipt.lr_number}`}
                >
                    <p className="px-4 pt-3 text-sm text-muted-foreground">
                        {[
                            lorryReceipt.billing_customer_name
                                ? `Customer ${lorryReceipt.billing_customer_name}`
                                : null,
                            lorryReceipt.consignee_name
                                ? `Consignee ${lorryReceipt.consignee_name}`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    <Form
                        action={urls.store}
                        method="post"
                        className="space-y-4 p-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="lorry_receipt_id"
                                    value={lorryReceipt.id}
                                />
                                {partload_inward_line_id ? (
                                    <input
                                        type="hidden"
                                        name="partload_inward_line_id"
                                        value={partload_inward_line_id}
                                    />
                                ) : null}
                                <div className="grid gap-3 md:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="dr_date">Date</Label>
                                        <Input
                                            id="dr_date"
                                            name="dr_date"
                                            type="date"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="payment_mode" required>
                                            Payment Mode
                                        </Label>
                                        <select
                                            id="payment_mode"
                                            name="payment_mode"
                                            required
                                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                            value={paymentMode}
                                            onChange={(event) =>
                                                setPaymentMode(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {paymentModeOptions.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        {errors.payment_mode ? (
                                            <p className="text-sm text-destructive">
                                                {errors.payment_mode}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="freight">Freight</Label>
                                        <Input
                                            id="freight"
                                            name="freight"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue={
                                                lorryReceipt.freight_total
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="handling_charge">
                                            Handling
                                        </Label>
                                        <Input
                                            id="handling_charge"
                                            name="handling_charge"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue="0"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="delivery_charge">
                                            Delivery
                                        </Label>
                                        <Input
                                            id="delivery_charge"
                                            name="delivery_charge"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue="0"
                                        />
                                    </div>
                                    {needsDueFrom ? (
                                        <div className="space-y-1 md:col-span-2">
                                            <SearchablePartySelect
                                                id="payment_due_from_party_id"
                                                name="payment_due_from_party_id"
                                                label="Payment Due From"
                                                options={dueFromParties}
                                                searchUrl={urls.parties_options}
                                                roles={[
                                                    'customer',
                                                    'consignee',
                                                ]}
                                                value={dueFromPartyId}
                                                onValueChange={
                                                    setDueFromPartyId
                                                }
                                                required
                                                createStoreUrl={
                                                    permissions?.can_create_party
                                                        ? urls.parties_store
                                                        : undefined
                                                }
                                                createRole="customer"
                                                emptyMessage="No customers or consignees found."
                                                error={
                                                    errors.payment_due_from_party_id
                                                }
                                            />
                                        </div>
                                    ) : null}
                                    {needsBankCash ? (
                                        <div className="space-y-1 md:col-span-2">
                                            <SearchableCombobox
                                                id="bank_cash_account_id"
                                                name="bank_cash_account_id"
                                                label="Cash / Bank account"
                                                options={accountOptions}
                                                value={bankCashAccountId}
                                                onValueChange={
                                                    setBankCashAccountId
                                                }
                                                required
                                                placeholder="Search account…"
                                                emptyMessage="No bank/cash accounts in this branch."
                                                error={
                                                    errors.bank_cash_account_id
                                                }
                                                addAction={
                                                    canQuickCreateBankCash
                                                        ? {
                                                              label: '+ Add',
                                                              onClick: () =>
                                                                  setBankCashQuickCreateOpen(
                                                                      true,
                                                                  ),
                                                          }
                                                        : undefined
                                                }
                                            />
                                        </div>
                                    ) : null}
                                </div>
                                {errors.lorry_receipt_id ? (
                                    <p className="text-sm text-destructive">
                                        {errors.lorry_receipt_id}
                                    </p>
                                ) : null}
                                <Button type="submit" disabled={processing}>
                                    Post Delivery Receipt
                                </Button>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>

            {bankCashQuickCreateOpen && urls.bank_cash_store ? (
                <QuickCreateBankCashSheet
                    open={bankCashQuickCreateOpen}
                    onOpenChange={setBankCashQuickCreateOpen}
                    storeUrl={urls.bank_cash_store}
                    onCreated={(account: QuickCreatedBankCashAccount) => {
                        setBankCashAccounts((current) =>
                            current.some((row) => row.id === account.id)
                                ? current
                                : [
                                      ...current,
                                      {
                                          id: account.id,
                                          name: account.name,
                                      },
                                  ],
                        );
                        setBankCashAccountId(account.id);
                        setBankCashQuickCreateOpen(false);
                    }}
                />
            ) : null}
        </>
    );
}
