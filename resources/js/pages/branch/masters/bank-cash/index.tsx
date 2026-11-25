import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: string;
    name: string;
    account_kind: string;
    bank_name: string | null;
    account_number_masked: string | null;
    is_active: boolean;
    branch_id: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_create: boolean };
    filters: { q: string; kind: string };
    accounts: {
        data: Row[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { hub: string; dashboard: string; index: string; create: string };
};

export default function BankCashIndex({
    branch,
    permissions,
    filters,
    accounts,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const showUrl = (id: string) => `/bank-cash/${id}`;

    return (
        <>
            <Head title={`Bank / Cash · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title="Bank / Cash accounts"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={links.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create account
                            </Link>
                        ) : null
                    }
                >
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}

                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const q = (
                                form.elements.namedItem('q') as HTMLInputElement
                            ).value.trim();
                            const kind = (
                                form.elements.namedItem(
                                    'kind',
                                ) as HTMLSelectElement
                            ).value;
                            router.get(
                                links.index,
                                { q: q || undefined, kind: kind || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                className="min-w-[12rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="kind">Kind</Label>
                            <select
                                id="kind"
                                name="kind"
                                defaultValue={filters.kind}
                                className="h-9 min-w-[8rem] rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">All</option>
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Kind</th>
                                    <th>Bank</th>
                                    <th>Account</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={6}>
                                            <p className="app-index-empty">
                                                No accounts. Create a bank or
                                                cash account.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    accounts.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.name}
                                            </td>
                                            <td>
                                                {row.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </td>
                                            <td>{row.account_kind}</td>
                                            <td>{row.bank_name || '—'}</td>
                                            <td className="font-mono">
                                                {row.account_number_masked ||
                                                    '—'}
                                            </td>
                                            <td>
                                                <div className="flex justify-center">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="View account"
                                                        href={showUrl(row.id)}
                                                        variant="info"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={links.index}
                            meta={accounts.meta}
                            query={{
                                q: filters.q || undefined,
                                kind: filters.kind || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
