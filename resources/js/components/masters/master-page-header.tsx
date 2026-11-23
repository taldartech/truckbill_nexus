type Props = {
    title: string;
    /** Optional short supporting line under the title (filters/actions sit below). */
    description?: string | null;
};

/**
 * Compact master/list page title only.
 * Company/branch context lives in the top bar — do not repeat it here.
 */
export function MasterPageHeader({ title, description = null }: Props) {
    return (
        <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            {description ? (
                <p className="text-sm text-muted-foreground">{description}</p>
            ) : null}
        </div>
    );
}
