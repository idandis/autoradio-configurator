<script setup lang="ts">
import { onMounted, ref } from 'vue';
const props = defineProps<{ src: string; busy: boolean; error?: string }>();
const emit = defineEmits<{ save: [data: { mask: File; strength: number }]; cancel: [] }>();
const image = ref<HTMLImageElement | null>(null), canvas = ref<HTMLCanvasElement | null>(null);
const strength = ref(12);
const size = ref(20), ready = ref(false), drawing = ref(false), eraser = ref(false);
const history = ref<ImageData[]>([]), error = ref('');
let last = { x: 0, y: 0 };
const src = new URL(props.src, window.location.href);
const url = ['http:', 'https:'].includes(src.protocol) ? src.pathname + src.search : props.src;
const loaded = () => {
    if (!image.value || !canvas.value) return;
    if (image.value.naturalWidth * image.value.naturalHeight > 8000000) { error.value = 'Massimo 8 megapixel per la sfocatura locale.'; return; }
    canvas.value.width = image.value.naturalWidth; canvas.value.height = image.value.naturalHeight; ready.value = true;
};
onMounted(() => { if (image.value?.complete && image.value.naturalWidth) loaded(); });
const point = (e: PointerEvent) => { const c = canvas.value!, b = c.getBoundingClientRect(); return { x: (e.clientX - b.left) * c.width / b.width, y: (e.clientY - b.top) * c.height / b.height }; };
const move = (e: PointerEvent) => {
    if (!drawing.value || props.busy) return;
    const ctx = canvas.value!.getContext('2d')!, p = point(e);
    ctx.globalCompositeOperation = eraser.value ? 'destination-out' : 'source-over';
    ctx.strokeStyle = '#ff3366'; ctx.fillStyle = '#ff3366'; ctx.lineWidth = size.value; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    ctx.beginPath(); ctx.arc(p.x, p.y, size.value / 2, 0, Math.PI * 2); ctx.fill(); last = p;
};
const start = (e: PointerEvent) => {
    if (!ready.value || props.busy || e.button !== 0) return;
    const c = canvas.value!;
    history.value.push(c.getContext('2d')!.getImageData(0, 0, c.width, c.height));
    while (history.value.length > Math.max(1, Math.min(15, Math.floor(64 * 1024 * 1024 / (c.width * c.height * 4))))) history.value.shift();
    c.setPointerCapture(e.pointerId); drawing.value = true; last = point(e); move(e);
};
const undo = () => { const state = history.value.pop(); if (state) canvas.value!.getContext('2d')!.putImageData(state, 0, 0); };
const save = () => {
    if (!ready.value || props.busy || drawing.value) return;
    error.value = '';
    const c = canvas.value!, data = c.getContext('2d')!.getImageData(0, 0, c.width, c.height);
    let count = 0;
    for (let i = 0; i < data.data.length; i += 4) {
        const selected = data.data[i + 3] > 0; if (selected) count++;
        data.data[i] = data.data[i + 1] = data.data[i + 2] = selected ? 255 : 0; data.data[i + 3] = 255;
    }
    if (!count) { error.value = 'Evidenzia almeno una scritta da rimuovere.'; return; }
    const mask = document.createElement('canvas'); mask.width = c.width; mask.height = c.height;
    mask.getContext('2d')!.putImageData(data, 0, 0);
    mask.toBlob(blob => {
        if (!blob || blob.size > 8 * 1024 * 1024) { error.value = 'Maschera troppo grande.'; return; }
        emit('save', { mask: new File([blob], 'mask.png', { type: 'image/png' }), strength: strength.value });
    }, 'image/png');
};
</script>
<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @keydown.esc="!busy && emit('cancel')">
            <section role="dialog" aria-modal="true" aria-label="Sfoca scritte" class="max-h-[95vh] w-[min(95vw,900px)] overflow-y-auto rounded-xl border bg-background p-5 text-foreground">
                <h2 class="font-semibold">Cancella scritte · sfocatura locale</h2>
                <p class="my-3 text-sm">Evidenzia in rosa le scritte da oscurare e regola la sfocatura. Il blur attenua anche i dettagli dentro la selezione; il resto della foto rimane originale. Nessuna ricostruzione AI.</p>
                <div class="my-3 flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-2">Pennello<input v-model.number="size" :disabled="busy" type="range" min="2" max="150" />{{ size }} px</label>
                    <label class="flex items-center gap-2">Intensità blur<input v-model.number="strength" :disabled="busy" type="range" min="1" max="100" />{{ strength }}</label>
                    <button type="button" :disabled="busy" :aria-pressed="eraser" class="rounded border px-3 py-1" @click="eraser = !eraser">{{ eraser ? 'Gomma selezione attiva' : 'Gomma selezione' }}</button>
                    <button type="button" :disabled="busy || !history.length" class="rounded border px-3 py-1" @click="undo">Annulla tratto</button>
                </div>
                <div class="relative mx-auto w-fit max-w-full">
                    <img ref="image" :src="url" alt="Foto originale da ripulire" class="block max-h-[60vh] max-w-full" :draggable="false" @load="loaded" @error="error = 'Foto non disponibile.'" />
                    <canvas ref="canvas" aria-label="Seleziona scritte da rimuovere" class="absolute inset-0 h-full w-full touch-none cursor-crosshair opacity-60" @pointerdown="start" @pointermove="move" @pointerup="drawing = false" @pointercancel="drawing = false" />
                </div>
                <p v-if="error || props.error" role="alert" class="mt-3 text-sm text-red-600">{{ error || props.error }}</p>
                <div class="mt-4 flex justify-end gap-3"><button type="button" :disabled="busy" class="rounded border px-4 py-2" @click="emit('cancel')">Annulla</button><button type="button" :disabled="busy || !ready || drawing" class="rounded bg-foreground px-4 py-2 text-background disabled:opacity-50" @click="save">{{ busy ? 'Sfocatura in corso…' : 'Sfoca scritte selezionate' }}</button></div>
            </section>
        </div>
    </Teleport>
</template>
