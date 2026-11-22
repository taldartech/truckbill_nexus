import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
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
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

export default function BankCashForm({
    company,
    branch,
    mode,
    account,
    links,
}: Props) {
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
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Bank / Cash"
                    title={
                        mode === 'create'
                            ? 'Create account'
                            : `Edit ${account?.name}`
                    }
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Bank / Cash', href: links.index },
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
                            {mode === 'create' ? (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="scope">Scope</Label>
                                        <select
                                            id="scope"
                                            name="scope"
                                            defaultValue="branch"
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="branch">
                                                Branch
                                            </option>
                                            <option value="shared">
                                                Shared
                                            </option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="account_kind">
                                            Kind
                                        </Label>
                                        <select
                                            id="account_kind"
                                            name="account_kind"
                                            value={kind}
                                            onChange={(e) =>
                                                setKind(e.target.value)
                                            }
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="bank">Bank</option>
                                            <option value="cash">Cash</option>
                                        </select>
                                    </div>
                                </>
                            ) : null}
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
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
                                        <Label htmlFor="account_number">
                                            {mode === 'edit'
                                                ? 'Account number (leave blank to keep)'
                                                : 'Account number'}
                                        </Label>
                                        <Input
                                            id="account_number"
                                            name="account_number"
                                            placeholder={
                                                account?.account_number_masked ??
                                                ''
                                            }
                                            autoComplete="off"
                                        />
                                        <InputError
                                            message={errors.account_number}
                                        />
                                        {mode === 'edit' &&
                                        account?.account_number_masked ? (
                                            <p className="text-xs text-muted-foreground">
                                                Current masked:{' '}
                                                {account.account_number_masked}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="ifsc">IFSC</Label>
                                        <Input
                                            id="ifsc"
                                            name="ifsc"
                                            defaultValue={account?.ifsc ?? ''}
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
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    defaultChecked={account?.is_active ?? true}
                                />{' '}
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
                                            ? 'Create bank/cash account'
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
