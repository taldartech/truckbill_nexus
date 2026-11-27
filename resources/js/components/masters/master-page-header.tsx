import { AppCardHeader } from '@/components/app-card-header';

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
        <AppCardHeader
            title={title}
            titleAs="h1"
            titleClassName="text-2xl font-semibold tracking-tight"
            meta={description}
        />
    );
}
