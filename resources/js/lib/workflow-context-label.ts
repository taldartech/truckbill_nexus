/**
 * User-facing labels for LR workflow context values (avoids raw enum strings in UI).
 */
export function workflowContextLabel(context: string): string {
    switch (context) {
        case 'own_fleet':
            return 'Own Fleet';
        case 'broker':
            return 'Broker';
        case 'partload':
            return 'Partload';
        default:
            return context
                .replaceAll('_', ' ')
                .replace(/\b\w/g, (character) => character.toUpperCase());
    }
}
