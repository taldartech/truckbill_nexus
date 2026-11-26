import InputError from '@/components/input-error';
import {
    TyreLayoutPreview,
    derivePreviewPositions,
} from '@/components/masters/tyre-layout-preview';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Minus, Plus } from 'lucide-react';

export type TyreAxleDraft = {
    sequence: number;
    configuration: 'single' | 'dual';
};

type Props = {
    layoutName: string;
    spareTyreCount: number;
    axles: TyreAxleDraft[];
    errors: Record<string, string[] | string>;
    onLayoutNameChange: (value: string) => void;
    onSpareTyreCountChange: (value: number) => void;
    onAxlesChange: (axles: TyreAxleDraft[]) => void;
};

function firstError(
    errors: Record<string, string[] | string>,
    key: string,
): string | undefined {
    const value = errors[key];
    if (Array.isArray(value)) {
        return value[0];
    }

    return typeof value === 'string' ? value : undefined;
}

export function TyreLayoutFormFields({
    layoutName,
    spareTyreCount,
    axles,
    errors,
    onLayoutNameChange,
    onSpareTyreCountChange,
    onAxlesChange,
}: Props) {
    const previewPositions = derivePreviewPositions(axles).map(
        (position, index) => ({
            id: `${position.position_key}-${index}`,
            ...position,
        }),
    );

    const addAxle = () => {
        onAxlesChange([
            ...axles,
            {
                sequence: axles.length + 1,
                configuration: 'dual',
            },
        ]);
    };

    const removeAxle = (sequence: number) => {
        onAxlesChange(
            axles
                .filter((axle) => axle.sequence !== sequence)
                .map((axle, index) => ({
                    ...axle,
                    sequence: index + 1,
                })),
        );
    };

    return (
        <div className="space-y-4">
            <div className="space-y-1">
                <Label htmlFor="tyre-layout-name" required>
                    Layout name
                </Label>
                <Input
                    id="tyre-layout-name"
                    value={layoutName}
                    onChange={(event) => onLayoutNameChange(event.target.value)}
                />
                <InputError message={firstError(errors, 'name')} />
            </div>

            <div className="space-y-1">
                <Label htmlFor="spare-tyre-count">Spare tyre count</Label>
                <Input
                    id="spare-tyre-count"
                    type="number"
                    min={0}
                    max={20}
                    value={spareTyreCount}
                    onChange={(event) =>
                        onSpareTyreCountChange(
                            Math.max(
                                0,
                                Number.parseInt(event.target.value, 10) || 0,
                            ),
                        )
                    }
                />
                <InputError message={firstError(errors, 'spare_tyre_count')} />
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <Label>Axles (front → rear)</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addAxle}
                    >
                        <Plus className="size-4" />
                        Add axle
                    </Button>
                </div>
                <InputError message={firstError(errors, 'axles')} />

                {axles.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Add at least one axle configuration.
                    </p>
                ) : (
                    <div className="space-y-2">
                        {axles.map((axle) => (
                            <div
                                key={axle.sequence}
                                className="grid gap-2 rounded-md border border-input p-3 sm:grid-cols-[auto_1fr_auto]"
                            >
                                <span className="self-center text-sm font-medium text-slate-600">
                                    Axle {axle.sequence}
                                </span>
                                <select
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    value={axle.configuration}
                                    onChange={(event) =>
                                        onAxlesChange(
                                            axles.map((item) =>
                                                item.sequence === axle.sequence
                                                    ? {
                                                          ...item,
                                                          configuration: event
                                                              .target
                                                              .value as TyreAxleDraft['configuration'],
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                >
                                    <option value="single">Single (L/R)</option>
                                    <option value="dual">
                                        Dual (inner/outer L/R)
                                    </option>
                                </select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() => removeAxle(axle.sequence)}
                                    disabled={axles.length <= 1}
                                    aria-label={`Remove axle ${axle.sequence}`}
                                >
                                    <Minus className="size-4" />
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <TyreLayoutPreview
                axles={axles}
                positions={previewPositions}
                spareTyreCount={spareTyreCount}
            />
        </div>
    );
}
