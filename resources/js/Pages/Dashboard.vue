<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, Head } from '@inertiajs/vue3';
import { usePermisos } from '@/composables/usePermisos';
import type { TotalesDashboard } from '@/types';

const { can } = usePermisos();

defineProps<{
    totales: TotalesDashboard;
}>();
</script>

<template>
    <Head title="Panel" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Panel
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-lg font-medium">
                            Bienvenido al sistema de gestión.
                        </p>

                        <div class="mt-6 grid gap-4 sm:grid-cols-3">
                            <div
                                v-if="can('productos.ver')"
                                class="rounded-lg border border-gray-200 p-4"
                            >
                                <p class="text-sm text-gray-500">Productos</p>
                                <p class="text-2xl font-semibold">
                                    {{ totales.productos }}
                                </p>
                            </div>

                            <div
                                v-if="can('categorias.ver')"
                                class="rounded-lg border border-gray-200 p-4"
                            >
                                <p class="text-sm text-gray-500">Categorías</p>
                                <p class="text-2xl font-semibold">
                                    {{ totales.categorias }}
                                </p>
                            </div>

                            <Link
                                v-if="can('pos.usar')"
                                :href="route('pos.index')"
                                class="block rounded-lg border border-gray-200 p-4 hover:bg-gray-50"
                            >
                                <p class="text-sm text-gray-500">
                                    Punto de venta
                                </p>
                                <p class="text-lg font-medium text-gray-700">
                                    Registrar ventas
                                </p>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
