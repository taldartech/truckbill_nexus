import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
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
            <AppIndexPage>
                <AppIndexListCard title="Masters">
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}
                    <div className="space-y-4 p-4">
                        <p className="text-sm text-slate-600">
                            Parties, fleet, stations, and supporting reference
                            data.
                        </p>
                        <p className="text-xs text-slate-500">
                            {categories.length} master data areas available in
                            this branch
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {categories.map((category) => (
                                <section
                                    key={category.key}
                                    className="flex flex-col gap-3 rounded-xl border border-[#e5e7eb] bg-white p-5 shadow-xs"
                                >
                                    <h2 className="text-base font-semibold tracking-tight text-slate-800">
                                        {category.title}
                                    </h2>
                                    <p className="flex-1 text-sm text-slate-500">
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
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
