<script setup lang="ts">
import { onMounted, ref } from 'vue';
const props = defineProps<{ src: string; busy: boolean; error?: string }>();
const emit = defineEmits<{ save: [file: File]; cancel: [] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const color = ref('#ffffff');
const size = ref(20);
const ready = ref(false);
const error = ref('');
const history = ref<ImageData[]>([]);
const palette = ['#ffffff', '#000000', '#808080', '#ff0000', '#ff9900', '#ffff00', '#00aa44', '#0088ff', '#6633cc', '#ff66aa'];
const drawing = ref(false);
let last = { x: 0, y: 0 };
onMounted(() => {
    const image = new Image();
    image.onload = () => {
        if (!canvas.value) return;
        canvas.value.width = image.naturalWidth;
        canvas.value.height = image.naturalHeight;
        canvas.value.getContext('2d')!.drawImage(image, 0, 0);
        ready.value = true;
    };
    image.onerror = () => { error.value = 'Immagine non disponibile.'; };
    const url = new URL(props.src, window.location.href);
    image.src = ['http:', 'https:'].includes(url.protocol) ? url.pathname + url.search : props.src;
});
const point = (e: PointerEvent) => {
    const c = canvas.value!, r = c.getBoundingClientRect();
    return { x: (e.clientX - r.left) * c.width / r.width, y: (e.clientY - r.top) * c.height / r.height };
};
const stroke = (e: PointerEvent) => {
    if (!drawing.value || props.busy) return;
    const ctx = canvas.value!.getContext('2d')!, p = point(e);
    ctx.strokeStyle = color.value;
    ctx.fillStyle = color.value;
    ctx.lineWidth = size.value;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    ctx.beginPath(); ctx.arc(p.x, p.y, size.value / 2, 0, Math.PI * 2); ctx.fill();
    last = p;
};
const start = (e: PointerEvent) => {
    if (!ready.value || props.busy || e.button !== 0) return;
    const c = canvas.value!, ctx = c.getContext('2d')!;
    history.value.push(ctx.getImageData(0, 0, c.width, c.height));
    while (history.value.length > Math.max(1, Math.min(15, Math.floor(64 * 1024 * 1024 / (c.width * c.height * 4))))) history.value.shift();
    c.setPointerCapture(e.pointerId);
    drawing.value = true; last = point(e); stroke(e);
};
const undo = () => { const state = history.value.pop(); if (state) canvas.value!.getContext('2d')!.putImageData(state, 0, 0); };
const save = () => {
    error.value = '';
    try {
        canvas.value?.toBlob(blob => {
            if (!blob || blob.size > 16 * 1024 * 1024) { error.value = 'Immagine troppo grande (massimo 16 MB).'; return; }
            emit('save', new File([blob], 'paint.png', { type: 'image/png' }));
        }, 'image/png');
    } catch {
        error.value = 'Impossibile salvare la foto. Chiudi Paint e riapri l’immagine.';
    }
};
</script>
<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @keydown.esc="!busy && emit('cancel')">
            <section role="dialog" aria-modal="true" aria-label="Paint" class="max-h-[95vh] w-[min(95vw,900px)] overflow-y-auto rounded-xl border bg-background p-5 text-foreground shadow-xl">
                <h2 class="font-semibold">Paint</h2>
                <div class="my-4 flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">Colore<input v-model="color" aria-label="Colore pennello" type="color" :disabled="busy" /></label>
                    <div class="flex gap-1"><button v-for="c in palette" :key="c" type="button" :aria-label="`Colore ${c}`" :aria-pressed="color === c" :style="{ backgroundColor: c }" class="h-7 w-7 rounded border-2" :class="color === c ? 'border-blue-500' : 'border-gray-400'" :disabled="busy" @click="color = c"></button></div>
                    <label class="flex items-center gap-2 text-sm">Pennello<input v-model.number="size" type="range" min="1" max="150" :disabled="busy" />{{ size }} px</label>
                    <button type="button" class="rounded border px-3 py-1" :disabled="busy || !history.length" @click="undo">Annulla tratto</button>
                </div>
                <canvas ref="canvas" class="mx-auto block h-auto max-h-[65vh] max-w-full touch-none cursor-crosshair" @pointerdown="start" @pointermove="stroke" @pointerup="drawing = false" @pointercancel="drawing = false" />
                <p class="mt-3 text-xs text-muted-foreground">Disegna direttamente sulla foto. Dimensioni e proporzioni restano invariate.</p>
                <p v-if="error || props.error" role="alert" class="mt-3 text-sm text-red-600">{{ error || props.error }}</p>
                <div class="mt-4 flex justify-end gap-3"><button type="button" class="rounded border px-4 py-2" :disabled="busy" @click="emit('cancel')">Annulla</button><button type="button" class="rounded bg-foreground px-4 py-2 text-background" :disabled="busy || !ready || drawing" @click="save">{{ busy ? 'Salvataggio…' : 'Salva foto' }}</button></div>
            </section>
        </div>
    </Teleport>
</template>
