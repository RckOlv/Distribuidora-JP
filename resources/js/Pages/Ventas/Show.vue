<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { formatoMoneda } from '@/helpers/formato';
import { notificarError } from '@/helpers/notificaciones';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type {
    DetalleVentaHistorial,
    TrabajoImpresionDetalle,
    VentaDetalle,
} from '@/types';

const props = defineProps<{
    venta: VentaDetalle;
    detalles: DetalleVentaHistorial[];
    trabajos: TrabajoImpresionDetalle[];
}>();

const muestraPago = computed(
    () =>
        props.venta.medio_pago === 'EFECTIVO' &&
        (props.venta.efectivo_recibido !== null || props.venta.vuelto !== null),
);

const descargandoPdf = ref(false);

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

const descargarPdf = async () => {
    if (descargandoPdf.value) {
        return;
    }

    descargandoPdf.value = true;

    try {
        const respuesta = await window.axios.get(
            route('ventas.ticket-pdf', props.venta.id),
            { responseType: 'blob' },
        );

        const url = URL.createObjectURL(respuesta.data);
        const enlace = document.createElement('a');
        enlace.href = url;

        const encabezado = respuesta.headers['content-disposition'] ?? '';
        const partida = /filename="?([^";]+)"?/.exec(encabezado);
        enlace.download = partida?.[1] ?? `ticket-${props.venta.numero}.pdf`;

        document.body.appendChild(enlace);
        enlace.click();
        document.body.removeChild(enlace);
        URL.revokeObjectURL(url);
    } catch (error) {
        let mensaje = 'No se pudo descargar el ticket.';
        const datos =
            (error as { response?: { data?: Blob } }).response?.data;

        if (datos instanceof Blob) {
            const texto = await datos.text().catch(() => '');
            if (texto) {
                mensaje = texto;
            }
        }
        notificarError(mensaje, 'Error al descargar el ticket');
    } finally {
        descargandoPdf.value = false;
    }
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="`Venta #${venta.numero}`" />

        <div class="py-12">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <Link
                        :href="route('ventas.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver al historial
                    </Link>

                    <PrimaryButton
                        v-if="venta.ticket"
                        type="button"
                        :class="{ 'opacity-25': descargandoPdf }"
                        :disabled="descargandoPdf"
                        @click="descargarPdf"
                    >
                        {{
                            descargandoPdf
                                ? 'Descargando…'
                                : 'Descargar ticket PDF'
                        }}
                    </PrimaryButton>
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
                                {{
                                    venta.pagos.length > 1
                                        ? 'Múltiples medios de pago'
                                        : venta.medio_pago_etiqueta
                                }}
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
                                Impresión original
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{
                                    etiquetaEstado(
                                        venta.ticket?.estado_impresion ??
                                            null,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>

                    <div class="border-t border-gray-200 px-6 py-5">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500">
                            Pagos
                        </h3>
                        <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-for="pago in venta.pagos" :key="pago.medio_pago">
                                <dt class="text-xs uppercase tracking-wider text-gray-500">
                                    {{ pago.etiqueta }}
                                </dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">
                                    {{ formatoMoneda(pago.monto) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-gray-500">
                                    Total pagado
                                </dt>
                                <dd class="mt-1 text-lg font-bold text-gray-900">
                                    {{ formatoMoneda(venta.total) }}
                                </dd>
                            </div>
                        </dl>

                        <dl
                            v-if="muestraPago"
                            class="mt-4 grid gap-x-6 gap-y-4 border-t border-gray-100 pt-4 sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-gray-500">
                                    Efectivo recibido
                                </dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">
                                    {{
                                        formatoMoneda(
                                            venta.efectivo_recibido,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-gray-500">
                                    Vuelto
                                </dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">
                                    {{ formatoMoneda(venta.vuelto) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div
                    class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm"
                >
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
                                        {{
                                            formatoMoneda(
                                                detalle.precio_unitario,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-end text-sm font-medium text-gray-900"
                                    >
                                        {{
                                            formatoMoneda(detalle.subtotal)
                                        }}
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

                <div
                    v-if="trabajos.length > 0"
                    class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm"
                >
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Impresiones
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Tipo
                                    </th>
                                    <th
                                        class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Solicitó
                                    </th>
                                    <th
                                        class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Fecha
                                    </th>
                                    <th
                                        class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Motivo
                                    </th>
                                    <th
                                        class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                    >
                                        Estado
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="trabajo in trabajos" :key="trabajo.id">
                                    <td
                                        class="px-6 py-3 text-sm font-medium text-gray-900"
                                    >
                                        {{ trabajo.tipo_etiqueta }}
                                    </td>
                                    <td
                                        class="px-6 py-3 text-sm text-gray-500"
                                    >
                                        {{ trabajo.usuario ?? '—' }}
                                    </td>
                                    <td
                                        class="px-6 py-3 text-sm text-gray-500"
                                    >
                                        {{ fecha(trabajo.solicitado_en) }}
                                    </td>
                                    <td
                                        class="px-6 py-3 text-sm text-gray-500"
                                    >
                                        {{ trabajo.motivo ?? '—' }}
                                    </td>
                                    <td
                                        class="px-6 py-3 text-end text-sm font-medium"
                                    >
                                        {{ trabajo.estado_etiqueta }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
