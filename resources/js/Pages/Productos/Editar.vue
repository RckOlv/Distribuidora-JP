<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatoMoneda, opcionesUnidad, unidadDescripcion } from '@/helpers/formato';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Categoria, Producto, UnidadVenta } from '@/types';

const props = defineProps<{
    producto: Producto;
    categorias: Categoria[];
}>();

const form = useForm({
    nombre: props.producto.nombre,
    categoria_id: String(props.producto.categoria_id),
    unidad_medida: props.producto.unidad_medida,
    monto: props.producto.precio_vigente?.monto ?? '',
    costo: props.producto.costo_vigente?.precio ?? '',
    codigo: props.producto.codigo ?? '',
    descripcion: props.producto.descripcion ?? '',
    imagen: null as File | null,
    quitar_imagen: false,
});

const nombreDuplicado = ref(false);
let temporizadorNombre: ReturnType<typeof setTimeout> | null = null;

const verificarNombre = async () => {
    const nombre = form.nombre.trim();

    if (nombre.length === 0) {
        nombreDuplicado.value = false;
        return;
    }

    try {
        const params = new URLSearchParams({
            nombre,
            excepto: String(props.producto.id),
        });
        const resp = await fetch(
            `${route('productos.verificar-nombre')}?${params.toString()}`,
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

const mostrandoImagenActual = computed(() => {
    return (
        props.producto.imagen_url &&
        !previewImagen.value &&
        !form.quitar_imagen
    );
});

const onImagenSeleccionada = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const archivo = input.files?.[0] ?? null;

    form.imagen = archivo;

    if (previewImagen.value) {
        URL.revokeObjectURL(previewImagen.value);
    }

    previewImagen.value = archivo ? URL.createObjectURL(archivo) : null;
};

const submit = () => {
    form.put(route('productos.update', props.producto.id));
};

const opciones = opcionesUnidad();
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Editar producto" />

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
                    Editar producto
                </h2>

                <form
                    @submit.prevent="submit"
                    class="mt-6 rounded-lg bg-white p-6 shadow sm:rounded-lg"
                >
                    <div>
                        <InputLabel for="nombre" value="Nombre" />
                        <TextInput
                            id="nombre"
                            v-model="form.nombre"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                            maxlength="150"
                        />
                        <InputError class="mt-2" :message="form.errors.nombre" />
                        <p
                            v-if="nombreDuplicado"
                            class="mt-2 text-sm text-red-600"
                        >
                            Ya existe un producto con ese nombre.
                        </p>
                    </div>

                    <div class="mt-4">
                        <InputLabel for="categoria_id" value="Categoría" />
                        <select
                            id="categoria_id"
                            v-model="form.categoria_id"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        >
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

                    <div class="mt-4">
                        <InputLabel for="unidad_medida" value="Tipo de venta" />
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
                            class="mt-2 text-xs text-gray-500"
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

                    <div class="mt-4">
                        <InputLabel for="costo" value="Precio de costo (opcional)" />
                        <TextInput
                            id="costo"
                            v-model="form.costo"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="mt-1 block w-full"
                            placeholder="Ej.: 1200"
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            {{
                                producto.costo_vigente
                                    ? `Costo actual: ${formatoMoneda(
                                          producto.costo_vigente.precio,
                                      )}. Un cambio de importe crea una nueva fila de costo vigente y conserva el historial; si el importe no cambia, no se registra ninguna fila nueva.`
                                    : 'El producto aún no tiene costo registrado. Al guardar con un importe se crea el costo vigente.'
                            }}
                        </p>
                        <InputError class="mt-2" :message="form.errors.costo" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="monto" value="Precio de venta vigente" />
                        <TextInput
                            id="monto"
                            v-model="form.monto"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="mt-1 block w-full"
                            placeholder="Ej.: 1800"
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            {{
                                producto.precio_vigente
                                    ? `Precio actual: ${formatoMoneda(
                                          producto.precio_vigente.monto,
                                      )}. Un cambio de importe crea una nueva fila de precio vigente y conserva el historial; si el importe no cambia, no se registra ninguna fila nueva.`
                                    : 'El producto aún no tiene precio vigente.'
                            }}
                        </p>
                        <InputError class="mt-2" :message="form.errors.monto" />
                    </div>

                    <div class="mt-4">
                        <InputLabel
                            for="codigo"
                            value="Código / barcode (opcional)"
                        />
                        <TextInput
                            id="codigo"
                            v-model="form.codigo"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="Ej.: 7791234567890"
                            maxlength="64"
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            Los productos a granel pueden dejarse sin código.
                        </p>
                        <InputError class="mt-2" :message="form.errors.codigo" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="imagen" value="Imagen" />
                        <input
                            id="imagen"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/gif,image/bmp"
                            @change="onImagenSeleccionada"
                            class="block w-full text-sm text-gray-500 file:me-3 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-green-700 hover:file:bg-green-100"
                        />
                        <div class="mt-3 flex items-center gap-4">
                            <img
                                v-if="mostrandoImagenActual"
                                :src="props.producto.imagen_url ?? undefined"
                                alt="Imagen actual"
                                class="h-20 w-20 rounded-md border border-gray-200 object-cover"
                            />
                            <img
                                v-else-if="previewImagen"
                                :src="previewImagen"
                                alt="Nueva imagen"
                                class="h-20 w-20 rounded-md border border-gray-200 object-cover"
                            />
                            <div
                                v-else
                                class="flex h-20 w-20 items-center justify-center rounded-md border border-dashed border-gray-300 text-xs text-gray-400"
                            >
                                Sin imagen
                            </div>
                        </div>
                        <label
                            v-if="props.producto.imagen_url"
                            class="mt-3 flex items-center gap-2 text-sm text-gray-600"
                        >
                            <input
                                type="checkbox"
                                v-model="form.quitar_imagen"
                                class="rounded border-gray-300 text-green-600 focus:ring-green-500"
                            />
                            Quitar imagen actual
                        </label>
                        <InputError class="mt-2" :message="form.errors.imagen" />
                    </div>

                    <div class="mt-4">
                        <InputLabel value="Estado" />
                        <p class="mt-1 text-sm text-gray-600">
                            Producto
                            <span class="font-medium text-gray-900">
                                {{ producto.activo ? 'activo' : 'inactivo' }}
                            </span>
                            . Podés cambiarlo desde el listado.
                        </p>
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
                            @click="form.reset()"
                        >
                            Deshacer
                        </SecondaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
