export type FieldReview = {
    label: string;
    value: string;
};

export type ChangeReview = {
    label: string;
    previous: string;
    next: string;
    changeType: 'modified' | 'added' | 'removed';
};

export type FormConfirmationMode = 'create' | 'edit' | 'destructive';

export type FormConfirmationProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    mode: FormConfirmationMode;
    fields?: FieldReview[];
    changes?: ChangeReview[];
    processing?: boolean;
    onConfirm: () => void;
    confirmLabel?: string;
};
