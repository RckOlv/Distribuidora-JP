<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ValorCampo from '@/Components/ValorCampo.vue';
import ValorCambio from '@/Components/ValorCambio.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { RegistroAuditoriaDetalle } from '@/types';
import { camposDe, cambiosDe } from '@/helpers/formatoAuditoria';

const props = defineProps<{
    registro: RegistroAuditoriaDetalle;
}>();

const fecha = (iso: string | null) => {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString('es-AR');
};

const entidad = (tipo: string) => tipo.replace(/_/g, ' ');

const anteriores = computed<Record<string, unknown> | null>(
    () => props.registro.datos_anteriores,
);
const nuevos = computed<Record<string, unknown> | null>(
    () => props.registro.datos_nuevos,
);
const tieneAnteriores = computed(
    () => anteriores.value !== null && Object.keys(anteriores.value).length > 0,
);
const tieneNuevos = computed(
    () => nuevos.value !== null && Object.keys(nuevos.value).length > 0,
);
const camposAnteriores = computed(() => camposDe(anteriores.value));
const camposNuevos = computed(() => camposDe(nuevos.value));
const cambios = computed(() => cambiosDe(anteriores.value, nuevos.value));
const mostrarCambios = computed(() => cambios.value.length > 0);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Detalle de auditoría" />

        <div class="py-12">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('auditoria.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver a la auditoría
                    </Link>
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-gray-900">
                    Detalle de auditoría
                </h2>

                <div class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm">
                    <dl class="grid gap-x-6 gap-y-4 px-6 py-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Fecha/hora
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ fecha(registro.fecha) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Usuario
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ registro.usuario ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Acción
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ registro.accion_etiqueta }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                Entidad
                            </dt>
                            <dd class="mt-1 text-sm capitalize text-gray-900">
                                {{ entidad(registro.entidad_tipo) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">
                                ID
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ registro.entidad_id ?? '—' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="border-t border-gray-200 px-6 py-4">
                        <dt class="text-xs uppercase tracking-wider text-gray-500">
                            Descripción
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ registro.descripcion ?? '—' }}
                        </dd>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-6 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Datos anteriores
                            </h3>
                        </div>
                        <dl
                            v-if="tieneAnteriores"
                            class="divide-y divide-gray-100"
                        >
                            <div
                                v-for="campo in camposAnteriores"
                                :key="campo.clave"
                                class="grid grid-cols-1 gap-1 px-6 py-3 sm:grid-cols-3 sm:gap-4"
                            >
                                <dt class="text-sm text-gray-500">
                                    {{ campo.etiqueta }}
                                </dt>
                                <dd class="text-sm font-medium text-gray-900 sm:col-span-2">
                                    <ValorCampo :valor="campo.valor" />
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="px-6 py-4 text-sm text-gray-500">
                            Sin datos anteriores.
                        </p>
                    </div>

                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-6 py-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Datos nuevos
                            </h3>
                        </div>
                        <dl
                            v-if="tieneNuevos"
                            class="divide-y divide-gray-100"
                        >
                            <div
                                v-for="campo in camposNuevos"
                                :key="campo.clave"
                                class="grid grid-cols-1 gap-1 px-6 py-3 sm:grid-cols-3 sm:gap-4"
                            >
                                <dt class="text-sm text-gray-500">
                                    {{ campo.etiqueta }}
                                </dt>
                                <dd class="text-sm font-medium text-gray-900 sm:col-span-2">
                                    <ValorCampo :valor="campo.valor" />
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="px-6 py-4 text-sm text-gray-500">
                            Sin datos nuevos.
                        </p>
                    </div>
                </div>

                <div
                    v-if="mostrarCambios"
                    class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm"
                >
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Cambios
                        </h3>
                    </div>
                    <dl class="divide-y divide-gray-100">
                        <div
                            v-for="cambio in cambios"
                            :key="cambio.clave"
                            class="grid grid-cols-1 gap-1 px-6 py-3 sm:grid-cols-3 sm:gap-4"
                        >
                            <dt class="text-sm text-gray-500">
                                {{ cambio.etiqueta }}
                            </dt>
                            <dd class="text-sm font-medium text-gray-900 sm:col-span-2">
                                <ValorCambio
                                    :anterior="cambio.anterior"
                                    :nuevo="cambio.nuevo"
                                />
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>