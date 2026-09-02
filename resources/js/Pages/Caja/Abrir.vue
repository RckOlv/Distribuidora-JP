<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    hay_caja_abierta: boolean;
}>();

const form = useForm({
    monto_inicial: '',
});

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
                    <div>
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
