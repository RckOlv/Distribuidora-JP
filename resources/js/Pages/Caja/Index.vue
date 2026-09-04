<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatoMoneda } from '@/helpers/formato';
import type { ResumenCaja } from '@/types';

const props = defineProps<{
    caja_abierta: boolean;
    resumen: ResumenCaja | null;
}>();

const resumen = computed(() => props.resumen);

const neto = computed(
    () => (props.resumen?.ingresos ?? 0) - (props.resumen?.egresos ?? 0),
);

const tabActiva = ref<'resumen' | 'movimientos'>('resumen');

const modalMovimiento = ref(false);

const formMovimiento = useForm({
    tipo: 'INGRESO',
    monto: '',
    medio_pago: 'EFECTIVO',
    concepto: '',
});

const submitMovimiento = () => {
    formMovimiento.post(route('caja.movimiento'), {
        preserveScroll: true,
        onSuccess: () => {
            modalMovimiento.value = false;
            formMovimiento.reset('monto', 'medio_pago', 'concepto');
        },
    });
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
        <Head title="Caja" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-900">Caja</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Apertura, movimientos y cierre de caja.
                        </p>
                    </div>

                    <Link
                        v-if="!caja_abierta"
                        :href="route('caja.abrir.form')"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Abrir caja
                    </Link>
                    <Link
                        v-else
                        :href="route('caja.cerrar.form')"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                    >
                        Cerrar caja{{
                            resumen?.caja_fisica_nombre
                                ? ` ${resumen.caja_fisica_nombre}`
                                : ''
                        }}
                    </Link>
                </div>

                <!-- Sin caja abierta -->
                <div
                    v-if="!caja_abierta"
                    class="mt-6 overflow-hidden bg-white shadow sm:rounded-lg"
                >
                    <div class="px-6 py-16 text-center">
                        <p class="text-lg font-medium text-gray-900">
                            No tenés una caja abierta
                        </p>
                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">
                            Para comenzar a vender desde el POS es necesario
                            abrir una caja. Al abrirla vas a registrar el monto
                            de dinero con el que iniciás la jornada.
                        </p>
                        <div class="mt-6">
                            <Link
                                :href="route('caja.abrir.form')"
                                class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                            >
                                Abrir caja
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Caja abierta -->
                <div v-else-if="resumen" class="mt-6">
                    <!-- Pestañas -->
                    <div class="border-b border-gray-200">
                        <nav
                            class="-mb-px flex gap-6"
                            aria-label="Secciones de caja"
                        >
                            <button
                                type="button"
                                @click="tabActiva = 'resumen'"
                                class="border-b-2 px-1 pb-3 text-sm font-medium transition"
                                :class="
                                    tabActiva === 'resumen'
                                        ? 'border-green-600 text-green-700'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                "
                            >
                                Resumen
                            </button>
                            <button
                                type="button"
                                @click="tabActiva = 'movimientos'"
                                class="border-b-2 px-1 pb-3 text-sm font-medium transition"
                                :class="
                                    tabActiva === 'movimientos'
                                        ? 'border-green-600 text-green-700'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                "
                            >
                                Movimientos del día
                            </button>
                        </nav>
                    </div>

                    <div class="mt-6 space-y-6">
                        <!-- Pestaña Resumen -->
                        <div
                            v-if="tabActiva === 'resumen'"
                            class="overflow-hidden bg-white shadow sm:rounded-lg"
                        >
                            <div class="border-b border-gray-200 px-6 py-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-lg font-semibold text-gray-900">
                                        Resumen de caja
                                    </h3>
                                    <div class="flex items-center gap-2">
                                        <span
                                            v-if="resumen.caja_fisica_nombre"
                                            class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700"
                                        >
                                            Caja actual:
                                            {{ resumen.caja_fisica_nombre }}
                                        </span>
                                        <span
                                            class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700"
                                        >
                                            Abierta
                                        </span>
                                    </div>
                                </div>
                                <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Abierta por
                                        </dt>
                                        <dd class="text-sm font-medium text-gray-900">
                                            {{ resumen.usuario_abrio ?? '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Apertura
                                        </dt>
                                        <dd class="text-sm text-gray-900">
                                            {{ fecha(resumen.abierta_en) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Monto inicial
                                        </dt>
                                        <dd class="text-sm font-medium text-gray-900">
                                            {{ formatoMoneda(resumen.monto_inicial) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Ventas
                                        </dt>
                                        <dd class="text-sm font-medium text-gray-900">
                                            {{ resumen.cantidad_ventas }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="grid gap-px bg-gray-200 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Total vendido
                                    </dt>
                                    <dd class="mt-1 text-xl font-semibold text-gray-900">
                                        {{ formatoMoneda(resumen.total_ventas) }}
                                    </dd>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Ventas en efectivo
                                    </dt>
                                    <dd class="mt-1 text-xl font-semibold text-gray-900">
                                        {{ formatoMoneda(resumen.ventas_efectivo) }}
                                    </dd>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Ventas transferencia
                                    </dt>
                                    <dd class="mt-1 text-xl font-semibold text-gray-900">
                                        {{ formatoMoneda(resumen.ventas_transferencia) }}
                                    </dd>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Ventas tarjeta
                                    </dt>
                                    <dd class="mt-1 text-xl font-semibold text-gray-900">
                                        {{ formatoMoneda(resumen.ventas_tarjeta) }}
                                    </dd>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Ingresos manuales
                                    </dt>
                                    <dd class="mt-1 text-lg font-medium text-green-700">
                                        + {{ formatoMoneda(resumen.ingresos) }}
                                    </dd>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Egresos manuales
                                    </dt>
                                    <dd class="mt-1 text-lg font-medium text-red-700">
                                        − {{ formatoMoneda(resumen.egresos) }}
                                    </dd>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-gray-500">
                                        Otros medios
                                    </dt>
                                    <dd class="mt-1 text-lg font-medium text-gray-900">
                                        {{ formatoMoneda(resumen.otros) }}
                                    </dd>
                                </div>
                                <div class="bg-green-50 px-6 py-4">
                                    <dt class="text-xs uppercase tracking-wider text-green-800">
                                        Efectivo esperado
                                    </dt>
                                    <dd class="mt-1 text-2xl font-bold text-green-800">
                                        {{ formatoMoneda(resumen.efectivo_esperado) }}
                                    </dd>
                                </div>
                            </div>
                        </div>

                        <!-- Pestaña Movimientos del día -->
                        <div
                            v-else
                            class="overflow-hidden bg-white shadow sm:rounded-lg"
                        >
                            <div class="border-b border-gray-200 px-6 py-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900">
                                            Movimientos del día
                                        </h3>
                                        <p class="mt-1 text-sm text-gray-600">
                                            Mostrando los ingresos y egresos
                                            manuales de la caja actual.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                                        @click="modalMovimiento = true"
                                    >
                                        + Registrar movimiento
                                    </button>
                                </div>
                            </div>

                            <div v-if="resumen.movimientos.length > 0">
                                <!-- Resumen de movimientos -->
                                <div class="grid gap-px bg-gray-200 sm:grid-cols-3">
                                    <div class="bg-white px-6 py-4">
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Ingresos
                                        </dt>
                                        <dd class="mt-1 text-lg font-semibold text-green-700">
                                            + {{ formatoMoneda(resumen.ingresos) }}
                                        </dd>
                                    </div>
                                    <div class="bg-white px-6 py-4">
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Egresos
                                        </dt>
                                        <dd class="mt-1 text-lg font-semibold text-red-700">
                                            − {{ formatoMoneda(resumen.egresos) }}
                                        </dd>
                                    </div>
                                    <div class="bg-white px-6 py-4">
                                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                                            Neto
                                        </dt>
                                        <dd
                                            class="mt-1 text-lg font-semibold"
                                            :class="
                                                neto >= 0
                                                    ? 'text-green-700'
                                                    : 'text-red-700'
                                            "
                                        >
                                            {{ neto >= 0 ? '+' : '−' }}
                                            {{ formatoMoneda(Math.abs(neto)) }}
                                        </dd>
                                    </div>
                                </div>

                                <!-- Lista de movimientos -->
                                <ul class="divide-y divide-gray-100">
                                    <li
                                        v-for="mov in resumen.movimientos"
                                        :key="mov.id"
                                        class="flex items-start justify-between gap-4 px-6 py-4"
                                    >
                                        <div class="min-w-0 flex-1 space-y-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide"
                                                    :class="
                                                        mov.tipo === 'INGRESO'
                                                            ? 'bg-green-100 text-green-700'
                                                            : 'bg-red-100 text-red-700'
                                                    "
                                                >
                                                    {{ mov.tipo_etiqueta }}
                                                </span>
                                                <span
                                                    v-if="mov.medio_pago_etiqueta"
                                                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700"
                                                >
                                                    {{ mov.medio_pago_etiqueta }}
                                                </span>
                                                <span
                                                    v-else
                                                    class="inline-flex items-center rounded-full border border-dashed border-gray-300 px-2.5 py-0.5 text-xs font-medium text-gray-400"
                                                    title="Movimiento registrado antes de incorporar el medio de pago"
                                                >
                                                    Sin especificar
                                                </span>
                                            </div>
                                            <p class="break-words text-sm font-medium text-gray-900">
                                                {{ mov.concepto }}
                                            </p>
                                            <p class="text-xs text-gray-400">
                                                {{ fecha(mov.fecha) }}
                                            </p>
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <p
                                                class="text-base font-semibold tabular-nums"
                                                :class="
                                                    mov.tipo === 'INGRESO'
                                                        ? 'text-green-700'
                                                        : 'text-red-700'
                                                "
                                            >
                                                {{ mov.tipo === 'INGRESO' ? '+' : '−' }}
                                                {{ formatoMoneda(mov.monto) }}
                                            </p>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <div
                                v-else
                                class="px-6 py-8 text-center text-sm text-gray-500"
                            >
                                Todavía no se registraron movimientos manuales.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: registrar movimiento -->
        <Modal
            :show="modalMovimiento"
            max-width="md"
            @close="modalMovimiento = false"
        >
            <form @submit.prevent="submitMovimiento">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Registrar movimiento
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Registrá un ingreso o egreso manual en la caja actual.
                    </p>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Tipo" />
                            <select
                                v-model="formMovimiento.tipo"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                            >
                                <option value="INGRESO">Ingreso</option>
                                <option value="EGRESO">Egreso</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Medio de pago" />
                            <select
                                v-model="formMovimiento.medio_pago"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                            >
                                <option value="EFECTIVO">Efectivo</option>
                                <option value="TRANSFERENCIA">
                                    Transferencia
                                </option>
                                <option value="TARJETA">Tarjeta</option>
                            </select>
                            <InputError
                                class="mt-2"
                                :message="formMovimiento.errors.medio_pago"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Monto" />
                            <TextInput
                                v-model="formMovimiento.monto"
                                type="number"
                                step="0.01"
                                min="0"
                                class="mt-1 block w-full"
                                placeholder="0,00"
                                required
                            />
                            <InputError
                                class="mt-2"
                                :message="formMovimiento.errors.monto"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Concepto" />
                            <TextInput
                                v-model="formMovimiento.concepto"
                                type="text"
                                class="mt-1 block w-full"
                                placeholder="Ej.: cambio, gasto menor, retiro…"
                                required
                                maxlength="200"
                            />
                            <InputError
                                class="mt-2"
                                :message="formMovimiento.errors.concepto"
                            />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <SecondaryButton
                            type="button"
                            :disabled="formMovimiento.processing"
                            @click="modalMovimiento = false"
                        >
                            Cancelar
                        </SecondaryButton>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-25"
                            :disabled="formMovimiento.processing"
                        >
                            {{
                                formMovimiento.processing
                                    ? 'Registrando…'
                                    : 'Registrar movimiento'
                            }}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>