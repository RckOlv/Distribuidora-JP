import { usePage } from '@inertiajs/vue3';

/**
 * Permite consultar permisos efectivos del usuario autenticado
 * (para ocultar opciones del menú; la autorización real está en el backend).
 */
export function usePermisos() {
    const can = (permiso: string): boolean =>
        usePage().props.auth.permisos.includes(permiso);

    return { can };
}