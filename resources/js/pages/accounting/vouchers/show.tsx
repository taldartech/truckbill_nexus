import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AccountingBackAction } from '@/components/accounting/accounting-back-action';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    voucher: {
        id?: string;
        voucher_number: string;
        voucher_date: string | null;
        voucher_type_label: string;
        status?: string;
        narration: string | null;
        total_debit: string;
        total_credit: string;
        source_document?: {
            type: string;
            id: string;
            label: string;
            purpose: string;
            url: string | null;
        } | null;
        lines: Array<{
            ledger_name: string | null;
            debit_amount: string;
            credit_amount: string;
            narration: string | null;
        }>;
    };
    permissions?: {
        can_reverse?: boolean;
    };
    urls: { index: string; reverse?: string };
};

export default function AccountingVoucherShow({
    voucher,
    permissions,
    urls,
}: Props) {
    const reverseForm = useForm({ reason: '' });

    const submitReverse = (e: FormEvent) => {
        e.preventDefault();
        if (urls.reverse) {
            reverseForm.post(urls.reverse, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Voucher ${voucher.voucher_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`${voucher.voucher_type_label} ${voucher.voucher_number}`}
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            {voucher.status ? (
                                <Badge variant="outline">
                                    {voucher.status}
                                </Badge>
                            ) : null}
                            <AccountingBackAction
                                href={urls.index}
                                label="Back to Day Book"
                            />
                        </div>
                    }
                >
                    <div className="grid gap-3 p-4 md:grid-cols-3">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Date
                            </div>
                            <div className="font-medium">
                                {voucher.voucher_date}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Totals
                            </div>
                            <div className="font-medium">
                                Dr {voucher.total_debit} / Cr{' '}
                                {voucher.total_credit}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Narration
                            </div>
                            <div className="font-medium">
                                {voucher.narration || '—'}
                            </div>
                        </div>
                    </div>

                    {voucher.source_document ? (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-t bg-muted/20 px-4 py-3 text-sm">
                            <div>
                                <span className="text-muted-foreground">
                                    Source Document (
                                    {voucher.source_document.purpose}):{' '}
                                </span>
                                <span className="font-medium">
                                    {voucher.source_document.label}
                                </span>
                            </div>
                            {voucher.source_document.url ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={voucher.source_document.url}>
                                        Open Source
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="overflow-x-auto border-t">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Ledger
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Debit
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Credit
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Narration
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {voucher.lines.map((line, index) => (
                                    <tr
                                        key={index}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {line.ledger_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.debit_amount}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.credit_amount}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.narration}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {permissions?.can_reverse && urls.reverse ? (
                        <form
                            onSubmit={submitReverse}
                            className="space-y-2 border-t p-4"
                        >
                            <Label htmlFor="reverse_reason" required>
                                Controlled Reversal Reason
                            </Label>
                            <div className="flex flex-wrap gap-2">
                                <Input
                                    id="reverse_reason"
                                    className="max-w-md"
                                    value={reverseForm.data.reason}
                                    onChange={(e) =>
                                        reverseForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Mandatory audit reason for reversal"
                                    required
                                />
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    size="sm"
                                    disabled={reverseForm.processing}
                                >
                                    {reverseForm.processing
                                        ? 'Reversing…'
                                        : 'Post Reversal Voucher'}
                                </Button>
                            </div>
                            <InputError message={reverseForm.errors.reason} />
                        </form>
                    ) : null}
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
