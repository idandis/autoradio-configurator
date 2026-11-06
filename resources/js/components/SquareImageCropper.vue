<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ src: string; busy: boolean; error?: string }>();
const emit = defineEmits<{ save: [rect: { x: number; y: number; size: number; width: number; height: number }]; cancel: [] }>();
const stage = ref<HTMLDivElement | null>(null);
const dialog = ref<HTMLElement | null>(null);
onMounted(() => dialog.value?.focus());
const width = ref(0);
const height = ref(0);
const x = ref(0);
const y = ref(0);
const size = ref(0);
const failed = ref(false);
const shape = ref<'square' | 'rectangle'>('square');
const rw = ref(0), rh = ref(0);
const cw = computed(() => shape.value === 'square' ? size.value : rw.value);
const ch = computed(() => shape.value === 'square' ? size.value : rh.value);
const changeShape = () => {
    if (shape.value === 'rectangle') { rw.value = size.value; rh.value = size.value; }
    else size.value = Math.min(rw.value || size.value, rh.value || size.value);
    fit();
};
const maxSize = computed(() => Math.min(width.value, height.value));
const box = computed(() => ({ left: `${x.value / width.value * 100}%`, top: `${y.value / height.value * 100}%`, width: `${cw.value / width.value * 100}%`, height: `${ch.value / height.value * 100}%` }));
let drag: { mode: 'move' | 'resize'; cx: number; cy: number; x: number; y: number; size: number; rw: number; rh: number; sx: number; sy: number } | null = null;
const fit = () => {
    size.value = Math.max(32, Math.min(Math.round(size.value), maxSize.value));
    rw.value = Math.max(32, Math.min(Math.round(rw.value), width.value));
    rh.value = Math.max(32, Math.min(Math.round(rh.value), height.value));
    x.value = Math.max(0, Math.min(x.value, width.value - cw.value));
    y.value = Math.max(0, Math.min(y.value, height.value - ch.value));
};
const loaded = (event: Event) => {
    const image = event.target as HTMLImageElement;
    width.value = image.naturalWidth;
    height.value = image.naturalHeight;
    size.value = Math.floor(maxSize.value * 0.8);
    x.value = Math.floor((width.value - size.value) / 2);
    y.value = Math.floor((height.value - size.value) / 2);
};
const start = (event: PointerEvent, mode: 'move' | 'resize') => {
    if (props.busy || !stage.value) return;
    event.preventDefault();
    const bounds = stage.value.getBoundingClientRect();
    drag = { mode, cx: event.clientX, cy: event.clientY, x: x.value, y: y.value, size: size.value, rw: rw.value, rh: rh.value, sx: width.value / bounds.width, sy: height.value / bounds.height };
    stage.value.setPointerCapture(event.pointerId);
};
const move = (event: PointerEvent) => {
    if (!drag || props.busy) return;
    const dx = (event.clientX - drag.cx) * drag.sx;
    const dy = (event.clientY - drag.cy) * drag.sy;
    if (drag.mode === 'move') {
        x.value = Math.round(drag.x + dx);
        y.value = Math.round(drag.y + dy);
    } else if (shape.value === 'rectangle') {
        rw.value = Math.max(32, Math.min(Math.round(drag.rw + dx), width.value - drag.x));
        rh.value = Math.max(32, Math.min(Math.round(drag.rh + dy), height.value - drag.y));
    } else size.value = Math.max(32, Math.min(Math.round(drag.size + (dx + dy) / 2), width.value - drag.x, height.value - drag.y));
    fit();
};
const keyMove = (event: KeyboardEvent) => {
    if (props.busy || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    const step = event.shiftKey ? 10 : 1;
    x.value += event.key === 'ArrowRight' ? step : event.key === 'ArrowLeft' ? -step : 0;
    y.value += event.key === 'ArrowDown' ? step : event.key === 'ArrowUp' ? -step : 0;
    fit();
};
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @keydown.esc="!busy && emit('cancel')" @click.self="!busy && emit('cancel')">
            <section ref="dialog" tabindex="-1" role="dialog" aria-modal="true" aria-label="Taglia immagine" class="max-h-[95vh] overflow-y-auto rounded-xl border bg-background p-5 text-foreground shadow-xl">
                <h2 class="font-semibold">Taglia immagine</h2>
                <p class="my-3 text-sm">Sposta la selezione e trascina l’angolo per ridimensionarlo. Tutto ciò che resta fuori sarà eliminato.</p>
                <label class="mb-3 flex items-center gap-3 text-sm">Formato<select v-model="shape" :disabled="busy" class="rounded border bg-background px-3 py-2" @change="changeShape"><option value="square">Quadrato</option><option value="rectangle">Rettangolo</option></select></label>
                <div ref="stage" class="relative mx-auto w-[min(75vw,640px)] touch-none overflow-hidden select-none" @pointermove="move" @pointerup="drag = null" @pointercancel="drag = null">
                    <img :src="src" alt="Immagine da ritagliare" class="block h-auto w-full" :draggable="false" @load="loaded" @error="failed = true" />
                    <div v-if="width && size" tabindex="0" role="group" :aria-label="shape === 'square' ? 'Area quadrata: trascina o usa le frecce' : 'Area rettangolare: trascina o usa le frecce'" :style="box" class="absolute cursor-move touch-none border-2 border-white outline-none focus:border-blue-400" style="box-shadow:0 0 0 9999px rgb(0 0 0 / 60%)" @pointerdown="start($event, 'move')" @keydown="keyMove">
                        <span class="pointer-events-none absolute inset-x-0 top-1/3 border-t border-white/40"></span>
                        <span class="pointer-events-none absolute inset-x-0 top-2/3 border-t border-white/40"></span>
                        <span class="pointer-events-none absolute inset-y-0 left-1/3 border-l border-white/40"></span>
                        <span class="pointer-events-none absolute inset-y-0 left-2/3 border-l border-white/40"></span>
                        <button type="button" :aria-label="shape === 'square' ? 'Ridimensiona quadrato' : 'Ridimensiona rettangolo'" class="absolute right-0 bottom-0 h-6 w-6 cursor-nwse-resize border-2 border-white bg-blue-600" @pointerdown.stop="start($event, 'resize')"></button>
                    </div>
                </div>
                <label v-if="width && shape === 'square'" class="mt-4 flex items-center gap-3 text-sm">Dimensione<input v-model.number="size" :disabled="busy" type="range" min="32" :max="maxSize" class="flex-1" @input="fit" /><span>{{ size }} × {{ size }} px</span></label>
                <div v-if="width && shape === 'rectangle'" class="mt-4 space-y-3 text-sm">
                    <label class="flex items-center gap-3">Larghezza<input v-model.number="rw" :disabled="busy" type="range" min="32" :max="width" class="flex-1" @input="fit" /><span>{{ rw }} px</span></label>
                    <label class="flex items-center gap-3">Altezza<input v-model.number="rh" :disabled="busy" type="range" min="32" :max="height" class="flex-1" @input="fit" /><span>{{ rh }} px</span></label>
                </div>
                <p v-if="failed || error" role="alert" class="mt-3 text-sm text-red-600">{{ error || 'Immagine non disponibile.' }}</p>
                <div class="mt-4 flex justify-end gap-3">
                    <button type="button" :disabled="busy" class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50" @click="emit('cancel')">Annulla</button>
                    <button type="button" :disabled="busy || !size || failed" class="rounded-lg bg-foreground px-4 py-2 text-sm text-background disabled:opacity-50" @click="emit('save', { x, y, size: cw, width: cw, height: ch })">{{ busy ? 'Ritaglio in corso…' : 'Applica taglio' }}</button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
