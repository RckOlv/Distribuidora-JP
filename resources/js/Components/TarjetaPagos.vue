<script setup lang="ts">
import { computed } from 'vue';
import { formatoMoneda } from '@/helpers/formato';
import { totalPagos, type PagoAuditoria } from '@/helpers/formatoAuditoria';

const props = defineProps<{
    pagos: PagoAuditoria[];
}>();

const total = computed(() => totalPagos(props.pagos));
</script>

<template>
    <div
        class="w-full min-w-0 max-w-sm overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
    >
        <div class="divide-y divide-gray-100">
            <div
                v-for="(pago, index) in pagos"
                :key="index"
                class="flex items-center justify-between gap-3 px-4 py-2"
            >
                <span class="min-w-0 truncate text-sm text-gray-700">
                    {{ pago.etiqueta }}
                </span>
                <span
                    class="shrink-0 text-sm font-medium tabular-nums text-gray-900"
                >
                    {{ formatoMoneda(pago.monto) }}
                </span>
            </div>
        </div>
        <div
            class="flex items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-4 py-2.5"
        >
            <span class="text-sm font-semibold text-gray-700">
                Total pagado
            </span>
            <span
                class="text-sm font-bold tabular-nums text-gray-900"
            >
                {{ formatoMoneda(total) }}
            </span>
        </div>
    </div>
</template>