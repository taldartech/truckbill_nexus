import { Form, Head, Link } from '@inertiajs/react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    urls: { store: string; index: string };
};

export default function AccountingFundTransferCreate({ urls }: Props) {
    return (
        <>
            <Head title="Fund Transfer" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Cash / Bank Transfer"
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
                                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                    <div className="space-y-1">
                                        <Label htmlFor="from_ledger" required>
                                            From
                                        </Label>
                                        <select
                                            id="from_ledger"
                                            name="from_ledger"
                                            required
                                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                            defaultValue="cash"
                                        >
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank</option>
                                        </select>
                                        {errors.from_ledger ? (
                                            <p className="text-sm text-destructive">
                                                {errors.from_ledger}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="to_ledger" required>
                                            To
                                        </Label>
                                        <select
                                            id="to_ledger"
                                            name="to_ledger"
                                            required
                                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                            defaultValue="bank"
                                        >
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank</option>
                                        </select>
                                        {errors.to_ledger ? (
                                            <p className="text-sm text-destructive">
                                                {errors.to_ledger}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="amount" required>
                                            Amount
                                        </Label>
                                        <Input
                                            id="amount"
                                            name="amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            required
                                        />
                                        {errors.amount ? (
                                            <p className="text-sm text-destructive">
                                                {errors.amount}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="voucher_date">
                                            Date
                                        </Label>
                                        <Input
                                            id="voucher_date"
                                            name="voucher_date"
                                            type="date"
                                        />
                                    </div>
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="narration">Narration</Label>
                                    <Input id="narration" name="narration" />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Post Transfer
                                </Button>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
