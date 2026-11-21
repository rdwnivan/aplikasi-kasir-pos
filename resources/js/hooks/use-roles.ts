import { usePage } from '@inertiajs/react';
import type { RoleName } from '@/types';

export function useRoles() {
    const roles = usePage().props.auth.roles;
    const has = (...names: RoleName[]) =>
        names.some((name) => roles.includes(name));

    return {
        roles,
        has,
        isOwner: roles.includes('owner'),
        canManageStock: has('owner', 'gudang'),
        canSell: has('owner', 'kasir'),
    };
}
