<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { PackageCheck, Plus, Search, Trash2 } from '@lucide/vue';

type Stock = { id: number; handle: string; title: string; image: string | null; quantity: number; discountPercent: number; available: boolean };
type Product = { handle: string; title: string; image: string | null };
const stock = ref<Stock[]>([]);
const products = ref<Product[]>([]);
const query = ref('');
const selected = ref('');
const quantity = ref(1);
const discount = ref(0);
const ready = ref(false);
const loading = ref(true);
const busy = ref(false);
const open = ref(false);
const error = ref('');
const saved = ref('');
const total = computed(() => stock.value.filter((p) => p.available && p.quantity > 0).length);
let timer: ReturnType<typeof setTimeout>;
let seq = 0;
const load = async () => {
    const n = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get('/dashboard/in-stock', { params: { search: query.value } });
        if (n !== seq) return;
        if (typeof data?.ready !== 'boolean' || !Array.isArray(data.stock) || !Array.isArray(data.products)) {
            throw new Error('Risposta non valida. Ricarica la Dashboard e accedi nuovamente se richiesto.');
        }
        stock.value = data.stock;
        products.value = data.products;
        ready.value = data.ready;
    } catch (e: any) {
        if (n === seq) error.value = e.response ? 'Impossibile caricare i prodotti. Riprova.' : e.message;
    } finally {
        if (n === seq) loading.value = false;
    }
};
const save = async (action: () => Promise<unknown>, message: string) => {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    saved.value = '';
    try {
        await action();
        saved.value = message;
        selected.value = '';
        await load();
    } catch (e: any) {
        error.value = Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || e.response?.data?.message || e.message || 'Salvataggio non riuscito. Riprova.';
    } finally {
        busy.value = false;
    }
};
const add = () => save(async () => {
    const { data } = await axios.post('/dashboard/in-stock', { product_handle: selected.value, quantity: quantity.value, discount_percent: discount.value });
    if (data?.saved !== true) throw new Error('Salvataggio non confermato. Ricarica la Dashboard e riprova.');
}, 'Prodotto aggiunto alla selezione della home.');
const update = (p: Stock, event: Event) => {
    const n = Number((event.target as HTMLInputElement).value);
    if (n === p.quantity) return;
    void save(() => axios.patch(`/dashboard/in-stock/${p.id}`, { quantity: n }), 'Disponibilità aggiornata.');
};
const updateDiscount = (p: Stock, event: Event) => {
    const n = Number((event.target as HTMLInputElement).value);
    if (n === p.discountPercent) return;
    void save(() => axios.patch(`/dashboard/in-stock/${p.id}`, { discount_percent: n }), 'Sconto aggiornato.');
};
watch(query, () => { selected.value = ''; clearTimeout(timer); timer = setTimeout(load, 250); });
onMounted(load);
onBeforeUnmount(() => { clearTimeout(timer); seq++; });
</script>

<template>
    <section class="rounded-xl border border-emerald-500/30 bg-card p-5 sm:p-6" aria-labelledby="stock-manager-title">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 id="stock-manager-title" class="flex items-center gap-2 text-lg font-semibold"><PackageCheck class="text-emerald-400" :size="22" />In offerta</h2><p class="mt-1 text-sm text-muted-foreground">{{ total }} prodotti nella home · Seleziona i prodotti del catalogo disponibili in pronta consegna.</p></div><div class="flex items-center gap-3"><a href="/home-beta" class="text-sm font-medium text-emerald-400 hover:underline">Anteprima home beta ↗</a><button type="button" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50" :disabled="loading || busy" :aria-expanded="open" aria-controls="stock-product-picker" @click="open = !open"><Plus :size="17" />Aggiungi prodotto</button></div></div>
        <p v-if="error" role="alert" class="mt-4 text-sm text-red-400">{{ error }}</p><p v-if="saved" role="status" class="mt-4 text-sm text-emerald-400">{{ saved }}</p>
        <p v-if="!ready && !loading" class="mt-4 text-sm text-amber-400">La selezione In offerta verrà attivata al primo salvataggio.</p>
        <div v-if="open" id="stock-product-picker" class="mt-5 rounded-lg border border-sidebar-border/70 p-4">
            <label for="stock-search" class="text-sm font-medium">Cerca nel catalogo</label><div class="relative mt-2"><Search class="absolute left-3 top-3 text-muted-foreground" :size="17" /><input id="stock-search" v-model="query" type="search" class="w-full rounded-lg border border-sidebar-border bg-background py-2.5 pl-10 pr-3 text-sm" placeholder="Titolo, SKU o handle" /></div>
            <p v-if="loading" role="status" class="mt-3 text-sm text-muted-foreground">Caricamento…</p>
            <div v-else class="mt-3 max-h-64 overflow-y-auto"><label v-for="p in products" :key="p.handle" class="flex cursor-pointer items-center gap-3 border-b border-sidebar-border/40 px-2 py-3 text-sm hover:bg-accent"><input v-model="selected" type="radio" name="stock-product" :value="p.handle" /><img v-if="p.image" :src="p.image" alt="" class="h-10 w-10 rounded object-contain" loading="lazy" /><span>{{ p.title }}<small v-if="stock.some((s) => s.handle === p.handle)" class="ml-2 text-emerald-400">Già selezionato</small></span></label><p v-if="!products.length" class="py-4 text-sm text-muted-foreground">Nessun prodotto trovato.</p></div>
            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="add"><div><label for="stock-add-quantity" class="block text-xs font-medium">Quantità disponibile</label><input id="stock-add-quantity" v-model.number="quantity" type="number" min="1" max="9999" step="1" required class="mt-1 w-28 rounded-lg border border-sidebar-border bg-background px-3 py-2 text-sm" /></div><div><label for="stock-add-discount" class="block text-xs font-medium">Sconto (%)</label><input id="stock-add-discount" v-model.number="discount" type="number" min="0" max="100" step="1" required class="mt-1 w-24 rounded-lg border border-sidebar-border bg-background px-3 py-2 text-sm" /></div><button :disabled="!selected || busy || loading" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ busy ? 'Salvataggio…' : 'Salva in offerta' }}</button></form>
        </div>
        <div v-if="stock.length" class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b border-sidebar-border text-muted-foreground"><tr><th class="py-3 pr-4 font-medium">Prodotto</th><th class="px-3 py-3 font-medium">Quantità</th><th class="px-3 py-3 font-medium">Sconto %</th><th class="px-3 py-3 font-medium">Home</th><th class="py-3 text-right font-medium">Azioni</th></tr></thead><tbody><tr v-for="p in stock" :key="p.id" class="border-b border-sidebar-border/40"><td class="min-w-56 py-3 pr-4"><div class="flex items-center gap-3"><img v-if="p.image" :src="p.image" alt="" class="h-12 w-12 rounded object-contain" loading="lazy" /><span>{{ p.title }}<small v-if="!p.available" class="block text-amber-400">Prodotto assente dal catalogo</small></span></div></td><td class="px-3 py-3"><input type="number" min="0" max="9999" step="1" class="w-20 rounded-lg border border-sidebar-border bg-background px-2 py-2" :value="p.quantity" :disabled="busy" :aria-label="`Quantità ${p.title}`" @change="update(p, $event)" /></td><td class="px-3 py-3"><input type="number" min="0" max="100" step="1" class="w-20 rounded-lg border border-sidebar-border bg-background px-2 py-2 text-yellow-400" :value="p.discountPercent" :disabled="busy" :aria-label="`Sconto percentuale ${p.title}`" @change="updateDiscount(p, $event)" /></td><td class="whitespace-nowrap px-3 py-3"><span :class="p.available && p.quantity > 0 ? 'text-emerald-400' : 'text-muted-foreground'">{{ p.available && p.quantity > 0 ? 'Visibile' : 'Non visibile' }}</span></td><td class="py-3 text-right"><button type="button" class="rounded-lg p-2 text-muted-foreground hover:bg-red-500/10 hover:text-red-400 disabled:opacity-50" :disabled="busy" :aria-label="`Rimuovi ${p.title} dalla home`" @click="save(() => axios.delete(`/dashboard/in-stock/${p.id}`), 'Prodotto rimosso dalla selezione della home.')"><Trash2 :size="18" /></button></td></tr></tbody></table><p class="mt-3 text-xs text-muted-foreground">Lo sconto 0 nasconde la targhetta. La quantità 0 nasconde il prodotto dalla home. La selezione viene conservata durante i reimport del catalogo.</p></div>
        <p v-else-if="ready && !loading" class="mt-5 rounded-lg bg-emerald-500/5 p-4 text-sm text-muted-foreground">Nessun prodotto selezionato. Aggiungi i prodotti disponibili per mostrarli nella sezione In offerta della home.</p>
    </section>
</template>
