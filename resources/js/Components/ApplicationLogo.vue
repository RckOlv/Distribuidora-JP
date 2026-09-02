<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        /** Altura del logo en píxeles (h-*) */
        altura?: string;
    }>(),
    {
        altura: 'h-9',
    },
);
</script>

<template>
    <img src="/img/logo.png" alt="Logo" :class="[props.altura, 'w-auto']">
</template>