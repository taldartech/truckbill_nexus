import type { ColumnPriority } from './types';

/**
 * Tailwind classes that hide a column below the priority's minimum breakpoint.
 * Header and cell must use the same class so visibility stays paired.
 *
 * Breakpoints (approx. content width with sidebar):
 * - P0: always
 * - P1: sm+ (≥640px)
 * - P2: md+ (≥768px)
 * - P3: xl+ (≥1280px)
 */
export function priorityVisibilityClass(priority: ColumnPriority): string {
    switch (priority) {
        case 0:
            return '';
        case 1:
            return 'hidden sm:table-cell';
        case 2:
            return 'hidden md:table-cell';
        case 3:
            return 'hidden xl:table-cell';
        default:
            return '';
    }
}

/**
 * Which priorities are considered "visible" at a given viewport width (px).
 * Used by expand-detail to avoid duplicating already-visible fields.
 */
export function visiblePrioritiesAtWidth(width: number): Set<ColumnPriority> {
    const visible = new Set<ColumnPriority>([0]);

    if (width >= 640) {
        visible.add(1);
    }

    if (width >= 768) {
        visible.add(2);
    }

    if (width >= 1280) {
        visible.add(3);
    }

    return visible;
}
