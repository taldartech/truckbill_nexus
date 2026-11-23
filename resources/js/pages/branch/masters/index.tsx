import { Head, Link, usePage } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';

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
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader title="Masters" />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {categories.map((category) => (
                        <section
                            key={category.key}
                            className="flex flex-col gap-3 rounded-xl border border-border p-4"
                        >
                            <h2 className="text-lg font-semibold">
                                {category.title}
                            </h2>
                            <p className="flex-1 text-sm text-muted-foreground">
                                {category.description}
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                className="self-start"
                            >
                                <Link href={category.href}>Open</Link>
                            </Button>
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}
