<script setup lang="ts">
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
    type ChartConfiguration,
} from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatoMoneda } from '@/helpers/formato';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

const props = defineProps<{
    tipo: 'line' | 'bar';
    etiquetas: string[];
    valores: number[];
}>();

const COLOR = '#16a34a';

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;

const config = (): ChartConfiguration => ({
    type: props.tipo,
    data: {
        labels: props.etiquetas,
        datasets: [
            {
                label: 'Ventas',
                data: props.valores,
                borderColor: COLOR,
                backgroundColor:
                    props.tipo === 'line'
                        ? 'rgba(22, 163, 74, 0.12)'
                        : COLOR,
                fill: props.tipo === 'line',
                tension: props.tipo === 'line' ? 0.3 : undefined,
                pointRadius: props.tipo === 'line' ? 3 : undefined,
                pointBackgroundColor: props.tipo === 'line' ? COLOR : undefined,
                borderRadius: props.tipo === 'bar' ? 4 : undefined,
                maxBarThickness: 36,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (elemento) => formatoMoneda(elemento.parsed.y),
                },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: {
                    maxRotation: props.tipo === 'line' ? 0 : 0,
                    autoSkip: true,
                    maxTicksLimit: props.tipo === 'line' ? 14 : 6,
                },
            },
            y: {
                beginAtZero: true,
                ticks: {
                    callback: (valor) =>
                        new Intl.NumberFormat('es-AR', {
                            style: 'currency',
                            currency: 'ARS',
                            notation: 'compact',
                        }).format(Number(valor)),
                },
            },
        },
    },
});

const crear = () => {
    if (canvas.value === null) {
        return;
    }

    chart?.destroy();
    chart = new Chart(canvas.value, config());
};

const actualizar = () => {
    if (chart === null) {
        crear();

        return;
    }

    chart.data.labels = props.etiquetas;
    chart.data.datasets[0].data = props.valores;
    chart.update();
};

onMounted(() => crear());

watch(() => [props.etiquetas, props.valores], actualizar, { deep: true });

onBeforeUnmount(() => {
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div class="relative h-64 w-full">
        <canvas ref="canvas"></canvas>
    </div>
</template>