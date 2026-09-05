<script setup lang="ts">
import TarjetaPagos from '@/Components/TarjetaPagos.vue';
import type { ValorAuditoria } from '@/helpers/formatoAuditoria';

defineProps<{
    anterior: ValorAuditoria;
    nuevo: ValorAuditoria;
}>();
</script>

<template>
    <div class="min-w-0">
        <template v-if="anterior.tipo === 'pagos' || nuevo.tipo === 'pagos'">
            <TarjetaPagos
                v-if="anterior.tipo === 'pagos'"
                :pagos="anterior.pagos ?? []"
            />
            <span
                v-else
                :class="
                    anterior.tipo === 'nulo'
                        ? 'italic text-gray-400'
                        : 'text-gray-500 line-through decoration-gray-300'
                "
            >
                {{ anterior.texto }}
            </span>

            <div class="my-2">
                <span class="text-gray-400">→</span>
            </div>

            <TarjetaPagos
                v-if="nuevo.tipo === 'pagos'"
                :pagos="nuevo.pagos ?? []"
            />
            <span
                v-else
                class="whitespace-pre-wrap break-words font-semibold"
                :class="nuevo.tipo === 'nulo' ? 'italic font-normal text-gray-400' : ''"
            >
                {{ nuevo.texto }}
            </span>
        </template>

        <span
            v-else
            class="flex flex-wrap items-center gap-x-2 gap-y-1"
        >
            <template v-if="anterior.tipo === 'booleano'">
                <span class="text-red-600">❌</span>
                <span
                    class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700"
                >
                    {{ anterior.texto }}
                </span>
            </template>
            <template v-else>
                <span
                    :class="
                        anterior.tipo === 'nulo'
                            ? 'italic text-gray-400'
                            : 'text-gray-500 line-through decoration-gray-300'
                    "
                >
                    {{ anterior.texto }}
                </span>
            </template>

            <span class="text-gray-400">→</span>

            <template v-if="nuevo.tipo === 'booleano'">
                <span class="text-green-600">✅</span>
                <span
                    class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700"
                >
                    {{ nuevo.texto }}
                </span>
            </template>
            <template v-else>
                <span
                    class="whitespace-pre-wrap break-words font-semibold"
                    :class="nuevo.tipo === 'nulo' ? 'italic font-normal text-gray-400' : ''"
                >
                    {{ nuevo.texto }}
                </span>
            </template>
        </span>
    </div>
</template>