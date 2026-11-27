import { Head, usePage } from '@inertiajs/react';
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
        <FbInfoGrid relaxed>
            {items.map((item) => (
                <FbInfoItem
                    key={item.label}
                    label={item.label}
                    value={
                        item.kind === 'date'
                            ? formatDisplayDate(item.value)
                            : item.value
                    }
                    span2={item.label === 'Address'}
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

    return (
        <>
            <Head title={party.name} />
            <FbDetailPage className="space-y-4">
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FbDetailCard
                    title={<span data-testid="party-name">{party.name}</span>}
                    badges={<Badge variant="outline">Active</Badge>}
                    meta={
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
                    }
                    actions={
                        <>
                            <AppIconAction
                                icon={ArrowLeft}
                                label="Back to parties"
                                href={links.index}
                                size="sm"
                            />
                            {documentDrawer ? (
                                <AppIconAction
                                    icon={FileText}
                                    label="Documents"
                                    onClick={() => setDocsOpen(true)}
                                    variant="info"
                                    size="sm"
                                />
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
                />

                {party.sections.map((section) => (
                    <FbDetailCard key={section.key} title={section.title}>
                        <ProfileItems items={section.items} />
                    </FbDetailCard>
                ))}

                {bookBalance ? (
                    <FbDetailCard title="Financial">
                        <FbInfoGrid relaxed>
                            <FbInfoItem
                                label="Available advance balance"
                                value={bookBalance.available_balance}
                            />
                            <FbInfoItem
                                label="Statement"
                                value={
                                    <a
                                        href={bookBalance.statement_url}
                                        className="text-sm text-[#0f766e] underline"
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
