import { AppTopbar } from '@/components/app-topbar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/** @deprecated Prefer AppTopbar — kept as thin alias for existing layouts. */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return <AppTopbar breadcrumbs={breadcrumbs} />;
}
