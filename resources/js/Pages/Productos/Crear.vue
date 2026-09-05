<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import {
    etiquetaUnidad,
    formatoMoneda,
    opcionesUnidad,
    unidadDescripcion,
} from '@/helpers/formato';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Categoria, UnidadVenta } from '@/types';

const props = defineProps<{
    categorias: Categoria[];
}>();

const form = useForm({
    nombre: '',
    categoria_id: '',
    unidad_medida: '',
    monto: '',
    costo: '',
    codigo: '',
    descripcion: '',
    imagen: null as File | null,
});

const modalConfirmacion = ref(false);

const confirmarGuardar = () => {
    form.post(route('productos.store'), {
        onSuccess: () => form.reset(),
        onError: () => {
            modalConfirmacion.value = false;
        },
    });
};

const nombreDuplicado = ref(false);
let temporizadorNombre: ReturnType<typeof setTimeout> | null = null;

const verificarNombre = async () => {
    const nombre = form.nombre.trim();

    if (nombre.length === 0) {
        nombreDuplicado.value = false;
        return;
    }

    try {
        const resp = await fetch(
            `${route('productos.verificar-nombre')}?nombre=${encodeURIComponent(nombre)}`,
            { headers: { Accept: 'application/json' } },
        );
        const datos = (await resp.json()) as { existe: boolean };
        nombreDuplicado.value = datos.existe;
    } catch {
        nombreDuplicado.value = false;
    }
};

watch(
    () => form.nombre,
    () => {
        if (temporizadorNombre) {
            clearTimeout(temporizadorNombre);
        }

        temporizadorNombre = setTimeout(verificarNombre, 400);
    },
);

const previewImagen = ref<string | null>(null);
const archivoImagen = ref<File | null>(null);
const inputImagen = ref<HTMLInputElement | null>(null);

const onImagenSeleccionada = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const archivo = input.files?.[0] ?? null;

    form.imagen = archivo;
    archivoImagen.value = archivo;

    if (previewImagen.value) {
        URL.revokeObjectURL(previewImagen.value);
    }

    previewImagen.value = archivo ? URL.createObjectURL(archivo) : null;
};

const limpiar = () => {
    if (previewImagen.value) {
        URL.revokeObjectURL(previewImagen.value);
    }

    previewImagen.value = null;
    archivoImagen.value = null;

    if (inputImagen.value) {
        inputImagen.value.value = '';
    }

    form.reset();
};

const submit = () => {
    modalConfirmacion.value = true;
};

const opciones = opcionesUnidad();

const categoriaSeleccionada = computed(
    () =>
        props.categorias.find(
            (c) => String(c.id) === form.categoria_id,
        )?.nombre ?? null,
);

const unidadEtiqueta = computed(() =>
    form.unidad_medida
        ? etiquetaUnidad(form.unidad_medida as UnidadVenta)
        : null,
);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Nuevo producto" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('productos.index')"
                        class="text-sm font-medium text-green-600 hover:text-green-900"
                    >
                        ← Volver a productos
                    </Link>
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-gray-900">
                    Nuevo producto
                </h2>

                <form
                    @submit.prevent="submit"
                    class="mt-6 rounded-lg bg-white p-6 shadow sm:rounded-lg"
                >
                    <div
                        class="grid grid-cols-1 items-start gap-x-6 gap-y-6 lg:grid-cols-2"
                    >
                        <div>
                            <InputLabel for="codigo" value="Código / barcode" />
                            <TextInput
                                id="codigo"
                                v-model="form.codigo"
                                type="text"
                                class="mt-1 block w-full"
                                placeholder="Ej.: 7791234567890"
                                maxlength="64"
                                required
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Código de barras del producto. Agiliza el
                                agregado desde el POS con el lector.
                            </p>
                            <InputError
                                class="mt-2"
                                :message="form.errors.codigo"
                            />
                        </div>

                        <div>
                            <InputLabel for="nombre" value="Nombre" />
                            <TextInput
                                id="nombre"
                                v-model="form.nombre"
                                type="text"
                                class="mt-1 block w-full"
                                placeholder="Ej.: Papa"
                                required
                                autofocus
                                maxlength="150"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.nombre"
                            />
                            <p
                                v-if="nombreDuplicado"
                                class="mt-2 text-sm text-red-600"
                            >
                                Ya existe un producto con ese nombre.
                            </p>
                        </div>

                        <div>
                            <InputLabel
                                for="categoria_id"
                                value="Categoría"
                            />
                            <select
                                id="categoria_id"
                                v-model="form.categoria_id"
                                required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                            >
                                <option value="" disabled>
                                    Seleccioná una categoría
                                </option>
                                <option
                                    v-for="categoria in props.categorias"
                                    :key="categoria.id"
                                    :value="String(categoria.id)"
                                >
                                    {{ categoria.nombre }}
                                </option>
                            </select>
                            <InputError
                                class="mt-2"
                                :message="form.errors.categoria_id"
                            />
                        </div>

                        <div>
                            <InputLabel
                                for="unidad_medida"
                                value="Tipo de venta"
                            />
                            <div class="mt-1 flex flex-wrap gap-2">
                                <button
                                    v-for="opcion in opciones"
                                    :key="opcion.valor"
                                    type="button"
                                    @click="form.unidad_medida = opcion.valor"
                                    class="rounded-md px-4 py-2 text-sm font-medium ring-1 ring-inset transition"
                                    :class="
                                        form.unidad_medida === opcion.valor
                                            ? 'bg-green-600 text-white ring-green-600'
                                            : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50'
                                    "
                                >
                                    {{ opcion.etiqueta }}
                                </button>
                            </div>
                            <p
                                v-if="form.unidad_medida"
                                class="mt-1 text-xs text-gray-500"
                            >
                                {{
                                    unidadDescripcion(
                                        form.unidad_medida as UnidadVenta,
                                    )
                                }}
                            </p>
                            <InputError
                                class="mt-2"
                                :message="form.errors.unidad_medida"
                            />
                        </div>

                        <div>
                            <InputLabel for="costo" value="Precio de costo" />
                            <TextInput
                                id="costo"
                                v-model="form.costo"
                                type="number"
                                step="0.01"
                                min="0.01"
                                class="mt-1 block w-full"
                                placeholder="Ej.: 1200"
                                required
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Costo de compra del producto. Queda registrado
                                como costo vigente y puede actualizarse después.
                            </p>
                            <InputError
                                class="mt-2"
                                :message="form.errors.costo"
                            />
                        </div>

                        <div>
                            <InputLabel for="monto" value="Precio de venta" />
                            <TextInput
                                id="monto"
                                v-model="form.monto"
                                type="number"
                                step="0.01"
                                min="0.01"
                                class="mt-1 block w-full"
                                placeholder="Ej.: 1800"
                                required
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                El precio inicial queda registrado como precio
                                de venta vigente del producto.
                            </p>
                            <InputError
                                class="mt-2"
                                :message="form.errors.monto"
                            />
                        </div>

                        <div>
                            <InputLabel
                                for="imagen"
                                value="Imagen (opcional)"
                            />
                            <div
                                class="mt-1 rounded-lg border border-gray-200 p-4"
                            >
                                <div class="flex items-center gap-4">
                                    <img
                                        v-if="previewImagen"
                                        :src="previewImagen"
                                        alt="Vista previa"
                                        class="h-24 w-24 shrink-0 rounded-md border border-gray-200 object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-24 w-24 shrink-0 items-center justify-center rounded-md border border-dashed border-gray-300 text-xs text-gray-400"
                                    >
                                        Sin imagen
                                    </div>
                                </div>
                                <label
                                    for="imagen"
                                    class="mt-3 block w-full cursor-pointer rounded-md bg-green-50 px-4 py-2 text-center text-sm font-medium text-green-700 ring-1 ring-inset ring-gray-300 hover:bg-green-100"
                                >
                                    Seleccionar archivo
                                </label>
                                <input
                                    id="imagen"
                                    ref="inputImagen"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif,image/bmp"
                                    @change="onImagenSeleccionada"
                                    class="sr-only"
                                />
                                <p
                                    class="mt-2 break-all text-sm text-gray-500"
                                >
                                    {{
                                        archivoImagen
                                            ? archivoImagen.name
                                            : 'Sin archivos seleccionados'
                                    }}
                                </p>
                                <InputError
                                    class="mt-2"
                                    :message="form.errors.imagen"
                                />
                            </div>
                        </div>

                        <div>
                            <InputLabel
                                for="descripcion"
                                value="Descripción"
                            />
                            <textarea
                                id="descripcion"
                                v-model="form.descripcion"
                                rows="4"
                                maxlength="500"
                                placeholder="Detalles del producto…"
                                class="mt-1 block w-full resize-y rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                            ></textarea>
                            <InputError
                                class="mt-2"
                                :message="form.errors.descripcion"
                            />
                        </div>
                    </div>

                    <div
                        class="mt-8 flex flex-wrap items-center justify-end gap-4"
                    >
                        <SecondaryButton
                            type="button"
                            @click="limpiar"
                        >
                            Limpiar
                        </SecondaryButton>
                        <PrimaryButton
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            Guardar
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>

        <Modal
            :show="modalConfirmacion"
            max-width="md"
            @close="modalConfirmacion = false"
        >
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">
                    Confirmar alta de producto
                </h3>
                <p class="mt-1 text-sm text-gray-600">
                    Vas a crear el siguiente producto:
                </p>

                <div
                    class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-center"
                >
                    <p class="text-xl font-semibold text-gray-900">
                        {{ form.nombre.trim() || '—' }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        Categoría:
                        <span class="font-medium text-gray-700">
                            {{ categoriaSeleccionada ?? '—' }}
                        </span>
                    </p>
                </div>

                <dl
                    class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2"
                >
                    <div
                        class="rounded-md bg-white px-3 py-2 ring-1 ring-gray-200"
                    >
                        <dt
                            class="text-xs uppercase tracking-wider text-gray-500"
                        >
                            Precio de venta
                        </dt>
                        <dd class="mt-0.5 text-base font-semibold text-gray-900">
                            {{ formatoMoneda(form.monto) }}
                        </dd>
                    </div>

                    <div
                        class="rounded-md bg-white px-3 py-2 ring-1 ring-gray-200"
                    >
                        <dt
                            class="text-xs uppercase tracking-wider text-gray-500"
                        >
                            Precio de costo
                        </dt>
                        <dd class="mt-0.5 text-base font-semibold text-gray-900">
                            {{ formatoMoneda(form.costo) }}
                        </dd>
                    </div>

                    <div
                        class="rounded-md bg-white px-3 py-2 ring-1 ring-gray-200"
                    >
                        <dt
                            class="text-xs uppercase tracking-wider text-gray-500"
                        >
                            Código
                        </dt>
                        <dd class="mt-0.5 text-base font-medium text-gray-900">
                            {{ form.codigo.trim() || '—' }}
                        </dd>
                    </div>

                    <div
                        class="rounded-md bg-white px-3 py-2 ring-1 ring-gray-200"
                    >
                        <dt
                            class="text-xs uppercase tracking-wider text-gray-500"
                        >
                            Tipo de venta
                        </dt>
                        <dd class="mt-0.5 text-base font-medium text-gray-900">
                            {{ unidadEtiqueta ?? '—' }}
                        </dd>
                    </div>

                    <div
                        v-if="form.descripcion.trim()"
                        class="rounded-md bg-white px-3 py-2 ring-1 ring-gray-200 sm:col-span-2"
                    >
                        <dt
                            class="text-xs uppercase tracking-wider text-gray-500"
                        >
                            Descripción
                        </dt>
                        <dd class="mt-0.5 text-sm text-gray-700">
                            {{ form.descripcion }}
                        </dd>
                    </div>
                </dl>

                <p class="mt-4 text-sm font-medium text-gray-700">
                    ¿Los datos son correctos?
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton
                        type="button"
                        :disabled="form.processing"
                        @click="modalConfirmacion = false"
                    >
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="confirmarGuardar"
                    >
                        {{
                            form.processing
                                ? 'Guardando…'
                                : 'Confirmar y guardar'
                        }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
