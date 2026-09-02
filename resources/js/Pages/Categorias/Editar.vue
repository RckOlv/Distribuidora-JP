<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { Categoria } from '@/types';

const props = defineProps<{
    categoria: Categoria;
}>();

const form = useForm({
    nombre: props.categoria.nombre,
    descripcion: props.categoria.descripcion ?? '',
});

const submit = () => {
    form.put(route('categorias.update', props.categoria.id));
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Editar categoría" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('categorias.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver a categorías
                    </Link>
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-gray-900">
                    Editar categoría
                </h2>

                <form
                    @submit.prevent="submit"
                    class="mt-6 rounded-lg bg-white p-6 shadow sm:rounded-lg"
                >
                    <div>
                        <InputLabel for="nombre" value="Nombre" />
                        <TextInput
                            id="nombre"
                            v-model="form.nombre"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                            maxlength="100"
                        />
                        <InputError class="mt-2" :message="form.errors.nombre" />
                    </div>

                    <div class="mt-4">
                        <InputLabel
                            for="descripcion"
                            value="Descripción (opcional)"
                        />
                        <TextInput
                            id="descripcion"
                            v-model="form.descripcion"
                            type="text"
                            class="mt-1 block w-full"
                            maxlength="500"
                        />
                        <InputError
                            class="mt-2"
                            :message="form.errors.descripcion"
                        />
                    </div>

                    <div class="mt-4">
                        <InputLabel value="Estado" />
                        <p class="mt-1 text-sm text-gray-600">
                            Categoría
                            <span class="font-medium text-gray-900">
                                {{ categoria.activa ? 'activa' : 'inactiva' }}
                            </span>
                            . Podés cambiarlo desde el listado.
                        </p>
                    </div>

                    <div class="mt-6 flex items-center gap-4">
                        <PrimaryButton
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            Guardar cambios
                        </PrimaryButton>
                        <SecondaryButton
                            type="button"
                            @click="form.reset()"
                        >
                            Deshacer
                        </SecondaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>