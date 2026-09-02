<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { formatoMoneda } from '@/helpers/formato';
import type { ResumenCaja } from '@/types';

defineProps<{
    caja_abierta: boolean;
    resumen: ResumenCaja | null;
}>();

const formMovimiento = useForm({
    tipo: 'INGRESO',
    monto: '',
    concepto: '',
});

const submitMovimiento = () => {
    formMovimiento.post(route('caja.movimiento'), {
        preserveScroll: true,
        onSuccess: () => formMovimiento.reset('monto', 'concepto'),
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
                        Cerrar caja
                    </Link>
                </div>

                <!-- Sin caja abierta -->
                <div
                    v-if="!caja_abierta"
                    class="mt-6 overflow-hidden bg-white shadow sm:rounded-lg"
                >
                    <div class="px-6 py-16 text-center">
                        <p class="text-lg font-medium text-gray-900">
                            No hay una caja abierta
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

                <!-- Caja abierta: resumen -->
                <div v-else-if="resumen" class="mt-6 space-y-6">
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="border-b border-gray-200 px-6 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-lg font-semibold text-gray-900">
                                    Resumen de caja
                                </h3>
                                <span
                                    class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700"
                                >
                                    Abierta
                                </span>
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

                    <!-- Movimiento manual -->
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="px-6 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Registrar movimiento
                            </h3>
                            <form
                                @submit.prevent="submitMovimiento"
                                class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                            >
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
                                <div class="lg:col-span-2">
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
                                <div class="flex items-end lg:col-span-4">
                                    <PrimaryButton
                                        :class="{ 'opacity-25': formMovimiento.processing }"
                                        :disabled="formMovimiento.processing"
                                    >
                                        Registrar movimiento
                                    </PrimaryButton>
                                    <SecondaryButton
                                        type="button"
                                        class="ms-3"
                                        @click="
                                            formMovimiento.reset(
                                                'monto',
                                                'concepto',
                                            )
                                        "
                                    >
                                        Limpiar
                                    </SecondaryButton>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
