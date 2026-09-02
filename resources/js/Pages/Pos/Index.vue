<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import {
    accionEscanear,
    agregarCantidadAlCarrito,
    esUnidadKilogramo,
    normalizarPeso,
} from '@/helpers/carrito';
import { formatoMoneda } from '@/helpers/formato';
import { Head, Link, useForm } from '@inertiajs/vue3';
import type {
    CategoriaVenta,
    ItemCarrito,
    MedioPago,
    OpcionMedioPago,
    ProductoVenta,
} from '@/types';

const props = defineProps<{
    categorias: CategoriaVenta[];
    productos: ProductoVenta[];
    medios_pago: OpcionMedioPago[];
    caja_abierta: boolean;
    puede_abrir_caja: boolean;
}>();

const carrito = ref<ItemCarrito[]>([]);
const categoriaSeleccionada = ref<number | null>(null);
const busqueda = ref('');
const aviso = ref<string | null>(null);
const inputScan = ref<HTMLInputElement | null>(null);
const medioPago = ref<MedioPago>(props.medios_pago[0]?.valor ?? 'EFECTIVO');

const pesoModalAbierto = ref(false);
const productoPeso = ref<ProductoVenta | null>(null);
const pesoSolicitado = ref('');
const errorPeso = ref<string | null>(null);
const inputPeso = ref<HTMLInputElement | null>(null);

const form = useForm<{
    medio_pago: MedioPago;
    items: { producto_id: number; cantidad: number }[];
}>({
    medio_pago: 'EFECTIVO',
    items: [],
});

const paso = (producto: ProductoVenta): number =>
    esUnidadKilogramo(producto) ? 0.5 : 1;

const minimo = (producto: ProductoVenta): number =>
    esUnidadKilogramo(producto) ? 0.001 : 1;

const enfocarScanner = async (): Promise<void> => {
    await nextTick();
    inputScan.value?.focus();
};

const enfocarPeso = async (): Promise<void> => {
    await nextTick();
    inputPeso.value?.focus();
    inputPeso.value?.select();
};

onMounted(enfocarScanner);

const productosVisibles = computed(() => {
    const texto = busqueda.value.trim().toLowerCase();

    return props.productos.filter((producto) => {
        const coincideCategoria =
            categoriaSeleccionada.value === null ||
            producto.categoria_id === categoriaSeleccionada.value;

        const coincideTexto =
            texto === '' ||
            producto.nombre.toLowerCase().includes(texto) ||
            (producto.codigo ?? '').toLowerCase().includes(texto);

        return coincideCategoria && coincideTexto;
    });
});

const total = computed(() =>
    carrito.value.reduce(
        (acumulado, item) =>
            acumulado + item.cantidad * Number(item.producto.precio ?? 0),
        0,
    ),
);

const unidadCorta = (producto: ProductoVenta): string => {
    switch (producto.unidad_medida) {
        case 'KILOGRAMO':
            return '/kg';
        case 'BOLSA':
            return '/bolsa';
        case 'UNIDAD':
            return '/unidad';
    }
};

const formatoCantidad = (cantidad: number): string =>
    cantidad.toLocaleString('es-AR', { maximumFractionDigits: 3 });

const erroresClientes = computed<string | null>(() => {
    if (carrito.value.length === 0) {
        return 'El carrito está vacío.';
    }

    for (const item of carrito.value) {
        if (!(item.cantidad > 0)) {
            return `La cantidad de «${item.producto.nombre}» debe ser mayor que cero.`;
        }

        if (
            !esUnidadKilogramo(item.producto) &&
            !Number.isInteger(item.cantidad)
        ) {
            return `La cantidad de «${item.producto.nombre}» debe ser un número entero.`;
        }
    }

    return null;
});

const mensajeError = computed<string | null>(
    () => Object.values(form.errors)[0] ?? aviso.value,
);

watch(busqueda, (valor) => {
    if (valor.trim() !== '') {
        aviso.value = null;
    }
});

const seleccionarCategoria = (id: number | null): void => {
    categoriaSeleccionada.value = id;
};

const buscarPorCodigo = (): void => {
    const termino = busqueda.value.trim();

    if (termino === '') {
        enfocarScanner();

        return;
    }

    const exacto = props.productos.find(
        (producto) =>
            producto.codigo !== null && producto.codigo.trim() === termino,
    );

    if (exacto) {
        addProducto(exacto);

        return;
    }

    aviso.value = `No se encontró ningún producto con el código «${termino}».`;
    enfocarScanner();
};

const addProducto = (producto: ProductoVenta): void => {
    const accion = accionEscanear(producto);

    if (accion.tipo === 'solicitar_peso') {
        solicitarPeso(producto);

        return;
    }

    agregarConCantidad(producto, accion.cantidad);
};

const solicitarPeso = (producto: ProductoVenta): void => {
    if (!producto.precio) {
        aviso.value = `«${producto.nombre}» no tiene precio vigente.`;

        return;
    }

    busqueda.value = '';
    aviso.value = null;
    form.clearErrors();
    productoPeso.value = producto;
    pesoSolicitado.value = '';
    errorPeso.value = null;
    pesoModalAbierto.value = true;
    enfocarPeso();
};

const cancelarPeso = (): void => {
    pesoModalAbierto.value = false;
    productoPeso.value = null;
    pesoSolicitado.value = '';
    errorPeso.value = null;
    enfocarScanner();
};

const confirmarPeso = (): void => {
    if (productoPeso.value === null) {
        return;
    }

    const cantidad = normalizarPeso(pesoSolicitado.value);

    if (cantidad === null) {
        errorPeso.value = 'Ingresá un peso válido mayor que cero (ej. 1,350).';
        enfocarPeso();

        return;
    }

    agregarConCantidad(productoPeso.value, cantidad);
    cancelarPeso();
};

const agregarConCantidad = (
    producto: ProductoVenta,
    cantidad: number,
): void => {
    if (!producto.precio) {
        aviso.value = `«${producto.nombre}» no tiene precio vigente.`;

        return;
    }

    carrito.value = agregarCantidadAlCarrito(carrito.value, producto, cantidad);

    busqueda.value = '';
    aviso.value = null;
    form.clearErrors();
    enfocarScanner();
};

const setCantidad = (item: ItemCarrito, evento: Event): void => {
    const numero = Number((evento.target as HTMLInputElement).value);

    if (!Number.isFinite(numero) || numero <= 0) {
        item.cantidad = minimo(item.producto);

        return;
    }

    item.cantidad = esUnidadKilogramo(item.producto)
        ? Math.round(numero * 1000) / 1000
        : Math.round(numero);
};

const incrementar = (item: ItemCarrito): void => {
    item.cantidad =
        Math.round((item.cantidad + paso(item.producto)) * 1000) / 1000;
};

const decrementar = (item: ItemCarrito): void => {
    const nuevo = item.cantidad - paso(item.producto);
    item.cantidad =
        nuevo < minimo(item.producto)
            ? minimo(item.producto)
            : Math.round(nuevo * 1000) / 1000;
};

const quitar = (item: ItemCarrito): void => {
    carrito.value = carrito.value.filter((i) => i !== item);
};

const vaciarCarrito = (): void => {
    carrito.value = [];
    aviso.value = null;
};

const confirmarVenta = (): void => {
    if (erroresClientes.value) {
        aviso.value = erroresClientes.value;

        return;
    }

    form.clearErrors();
    form.transform(() => ({
        medio_pago: medioPago.value,
        items: carrito.value.map((item) => ({
            producto_id: item.producto.id,
            cantidad: item.cantidad,
        })),
    })).post(route('pos.ventas.store'), {
        preserveScroll: true,
        onSuccess: () => {
            carrito.value = [];
            aviso.value = null;
            enfocarScanner();
        },
        onError: enfocarScanner,
    });
};
</script>

<template>
    <Head title="Punto de venta" />

    <AuthenticatedLayout>
        <div
            v-if="!caja_abierta"
            class="flex py-24"
        >
            <div class="mx-auto max-w-md px-4 text-center sm:px-6">
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 font-bold text-amber-700"
                >
                    !
                </div>
                <h2 class="mt-4 text-xl font-semibold text-gray-900">
                    Caja no abierta
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    Debe abrir una caja para comenzar a vender.
                </p>
                <div v-if="puede_abrir_caja" class="mt-6">
                    <Link
                        :href="route('caja.abrir.form')"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Abrir caja
                    </Link>
                </div>
            </div>
        </div>

        <div v-else class="py-6">
            <div
                class="mx-auto grid max-w-screen-2xl grid-cols-1 gap-4 px-4 sm:px-6 lg:grid-cols-3 2xl:px-8"
            >
                <!-- Catalogo -->
                <div class="space-y-4 lg:col-span-2">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <label
                            for="scan"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Buscar o escanear código
                        </label>
                        <input
                            id="scan"
                            ref="inputScan"
                            v-model="busqueda"
                            type="text"
                            autocomplete="off"
                            placeholder="Escribí un código y ENTER, o buscá un nombre…"
                            @keyup.enter="buscarPorCodigo"
                            class="mt-1 block w-full rounded-md border-gray-300 text-lg shadow-sm focus:border-green-500 focus:ring-green-500"
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            El lector funciona como teclado: al escanear se agrega el
                            producto y el foco vuelve aquí.
                        </p>
                    </div>

                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-sm font-medium text-gray-700">
                            Categorías
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                type="button"
                                @click="seleccionarCategoria(null)"
                                class="rounded-md px-4 py-2 text-sm font-medium shadow-sm transition"
                                :class="
                                    categoriaSeleccionada === null
                                        ? 'bg-green-600 text-white'
                                        : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'
                                "
                            >
                                Todas
                            </button>
                            <button
                                v-for="categoria in categorias"
                                :key="categoria.id"
                                type="button"
                                @click="seleccionarCategoria(categoria.id)"
                                class="rounded-md px-4 py-2 text-sm font-medium shadow-sm transition"
                                :class="
                                    categoriaSeleccionada === categoria.id
                                        ? 'bg-green-600 text-white'
                                        : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'
                                "
                            >
                                {{ categoria.nombre }}
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                        <button
                            v-for="producto in productosVisibles"
                            :key="producto.id"
                            type="button"
                            @click="addProducto(producto)"
                            class="group overflow-hidden rounded-lg bg-white text-start shadow-sm transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            <div class="flex h-24 items-center justify-center bg-gray-50">
                                <img
                                    v-if="producto.imagen_url"
                                    :src="producto.imagen_url"
                                    :alt="producto.nombre"
                                    class="h-full w-full object-cover"
                                />
                                <svg
                                    v-else
                                    class="h-10 w-10 text-gray-300"
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
                            <div class="p-3">
                                <p class="line-clamp-2 text-sm font-medium text-gray-900">
                                    {{ producto.nombre }}
                                </p>
                                <p
                                    class="mt-1 text-sm font-semibold text-green-700"
                                >
                                    {{
                                        formatoMoneda(producto.precio) +
                                        ' ' +
                                        unidadCorta(producto)
                                    }}
                                </p>
                            </div>
                        </button>
                    </div>

                    <div
                        v-if="productosVisibles.length === 0"
                        class="rounded-lg bg-white p-10 text-center text-sm text-gray-500 shadow-sm"
                    >
                        No se encontraron productos.
                    </div>
                </div>

                <!-- Carrito -->
                <div
                    class="flex max-h-[calc(100vh-8rem)] flex-col rounded-lg bg-white shadow-sm lg:sticky lg:top-24"
                >
                    <div class="border-b border-gray-200 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Carrito
                            </h3>
                            <button
                                v-if="carrito.length > 0"
                                type="button"
                                @click="vaciarCarrito"
                                class="text-sm text-gray-500 hover:text-gray-700"
                            >
                                Vaciar
                            </button>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-3">
                        <div
                            v-if="carrito.length === 0"
                            class="py-10 text-center text-sm text-gray-400"
                        >
                            El carrito está vacío.
                        </div>

                        <div
                            v-for="item in carrito"
                            :key="item.producto.id"
                            class="border-b border-gray-100 py-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">
                                        {{ item.producto.nombre }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{
                                            formatoMoneda(item.producto.precio) +
                                            ' ' +
                                            unidadCorta(item.producto)
                                        }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="quitar(item)"
                                    class="text-gray-400 hover:text-red-600"
                                    aria-label="Quitar producto"
                                >
                                    <svg
                                        class="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </button>
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        @click="decrementar(item)"
                                        class="h-8 w-8 rounded-md bg-gray-100 text-lg font-medium text-gray-700 hover:bg-gray-200"
                                    >
                                        −
                                    </button>
                                    <input
                                        type="number"
                                        :step="
                                            esUnidadKilogramo(item.producto)
                                                ? '0.001'
                                                : '1'
                                        "
                                        :min="minimo(item.producto)"
                                        :value="item.cantidad"
                                        @input="setCantidad(item, $event)"
                                        class="w-20 rounded-md border-gray-300 text-center text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                                    />
                                    <button
                                        type="button"
                                        @click="incrementar(item)"
                                        class="h-8 w-8 rounded-md bg-gray-100 text-lg font-medium text-gray-700 hover:bg-gray-200"
                                    >
                                        +
                                    </button>
                                    <span class="ms-1 text-xs text-gray-500">
                                        {{
                                            esUnidadKilogramo(item.producto)
                                                ? 'kg'
                                                : item.producto.unidad_medida ===
                                                    'BOLSA'
                                                  ? 'bolsas'
                                                  : 'uds'
                                        }}
                                    </span>
                                </div>
                                <p class="text-sm font-semibold text-gray-900">
                                    {{
                                        formatoMoneda(
                                            item.cantidad *
                                                Number(item.producto.precio),
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-gray-500">
                                TOTAL
                            </p>
                            <p class="text-2xl font-bold text-gray-900">
                                {{ formatoMoneda(total) }}
                            </p>
                        </div>

                        <p class="mt-3 text-sm font-medium text-gray-700">
                            Medio de pago
                        </p>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            <button
                                v-for="medio in medios_pago"
                                :key="medio.valor"
                                type="button"
                                @click="medioPago = medio.valor"
                                class="rounded-md px-2 py-2 text-sm font-medium transition"
                                :class="
                                    medioPago === medio.valor
                                        ? 'bg-green-600 text-white'
                                        : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'
                                "
                            >
                                {{ medio.etiqueta }}
                            </button>
                        </div>

                        <p
                            v-if="mensajeError"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ mensajeError }}
                        </p>

                        <button
                            type="button"
                            @click="confirmarVenta"
                            :disabled="form.processing"
                            class="mt-3 w-full rounded-md bg-green-600 px-4 py-3 text-lg font-semibold text-white shadow-sm transition hover:bg-green-700 disabled:opacity-50"
                        >
                            {{
                                form.processing
                                    ? 'Confirmando…'
                                    : 'Confirmar venta'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <Modal
            :show="pesoModalAbierto"
            max-width="md"
            @close="cancelarPeso"
        >
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">
                    ¿Cuánto pesa el producto?
                </h3>

                <p class="mt-1 text-sm text-gray-600">
                    {{
                        productoPeso
                            ? `${productoPeso.nombre} · ${formatoMoneda(
                                  productoPeso.precio,
                              )} /kg`
                            : ''
                    }}
                </p>

                <form
                    class="mt-4"
                    @submit.prevent="confirmarPeso"
                >
                    <div class="flex items-center gap-2">
                        <input
                            ref="inputPeso"
                            v-model="pesoSolicitado"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="Peso en kg (ej. 1,350)"
                            class="block w-full rounded-md border-gray-300 text-lg shadow-sm focus:border-green-500 focus:ring-green-500"
                        />
                        <span class="text-sm font-medium text-gray-500">
                            kg
                        </span>
                    </div>

                    <p
                        v-if="errorPeso"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ errorPeso }}
                    </p>

                    <div class="mt-4 flex justify-end gap-2">
                        <button
                            type="button"
                            @click="cancelarPeso"
                            class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-green-700"
                        >
                            Agregar
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>