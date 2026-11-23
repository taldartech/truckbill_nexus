import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
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
            <PageShell
                title="Cannot create Lorry Receipt"
                description="Workflow context is not available for a new LR."
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to lorry receipts"
                        href={urls.index}
                        variant="outline"
                    />
                }
            >
                <ShellEmptyState
                    title="Workflow context unavailable"
                    description={reason}
                    action={
                        <Button variant="secondary" asChild>
                            <Link href={urls.index}>Return to list</Link>
                        </Button>
                    }
                />
            </PageShell>
        </>
    );
}
