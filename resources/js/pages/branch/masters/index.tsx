import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';

type Category = {
    key: string;
    title: string;
    description: string;
    href: string;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    categories: Category[];
    links: { dashboard: string };
};

type Flash = { flash?: { success?: string | null } };

export default function MastersHub({ branch, categories }: Props) {
    const { flash } = usePage<Flash>().props;
    return (
        <>
            <Head title={`Masters · ${branch.name}`} />
            <PageShell
                title="Masters"
                description="Parties, fleet, stations, and supporting reference data."
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <MetricCard
                    label="Categories"
                    value={categories.length}
                    hint="Master data areas available in this branch"
                    tone="blue"
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {categories.map((category) => (
                        <section
                            key={category.key}
                            className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-xs"
                        >
                            <h2 className="text-base font-semibold tracking-tight">
                                {category.title}
                            </h2>
                            <p className="flex-1 text-sm text-muted-foreground">
                                {category.description}
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                className="gap-2 self-start"
                            >
                                <Link href={category.href}>
                                    Open
                                    <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        </section>
                    ))}
                </div>
            </PageShell>
        </>
    );
}
