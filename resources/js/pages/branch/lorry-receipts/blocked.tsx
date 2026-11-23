import { Head, Link } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';

type Props = {
    reason: string;
    urls: { index: string };
};

export default function LorryReceiptBlocked({ reason, urls }: Props) {
    return (
        <>
            <Head title="LR unavailable" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Back to Lorry Receipts
                    </Link>
                    <MasterPageHeader title="Cannot create Lorry Receipt" />
                </div>

                <ShellEmptyState
                    title="Workflow context unavailable"
                    description={reason}
                    action={
                        <Button variant="secondary" asChild>
                            <Link href={urls.index}>Return to list</Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}
