import { Head, Link } from '@inertiajs/react';
import { Building2, Package, Truck } from 'lucide-react';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';

type Props = {
    website_url: string;
    demo_url: string;
    policies: {
        privacy: string;
        terms: string;
    };
    support_email: string;
};

const products = [
    {
        id: 'broker',
        title: 'Broker',
        icon: Building2,
        description:
            'Manage lorry hires, parties, and brokerage workflows from one workspace.',
    },
    {
        id: 'ownfleet',
        title: 'OwnFleet',
        icon: Truck,
        description:
            'Operate owned vehicles, trips, and fleet costs with branch-level control.',
    },
    {
        id: 'partload',
        title: 'Partload',
        icon: Package,
        description:
            'Consolidated part-load operations — coming to the platform.',
        availability: 'Coming to the platform',
    },
] as const;

export default function Welcome({
    website_url,
    demo_url,
    policies,
    support_email,
}: Props) {
    return (
        <>
            <Head title="Home" />
            <div className="flex min-h-screen flex-col bg-[var(--brand-surface-muted)] text-foreground">
                <header className="border-b border-border bg-card/80 backdrop-blur-sm">
                    <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4 md:px-6">
                        <a
                            href={website_url}
                            className="flex items-center gap-3"
                            aria-label="TruckBill on truckbill.in"
                        >
                            <img
                                src="/brand/truckbill-logo.svg"
                                alt=""
                                className="h-9 w-auto dark:brightness-110"
                            />
                        </a>
                        <nav className="flex flex-wrap items-center gap-2 sm:gap-3">
                            <a
                                href={website_url}
                                className="text-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Website
                            </a>
                            <Button asChild variant="default" size="sm">
                                <Link href={login()}>Login</Link>
                            </Button>
                        </nav>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-10 px-4 py-10 md:gap-12 md:px-6 md:py-14">
                    <section className="rounded-2xl border border-border bg-card p-6 shadow-xs md:p-10">
                        <div
                            className="mb-4 h-1 w-20 rounded-full"
                            style={{ backgroundImage: 'var(--brand-gradient)' }}
                            aria-hidden
                        />
                        <h1 className="max-w-3xl text-2xl font-semibold tracking-tight md:text-4xl">
                            Your Transport Business. One Powerful Platform.
                        </h1>
                        <p className="mt-4 max-w-2xl text-sm text-muted-foreground md:text-base">
                            TruckBill unifies broker, fleet, and logistics
                            operations with accounting-grade controls. Sign in
                            to your workspace or request a guided demo from our
                            team at{' '}
                            <a
                                href={`mailto:${support_email}`}
                                className="font-medium text-[var(--brand-blue)] underline-offset-4 hover:underline"
                            >
                                {support_email}
                            </a>
                            .
                        </p>
                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            <Button
                                asChild
                                size="lg"
                                className="bg-[var(--brand-green)] text-white hover:bg-[var(--brand-green)]/90"
                            >
                                <Link href={login()}>Login to TruckBill</Link>
                            </Button>
                            <Button asChild variant="outline" size="lg">
                                <a
                                    href={demo_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Request a Demo
                                </a>
                            </Button>
                        </div>
                    </section>

                    <section aria-labelledby="products-heading">
                        <h2
                            id="products-heading"
                            className="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Product suites
                        </h2>
                        <ul className="mt-4 grid gap-4 md:grid-cols-3">
                            {products.map((product) => {
                                const Icon = product.icon;
                                return (
                                    <li
                                        key={product.id}
                                        className="flex flex-col rounded-xl border border-border bg-card p-5 shadow-xs"
                                    >
                                        <div className="mb-3 flex size-10 items-center justify-center rounded-lg bg-[var(--brand-green)]/10 text-[var(--brand-green)]">
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </div>
                                        <h3 className="text-base font-semibold">
                                            {product.title}
                                        </h3>
                                        {'availability' in product ? (
                                            <p className="mt-1 text-xs font-medium text-[var(--brand-blue)]">
                                                {product.availability}
                                            </p>
                                        ) : null}
                                        <p className="mt-2 flex-1 text-sm text-muted-foreground">
                                            {product.description}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                </main>

                <footer className="mt-auto border-t border-border bg-card">
                    <div className="mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-6 text-sm text-muted-foreground md:flex-row md:flex-wrap md:items-center md:justify-between md:px-6">
                        <p>
                            A product by{' '}
                            <span className="font-medium text-foreground">
                                Taldar Tech Consultancy
                            </span>
                        </p>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <a
                                href={website_url}
                                className="underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Website
                            </a>
                            <a
                                href={policies.privacy}
                                className="underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Privacy
                            </a>
                            <a
                                href={policies.terms}
                                className="underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Terms
                            </a>
                            <a
                                href={`mailto:${support_email}`}
                                className="underline-offset-4 hover:text-foreground hover:underline"
                            >
                                {support_email}
                            </a>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
