import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { PortalErrorList } from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AssignableUser = { id: number; name: string; email: string };

type Props = {
    sources: string[];
    products: string[];
    assignable_users?: AssignableUser[];
    can_assign?: boolean;
    store_url: string;
    index_url: string;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function CrmLeadCreate({
    sources,
    products,
    assignable_users = [],
    can_assign = false,
    store_url,
    index_url,
}: Props) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title="Create lead" />
            <PageShell
                title="Create lead"
                description="Capture prospect details for qualification and follow-up."
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to leads"
                        href={index_url}
                        variant="outline"
                    />
                }
            >
                <PortalErrorList errors={errors} keys={['email']} />

                <Form action={store_url} method="post" className="space-y-6">
                    <FormSection title="Contact" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="contact_name">Contact name</Label>
                            <Input
                                id="contact_name"
                                name="contact_name"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" name="email" type="email" />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="mobile">Mobile</Label>
                            <Input id="mobile" name="mobile" />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="company_name">Company name</Label>
                            <Input id="company_name" name="company_name" />
                        </div>
                    </FormSection>

                    <FormSection title="Geography" columns={1}>
                        <StateCityFields valueMode="names" required={false} />
                    </FormSection>

                    <FormSection title="Pipeline" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="source">Source</Label>
                            <select
                                id="source"
                                name="source"
                                required
                                className={selectClass}
                                defaultValue={sources[0]}
                            >
                                {sources.map((source) => (
                                    <option key={source} value={source}>
                                        {source}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="interested_product">
                                Interested product
                            </Label>
                            <select
                                id="interested_product"
                                name="interested_product"
                                required
                                className={selectClass}
                                defaultValue={products[0]}
                            >
                                {products.map((product) => (
                                    <option key={product} value={product}>
                                        {product}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {can_assign ? (
                            <div className="space-y-2 md:col-span-2">
                                <Label htmlFor="assigned_to_user_id">
                                    Assign to
                                </Label>
                                <select
                                    id="assigned_to_user_id"
                                    name="assigned_to_user_id"
                                    className={selectClass}
                                    defaultValue=""
                                >
                                    <option value="">Unassigned</option>
                                    {assignable_users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        ) : null}
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="remarks">Remarks</Label>
                            <textarea
                                id="remarks"
                                name="remarks"
                                rows={3}
                                className={textareaClass}
                            />
                        </div>
                    </FormSection>

                    <FormSection
                        title="Duplicate handling"
                        description="Override only when the duplicate warning is a false positive."
                        columns={2}
                    >
                        <label className="flex items-center gap-2 text-sm md:col-span-2">
                            <input
                                type="checkbox"
                                name="duplicate_override"
                                value="1"
                                className="size-4 rounded border border-input"
                            />
                            Override potential duplicate warning
                        </label>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="duplicate_override_remark">
                                Duplicate override remark
                            </Label>
                            <textarea
                                id="duplicate_override_remark"
                                name="duplicate_override_remark"
                                rows={2}
                                className={textareaClass}
                            />
                        </div>
                    </FormSection>

                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                        <Button asChild variant="outline">
                            <Link href={index_url}>Cancel</Link>
                        </Button>
                        <AppIconAction
                            icon={Save}
                            label="Save lead"
                            type="submit"
                            variant="default"
                            size="default"
                            className="gap-2 px-3"
                        >
                            <span className="text-sm font-medium">Save</span>
                        </AppIconAction>
                    </div>
                </Form>
            </PageShell>
        </>
    );
}
