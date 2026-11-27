import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { AccountingBackAction } from '@/components/accounting/accounting-back-action';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CutoverLine = {
    gl_ledger_id: string;
    debit_amount: string;
    credit_amount: string;
    narration: string;
};

type DryRunResult = {
    valid: boolean;
    balanced: boolean;
    as_of: string;
    total_debit: string;
    total_credit: string;
    double_recognition_risk?: boolean;
    prior_voucher_count?: number;
    errors?: string[];
};

type Props = {
    activation: {
        entitlement_active: boolean;
        entitlement_read: boolean;
        state: {
            id: string;
            activated_at: string;
            cutover_date: string;
            setup_completed_at: string | null;
            opening_voucher_id: string | null;
            last_expired_at: string | null;
            last_catchup_from: string | null;
            last_catchup_to: string | null;
        };
        operational_summary: {
            customer_receivables: string;
            transporter_payables: string;
            transporter_advances: string;
            vendor_payables: string;
            driver_advances: string;
            suggested_lines: CutoverLine[];
            has_operational_balances: boolean;
        };
        catchup_preview: {
            from_date: string;
            to_date: string;
            unposted_count: number;
            breakdown: Record<string, number>;
        };
        ledgers: Array<{ id: string; name: string }>;
    };
    urls: {
        dry_run: string;
        confirm: string;
        catch_up: string;
    };
};

function csrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1] ?? '') : '';
}

export default function AccountingActivation({ activation, urls }: Props) {
    const [lines, setLines] = useState<CutoverLine[]>(
        activation.operational_summary.suggested_lines,
    );
    const [cutoverDate, setCutoverDate] = useState(
        activation.state.cutover_date,
    );
    const [dryRun, setDryRun] = useState<DryRunResult | null>(null);
    const [dryRunError, setDryRunError] = useState<string | null>(null);
    const [dryRunLoading, setDryRunLoading] = useState(false);

    const confirmForm = useForm<{
        cutover_date: string;
        lines: CutoverLine[];
    }>({
        cutover_date: activation.state.cutover_date,
        lines: activation.operational_summary.suggested_lines,
    });

    const catchUpForm = useForm({
        from_date: activation.catchup_preview.from_date,
        to_date: activation.catchup_preview.to_date,
    });

    const ledgerMap = new Map(activation.ledgers.map((l) => [l.id, l.name]));

    const runDryRun = async () => {
        setDryRunLoading(true);
        setDryRunError(null);
        try {
            const response = await fetch(urls.dry_run, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    cutover_date: cutoverDate,
                    lines,
                }),
            });
            const payload = (await response.json()) as DryRunResult & {
                message?: string;
            };
            if (!response.ok) {
                setDryRunError(
                    payload.message ?? 'Could not run opening balance dry-run.',
                );
            } else {
                setDryRun(payload);
            }
        } catch {
            setDryRunError('Network error while checking opening balances.');
        } finally {
            setDryRunLoading(false);
        }
    };

    const submitConfirm = (e: FormEvent) => {
        e.preventDefault();
        confirmForm.transform(() => ({
            cutover_date: cutoverDate,
            lines,
        }));
        confirmForm.post(urls.confirm, { preserveScroll: true });
    };

    const submitCatchUp = (e: FormEvent) => {
        e.preventDefault();
        catchUpForm.post(urls.catch_up, { preserveScroll: true });
    };

    const addLine = () => {
        const firstLedger = activation.ledgers[0]?.id ?? '';
        setLines((curr) => [
            ...curr,
            {
                gl_ledger_id: firstLedger,
                debit_amount: '0.00',
                credit_amount: '0.00',
                narration: '',
            },
        ]);
    };

    return (
        <>
            <Head title="Accounting Activation & Cutover" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Accounting Activation, Opening Cutover & Catch-up"
                    toolbar={<AccountingBackAction />}
                >
                    <div className="grid gap-3 border-b p-4 md:grid-cols-4">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Entitlement
                            </div>
                            <Badge
                                variant={
                                    activation.entitlement_active
                                        ? 'secondary'
                                        : 'destructive'
                                }
                            >
                                {activation.entitlement_active
                                    ? 'Active'
                                    : 'Read-only / Expired'}
                            </Badge>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Cutover Date
                            </div>
                            <div className="font-medium">
                                {activation.state.cutover_date}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Cutover Confirmation
                            </div>
                            <div className="font-medium">
                                {activation.state.setup_completed_at
                                    ? `Confirmed (${activation.state.setup_completed_at})`
                                    : 'Pending confirmation'}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Unposted Operational Events
                            </div>
                            <div className="font-medium">
                                {activation.catchup_preview.unposted_count}
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 border-b bg-muted/20 p-4 md:grid-cols-5">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Customer Receivables
                            </div>
                            <div className="font-semibold">
                                ₹
                                {
                                    activation.operational_summary
                                        .customer_receivables
                                }
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Transporter Payables
                            </div>
                            <div className="font-semibold">
                                ₹
                                {
                                    activation.operational_summary
                                        .transporter_payables
                                }
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Transporter Advances
                            </div>
                            <div className="font-semibold">
                                ₹
                                {
                                    activation.operational_summary
                                        .transporter_advances
                                }
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Vendor Payables
                            </div>
                            <div className="font-semibold">
                                ₹
                                {activation.operational_summary.vendor_payables}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Driver Advances
                            </div>
                            <div className="font-semibold">
                                ₹
                                {activation.operational_summary.driver_advances}
                            </div>
                        </div>
                    </div>

                    <form onSubmit={submitConfirm} className="space-y-4 p-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="max-w-xs space-y-1.5">
                                <Label htmlFor="cutover_date" required>
                                    Prospective Cutover Date
                                </Label>
                                <Input
                                    id="cutover_date"
                                    type="date"
                                    value={cutoverDate}
                                    onChange={(e) =>
                                        setCutoverDate(e.target.value)
                                    }
                                    disabled={!activation.entitlement_active}
                                />
                                <InputError
                                    message={confirmForm.errors.cutover_date}
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addLine}
                                    disabled={!activation.entitlement_active}
                                >
                                    Add Opening Line
                                </Button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={runDryRun}
                                    disabled={
                                        dryRunLoading ||
                                        !activation.entitlement_active ||
                                        lines.length === 0
                                    }
                                >
                                    {dryRunLoading
                                        ? 'Checking…'
                                        : 'Run Idempotent Dry-Run'}
                                </Button>
                            </div>
                        </div>

                        <div className="overflow-x-auto rounded-md border">
                            <table className="app-index-table min-w-full">
                                <thead>
                                    <tr>
                                        <th>Ledger</th>
                                        <th>Debit</th>
                                        <th>Credit</th>
                                        <th>Narration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.length === 0 ? (
                                        <tr>
                                            <td colSpan={4}>
                                                <p className="app-index-empty">
                                                    No open prior-period
                                                    operational balances
                                                    detected. You may confirm
                                                    zero-balance cutover or add
                                                    bank/cash opening lines.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        lines.map((line, index) => (
                                            <tr key={index}>
                                                <td>
                                                    <select
                                                        value={
                                                            line.gl_ledger_id
                                                        }
                                                        aria-label={`Ledger line ${index + 1}`}
                                                        onChange={(e) => {
                                                            const next = [
                                                                ...lines,
                                                            ];
                                                            next[index] = {
                                                                ...next[index],
                                                                gl_ledger_id:
                                                                    e.target
                                                                        .value,
                                                            };
                                                            setLines(next);
                                                        }}
                                                        className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    >
                                                        {activation.ledgers.map(
                                                            (ledger) => (
                                                                <option
                                                                    key={
                                                                        ledger.id
                                                                    }
                                                                    value={
                                                                        ledger.id
                                                                    }
                                                                >
                                                                    {
                                                                        ledger.name
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <span className="sr-only">
                                                        {ledgerMap.get(
                                                            line.gl_ledger_id,
                                                        )}
                                                    </span>
                                                </td>
                                                <td>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={
                                                            line.debit_amount
                                                        }
                                                        onChange={(e) => {
                                                            const next = [
                                                                ...lines,
                                                            ];
                                                            next[index] = {
                                                                ...next[index],
                                                                debit_amount:
                                                                    e.target
                                                                        .value,
                                                            };
                                                            setLines(next);
                                                        }}
                                                    />
                                                </td>
                                                <td>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={
                                                            line.credit_amount
                                                        }
                                                        onChange={(e) => {
                                                            const next = [
                                                                ...lines,
                                                            ];
                                                            next[index] = {
                                                                ...next[index],
                                                                credit_amount:
                                                                    e.target
                                                                        .value,
                                                            };
                                                            setLines(next);
                                                        }}
                                                    />
                                                </td>
                                                <td>
                                                    <Input
                                                        value={line.narration}
                                                        onChange={(e) => {
                                                            const next = [
                                                                ...lines,
                                                            ];
                                                            next[index] = {
                                                                ...next[index],
                                                                narration:
                                                                    e.target
                                                                        .value,
                                                            };
                                                            setLines(next);
                                                        }}
                                                    />
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {dryRunError ? (
                            <p className="text-sm text-destructive">
                                {dryRunError}
                            </p>
                        ) : null}

                        {dryRun ? (
                            <div className="rounded-md border bg-muted/30 p-3 text-sm">
                                <div className="font-medium">
                                    Dry-Run Result ({dryRun.as_of}):{' '}
                                    {dryRun.balanced
                                        ? 'Balanced'
                                        : 'Unbalanced'}{' '}
                                    · Dr ₹{dryRun.total_debit} / Cr ₹
                                    {dryRun.total_credit}
                                </div>
                                {dryRun.double_recognition_risk ? (
                                    <p className="mt-1 text-xs text-amber-700">
                                        Warning: {dryRun.prior_voucher_count}{' '}
                                        prior-period posted voucher(s) already
                                        exist before or on {dryRun.as_of}.
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        <Button
                            type="submit"
                            disabled={
                                confirmForm.processing ||
                                !activation.entitlement_active ||
                                Boolean(activation.state.opening_voucher_id)
                            }
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            {activation.state.opening_voucher_id
                                ? 'Opening Cutover Already Confirmed'
                                : confirmForm.processing
                                  ? 'Confirming…'
                                  : 'Confirm Accounting Cutover'}
                        </Button>
                    </form>

                    <form
                        onSubmit={submitCatchUp}
                        className="grid gap-3 border-t p-4 md:grid-cols-3"
                    >
                        <h3 className="text-sm font-semibold md:col-span-3">
                            Post-Renewal / Unposted Catch-up (
                            {activation.catchup_preview.unposted_count} eligible
                            transaction(s))
                        </h3>
                        <div className="space-y-1.5">
                            <Label htmlFor="catchup_from" required>
                                From Date
                            </Label>
                            <Input
                                id="catchup_from"
                                type="date"
                                value={catchUpForm.data.from_date}
                                onChange={(e) =>
                                    catchUpForm.setData(
                                        'from_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="catchup_to" required>
                                To Date
                            </Label>
                            <Input
                                id="catchup_to"
                                type="date"
                                value={catchUpForm.data.to_date}
                                onChange={(e) =>
                                    catchUpForm.setData(
                                        'to_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="flex items-end">
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={
                                    catchUpForm.processing ||
                                    !activation.entitlement_active
                                }
                            >
                                {catchUpForm.processing
                                    ? 'Posting Catch-up…'
                                    : 'Run Idempotent Catch-up'}
                            </Button>
                        </div>
                    </form>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
