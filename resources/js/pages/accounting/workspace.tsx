import { Head, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    BookOpen,
    ChartColumn,
    ChevronRight,
    NotebookTabs,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type WorkspaceLink = {
    key: string;
    label: string;
    description: string;
    href: string;
};

type WorkspaceSection = {
    key: string;
    title: string;
    links: WorkspaceLink[];
};

type Props = {
    periodLabel: string | null;
    permissions: { can_create: boolean };
    sections: WorkspaceSection[];
    urls: { day_book: string; create_voucher: string | null };
};

const sectionIcons: Record<string, LucideIcon> = {
    transactions: NotebookTabs,
    masters: BookOpen,
    reports: ChartColumn,
    compliance: ShieldCheck,
};

export default function AccountingWorkspace({
    periodLabel,
    permissions,
    sections,
    urls,
}: Props) {
    return (
        <>
            <Head title="Accounting" />
            <PageShell
                title="Accounting"
                description={
                    periodLabel
                        ? `Financial Year ${periodLabel}`
                        : 'Books of accounts for this branch.'
                }
                actions={
                    <>
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.day_book}>Day Book</Link>
                        </Button>
                        {permissions.can_create && urls.create_voucher ? (
                            <Button asChild size="sm">
                                <Link href={urls.create_voucher}>
                                    <Plus aria-hidden />
                                    New Voucher
                                </Link>
                            </Button>
                        ) : null}
                    </>
                }
            >
                {sections.length === 0 ? (
                    <ShellEmptyState
                        title="No accounting screens available"
                        description="Your role does not include any Accounting screens for this branch."
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
                        {sections.map((section) => (
                            <WorkspaceSectionCard
                                key={section.key}
                                section={section}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </>
    );
}

function WorkspaceSectionCard({ section }: { section: WorkspaceSection }) {
    const Icon = sectionIcons[section.key] ?? NotebookTabs;
    const headingId = `accounting-section-${section.key}`;

    return (
        <section aria-labelledby={headingId}>
            <Card className="h-full gap-3 py-4">
                <CardHeader className="flex-row items-center gap-2 px-4">
                    <Icon className="size-4 text-primary" aria-hidden />
                    <CardTitle className="text-sm">
                        <h2 id={headingId}>{section.title}</h2>
                    </CardTitle>
                </CardHeader>
                <CardContent className="px-2">
                    <ul className="divide-y divide-border">
                        {section.links.map((link) => (
                            <li key={link.key}>
                                <Link
                                    href={link.href}
                                    className="group flex items-center gap-2 rounded-md px-2 py-2 outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium text-foreground group-hover:text-accent-foreground">
                                            {link.label}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {link.description}
                                        </span>
                                    </span>
                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </section>
    );
}
