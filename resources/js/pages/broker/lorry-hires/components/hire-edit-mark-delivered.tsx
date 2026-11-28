import { router } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    canMarkDelivered: boolean;
    saveInProgress: boolean;
    onProceedToMemo: () => void;
    onSaveThenDeliver: () => void;
    deliverBaseline: string;
    currentPayload: string;
};

export function HireEditMarkDelivered({
    canMarkDelivered,
    saveInProgress,
    onProceedToMemo,
    onSaveThenDeliver,
    deliverBaseline,
    currentPayload,
}: Props) {
    const [unsavedOpen, setUnsavedOpen] = useState(false);
    const unsaved = useMemo(
        () => deliverBaseline !== currentPayload,
        [deliverBaseline, currentPayload],
    );

    if (!canMarkDelivered) {
        return null;
    }

    const openDelivery = () => {
        if (unsaved) {
            setUnsavedOpen(true);

            return;
        }
        onProceedToMemo();
    };

    return (
        <>
            <AppIconAction
                icon={PackageCheck}
                label="Mark as Delivered (Memo / POD)"
                variant="primary"
                size="sm"
                onClick={openDelivery}
                data-testid="lorry-hire-edit-mark-delivered"
            />

            <Dialog open={unsavedOpen} onOpenChange={setUnsavedOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Unsaved changes</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        Save or discard your Lorry Hire edits before marking
                        delivery, or cancel to keep editing.
                    </p>
                    <DialogFooter className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setUnsavedOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={saveInProgress}
                            onClick={() => {
                                setUnsavedOpen(false);
                                router.reload({
                                    onSuccess: onProceedToMemo,
                                });
                            }}
                        >
                            Discard changes and continue
                        </Button>
                        <Button
                            type="button"
                            disabled={saveInProgress}
                            onClick={() => {
                                setUnsavedOpen(false);
                                onSaveThenDeliver();
                            }}
                        >
                            Save changes and continue
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}