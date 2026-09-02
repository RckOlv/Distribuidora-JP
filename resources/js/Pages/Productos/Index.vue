<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import { etiquetaUnidad, formatoMoneda } from '@/helpers/formato';
import { usePermisos } from '@/composables/usePermisos';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type {
    Categoria,
    FiltrosProductos,
    Paginacion as TipoPaginacion,
    Producto,
} from '@/types';

const props = defineProps<{
    productos: TipoPaginacion;
    categorias: Categoria[];
    filtros: FiltrosProductos;
}>();

const { can } = usePermisos();

const form = useForm({
    q: props.filtros.q,
    categoria_id: String(props.filtros.categoria_id ?? ''),
    estado: props.filtros.estado,
});

const buscar = () => {
    form.get(route('productos.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

const limpiar = () => {
    router.get(route('productos.index'));
};

const toggleEstado = (producto: Producto) => {
    router.post(route('productos.estado', producto.id), undefined, {
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Productos" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-900">
                            Productos
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Buscá por nombre o código y administrá el catálogo.
                        </p>
                    </div>

                    <Link
                        v-if="can('productos.crear')"
                        :href="route('productos.create')"
                        class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Nuevo producto
                    </Link>
                </div>

                <form
                    @submit.prevent="buscar"
                    class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4"
                >
                    <div>
                        <label
                            for="q"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Buscar
                        </label>
                        <input
                            id="q"
                            v-model="form.q"
                            type="text"
                            placeholder="Nombre o código"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        />
                    </div>

                    <div>
                        <label
                            for="categoria_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Categoría
                        </label>
                        <select
                            id="categoria_id"
                            v-model="form.categoria_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="">Todas</option>
                            <option
                                v-for="categoria in categorias"
                                :key="categoria.id"
                                :value="String(categoria.id)"
                            >
                                {{ categoria.nombre }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="estado"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Estado
                        </label>
                        <select
                            id="estado"
                            v-model="form.estado"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
                            <option value="todos">Todos</option>
                            <option value="activos">Activos</option>
                            <option value="inactivos">Inactivos</option>
                        </select>
                    </div>

                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            @click="limpiar"
                            class="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <div
                    class="mt-6 overflow-hidden bg-white shadow sm:rounded-lg"
                >
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Producto
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Categoría
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Tipo de venta
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Código
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Precio actual
                                </th>
                                <th
                                    class="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Estado
                                </th>
                                <th
                                    v-if="can('productos.editar')"
                                    class="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="producto in productos.data"
                                :key="producto.id"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <img
                                            v-if="producto.imagen_url"
                                            :src="producto.imagen_url"
                                            alt=""
                                            class="h-10 w-10 shrink-0 rounded-md border border-gray-200 object-cover"
                                        />
                                        <div
                                            v-else
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400"
                                        >
                                            <svg
                                                class="h-5 w-5"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M3 7.5h18l-1.5 12a1.5 1.5 0 01-1.5 1.5H6a1.5 1.5 0 01-1.5-1.5L3 7.5zM8 10V6a4 4 0 118 0v4"
                                                />
                                            </svg>
                                        </div>
                                        <div>
                                            <div
                                                class="text-sm font-medium text-gray-900"
                                            >
                                                {{ producto.nombre }}
                                            </div>
                                            <div
                                                v-if="producto.descripcion"
                                                class="text-sm text-gray-500"
                                            >
                                                {{ producto.descripcion }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                    {{ producto.categoria?.nombre ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">
                                    {{
                                        etiquetaUnidad(producto.unidad_medida)
                                    }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                    <span
                                        v-if="producto.codigo"
                                        class="font-mono text-xs text-gray-600"
                                    >
                                        {{ producto.codigo }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-gray-400"
                                    >
                                        —
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                    {{
                                        formatoMoneda(
                                            producto.precio_vigente?.monto,
                                        )
                                    }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                        :class="
                                            producto.activo
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-gray-100 text-gray-600'
                                        "
                                    >
                                        {{
                                            producto.activo
                                                ? 'Activo'
                                                : 'Inactivo'
                                        }}
                                    </span>
                                </td>
                                <td
                                    v-if="can('productos.editar')"
                                    class="whitespace-nowrap px-6 py-4 text-end text-sm font-medium"
                                >
                                    <Link
                                        :href="
                                            route(
                                                'productos.edit',
                                                producto.id,
                                            )
                                        "
                                        class="text-green-600 hover:text-green-900"
                                    >
                                        Editar
                                    </Link>
                                    <button
                                        type="button"
                                        @click="toggleEstado(producto)"
                                        class="ms-4 text-gray-500 hover:text-gray-700"
                                    >
                                        {{
                                            producto.activo
                                                ? 'Desactivar'
                                                : 'Activar'
                                        }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="productos.data.length === 0">
                                <td
                                    :colspan="can('productos.editar') ? 7 : 6"
                                    class="px-6 py-10 text-center text-sm text-gray-500"
                                >
                                    No se encontraron productos.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <Paginacion :paginacion="productos" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>