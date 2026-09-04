<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { usePermisos } from '@/composables/usePermisos';
import type { Categoria } from '@/types';

defineProps<{
    categorias: Categoria[];
}>();

const { can } = usePermisos();

const toggleEstado = (categoria: Categoria) => {
    router.post(route('categorias.estado', categoria.id), undefined, {
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Categorías" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-900">
                            Categorías
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Administrá las categorías del catálogo.
                        </p>
                    </div>

                    <Link
                        v-if="can('categorias.gestionar')"
                        :href="route('categorias.create')"
                        class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Nueva categoría
                    </Link>
                </div>

                <div class="mt-6 -mx-4 overflow-x-auto px-4 bg-white shadow sm:mx-0 sm:rounded-lg sm:px-0">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Categoría
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Estado
                                </th>
                                <th
                                    v-if="can('categorias.gestionar')"
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="categoria in categorias"
                                :key="categoria.id"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ categoria.nombre }}
                                    </div>
                                    <div
                                        v-if="categoria.descripcion"
                                        class="text-sm text-gray-500"
                                    >
                                        {{ categoria.descripcion }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ categoria.productos_count ?? 0 }}
                                        producto(s)
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                        :class="
                                            categoria.activa
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-gray-100 text-gray-600'
                                        "
                                    >
                                        {{
                                            categoria.activa
                                                ? 'Activa'
                                                : 'Inactiva'
                                        }}
                                    </span>
                                </td>
                                <td
                                    v-if="can('categorias.gestionar')"
                                    class="whitespace-nowrap px-6 py-4 text-end text-sm font-medium"
                                >
                                    <Link
                                        :href="
                                            route(
                                                'categorias.edit',
                                                categoria.id,
                                            )
                                        "
                                        class="text-green-600 hover:text-green-900"
                                    >
                                        Editar
                                    </Link>
                                    <button
                                        type="button"
                                        @click="toggleEstado(categoria)"
                                        class="ms-4 text-gray-500 hover:text-gray-700"
                                    >
                                        {{
                                            categoria.activa
                                                ? 'Desactivar'
                                                : 'Activar'
                                        }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="categorias.length === 0">
                                <td
                                    :colspan="can('categorias.gestionar') ? 3 : 2"
                                    class="px-6 py-10 text-center text-sm text-gray-500"
                                >
                                    No hay categorías cargadas.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>