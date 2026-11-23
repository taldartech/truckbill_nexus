import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

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
            <div className="mx-auto flex max-w-3xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={index_url}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Tickets
                    </Link>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Create support ticket
                    </h1>
                </div>

                {errors?.subject ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.subject}
                    </p>
                ) : null}

                <Form action={store_url} method="post" className="grid gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        Subject
                        <input
                            name="subject"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Description
                        <textarea
                            name="description"
                            required
                            rows={5}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Category
                        <input
                            name="category"
                            required
                            defaultValue="system"
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Priority
                        <select
                            name="priority"
                            required
                            defaultValue={priorities[1] ?? priorities[0]}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        >
                            {priorities.map((priority) => (
                                <option key={priority} value={priority}>
                                    {priority}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Due at
                        <input
                            name="due_at"
                            type="datetime-local"
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <Button type="submit">Save ticket</Button>
                </Form>
            </div>
        </>
    );
}
