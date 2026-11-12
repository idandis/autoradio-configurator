<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import CopyProductTitle from '@/components/CopyProductTitle.vue';
import CatalogPriceRules from '@/components/CatalogPriceRules.vue';

type Variant = { id: number; title: string; status: string; old_price: string; supplier_label?: string; new_price?: string; cost?: string; minimum?: string; reason?: string };
type Item = { id: number; title: string; status: string; manual_updated?: boolean; source_urls?: string[]; error?: string; result?: { supplier_variants: number; variants: Variant[] } };
type PriceColor = 'all' | 'red' | 'blue' | 'green' | 'yellow' | 'none';
const props = defineProps<{ run?: { id: number; status: string; error?: string; counts: { raised: number; unchanged: number; skipped: number }; items: Item[] } | null }>();
const form = useForm({});
const deletingProduct = ref<number | null>(null);
const deleteError = ref('');
const detailsOpen = ref(false);
const openRows = ref<number[]>([]);
const colorFilter = ref<PriceColor>('all');
const stateKey = (id: number) => `catalog-price-update-open:${id}`;
watch(() => props.run?.id, id => {
    if (!id || typeof window === 'undefined') return;
    try {
        const state = JSON.parse(sessionStorage.getItem(stateKey(id)) || '{}');
        detailsOpen.value = state.details === true;
        openRows.value = Array.isArray(state.rows) ? state.rows.filter((row: unknown) => typeof row === 'number') : [];
    } catch { detailsOpen.value = false; openRows.value = []; }
}, { immediate: true });
const persistOpen = () => {
    if (props.run?.id && typeof window !== 'undefined') sessionStorage.setItem(stateKey(props.run.id), JSON.stringify({ details: detailsOpen.value, rows: openRows.value }));
};
const toggleDetails = (event: Event) => { detailsOpen.value = (event.target as HTMLDetailsElement).open; persistOpen(); };
const toggleRow = (id: number, event: Event) => {
    const open = (event.target as HTMLDetailsElement).open;
    openRows.value = open ? [...new Set([...openRows.value, id])] : openRows.value.filter(row => row !== id);
    persistOpen();
};
const deleteProduct = (item: Item) => {
    if (deletingProduct.value !== null) return;
    deletingProduct.value = item.id; deleteError.value = '';
    router.delete(`/imported-products/${item.id}`, { preserveScroll: true,
        onError: () => { deleteError.value = 'Eliminazione non riuscita.'; },
        onFinish: () => { deletingProduct.value = null; },
    });
};
const openSupplier = (url: string) => { window.open(url, '_blank', 'noopener,noreferrer'); };
const done = computed(() => props.run?.items.filter(i => ['completed', 'error', 'manual_updated'].includes(i.status)).length || 0);
const canReset = computed(() => !!props.run && ['blocked', 'stopped'].includes(props.run.status));
const canResume = computed(() => !!props.run && ['blocked', 'stopped', 'completed'].includes(props.run.status)
    && props.run.items.some(i => !i.manual_updated && (['pending', 'running'].includes(i.status)
        || (i.status === 'completed' && i.result?.variants.some(v => v.status === 'skipped') && !i.result?.variants.some(v => v.status === 'raised')))));
const rowCategory = (item: Item): Exclude<PriceColor, 'all'> => {
    if (item.manual_updated) return 'blue';
    if (item.status === 'error') return 'red';
    if (item.status !== 'completed') return 'none';
    if (item.result?.variants.some(v => v.status === 'raised')) return 'blue';
    if (item.result?.variants.some(v => v.status === 'skipped')) return 'yellow';
    return 'green';
};
const rowColor = (item: Item) => ({ red: 'border-red-500/40 bg-red-500/10', blue: 'border-sky-400/40 bg-sky-400/10', green: 'border-emerald-500/40 bg-emerald-500/10', yellow: 'border-yellow-400/40 bg-yellow-400/10', none: '' })[rowCategory(item)];
const visibleItems = computed(() => (props.run?.items || []).map((item, index) => ({ item, index })).filter(({ item }) => colorFilter.value === 'all' || rowCategory(item) === colorFilter.value));
const setFilter = (color: PriceColor) => { colorFilter.value = color; detailsOpen.value = true; persistOpen(); };
const labels: Record<string, string> = { name_matched: 'Tentato aggiornamento con nome variante con successo', manual_matched: 'Abbinamento manuale aggiornato con successo', manual_updated: 'Abbinamento aggiornato', running: 'In corso', pending: 'In coda', completed: 'Completato', error: 'Errore', blocked: 'Autorizzazione o verifica richiesta', stopped: 'Fermato', raised: 'Prezzo aumentato', unchanged: 'Già sufficiente', skipped: 'Da verificare' };
let timer: ReturnType<typeof setInterval> | undefined;
watch(() => props.run?.status, status => {
    if (timer) clearInterval(timer);
    if (status === 'running') timer = setInterval(() => router.reload({ only: ['priceUpdate', 'products'], preserveScroll: true, preserveState: true }), 5000);
}, { immediate: true });
onUnmounted(() => { if (timer) clearInterval(timer); });
const action = (type: string) => form.post(type === 'start' ? '/imported-products/price-update' : `/imported-products/price-update/${props.run?.id}/${type}`, { preserveScroll: true });
</script>

<template>
    <section class="mb-6 rounded-xl border border-sidebar-border/70 bg-card p-5">
        <h2 class="font-semibold">Aggiornamento prezzo</h2>
        <CatalogPriceRules />
        <div aria-label="Filtra prodotti per colore" class="mt-3 flex flex-wrap items-center gap-2 text-xs">
            <label class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2"><input type="checkbox" :checked="colorFilter === 'all'" @change="setFilter('all')" /> Tutti</label>
            <button type="button" :aria-pressed="colorFilter === 'red'" class="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2" :class="colorFilter === 'red' ? 'ring-2 ring-red-500' : ''" @click="setFilter('red')">Rosso · errore lettura/link</button>
            <button type="button" :aria-pressed="colorFilter === 'blue'" class="rounded-md border border-sky-400/40 bg-sky-400/10 px-3 py-2" :class="colorFilter === 'blue' ? 'ring-2 ring-sky-400' : ''" @click="setFilter('blue')">Blu · variante aumentata o abbinamento aggiornato</button>
            <button type="button" :aria-pressed="colorFilter === 'green'" class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-3 py-2" :class="colorFilter === 'green' ? 'ring-2 ring-emerald-500' : ''" @click="setFilter('green')">Verde · completato senza aumenti</button>
            <button type="button" :aria-pressed="colorFilter === 'yellow'" class="rounded-md border border-yellow-400/40 bg-yellow-400/10 px-3 py-2" :class="colorFilter === 'yellow' ? 'ring-2 ring-yellow-400' : ''" @click="setFilter('yellow')">Giallo · SKU non abbinati, nessun aumento</button>
            <button type="button" :aria-pressed="colorFilter === 'none'" class="rounded-md border px-3 py-2" :class="colorFilter === 'none' ? 'ring-2 ring-foreground' : ''" @click="setFilter('none')">Nessun colore · in corso/in coda</button>
        </div>
        <p class="my-3 text-sm text-muted-foreground">Solo autoradio con link AliExpress valido · camere, dashcam, altoparlanti e accessori esclusi. Costi aggiornati in EUR, destinazione Spagna · lavoro in background senza browser. Salva il costo per ogni variante abbinata e aumenta soltanto i prezzi inferiori alla formula. Prezzi già uguali o superiori invariati. Nessuna variante aggiunta; SKU non abbinabili segnalati. Aggiorna solo il database locale: per Shopify usa l’esportazione.</p>
        <button v-if="!run || run.status === 'completed'" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50" @click="action('start')">Aggiorna costi e prezzi</button>
        <button v-if="run?.status === 'running'" :disabled="form.processing" class="rounded-lg border border-red-500 px-4 py-2 text-red-500" @click="action('stop')">Ferma aggiornamento</button>
        <button v-if="canResume" :disabled="form.processing" class="ml-2 rounded-lg border px-4 py-2" @click="action('resume')">Riprendi aggiornamento · solo gialli e in coda</button>
        <button v-if="canReset" :disabled="form.processing" class="ml-2 rounded-lg border border-red-500 px-4 py-2 text-red-500 disabled:opacity-50" @click="action('reset')">Reset e ricomincia</button>
        <p v-if="canReset" class="mt-2 text-xs text-muted-foreground">Reset azzera il riepilogo e consente una nuova verifica di tutte le radio. Prezzi, costi e abbinamenti salvati restano invariati. Nessun avvio automatico.</p>
        <a v-if="run?.status === 'blocked' || Object.keys(form.errors).length" href="/autogenesi/aliexpress/callback" class="ml-3 text-blue-400 underline">Verifica autorizzazione AliExpress</a>
        <p v-for="(error, key) in form.errors" :key="key" class="mt-2 text-red-500">{{ error }}</p>
        <p v-if="deleteError" role="alert" class="mt-2 text-red-500">{{ deleteError }}</p>
        <div v-if="run" class="mt-4 text-sm">
            <p>{{ labels[run.status] }} · {{ done }}/{{ run.items.length }} prodotti · {{ run.counts.raised }} varianti aumentate · {{ run.counts.unchanged }} già sufficienti · {{ run.counts.skipped }} da verificare</p>
            <p v-if="run.error" class="mt-2 text-amber-500">{{ run.error }}</p>
            <details class="mt-3" :open="detailsOpen" @toggle="toggleDetails"><summary class="cursor-pointer">Dettaglio prodotti e varianti</summary>
                <div class="mt-3 max-h-96 space-y-2 overflow-auto">
                    <details v-for="{ item, index } in visibleItems" :key="item.id" :open="openRows.includes(item.id)" class="rounded-lg border p-3" :class="rowColor(item)" @toggle="toggleRow(item.id, $event)">
                        <summary class="cursor-pointer"><span class="mr-2 inline-block min-w-8 font-semibold tabular-nums">{{ index + 1 }}.</span>{{ item.title }} · {{ labels[item.status] }}
                            <a v-for="(url, i) in item.source_urls" :key="url" :href="url" target="_blank" rel="noopener noreferrer" class="ml-3 inline-block text-blue-400 underline underline-offset-2" @click.stop.prevent="openSupplier(url)">Apri prodotto fornitore{{ (item.source_urls?.length ?? 0) > 1 ? ` ${i + 1}` : '' }} ↗</a>
                            <CopyProductTitle :title="item.title" />
                            <button type="button" :disabled="deletingProduct !== null" class="ml-2 rounded-md border border-red-500/50 px-2 py-1 text-xs text-red-500 disabled:opacity-40" :aria-label="`Elimina prodotto ${item.title}`" @click.stop.prevent="deleteProduct(item)">{{ deletingProduct === item.id ? 'Eliminazione…' : 'Elimina prodotto' }}</button>
                        </summary>
                        <p v-if="item.error" class="mt-2 text-amber-500">{{ item.error }}</p>
                        <template v-if="item.result">
                            <p class="my-2 text-muted-foreground">{{ item.result.variants.length }} varianti locali · {{ item.result.supplier_variants }} varianti fornitore disponibili</p>
                            <div v-for="v in item.result.variants" :key="v.id" class="border-t py-2">
                                {{ v.title }} · {{ labels[v.status] }}
                                <span v-if="v.supplier_label" class="block text-xs text-muted-foreground">AliExpress: {{ v.supplier_label }}</span>
                                <span v-if="v.cost"> · Costo {{ v.cost }} € · Minimo {{ v.minimum }} € · Vendita {{ v.old_price }} → {{ v.new_price }} €</span>
                                <span v-if="v.reason" class="text-amber-500"> · {{ v.reason }}</span>
                            </div>
                        </template>
                        <a v-if="item.status === 'error' || item.manual_updated || item.result?.skipped" :href="`/imported-products/${item.id}/variant-matcher?replace=${item.status === 'error' || !item.result?.variants.some(v => v.status === 'raised') ? 1 : 0}`" target="_blank" rel="opener" class="mt-3 inline-flex rounded border border-amber-500/50 px-3 py-2 text-sm hover:bg-amber-500/10">Ricarica nomi varianti ↗</a>
                    </details>
                    <p v-if="!visibleItems.length" class="py-3 text-muted-foreground">Nessun prodotto per questo filtro.</p>
                </div>
            </details>
        </div>
    </section>
</template>
