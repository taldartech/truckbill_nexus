import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Station = {
    id: string;
    code: string;
    name: string;
    state: string | null;
    city: string | null;
    is_active: boolean;
};
type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    mode: 'create' | 'edit';
    station: Station | null;
    links: {
        hub: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};
export default function StationForm({ mode, station, links }: Props) {
    const action = mode === 'create' ? links.store! : links.update!;
    return (
        <>
            <Head
                title={
                    mode === 'create'
                        ? 'Create station'
                        : `Edit ${station?.name}`
                }
            />
            <LrfPage
                title={
                    mode === 'create'
                        ? 'Create station'
                        : `Edit ${station?.name}`
                }
                subtitle="Owner-shared station directory."
                backHref={links.index}
                backLabel="Stations"
            >
                <Form
                    action={action}
                    method={mode === 'create' ? 'post' : 'patch'}
                    className="space-y-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <LrfSection
                                step={1}
                                title="Station details"
                                description="Code, name, and location."
                                tone="receipt"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
                                    {mode === 'create' ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="code">Code</Label>
                                            <Input
                                                id="code"
                                                name="code"
                                                required
                                            />
                                            <InputError message={errors.code} />
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Code: {station?.code}
                                        </p>
                                    )}
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            defaultValue={station?.name ?? ''}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="state">State</Label>
                                        <Input
                                            id="state"
                                            name="state"
                                            defaultValue={station?.state ?? ''}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="city">City</Label>
                                        <Input
                                            id="city"
                                            name="city"
                                            defaultValue={station?.city ?? ''}
                                        />
                                    </div>
                                    <label className="flex items-center gap-2 text-sm md:col-span-2">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            value="1"
                                            defaultChecked={
                                                station?.is_active ?? true
                                            }
                                        />{' '}
                                        Active
                                    </label>
                                </div>
                            </LrfSection>
                            <LrfSection
                                step={2}
                                title="Remark"
                                description="Audit remark required on edit."
                                tone="optional"
                            >
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="remark">Remark</Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required={mode === 'edit'}
                                            defaultValue={
                                                mode === 'create'
                                                    ? 'Create station'
                                                    : ''
                                            }
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <div className="flex gap-3">
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {processing ? 'Saving…' : 'Save'}
                                        </Button>
                                        <Button asChild variant="outline">
                                            <Link
                                                href={links.show ?? links.index}
                                            >
                                                Cancel
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </LrfSection>
                        </>
                    )}
                </Form>
            </LrfPage>
        </>
    );
}
