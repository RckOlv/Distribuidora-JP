<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginacion } from '@/types';

defineProps<{
    paginacion: Paginacion;
}>();
</script>

<template>
    <div
        class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6"
    >
        <p class="text-sm text-gray-500">
            Mostrando {{ paginacion.from ?? 0 }}–{{ paginacion.to ?? 0 }} de
            {{ paginacion.total }} productos
        </p>

        <div class="flex items-center gap-2">
            <Link
                v-if="paginacion.prev_page_url"
                :href="paginacion.prev_page_url"
                class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
            >
                Anterior
            </Link>
            <span
                v-else
                class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-300"
            >
                Anterior
            </span>

            <Link
                v-if="paginacion.next_page_url"
                :href="paginacion.next_page_url"
                class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
            >
                Siguiente
            </Link>
            <span
                v-else
                class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-300"
            >
                Siguiente
            </span>
        </div>
    </div>
</template>