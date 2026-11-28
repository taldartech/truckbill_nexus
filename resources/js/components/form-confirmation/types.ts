export type FieldReview = {
    label: string;
    value: string;
    /** Optional grouping key for two-column review layout */
    group?: string;
    /** Render as a prominent total row (financial summaries). */
    emphasis?: 'total';
};

export type FieldReviewTable = {
    headers: string[];
    rows: string[][];
};

export type FormConfirmationSize =
    | 'compact'
    | 'standard'
    | 'large'
    | 'extraWide';

export type FormConfirmationSectionTone =
    | 'business'
    | 'route'
    | 'financial'
    | 'success'
    | 'destructive';

export type FieldReviewSection = {
    title: string;
    fields?: FieldReview[];
    table?: FieldReviewTable;
    /** Full-width section in the confirmation grid. */
    layout?: 'default' | 'full';
    tone?: FormConfirmationSectionTone;
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

export type FormConfirmationReason = {
    label?: string;
    placeholder?: string;
    /** Server-side error for the reason (e.g. a rejected remark). */
    error?: string | null;
};

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
    /** Edit: read-only context rendered below the changes (e.g. payments and revised balance). */
    supplementarySections?: FieldReviewSection[];
    /** Edit audit remark entered on the form, shown with the changes. */
    remark?: string;
    /** Destructive: what will happen when the action is confirmed. */
    consequences?: string[];
    /** Destructive: capture a mandatory reason inside the dialog; it is passed to `onConfirm`. */
    reason?: FormConfirmationReason;
    processing?: boolean;
    onConfirm: (reason?: string) => void;
    confirmLabel?: string;
    /** Optional second primary path (e.g. Save & Create Lorry Hire). */
    onSecondaryConfirm?: () => void;
    secondaryConfirmLabel?: string;
    /** Explicit modal width; otherwise inferred from content. */
    size?: FormConfirmationSize;
    /** Cancel / dismiss without confirming (e.g. reset Payee). */
    onCancel?: () => void;
};
