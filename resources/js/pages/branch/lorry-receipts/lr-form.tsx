import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };
type NamedOption = { id: string; name: string; code?: string };

type GoodsRow = {
    goods_type_id: string;
    package_type_id: string;
    goods_description: string;
    package_count: string;
    actual_weight: string;
    charged_weight: string;
    freight_rate_type: string;
    freight_rate: string;
    goods_value: string;
};

type EwbRow = {
    ewb_number: string;
    ewb_date: string;
    valid_until: string;
};

type ChargeRow = {
    description: string;
    amount: string;
};

type LorryReceiptDetail = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    from_text: string | null;
    to_text: string | null;
    from_station_id: string | null;
    to_station_id: string | null;
    consignor_party_id: string | null;
    consignee_party_id: string | null;
    goods_value: string | null;
    goods: Array<{
        goods_type_id: string | null;
        package_type_id: string | null;
        goods_description: string | null;
        package_count: string | null;
        actual_weight: string | null;
        charged_weight: string | null;
        freight_rate_type: string;
        freight_rate: string;
        goods_value: string | null;
    }>;
    eway_bills: Array<{
        ewb_number: string;
        ewb_date: string | null;
        valid_until: string | null;
    }>;
    additional_charges: Array<{
        description: string;
        amount: string;
    }>;
};

type Props = {
    mode: 'create' | 'edit';
    workflowContext: string;
    contextLabel: string;
    lorryReceipt: LorryReceiptDetail | null;
    formOptions: {
        rateTypeOptions: Option[];
        consignors: NamedOption[];
        consignees: NamedOption[];
        stations: NamedOption[];
        goodsTypes: NamedOption[];
        packageTypes: NamedOption[];
    };
    urls: {
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

const emptyGoods = (): GoodsRow => ({
    goods_type_id: '',
    package_type_id: '',
    goods_description: '',
    package_count: '1',
    actual_weight: '',
    charged_weight: '',
    freight_rate_type: 'fixed_amount',
    freight_rate: '0',
    goods_value: '',
});

export default function LrForm({
    mode,
    workflowContext,
    contextLabel,
    lorryReceipt,
    formOptions,
    urls,
}: Props) {
    const [numberMode, setNumberMode] = useState<'auto' | 'manual'>('auto');
    const [goods, setGoods] = useState<GoodsRow[]>(() =>
        lorryReceipt?.goods?.length
            ? lorryReceipt.goods.map((row) => ({
                  goods_type_id: row.goods_type_id ?? '',
                  package_type_id: row.package_type_id ?? '',
                  goods_description: row.goods_description ?? '',
                  package_count: row.package_count ?? '',
                  actual_weight: row.actual_weight ?? '',
                  charged_weight: row.charged_weight ?? '',
                  freight_rate_type: row.freight_rate_type,
                  freight_rate: row.freight_rate ?? '0',
                  goods_value: row.goods_value ?? '',
              }))
            : [emptyGoods()],
    );
    const [ewbs, setEwbs] = useState<EwbRow[]>(() =>
        lorryReceipt?.eway_bills?.length
            ? lorryReceipt.eway_bills.map((row) => ({
                  ewb_number: row.ewb_number,
                  ewb_date: row.ewb_date ?? '',
                  valid_until: row.valid_until ?? '',
              }))
            : [],
    );
    const [charges, setCharges] = useState<ChargeRow[]>(() =>
        lorryReceipt?.additional_charges?.length
            ? lorryReceipt.additional_charges.map((row) => ({
                  description: row.description,
                  amount: row.amount,
              }))
            : [],
    );

    const action = mode === 'create' ? urls.store! : urls.update!;
    const method = mode === 'create' ? 'post' : 'patch';
    const title =
        mode === 'create'
            ? `Create ${contextLabel} Lorry Receipt`
            : `Edit LR ${lorryReceipt?.lr_number}`;

    const needsStations =
        workflowContext === 'own_fleet' || workflowContext === 'partload';
    const needsParties =
        workflowContext === 'broker' || workflowContext === 'partload';
    const needsTextLocations = workflowContext === 'broker';
    const partiesOptional = workflowContext === 'own_fleet';

    return (
        <>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <Link
                        href={urls.show ?? urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Back
                    </Link>
                    <MasterPageHeader title={title} />
                </div>

                <Form
                    action={action}
                    method={method}
                    className="mx-auto w-full max-w-6xl space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="workflow_context"
                                value={workflowContext}
                            />

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 md:p-5">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Header
                                </h2>
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label>Workflow context</Label>
                                        <Input
                                            value={contextLabel}
                                            disabled
                                            readOnly
                                        />
                                        <InputError
                                            message={errors.workflow_context}
                                        />
                                    </div>
                                    {mode === 'create' ? (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="number_mode">
                                                    Number mode
                                                </Label>
                                                <select
                                                    id="number_mode"
                                                    name="number_mode"
                                                    className={fieldClass}
                                                    value={numberMode}
                                                    onChange={(e) =>
                                                        setNumberMode(
                                                            e.target.value as
                                                                | 'auto'
                                                                | 'manual',
                                                        )
                                                    }
                                                >
                                                    <option value="auto">
                                                        Auto
                                                    </option>
                                                    <option value="manual">
                                                        Manual
                                                    </option>
                                                </select>
                                            </div>
                                            {numberMode === 'manual' ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="lr_number">
                                                        LR number
                                                    </Label>
                                                    <Input
                                                        id="lr_number"
                                                        name="lr_number"
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.lr_number
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                        </>
                                    ) : (
                                        <div className="space-y-2">
                                            <Label>LR number</Label>
                                            <Input
                                                value={
                                                    lorryReceipt?.lr_number ??
                                                    ''
                                                }
                                                disabled
                                                readOnly
                                            />
                                        </div>
                                    )}
                                    <div className="space-y-2">
                                        <Label htmlFor="lr_date">LR date</Label>
                                        <Input
                                            id="lr_date"
                                            name="lr_date"
                                            type="date"
                                            defaultValue={
                                                lorryReceipt?.lr_date ?? ''
                                            }
                                        />
                                    </div>
                                    {needsTextLocations ? (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="from_text">
                                                    From text
                                                </Label>
                                                <Input
                                                    id="from_text"
                                                    name="from_text"
                                                    defaultValue={
                                                        lorryReceipt?.from_text ??
                                                        ''
                                                    }
                                                />
                                                <InputError
                                                    message={errors.from_text}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="to_text">
                                                    To text
                                                </Label>
                                                <Input
                                                    id="to_text"
                                                    name="to_text"
                                                    defaultValue={
                                                        lorryReceipt?.to_text ??
                                                        ''
                                                    }
                                                />
                                                <InputError
                                                    message={errors.to_text}
                                                />
                                            </div>
                                        </>
                                    ) : null}
                                    {needsStations ? (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="from_station_id">
                                                    From station *
                                                </Label>
                                                <select
                                                    id="from_station_id"
                                                    name="from_station_id"
                                                    className={fieldClass}
                                                    defaultValue={
                                                        lorryReceipt?.from_station_id ??
                                                        ''
                                                    }
                                                    required
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    {formOptions.stations.map(
                                                        (station) => (
                                                            <option
                                                                key={station.id}
                                                                value={
                                                                    station.id
                                                                }
                                                            >
                                                                {station.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={
                                                        errors.from_station_id
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="to_station_id">
                                                    To station *
                                                </Label>
                                                <select
                                                    id="to_station_id"
                                                    name="to_station_id"
                                                    className={fieldClass}
                                                    defaultValue={
                                                        lorryReceipt?.to_station_id ??
                                                        ''
                                                    }
                                                    required
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    {formOptions.stations.map(
                                                        (station) => (
                                                            <option
                                                                key={station.id}
                                                                value={
                                                                    station.id
                                                                }
                                                            >
                                                                {station.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={
                                                        errors.to_station_id
                                                    }
                                                />
                                            </div>
                                        </>
                                    ) : null}
                                    {needsParties || partiesOptional ? (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="consignor_party_id">
                                                    Consignor
                                                    {needsParties ? ' *' : ''}
                                                </Label>
                                                <select
                                                    id="consignor_party_id"
                                                    name="consignor_party_id"
                                                    className={fieldClass}
                                                    defaultValue={
                                                        lorryReceipt?.consignor_party_id ??
                                                        ''
                                                    }
                                                    required={needsParties}
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    {formOptions.consignors.map(
                                                        (party) => (
                                                            <option
                                                                key={party.id}
                                                                value={party.id}
                                                            >
                                                                {party.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={
                                                        errors.consignor_party_id
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="consignee_party_id">
                                                    Consignee
                                                    {needsParties ? ' *' : ''}
                                                </Label>
                                                <select
                                                    id="consignee_party_id"
                                                    name="consignee_party_id"
                                                    className={fieldClass}
                                                    defaultValue={
                                                        lorryReceipt?.consignee_party_id ??
                                                        ''
                                                    }
                                                    required={needsParties}
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    {formOptions.consignees.map(
                                                        (party) => (
                                                            <option
                                                                key={party.id}
                                                                value={party.id}
                                                            >
                                                                {party.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={
                                                        errors.consignee_party_id
                                                    }
                                                />
                                            </div>
                                        </>
                                    ) : null}
                                    <div className="space-y-2">
                                        <Label htmlFor="goods_value">
                                            Goods value (optional override)
                                        </Label>
                                        <Input
                                            id="goods_value"
                                            name="goods_value"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue={
                                                lorryReceipt?.goods_value ?? ''
                                            }
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 md:p-5">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Goods
                                    </h2>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setGoods((current) => [
                                                ...current,
                                                emptyGoods(),
                                            ])
                                        }
                                    >
                                        Add row
                                    </Button>
                                </div>
                                <InputError message={errors.goods} />
                                <div className="space-y-4">
                                    {goods.map((row, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-3 rounded-lg border border-border/70 p-3 md:grid-cols-2 lg:grid-cols-4"
                                        >
                                            <div className="space-y-2 lg:col-span-2">
                                                <Label>Description</Label>
                                                <Input
                                                    name={`goods[${index}][goods_description]`}
                                                    value={
                                                        row.goods_description
                                                    }
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              goods_description:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Goods type</Label>
                                                <select
                                                    name={`goods[${index}][goods_type_id]`}
                                                    className={fieldClass}
                                                    value={row.goods_type_id}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              goods_type_id:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    {formOptions.goodsTypes.map(
                                                        (item) => (
                                                            <option
                                                                key={item.id}
                                                                value={item.id}
                                                            >
                                                                {item.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Package type</Label>
                                                <select
                                                    name={`goods[${index}][package_type_id]`}
                                                    className={fieldClass}
                                                    value={row.package_type_id}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              package_type_id:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    {formOptions.packageTypes.map(
                                                        (item) => (
                                                            <option
                                                                key={item.id}
                                                                value={item.id}
                                                            >
                                                                {item.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Packages</Label>
                                                <Input
                                                    name={`goods[${index}][package_count]`}
                                                    type="number"
                                                    min="0"
                                                    step="0.001"
                                                    value={row.package_count}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              package_count:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Actual weight</Label>
                                                <Input
                                                    name={`goods[${index}][actual_weight]`}
                                                    type="number"
                                                    min="0"
                                                    step="0.001"
                                                    value={row.actual_weight}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              actual_weight:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Charged weight</Label>
                                                <Input
                                                    name={`goods[${index}][charged_weight]`}
                                                    type="number"
                                                    min="0"
                                                    step="0.001"
                                                    value={row.charged_weight}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              charged_weight:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Rate type</Label>
                                                <select
                                                    name={`goods[${index}][freight_rate_type]`}
                                                    className={fieldClass}
                                                    value={
                                                        row.freight_rate_type
                                                    }
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              freight_rate_type:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                >
                                                    {formOptions.rateTypeOptions.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Rate</Label>
                                                <Input
                                                    name={`goods[${index}][freight_rate]`}
                                                    type="number"
                                                    min="0"
                                                    step="0.0001"
                                                    value={row.freight_rate}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              freight_rate:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Goods value</Label>
                                                <Input
                                                    name={`goods[${index}][goods_value]`}
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    value={row.goods_value}
                                                    onChange={(e) =>
                                                        setGoods((current) =>
                                                            current.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              goods_value:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            {goods.length > 1 ? (
                                                <div className="flex items-end">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setGoods(
                                                                (current) =>
                                                                    current.filter(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) =>
                                                                            i !==
                                                                            index,
                                                                    ),
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            ) : null}
                                        </div>
                                    ))}
                                </div>
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 md:p-5">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        E-way bills
                                    </h2>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setEwbs((current) => [
                                                ...current,
                                                {
                                                    ewb_number: '',
                                                    ewb_date: '',
                                                    valid_until: '',
                                                },
                                            ])
                                        }
                                    >
                                        Add EWB
                                    </Button>
                                </div>
                                {ewbs.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No e-way bills.
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        {ewbs.map((row, index) => (
                                            <div
                                                key={index}
                                                className="grid gap-3 md:grid-cols-4"
                                            >
                                                <div className="space-y-2">
                                                    <Label>Number</Label>
                                                    <Input
                                                        name={`eway_bills[${index}][ewb_number]`}
                                                        value={row.ewb_number}
                                                        onChange={(e) =>
                                                            setEwbs((current) =>
                                                                current.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  ewb_number:
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Date</Label>
                                                    <Input
                                                        name={`eway_bills[${index}][ewb_date]`}
                                                        type="date"
                                                        value={row.ewb_date}
                                                        onChange={(e) =>
                                                            setEwbs((current) =>
                                                                current.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  ewb_date:
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Valid until</Label>
                                                    <Input
                                                        name={`eway_bills[${index}][valid_until]`}
                                                        type="date"
                                                        value={row.valid_until}
                                                        onChange={(e) =>
                                                            setEwbs((current) =>
                                                                current.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  valid_until:
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="flex items-end">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setEwbs((current) =>
                                                                current.filter(
                                                                    (_, i) =>
                                                                        i !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 md:p-5">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Additional charges
                                    </h2>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setCharges((current) => [
                                                ...current,
                                                {
                                                    description: '',
                                                    amount: '',
                                                },
                                            ])
                                        }
                                    >
                                        Add charge
                                    </Button>
                                </div>
                                {charges.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No additional charges.
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        {charges.map((row, index) => (
                                            <div
                                                key={index}
                                                className="grid gap-3 md:grid-cols-3"
                                            >
                                                <div className="space-y-2 md:col-span-1">
                                                    <Label>Description</Label>
                                                    <Input
                                                        name={`additional_charges[${index}][description]`}
                                                        value={row.description}
                                                        onChange={(e) =>
                                                            setCharges(
                                                                (current) =>
                                                                    current.map(
                                                                        (
                                                                            item,
                                                                            i,
                                                                        ) =>
                                                                            i ===
                                                                            index
                                                                                ? {
                                                                                      ...item,
                                                                                      description:
                                                                                          e
                                                                                              .target
                                                                                              .value,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Amount</Label>
                                                    <Input
                                                        name={`additional_charges[${index}][amount]`}
                                                        type="number"
                                                        min="0.01"
                                                        step="0.01"
                                                        value={row.amount}
                                                        onChange={(e) =>
                                                            setCharges(
                                                                (current) =>
                                                                    current.map(
                                                                        (
                                                                            item,
                                                                            i,
                                                                        ) =>
                                                                            i ===
                                                                            index
                                                                                ? {
                                                                                      ...item,
                                                                                      amount: e
                                                                                          .target
                                                                                          .value,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="flex items-end">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setCharges(
                                                                (current) =>
                                                                    current.filter(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) =>
                                                                            i !==
                                                                            index,
                                                                    ),
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            <div className="flex items-center justify-end gap-3">
                                <Button variant="secondary" asChild>
                                    <Link href={urls.show ?? urls.index}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {mode === 'create'
                                        ? 'Create LR'
                                        : 'Save changes'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
