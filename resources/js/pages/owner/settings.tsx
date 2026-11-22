import { Form, Head, Link, usePage } from '@inertiajs/react';
import OwnerSettingsController from '@/actions/App/Http/Controllers/Owner/OwnerSettingsController';
import { CompanySwitcher } from '@/components/company-switcher';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type DeferredSection = {
    key: string;
    title: string;
    message: string;
};

type OwnerSettingsProps = {
    company: {
        id: string;
        name: string;
        status: string;
        owner: { id: number; name: string; email: string } | null;
        updated_at: string | null;
    };
    ownerPanelScope: string;
    canEditProfile: boolean;
    deferredSections: DeferredSection[];
    links: {
        dashboard: string;
        update: string;
    };
};

type FlashPageProps = {
    flash?: { success?: string | null };
};

export default function OwnerSettings({
    company,
    ownerPanelScope,
    canEditProfile,
    deferredSections,
    links,
}: OwnerSettingsProps) {
    const { flash } = usePage<FlashPageProps>().props;

    return (
        <>
            <Head title={`Settings · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Company settings
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <WorkspaceStatusBadge status={company.status} />
                            <span>Scope: {ownerPanelScope}</span>
                        </div>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <section className="max-w-xl space-y-4 rounded-xl border border-border p-4">
                    <div>
                        <h2 className="font-medium">Company profile</h2>
                        <p className="text-sm text-muted-foreground">
                            Display name and ownership identity. Legal and tax
                            identifiers are not available until schema and
                            policy are approved.
                        </p>
                    </div>

                    <dl className="grid gap-2 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Status</dt>
                            <dd>
                                <WorkspaceStatusBadge status={company.status} />
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Owner</dt>
                            <dd className="text-right">
                                {company.owner
                                    ? `${company.owner.name} (${company.owner.email})`
                                    : '—'}
                            </dd>
                        </div>
                    </dl>

                    {canEditProfile ? (
                        <Form
                            {...OwnerSettingsController.update.form(company.id)}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Company name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={company.name}
                                            required
                                            maxLength={255}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="remark">
                                            Remark (required)
                                        </Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required
                                            maxLength={1000}
                                            placeholder="Reason for this change"
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        data-test="update-company-profile"
                                    >
                                        Save profile
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Profile editing is read-only while the company is
                            deactivated.
                        </p>
                    )}
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    {deferredSections.map((section) => (
                        <div
                            key={section.key}
                            className="rounded-xl border border-dashed border-border p-4"
                        >
                            <h2 className="font-medium">{section.title}</h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {section.message}
                            </p>
                        </div>
                    ))}
                </section>

                <div>
                    <Button asChild variant="secondary">
                        <Link href={links.dashboard}>Back to Owner Panel</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
