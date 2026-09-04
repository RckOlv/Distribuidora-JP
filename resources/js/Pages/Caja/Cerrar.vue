<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatoMoneda } from '@/helpers/formato';
import type { ResumenCaja } from '@/types';

const props = defineProps<{
    caja_id: number;
    caja_fisica_nombre: string | null;
    resumen: ResumenCaja;
}>();

const form = useForm({
    efectivo_contado: '',
    observacion: '',
});

const diferencia = computed(() => {
    const contado = Number(form.efectivo_contado);
    if (form.efectivo_contado === '' || Number.isNaN(contado)) {
        return null;
    }
    return contado - props.resumen.efectivo_esperado;
});

const hayDiferencia = computed(() => {
    const valor = diferencia.value;
    return valor !== null && Math.abs(valor) > 0.004;
});

const submit = () => {
    form.post(route('caja.cerrar'));
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Cerrar caja" />

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
                    Cerrar caja
                    {{
                        props.caja_fisica_nombre
                            ? `· ${props.caja_fisica_nombre}`
                            : ''
                    }}
                </h2>

                <div class="mt-6 overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Resumen antes del cierre
                        </h3>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-gray-500">
                                    Total vendido
                                </dt>
                                <dd class="text-sm font-medium text-gray-900">
                                    {{ formatoMoneda(resumen.total_ventas) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-gray-500">
                                    Efectivo esperado
                                </dt>
                                <dd class="text-sm font-semibold text-green-700">
                                    {{ formatoMoneda(resumen.efectivo_esperado) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <form @submit.prevent="submit" class="px-6 py-5">
                        <InputLabel
                            for="efectivo_contado"
                            value="Efectivo contado ($)"
                        />
                        <TextInput
                            id="efectivo_contado"
                            v-model="form.efectivo_contado"
                            type="number"
                            step="0.01"
                            min="0"
                            class="mt-1 block w-full"
                            placeholder="Ej.: 16000"
                            required
                            autofocus
                        />
                        <InputError
                            class="mt-2"
                            :message="form.errors.efectivo_contado"
                        />

                        <div
                            v-if="diferencia !== null"
                            class="mt-3 rounded-md p-3 text-sm font-medium"
                            :class="
                                diferencia === 0
                                    ? 'bg-green-50 text-green-700'
                                    : 'bg-amber-50 text-amber-800'
                            "
                        >
                            Diferencia prevista:
                            {{
                                diferencia === 0
                                    ? 'sin diferencias'
                                    : `${diferencia > 0 ? '+' : '−'} ${formatoMoneda(
                                          Math.abs(diferencia),
                                      )}`
                            }}
                        </div>

                        <div v-if="hayDiferencia" class="mt-4">
                            <InputLabel for="observacion" value="Observación" />
                            <textarea
                                id="observacion"
                                v-model="form.observacion"
                                rows="3"
                                maxlength="5000"
                                required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                                placeholder="Motivo de la diferencia entre el efectivo contado y el esperado…"
                            ></textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Obligatoria porque el efectivo contado difiere
                                del esperado.
                            </p>
                            <InputError
                                class="mt-2"
                                :message="form.errors.observacion"
                            />
                        </div>

                        <div class="mt-6 flex items-center gap-4">
                            <PrimaryButton
                                :class="{ 'opacity-25': form.processing }"
                                :disabled="form.processing"
                            >
                                Confirmar cierre
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
        </div>
    </AuthenticatedLayout>
</template>
