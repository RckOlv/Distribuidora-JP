<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type {
    FiltrosAuditoria,
    OpcionAccionAuditoria,
    OpcionEntidadAuditoria,
    Paginador,
    RegistroAuditoria,
    UsuarioOpcion,
} from '@/types';

const props = defineProps<{
    registros: Paginador<RegistroAuditoria>;
    usuarios: UsuarioOpcion[];
    acciones: OpcionAccionAuditoria[];
    entidades: OpcionEntidadAuditoria[];
    filtros: FiltrosAuditoria;
}>();

const form = useForm({
    fecha_desde: props.filtros.fecha_desde ?? '',
    fecha_hasta: props.filtros.fecha_hasta ?? '',
    usuario_id: props.filtros.usuario_id ? String(props.filtros.usuario_id) : '',
    accion: props.filtros.accion,
    entidad_tipo: props.filtros.entidad_tipo,
});

const buscar = () => {
    form.get(route('auditoria.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

const limpiar = () => {
    router.get(route('auditoria.index'));
};

const fecha = (iso: string | null) => {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString('es-AR');
};

// Páginas numeradas (con ventana y "…" para listados grandes).
const paginas = computed<Array<number | '...'>>(() => {
    const actual = props.registros.current_page;
    const total = props.registros.last_page;

    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const candidatas = Array.from(
        new Set([1, total, actual - 1, actual, actual + 1]),
    ).filter((n) => n >= 1 && n <= total).sort((a, b) => a - b);

    const resultado: Array<number | '...'> = [];
    let anterior = 0;

    for (const n of candidatas) {
        if (anterior !== 0 && n - anterior > 1) {
            resultado.push('...');
        }
        resultado.push(n);
        anterior = n;
    }

    return resultado;
});

// Conserva los filtros activos del querystring al navegar de página.
const urlPagina = (numero: number): string => {
    const url = new URL(window.location.href);
    url.searchParams.set('page', String(numero));

    return url.pathname + url.search;
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Auditoría" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div>
                    <h2 class="text-2xl font-semibold text-gray-900">
                        Auditoría
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Registro de las acciones relevantes realizadas en el
                        sistema. Solo lectura.
                    </p>
                </div>

                <form
                    @submit.prevent="buscar"
                    class="mt-6 grid grid-cols-1 gap-4 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6"
                >
                    <div>
                        <label
                            for="fecha_desde"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Desde
                        </label>
                        <input
                            id="fecha_desde"
                            v-model="form.fecha_desde"
                            type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label
                            for="fecha_hasta"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Hasta
                        </label>
                        <input
                            id="fecha_hasta"
                            v-model="form.fecha_hasta"
                            type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label
                            for="usuario_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Usuario
                        </label>
                        <select
                            id="usuario_id"
                            v-model="form.usuario_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="usuario in usuarios"
                                :key="usuario.id"
                                :value="String(usuario.id)"
                            >
                                {{ usuario.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            for="accion"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Acción
                        </label>
                        <select
                            id="accion"
                            v-model="form.accion"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="">Todas</option>
                            <option
                                v-for="accion in acciones"
                                :key="accion.valor"
                                :value="accion.valor"
                            >
                                {{ accion.etiqueta }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label
                            for="entidad_tipo"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Entidad
                        </label>
                        <select
                            id="entidad_tipo"
                            v-model="form.entidad_tipo"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="">Todas</option>
                            <option
                                v-for="entidad in entidades"
                                :key="entidad.valor"
                                :value="entidad.valor"
                            >
                                {{ entidad.etiqueta }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            @click="limpiar"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <div class="mt-6 overflow-x-auto bg-white shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Fecha
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Usuario
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Acción
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Entidad
                                </th>
                                <th
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    ID
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Descripción
                                </th>
                                <th
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Acción
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="registro in registros.data"
                                :key="registro.id"
                            >
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ fecha(registro.fecha) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ registro.usuario ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                    {{ registro.accion_etiqueta }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm capitalize text-gray-500">
                                    {{ registro.entidad_tipo.replace(/_/g, ' ') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-500">
                                    {{ registro.entidad_id ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    {{ registro.descripcion ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-end text-sm font-medium">
                                    <Link
                                        :href="route('auditoria.show', registro.id)"
                                        class="text-green-600 hover:text-green-900"
                                    >
                                        Ver
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="registros.data.length === 0">
                                <td
                                    colspan="7"
                                    class="px-6 py-10 text-center text-sm text-gray-500"
                                >
                                    No hay registros que coincidan con los filtros.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6"
                    >
                        <p class="text-sm text-gray-500">
                            Mostrando
                            {{ registros.from ?? 0 }}–{{ registros.to ?? 0 }} de
                            {{ registros.total }} registros
                        </p>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="registros.prev_page_url"
                                :href="registros.prev_page_url"
                                preserve-state
                                preserve-scroll
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

                            <template v-for="pagina in paginas" :key="pagina">
                                <span
                                    v-if="pagina === '...'"
                                    class="px-1 py-1.5 text-sm text-gray-400"
                                >
                                    …
                                </span>
                                <Link
                                    v-else
                                    :href="urlPagina(pagina)"
                                    preserve-state
                                    preserve-scroll
                                    class="rounded-md px-3 py-1.5 text-sm font-medium shadow-sm ring-1 ring-inset ring-gray-300 transition"
                                    :class="
                                        pagina ===
                                        registros.current_page
                                            ? 'bg-green-600 text-white ring-green-600'
                                            : 'bg-white text-gray-700 hover:bg-gray-50'
                                    "
                                >
                                    {{ pagina }}
                                </Link>
                            </template>

                            <Link
                                v-if="registros.next_page_url"
                                :href="registros.next_page_url"
                                preserve-state
                                preserve-scroll
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
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
