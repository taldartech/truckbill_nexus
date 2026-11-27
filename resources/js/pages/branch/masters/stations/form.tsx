import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { diffScalarFields } from '@/components/form-confirmation/diff';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { ChangeReview } from '@/components/form-confirmation/types';
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
    name: string;
    state: string | null;
    city: string | null;
    state_id?: string | null;
    city_id?: string | null;
    is_active: boolean;
};
const STATION_FORM_ID = 'station-form';

function stationFormElement(): HTMLFormElement | null {
    const form = document.getElementById(STATION_FORM_ID);
    return form instanceof HTMLFormElement ? form : null;
}

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
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [review, setReview] = useState<{
        name: string;
        isActive: boolean;
        remark: string;
    }>({ name: '', isActive: true, remark: '' });

    const openConfirmation = (): boolean => {
        if (submitAllowed.current) {
            submitAllowed.current = false;
            return true;
        }
        const form = stationFormElement();
        if (!form) {
            return true;
        }
        const data = new FormData(form);
        const text = (field: string): string => {
            const value = data.get(field);
            return typeof value === 'string' ? value.trim() : '';
        };
        setReview({
            name: text('name'),
            isActive: data.get('is_active') === '1',
            remark: text('remark'),
        });
        setConfirmOpen(true);
        return false;
    };

    const confirmSubmit = () => {
        submitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => stationFormElement()?.requestSubmit(), 0);
    };

    const yesNo = (value: unknown): string => (value ? 'Active' : 'Inactive');
    const changes: ChangeReview[] =
        mode === 'edit' && station
            ? diffScalarFields(
                  {
                      name: station.name,
                      state: station.state ?? '',
                      city: station.city ?? '',
                      is_active: station.is_active,
                  },
                  {
                      name: review.name,
                      state,
                      city,
                      is_active: review.isActive,
                  },
                  [
                      { key: 'name', label: 'Name' },
                      { key: 'state', label: 'State' },
                      { key: 'city', label: 'City' },
                      { key: 'is_active', label: 'Status', format: yesNo },
                  ],
              )
            : [];

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
                    id={STATION_FORM_ID}
                    action={action}
                    method={mode === 'create' ? 'post' : 'patch'}
                    className="space-y-3"
                    onBefore={openConfirmation}
                >
                    {({ errors, processing }) => (
                        <>
                            <LrfSection
                                step={1}
                                title="Station details"
                                description="Name and geography."
                                tone="receipt"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="name" required>
                                            Name
                                        </Label>
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
                                            required
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
                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode={mode}
                    title={
                        mode === 'create'
                            ? 'Create this station?'
                            : 'Save station changes?'
                    }
                    sections={[
                        {
                            title: 'Station',
                            fields: [
                                { label: 'Name', value: review.name },
                                { label: 'State', value: state },
                                { label: 'City', value: city },
                                {
                                    label: 'Status',
                                    value: yesNo(review.isActive),
                                },
                            ],
                        },
                    ]}
                    changes={changes}
                    remark={mode === 'edit' ? review.remark : undefined}
                    onConfirm={confirmSubmit}
                />
            </LrfPage>
        </>
    );
}
