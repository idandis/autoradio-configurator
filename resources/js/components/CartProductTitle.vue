<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ title: string; fallback?: string }>();
const measure = ref<HTMLElement | null>(null);
const overflow = ref(false);
let observer: ResizeObserver | undefined;

const update = () => {
    const el = measure.value;
    if (!el || !el.clientWidth) return;
    overflow.value = el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight) * 3 + 1;
};

onMounted(() => {
    observer = new ResizeObserver(update);
    if (measure.value) observer.observe(measure.value);
    void document.fonts.ready.then(update);
    update();
});
watch(() => props.title, update, { flush: 'post' });
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="relative min-w-0 font-semibold leading-6" :title="title">
        <p class="break-words" :class="{ 'line-clamp-3': !overflow || !fallback }">{{ overflow && fallback ? fallback : title }}</p>
        <p ref="measure" aria-hidden="true" class="pointer-events-none invisible absolute inset-x-0 top-0 break-words">{{ title }}</p>
    </div>
</template>
