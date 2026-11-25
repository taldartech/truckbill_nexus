import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { QuickCreateLedgerSheet } from '@/components/accounting/quick-create-ledger-sheet';
import {
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Ledger = { id: string; name: string };
type AccountGroup = { id: string; name: string; nature: string };

type Line = {
    gl_ledger_id: string;
    debit_amount: string;
    credit_amount: string;
    narration: string;
};

type Props = {
    voucherTypeOptions: Array<{ value: string; label: string }>;
    ledgers: Ledger[];
    accountGroups: AccountGroup[];
    urls: { store: string; index: string; ledger_quick_create: string };
};

export default function AccountingVoucherCreate({
    voucherTypeOptions,
    ledgers: initialLedgers,
    accountGroups,
    urls,
}: Props) {
    const [ledgers, setLedgers] = useState<Ledger[]>(initialLedgers);
    const [quickCreateOpen, setQuickCreateOpen] = useState(false);
    const [quickCreateLineIndex, setQuickCreateLineIndex] = useState(0);
    const [lines, setLines] = useState<Line[]>([
        { gl_ledger_id: '', debit_amount: '', credit_amount: '', narration: '' },
        { gl_ledger_id: '', debit_amount: '', credit_amount: '', narration: '' },
    ]);

    const totalDebit = lines.reduce((sum, line) => sum + Number(line.debit_amount || 0), 0);
    const totalCredit = lines.reduce((sum, line) => sum + Number(line.credit_amount || 0), 0);
    const balanced = Math.abs(totalDebit - totalCredit) < 0.005 && totalDebit > 0;

    const openQuickCreate = (lineIndex: number) => {
        setQuickCreateLineIndex(lineIndex);
        setQuickCreateOpen(true);
    };

    return (
        <>
            <Head title="New Accounting Voucher" />
            <AppIndexPage>
                <AppIndexListCard
                    title="New Voucher"
                    toolbar={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.index}>Day Book</Link>
                        </Button>
                    }
                >
                    <Form action={urls.store} method="post" className="space-y-4 p-4">
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 md:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="voucher_type" required>
                                            Voucher Type
                                        </Label>
                                        <select
                                            id="voucher_type"
                                            name="voucher_type"
                                            required
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                            defaultValue="journal"
                                        >
                                            {voucherTypeOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="voucher_date" required>
                                            Date
                                        </Label>
                                        <Input id="voucher_date" name="voucher_date" type="date" required />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="narration">Narration</Label>
                                        <Input id="narration" name="narration" />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <Label required>Lines</Label>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setLines((current) => [
                                                    ...current,
                                                    {
                                                        gl_ledger_id: '',
                                                        debit_amount: '',
                                                        credit_amount: '',
                                                        narration: '',
                                                    },
                                                ])
                                            }
                                        >
                                            Add Line
                                        </Button>
                                    </div>
                                    {errors.lines ? (
                                        <p className="text-sm text-destructive">{errors.lines}</p>
                                    ) : null}
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full min-w-[720px] text-left text-sm">
                                            <thead className="border-b bg-muted/40 text-muted-foreground">
                                                <tr>
                                                    <th className="px-3 py-2 font-medium">Ledger</th>
                                                    <th className="px-3 py-2 font-medium">Debit</th>
                                                    <th className="px-3 py-2 font-medium">Credit</th>
                                                    <th className="px-3 py-2 font-medium">Narration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {lines.map((line, index) => (
                                                    <tr key={index} className="border-b last:border-0">
                                                        <td className="px-3 py-2">
                                                            <div className="space-y-1">
                                                                <div className="flex items-center justify-between gap-2">
                                                                    <span className="text-xs text-muted-foreground">
                                                                        Ledger *
                                                                    </span>
                                                                    <button
                                                                        type="button"
                                                                        className="text-xs font-medium text-primary hover:underline"
                                                                        onClick={() => openQuickCreate(index)}
                                                                    >
                                                                        + Add
                                                                    </button>
                                                                </div>
                                                                <select
                                                                    name={`lines[${index}][gl_ledger_id]`}
                                                                    required
                                                                    className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                                                    value={line.gl_ledger_id}
                                                                    onChange={(event) => {
                                                                        const value = event.target.value;
                                                                        setLines((current) =>
                                                                            current.map((row, rowIndex) =>
                                                                                rowIndex === index
                                                                                    ? { ...row, gl_ledger_id: value }
                                                                                    : row,
                                                                            ),
                                                                        );
                                                                    }}
                                                                >
                                                                    <option value="">Select</option>
                                                                    {ledgers.map((ledger) => (
                                                                        <option key={ledger.id} value={ledger.id}>
                                                                            {ledger.name}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                name={`lines[${index}][debit_amount]`}
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                value={line.debit_amount}
                                                                onChange={(event) => {
                                                                    const value = event.target.value;
                                                                    setLines((current) =>
                                                                        current.map((row, rowIndex) =>
                                                                            rowIndex === index
                                                                                ? {
                                                                                      ...row,
                                                                                      debit_amount: value,
                                                                                      credit_amount: value
                                                                                          ? ''
                                                                                          : row.credit_amount,
                                                                                  }
                                                                                : row,
                                                                        ),
                                                                    );
                                                                }}
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                name={`lines[${index}][credit_amount]`}
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                value={line.credit_amount}
                                                                onChange={(event) => {
                                                                    const value = event.target.value;
                                                                    setLines((current) =>
                                                                        current.map((row, rowIndex) =>
                                                                            rowIndex === index
                                                                                ? {
                                                                                      ...row,
                                                                                      credit_amount: value,
                                                                                      debit_amount: value
                                                                                          ? ''
                                                                                          : row.debit_amount,
                                                                                  }
                                                                                : row,
                                                                        ),
                                                                    );
                                                                }}
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                name={`lines[${index}][narration]`}
                                                                value={line.narration}
                                                                onChange={(event) => {
                                                                    const value = event.target.value;
                                                                    setLines((current) =>
                                                                        current.map((row, rowIndex) =>
                                                                            rowIndex === index
                                                                                ? { ...row, narration: value }
                                                                                : row,
                                                                        ),
                                                                    );
                                                                }}
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div className="text-sm">
                                        Debit {totalDebit.toFixed(2)} · Credit {totalCredit.toFixed(2)}
                                        {!balanced ? (
                                            <span className="ml-2 text-destructive">Not balanced</span>
                                        ) : (
                                            <span className="ml-2 text-emerald-700">Balanced</span>
                                        )}
                                    </div>
                                </div>

                                <Button type="submit" disabled={processing || !balanced}>
                                    Post Voucher
                                </Button>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>

            <QuickCreateLedgerSheet
                open={quickCreateOpen}
                onOpenChange={setQuickCreateOpen}
                storeUrl={urls.ledger_quick_create}
                accountGroups={accountGroups}
                onCreated={(ledger) => {
                    setLedgers((current) =>
                        current.some((row) => row.id === ledger.id)
                            ? current
                            : [...current, ledger],
                    );
                    setLines((current) =>
                        current.map((row, rowIndex) =>
                            rowIndex === quickCreateLineIndex
                                ? { ...row, gl_ledger_id: ledger.id }
                                : row,
                        ),
                    );
                }}
            />
        </>
    );
}
