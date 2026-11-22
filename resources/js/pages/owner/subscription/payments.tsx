import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    gate_note: string;
    payments: {
        id: string;
        purpose: string;
        amount: string;
        currency: string;
        status: string;
        verification_status: string;
        gateway: string;
        gateway_order_id: string | null;
        gateway_payment_id: string | null;
        created_at: string | null;
    }[];
    links: { overview: string };
};

export default function SubscriptionPayments({
    company,
    ownerPanelScope,
    gate_note,
    payments,
    links,
}: Props) {
    return (
        <>
            <Head title={`Payments · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Owner Panel · Payment history
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {company.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Scope: {ownerPanelScope}
                    </p>
                </div>
                <p className="text-sm text-muted-foreground">{gate_note}</p>
                <Button asChild variant="outline" className="w-fit">
                    <Link href={links.overview}>Back to subscription</Link>
                </Button>
                {payments.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No payment intents recorded for this company.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Purpose
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Gateway order
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Created
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {payments.map((payment) => (
                                    <tr
                                        key={payment.id}
                                        className="border-b border-border/60"
                                    >
                                        <td className="px-3 py-2">
                                            {payment.purpose}
                                        </td>
                                        <td className="px-3 py-2">
                                            {payment.currency} {payment.amount}
                                        </td>
                                        <td className="px-3 py-2">
                                            {payment.status} /{' '}
                                            {payment.verification_status}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {payment.gateway_order_id || '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {payment.created_at || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
