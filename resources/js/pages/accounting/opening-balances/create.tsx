import { Form, Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type LedgerOption = { id: string; name: string };

type LineDraft = {
    gl_ledger_id: string;
    debit_amount: string;
    credit_amount: string;
    narration: string;
};

type Props = {
    ledgers: LedgerOption[];
    urls: { store: string; index: string };
};

function emptyLine(): LineDraft {
    return {
        gl_ledger_id: '',
        debit_amount: '',
        credit_amount: '',
        narration: '',
    };
}

function formatAmount(value: number): string {
    return value.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export default function AccountingOpeningBalanceCreate({
    ledgers,
    urls,
}: Props) {
    const [lines, setLines] = useState<LineDraft[]>([emptyLine(), emptyLine()]);

    const totals = useMemo(() => {
        let debit = 0;
        let credit = 0;
        for (const line of lines) {
            debit += Number(line.debit_amount || 0);
            credit += Number(line.credit_amount || 0);
        }
        return {
            debit,
            credit,
            difference: Math.abs(debit - credit),
            balanced: debit > 0 && Math.abs(debit - credit) < 0.005,
        };
    }, [lines]);

    const updateLine = (index: number, patch: Partial<LineDraft>) => {
        setLines((current) =>
            current.map((line, rowIndex) =>
                rowIndex === index ? { ...line, ...patch } : line,
            ),
        );
    };

    return (
        <>
            <Head title="Opening Balances" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Opening Balances"
                    toolbar={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.index}>Day Book</Link>
                        </Button>
                    }
                >
                    <Form
                        action={urls.store}
                        method="post"
                        className="space-y-4 p-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label htmlFor="as_of_date">
                                            As of date
                                        </Label>
                                        <Input
                                            id="as_of_date"
                                            name="as_of_date"
                                            type="date"
                                        />
                                        {errors.as_of_date ? (
                                            <p className="text-sm text-destructive">
                                                {errors.as_of_date}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="cutover_key">
                                            Cutover key
                                        </Label>
                                        <Input
                                            id="cutover_key"
                                            name="cutover_key"
                                            placeholder="default"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Same key posts idempotently; change
                                            only for a new cutover batch.
                                        </p>
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    {lines.map((line, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-3 rounded-md border border-border/70 p-3 md:grid-cols-4"
                                        >
                                            <div className="space-y-1 md:col-span-2">
                                                <Label
                                                    htmlFor={`opening-line-ledger-${index}`}
                                                    required
                                                >
                                                    Ledger
                                                </Label>
                                                <select
                                                    id={`opening-line-ledger-${index}`}
                                                    name={`lines[${index}][gl_ledger_id]`}
                                                    required
                                                    className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    value={line.gl_ledger_id}
                                                    onChange={(event) =>
                                                        updateLine(index, {
                                                            gl_ledger_id:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        Select ledger
                                                    </option>
                                                    {ledgers.map((ledger) => (
                                                        <option
                                                            key={ledger.id}
                                                            value={ledger.id}
                                                        >
                                                            {ledger.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Debit</Label>
                                                <Input
                                                    name={`lines[${index}][debit_amount]`}
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={line.debit_amount}
                                                    onChange={(event) =>
                                                        updateLine(index, {
                                                            debit_amount:
                                                                event.target
                                                                    .value,
                                                            credit_amount: '',
                                                        })
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Credit</Label>
                                                <Input
                                                    name={`lines[${index}][credit_amount]`}
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={line.credit_amount}
                                                    onChange={(event) =>
                                                        updateLine(index, {
                                                            credit_amount:
                                                                event.target
                                                                    .value,
                                                            debit_amount: '',
                                                        })
                                                    }
                                                />
                                            </div>
                                            <input
                                                type="hidden"
                                                name={`lines[${index}][narration]`}
                                                value={
                                                    line.narration ||
                                                    'Opening balance'
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>

                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setLines((current) => [
                                                ...current,
                                                emptyLine(),
                                            ])
                                        }
                                    >
                                        Add line
                                    </Button>
                                    {lines.length > 2 ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setLines((current) =>
                                                    current.slice(0, -1),
                                                )
                                            }
                                        >
                                            Remove last
                                        </Button>
                                    ) : null}
                                </div>

                                <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                                    <span>
                                        Debit:{' '}
                                        <strong>
                                            {formatAmount(totals.debit)}
                                        </strong>
                                    </span>
                                    <span>
                                        Credit:{' '}
                                        <strong>
                                            {formatAmount(totals.credit)}
                                        </strong>
                                    </span>
                                    <span>
                                        Difference:{' '}
                                        <strong>
                                            {formatAmount(totals.difference)}
                                        </strong>
                                    </span>
                                </div>

                                {errors.lines ? (
                                    <p className="text-sm text-destructive">
                                        {errors.lines}
                                    </p>
                                ) : null}

                                <Button
                                    type="submit"
                                    disabled={processing || !totals.balanced}
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    {processing
                                        ? 'Posting…'
                                        : 'Post opening balances'}
                                </Button>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
