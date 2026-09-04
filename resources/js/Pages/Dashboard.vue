<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, Head, usePage } from '@inertiajs/vue3';
import { usePermisos } from '@/composables/usePermisos';
import type { TotalesDashboard } from '@/types';

const { can } = usePermisos();

const props = defineProps<{
    totales: TotalesDashboard;
}>();

const esDueno = computed(
    () => usePage().props.auth.user?.rol === 'DUENO',
);

const esCajero = computed(
    () => usePage().props.auth.user?.rol === 'CAJERO',
);

const titulo = computed(() =>
    esDueno.value ? 'Dashboard Admin' : 'Panel',
);

// Módulos exclusivos del dueño: el cajero no los ve en la interfaz.
const modulosExclusivosDueno = ['categorias.ver', 'productos.ver', 'ventas.ver'];

const modulos = computed(() => {
    const disponibles = [
        {
            permiso: 'categorias.ver',
            ruta: route('categorias.index'),
            nombre: 'Categorías',
            descripcion: 'Administrar las categorías de productos',
            detalle: props.totales.categorias ?? null,
            detalle_etiqueta: 'categorías activas',
        },
        {
            permiso: 'productos.ver',
            ruta: route('productos.index'),
            nombre: 'Productos',
            descripcion: 'Administrar el catálogo de productos y precios',
            detalle: props.totales.productos ?? null,
            detalle_etiqueta: 'productos activos',
        },
        {
            permiso: 'pos.usar',
            ruta: route('pos.index'),
            nombre: 'Punto de venta (POS)',
            descripcion: 'Vender, pesar a granel e imprimir tickets',
            detalle: null,
            detalle_etiqueta: '',
        },
        {
            permiso: 'cajas.usar',
            ruta: route('caja.index'),
            nombre: 'Caja',
            descripcion: 'Apertura, movimientos y cierre de caja',
            detalle: null,
            detalle_etiqueta: '',
        },
        {
            permiso: 'ventas.ver',
            ruta: route('ventas.index'),
            nombre: 'Ventas',
            descripcion: 'Historial de ventas y tickets',
            detalle: null,
            detalle_etiqueta: '',
        },
        {
            permiso: 'usuarios.gestionar',
            ruta: route('usuarios.index'),
            nombre: 'Usuarios',
            descripcion: 'Administrar usuarios y permisos',
            detalle: null,
            detalle_etiqueta: '',
        },
        {
            permiso: 'auditoria.ver',
            ruta: route('auditoria.index'),
            nombre: 'Auditoría',
            descripcion: 'Registro de acciones relevantes del sistema',
            detalle: null,
            detalle_etiqueta: '',
        },
        {
            permiso: 'reportes.ver',
            ruta: route('reportes.index'),
            nombre: 'Reportes',
            descripcion: 'Resumen de ventas y ganancias',
            detalle: null,
            detalle_etiqueta: '',
        },
    ].filter(
        (modulo) =>
            can(modulo.permiso) &&
            !(esCajero.value && modulosExclusivosDueno.includes(modulo.permiso)),
    );

    return disponibles;
});
</script>

<template>
    <Head :title="titulo" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ titulo }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-lg font-medium">
                            Bienvenido al sistema de gestión.
                        </p>
                        <p class="mt-1 text-sm text-gray-600">
                            Elegí un módulo para comenzar.
                        </p>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <Link
                                v-for="modulo in modulos"
                                :key="modulo.permiso"
                                :href="modulo.ruta"
                                class="group rounded-lg border border-gray-200 p-4 transition hover:border-green-500 hover:shadow-md"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold text-gray-900">
                                        {{ modulo.nombre }}
                                    </p>
                                    <span
                                        v-if="modulo.detalle !== null"
                                        class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700"
                                    >
                                        {{ modulo.detalle }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ modulo.descripcion }}
                                </p>
                                <p class="mt-3 text-sm font-medium text-green-600 group-hover:text-green-800">
                                    Ir al módulo →
                                </p>
                            </Link>
                        </div>

                        <div
                            v-if="modulos.length === 0"
                            class="mt-6 rounded-lg border border-gray-200 p-6 text-sm text-gray-500"
                        >
                            No tenés módulos disponibles con tu rol actual.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>