import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, FileText, Pencil } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    FbDetailCard,
    FbDetailPage,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDisplayDate } from '@/lib/dates';

type ProfileItem = { label: string; value: string; kind?: 'date' };

type ProfileSection = { key: string; title: string; items: ProfileItem[] };

type Props = {
    permissions: { can_edit: boolean };
    party: { name: string; roles: string[]; sections: ProfileSection[] };
    bookBalance?: {
        available_balance: string;
        statement_url: string;
    } | null;
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: { index: string; edit: string; documents: string };
};

function ProfileItems({ items }: { items: ProfileItem[] }) {
    return (
        <FbInfoGrid>
            {items.map((item) => (
                <FbInfoItem
                    key={item.label}
                    label={item.label}
                    value={
                        item.kind === 'date'
                            ? formatDisplayDate(item.value)
                            : item.value
                    }
                    full={item.label === 'Address'}
                />
            ))}
        </FbInfoGrid>
    );
}

export default function PartyShow({
    permissions,
    party,
    bookBalance = null,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);
    const basicSection = party.sections.find(
        (section) => section.key === 'basic',
    );
    const otherSections = party.sections.filter(
        (section) => section.key !== 'basic',
    );

    return (
        <>
            <Head title={party.name} />
            <FbDetailPage>
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FbDetailCard
                    title={<span data-testid="party-name">{party.name}</span>}
                    badges={
                        <>
                            <span
                                className="flex flex-wrap gap-1"
                                aria-label="Roles"
                            >
                                {party.roles.map((role) => (
                                    <Badge key={role} variant="secondary">
                                        {role}
                                    </Badge>
                                ))}
                            </span>
                            <Badge variant="outline">Active</Badge>
                        </>
                    }
                    actions={
                        <>
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.index}>
                                    <ArrowLeft className="size-4" />
                                    Back
                                </Link>
                            </Button>
                            {documentDrawer ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-2"
                                    onClick={() => setDocsOpen(true)}
                                >
                                    <FileText className="size-4" />
                                    Documents
                                </Button>
                            ) : (
                                <AppIconAction
                                    icon={FileText}
                                    label="Documents"
                                    href={links.documents}
                                    variant="info"
                                    size="sm"
                                />
                            )}
                            <AppIconAction
                                icon={Pencil}
                                label="Edit party"
                                href={links.edit}
                                variant="warning"
                                size="sm"
                                visible={permissions.can_edit}
                            />
                        </>
                    }
                >
                    {basicSection ? (
                        <ProfileItems items={basicSection.items} />
                    ) : null}
                </FbDetailCard>

                {otherSections.map((section) => (
                    <FbDetailCard key={section.key} title={section.title}>
                        <ProfileItems items={section.items} />
                    </FbDetailCard>
                ))}

                {bookBalance ? (
                    <FbDetailCard title="Financial">
                        <FbInfoGrid>
                            <FbInfoItem
                                label="Available advance balance"
                                value={bookBalance.available_balance}
                            />
                            <FbInfoItem
                                label="Statement"
                                value={
                                    <a
                                        href={bookBalance.statement_url}
                                        className="text-sm font-medium text-[#0f766e] underline"
                                    >
                                        View book balance detail
                                    </a>
                                }
                            />
                        </FbInfoGrid>
                    </FbDetailCard>
                ) : null}
            </FbDetailPage>

            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    {...documentDrawer}
                />
            ) : null}
        </>
    );
}
