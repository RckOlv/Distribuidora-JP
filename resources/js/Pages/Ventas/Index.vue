<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatoMoneda } from '@/helpers/formato';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type {
    FiltrosVentas,
    Paginador,
    VentaHistorial,
    OpcionMedioPago,
    UsuarioOpcion,
} from '@/types';

const props = defineProps<{
    ventas: Paginador<VentaHistorial>;
    usuarios: UsuarioOpcion[];
    medios_pago: OpcionMedioPago[];
    filtros: FiltrosVentas;
}>();

const form = useForm({
    fecha_desde: props.filtros.fecha_desde ?? '',
    fecha_hasta: props.filtros.fecha_hasta ?? '',
    numero: props.filtros.numero,
    usuario_id: props.filtros.usuario_id ? String(props.filtros.usuario_id) : '',
    medio_pago: props.filtros.medio_pago,
});

const buscar = () => {
    form.get(route('ventas.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

const limpiar = () => {
    router.get(route('ventas.index'));
};

const fecha = (iso: string | null) => {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString('es-AR');
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Historial de ventas" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div>
                    <h2 class="text-2xl font-semibold text-gray-900">
                        Historial de ventas
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Consultá las ventas realizadas anteriormente, incluso
                        las de cajas ya cerradas.
                    </p>
                </div>

                <form
                    @submit.prevent="buscar"
                    class="mt-6 grid grid-cols-1 gap-4 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6"
                >
                    <div>
                        <label
                            for="fecha_desde"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Desde
                        </label>
                        <input
                            id="fecha_desde"
                            v-model="form.fecha_desde"
                            type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label
                            for="fecha_hasta"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Hasta
                        </label>
                        <input
                            id="fecha_hasta"
                            v-model="form.fecha_hasta"
                            type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label
                            for="numero"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Nº venta
                        </label>
                        <input
                            id="numero"
                            v-model="form.numero"
                            type="text"
                            placeholder="Ej.: 000123"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label
                            for="usuario_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Usuario
                        </label>
                        <select
                            id="usuario_id"
                            v-model="form.usuario_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="usuario in usuarios"
                                :key="usuario.id"
                                :value="String(usuario.id)"
                            >
                                {{ usuario.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            for="medio_pago"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Medio de pago
                        </label>
                        <select
                            id="medio_pago"
                            v-model="form.medio_pago"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="medio in medios_pago"
                                :key="medio.valor"
                                :value="medio.valor"
                            >
                                {{ medio.etiqueta }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            @click="limpiar"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <div class="mt-6 overflow-x-auto bg-white shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Venta
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Fecha
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Usuario
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Medio de pago
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Caja
                                </th>
                                <th
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Total
                                </th>
                                <th
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Acción
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="venta in ventas.data"
                                :key="venta.id"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        #{{ venta.numero }}
                                    </div>
                                    <span
                                        v-if="venta.estado_impresion"
                                        class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-green-100 text-green-700':
                                                venta.estado_impresion ===
                                                'IMPRESO',
                                            'bg-amber-100 text-amber-700':
                                                venta.estado_impresion ===
                                                'PENDIENTE',
                                            'bg-blue-100 text-blue-700':
                                                venta.estado_impresion ===
                                                'PROCESANDO',
                                            'bg-red-100 text-red-700':
                                                venta.estado_impresion ===
                                                'ERROR',
                                        }"
                                    >
                                        {{ venta.estado_impresion_etiqueta }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ fecha(venta.fecha) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ venta.usuario ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    <div
                                        v-if="venta.pagos.length > 1"
                                        class="flex flex-wrap gap-1"
                                    >
                                        <span
                                            v-for="pago in venta.pagos"
                                            :key="pago.medio_pago"
                                            class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"
                                        >
                                            {{ pago.etiqueta }} ·
                                            {{ formatoMoneda(pago.monto) }}
                                        </span>
                                    </div>
                                    <span v-else>
                                        {{ venta.medio_pago_etiqueta }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ venta.caja_id ? '#' + venta.caja_id : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-end text-sm font-semibold text-gray-900">
                                    {{ formatoMoneda(venta.total) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-end text-sm font-medium">
                                    <Link
                                        :href="route('ventas.show', venta.id)"
                                        class="text-green-600 hover:text-green-900"
                                    >
                                        Ver
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="ventas.data.length === 0">
                                <td
                                    colspan="7"
                                    class="px-6 py-10 text-center text-sm text-gray-500"
                                >
                                    No hay ventas que coincidan con los filtros.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6"
                    >
                        <p class="text-sm text-gray-500">
                            Mostrando
                            {{ ventas.from ?? 0 }}–{{ ventas.to ?? 0 }} de
                            {{ ventas.total }} ventas
                        </p>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="ventas.prev_page_url"
                                :href="ventas.prev_page_url"
                                class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                Anterior
                            </Link>
                            <span
                                v-else
                                class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-300"
                            >
                                Anterior
                            </span>
                            <Link
                                v-if="ventas.next_page_url"
                                :href="ventas.next_page_url"
                                class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                Siguiente
                            </Link>
                            <span
                                v-else
                                class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-300"
                            >
                                Siguiente
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
