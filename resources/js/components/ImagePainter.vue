<script setup lang="ts">
import { onMounted, ref } from 'vue';
const props = defineProps<{ src: string; busy: boolean; error?: string; cover?: boolean }>();
const emit = defineEmits<{ save: [file: File]; cancel: [] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const color = ref(props.cover ? '#000000' : '#ffffff');
const size = ref(props.cover ? 12 : 20);
const picking = ref(false);
const ready = ref(false);
const error = ref('');
const history = ref<ImageData[]>([]);
const palette = ['#ffffff', '#000000', '#808080', '#ff0000', '#ff9900', '#ffff00', '#00aa44', '#0088ff', '#6633cc', '#ff66aa'];
const drawing = ref(false);
const tool = ref<'brush' | 'line' | 'square' | 'rectangle'>('brush');
const paintTools = [{ id: 'brush', label: 'Pennello' }, { id: 'line', label: 'Linea retta' }, { id: 'square', label: 'Quadrato' }, { id: 'rectangle', label: 'Rettangolo' }] as const;
let last = { x: 0, y: 0 };
let origin = { x: 0, y: 0 };
let base: ImageData | null = null;
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
    if (tool.value !== 'brush') { drawShape(e); return; }
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
const drawShape = (e: PointerEvent) => {
    if (!base || !canvas.value) return;
    const ctx = canvas.value.getContext('2d')!, p = point(e);
    ctx.putImageData(base, 0, 0);
    ctx.strokeStyle = color.value; ctx.lineWidth = size.value; ctx.lineCap = 'round';
    if (tool.value === 'line') {
        ctx.beginPath(); ctx.moveTo(origin.x, origin.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        return;
    }
    let w = p.x - origin.x, h = p.y - origin.y;
    if (tool.value === 'square') {
        const side = Math.min(Math.abs(w), Math.abs(h));
        w = Math.sign(w) * side; h = Math.sign(h) * side;
    }
    ctx.strokeRect(origin.x, origin.y, w, h);
};
const start = (e: PointerEvent) => {
    if (!ready.value || props.busy || e.button !== 0) return;
    const c = canvas.value!, ctx = c.getContext('2d')!;
    if (picking.value) {
        const p = point(e), pixel = ctx.getImageData(Math.max(0, Math.min(c.width - 1, Math.floor(p.x))), Math.max(0, Math.min(c.height - 1, Math.floor(p.y))), 1, 1).data;
        color.value = '#' + Array.from(pixel.slice(0, 3)).map(v => v.toString(16).padStart(2, '0')).join('');
        picking.value = false; return;
    }
    base = ctx.getImageData(0, 0, c.width, c.height);
    history.value.push(base);
    while (history.value.length > Math.max(1, Math.min(15, Math.floor(64 * 1024 * 1024 / (c.width * c.height * 4))))) history.value.shift();
    c.setPointerCapture(e.pointerId);
    drawing.value = true; origin = last = point(e);
    if (tool.value === 'brush') stroke(e);
};
const finish = (e: PointerEvent) => {
    if (!drawing.value) return;
    if (tool.value !== 'brush') drawShape(e);
    drawing.value = false; base = null;
};
const cancelStroke = () => {
    if (base) canvas.value?.getContext('2d')?.putImageData(base, 0, 0);
    if (drawing.value) history.value.pop();
    drawing.value = false; base = null;
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
            <section role="dialog" aria-modal="true" :aria-label="cover ? 'Oscura scritte' : 'Paint'" class="max-h-[95vh] w-[min(95vw,900px)] overflow-y-auto rounded-xl border bg-background p-5 text-foreground shadow-xl">
                <h2 class="font-semibold">{{ cover ? 'Oscura scritte · senza AI' : 'Paint' }}</h2>
                <div class="my-4 flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">Colore<input v-model="color" aria-label="Colore pennello" type="color" :disabled="busy" /></label>
                    <div class="flex gap-1"><button v-for="c in palette" :key="c" type="button" :aria-label="`Colore ${c}`" :aria-pressed="color === c" :style="{ backgroundColor: c }" class="h-7 w-7 rounded border-2" :class="color === c ? 'border-blue-500' : 'border-gray-400'" :disabled="busy" @click="color = c"></button></div>
                    <label class="flex items-center gap-2 text-sm">Pennello<input v-model.number="size" type="range" min="1" max="150" :disabled="busy" />{{ size }} px</label>
                    <div class="flex gap-1"><button v-for="item in paintTools" :key="item.id" type="button" :aria-pressed="tool === item.id" :disabled="busy || drawing" class="rounded border px-3 py-1 text-sm" :class="tool === item.id ? 'border-blue-500 bg-blue-500/15' : ''" @click="tool = item.id">{{ item.label }}</button></div>
                    <button type="button" class="rounded border px-3 py-1" :disabled="busy" :aria-pressed="picking" @click="picking = !picking">{{ picking ? 'Clicca un colore nella foto…' : 'Pick color' }}</button>
                    <button type="button" class="rounded border px-3 py-1" :disabled="busy || !history.length" @click="undo">Annulla tratto</button>
                </div>
                <canvas ref="canvas" class="mx-auto block h-auto max-h-[65vh] max-w-full touch-none cursor-crosshair" @pointerdown="start" @pointermove="stroke" @pointerup="finish" @pointercancel="cancelStroke" />
                <p class="mt-3 text-xs text-muted-foreground">{{ cover ? 'Disegna sulle scritte da coprire. Scegli un colore oppure campionalo vicino alla scritta. I pixel fuori dai tratti restano invariati.' : 'Disegna sulla foto con pennello, linea retta, quadrato o rettangolo. Quadrati e rettangoli hanno solo il contorno. Dimensioni e proporzioni restano invariate.' }}</p>
                <p v-if="error || props.error" role="alert" class="mt-3 text-sm text-red-600">{{ error || props.error }}</p>
                <div class="mt-4 flex justify-end gap-3"><button type="button" class="rounded border px-4 py-2" :disabled="busy" @click="emit('cancel')">Annulla</button><button type="button" class="rounded bg-foreground px-4 py-2 text-background" :disabled="busy || !ready || drawing" @click="save">{{ busy ? 'Salvataggio…' : 'Salva foto' }}</button></div>
            </section>
        </div>
    </Teleport>
</template>
