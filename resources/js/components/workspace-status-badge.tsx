type WorkspaceStatusBadgeProps = {
    status: string;
};

/**
 * Status must not rely on colour alone (Design.md §13).
 */
export function WorkspaceStatusBadge({ status }: WorkspaceStatusBadgeProps) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/50 px-2 py-0.5 text-xs font-medium text-foreground">
            <span
                aria-hidden
                className="size-1.5 rounded-full bg-foreground/70"
            />
            <span>{status}</span>
        </span>
    );
}
