import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { StateCityFields } from '@/components/geography/state-city-fields';
import InputError from '@/components/input-error';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';

type Station = {
    id: string;
    code: string;
    name: string;
    state: string | null;
    city: string | null;
    state_id?: string | null;
    city_id?: string | null;
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
    const canQuickCreateGeography = readCanQuickCreateGeography(
        usePage().props,
    );
    const action = mode === 'create' ? links.store! : links.update!;
    const [stateId, setStateId] = useState(station?.state_id ?? '');
    const [cityId, setCityId] = useState(station?.city_id ?? '');
    const [state, setState] = useState(station?.state ?? '');
    const [city, setCity] = useState(station?.city ?? '');
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
                                    <div className="md:col-span-2">
                                        <StateCityFields
                                            canQuickCreate={
                                                canQuickCreateGeography
                                            }
                                            stateId={stateId}
                                            cityId={cityId}
                                            stateName={state}
                                            cityName={city}
                                            stateError={errors.state_id}
                                            cityError={errors.city_id}
                                            onChange={(next) => {
                                                setStateId(next.state_id);
                                                setCityId(next.city_id);
                                                setState(next.state);
                                                setCity(next.city);
                                            }}
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
                                title={mode === 'edit' ? 'Remark' : 'Save'}
                                description={
                                    mode === 'edit'
                                        ? 'Audit remark required when editing this station.'
                                        : undefined
                                }
                                tone="optional"
                            >
                                <div className="space-y-3">
                                    {mode === 'edit' ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="remark" required>
                                                Remark
                                            </Label>
                                            <Input
                                                id="remark"
                                                name="remark"
                                                required
                                            />
                                            <InputError
                                                message={errors.remark}
                                            />
                                        </div>
                                    ) : null}
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
