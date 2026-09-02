<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatoMoneda } from '@/helpers/formato';
import { Head, Link } from '@inertiajs/vue3';
import type { DetalleVentaHistorial, VentaDetalle } from '@/types';

defineProps<{
    venta: VentaDetalle;
    detalles: DetalleVentaHistorial[];
}>();

const fecha = (iso: string | null) => {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString('es-AR');
};

const unidadCorta = (unidad: string | null): string => {
    switch (unidad) {
        case 'KILOGRAMO':
            return 'kg';
        case 'BOLSA':
            return 'bolsa';
        case 'UNIDAD':
            return 'unidad';
        default:
            return '';
    }
};

const etiquetaEstado = (estado: string | null): string => {
    switch (estado) {
        case 'IMPRESO':
            return 'Impreso';
        case 'PENDIENTE':
            return 'Pendiente';
        case 'PROCESANDO':
            return 'Procesando';
        case 'ERROR':
            return 'Error';
        default:
            return 'Sin ticket';
    }
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="`Venta #${venta.numero}`" />

        <div class="py-12">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('ventas.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver al historial
                    </Link>
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-gray-900">
                    Venta #{{ venta.numero }}
                </h2>

                <div
                    class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm"
                >
                    <dl class="grid gap-x-6 gap-y-4 px-6 py-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Fecha
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ fecha(venta.fecha) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Usuario
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ venta.usuario ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Caja
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ venta.caja_id ? '#' + venta.caja_id : '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Medio de pago
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ venta.medio_pago_etiqueta }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Ticket
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{
                                    venta.ticket
                                        ? '#' + venta.ticket.numero
                                        : 'Sin ticket'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Impresión
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ etiquetaEstado(venta.ticket?.estado_impresion ?? null) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Productos
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Producto
                                    </th>
                                    <th
                                        class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Unidad
                                    </th>
                                    <th
                                        class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Cantidad
                                    </th>
                                    <th
                                        class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Precio unitario
                                    </th>
                                    <th
                                        class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Subtotal
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr
                                    v-for="(detalle, index) in detalles"
                                    :key="index"
                                >
                                    <td
                                        class="px-6 py-4 text-sm font-medium text-gray-900"
                                    >
                                        {{ detalle.nombre }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm text-gray-500"
                                    >
                                        {{ unidadCorta(detalle.unidad_medida) }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-end text-sm text-gray-500"
                                    >
                                        {{ detalle.cantidad }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-end text-sm text-gray-500"
                                    >
                                        {{ formatoMoneda(detalle.precio_unitario) }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-end text-sm font-medium text-gray-900"
                                    >
                                        {{ formatoMoneda(detalle.subtotal) }}
                                    </td>
                                </tr>
                                <tr v-if="detalles.length === 0">
                                    <td
                                        colspan="5"
                                        class="px-6 py-8 text-center text-sm text-gray-500"
                                    >
                                        Sin detalle de productos.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-gray-200 px-6 py-4"
                    >
                        <span class="text-sm font-medium text-gray-700">
                            TOTAL
                        </span>
                        <span class="text-2xl font-bold text-gray-900">
                            {{ formatoMoneda(venta.total) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
