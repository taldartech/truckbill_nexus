import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    FieldReview,
    FieldReviewSection,
    FormConfirmationProps,
} from './types';

function changeTypeLabel(changeType: 'modified' | 'added' | 'removed'): string {
    switch (changeType) {
        case 'added':
            return 'Added';
        case 'removed':
            return 'Removed';
        default:
            return 'Modified';
    }
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

function CreateReviewBody({
    sections,
    fields,
}: {
    sections?: FieldReviewSection[];
    fields: FieldReview[];
}) {
    const resolvedSections = useMemo(() => {
        if (sections && sections.length > 0) {
            return sections;
        }
        if (fields.length === 0) {
            return [];
        }
        return groupFieldsByKey(fields);
    }, [fields, sections]);

    if (resolvedSections.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No values to review.
            </p>
        );
    }

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {resolvedSections.map((section) => (
                <section
                    key={section.title}
                    className="rounded-lg border border-border/80 bg-muted/20 p-3"
                >
                    <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {section.title}
                    </h3>
                    <dl className="space-y-2.5">
                        {section.fields.map((field) => (
                            <div key={`${section.title}-${field.label}`}>
                                <dt className="text-xs text-muted-foreground">
                                    {field.label}
                                </dt>
                                <dd className="mt-0.5 text-sm break-words text-foreground">
                                    {field.value || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            ))}
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
    processing = false,
    onConfirm,
    confirmLabel,
    onSecondaryConfirm,
    secondaryConfirmLabel,
}: FormConfirmationProps) {
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

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[88vh] w-[calc(100vw-2rem)] max-w-[960px] flex-col gap-0 overflow-hidden p-0 sm:w-full">
                <DialogHeader className="shrink-0 border-b border-border px-6 py-4">
                    <DialogTitle>{title}</DialogTitle>
                    {description ? (
                        <DialogDescription>{description}</DialogDescription>
                    ) : null}
                </DialogHeader>

                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                    {mode === 'create' ? (
                        <CreateReviewBody sections={sections} fields={fields} />
                    ) : (
                        <div className="overflow-x-auto rounded-md border border-border">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted/40 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Field
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Previous
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            New
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Change
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {changes.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="px-3 py-4 text-muted-foreground"
                                            >
                                                No field changes detected.
                                            </td>
                                        </tr>
                                    ) : (
                                        changes.map((change) => (
                                            <tr
                                                key={change.label}
                                                className="border-t border-border"
                                            >
                                                <td className="px-3 py-2 font-medium">
                                                    {change.label}
                                                </td>
                                                <td className="px-3 py-2 text-muted-foreground">
                                                    {change.previous}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {change.next}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <span className="rounded-md border border-border px-2 py-0.5 text-xs">
                                                        {changeTypeLabel(
                                                            change.changeType,
                                                        )}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <DialogFooter className="sticky bottom-0 shrink-0 flex-col gap-2 border-t border-border bg-background px-6 py-4 sm:flex-row sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={() => onOpenChange(false)}
                    >
                        {mode === 'edit' ? 'Back to edit' : 'Cancel'}
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
                        onClick={onConfirm}
                    >
                        {processing ? 'Working…' : resolvedConfirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
