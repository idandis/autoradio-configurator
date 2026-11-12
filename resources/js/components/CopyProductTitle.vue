<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
const props = withDefaults(defineProps<{ title: string; text?: string }>(), { text: 'Copia titolo prodotto' });
const label = ref(props.text);
let timer: ReturnType<typeof setTimeout> | undefined;
async function copy() {
    if (timer) clearTimeout(timer);
    try { await navigator.clipboard.writeText(props.title); label.value = 'Copiato ✓'; }
    catch { label.value = 'Copia non riuscita'; }
    timer = setTimeout(() => { label.value = props.text; }, 2000);
}
onUnmounted(() => { if (timer) clearTimeout(timer); });
</script>

<template>
    <button type="button" class="ml-3 inline-block rounded-md border px-2 py-1 text-xs font-medium hover:border-primary" :aria-label="`${text}: ${title}`" @click.stop.prevent="copy">{{ label }}</button>
</template>
