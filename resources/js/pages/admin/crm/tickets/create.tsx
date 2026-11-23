import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    priorities: string[];
    store_url: string;
    index_url: string;
};

export default function CrmTicketCreate({
    priorities,
    store_url,
    index_url,
}: Props) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title="Create support ticket" />
            <PageShell
                title="Create support ticket"
                description="Capture the issue clearly so Support can triage and respond."
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to tickets"
                        href={index_url}
                    />
                }
            >
                {errors?.subject ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.subject}
                    </p>
                ) : null}

                <Form action={store_url} method="post" className="space-y-6">
                    <FormSection title="Ticket details" columns={2}>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="subject">Subject</Label>
                            <Input id="subject" name="subject" required />
                            {errors?.subject ? (
                                <p className="text-sm text-destructive">
                                    {errors.subject}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                name="description"
                                required
                                rows={8}
                                className="min-h-40 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            {errors?.description ? (
                                <p className="text-sm text-destructive">
                                    {errors.description}
                                </p>
                            ) : null}
                        </div>
                    </FormSection>

                    <FormSection title="Classification" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="category">Category</Label>
                            <Input
                                id="category"
                                name="category"
                                required
                                defaultValue="system"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="priority">Priority</Label>
                            <select
                                id="priority"
                                name="priority"
                                required
                                defaultValue={priorities[1] ?? priorities[0]}
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                {priorities.map((priority) => (
                                    <option key={priority} value={priority}>
                                        {priority}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="due_at">Due date / time</Label>
                            <Input
                                id="due_at"
                                name="due_at"
                                type="datetime-local"
                            />
                        </div>
                    </FormSection>

                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                        <Button asChild variant="outline">
                            <Link href={index_url}>Cancel</Link>
                        </Button>
                        <AppIconAction
                            icon={Save}
                            label="Save ticket"
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
