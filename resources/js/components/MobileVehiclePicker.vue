<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

type VehicleChoice = { brand: string | null; model: string | null; year: number | null };
const props = defineProps<{
    value: VehicleChoice;
    entries: { brand: string; model: string; yearFrom: number | null; yearTo: number | null }[];
    displayModel: (model: string | null) => string;
    missingLabels: string[];
}>();
const emit = defineEmits<{ complete: [value: VehicleChoice]; missing: [value: VehicleChoice] }>();
const dialog = ref<HTMLDialogElement>();
const heading = ref<HTMLElement>();
const list = ref<HTMLElement>();
const opened = ref(false);
const step = ref(0);
const query = ref('');
const draft = ref<VehicleChoice>({ brand: null, model: null, year: null });
const active = ref(0);
const titles = ['Selecciona la marca', 'Selecciona el modelo', 'Selecciona el año'];
const historyKey = 'autoradioVehiclePicker';
let session = '';
let depth = 0;
let closing = false;
let afterClose: (() => void) | undefined;
let opener: HTMLElement | null = null;
let previousOverflow = '';
const selected = computed(() => [draft.value.brand, draft.value.model, draft.value.year][step.value]);
const options = computed(() => {
    const entries = props.entries.filter(entry => step.value === 0 || entry.brand === draft.value.brand);
    let values: (string | number)[];
    if (step.value === 0) values = [...new Set(entries.map(entry => entry.brand))].sort();
    else if (step.value === 1) values = [...new Set(entries.map(entry => entry.model))].sort((a, b) => a.localeCompare(b, 'es', { numeric: true }));
    else {
        const years = new Set<number>();
        entries.filter(entry => entry.model === draft.value.model).forEach(entry => {
            if (entry.yearFrom === null || entry.yearTo === null) return;
            for (let year = entry.yearFrom; year <= entry.yearTo; year++) years.add(year);
        });
        values = [...years].sort((a, b) => a - b);
    }
    return values.map(value => ({ value, label: step.value === 1 ? props.displayModel(String(value)) : String(value) }))
        .filter(option => option.label.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase()));
});
async function focusStep() {
    query.value = '';
    await nextTick();
    active.value = Math.max(0, options.value.findIndex(option => option.value === selected.value));
    heading.value?.focus();
    await nextTick();
    list.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
}
function pushStep() {
    const state = { ...window.history.state, [historyKey]: { session, step: step.value } };
    if (depth) window.history.replaceState(state, '');
    else window.history.pushState(state, '');
    depth = 1;
}
async function open() {
    if (opened.value || closing) return;
    opener = document.activeElement as HTMLElement;
    draft.value = { ...props.value };
    step.value = 0;
    session = String(Date.now());
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    opened.value = true;
    pushStep();
    await nextTick();
    dialog.value?.showModal();
    await focusStep();
}
function finishClose() {
    dialog.value?.close();
    opened.value = false;
    closing = false;
    depth = 0;
    document.body.style.overflow = previousOverflow;
    opener?.focus();
    const callback = afterClose;
    afterClose = undefined;
    callback?.();
}
function close(callback?: () => void) {
    if (closing) return;
    afterClose = callback;
    closing = true;
    if (depth) window.history.go(-depth);
    else finishClose();
}
function finishWithResult(callback: () => void) {
    if (closing) return;

    // Leaving the wizard for a result must not traverse browser history: doing
    // so can activate the previous Inertia page before the next UI is shown.
    const restoredState = { ...window.history.state };
    delete restoredState[historyKey];
    window.history.replaceState(restoredState, '', window.location.href);
    afterClose = callback;
    closing = true;
    finishClose();
}
function complete(result: VehicleChoice) {
    finishWithResult(() => emit('complete', result));
}
function back() {
    if (closing || !opened.value) return;
    if (step.value === 0) return close();
    step.value--;
    pushStep();
    void focusStep();
}
function onPop(event: PopStateEvent) {
    if (!opened.value) return;
    // Consume selector navigation before the page router handles popstate.
    event.stopImmediatePropagation();
    if (closing) return finishClose();
    depth = 0;
    if (step.value === 0) return finishClose();
    step.value--;
    pushStep();
    void focusStep();
}
function choose(value: string | number) {
    if (closing) return;
    if (step.value === 0 && draft.value.brand !== value) draft.value = { brand: String(value), model: null, year: null };
    if (step.value === 1 && draft.value.model !== value) draft.value = { ...draft.value, model: String(value), year: null };
    if (step.value === 2) {
        draft.value.year = Number(value);
        const result = { ...draft.value };
        complete(result);
        return;
    }
    step.value++;
    pushStep();
    void focusStep();
}
function keyboard(event: KeyboardEvent) {
    const count = options.value.length;
    if (!count) return;
    if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        active.value = event.key === 'Home' ? 0 : event.key === 'End' ? count - 1 : (active.value + (event.key === 'ArrowDown' ? 1 : -1) + count) % count;
        list.value?.querySelectorAll<HTMLElement>('[role="option"]')[active.value]?.scrollIntoView({ block: 'nearest' });
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (options.value[active.value]) choose(options.value[active.value].value);
    }
}
function missing() {
    const result = { ...draft.value };
    if (step.value === 0) result.brand = result.model = null;
    if (step.value <= 1) result.model = null;
    result.year = null;
    finishWithResult(() => emit('missing', result));
}
function resize() { if (opened.value && window.innerWidth >= 1024) close(); }
onMounted(() => {
    window.addEventListener('popstate', onPop, true);
    window.addEventListener('resize', resize);
});
onBeforeUnmount(() => {
    window.removeEventListener('popstate', onPop, true);
    window.removeEventListener('resize', resize);
    if (opened.value) {
        document.body.style.overflow = previousOverflow;
        dialog.value?.close();
        if (depth) window.history.go(-depth);
    }
});
defineExpose({ open });
</script>

<template>
    <Teleport to="body">
        <dialog ref="dialog" class="vehicle-picker" aria-modal="true" aria-labelledby="vehicle-picker-title" @cancel.prevent="close()">
            <div v-if="opened" class="picker-layout">
                <header>
                    <p class="mb-2 text-sm text-amber-400">{{ step + 1 }} / 3</p>
                    <h2 id="vehicle-picker-title" ref="heading" tabindex="-1" class="text-2xl font-bold outline-none">{{ titles[step] }}</h2>
                    <input v-if="step < 2" v-model="query" type="search" :aria-label="step === 0 ? 'Buscar marca' : 'Buscar modelo'" :placeholder="step === 0 ? 'Buscar marca…' : 'Buscar modelo…'" class="mt-5 w-full rounded-lg border border-amber-400 bg-neutral-900 p-3 text-white" @input="active = 0" @keydown.down.prevent="list?.focus()" />
                </header>
                <div ref="list" role="listbox" tabindex="0" aria-labelledby="vehicle-picker-title" :aria-activedescendant="options.length ? `vehicle-option-${active}` : undefined" class="picker-list" @keydown="keyboard">
                    <div v-for="(option, index) in options" :id="`vehicle-option-${index}`" :key="option.value" role="option" :aria-selected="selected === option.value" class="picker-option" :class="{ chosen: selected === option.value, active: active === index }" @click="choose(option.value)">
                        <span>{{ option.label }}</span><span v-if="selected === option.value" aria-hidden="true">✓</span>
                    </div>
                    <p v-if="!options.length" role="status" class="p-4 text-neutral-400">No se encontraron resultados</p>
                </div>
                <footer class="grid gap-3">
                    <button type="button" class="rounded-lg border border-amber-400 p-3 text-amber-400" @click="missing">{{ missingLabels[step] }}</button>
                    <button type="button" class="flex min-h-12 items-center justify-center gap-3 rounded-xl bg-[#334fb4] p-3 font-semibold text-white hover:bg-[#405dc7]" @click="back"><span aria-hidden="true">←</span> Atrás</button>
                </footer>
            </div>
        </dialog>
    </Teleport>
</template>

<style scoped>
.vehicle-picker { position: fixed; inset: 0; margin: 0; width: 100%; max-width: none; height: 100dvh; max-height: none; padding: 0; border: 1px solid #fbbf24; background: #080808; color: white; }
.vehicle-picker::backdrop { background: #080808; }
.picker-layout { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; gap: 20px; height: 100%; padding: max(20px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(20px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left)); }
.picker-list { overflow-y: auto; overscroll-behavior: contain; border-radius: 8px; }
.picker-option { display: flex; justify-content: space-between; padding: 16px; margin-bottom: 8px; border: 1px solid #fbbf24; border-radius: 8px; cursor: pointer; }
.picker-option.chosen { background: #fbbf24; color: #000; font-weight: 700; }
.picker-list:focus-visible { outline: 2px solid white; outline-offset: 3px; }
.picker-list:focus .picker-option.active { box-shadow: inset 0 0 0 3px white; }
</style>
