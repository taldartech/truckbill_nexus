type Axle = {
    sequence: number;
    configuration: 'single' | 'dual';
    label?: string;
};

type Position = {
    id: string;
    position_key: string;
    label: string;
    axle_sequence: number;
    side: string;
    ring: string | null;
};

type Props = {
    axles: Axle[];
    positions: Position[];
    spareTyreCount: number;
    compact?: boolean;
};

export function TyreLayoutPreview({
    axles,
    positions,
    spareTyreCount,
    compact = false,
}: Props) {
    const orderedAxles = [...axles].sort((a, b) => a.sequence - b.sequence);

    return (
        <div
            className={
                compact
                    ? 'space-y-2 rounded-lg border border-[#e5e7eb] bg-[#f8fafc] p-3'
                    : 'space-y-3 rounded-lg border border-[#e5e7eb] bg-[#f8fafc] p-4'
            }
        >
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
                <span>{orderedAxles.length} axle(s)</span>
                <span>{positions.length} drive position(s)</span>
                <span>{spareTyreCount} spare</span>
            </div>

            <div className="space-y-3">
                {orderedAxles.map((axle) => {
                    const axlePositions = positions.filter(
                        (position) => position.axle_sequence === axle.sequence,
                    );

                    return (
                        <div
                            key={axle.sequence}
                            className="rounded-md border border-dashed border-slate-300 bg-white p-2"
                        >
                            <p className="mb-2 text-xs font-medium text-slate-700">
                                Axle {axle.sequence} ·{' '}
                                {axle.label ??
                                    (axle.configuration === 'dual'
                                        ? 'Dual'
                                        : 'Single')}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {axlePositions.map((position) => (
                                    <span
                                        key={
                                            position.id ?? position.position_key
                                        }
                                        className="inline-flex min-w-[4.5rem] items-center justify-center rounded-full border border-slate-300 bg-slate-50 px-2 py-1 font-mono text-[10px] text-slate-700"
                                        title={position.label}
                                    >
                                        {position.position_key}
                                    </span>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>

            {spareTyreCount > 0 ? (
                <p className="text-xs text-slate-500">
                    +{spareTyreCount} spare tyre
                    {spareTyreCount === 1 ? '' : 's'} (not shown on axles)
                </p>
            ) : null}
        </div>
    );
}

export function derivePreviewPositions(
    axles: Array<{ sequence: number; configuration: 'single' | 'dual' }>,
): Omit<Position, 'id'>[] {
    const positions: Omit<Position, 'id'>[] = [];

    for (const axle of axles) {
        const prefix = `A${axle.sequence}`;
        if (axle.configuration === 'single') {
            positions.push(
                {
                    position_key: `${prefix}-L`,
                    label: `Axle ${axle.sequence} Left`,
                    axle_sequence: axle.sequence,
                    side: 'L',
                    ring: null,
                },
                {
                    position_key: `${prefix}-R`,
                    label: `Axle ${axle.sequence} Right`,
                    axle_sequence: axle.sequence,
                    side: 'R',
                    ring: null,
                },
            );
            continue;
        }

        for (const side of ['L', 'R'] as const) {
            positions.push(
                {
                    position_key: `${prefix}-${side}I`,
                    label: `Axle ${axle.sequence} ${side === 'L' ? 'Left' : 'Right'} Inner`,
                    axle_sequence: axle.sequence,
                    side,
                    ring: 'inner',
                },
                {
                    position_key: `${prefix}-${side}O`,
                    label: `Axle ${axle.sequence} ${side === 'L' ? 'Left' : 'Right'} Outer`,
                    axle_sequence: axle.sequence,
                    side,
                    ring: 'outer',
                },
            );
        }
    }

    return positions;
}
