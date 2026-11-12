<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { contrastMask } from '@/lib/product-cutout';
const props = defineProps<{ src: string; busy: boolean; error?: string }>();
const emit = defineEmits<{ save: [points: number[][]]; automatic: [mask: File]; cancel: [] }>();
const image = ref<HTMLImageElement | null>(null);
const width = ref(0), height = ref(0);
const points = ref<number[][]>([]);
const mode = ref<'manual' | 'auto'>('manual');
const tolerance = ref(40);
const removeHoles = ref(true);
const preview = ref<HTMLCanvasElement | null>(null);
const autoError = ref('');
let maskCanvas: HTMLCanvasElement | null = null;
const prepare = () => {
    if (mode.value !== 'auto' || !image.value || !width.value || !preview.value) return;
    autoError.value = ''; maskCanvas = null;
    try {
        if (width.value * height.value > 16000000) throw new Error('Foto troppo grande per lo scontorno automatico.');
        const c = preview.value; c.width = width.value; c.height = height.value;
        const ctx = c.getContext('2d')!; ctx.drawImage(image.value, 0, 0);
        const pixels = ctx.getImageData(0, 0, c.width, c.height);
        const mask = contrastMask(pixels.data, c.width, c.height, tolerance.value, removeHoles.value);
        maskCanvas = document.createElement('canvas'); maskCanvas.width = c.width; maskCanvas.height = c.height;
        const mc = maskCanvas.getContext('2d')!, mp = mc.createImageData(c.width, c.height);
        for (let i = 0; i < mask.length; i++) {
            mp.data[i * 4] = mp.data[i * 4 + 1] = mp.data[i * 4 + 2] = mask[i] ? 255 : 0;
            mp.data[i * 4 + 3] = 255;
            if (!mask[i]) pixels.data[i * 4 + 3] = 0;
        }
        mc.putImageData(mp, 0, 0); ctx.putImageData(pixels, 0, 0);
    } catch (e) { maskCanvas = null; autoError.value = e instanceof Error ? e.message : 'Scontorno non disponibile.'; }
};
watch([mode, tolerance, removeHoles], prepare, { flush: 'post' });
const automatic = () => {
    if (!maskCanvas || props.busy) return;
    maskCanvas.toBlob(blob => {
        if (!blob || blob.size > 8 * 1024 * 1024) { autoError.value = 'Maschera troppo grande.'; return; }
        emit('automatic', new File([blob], 'contorno.png', { type: 'image/png' }));
    }, 'image/png');
};
const failed = ref(false);
const outline = computed(() => points.value.map(p => p.join(',')).join(' '));
const src = computed(() => { const u = new URL(props.src, window.location.href); return u.pathname + u.search; });
const loaded = () => { width.value = image.value!.naturalWidth; height.value = image.value!.naturalHeight; prepare(); };
const add = (e: MouseEvent) => {
    if (mode.value !== 'manual' || props.busy || !width.value || points.value.length >= 100) return;
    const r = image.value!.getBoundingClientRect();
    points.value.push([Math.max(0, Math.min(width.value - 1, Math.round((e.clientX - r.left) * width.value / r.width))),
        Math.max(0, Math.min(height.value - 1, Math.round((e.clientY - r.top) * height.value / r.height)))]);
};
</script>
<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @keydown.esc="!busy && emit('cancel')">
            <section role="dialog" aria-modal="true" aria-label="Contorno pantalla" class="max-h-[95vh] w-[min(95vw,850px)] overflow-y-auto rounded-xl border bg-background p-5 text-foreground">
                <h2 class="font-semibold">Seleziona il contorno della pantalla</h2>
                <label class="my-3 flex items-center gap-3 text-sm">Contorno<select v-model="mode" :disabled="busy" class="rounded border bg-background px-3 py-2"><option value="manual">Con punti</option><option value="auto">Automatico per contrasto</option></select></label>
                <p v-if="mode === 'auto'" class="my-3 text-sm">Per foto con sfondo chiaro uniforme: segue il contrasto e conserva la figura principale. Verifica l’anteprima; aumenta la sensibilità per togliere più sfondo, riducila per conservare dettagli chiari.</p>
                <label v-if="mode === 'auto'" class="my-3 flex items-center gap-3 text-sm">Sensibilità<input v-model.number="tolerance" :disabled="busy" type="range" min="10" max="100" step="1" />{{ tolerance }}</label>
                <label v-if="mode === 'auto'" class="my-3 flex items-center gap-2 text-sm"><input v-model="removeHoles" :disabled="busy" type="checkbox" />Rendi trasparenti i vuoti bianchi interni</label>
                <p v-if="mode === 'manual'" class="my-3 text-sm">Clicca lungo il bordo esterno, in ordine. Includi solo la pantalla e la sua cornice. Il contorno si chiude automaticamente. L’AI genera lo sfondo con piattaforma di pietra, dune canarie, sole pieno e cielo azzurro. Il prodotto originale viene appoggiato sul piano con ombra di contatto.</p>
                <div :class="mode === 'auto' ? 'hidden' : ''" class="relative mx-auto w-fit max-w-full cursor-crosshair" @click="add">
                    <img ref="image" :src="src" alt="Pantalla da scontornare" class="block max-h-[60vh] max-w-full" :draggable="false" @load="loaded" @error="failed = true" />
                    <svg v-if="width" :viewBox="`0 0 ${width} ${height}`" class="pointer-events-none absolute inset-0 h-full w-full">
                        <polygon v-if="points.length > 2" :points="outline" fill="rgb(255 220 0 / 15%)" stroke="#ffdd00" :stroke-width="width / 300" />
                        <polyline v-else :points="outline" fill="none" stroke="#ffdd00" :stroke-width="width / 300" />
                        <circle v-for="(p, i) in points" :key="i" :cx="p[0]" :cy="p[1]" :r="width / 150" fill="#ffdd00" />
                    </svg>
                </div>
                <canvas v-show="mode === 'auto'" ref="preview" aria-label="Anteprima contorno automatico" class="mx-auto block max-h-[60vh] max-w-full" style="background:repeating-conic-gradient(#bbb 0% 25%, #eee 0% 50%) 0 / 20px 20px" />
                <p v-if="autoError" role="alert" class="mt-3 text-sm text-red-600">{{ autoError }}</p>
                <p v-if="error || failed" role="alert" class="mt-3 text-sm text-red-600">{{ error || 'Foto non disponibile.' }}</p>
                <div class="mt-4 flex flex-wrap justify-end gap-3">
                    <button v-if="mode === 'manual'" type="button" class="rounded border px-3 py-2" :disabled="busy || !points.length" @click="points.pop()">Annulla punto</button>
                    <button type="button" class="rounded border px-3 py-2" :disabled="busy" @click="points = []; prepare()">Ricomincia</button>
                    <button type="button" class="rounded border px-3 py-2" :disabled="busy" @click="emit('cancel')">Chiudi</button>
                    <button type="button" class="rounded bg-foreground px-3 py-2 text-background disabled:opacity-50" :disabled="busy || failed || (mode === 'manual' ? points.length < 3 : !width || !!autoError)" @click="mode === 'manual' ? emit('save', points) : automatic()">{{ busy ? 'Generazione sfondo…' : 'Genera solo sfondo' }}</button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
