/** "Goods types" → "goods type"; "Expense categories" → "expense category". */
export function singularMasterLabel(pluralTitle: string): string {
    const lower = pluralTitle.trim().toLowerCase();

    if (lower.endsWith(' categories')) {
        return `${lower.slice(0, -' categories'.length)} category`;
    }

    if (lower.endsWith(' types')) {
        return `${lower.slice(0, -' types'.length)} type`;
    }

    if (lower.endsWith(' layouts')) {
        return `${lower.slice(0, -' layouts'.length)} layout`;
    }

    return lower;
}

export function addMasterActionLabel(pluralTitle: string): string {
    return `Add ${singularMasterLabel(pluralTitle)}`;
}

const catalogueTypeStoreSlug: Record<string, string> = {
    goods_type: 'goods-types',
    truck_type: 'truck-types',
    document_type: 'document-types',
    expense_category: 'expense-categories',
    package_type: 'package-types',
};

/** Typed catalogue store endpoint for JSON quick-create (no legacy /catalogues POST). */
export function catalogueTypeStoreUrl(catalogueType: string): string {
    const slug = catalogueTypeStoreSlug[catalogueType];
    return slug ? `/${slug}` : '/goods-types';
}

export function ownerCatalogueTypeUsesCode(catalogueType: string): boolean {
    return catalogueType === 'document_type';
}
