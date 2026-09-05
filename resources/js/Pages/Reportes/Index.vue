<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GraficoVentas from '@/Components/Graficos/GraficoVentas.vue';
import { formatoMoneda } from '@/helpers/formato';
import {
    formatoFechaLegible,
    rangoHoy,
    rangoMesActual,
    rangoSemanaActual,
} from '@/helpers/fechas';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ResumenDiario } from '@/types';

const props = defineProps<{
    resumen: ResumenDiario;
}>();

type AccesoRapido = 'hoy' | 'semana' | 'mes';

const ACCESOS: { valor: AccesoRapido; etiqueta: string }[] = [
    { valor: 'hoy', etiqueta: 'Hoy' },
    { valor: 'semana', etiqueta: 'Esta semana' },
    { valor: 'mes', etiqueta: 'Este mes' },
];

const form = useForm({
    desde: props.resumen.desde,
    hasta: props.resumen.hasta,
});

const rangoDe = (acceso: AccesoRapido): { desde: string; hasta: string } => {
    if (acceso === 'hoy') {
        return rangoHoy();
    }
    if (acceso === 'semana') {
        return rangoSemanaActual();
    }
    return rangoMesActual();
};

const accesoActivo = computed<AccesoRapido | null>(() => {
    const rangoHoyValue = rangoHoy();
    const rangoSemanaValue = rangoSemanaActual();
    const rangoMesValue = rangoMesActual();

    if (form.desde === rangoHoyValue.desde && form.hasta === rangoHoyValue.hasta) {
        return 'hoy';
    }
    if (form.desde === rangoSemanaValue.desde && form.hasta === rangoSemanaValue.hasta) {
        return 'semana';
    }
    if (form.desde === rangoMesValue.desde && form.hasta === rangoMesValue.hasta) {
        return 'mes';
    }

    return null;
});

const errorRango = computed(() => {
    if (form.desde && form.hasta && form.desde > form.hasta) {
        return 'La fecha desde no puede ser posterior a la fecha hasta.';
    }
    return null;
});

const aplicarAcceso = (acceso: AccesoRapido) => {
    const rango = rangoDe(acceso);

    form.desde = rango.desde;
    form.hasta = rango.hasta;
    consultar();
};

const consultar = () => {
    if (errorRango.value) {
        return;
    }

    form.get(route('reportes.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

const margen = props.resumen.ganancia.margen;
const margenTexto =
    margen === null
        ? 'N/A'
        : `${margen.toLocaleString('es-AR')} %`;

const etiquetasVentasPorDia = computed(() =>
    props.resumen.ventas_por_dia.map((dia) => {
        const [, mes, diaDelMes] = dia.fecha.split('-');
        return `${diaDelMes}/${mes}`;
    }),
);

const valoresVentasPorDia = computed(() =>
    props.resumen.ventas_por_dia.map((dia) => dia.total),
);

const etiquetasMedio = computed(() =>
    props.resumen.ventas.por_medio.map((medio) => medio.etiqueta),
);

const valoresMedio = computed(() =>
    props.resumen.ventas.por_medio.map((medio) => medio.total),
);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Reportes" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div>
                    <h2 class="text-2xl font-semibold text-gray-900">
                        Reportes
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Ventas, ganancias y caja de un período. Las ganancias
                        usan el costo histórico del momento de la venta.
                    </p>
                </div>

                <form
                    @submit.prevent="consultar"
                    class="mt-6 rounded-lg bg-white p-4 shadow-sm"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-gray-600">Acceso rápido:</span>
                        <button
                            v-for="acceso in ACCESOS"
                            :key="acceso.valor"
                            type="button"
                            class="rounded-full px-3 py-1 text-sm font-medium transition"
                            :class="
                                accesoActivo === acceso.valor
                                    ? 'bg-green-600 text-white shadow-sm'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                            "
                            @click="aplicarAcceso(acceso.valor)"
                        >
                            {{ acceso.etiqueta }}
                        </button>
                    </div>

                    <div
                        class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
                    >
                        <div>
                            <label
                                for="desde"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Desde
                            </label>
                            <input
                                id="desde"
                                v-model="form.desde"
                                type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                            />
                        </div>
                        <div>
                            <label
                                for="hasta"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Hasta
                            </label>
                            <input
                                id="hasta"
                                v-model="form.hasta"
                                type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                            />
                        </div>
                        <button
                            type="submit"
                            class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                        >
                            Consultar
                        </button>
                    </div>

                    <p
                        v-if="errorRango"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ errorRango }}
                    </p>
                </form>

                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-gray-500">
                            Ventas del período
                        </p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ resumen.ventas.cantidad }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ formatoMoneda(resumen.ventas.total) }}
                        </p>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-gray-500">Costo</p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ formatoMoneda(resumen.ganancia.costo) }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            (solo ventas con costo)
                        </p>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-gray-500">
                            Ganancia
                        </p>
                        <p
                            v-if="resumen.ganancia.ganancia !== null"
                            class="mt-2 text-3xl font-bold text-green-600"
                        >
                            {{ formatoMoneda(resumen.ganancia.ganancia) }}
                        </p>
                        <p
                            v-else
                            class="mt-2 text-3xl font-bold text-gray-400"
                        >
                            No calculable
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            ventas − costo histórico
                        </p>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-gray-500">
                            Margen
                        </p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ margenTexto }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            ganancia / ventas con costo
                        </p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Ventas por día
                        </h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Total vendido por cada día del período.
                        </p>
                        <div class="mt-4">
                            <GraficoVentas
                                tipo="line"
                                :etiquetas="etiquetasVentasPorDia"
                                :valores="valoresVentasPorDia"
                            />
                        </div>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Ventas por medio de pago
                        </h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Importe total vendido agrupado por medio de pago del
                            período.
                        </p>
                        <div class="mt-4">
                            <GraficoVentas
                                tipo="bar"
                                :etiquetas="etiquetasMedio"
                                :valores="valoresMedio"
                            />
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-5 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Ventas por medio de pago
                            </h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <div
                                v-for="medio in resumen.ventas.por_medio"
                                :key="medio.medio"
                                class="flex items-center justify-between px-5 py-3"
                            >
                                <div>
                                    <p class="text-sm font-medium text-gray-900">
                                        {{ medio.etiqueta }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{ medio.cantidad }} venta(s)
                                    </p>
                                </div>
                                <p
                                    class="text-sm font-semibold text-gray-900"
                                >
                                    {{ formatoMoneda(medio.total) }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-5 py-3"
                        >
                            <p class="text-sm font-medium text-gray-700">
                                Total
                            </p>
                            <p class="text-sm font-bold text-gray-900">
                                {{ formatoMoneda(resumen.ventas.total) }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-5 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Detalle de ganancia
                            </h3>
                        </div>
                        <div class="divide-y divide-gray-100 text-sm">
                            <div
                                class="flex items-center justify-between px-5 py-3"
                            >
                                <span class="text-gray-600">
                                    Total vendido
                                </span>
                                <span class="font-semibold text-gray-900">
                                    {{ formatoMoneda(resumen.ganancia.total) }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between px-5 py-3"
                            >
                                <span class="text-gray-600">
                                    Ventas con costo
                                </span>
                                <span class="font-semibold text-gray-900">
                                    {{ formatoMoneda(resumen.ganancia.con_costo) }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between px-5 py-3"
                            >
                                <span class="text-gray-600">
                                    Costo (histórico)
                                </span>
                                <span class="font-semibold text-gray-900">
                                    {{ formatoMoneda(resumen.ganancia.costo) }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between px-5 py-3"
                            >
                                <span class="text-gray-600">
                                    Ganancia
                                </span>
                                <span
                                    v-if="resumen.ganancia.ganancia !== null"
                                    class="font-bold text-green-600"
                                >
                                    {{ formatoMoneda(resumen.ganancia.ganancia) }}
                                </span>
                                <span v-else class="text-gray-400">
                                    No calculable
                                </span>
                            </div>
                            <div
                                v-if="resumen.ganancia.sin_costo > 0"
                                class="rounded-b-lg bg-amber-50 px-5 py-3"
                            >
                                <p class="text-xs text-amber-700">
                                    Hay
                                    {{ formatoMoneda(resumen.ganancia.sin_costo) }}
                                    en ventas sin costo cargado. No se
                                    inventó ganancia para ellas; aparecen por
                                    separado.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-5 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Resumen de caja
                            </h3>
                        </div>
                        <div
                            v-if="resumen.cajas.cajas.length === 0"
                            class="px-5 py-10 text-center text-sm text-gray-500"
                        >
                            No hubo movimientos de caja en este período.
                        </div>
                        <div v-else class="divide-y divide-gray-100">
                            <div
                                v-for="caja in resumen.cajas.cajas"
                                :key="caja.id"
                                class="px-5 py-4"
                            >
                                <div
                                    class="flex items-center justify-between"
                                >
                                    <p class="text-sm font-semibold text-gray-900">
                                        Caja #{{ caja.id }}
                                        <span
                                            class="ms-2 text-xs font-normal text-gray-500"
                                        >
                                            {{ caja.usuario_abrio ?? '—' }}
                                        </span>
                                    </p>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            caja.estado === 'ABIERTA'
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-gray-100 text-gray-600'
                                        "
                                    >
                                        {{ caja.estado_etiqueta }}
                                    </span>
                                </div>
                                <div
                                    class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2"
                                >
                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase tracking-wide text-gray-400"
                                        >
                                            Ventas
                                        </p>
                                        <dl class="mt-2 space-y-1 text-sm">
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Efectivo
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.ventas_efectivo) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Tarjeta
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.ventas_tarjeta) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Transferencia
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.ventas_transferencia) }}
                                                </dd>
                                            </div>
                                            <div
                                                v-if="caja.otros > 0"
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Otros
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.otros) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="mt-2 flex items-center justify-between gap-4 border-t border-gray-200 pt-2"
                                            >
                                                <dt
                                                    class="font-medium text-gray-700"
                                                >
                                                    Total vendido
                                                </dt>
                                                <dd
                                                    class="font-bold text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.total_ventas) }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase tracking-wide text-gray-400"
                                        >
                                            Efectivo en caja
                                        </p>
                                        <dl class="mt-2 space-y-1 text-sm">
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Inicial
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.monto_inicial) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Ventas efectivo
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.ventas_efectivo) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Ingresos
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.ingresos) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="flex items-center justify-between gap-4"
                                            >
                                                <dt class="text-gray-500">
                                                    Egresos
                                                </dt>
                                                <dd
                                                    class="font-medium text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.egresos) }}
                                                </dd>
                                            </div>
                                            <div
                                                class="mt-2 flex items-center justify-between gap-4 border-t border-gray-200 pt-2"
                                            >
                                                <dt
                                                    class="font-medium text-gray-700"
                                                >
                                                    Efectivo esperado
                                                </dt>
                                                <dd
                                                    class="font-bold text-gray-900"
                                                >
                                                    {{ formatoMoneda(caja.efectivo_esperado) }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="resumen.cajas.cajas.length > 0"
                            class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-5 py-3 text-sm"
                        >
                            <span class="font-medium text-gray-700">
                                Efectivo esperado del período ({{
                                    resumen.cajas.agregado.cantidad_sesiones
                                }}
                                {{
                                    resumen.cajas.agregado.cantidad_sesiones === 1
                                        ? 'sesión'
                                        : 'sesiones'
                                }})
                            </span>
                            <span class="font-bold text-gray-900">
                                {{ formatoMoneda(resumen.cajas.agregado.efectivo_esperado) }}
                            </span>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-5 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Productos más vendidos
                            </h3>
                        </div>
                        <div
                            v-if="resumen.mas_vendidos.length === 0"
                            class="px-5 py-10 text-center text-sm text-gray-500"
                        >
                            No hubo ventas en este período.
                        </div>
                        <ul v-else class="divide-y divide-gray-100">
                            <li
                                v-for="producto in resumen.mas_vendidos"
                                :key="producto.producto_id"
                                class="flex items-center justify-between px-5 py-3"
                            >
                                <div>
                                    <p class="text-sm font-medium text-gray-900">
                                        {{ producto.nombre }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{ producto.cantidad }} unidades vendidas
                                    </p>
                                </div>
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ formatoMoneda(producto.total) }}
                                </p>
                            </li>
                        </ul>
                    </div>
                </div>

                <p class="mt-6 text-xs text-gray-400">
                    Informe del período del
                    {{ formatoFechaLegible(resumen.desde) }} al
                    {{ formatoFechaLegible(resumen.hasta) }}. La ganancia de
                    cada detalle queda congelada con el costo del momento en que
                    se vendió; cambiar un costo o precio actual no altera estos
                    históricos.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>