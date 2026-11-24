import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { FormConfirmationProps } from './types';

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

export function FormConfirmationDialog({
    open,
    onOpenChange,
    title,
    description,
    mode,
    fields = [],
    changes = [],
    processing = false,
    onConfirm,
    confirmLabel,
}: FormConfirmationProps) {
    const resolvedConfirmLabel =
        confirmLabel ??
        (mode === 'destructive'
            ? 'Confirm delete'
            : mode === 'create'
              ? 'Confirm create'
              : 'Confirm save');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description ? (
                        <DialogDescription>{description}</DialogDescription>
                    ) : null}
                </DialogHeader>

                {mode === 'create' ? (
                    <dl className="space-y-3">
                        {fields.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No values to review.
                            </p>
                        ) : (
                            fields.map((field) => (
                                <div key={field.label}>
                                    <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                        {field.label}
                                    </dt>
                                    <dd className="mt-1 text-sm text-foreground">
                                        {field.value || '—'}
                                    </dd>
                                </div>
                            ))
                        )}
                    </dl>
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

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={() => onOpenChange(false)}
                    >
                        {mode === 'edit' ? 'Back to edit' : 'Cancel'}
                    </Button>
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
