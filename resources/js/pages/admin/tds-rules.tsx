import { Form, Head, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    compliance_note: string;
    store_url: string;
    rules: {
        id: string;
        code: string;
        name: string;
        priority: number;
        is_active: boolean;
        condition_logic: string;
        conditions: unknown;
        rate_percent: string;
        description: string | null;
        update_url: string;
    }[];
};

export default function AdminTdsRules({
    compliance_note,
    store_url,
    rules,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title="TDS rule master" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Tax masters
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        TDS rules
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {compliance_note}
                    </p>
                </div>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {(errors?.code ||
                    errors?.remark ||
                    errors?.conditions_json) && (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.code || errors.remark || errors.conditions_json}
                    </p>
                )}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Create rule</h2>
                    <Form
                        action={store_url}
                        method="post"
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Code
                            <input
                                name="code"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2 font-mono text-sm"
                                placeholder="PAN_INDIVIDUAL_1"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Name
                            <input
                                name="name"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Priority
                            <input
                                name="priority"
                                type="number"
                                min="1"
                                required
                                defaultValue={100}
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Condition logic
                            <select
                                name="condition_logic"
                                defaultValue="AND"
                                className="rounded-md border border-border bg-background px-3 py-2"
                            >
                                <option value="AND">AND</option>
                                <option value="OR">OR</option>
                            </select>
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Rate percent
                            <input
                                name="rate_percent"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Description
                            <input
                                name="description"
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm md:col-span-2">
                            Conditions JSON
                            <textarea
                                name="conditions_json"
                                required
                                rows={3}
                                className="rounded-md border border-border bg-background px-3 py-2 font-mono text-xs"
                                defaultValue='[{"field":"pan_status","operator":"eq","value":"valid"}]'
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm md:col-span-2">
                            Remark
                            <input
                                name="remark"
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <div className="md:col-span-2">
                            <Button type="submit">Create TDS rule</Button>
                        </div>
                    </Form>
                </section>

                {rules.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No TDS rules yet.
                    </p>
                ) : (
                    <ul className="space-y-4">
                        {rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="space-y-3 rounded-xl border border-border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-semibold">
                                            {rule.name}{' '}
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {rule.code}
                                            </span>
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Priority {rule.priority} ·{' '}
                                            {rule.condition_logic} ·{' '}
                                            {rule.rate_percent}% ·{' '}
                                            {rule.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </p>
                                    </div>
                                </div>
                                <pre className="overflow-x-auto rounded-md bg-muted/40 p-2 text-xs">
                                    {JSON.stringify(rule.conditions, null, 2)}
                                </pre>
                                <Form
                                    action={rule.update_url}
                                    method="post"
                                    className="flex flex-wrap items-end gap-2"
                                >
                                    <input
                                        type="hidden"
                                        name="_method"
                                        value="put"
                                    />
                                    <label className="flex flex-col gap-1 text-sm">
                                        Active
                                        <select
                                            name="is_active"
                                            defaultValue={
                                                rule.is_active ? '1' : '0'
                                            }
                                            className="rounded-md border border-border bg-background px-2 py-1"
                                        >
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                    </label>
                                    <label className="flex flex-col gap-1 text-sm">
                                        Priority
                                        <input
                                            name="priority"
                                            type="number"
                                            defaultValue={rule.priority}
                                            className="w-24 rounded-md border border-border bg-background px-2 py-1"
                                        />
                                    </label>
                                    <label className="flex min-w-[12rem] flex-1 flex-col gap-1 text-sm">
                                        Remark
                                        <input
                                            name="remark"
                                            required
                                            className="rounded-md border border-border bg-background px-2 py-1"
                                            placeholder="Required audit remark"
                                        />
                                    </label>
                                    <Button type="submit" size="sm">
                                        Update
                                    </Button>
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
