<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { UsuarioGestion } from '@/types';

defineProps<{
    usuarios: UsuarioGestion[];
}>();

const confirmandoDesactivar = ref<UsuarioGestion | null>(null);
const procesando = ref(false);

const abrirConfirmacion = (usuario: UsuarioGestion) => {
    confirmandoDesactivar.value = usuario;
};

const cerrarConfirmacion = () => {
    confirmandoDesactivar.value = null;
};

const desactivar = () => {
    if (!confirmandoDesactivar.value) {
        return;
    }

    procesando.value = true;
    router.post(
        route('usuarios.estado', confirmandoDesactivar.value.id),
        undefined,
        {
            preserveScroll: true,
            onFinish: () => {
                procesando.value = false;
                cerrarConfirmacion();
            },
        },
    );
};

const activar = (usuario: UsuarioGestion) => {
    router.post(route('usuarios.estado', usuario.id), undefined, {
        preserveScroll: true,
    });
};

const etiquetaRol = (nombre: string | null) =>
    nombre === 'DUENO' ? 'Dueño' : nombre === 'CAJERO' ? 'Cajero' : 'Sin rol';
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Usuarios" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-900">
                            Usuarios
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Administrá las cuentas y roles del sistema.
                        </p>
                    </div>

                    <Link
                        :href="route('usuarios.create')"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Nuevo usuario
                    </Link>
                </div>

                <div class="mt-6 overflow-x-auto bg-white shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Nombre
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Email
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Rol
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Estado
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Creado
                                </th>
                                <th
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="usuario in usuarios"
                                :key="usuario.id"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ usuario.name }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ usuario.email }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="inline-flex rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700"
                                    >
                                        {{ etiquetaRol(usuario.rol?.nombre ?? null) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                        :class="
                                            usuario.activo
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-gray-100 text-gray-600'
                                        "
                                    >
                                        {{
                                            usuario.activo
                                                ? 'Activo'
                                                : 'Inactivo'
                                        }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{
                                        usuario.created_at
                                            ? new Date(
                                                  usuario.created_at,
                                              ).toLocaleDateString()
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-6 py-4 text-end text-sm font-medium"
                                >
                                    <Link
                                        :href="
                                            route('usuarios.edit', usuario.id)
                                        "
                                        class="text-green-600 hover:text-green-900"
                                    >
                                        Editar
                                    </Link>
                                    <button
                                        v-if="usuario.activo"
                                        type="button"
                                        class="ms-4 text-gray-500 hover:text-gray-700"
                                        @click="abrirConfirmacion(usuario)"
                                    >
                                        Desactivar
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="ms-4 text-green-600 hover:text-green-900"
                                        @click="activar(usuario)"
                                    >
                                        Activar
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="usuarios.length === 0">
                                <td
                                    colspan="6"
                                    class="px-6 py-10 text-center text-sm text-gray-500"
                                >
                                    No hay usuarios cargados.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Modal :show="confirmandoDesactivar !== null" @close="cerrarConfirmacion">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">
                    ¿Desactivar usuario?
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    {{
                        confirmandoDesactivar
                            ? `Desactivar a "${confirmandoDesactivar.name}" (${confirmandoDesactivar.email}) le impedirá iniciar sesión. Sus ventas y datos históricos se conservan.`
                            : ''
                    }}
                </p>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="cerrarConfirmacion">
                        Cancelar
                    </SecondaryButton>
                    <button
                        type="button"
                        class="ms-3 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                        :class="{ 'opacity-25': procesando }"
                        :disabled="procesando"
                        @click="desactivar"
                    >
                        Desactivar
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>