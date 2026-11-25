import type { Auth } from '@/types/auth';

type SharedPageProps = {
    auth?: Auth;
};

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function isAuth(value: unknown): value is Auth {
    if (!isRecord(value)) {
        return false;
    }
    return value.user === null || isRecord(value.user);
}

/**
 * Internal Team master administration (edit / deactivate / GST codes).
 */
export function readCanManageGeography(props: unknown): boolean {
    if (!isRecord(props)) {
        return false;
    }
    const auth = (props as SharedPageProps).auth;
    if (!isAuth(auth)) {
        return false;
    }
    return auth.can_manage_geography === true;
}

/**
 * Authenticated users may quick-add missing State/City from operational forms.
 */
export function readCanQuickCreateGeography(props: unknown): boolean {
    if (!isRecord(props)) {
        return false;
    }
    const auth = (props as SharedPageProps).auth;
    if (!isAuth(auth)) {
        return false;
    }
    if (auth.can_quick_create_geography === true) {
        return true;
    }
    // Backward-compatible fallback while shared props roll out.
    return auth.can_manage_geography === true;
}
