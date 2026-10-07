<script setup lang="ts">
defineProps<{
    items: Array<{ key: string; title: string }>;
    discounts: Record<string, { code: string; percentage: string }>;
    applied: Array<{ key: string; amount: number }>;
    labels: { title: string; code: string; percentage: string; codePlaceholder: string };
    removeLabel: string;
}>();
const emit = defineEmits<{
    change: [key: string, field: 'code' | 'percentage', value: string];
    remove: [key: string];
}>();
</script>

<template>
    <details v-for="p in items" :key="p.key" class="group col-span-2 min-w-0 open:rounded-lg open:border open:border-amber-400/30 open:bg-amber-400/5 open:p-3">
        <summary class="cursor-pointer list-none text-sm font-semibold text-amber-400"><span class="group-open:hidden">+ </span><span class="hidden group-open:inline">− </span>{{ labels.title }}</summary>
        <p class="mt-1 break-words text-xs text-neutral-400">{{ p.title }}</p>
        <div class="mt-3 grid grid-cols-[minmax(0,1fr)_90px] gap-2">
            <label class="grid min-w-0 gap-1 text-xs text-neutral-300">
                {{ labels.code }}
                <input :value="discounts[p.key]?.code ?? ''" type="text" maxlength="100" autocomplete="off" :placeholder="labels.codePlaceholder" class="w-full min-w-0 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-2 text-sm text-white" @input="emit('change', p.key, 'code', ($event.target as HTMLInputElement).value)" />
            </label>
            <label class="grid min-w-0 gap-1 text-xs text-neutral-300">
                {{ labels.percentage }} %
                <input :value="discounts[p.key]?.percentage ?? ''" type="number" min="0" max="100" step="0.01" placeholder="0" class="w-full min-w-0 rounded-md border border-neutral-700 bg-neutral-900 px-2 py-2 text-sm text-white" @input="emit('change', p.key, 'percentage', ($event.target as HTMLInputElement).value)" />
            </label>
        </div>
        <p v-if="applied.find(d => d.key === p.key)" class="mt-2 text-sm text-emerald-300">−{{ applied.find(d => d.key === p.key)!.amount.toFixed(2) }} €</p>
        <button v-if="discounts[p.key]" type="button" class="mt-2 text-xs text-neutral-400 underline" @click="emit('remove', p.key)">{{ removeLabel }}</button>
    </details>
</template>
