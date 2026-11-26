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
import { useEffect, useState } from 'react';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel?: string;
    processing?: boolean;
    errorMessage?: string | null;
    onConfirm: (remark: string) => void;
};

export function MasterAuditRemarkDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel = 'Confirm',
    processing = false,
    errorMessage,
    onConfirm,
}: Props) {
    const [remark, setRemark] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setRemark('');
            setLocalError(null);
        }
    }, [open]);

    const submit = () => {
        const trimmed = remark.trim();
        if (trimmed === '') {
            setLocalError('Audit remark is required.');
            return;
        }
        setLocalError(null);
        onConfirm(trimmed);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div className="space-y-2 py-2">
                    <Label htmlFor="master-audit-remark" required>
                        Remark
                    </Label>
                    <textarea
                        id="master-audit-remark"
                        className="min-h-[5rem] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        value={remark}
                        maxLength={1000}
                        onChange={(event) => setRemark(event.target.value)}
                        autoFocus
                    />
                    <InputError
                        message={localError ?? errorMessage ?? undefined}
                    />
                </div>

                <DialogFooter className="gap-2 sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={submit}
                    >
                        {processing ? 'Working…' : confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
