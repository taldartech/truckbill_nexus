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
 * Whether the signed-in user may quick-create platform geography masters.
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
