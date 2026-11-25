import { Head, Link } from '@inertiajs/react';
import {
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';

type Props = {
    voucher: {
        voucher_number: string;
        voucher_date: string | null;
        voucher_type_label: string;
        narration: string | null;
        total_debit: string;
        total_credit: string;
        lines: Array<{
            ledger_name: string | null;
            debit_amount: string;
            credit_amount: string;
            narration: string | null;
        }>;
    };
    urls: { index: string };
};

export default function AccountingVoucherShow({ voucher, urls }: Props) {
    return (
        <>
            <Head title={`Voucher ${voucher.voucher_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`${voucher.voucher_type_label} ${voucher.voucher_number}`}
                    toolbar={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.index}>Day Book</Link>
                        </Button>
                    }
                >
                    <div className="grid gap-3 p-4 md:grid-cols-3">
                        <div>
                            <div className="text-xs text-muted-foreground">Date</div>
                            <div className="font-medium">{voucher.voucher_date}</div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Totals</div>
                            <div className="font-medium">
                                Dr {voucher.total_debit} / Cr {voucher.total_credit}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Narration</div>
                            <div className="font-medium">{voucher.narration || '—'}</div>
                        </div>
                    </div>
                    <div className="overflow-x-auto border-t">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Ledger</th>
                                    <th className="px-3 py-2 font-medium">Debit</th>
                                    <th className="px-3 py-2 font-medium">Credit</th>
                                    <th className="px-3 py-2 font-medium">Narration</th>
                                </tr>
                            </thead>
                            <tbody>
                                {voucher.lines.map((line, index) => (
                                    <tr key={index} className="border-b last:border-0">
                                        <td className="px-3 py-2 font-medium">{line.ledger_name}</td>
                                        <td className="px-3 py-2">{line.debit_amount}</td>
                                        <td className="px-3 py-2">{line.credit_amount}</td>
                                        <td className="px-3 py-2">{line.narration}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
