<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { CajaFisica } from '@/types';

const props = defineProps<{
    hay_caja_abierta: boolean;
    cajas_fisicas: CajaFisica[];
}>();

const form = useForm({
    monto_inicial: '',
    caja_fisica_id: '' as number | '',
});

const errorCaja = computed<string | undefined>(() => {
    const errores = form.errors as Record<string, string | undefined>;
    return errores['caja'];
});

const hayDisponibles = props.cajas_fisicas.some(
    (caja) => caja.disponible,
);

const elegirCaja = (id: number, disponible: boolean) => {
    if (!disponible) {
        return;
    }
    form.caja_fisica_id = id;
};

const submit = () => {
    form.post(route('caja.abrir'));
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Abrir caja" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('caja.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver a caja
                    </Link>
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-gray-900">
                    Abrir caja
                </h2>

                <div
                    v-if="hay_caja_abierta"
                    class="mt-6 rounded-lg bg-amber-50 p-6 text-sm text-amber-800 shadow sm:rounded-lg"
                >
                    Ya hay una caja abierta. Cerrá la caja actual antes de abrir
                    una nueva.
                </div>

                <form
                    v-else
                    @submit.prevent="submit"
                    class="mt-6 rounded-lg bg-white p-6 shadow sm:rounded-lg"
                >
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">
                            Caja física
                        </legend>
                        <p class="mt-1 text-xs text-gray-500">
                            Elegí en qué puesto o terminal vas a operar.
                        </p>

                        <div
                            v-if="!hayDisponibles"
                            class="mt-3 rounded-md bg-amber-50 p-4 text-sm text-amber-800"
                        >
                            No hay cajas disponibles en este momento. Todas las
                            cajas están ocupadas por otra sesión abierta.
                        </div>

                        <div
                            v-else
                            class="mt-3 grid gap-3 sm:grid-cols-2"
                        >
                            <label
                                v-for="caja in cajas_fisicas"
                                :key="caja.id"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition"
                                :class="[
                                    form.caja_fisica_id === caja.id
                                        ? 'border-green-500 bg-green-50'
                                        : caja.disponible
                                          ? 'border-gray-300 bg-white hover:border-green-300'
                                          : 'cursor-not-allowed border-gray-200 bg-gray-100 opacity-60',
                                ]"
                            >
                                <input
                                    type="radio"
                                    :value="caja.id"
                                    :checked="form.caja_fisica_id === caja.id"
                                    :disabled="!caja.disponible"
                                    class="mt-0.5 h-4 w-4 border-gray-300 text-green-600 focus:ring-green-500"
                                    @change="elegirCaja(caja.id, caja.disponible)"
                                />
                                <span>
                                    <span class="block text-sm font-medium text-gray-900">
                                        {{ caja.nombre }}
                                    </span>
                                    <span
                                        class="mt-0.5 block text-xs"
                                        :class="
                                            caja.disponible
                                                ? 'text-green-600'
                                                : 'text-gray-500'
                                        "
                                    >
                                        {{
                                            caja.disponible
                                                ? 'Disponible'
                                                : 'En uso'
                                        }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.caja_fisica_id"
                        />
                        <InputError class="mt-2" :message="errorCaja" />
                    </fieldset>

                    <div class="mt-6">
                        <InputLabel
                            for="monto_inicial"
                            value="Monto inicial ($)"
                        />
                        <TextInput
                            id="monto_inicial"
                            v-model="form.monto_inicial"
                            type="number"
                            step="0.01"
                            min="0"
                            class="mt-1 block w-full"
                            placeholder="Ej.: 10000"
                            required
                            autofocus
                        />
                        <InputError
                            class="mt-2"
                            :message="form.errors.monto_inicial"
                        />
                        <p class="mt-2 text-xs text-gray-500">
                            Dinero con el que iniciás la jornada. Se tiene en
                            cuenta al calcular el efectivo esperado al cierre.
                        </p>
                    </div>

                    <div class="mt-6 flex items-center gap-4">
                        <PrimaryButton
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            Abrir caja
                        </PrimaryButton>
                        <SecondaryButton
                            type="button"
                            @click="form.reset()"
                        >
                            Limpiar
                        </SecondaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
