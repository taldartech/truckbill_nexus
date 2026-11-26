import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Account = {
    id: string;
    name: string;
    account_kind: string;
    bank_name: string | null;
    account_number_masked: string | null;
    ifsc: string | null;
    account_holder_name: string | null;
    is_active: boolean;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    mode: 'create' | 'edit';
    account: Account | null;
    links: {
        hub: string;
        dashboard: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

export default function BankCashForm({ mode, account, links }: Props) {
    const [kind, setKind] = useState(account?.account_kind ?? 'bank');
    const action = mode === 'create' ? links.store! : links.update!;
    return (
        <>
            <Head
                title={
                    mode === 'create'
                        ? 'Create account'
                        : `Edit ${account?.name}`
                }
            />
            <LrfPage
                title={
                    mode === 'create'
                        ? 'Create account'
                        : `Edit ${account?.name}`
                }
                subtitle="Branch bank and cash accounts for settlements."
                backHref={links.index}
                backLabel="Bank / cash"
            >
                <Form
                    action={action}
                    method={mode === 'create' ? 'post' : 'patch'}
                    className="space-y-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <LrfSection
                                step={1}
                                title="Account details"
                                description="Kind, name, and banking fields."
                                tone="receipt"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
                                    {mode === 'create' ? (
                                        <div className="space-y-2">
                                            <Label
                                                htmlFor="account_kind"
                                                required
                                            >
                                                Kind
                                            </Label>
                                            <select
                                                id="account_kind"
                                                name="account_kind"
                                                required
                                                value={kind}
                                                onChange={(e) =>
                                                    setKind(e.target.value)
                                                }
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                <option value="bank">
                                                    Bank
                                                </option>
                                                <option value="cash">
                                                    Cash
                                                </option>
                                            </select>
                                        </div>
                                    ) : null}
                                    <div className="space-y-2">
                                        <Label htmlFor="name" required>
                                            Name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            defaultValue={account?.name ?? ''}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    {kind === 'bank' ||
                                    account?.account_kind === 'bank' ? (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="bank_name">
                                                    Bank name
                                                </Label>
                                                <Input
                                                    id="bank_name"
                                                    name="bank_name"
                                                    defaultValue={
                                                        account?.bank_name ?? ''
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor="account_number"
                                                    required={
                                                        mode === 'create' &&
                                                        kind === 'bank'
                                                    }
                                                >
                                                    {mode === 'edit'
                                                        ? 'Account number (leave blank to keep)'
                                                        : 'Account number'}
                                                </Label>
                                                <Input
                                                    id="account_number"
                                                    name="account_number"
                                                    required={
                                                        mode === 'create' &&
                                                        kind === 'bank'
                                                    }
                                                    placeholder={
                                                        account?.account_number_masked ??
                                                        ''
                                                    }
                                                    autoComplete="off"
                                                />
                                                <InputError
                                                    message={
                                                        errors.account_number
                                                    }
                                                />
                                                {mode === 'edit' &&
                                                account?.account_number_masked ? (
                                                    <p className="text-xs text-muted-foreground">
                                                        Current masked:{' '}
                                                        {
                                                            account.account_number_masked
                                                        }
                                                    </p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="ifsc">
                                                    IFSC
                                                </Label>
                                                <Input
                                                    id="ifsc"
                                                    name="ifsc"
                                                    defaultValue={
                                                        account?.ifsc ?? ''
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="account_holder_name">
                                                    Account holder
                                                </Label>
                                                <Input
                                                    id="account_holder_name"
                                                    name="account_holder_name"
                                                    defaultValue={
                                                        account?.account_holder_name ??
                                                        ''
                                                    }
                                                />
                                            </div>
                                        </>
                                    ) : null}
                                    <label className="flex items-center gap-2 text-sm md:col-span-2">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            value="1"
                                            defaultChecked={
                                                account?.is_active ?? true
                                            }
                                        />{' '}
                                        Active
                                    </label>
                                </div>
                            </LrfSection>
                            <LrfSection
                                step={2}
                                title={mode === 'edit' ? 'Remark' : 'Save'}
                                description={
                                    mode === 'edit'
                                        ? 'Audit remark required when editing this account.'
                                        : undefined
                                }
                                tone="optional"
                            >
                                <div className="space-y-3">
                                    {mode === 'edit' ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="remark" required>
                                                Remark
                                            </Label>
                                            <Input
                                                id="remark"
                                                name="remark"
                                                required
                                            />
                                            <InputError
                                                message={errors.remark}
                                            />
                                        </div>
                                    ) : null}
                                    <div className="flex gap-3">
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {processing ? 'Saving…' : 'Save'}
                                        </Button>
                                        <Button asChild variant="outline">
                                            <Link
                                                href={links.show ?? links.index}
                                            >
                                                Cancel
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </LrfSection>
                        </>
                    )}
                </Form>
            </LrfPage>
        </>
    );
}
