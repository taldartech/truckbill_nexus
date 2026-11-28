import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, FileText, Pencil, Wallet } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { BookBalanceDialog } from '@/components/broker/book-balance-dialog';
import {
    FbDetailCard,
    FbDetailPage,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import {
    DocumentDrawer,
    DocumentSectionList,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDisplayDate } from '@/lib/dates';
import { formatInr } from '@/lib/money';

type ProfileItem = { label: string; value: string; kind?: 'date' };

type ProfileSection = { key: string; title: string; items: ProfileItem[] };

type BookBalanceEntry = {
    id: string;
    transaction_date: string;
    transaction_type: string;
    particulars: string;
    reference_number: string | null;
    debit: string | null;
    credit: string | null;
};

type Props = {
    permissions: { can_edit: boolean };
    party: {
        id?: string;
        name: string;
        roles: string[];
        sections: ProfileSection[];
    };
    bookBalance?: {
        party_id?: string;
        party_name?: string;
        available_balance: string | null;
        running_balance?: string | null;
        can_manage?: boolean;
        today?: string | null;
        bank_cash_accounts?: Array<{
            id: string;
            name: string;
            account_kind?: string;
        }>;
        can_create_bank_cash?: boolean;
        recent_entries?: BookBalanceEntry[];
        store_url?: string | null;
        context_url?: string | null;
        bank_cash_store_url?: string | null;
        statement_url: string;
    } | null;
    driverMasterContext?: boolean;
    documentDrawer: Omit<DocumentDrawerProps, 'open' | 'onOpenChange'> | null;
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
    driverMasterContext = false,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);
    const [bookBalanceOpen, setBookBalanceOpen] = useState(false);

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
                                label={
                                    driverMasterContext
                                        ? 'Back to drivers'
                                        : 'Back to parties'
                                }
                                href={links.index}
                                size="sm"
                            />
                            {bookBalance?.can_manage &&
                            bookBalance.store_url ? (
                                <AppIconAction
                                    icon={Wallet}
                                    label="Book Balance"
                                    type="button"
                                    onClick={() => setBookBalanceOpen(true)}
                                    variant="primary"
                                    size="sm"
                                />
                            ) : null}
                            {documentDrawer ? (
                                <AppIconAction
                                    icon={FileText}
                                    label={documentDrawer.title}
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
                                label={
                                    driverMasterContext
                                        ? 'Edit driver'
                                        : 'Edit party'
                                }
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

                {documentDrawer ? (
                    <FbDetailCard
                        title={documentDrawer.title}
                        actions={
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() => setDocsOpen(true)}
                            >
                                Manage documents
                            </Button>
                        }
                    >
                        <div className="px-4 py-3">
                            <DocumentSectionList
                                sections={documentDrawer.sections}
                                attachments={documentDrawer.attachments}
                            />
                        </div>
                    </FbDetailCard>
                ) : null}

                {bookBalance ? (
                    <FbDetailCard
                        title="Transporter Book Balance"
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {bookBalance.can_manage &&
                                bookBalance.store_url ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                        onClick={() => setBookBalanceOpen(true)}
                                    >
                                        <Wallet className="mr-1.5 h-3.5 w-3.5" />
                                        Book Balance
                                    </Button>
                                ) : null}
                                <Button asChild size="sm" variant="outline">
                                    <Link href={bookBalance.statement_url}>
                                        View Statement
                                    </Link>
                                </Button>
                            </div>
                        }
                    >
                        <div className="space-y-4 px-4 py-3">
                            <FbInfoGrid relaxed>
                                <FbInfoItem
                                    label="Available Book Balance"
                                    value={
                                        <span
                                            className="text-base font-bold text-emerald-900 tabular-nums"
                                            data-testid="party-book-balance"
                                        >
                                            {bookBalance.available_balance !==
                                            null
                                                ? formatInr(
                                                      bookBalance.available_balance,
                                                  )
                                                : '—'}
                                        </span>
                                    }
                                />
                                <FbInfoItem
                                    label="Statement"
                                    value={
                                        <Link
                                            href={bookBalance.statement_url}
                                            className="text-sm font-medium text-[#0f766e] underline"
                                        >
                                            Open full transaction statement
                                        </Link>
                                    }
                                />
                            </FbInfoGrid>

                            {bookBalance.recent_entries &&
                            bookBalance.recent_entries.length > 0 ? (
                                <div className="overflow-x-auto rounded-md border border-border">
                                    <table className="w-full text-xs">
                                        <thead className="bg-muted/40 text-left text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-1.5">
                                                    Date
                                                </th>
                                                <th className="px-3 py-1.5">
                                                    Transaction Type
                                                </th>
                                                <th className="px-3 py-1.5">
                                                    Particulars
                                                </th>
                                                <th className="px-3 py-1.5">
                                                    Reference
                                                </th>
                                                <th className="px-3 py-1.5 text-end">
                                                    Debit
                                                </th>
                                                <th className="px-3 py-1.5 text-end">
                                                    Credit
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {bookBalance.recent_entries.map(
                                                (entry) => (
                                                    <tr
                                                        key={entry.id}
                                                        className="border-t border-border/60"
                                                    >
                                                        <td className="px-3 py-1.5">
                                                            {
                                                                entry.transaction_date
                                                            }
                                                        </td>
                                                        <td className="px-3 py-1.5">
                                                            {
                                                                entry.transaction_type
                                                            }
                                                        </td>
                                                        <td className="px-3 py-1.5 text-slate-600">
                                                            {entry.particulars}
                                                        </td>
                                                        <td className="px-3 py-1.5 font-mono text-slate-600">
                                                            {entry.reference_number ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-3 py-1.5 text-end text-emerald-700 tabular-nums">
                                                            {entry.debit &&
                                                            Number(
                                                                entry.debit,
                                                            ) > 0
                                                                ? formatInr(
                                                                      entry.debit,
                                                                  )
                                                                : '—'}
                                                        </td>
                                                        <td className="px-3 py-1.5 text-end text-amber-700 tabular-nums">
                                                            {entry.credit &&
                                                            Number(
                                                                entry.credit,
                                                            ) > 0
                                                                ? formatInr(
                                                                      entry.credit,
                                                                  )
                                                                : '—'}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            ) : null}
                        </div>
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

            {bookBalance?.can_manage &&
            bookBalance.store_url &&
            bookBalance.today &&
            (bookBalance.party_id ?? party.id) ? (
                <BookBalanceDialog
                    open={bookBalanceOpen}
                    onOpenChange={setBookBalanceOpen}
                    transporter={{
                        id: bookBalance.party_id ?? party.id!,
                        name: bookBalance.party_name ?? party.name,
                        available_balance: bookBalance.available_balance,
                    }}
                    contextUrl={bookBalance.context_url}
                    storeUrl={bookBalance.store_url}
                    bankCashAccounts={bookBalance.bank_cash_accounts ?? []}
                    bankCashStoreUrl={bookBalance.bank_cash_store_url}
                    canCreateBankCash={bookBalance.can_create_bank_cash}
                    today={bookBalance.today}
                    onRecorded={() => router.reload()}
                />
            ) : null}
        </>
    );
}
