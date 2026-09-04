<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { usePermisos } from '@/composables/usePermisos';
import { notificarError, notificarExito } from '@/helpers/notificaciones';

const { can } = usePermisos();

const esCajero = computed(() => usePage().props.auth.user?.rol === 'CAJERO');

const showingNavigationDropdown = ref(false);

const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

watch(flashSuccess, (mensaje) => {
    if (mensaje) {
        notificarExito(mensaje);
    }
});

watch(flashError, (mensaje) => {
    if (!mensaje) {
        return;
    }

    const texto = Array.isArray(mensaje) ? mensaje.join(', ') : mensaje;

    notificarError(texto);
});

const rolLabel = computed(() => {
    const rol = usePage().props.auth.user?.rol;

    switch (rol) {
        case 'DUENO':
            return 'Dueño';
        case 'CAJERO':
            return 'Cajero';
        default:
            return rol ?? 'Sin rol';
    }
});
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav class="border-b border-gray-100 bg-white">
                <!-- Menu de navegacion principal -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                        <ApplicationLogo altura="h-11" />
                                    </Link>
                            </div>

                            <!-- Enlaces de navegacion -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Panel
                                </NavLink>

                                <NavLink
                                    v-if="!esCajero && can('categorias.ver')"
                                    :href="route('categorias.index')"
                                    :active="route().current('categorias.*')"
                                >
                                    Categorías
                                </NavLink>

                                <NavLink
                                    v-if="!esCajero && can('productos.ver')"
                                    :href="route('productos.index')"
                                    :active="route().current('productos.*')"
                                >
                                    Productos
                                </NavLink>

                                <NavLink
                                    v-if="can('pos.usar')"
                                    :href="route('pos.index')"
                                    :active="route().current('pos.*')"
                                >
                                    POS
                                </NavLink>

                                <NavLink
                                    v-if="can('cajas.usar')"
                                    :href="route('caja.index')"
                                    :active="route().current('caja.*')"
                                >
                                    Caja
                                </NavLink>

                                <NavLink
                                    v-if="!esCajero && can('ventas.ver')"
                                    :href="route('ventas.index')"
                                    :active="route().current('ventas.*')"
                                >
                                    Ventas
                                </NavLink>

                                <NavLink
                                    v-if="can('usuarios.gestionar')"
                                    :href="route('usuarios.index')"
                                    :active="route().current('usuarios.*')"
                                >
                                    Usuarios
                                </NavLink>

                                <NavLink
                                    v-if="can('auditoria.ver')"
                                    :href="route('auditoria.index')"
                                    :active="route().current('auditoria.*')"
                                >
                                    Auditoría
                                </NavLink>

                                <NavLink
                                    v-if="can('reportes.ver')"
                                    :href="route('reportes.index')"
                                    :active="route().current('reportes.*')"
                                >
                                    Reportes
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Menu de usuario -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
{{ $page.props.auth.user?.name }}

                                                <span
                                                    class="ms-2 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700"
                                                >
                                                    {{ rolLabel }}
                                                </span>

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <div
                                            class="border-b border-gray-100 px-4 py-3 text-sm text-gray-500"
                                        >
                                            Rol:
                                            <span
                                                class="font-medium text-gray-700"
                                            >
                                                {{ rolLabel }}
                                            </span>
                                        </div>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Perfil
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Cerrar sesión
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Boton hamburguesa (movil) -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Menu de navegacion responsive -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Panel
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="!esCajero && can('categorias.ver')"
                            :href="route('categorias.index')"
                            :active="route().current('categorias.*')"
                        >
                            Categorías
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="!esCajero && can('productos.ver')"
                            :href="route('productos.index')"
                            :active="route().current('productos.*')"
                        >
                            Productos
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="can('pos.usar')"
                            :href="route('pos.index')"
                            :active="route().current('pos.*')"
                        >
                            POS
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="can('cajas.usar')"
                            :href="route('caja.index')"
                            :active="route().current('caja.*')"
                        >
                            Caja
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="!esCajero && can('ventas.ver')"
                            :href="route('ventas.index')"
                            :active="route().current('ventas.*')"
                        >
                            Ventas
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="can('usuarios.gestionar')"
                            :href="route('usuarios.index')"
                            :active="route().current('usuarios.*')"
                        >
                            Usuarios
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="can('auditoria.ver')"
                            :href="route('auditoria.index')"
                            :active="route().current('auditoria.*')"
                        >
                            Auditoría
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="can('reportes.ver')"
                            :href="route('reportes.index')"
                            :active="route().current('reportes.*')"
                        >
                            Reportes
                        </ResponsiveNavLink>
                    </div>

                    <!-- Opciones de usuario (responsive) -->
                    <div class="border-t border-gray-200 pb-1 pt-4">
                        <div class="px-4">
                            <div class="text-base font-medium text-gray-800">
                                {{ $page.props.auth.user?.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user?.email }}
                            </div>
                            <div class="text-xs text-gray-400">
                                Rol: {{ rolLabel }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Perfil
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Cerrar sesión
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Encabezado de la pagina -->
            <header class="bg-white shadow" v-if="$slots.header">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Contenido de la pagina -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
