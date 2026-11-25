import { Form, Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
type StateRow = {
    id: string;
    name: string;
    code: string;
    gst_code: string;
    is_active: boolean;
    cities_count: number;
};

type CityRow = {
    id: string;
    name: string;
    is_active: boolean;
    state_id: string;
};

type Props = {
    filters: {
        state_id: string;
        q: string;
    };
    states: StateRow[];
    cities: CityRow[];
    urls: {
        index: string;
        store_city: string;
        toggle_city: string;
    };
    note: string;
};

function toggleCityUrl(template: string, cityId: string): string {
    return template.replace('CITY_ID', cityId);
}

export default function AdminLocations({
    filters,
    states,
    cities,
    urls,
    note,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [citySearch, setCitySearch] = useState(filters.q);
    const selectedState = states.find((row) => row.id === filters.state_id);

    const stateColumns = useMemo<DataTableColumn<StateRow>[]>(
        () => [
            {
                id: 'name',
                header: 'State',
                priority: 0,
                cell: (row) => row.name,
            },
            {
                id: 'code',
                header: 'Code',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.code,
            },
            {
                id: 'gst_code',
                header: 'GST code',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.gst_code,
            },
            {
                id: 'cities',
                header: 'Cities',
                priority: 2,
                cell: (row) => row.cities_count,
            },
            {
                id: 'active',
                header: 'Active',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    const cityColumns = useMemo<DataTableColumn<CityRow>[]>(
        () => [
            {
                id: 'name',
                header: 'City',
                priority: 0,
                cell: (row) => row.name,
            },
            {
                id: 'active',
                header: 'Active',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    const selectState = (stateId: string) => {
        router.get(
            urls.index,
            { state_id: stateId, q: undefined },
            { preserveState: true, replace: true },
        );
    };

    const applyCitySearch = (event: React.FormEvent) => {
        event.preventDefault();
        if (!filters.state_id) {
            return;
        }
        router.get(
            urls.index,
            {
                state_id: filters.state_id,
                q: citySearch.trim() || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Locations master" />
            <PageShell title="State & city master" description={note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['name', 'remark', 'state_id']}
                />

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Active states"
                        columns={stateColumns}
                        rows={states}
                        rowKey={(row) => row.id}
                        emptyTitle="No states configured"
                        emptyDescription="State master is empty."
                        actions={(row) => (
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    row.id === filters.state_id
                                        ? 'default'
                                        : 'outline'
                                }
                                className={
                                    row.id === filters.state_id
                                        ? 'bg-[#2FB36F] text-white hover:bg-[#279960]'
                                        : undefined
                                }
                                onClick={() => selectState(row.id)}
                            >
                                {row.id === filters.state_id
                                    ? 'Selected'
                                    : 'Manage cities'}
                            </Button>
                        )}
                    />
                </div>

                {filters.state_id ? (
                    <>
                        <form onSubmit={applyCitySearch}>
                            <FormSection
                                title={
                                    selectedState
                                        ? `Cities · ${selectedState.name}`
                                        : 'Cities'
                                }
                                columns={2}
                            >
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="city_q">City search</Label>
                                    <Input
                                        id="city_q"
                                        value={citySearch}
                                        onChange={(event) =>
                                            setCitySearch(event.target.value)
                                        }
                                        placeholder="Filter cities in this state"
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <Button type="submit" variant="secondary">
                                        Apply filter
                                    </Button>
                                </div>
                            </FormSection>
                        </form>

                        <Form action={urls.store_city} method="post">
                            <FormSection title="Add city" columns={2}>
                                <input
                                    type="hidden"
                                    name="state_id"
                                    value={filters.state_id}
                                />
                                <div className="space-y-2">
                                    <Label htmlFor="city_name" required>
                                        City name
                                    </Label>
                                    <Input
                                        id="city_name"
                                        name="name"
                                        required
                                        maxLength={120}
                                        placeholder="City name"
                                    />
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="city_remark" required>
                                        Remark
                                    </Label>
                                    <Input
                                        id="city_remark"
                                        name="remark"
                                        required
                                        maxLength={500}
                                        placeholder="Audit remark for this addition"
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <Button
                                        type="submit"
                                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                    >
                                        Add city
                                    </Button>
                                </div>
                            </FormSection>
                        </Form>

                        <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                            <ResponsiveDataTable
                                caption="Cities in selected state"
                                columns={cityColumns}
                                rows={cities}
                                rowKey={(row) => row.id}
                                emptyTitle="No cities in this state"
                                emptyDescription="Add a city using the form above."
                                actions={(city) => (
                                    <Form
                                        action={toggleCityUrl(
                                            urls.toggle_city,
                                            city.id,
                                        )}
                                        method="post"
                                        className="flex flex-wrap items-center justify-end gap-2"
                                    >
                                        <Input
                                            name="remark"
                                            required
                                            placeholder="Toggle remark"
                                            aria-label={`Remark for ${city.name}`}
                                            className="h-8 max-w-[10rem] text-xs"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant={
                                                city.is_active
                                                    ? 'destructive'
                                                    : 'default'
                                            }
                                        >
                                            {city.is_active
                                                ? 'Deactivate'
                                                : 'Reactivate'}
                                        </Button>
                                    </Form>
                                )}
                            />
                        </div>
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Select a state to review and maintain its city master.
                    </p>
                )}
            </PageShell>
        </>
    );
}
