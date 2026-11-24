import { Form, Head, usePage } from '@inertiajs/react';
import { AppIconAction } from '@/components/app-icon-action';
import { ArrowLeft, Eye } from 'lucide-react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type Props = {
    identity: {
        id: number;
        name: string;
        email: string;
        identity_type: string | null;
        is_super_admin: boolean;
        is_active: boolean;
        deactivated_at: string | null;
        is_self: boolean;
    };
    owned_companies: {
        id: string;
        name: string;
        is_active: boolean;
        show_url: string;
    }[];
    urls: {
        index: string;
        deactivate: string;
        reactivate: string;
    };
};

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function AdminIdentityShow({
    identity,
    owned_companies,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={`${identity.email} · identity`} />
            <PageShell
                title={identity.name}
                description={`${identity.email} · ${identity.identity_type ?? '—'}${identity.is_super_admin ? ' · Super Admin' : ''}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge
                            status={
                                identity.is_active ? 'active' : 'deactivated'
                            }
                        />
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to identities"
                            href={urls.index}
                            variant="outline"
                        />
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} keys={['remark']} />

                <FormSection
                    title={
                        identity.is_active
                            ? 'Deactivate identity'
                            : 'Reactivate identity'
                    }
                    columns={2}
                >
                    {identity.is_self ? (
                        <p className="text-sm text-muted-foreground md:col-span-2">
                            You cannot change your own identity lifecycle from
                            this screen.
                        </p>
                    ) : (
                        <Form
                            action={
                                identity.is_active
                                    ? urls.deactivate
                                    : urls.reactivate
                            }
                            method="post"
                            className="contents"
                        >
                            <p className="text-sm text-muted-foreground md:col-span-2">
                                Deactivation revokes active sessions. Last Super
                                Admin protection remains enforced.
                            </p>
                            <div className="space-y-2 md:col-span-2">
                                <Label htmlFor="remark">Remark</Label>
                                <textarea
                                    id="remark"
                                    name="remark"
                                    required
                                    rows={3}
                                    className={textareaClass}
                                />
                            </div>
                            <label className="flex items-center gap-2 text-sm md:col-span-2">
                                <input
                                    type="checkbox"
                                    name="confirm"
                                    value="1"
                                    required
                                    className="size-4 rounded border border-input"
                                />
                                I confirm this identity lifecycle change
                            </label>
                            <div className="md:col-span-2">
                                <Button
                                    type="submit"
                                    variant={
                                        identity.is_active
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {identity.is_active
                                        ? 'Deactivate identity'
                                        : 'Reactivate identity'}
                                </Button>
                            </div>
                        </Form>
                    )}
                </FormSection>

                <FormSection title="Owned companies" columns={1}>
                    {owned_companies.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No owned companies.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {owned_companies.map((company) => (
                                <li
                                    key={company.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-muted/20 px-3 py-2"
                                >
                                    <span className="font-medium">
                                        {company.name}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <WorkspaceStatusBadge
                                            status={
                                                company.is_active
                                                    ? 'active'
                                                    : 'deactivated'
                                            }
                                        />
                                        <AppIconAction
                                            icon={Eye}
                                            label={`Open ${company.name}`}
                                            href={company.show_url}
                                            variant="info"
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </FormSection>
            </PageShell>
        </>
    );
}
