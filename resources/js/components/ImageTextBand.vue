<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
const props = defineProps<{ src: string; busy: boolean; error?: string }>();
const emit = defineEmits<{ save: [file: File]; cancel: [] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const text = ref('');
const font = ref(40);
const position = ref(100);
const ready = ref(false);
const error = ref('');
const moving = ref(false);
let source: HTMLImageElement;
let bandY = 0, bandHeight = 0, offset = 0;
const draw = () => {
    const c = canvas.value;
    if (!ready.value || !c) return;
    const ctx = c.getContext('2d')!;
    ctx.clearRect(0, 0, c.width, c.height);
    ctx.drawImage(source, 0, 0);
    const pad = Math.max(8, c.width * 0.025);
    let fs = font.value;
    const wrap = () => {
        ctx.font = `600 ${fs}px Arial, sans-serif`;
        const lines: string[] = [];
        for (const paragraph of (text.value || 'Inserisci testo').split('\n')) {
            let line = '';
            for (const char of paragraph) {
                if (line && ctx.measureText(line + char).width > c.width - pad * 2) { lines.push(line); line = ''; }
                line += char;
            }
            lines.push(line);
        }
        return lines;
    };
    let lines = wrap();
    while (lines.length * fs * 1.25 + pad * 2 > c.height && fs > 6) { fs--; lines = wrap(); }
    bandHeight = Math.min(c.height, Math.ceil(lines.length * fs * 1.25 + pad * 2));
    bandY = Math.round((c.height - bandHeight) * position.value / 100);
    ctx.fillStyle = '#000000'; ctx.fillRect(0, bandY, c.width, bandHeight);
    ctx.fillStyle = '#ffffff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    lines.forEach((line, i) => ctx.fillText(line, c.width / 2, bandY + bandHeight / 2 + (i - (lines.length - 1) / 2) * fs * 1.25));
};
watch([text, font, position], draw);
onMounted(() => {
    source = new Image();
    source.onload = () => {
        if (!canvas.value) return;
        canvas.value.width = source.naturalWidth; canvas.value.height = source.naturalHeight;
        font.value = Math.max(12, Math.min(100, Math.round(source.naturalWidth * 0.04)));
        ready.value = true; draw();
    };
    source.onerror = () => { error.value = 'Immagine non disponibile.'; };
    const u = new URL(props.src, window.location.href);
    source.src = ['http:', 'https:'].includes(u.protocol) ? u.pathname + u.search : props.src;
});
const y = (e: PointerEvent) => { const c = canvas.value!, r = c.getBoundingClientRect(); return (e.clientY - r.top) * c.height / r.height; };
const start = (e: PointerEvent) => {
    if (props.busy || !ready.value || e.button !== 0 || y(e) < bandY || y(e) > bandY + bandHeight) return;
    offset = y(e) - bandY; moving.value = true; canvas.value!.setPointerCapture(e.pointerId);
};
const move = (e: PointerEvent) => {
    if (!moving.value || props.busy) return;
    const range = canvas.value!.height - bandHeight;
    position.value = range > 0 ? Math.max(0, Math.min(100, (y(e) - offset) / range * 100)) : 0;
};
const save = () => {
    if (!ready.value || props.busy || !text.value.trim()) return;
    error.value = '';
    try {
        const qualities = [0.92, 0.85, 0.75, 0.65, 0.5, 0.35];
        const encode = (index: number) => canvas.value?.toBlob(blob => {
            if (!blob || blob.type !== 'image/webp') { error.value = 'Salvataggio WebP non disponibile nel browser.'; return; }
            if (blob.size > 1_900_000 && index < qualities.length - 1) { encode(index + 1); return; }
            if (blob.size > 1_900_000) { error.value = 'Immagine troppo grande per il caricamento.'; return; }
            emit('save', new File([blob], 'testo.webp', { type: 'image/webp' }));
        }, 'image/webp', qualities[index]);
        encode(0);
    } catch { error.value = 'Salvataggio non riuscito. Riapri la foto e riprova.'; }
};
</script>
<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @keydown.esc="!busy && emit('cancel')">
            <section role="dialog" aria-modal="true" aria-label="Testo sulla foto" class="max-h-[95vh] w-[min(95vw,900px)] overflow-y-auto rounded-xl border bg-background p-5 text-foreground shadow-xl">
                <h2 class="font-semibold">TESTO</h2>
                <label class="mt-3 block text-sm">Testo bianco<textarea v-model="text" maxlength="500" rows="2" :disabled="busy" class="mt-1 w-full rounded border bg-background p-2" placeholder="Scrivi il testo della banda" /></label>
                <div class="my-3 flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2">Dimensione testo<input v-model.number="font" type="range" min="12" max="100" :disabled="busy" />{{ font }} px</label>
                    <label class="flex items-center gap-2">Posizione verticale<input v-model.number="position" type="range" min="0" max="100" step="0.1" :disabled="busy" /></label>
                </div>
                <canvas ref="canvas" class="mx-auto block h-auto max-h-[60vh] max-w-full touch-none cursor-ns-resize" @pointerdown="start" @pointermove="move" @pointerup="moving = false" @pointercancel="moving = false" />
                <p class="mt-3 text-xs text-muted-foreground">Trascina la banda nera in alto o in basso, oppure usa Posizione verticale. La foto mantiene dimensioni e proporzioni.</p>
                <p v-if="error || props.error" role="alert" class="mt-3 text-sm text-red-600">{{ error || props.error }}</p>
                <div class="mt-4 flex justify-end gap-3"><button type="button" class="rounded border px-4 py-2" :disabled="busy" @click="emit('cancel')">Annulla</button><button type="button" class="rounded bg-foreground px-4 py-2 text-background disabled:opacity-50" :disabled="busy || !ready || !text.trim() || moving" @click="save">{{ busy ? 'Salvataggio…' : 'Salva foto' }}</button></div>
            </section>
        </div>
    </Teleport>
</template>
