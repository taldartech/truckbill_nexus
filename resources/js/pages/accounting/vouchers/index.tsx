import { Head, Link, router } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { AccountingBackAction } from '@/components/accounting/accounting-back-action';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import InputError from '@/components/input-error';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Filters = {
    financial_year_id: string;
    from_date: string;
    to_date: string;
    voucher_type: string;
};

type Props = {
    filters: Filters;
    financialYearOptions: Option[];
    voucherTypeOptions: Option[];
    permissions: { can_create: boolean };
    vouchers: {
        data: Array<{
            id: string;
            voucher_number: string;
            voucher_date: string | null;
            voucher_type_label: string;
            narration: string | null;
            total_debit: string;
            urls: { show: string };
        }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string; create: string; workspace: string };
    errors?: Partial<Record<keyof Filters, string>>;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const filterFieldClass = 'w-full space-y-1 sm:w-44';

function formValue(
    form: HTMLFormElement,
    name: keyof Filters,
): string | undefined {
    const field = form.elements.namedItem(name) as
        | HTMLInputElement
        | HTMLSelectElement
        | null;

    return field?.value || undefined;
}

export default function AccountingDayBook({
    filters,
    financialYearOptions,
    voucherTypeOptions,
    permissions,
    vouchers,
    urls,
    errors = {},
}: Props) {
    const hasActiveFilters = Object.values(filters).some(Boolean);
    const selectedYearLabel = financialYearOptions.find(
        (option) => option.value === filters.financial_year_id,
    )?.label;

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        router.get(
            urls.index,
            {
                financial_year_id: formValue(form, 'financial_year_id'),
                from_date: formValue(form, 'from_date'),
                to_date: formValue(form, 'to_date'),
                voucher_type: formValue(form, 'voucher_type'),
            },
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Day Book" />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Day Book${selectedYearLabel ? ` · FY ${selectedYearLabel}` : ''}`}
                    toolbar={
                        <>
                            <AccountingBackAction href={urls.workspace} />
                            {permissions.can_create ? (
                                <Link
                                    href={urls.create}
                                    className="app-index-header-btn"
                                >
                                    <Plus className="size-3.5" aria-hidden />
                                    New Voucher
                                </Link>
                            ) : null}
                        </>
                    }
                >
                    <AppIndexFilters onSubmit={applyFilters}>
                        <div className={filterFieldClass}>
                            <Label htmlFor="financial_year_id">
                                Financial Year
                            </Label>
                            <select
                                id="financial_year_id"
                                name="financial_year_id"
                                className={selectClass}
                                defaultValue={filters.financial_year_id}
                            >
                                <option value="">All years</option>
                                {financialYearOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.financial_year_id} />
                        </div>
                        <div className={filterFieldClass}>
                            <Label htmlFor="from_date">From</Label>
                            <Input
                                id="from_date"
                                name="from_date"
                                type="date"
                                defaultValue={filters.from_date}
                            />
                            <InputError message={errors.from_date} />
                        </div>
                        <div className={filterFieldClass}>
                            <Label htmlFor="to_date">To</Label>
                            <Input
                                id="to_date"
                                name="to_date"
                                type="date"
                                defaultValue={filters.to_date}
                            />
                            <InputError message={errors.to_date} />
                        </div>
                        <div className={filterFieldClass}>
                            <Label htmlFor="voucher_type">Voucher Type</Label>
                            <select
                                id="voucher_type"
                                name="voucher_type"
                                className={selectClass}
                                defaultValue={filters.voucher_type}
                            >
                                <option value="">All types</option>
                                {voucherTypeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.voucher_type} />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" size="sm" className="h-9">
                                Apply
                            </Button>
                            {hasActiveFilters ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="h-9"
                                >
                                    <Link href={urls.index}>Reset</Link>
                                </Button>
                            ) : null}
                        </div>
                    </AppIndexFilters>
                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-[760px]">
                            <thead>
                                <tr>
                                    <th className="w-28">Date</th>
                                    <th className="w-40">Voucher No.</th>
                                    <th className="w-32">Type</th>
                                    <th>Narration</th>
                                    <th className="w-36 text-right">Amount</th>
                                    <th className="w-16">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="app-index-empty"
                                        >
                                            {hasActiveFilters
                                                ? 'No posted vouchers match these filters.'
                                                : 'No vouchers have been posted yet.'}
                                        </td>
                                    </tr>
                                ) : (
                                    vouchers.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>{row.voucher_date}</td>
                                            <td className="font-medium">
                                                {row.voucher_number}
                                            </td>
                                            <td>{row.voucher_type_label}</td>
                                            <td className="min-w-64 whitespace-normal">
                                                {row.narration ?? '—'}
                                            </td>
                                            <td className="text-right tabular-nums">
                                                {row.total_debit}
                                            </td>
                                            <td className="text-right">
                                                <AppIconAction
                                                    icon={Eye}
                                                    label={`View voucher ${row.voucher_number}`}
                                                    tooltip="View"
                                                    href={row.urls.show}
                                                    size="sm"
                                                />
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-4 py-3">
                        <TablePagination
                            baseUrl={urls.index}
                            meta={vouchers.meta}
                            query={{
                                financial_year_id:
                                    filters.financial_year_id || undefined,
                                from_date: filters.from_date || undefined,
                                to_date: filters.to_date || undefined,
                                voucher_type: filters.voucher_type || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
