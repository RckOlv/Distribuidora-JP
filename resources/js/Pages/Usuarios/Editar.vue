<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { NombreRol, RolOpcion, UsuarioGestion } from '@/types';

const props = defineProps<{
    usuario: UsuarioGestion;
    roles: RolOpcion[];
}>();

const form = useForm({
    name: props.usuario.name,
    email: props.usuario.email,
    password: '',
    password_confirmation: '',
    rol_id: String(props.usuario.rol?.id ?? ''),
    activo: props.usuario.activo,
});

const etiquetaRol = (nombre: NombreRol) =>
    nombre === 'DUENO' ? 'Dueño' : 'Cajero';

const submit = () => {
    form.put(route('usuarios.update', props.usuario.id), {
        onSuccess: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Editar usuario" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('usuarios.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver a usuarios
                    </Link>
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-gray-900">
                    Editar usuario
                </h2>

                <form
                    @submit.prevent="submit"
                    class="mt-6 rounded-lg bg-white p-6 shadow sm:rounded-lg"
                >
                    <div>
                        <InputLabel for="name" value="Nombre" />
                        <TextInput
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                            maxlength="100"
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="email" value="Email" />
                        <TextInput
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="mt-1 block w-full"
                            required
                            maxlength="255"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="rol_id" value="Rol" />
                        <select
                            id="rol_id"
                            v-model="form.rol_id"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="" disabled>
                                Seleccioná un rol
                            </option>
                            <option
                                v-for="rol in roles"
                                :key="rol.id"
                                :value="String(rol.id)"
                            >
                                {{ etiquetaRol(rol.nombre) }}
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.rol_id" />
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel
                                for="password"
                                value="Nueva contraseña (opcional)"
                            />
                            <TextInput
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                placeholder="Dejala vacía para no cambiarla"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.password"
                            />
                        </div>

                        <div>
                            <InputLabel
                                for="password_confirmation"
                                value="Confirmar nueva contraseña"
                            />
                            <TextInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.password_confirmation"
                            />
                        </div>
                    </div>

                    <div class="mt-4">
                        <InputLabel value="Estado" />
                        <div class="mt-2 flex items-center gap-6">
                            <label
                                class="inline-flex items-center gap-2 text-sm text-gray-700"
                            >
                                <input
                                    v-model="form.activo"
                                    type="radio"
                                    :value="true"
                                    class="rounded-full border-gray-300 text-green-600 focus:ring-green-500"
                                />
                                Activo
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-sm text-gray-700"
                            >
                                <input
                                    v-model="form.activo"
                                    type="radio"
                                    :value="false"
                                    class="rounded-full border-gray-300 text-green-600 focus:ring-green-500"
                                />
                                Inactivo
                            </label>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.activo"
                        />
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
                            @click="form.reset('password', 'password_confirmation')"
                        >
                            Deshacer
                        </SecondaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>