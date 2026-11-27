import { Head, Link } from '@inertiajs/react';
import { AccountingBackAction } from '@/components/accounting/accounting-back-action';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';

type Props = {
    ledgers: Array<{
        id: string;
        name: string;
        group_name: string | null;
        urls: { show: string };
    }>;
};

export default function AccountingLedgersIndex({ ledgers }: Props) {
    return (
        <>
            <Head title="Ledgers" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Chart of Accounts — Ledgers"
                    toolbar={<AccountingBackAction />}
                >
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Ledger
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Group
                                    </th>
                                    <th className="px-3 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {ledgers.map((ledger) => (
                                    <tr
                                        key={ledger.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {ledger.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {ledger.group_name}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link href={ledger.urls.show}>
                                                    Statement
                                                </Link>
                                            </Button>
                                        </td>
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
