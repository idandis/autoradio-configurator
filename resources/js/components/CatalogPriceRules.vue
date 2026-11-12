<script setup lang="ts">
import { onMounted, ref } from 'vue';
type Range = { min: string; max: string | null; percentage: string; fixed: string };
type Row = Range & { usePercentage: boolean; useFixed: boolean };
type Quote = { supplier_cost: string; markup_fixed: string; markup_percentage: string; customs_estimate: string; selling_price: string };
const rows = ref<Row[]>([]);
const busy = ref(false);
const error = ref('');
const message = ref('');
const cost = ref('200.00');
const quote = ref<Quote | null>(null);
const version = ref('');
const vat = ref('21.00');
const min = (i: number) => i === 0 ? '0.00' : rows.value[i - 1].max ?? '';
const payload = () => rows.value.map((r, i) => ({ min: min(i), max: i === rows.value.length - 1 ? null : r.max,
    percentage: r.usePercentage ? r.percentage : '0.00', fixed: r.useFixed ? r.fixed : '0.00' }));
const assign = (data: { ranges: Range[]; version: string; vat_rate?: string }) => {
    rows.value = data.ranges.map(r => ({ ...r, usePercentage: Number(r.percentage) > 0, useFixed: Number(r.fixed) > 0 }));
    version.value = data.version; vat.value = data.vat_rate ?? '21.00';
};
async function request(path: string, body?: object) {
    const token = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
    const r = await fetch(path, { method: body ? 'POST' : 'GET', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(token || '') }, ...(body ? { body: JSON.stringify(body) } : {}) });
    if (r.redirected || r.status === 401) throw new Error('Accedi nuovamente alla sessione locale.');
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Richiesta non completata.');
    return data;
}
async function load() {
    busy.value = true; error.value = '';
    try { assign(await request('/imported-products/price-rules')); quote.value = await request('/imported-products/price-rules/preview', { cost: cost.value, ranges: payload(), vat_rate: vat.value }); }
    catch (e) { error.value = e instanceof Error ? e.message : 'Regola non leggibile.'; }
    finally { busy.value = false; }
}
async function save() {
    busy.value = true; error.value = ''; message.value = '';
    try { assign(await request('/imported-products/price-rules', { ranges: payload(), vat_rate: vat.value })); message.value = 'Regola salvata: sarà usata da tutti i prossimi calcoli delle radio. Nessun prezzo già salvato viene modificato da questo pulsante.'; }
    catch (e) { error.value = e instanceof Error ? e.message : 'Salvataggio non riuscito.'; }
    finally { busy.value = false; }
}
async function preview() {
    busy.value = true; error.value = ''; quote.value = null;
    try { quote.value = await request('/imported-products/price-rules/preview', { cost: cost.value, ranges: payload(), vat_rate: vat.value }); }
    catch (e) { error.value = e instanceof Error ? e.message : 'Anteprima non riuscita.'; }
    finally { busy.value = false; }
}
function add() {
    const last = rows.value.at(-1); if (!last || rows.value.length >= 50) return;
    last.max = (Number(min(rows.value.length - 1)) + 100).toFixed(2);
    rows.value.push({ ...last, min: last.max, max: null }); quote.value = null; message.value = '';
}
function remove(i: number) {
    if (rows.value.length < 2) return;
    rows.value.splice(i, 1); rows.value[rows.value.length - 1].max = null; quote.value = null; message.value = '';
}
onMounted(load);
</script>

<template>
    <details class="my-4 rounded-xl border border-sky-400/30 bg-sky-400/5 p-4">
        <summary class="cursor-pointer font-semibold">Regola prezzo · solo autoradio</summary>
        <p class="my-2 text-sm text-muted-foreground">Costo fornitore + percentuale sul costo + importo fisso + stima doganale. Arrotondamento finale in eccesso, senza decimali. Puoi attivare percentuale, fisso o entrambi per ciascuna fascia.</p>
        <p class="mb-3 text-xs text-muted-foreground">Da incluso, fino a escluso. Il limite iniziale segue la fascia precedente; l’ultima non ha limite. Le modifiche diventano operative solo con «Salva regola».</p>
        <form v-if="rows.length" @submit.prevent="save">
            <label class="mb-3 flex items-center gap-2 text-sm">IVA %<input v-model="vat" type="number" min="0" max="100" step="0.01" required :disabled="busy" class="w-24 rounded border bg-background p-2" @input="quote = null; message = ''" /></label>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-2">Da €</th><th class="p-2">Fino a €</th><th class="p-2">Gruppo percentuale</th><th class="p-2">Gruppo prezzo fisso</th><th class="p-2">Azioni</th></tr></thead>
                <tbody><tr v-for="(r, i) in rows" :key="i" class="border-b">
                    <td class="p-2 tabular-nums">{{ min(i) }}</td>
                    <td class="p-2"><input v-if="i < rows.length - 1" v-model="r.max" type="number" step="0.01" min="0.01" max="1000000" required :disabled="busy" :aria-label="`Limite superiore fascia ${i + 1}`" class="w-28 rounded border bg-background p-2" @input="quote = null; message = ''" /><span v-else>Senza limite</span></td>
                    <td class="p-2"><label class="flex items-center gap-2"><input v-model="r.usePercentage" type="checkbox" :disabled="busy" :aria-label="`Attiva percentuale fascia ${i + 1}`" @change="quote = null; message = ''" /><input v-model="r.percentage" type="number" min="0" max="1000" step="0.01" :disabled="busy || !r.usePercentage" :aria-label="`Percentuale fascia ${i + 1}`" class="w-24 rounded border bg-background p-2 disabled:opacity-40" @input="quote = null; message = ''" />%</label></td>
                    <td class="p-2"><label class="flex items-center gap-2"><input v-model="r.useFixed" type="checkbox" :disabled="busy" :aria-label="`Attiva fisso fascia ${i + 1}`" @change="quote = null; message = ''" /><input v-model="r.fixed" type="number" min="0" max="999999" step="0.01" :disabled="busy || !r.useFixed" :aria-label="`Importo fisso fascia ${i + 1}`" class="w-24 rounded border bg-background p-2 disabled:opacity-40" @input="quote = null; message = ''" />€</label></td>
                    <td class="p-2"><button type="button" :disabled="busy || rows.length < 2" class="rounded border border-red-500/50 px-2 py-1 text-xs text-red-500 disabled:opacity-40" @click="remove(i)">Elimina fascia</button></td>
                </tr></tbody>
            </table></div>
            <div class="my-3 flex flex-wrap gap-2"><button type="button" :disabled="busy || rows.length >= 50" class="rounded border px-3 py-2 text-sm disabled:opacity-40" @click="add">+ Aggiungi fascia</button><button :disabled="busy" class="rounded bg-sky-500 px-3 py-2 text-sm font-medium text-white disabled:opacity-40">Salva regola</button></div>
        </form>
        <details class="my-3 text-sm"><summary class="cursor-pointer">Stima doganale · IVA {{ vat }}%</summary><p class="mt-2 text-muted-foreground">Sotto 150 €: nessuna stima doganale aggiunta. Da 150 €: base = costo + 18,32 €; dazio 14% sulla base; IVA {{ vat }}% su base + dazio; spese (21,81 € + 5,18 €) maggiorate del {{ vat }}%. Questi parametri restano separati dalle fasce di ricarico.</p></details>
        <div v-if="rows.length" class="flex flex-wrap items-center gap-2 text-sm"><label>Costo di prova € <input v-model="cost" type="number" min="0.01" max="999999" step="0.01" :disabled="busy" class="w-28 rounded border bg-background p-2" @input="quote = null" /></label><button type="button" :disabled="busy" class="rounded border px-3 py-2" @click="preview">Prova regola senza salvare</button></div>
        <p v-if="quote" class="mt-3 text-sm">Costo {{ quote.supplier_cost }} € + percentuale {{ quote.markup_percentage }} € + fisso {{ quote.markup_fixed }} € + dogana {{ quote.customs_estimate }} € → <strong>{{ quote.selling_price }} €</strong></p>
        <p v-if="message" class="mt-3 text-sm text-emerald-500">{{ message }}</p><p v-if="error" role="alert" class="mt-3 text-sm text-red-500">{{ error }}</p>
        <p v-if="version" class="mt-2 text-xs text-muted-foreground">Regola attiva: {{ version }}</p>
    </details>
</template>
