import { AlertTriangle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    ChangeReview,
    FieldReview,
    FieldReviewSection,
    FormConfirmationProps,
} from './types';

/** Above this many review values (or with any table) the dialog uses the wide two-column layout. */
const COMPACT_FIELD_LIMIT = 8;

const CHANGE_TYPE_LABEL: Record<ChangeReview['changeType'], string> = {
    modified: 'Changed',
    added: 'Added',
    removed: 'Removed',
};

function groupChanges(
    changes: ChangeReview[],
): { title: string | null; changes: ChangeReview[] }[] {
    const groups: { title: string | null; changes: ChangeReview[] }[] = [];
    for (const change of changes) {
        const title = change.group?.trim() || null;
        const current = groups[groups.length - 1];
        if (current && current.title === title) {
            current.changes.push(change);
        } else {
            groups.push({ title, changes: [change] });
        }
    }
    return groups;
}

function groupFieldsByKey(fields: FieldReview[]): FieldReviewSection[] {
    const map = new Map<string, FieldReview[]>();
    for (const field of fields) {
        const key = field.group?.trim() || 'Details';
        const bucket = map.get(key) ?? [];
        bucket.push(field);
        map.set(key, bucket);
    }
    return Array.from(map.entries()).map(([title, sectionFields]) => ({
        title,
        fields: sectionFields,
    }));
}

function displayValue(value: string): string {
    return value.trim() === '' ? '—' : value;
}

function ReviewFields({ fields }: { fields: FieldReview[] }) {
    const details = fields.filter((field) => field.emphasis !== 'total');
    const totals = fields.filter((field) => field.emphasis === 'total');

    return (
        <>
            {details.length > 0 ? (
                <dl className="grid grid-cols-[minmax(7rem,40%)_1fr] gap-x-4 gap-y-2 text-sm">
                    {details.map((field) => (
                        <div key={field.label} className="contents">
                            <dt className="text-muted-foreground">
                                {field.label}
                            </dt>
                            <dd className="break-words text-foreground">
                                {displayValue(field.value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : null}
            {totals.length > 0 ? (
                <dl className="mt-3 space-y-1 border-t border-border pt-3">
                    {totals.map((field) => (
                        <div
                            key={field.label}
                            className="flex items-baseline justify-between gap-4"
                        >
                            <dt className="text-sm text-muted-foreground">
                                {field.label}
                            </dt>
                            <dd className="text-base font-semibold text-foreground tabular-nums">
                                {displayValue(field.value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : null}
        </>
    );
}

function ReviewTable({
    headers,
    rows,
}: {
    headers: string[];
    rows: string[][];
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[32rem] border-collapse text-left text-sm">
                <thead>
                    <tr className="border-b border-border text-muted-foreground">
                        {headers.map((header) => (
                            <th
                                key={header}
                                scope="col"
                                className="px-2 py-1.5 font-normal"
                            >
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={index}
                            className="border-b border-border/60 last:border-0"
                        >
                            {row.map((cell, cellIndex) => (
                                <td
                                    key={cellIndex}
                                    className="px-2 py-1.5 align-top"
                                >
                                    {displayValue(cell)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ReviewSections({
    sections,
    wide,
}: {
    sections: FieldReviewSection[];
    wide: boolean;
}) {
    return (
        <div className={cn('grid gap-x-8 gap-y-5', wide && 'sm:grid-cols-2')}>
            {sections.map((section) => (
                <section
                    key={section.title}
                    aria-label={section.title}
                    className={cn(
                        'space-y-3',
                        section.layout === 'full' && 'sm:col-span-2',
                    )}
                >
                    <h3 className="border-b border-border pb-1.5 text-sm font-semibold text-foreground">
                        {section.title}
                    </h3>
                    {section.table ? (
                        <ReviewTable
                            headers={section.table.headers}
                            rows={section.table.rows}
                        />
                    ) : null}
                    {section.fields && section.fields.length > 0 ? (
                        <ReviewFields fields={section.fields} />
                    ) : null}
                </section>
            ))}
        </div>
    );
}

function ChangesTable({ changes }: { changes: ChangeReview[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[32rem] border-collapse text-left text-sm">
                <thead>
                    <tr className="border-b border-border text-muted-foreground">
                        <th scope="col" className="px-2 py-1.5 font-normal">
                            Field
                        </th>
                        <th scope="col" className="px-2 py-1.5 font-normal">
                            Previous
                        </th>
                        <th scope="col" className="px-2 py-1.5 font-normal">
                            New
                        </th>
                    </tr>
                </thead>
                {groupChanges(changes).map((group, index) => (
                    <tbody key={`${group.title ?? 'changes'}-${index}`}>
                        {group.title ? (
                            <tr>
                                <th
                                    colSpan={3}
                                    scope="rowgroup"
                                    className="px-2 pt-4 pb-1 text-left text-sm font-semibold text-foreground"
                                >
                                    {group.title}
                                </th>
                            </tr>
                        ) : null}
                        {group.changes.map((change) => (
                            <tr
                                key={`${group.title ?? ''}-${change.label}`}
                                className="border-b border-border/60 last:border-0"
                            >
                                <td className="px-2 py-2 align-top">
                                    <span className="text-foreground">
                                        {change.label}
                                    </span>
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {CHANGE_TYPE_LABEL[change.changeType]}
                                    </span>
                                </td>
                                <td className="px-2 py-2 align-top text-muted-foreground line-through decoration-muted-foreground/50">
                                    {displayValue(change.previous)}
                                </td>
                                <td className="px-2 py-2 align-top">
                                    <span className="rounded bg-warning/15 px-1 py-0.5 text-foreground">
                                        {displayValue(change.next)}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                ))}
            </table>
        </div>
    );
}

export function FormConfirmationDialog({
    open,
    onOpenChange,
    title,
    description,
    mode,
    fields = [],
    sections,
    changes = [],
    remark,
    consequences = [],
    reason,
    processing = false,
    onConfirm,
    confirmLabel,
    onSecondaryConfirm,
    secondaryConfirmLabel,
}: FormConfirmationProps) {
    const [reasonText, setReasonText] = useState('');
    const [reasonError, setReasonError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setReasonText('');
            setReasonError(null);
        }
    }, [open]);

    const reviewSections = useMemo(
        () =>
            sections && sections.length > 0
                ? sections
                : fields.length > 0
                  ? groupFieldsByKey(fields)
                  : [],
        [fields, sections],
    );

    const wide = useMemo(() => {
        if (changes.length > 0) {
            return true;
        }
        const valueCount = reviewSections.reduce(
            (count, section) => count + (section.fields?.length ?? 0),
            0,
        );
        return (
            valueCount > COMPACT_FIELD_LIMIT ||
            reviewSections.some((section) => section.table)
        );
    }, [changes.length, reviewSections]);

    const resolvedConfirmLabel =
        confirmLabel ??
        (mode === 'destructive'
            ? 'Confirm delete'
            : mode === 'create'
              ? 'Confirm create'
              : 'Confirm save');
    const showSecondary =
        typeof onSecondaryConfirm === 'function' &&
        typeof secondaryConfirmLabel === 'string' &&
        secondaryConfirmLabel.trim() !== '';

    const confirm = () => {
        if (!reason) {
            onConfirm();
            return;
        }
        const trimmed = reasonText.trim();
        if (trimmed === '') {
            setReasonError(`${reason.label ?? 'Reason'} is required.`);
            return;
        }
        setReasonError(null);
        onConfirm(trimmed);
    };

    const showChanges = mode === 'edit' && changes.length > 0;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'flex max-h-[88vh] w-[calc(100vw-2rem)] flex-col gap-0 overflow-hidden p-0 sm:w-full',
                    wide ? 'max-w-[960px]' : 'max-w-xl',
                )}
            >
                <DialogHeader className="shrink-0 border-b border-border px-6 py-4">
                    <DialogTitle>{title}</DialogTitle>
                    {description ? (
                        <DialogDescription>{description}</DialogDescription>
                    ) : null}
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    {mode === 'destructive' && consequences.length > 0 ? (
                        <div
                            className="flex gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm"
                            role="alert"
                        >
                            <AlertTriangle
                                className="mt-0.5 size-4 shrink-0 text-destructive"
                                aria-hidden
                            />
                            <ul className="list-disc space-y-1 pl-4 text-foreground">
                                {consequences.map((consequence) => (
                                    <li key={consequence}>{consequence}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {showChanges ? (
                        <ChangesTable changes={changes} />
                    ) : reviewSections.length > 0 ? (
                        <ReviewSections sections={reviewSections} wide={wide} />
                    ) : mode === 'edit' ? (
                        <p className="text-sm text-muted-foreground">
                            No field changes detected.
                        </p>
                    ) : null}

                    {mode === 'edit' && remark !== undefined ? (
                        <dl className="grid grid-cols-[minmax(7rem,40%)_1fr] gap-x-4 border-t border-border pt-4 text-sm">
                            <dt className="text-muted-foreground">Remark</dt>
                            <dd className="break-words text-foreground">
                                {displayValue(remark)}
                            </dd>
                        </dl>
                    ) : null}

                    {reason ? (
                        <div className="space-y-2">
                            <Label htmlFor="form-confirmation-reason" required>
                                {reason.label ?? 'Reason'}
                            </Label>
                            <textarea
                                id="form-confirmation-reason"
                                className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                value={reasonText}
                                maxLength={1000}
                                placeholder={reason.placeholder}
                                aria-invalid={
                                    Boolean(reasonError ?? reason.error) ||
                                    undefined
                                }
                                onChange={(event) =>
                                    setReasonText(event.target.value)
                                }
                                autoFocus
                            />
                            <InputError
                                message={
                                    reasonError ?? reason.error ?? undefined
                                }
                            />
                        </div>
                    ) : null}
                </div>

                <DialogFooter className="shrink-0 flex-col gap-2 border-t border-border bg-background px-6 py-4 sm:flex-row sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={() => onOpenChange(false)}
                    >
                        {mode === 'destructive' ? 'Cancel' : 'Back to edit'}
                    </Button>
                    {showSecondary ? (
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                            onClick={onSecondaryConfirm}
                        >
                            {processing ? 'Working…' : secondaryConfirmLabel}
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant={
                            mode === 'destructive' ? 'destructive' : 'primary'
                        }
                        disabled={processing}
                        onClick={confirm}
                    >
                        {processing ? 'Working…' : resolvedConfirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
