import { useEffect, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { AppFormGrid, AppFormSection } from '@/components/app-form-page';
import { FormSection } from '@/components/form-section';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup } from '@/components/ui/radio-group';
import type { TransporterAttrs } from '@/components/masters/party-master/party-master-types';

const PAN_STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
] as const;

const DECLARATION_RECEIVED_OPTIONS = [
    { value: '1', label: 'Yes' },
    { value: '0', label: 'No' },
] as const;

type TdsPreviewRequest = {
    pan: string;
    pan_status: string;
    declaration: boolean;
    goods_vehicles_count: string;
};

type TdsPreviewPayload = {
    applicable_rate?: string | number;
    calculated_rate?: string | number;
    applicable_tds_percentage?: string | number;
    rate_percent?: string | number;
    explanation?: string;
    message?: string;
};

type TdsPreviewResponse = TdsPreviewPayload & {
    data?: TdsPreviewPayload;
};

function unwrapTdsPreview(response: TdsPreviewResponse): TdsPreviewPayload {
    return response.data ?? response;
}

function resolveApplicableTds(response: TdsPreviewResponse): string {
    const payload = unwrapTdsPreview(response);
    const value =
        payload.applicable_rate ??
        payload.applicable_tds_percentage ??
        payload.rate_percent ??
        payload.calculated_rate ??
        '';

    return value === '' || value === null || value === undefined
        ? ''
        : String(value);
}

function resolveTdsExplanation(response: TdsPreviewResponse): string | null {
    const payload = unwrapTdsPreview(response);

    return payload.explanation ?? payload.message ?? null;
}

export type TransporterTdsPanelProps = {
    roleIndex: number;
    pan: string;
    setPan: (value: string) => void;
    transporter: TransporterAttrs;
    setTransporter: (
        updater: (current: TransporterAttrs) => TransporterAttrs,
    ) => void;
    errors: Record<string, string>;
    previewUrl?: string;
    idPrefix?: string;
    /** When true, render hidden `name` inputs for full-page Inertia form POST. */
    nativeForm?: boolean;
    /** Compact sheet uses FormSection styling instead of AppFormSection. */
    variant?: 'page' | 'sheet';
};

export function TransporterTdsPanel({
    roleIndex,
    pan,
    setPan,
    transporter,
    setTransporter,
    errors,
    previewUrl,
    idPrefix = '',
    nativeForm = true,
    variant = 'page',
}: TransporterTdsPanelProps) {
    const [previewPercent, setPreviewPercent] = useState<string | null>(null);
    const [previewExplanation, setPreviewExplanation] = useState<string | null>(
        null,
    );
    const [previewMessage, setPreviewMessage] = useState<string | null>(null);
    const {
        setData,
        post,
        processing: previewLoading,
        cancel,
    } = useHttp<TdsPreviewRequest, TdsPreviewResponse>({
        pan: '',
        pan_status: '',
        declaration: false,
        goods_vehicles_count: '',
    });
    const previewReady =
        transporter.pan_status !== '' && transporter.declaration !== '';

    useEffect(() => {
        if (!previewUrl || !previewReady) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            setData({
                pan,
                pan_status: transporter.pan_status,
                declaration: transporter.declaration === '1',
                goods_vehicles_count: transporter.goods_vehicles_count,
            });
            void post(previewUrl, {
                onSuccess: (response) => {
                    setPreviewPercent(resolveApplicableTds(response));
                    setPreviewExplanation(resolveTdsExplanation(response));
                    setPreviewMessage(null);
                },
                onError: (validationErrors) => {
                    setPreviewPercent(null);
                    setPreviewExplanation(null);
                    const first = Object.values(validationErrors)[0];
                    setPreviewMessage(
                        typeof first === 'string'
                            ? first
                            : 'TDS preview validation failed. Check declaration fields.',
                    );
                },
                onHttpException: (response) => {
                    setPreviewPercent(null);
                    setPreviewExplanation(null);
                    if (response.status === 404) {
                        setPreviewMessage(
                            'TDS preview is not available yet. You can still save transporter details.',
                        );
                    } else {
                        setPreviewMessage(
                            'Unable to load TDS preview. Try again after updating declaration fields.',
                        );
                    }

                    return false;
                },
                onNetworkError: () => {
                    setPreviewPercent(null);
                    setPreviewExplanation(null);
                    setPreviewMessage(
                        'Unable to reach TDS preview. Check your connection and try again.',
                    );

                    return false;
                },
            });
        }, 400);

        return () => {
            window.clearTimeout(timer);
            cancel();
        };
    }, [
        pan,
        transporter.pan_status,
        transporter.declaration,
        transporter.goods_vehicles_count,
        previewUrl,
        previewReady,
        setData,
        post,
        cancel,
    ]);

    const attrName = (key: string) =>
        nativeForm ? `roles[${roleIndex}][attributes][${key}]` : undefined;

    const body = (
        <AppFormGrid cols={2}>
            {nativeForm ? (
                <input type="hidden" name={attrName('pan')} value={pan} />
            ) : null}
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}transporter_pan`} required>
                    PAN
                </Label>
                <Input
                    id={`${idPrefix}transporter_pan`}
                    name={nativeForm ? 'pan' : undefined}
                    required
                    value={pan}
                    onChange={(event) =>
                        setPan(event.target.value.toUpperCase())
                    }
                    autoComplete="off"
                    className="uppercase"
                    maxLength={10}
                />
                <InputError message={errors.pan} />
            </div>
            <div className="space-y-2">
                <Label id={`${idPrefix}pan_status_label`} required>
                    PAN Status
                </Label>
                <RadioGroup
                    name={attrName('pan_status') ?? `${idPrefix}pan_status`}
                    labelledBy={`${idPrefix}pan_status_label`}
                    required
                    options={PAN_STATUS_OPTIONS}
                    value={transporter.pan_status}
                    invalid={Boolean(errors['roles.attributes.pan_status'])}
                    onValueChange={(value) =>
                        setTransporter((current) => ({
                            ...current,
                            pan_status: value as TransporterAttrs['pan_status'],
                        }))
                    }
                />
                <InputError message={errors['roles.attributes.pan_status']} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}goods_vehicles_count`}>
                    Goods vehicles count
                </Label>
                <Input
                    id={`${idPrefix}goods_vehicles_count`}
                    type="number"
                    min={0}
                    value={transporter.goods_vehicles_count}
                    onChange={(event) =>
                        setTransporter((current) => ({
                            ...current,
                            goods_vehicles_count: event.target.value,
                        }))
                    }
                />
                {nativeForm && transporter.goods_vehicles_count !== '' ? (
                    <input
                        type="hidden"
                        name={attrName('goods_vehicles_count')}
                        value={transporter.goods_vehicles_count}
                    />
                ) : null}
            </div>
            <div className="space-y-2">
                <Label id={`${idPrefix}declaration_label`} required>
                    Declaration Received
                </Label>
                <RadioGroup
                    name={attrName('declaration') ?? `${idPrefix}declaration`}
                    labelledBy={`${idPrefix}declaration_label`}
                    required
                    options={DECLARATION_RECEIVED_OPTIONS}
                    value={transporter.declaration}
                    invalid={Boolean(errors['roles.attributes.declaration'])}
                    onValueChange={(value) =>
                        setTransporter((current) => ({
                            ...current,
                            declaration:
                                value as TransporterAttrs['declaration'],
                        }))
                    }
                />
                <InputError message={errors['roles.attributes.declaration']} />
            </div>

            {previewUrl ? (
                <div
                    className="space-y-2 rounded-md border border-dashed border-info/40 bg-info/5 p-3 sm:col-span-2"
                    data-testid="tds-preview-panel"
                    aria-live="polite"
                >
                    <p className="text-xs font-medium tracking-wide text-info uppercase">
                        Live TDS preview
                    </p>
                    {previewReady && previewLoading ? (
                        <p className="text-sm text-muted-foreground">
                            Calculating applicable TDS…
                        </p>
                    ) : null}
                    {previewReady &&
                    !previewLoading &&
                    previewPercent !== null ? (
                        <p className="text-sm font-semibold text-foreground">
                            Applicable TDS{' '}
                            <span className="text-primary">
                                {previewPercent}%
                            </span>
                        </p>
                    ) : null}
                    {previewReady && !previewLoading && previewExplanation ? (
                        <p className="text-sm text-muted-foreground">
                            {previewExplanation}
                        </p>
                    ) : null}
                    {previewReady && !previewLoading && previewMessage ? (
                        <p className="text-sm text-muted-foreground">
                            {previewMessage}
                        </p>
                    ) : null}
                    {!previewReady ? (
                        <p className="text-sm text-muted-foreground">
                            Select PAN Status and Declaration Received to
                            preview the applicable TDS.
                        </p>
                    ) : null}
                    {previewReady &&
                    !previewLoading &&
                    previewPercent === null &&
                    !previewMessage ? (
                        <p className="text-sm text-muted-foreground">
                            Update the TDS inputs to refresh the preview.
                        </p>
                    ) : null}
                </div>
            ) : null}

            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                <input
                    type="checkbox"
                    checked={transporter.tds_override}
                    onChange={(event) =>
                        setTransporter((current) => ({
                            ...current,
                            tds_override: event.target.checked,
                        }))
                    }
                />
                Override applicable TDS
                {nativeForm ? (
                    <input
                        type="hidden"
                        name={attrName('tds_override')}
                        value={transporter.tds_override ? '1' : '0'}
                    />
                ) : null}
            </label>
            {transporter.tds_override ? (
                <>
                    <div className="space-y-2">
                        <Label htmlFor={`${idPrefix}tds_percentage`} required>
                            TDS %
                        </Label>
                        <Input
                            id={`${idPrefix}tds_percentage`}
                            required
                            value={transporter.tds_percentage}
                            onChange={(event) =>
                                setTransporter((current) => ({
                                    ...current,
                                    tds_percentage: event.target.value,
                                }))
                            }
                        />
                        {nativeForm && transporter.tds_percentage !== '' ? (
                            <input
                                type="hidden"
                                name={attrName('tds_percentage')}
                                value={transporter.tds_percentage}
                            />
                        ) : null}
                        <InputError
                            message={errors['roles.attributes.tds_percentage']}
                        />
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label
                            htmlFor={`${idPrefix}tds_adjustment_remark`}
                            required
                        >
                            TDS adjustment remark
                        </Label>
                        <Input
                            id={`${idPrefix}tds_adjustment_remark`}
                            required
                            value={transporter.tds_adjustment_remark}
                            onChange={(event) =>
                                setTransporter((current) => ({
                                    ...current,
                                    tds_adjustment_remark: event.target.value,
                                }))
                            }
                        />
                        {nativeForm ? (
                            <input
                                type="hidden"
                                name={attrName('tds_adjustment_remark')}
                                value={transporter.tds_adjustment_remark}
                            />
                        ) : null}
                        <InputError
                            message={
                                errors['roles.attributes.tds_adjustment_remark']
                            }
                        />
                    </div>
                </>
            ) : nativeForm && transporter.tds_percentage !== '' ? (
                <input
                    type="hidden"
                    name={attrName('tds_percentage')}
                    value={transporter.tds_percentage}
                />
            ) : null}
        </AppFormGrid>
    );

    if (variant === 'sheet') {
        return (
            <FormSection
                title="Transporter TDS"
                description="PAN and TDS declaration details required for hire settlement."
                className="border-info/20"
                data-testid="transporter-tds-panel"
            >
                {body}
            </FormSection>
        );
    }

    return (
        <AppFormSection
            title="Transporter TDS"
            description="PAN status, declaration and vehicle count drive the live applicable TDS preview."
            className="border-info/20"
        >
            <div data-testid="transporter-tds-panel">{body}</div>
        </AppFormSection>
    );
}
