import { Head, Link } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';

type Option = {
    value: string;
    label: string;
    create_url: string;
};

type Props = {
    options: Option[];
    urls: { index: string };
};

export default function ChooseLrContext({ options, urls }: Props) {
    return (
        <>
            <Head title="Choose LR context" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Back to Lorry Receipts
                    </Link>
                    <MasterPageHeader title="Choose workflow context" />
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Your subscription includes more than one core module.
                        Choose which workflow this Lorry Receipt belongs to.
                    </p>
                </div>

                <div className="mx-auto grid w-full max-w-3xl gap-3 md:grid-cols-3">
                    {options.map((option) => (
                        <div
                            key={option.value}
                            className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4"
                        >
                            <div>
                                <h2 className="text-base font-semibold text-foreground">
                                    {option.label}
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Create an LR under the {option.label}{' '}
                                    workflow.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={option.create_url}>
                                    Continue with {option.label}
                                </Link>
                            </Button>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
