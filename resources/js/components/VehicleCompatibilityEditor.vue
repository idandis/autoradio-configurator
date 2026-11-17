<script setup lang="ts">
import { ref, watch } from 'vue';
import { editVehicles, expandVehicles, vehicleRow, type Vehicle } from '@/lib/vehicle-transform';
const props = defineProps<{ modelValue: Vehicle[]; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [Vehicle[]]; valid: [boolean] }>();
const row = ref<Vehicle>({ brand: '', model: '', year_from: 0, year_to: 0 });
const years = ref<Vehicle[]>([]);
const extra = ref<Vehicle>({ brand: '', model: '', year_from: 0, year_to: 0 });
const adding = ref(false);
const error = ref('');
watch(error, value => emit('valid', !value), { immediate: true });
watch(() => props.modelValue, values => {
    try { row.value = vehicleRow(values, true); years.value = expandVehicles(values, true); error.value = ''; }
    catch (e) { error.value = e instanceof Error ? e.message : 'Compatibilità non valida.'; }
}, { immediate: true, deep: true });
function commit(): Vehicle[] | null {
    try { const entries = editVehicles(row.value, props.modelValue); error.value = ''; emit('update:modelValue', entries); return entries; }
    catch (e) { error.value = e instanceof Error ? e.message : 'Compatibilità non valida.'; return null; }
}
function commitYears() {
    try { emit('update:modelValue', expandVehicles(years.value, true)); error.value = ''; }
    catch (e) { error.value = e instanceof Error ? e.message : 'Controlla gli anni della compatibilità.'; }
}
function start() {
    extra.value = { brand: row.value.brand.split('|')[0].trim(), model: '', year_from: row.value.year_from, year_to: row.value.year_to };
    adding.value = true; error.value = '';
}
function add() {
    try {
        const entries = editVehicles(row.value, props.modelValue);
        const added = expandVehicles([extra.value], true);
        if (!added.length) throw new Error('Inserisci la compatibilità da aggiungere.');
        emit('update:modelValue', expandVehicles([...entries, ...added], true)); adding.value = false; error.value = '';
    } catch (e) { error.value = e instanceof Error ? e.message : 'Compatibilità non valida.'; }
}
</script>

<template>
    <div class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-[1fr_1fr_110px_110px]">
            <label class="text-sm">Marca<input v-model="row.brand" :disabled="disabled" maxlength="255" class="mt-1 w-full rounded border bg-background px-3 py-2" @change="commit" /></label>
            <label class="text-sm">Modello<input v-model="row.model" :disabled="disabled" maxlength="4000" class="mt-1 w-full rounded border bg-background px-3 py-2" @change="commit" /></label>
            <label class="text-sm">Dal<input v-if="years.length === 1" v-model.number="years[0].year_from" :disabled="disabled" type="number" min="1" max="65535" class="mt-1 w-full rounded border bg-background px-3 py-2" @change="commitYears" /><input v-else :value="row.year_from" readonly type="number" title="Anno minimo delle compatibilità" class="mt-1 w-full rounded border bg-background px-3 py-2 text-muted-foreground" /></label>
            <label class="text-sm">Al<input v-if="years.length === 1" v-model.number="years[0].year_to" :disabled="disabled" type="number" :min="years[0].year_from || 1" max="65535" class="mt-1 w-full rounded border bg-background px-3 py-2" @change="commitYears" /><input v-else :value="row.year_to" readonly type="number" title="Anno massimo delle compatibilità" class="mt-1 w-full rounded border bg-background px-3 py-2 text-muted-foreground" /></label>
        </div>
        <div v-if="years.length > 1" class="space-y-2 rounded-lg border p-3">
            <p class="text-sm font-medium">Anni per modello</p>
            <div v-for="(vehicle, index) in years" :key="index" class="grid items-center gap-2 sm:grid-cols-[1fr_110px_110px]">
                <span class="text-sm">{{ vehicle.brand }} {{ vehicle.model }}</span>
                <label class="text-xs">Dal<input v-model.number="vehicle.year_from" :disabled="disabled" type="number" min="1" max="65535" class="mt-1 w-full rounded border bg-background px-2 py-2" @change="commitYears" /></label>
                <label class="text-xs">Al<input v-model.number="vehicle.year_to" :disabled="disabled" type="number" :min="vehicle.year_from || 1" max="65535" class="mt-1 w-full rounded border bg-background px-2 py-2" @change="commitYears" /></label>
            </div>
        </div>
        <button type="button" :disabled="disabled" class="rounded border px-3 py-2 text-sm" @click="start">Aggiungi compatibilità diversa</button>
        <section v-if="adding" class="space-y-3 rounded-lg border border-sky-400/40 bg-sky-400/5 p-3">
            <h4 class="text-sm font-medium">Compatibilità da unire con TRASFORMA</h4>
            <div class="grid gap-3 sm:grid-cols-[1fr_1fr_110px_110px]">
                <label class="text-sm">Marca<input v-model="extra.brand" :disabled="disabled" maxlength="255" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                <label class="text-sm">Modello<input v-model="extra.model" :disabled="disabled" maxlength="4000" class="mt-1 w-full rounded border bg-background px-3 py-2" @keydown.enter.prevent="add" /></label>
                <label class="text-sm">Dal<input v-model.number="extra.year_from" :disabled="disabled" type="number" min="1" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                <label class="text-sm">Al<input v-model.number="extra.year_to" :disabled="disabled" type="number" :min="extra.year_from || 1" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
            </div>
            <div class="flex gap-2"><button type="button" :disabled="disabled" class="rounded bg-sky-600 px-3 py-2 text-sm text-white" @click="add">Aggiungi e unisci</button><button type="button" :disabled="disabled" class="rounded border px-3 py-2 text-sm" @click="adding = false; error = ''">Annulla</button></div>
        </section>
        <p v-if="error" role="alert" class="text-sm text-red-500">{{ error }}</p>
        <p class="text-xs text-muted-foreground">TRASFORMA: una sola riga; marche MAIUSCOLE, modelli separati da |. Con più marche: FIAT | CITROEN e 1:Ducato | 2:Jumper. Con più modelli modifica gli anni nelle righe dedicate; Dal/Al generali seguono il minimo e il massimo.</p>
    </div>
</template>
