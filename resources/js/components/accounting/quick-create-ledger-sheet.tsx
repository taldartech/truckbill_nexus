import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

type AccountGroup = { id: string; name: string; nature: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    accountGroups: AccountGroup[];
    onCreated: (ledger: { id: string; name: string }) => void;
};

export function QuickCreateLedgerSheet({
    open,
    onOpenChange,
    storeUrl,
    accountGroups,
    onCreated,
}: Props) {
    const [name, setName] = useState('');
    const [accountGroupId, setAccountGroupId] = useState(
        accountGroups[0]?.id ?? '',
    );
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const reset = () => {
        setName('');
        setAccountGroupId(accountGroups[0]?.id ?? '');
        setError(null);
    };

    const submit = async () => {
        setProcessing(true);
        setError(null);
        try {
            const token = (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content;
            const response = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({
                    name,
                    account_group_id: accountGroupId,
                }),
            });
            const json = (await response.json()) as {
                data?: { id: string; name: string };
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!response.ok) {
                const firstError =
                    json.errors?.name?.[0] ??
                    json.errors?.account_group_id?.[0] ??
                    json.message ??
                    'Unable to create ledger.';
                setError(firstError);
                return;
            }
            if (json.data) {
                onCreated(json.data);
                reset();
                onOpenChange(false);
            }
        } catch {
            setError('Unable to create ledger.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                }
                onOpenChange(next);
            }}
        >
            <SheetContent className="sm:max-w-md" style={{ zIndex: 200 }}>
                <SheetHeader>
                    <SheetTitle>Add Ledger</SheetTitle>
                    <SheetDescription>
                        Create a ledger without leaving the voucher form.
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-3 py-4">
                    <div className="space-y-1">
                        <Label htmlFor="quick-ledger-name" required>
                            Ledger Name
                        </Label>
                        <Input
                            id="quick-ledger-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            autoFocus
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="quick-ledger-group" required>
                            Account Group
                        </Label>
                        <select
                            id="quick-ledger-group"
                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                            value={accountGroupId}
                            onChange={(event) =>
                                setAccountGroupId(event.target.value)
                            }
                        >
                            {accountGroups.map((group) => (
                                <option key={group.id} value={group.id}>
                                    {group.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    {error ? (
                        <p className="text-sm text-destructive">{error}</p>
                    ) : null}
                </div>
                <SheetFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={
                            processing ||
                            name.trim() === '' ||
                            accountGroupId === ''
                        }
                        onClick={() => void submit()}
                    >
                        Create
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
