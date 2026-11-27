import { usePage } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type SummaryItem = {
    key: string;
    label: string;
    message: string;
    field: HTMLElement | null;
};

const MARKER = 'data-error-summary-invalid';

const RENDER_SETTLE_MS = 80;

/**
 * Application-wide rejected-submission feedback for Inertia forms.
 *
 * After a failed visit it associates every error with its input (aria-invalid,
 * aria-describedby), lists messages the page does not render itself in a
 * shared summary, expands collapsed sections and focuses the first problem.
 */
export function FormErrorSummary() {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const [items, setItems] = useState<SummaryItem[]>([]);
    const summaryRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        clearMarkers();
        const entries = (
            Object.entries(errors ?? {}) as [string, unknown][]
        ).filter(
            (entry): entry is [string, string] =>
                typeof entry[1] === 'string' && entry[1].trim() !== '',
        );

        if (entries.length === 0) {
            setItems([]);

            return;
        }

        // Pages that mirror errors into local form state (useForm) render them a tick later.
        const timer = window.setTimeout(() => {
            const scope = activeScope();
            const unrendered: SummaryItem[] = [];
            let firstField: HTMLElement | null = null;

            for (const [key, message] of entries) {
                const field = findField(scope, key);
                const rendered = findRenderedMessage(
                    scope,
                    message,
                    summaryRef.current,
                );

                if (field) {
                    markInvalid(field, rendered, key);
                    if (
                        firstField === null ||
                        firstField.compareDocumentPosition(field) &
                            Node.DOCUMENT_POSITION_PRECEDING
                    ) {
                        firstField = field;
                    }
                }

                if (!rendered) {
                    unrendered.push({
                        key,
                        message,
                        field,
                        label: field ? fieldLabel(field, key) : '',
                    });
                }
            }

            setItems(unrendered);

            if (firstField) {
                focusField(firstField);
            } else if (unrendered.length > 0) {
                window.requestAnimationFrame(() => {
                    summaryRef.current?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                    summaryRef.current?.focus({ preventScroll: true });
                });
            }
        }, RENDER_SETTLE_MS);

        return () => window.clearTimeout(timer);
    }, [errors]);

    if (items.length === 0) {
        return null;
    }

    return (
        <div
            ref={summaryRef}
            tabIndex={-1}
            role="alert"
            aria-live="assertive"
            data-testid="form-error-summary"
            className="mx-4 mt-4 rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-sm outline-none focus-visible:ring-2 focus-visible:ring-destructive/40 md:mx-6"
        >
            <p className="flex items-center gap-2 font-semibold text-destructive">
                <AlertCircle className="size-4 shrink-0" aria-hidden />
                The submission could not be completed
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-foreground">
                {items.map((item) => (
                    <li key={item.key}>
                        {item.field ? (
                            <button
                                type="button"
                                className="text-left underline-offset-2 hover:underline"
                                onClick={() =>
                                    item.field && focusField(item.field)
                                }
                            >
                                {item.label ? (
                                    <span className="font-medium">
                                        {item.label}:{' '}
                                    </span>
                                ) : null}
                                {item.message}
                            </button>
                        ) : (
                            item.message
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function activeScope(): ParentNode {
    const dialogs = document.querySelectorAll<HTMLElement>(
        '[role="dialog"][data-state="open"]',
    );

    return dialogs.length > 0 ? dialogs[dialogs.length - 1] : document;
}

function candidateNames(key: string): string[] {
    const parts = key.split('.');
    const bracketed =
        parts.length > 1
            ? parts[0] +
              parts
                  .slice(1)
                  .map((part) => `[${part}]`)
                  .join('')
            : key;

    return Array.from(new Set([key, bracketed, `${bracketed}[]`]));
}

function findField(scope: ParentNode, key: string): HTMLElement | null {
    const ids = [
        `${key}-trigger`,
        key,
        key.replaceAll('.', '-'),
        key.replaceAll('.', '_'),
    ];
    for (const id of ids) {
        const byId = scope.querySelector<HTMLElement>(`#${CSS.escape(id)}`);
        if (byId && isFocusable(byId)) {
            return byId;
        }
    }

    const byDataField = scope.querySelector<HTMLElement>(
        `[data-field="${CSS.escape(key)}"]`,
    );
    if (byDataField) {
        return byDataField;
    }

    for (const name of candidateNames(key)) {
        const byName = scope.querySelector<HTMLElement>(
            `[name="${CSS.escape(name)}"]`,
        );
        if (byName && isFocusable(byName)) {
            return byName;
        }
    }

    return null;
}

function isFocusable(element: HTMLElement): boolean {
    return !(element instanceof HTMLInputElement && element.type === 'hidden');
}

function findRenderedMessage(
    scope: ParentNode,
    message: string,
    exclude: HTMLElement | null,
): HTMLElement | null {
    const expected = message.trim();
    const nodes = scope.querySelectorAll<HTMLElement>(
        'p, span, li, small, div[role="alert"]',
    );
    for (const node of nodes) {
        if (exclude?.contains(node) || node.closest('[data-sonner-toaster]')) {
            continue;
        }
        if (
            node.textContent?.trim() === expected &&
            node.offsetParent !== null
        ) {
            return node;
        }
    }

    return null;
}

function markInvalid(
    field: HTMLElement,
    message: HTMLElement | null,
    key: string,
): void {
    if (!field.hasAttribute('aria-invalid')) {
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute(MARKER, 'true');
    }

    if (message && !field.hasAttribute('aria-describedby')) {
        if (!message.id) {
            message.id = `${field.id || key.replaceAll('.', '-')}-error`;
        }
        field.setAttribute('aria-describedby', message.id);
        field.setAttribute(`${MARKER}-described`, 'true');
    }
}

function clearMarkers(): void {
    document.querySelectorAll(`[${MARKER}]`).forEach((element) => {
        element.removeAttribute('aria-invalid');
        element.removeAttribute(MARKER);
    });
    document.querySelectorAll(`[${MARKER}-described]`).forEach((element) => {
        element.removeAttribute('aria-describedby');
        element.removeAttribute(`${MARKER}-described`);
    });
}

function fieldLabel(field: HTMLElement, key: string): string {
    const labelledBy = field.getAttribute('aria-labelledby');
    const label =
        (labelledBy ? document.getElementById(labelledBy) : null) ??
        (field.id
            ? document.querySelector(`label[for="${CSS.escape(field.id)}"]`)
            : null);
    const text =
        label?.textContent?.replace('*', '').trim() ||
        field.getAttribute('aria-label') ||
        '';

    return text !== '' ? text : humanizeKey(key);
}

export function humanizeKey(key: string): string {
    return key
        .split('.')
        .map((part) =>
            /^\d+$/.test(part)
                ? `row ${Number(part) + 1}`
                : part.replaceAll('_', ' '),
        )
        .join(' · ')
        .replace(/^./, (first) => first.toUpperCase());
}

function revealAncestors(field: HTMLElement): void {
    let node: HTMLElement | null = field.parentElement;
    while (node) {
        if (node instanceof HTMLDetailsElement && !node.open) {
            node.open = true;
        }
        if (
            node.getAttribute('role') === 'tabpanel' &&
            node.getAttribute('data-state') === 'inactive' &&
            node.id
        ) {
            document
                .querySelector<HTMLElement>(
                    `[aria-controls="${CSS.escape(node.id)}"]`,
                )
                ?.click();
        }
        node = node.parentElement;
    }
}

function focusField(field: HTMLElement): void {
    revealAncestors(field);
    window.requestAnimationFrame(() => {
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        field.focus({ preventScroll: true });
    });
}
