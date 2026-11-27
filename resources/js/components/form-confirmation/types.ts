export type FieldReview = {
    label: string;
    value: string;
    /** Optional grouping key for two-column review layout */
    group?: string;
};

export type FieldReviewSection = {
    title: string;
    fields: FieldReview[];
};

export type ChangeReview = {
    label: string;
    previous: string;
    next: string;
    changeType: 'modified' | 'added' | 'removed';
    /** Optional section heading; grouped changes render under their section. */
    group?: string;
};

export type FormConfirmationMode = 'create' | 'edit' | 'destructive';

export type FormConfirmationProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    mode: FormConfirmationMode;
    fields?: FieldReview[];
    /** Optional pre-grouped sections (takes precedence over flat fields when provided). */
    sections?: FieldReviewSection[];
    changes?: ChangeReview[];
    processing?: boolean;
    onConfirm: () => void;
    confirmLabel?: string;
    /** Optional second primary path (e.g. Save & Create Lorry Hire). */
    onSecondaryConfirm?: () => void;
    secondaryConfirmLabel?: string;
};
