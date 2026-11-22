import { Head, Link, router, usePage } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';

type Account = {
    id: string;
    name: string;
    account_kind: string;
    bank_name: string | null;
    account_number_masked: string | null;
    ifsc: string | null;
    account_holder_name: string | null;
    is_active: boolean;
    branch_id: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_edit: boolean; can_delete: boolean };
    account: Account;
    links: {
        hub: string;
        index: string;
        edit: string;
        destroy: string;
        documents: string;
    };
};

export default function BankCashShow({
    company,
    branch,
    permissions,
    account,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    return (
        <>
            <Head title={account.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Bank / Cash"
                    title={account.name}
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Bank / Cash', href: links.index },
                        { label: account.name },
                    ]}
                />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {permissions.can_edit ? (
                        <Button asChild variant="outline">
                            <Link href={links.edit}>Edit</Link>
                        </Button>
                    ) : null}
                    <Button asChild variant="outline">
                        <Link href={links.documents}>Documents</Link>
                    </Button>
                    {permissions.can_delete ? (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                const remark = window.prompt(
                                    'Remark for soft deletion',
                                );
                                if (!remark) return;
                                router.delete(links.destroy, {
                                    data: { remark },
                                });
                            }}
                        >
                            Soft delete
                        </Button>
                    ) : null}
                </div>
                <dl className="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
                    {[
                        ['Kind', account.account_kind],
                        ['Bank', account.bank_name],
                        ['Account (masked)', account.account_number_masked],
                        ['IFSC', account.ifsc],
                        ['Holder', account.account_holder_name],
                        ['Scope', account.branch_id ? 'branch' : 'shared'],
                        ['Status', account.is_active ? 'Active' : 'Inactive'],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                {label}
                            </dt>
                            <dd className="mt-1 text-sm">{value || '—'}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </>
    );
}
