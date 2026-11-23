import { Form, Head, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

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

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

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
            <PageShell title="TDS rules" description={compliance_note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['code', 'remark', 'conditions_json']}
                />

                <Form action={store_url} method="post">
                    <FormSection title="Create rule" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                name="code"
                                required
                                className="font-mono"
                                placeholder="PAN_INDIVIDUAL_1"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" name="name" required />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="priority">Priority</Label>
                            <Input
                                id="priority"
                                name="priority"
                                type="number"
                                min="1"
                                required
                                defaultValue={100}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="condition_logic">
                                Condition logic
                            </Label>
                            <select
                                id="condition_logic"
                                name="condition_logic"
                                defaultValue="AND"
                                className={selectClass}
                            >
                                <option value="AND">AND</option>
                                <option value="OR">OR</option>
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="rate_percent">Rate percent</Label>
                            <Input
                                id="rate_percent"
                                name="rate_percent"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="description">Description</Label>
                            <Input id="description" name="description" />
                        </div>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="conditions_json">
                                Conditions JSON
                            </Label>
                            <textarea
                                id="conditions_json"
                                name="conditions_json"
                                required
                                rows={3}
                                className={textareaClass}
                                defaultValue='[{"field":"pan_status","operator":"eq","value":"valid"}]'
                            />
                        </div>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="create-remark">Remark</Label>
                            <Input id="create-remark" name="remark" />
                        </div>
                        <div className="md:col-span-2">
                            <Button type="submit" variant="default">
                                Create TDS rule
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                {rules.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No TDS rules yet.
                    </p>
                ) : (
                    <ul className="space-y-4">
                        {rules.map((rule) => (
                            <li key={rule.id}>
                                <FormSection
                                    title={rule.name}
                                    description={`${rule.code} · Priority ${rule.priority} · ${rule.condition_logic} · ${rule.rate_percent}%`}
                                    columns={2}
                                >
                                    <div className="flex flex-wrap items-center gap-2 md:col-span-2">
                                        <WorkspaceStatusBadge
                                            status={
                                                rule.is_active
                                                    ? 'active'
                                                    : 'deactivated'
                                            }
                                        />
                                    </div>
                                    <pre className="overflow-x-auto rounded-md bg-muted/40 p-2 text-xs md:col-span-2">
                                        {JSON.stringify(
                                            rule.conditions,
                                            null,
                                            2,
                                        )}
                                    </pre>
                                    <Form
                                        action={rule.update_url}
                                        method="post"
                                        className="contents"
                                    >
                                        <input
                                            type="hidden"
                                            name="_method"
                                            value="put"
                                        />
                                        <div className="space-y-2">
                                            <Label
                                                htmlFor={`active-${rule.id}`}
                                            >
                                                Active
                                            </Label>
                                            <select
                                                id={`active-${rule.id}`}
                                                name="is_active"
                                                defaultValue={
                                                    rule.is_active ? '1' : '0'
                                                }
                                                className={selectClass}
                                            >
                                                <option value="1">
                                                    Active
                                                </option>
                                                <option value="0">
                                                    Inactive
                                                </option>
                                            </select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label
                                                htmlFor={`priority-${rule.id}`}
                                            >
                                                Priority
                                            </Label>
                                            <Input
                                                id={`priority-${rule.id}`}
                                                name="priority"
                                                type="number"
                                                defaultValue={rule.priority}
                                            />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label
                                                htmlFor={`remark-${rule.id}`}
                                            >
                                                Remark
                                            </Label>
                                            <Input
                                                id={`remark-${rule.id}`}
                                                name="remark"
                                                required
                                                placeholder="Required audit remark"
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <AppIconAction
                                                icon={Save}
                                                label="Update TDS rule"
                                                type="submit"
                                                variant="warning"
                                                size="default"
                                                className="gap-2 px-3"
                                            >
                                                <span className="text-sm font-medium">
                                                    Update
                                                </span>
                                            </AppIconAction>
                                        </div>
                                    </Form>
                                </FormSection>
                            </li>
                        ))}
                    </ul>
                )}
            </PageShell>
        </>
    );
}
