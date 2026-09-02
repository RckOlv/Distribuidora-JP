<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        checked: Array<any> | boolean;
        value?: any;
    }>(),
    {
        value: null,
    },
);

const emit = defineEmits<{
    (e: 'update:checked', value: Array<any> | boolean): void;
}>();

const proxyChecked = computed({
    get() {
        return props.checked;
    },

    set(val) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <input
        type="checkbox"
        :value="value"
        v-model="proxyChecked"
        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
    />
</template>
