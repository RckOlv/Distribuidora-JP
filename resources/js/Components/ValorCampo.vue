<script setup lang="ts">
import TarjetaPagos from '@/Components/TarjetaPagos.vue';
import type { ValorAuditoria } from '@/helpers/formatoAuditoria';

defineProps<{
    valor: ValorAuditoria;
}>();
</script>

<template>
    <TarjetaPagos
        v-if="valor.tipo === 'pagos'"
        :pagos="valor.pagos ?? []"
    />

    <span
        v-else-if="valor.tipo === 'booleano'"
        class="inline-flex items-center gap-1.5"
    >
        <span
            class="inline-flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-bold text-white"
            :class="valor.booleano ? 'bg-green-500' : 'bg-red-400'"
        >
            {{ valor.booleano ? '✓' : '✕' }}
        </span>
        <span
            class="rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="
                valor.booleano
                    ? 'bg-green-100 text-green-700'
                    : 'bg-red-100 text-red-700'
            "
        >
            {{ valor.texto }}
        </span>
    </span>

    <span
        v-else-if="valor.tipo === 'nulo'"
        class="italic text-gray-400"
    >
        {{ valor.texto }}
    </span>

    <span
        v-else
        class="whitespace-pre-wrap break-words"
    >
        {{ valor.texto }}
    </span>
</template>