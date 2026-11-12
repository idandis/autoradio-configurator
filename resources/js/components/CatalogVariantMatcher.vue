<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import CopyProductTitle from '@/components/CopyProductTitle.vue';

type LocalVariant = { id: number; label: string; sku: string | null; price: string | null; rule_price?: string | null; supplier_label?: string | null; supplier_cost?: string | null; source_id: string | null; sku_id: string | null };
type SupplierVariant = { source_id: string; sku_id: string; label: string; cost: string | null; minimum?: string | null; available: boolean; recreate_name: string | null; url: string };
const props = defineProps<{ productId: number; allowReplacement?: boolean; autoLoad?: boolean }>();
const opened = ref(false);
const busy = ref(false);
const selected = ref<number | null>(null);
const adding = ref(false);
const newTitle = ref('');
const error = ref('');
const message = ref('');
const showReplacement = ref(false);
const replacementUrl = ref('');
const previewUrl = ref('');
const comparison = ref<{ app: LocalVariant[]; aliexpress: SupplierVariant[]; errors: string[] } | null>(null);
const supplierUrl = computed(() => previewUrl.value || comparison.value?.aliexpress.find(v => v.available)?.url || comparison.value?.aliexpress[0]?.url || '');
onMounted(() => { if (props.autoLoad) load(); });
function refreshParent() {
    if (!props.autoLoad) router.reload({ only: ['products', 'priceUpdate'], preserveScroll: true, preserveState: true });
}
function sortApp() {
    comparison.value?.app.sort((a, b) => {
        const ap = a.price === null ? Infinity : Number(a.price);
        const bp = b.price === null ? Infinity : Number(b.price);
        return ap - bp || a.id - b.id;
    });
}
async function request(path: string, body?: object, method = body ? 'POST' : 'GET') {
    const token = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
    const r = await fetch(path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(token || '') }, ...(body ? { body: JSON.stringify(body) } : {}) });
    if (r.redirected || r.status === 401) throw new Error('Accedi nuovamente alla sessione locale.');
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(r.status === 429 ? 'Troppe richieste: attendi un minuto.' : Object.values(data.errors || {}).flat()[0] || data.message || 'Lettura varianti non riuscita.');
    return data;
}
async function load(useNew = false) {
    opened.value = true; busy.value = true; error.value = ''; selected.value = null;
    adding.value = false;
    comparison.value = null; message.value = ''; previewUrl.value = '';
    try {
        comparison.value = await request(`/imported-products/${props.productId}/supplier-variants`, useNew ? { source_url: replacementUrl.value.trim() } : undefined);
        sortApp();
        if (useNew) previewUrl.value = comparison.value?.aliexpress[0]?.url || replacementUrl.value.trim();
    }
    catch (e) { error.value = e instanceof Error ? e.message : 'Lettura non riuscita.'; }
    finally { busy.value = false; }
}
async function match(supplier: SupplierVariant) {
    if ((selected.value === null && !adding.value) || busy.value || !supplier.available || !comparison.value) return;
    busy.value = true; error.value = ''; message.value = '';
    try {
        const data = await request(adding.value ? `/imported-products/${props.productId}/supplier-variants/add` : `/imported-products/${props.productId}/variants/${selected.value}/supplier-match`, { source_id: supplier.source_id, sku_id: supplier.sku_id, ...(adding.value ? { title: newTitle.value.trim() } : {}), ...(previewUrl.value ? { replacement_url: previewUrl.value } : {}) });
        if (adding.value) comparison.value.app.push(data.app_variant);
        else {
            const local = comparison.value.app.find(v => v.id === selected.value);
            if (local) {
                local.source_id = supplier.source_id; local.sku_id = supplier.sku_id; local.price = data.result.variants[0]?.new_price ?? local.price;
                local.rule_price = data.result.variants[0]?.minimum ?? null; local.supplier_label = data.result.variants[0]?.supplier_label || supplier.label;
                local.supplier_cost = data.result.variants[0]?.cost ?? null;
            }
        }
        sortApp();
        selected.value = null; adding.value = false; newTitle.value = ''; message.value = data.message;
        refreshParent();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Abbinamento non salvato.'; }
    finally { busy.value = false; }
}
async function addManual() {
    const title = newTitle.value.trim();
    if (busy.value || !comparison.value || !title) return;
    busy.value = true; error.value = ''; message.value = '';
    try {
        const data = await request(`/imported-products/${props.productId}/supplier-variants/add-manual`, { title });
        comparison.value.app.push(data.app_variant);
        sortApp();
        adding.value = false; newTitle.value = ''; message.value = data.message;
        refreshParent();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Variante non aggiunta.'; }
    finally { busy.value = false; }
}
async function removeVariant(variant: LocalVariant) {
    if (busy.value || !comparison.value || comparison.value.app.length < 2) return;
    busy.value = true; error.value = ''; message.value = '';
    try {
        const data = await request(`/imported-products/${props.productId}/variants/${variant.id}/supplier-match`, undefined, 'DELETE');
        comparison.value.app = comparison.value.app.filter(v => v.id !== variant.id);
        if (selected.value === variant.id) selected.value = null;
        message.value = data.message;
        refreshParent();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Eliminazione non riuscita.'; }
    finally { busy.value = false; }
}
function savePrice(variant: LocalVariant) {
    if (busy.value) return;
    if (variant.price === null || String(variant.price).trim() === '' || !Number.isFinite(Number(variant.price)) || Number(variant.price) < 0 || Number(variant.price) > 999999.99) {
        error.value = 'Inserisci un prezzo finale valido.'; return;
    }
    const value = Number(variant.price).toFixed(2);
    busy.value = true; error.value = ''; message.value = '';
    router.patch(`/imported-products/${props.productId}/variants`, { variants: [{ id: variant.id, price: value }] }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { variant.price = value; sortApp(); message.value = 'Prezzo finale modificato e salvato nel DB.'; },
        onError: errors => { error.value = Object.values(errors).join(' ') || 'Prezzo non salvato.'; },
        onFinish: () => { busy.value = false; },
    });
}
async function saveName(variant: LocalVariant) {
    if (busy.value) return;
    if (!variant.label.trim()) { error.value = 'Inserisci il nome variante.'; return; }
    busy.value = true; error.value = ''; message.value = '';
    try {
        const data = await request(`/imported-products/${props.productId}/variants/${variant.id}/supplier-match`, { title: variant.label.trim() }, 'PATCH');
        variant.label = data.title; message.value = data.message;
        refreshParent();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Nome non salvato.'; }
    finally { busy.value = false; }
}
async function attempt() {
    if (busy.value || !comparison.value) return;
    busy.value = true; error.value = ''; message.value = ''; selected.value = null; adding.value = false;
    try {
        const data = await request(`/imported-products/${props.productId}/supplier-variants/attempt`, previewUrl.value ? { source_url: previewUrl.value } : {});
        comparison.value.app = data.app; sortApp(); message.value = data.message;
        refreshParent();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Abbinamento non riuscito.'; }
    finally { busy.value = false; }
}
async function recreate() {
    if (busy.value || !comparison.value) return;
    busy.value = true; error.value = ''; message.value = ''; selected.value = null; adding.value = false;
    try {
        const data = await request(`/imported-products/${props.productId}/supplier-variants/recreate`, previewUrl.value ? { source_url: previewUrl.value } : {});
        comparison.value.app = data.app;
        sortApp();
        message.value = data.message;
        refreshParent();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Ricreazione varianti non riuscita.'; }
    finally { busy.value = false; }
}
</script>

<template>
    <div class="mt-3 rounded-lg border border-amber-500/40 p-3">
        <button type="button" :disabled="busy" class="rounded border px-3 py-2 text-sm disabled:opacity-50" @click="load(false)">{{ busy ? 'Lettura / salvataggio…' : opened ? 'Ricarica nomi varianti' : 'Mostra varianti app / AliExpress e abbina' }}</button>
        <button v-if="allowReplacement" type="button" :disabled="busy" class="ml-2 rounded border border-sky-400/50 bg-sky-400/10 px-3 py-2 text-sm" @click="showReplacement = !showReplacement">Aggiorna nuovo link</button>
        <a v-if="supplierUrl" :href="supplierUrl" target="_blank" rel="noopener noreferrer" class="ml-2 inline-flex rounded border px-3 py-2 text-sm">Apri scheda fornitore AliExpress ↗</a>
        <form v-if="showReplacement" class="mt-3 space-y-2" @submit.prevent="load(true)">
            <label class="block text-sm">Nuovo link prodotto AliExpress<input v-model="replacementUrl" required type="url" maxlength="2048" :disabled="busy" placeholder="https://es.aliexpress.com/item/123.html" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
            <button :disabled="busy || !replacementUrl.trim()" class="rounded bg-sky-500 px-3 py-2 text-sm font-medium text-white disabled:opacity-50">Leggi varianti nuovo link</button>
            <p class="text-xs text-muted-foreground">Il nuovo link viene salvato al primo abbinamento. Ogni coppia scelta aggiorna subito costo e prezzo nel DB. Le varianti non ancora abbinate restano invariate e da verificare.</p>
        </form>
        <p v-if="error" role="alert" class="mt-2 text-red-500">{{ error }}</p>
        <p v-if="message" role="status" class="mt-2 text-emerald-500">{{ message }}</p>
        <template v-if="opened && comparison">
            <p class="my-3 text-xs text-muted-foreground">1. Clicca la variante app. 2. Clicca la variante AliExpress: il prezzo viene prima reimpostato secondo la regola attiva. Poi puoi ritoccare «Prezzo finale»: salva con Invio o uscendo dal campo. Un nuovo abbinamento ricalcola nuovamente il prezzo. L’esito del job sopra è storico.</p>
            <p v-for="(warning, i) in comparison.errors" :key="i" class="my-2 text-amber-500">{{ warning }}</p>
            <div class="grid gap-3 md:grid-cols-2 md:items-start">
                <div tabindex="0" aria-label="Scorri varianti app" class="min-w-0 md:max-h-[calc(100dvh-12rem)] md:overflow-y-auto md:overscroll-contain md:pr-2"><h3 class="mb-2 font-semibold">Varianti app</h3>
                    <button type="button" :disabled="busy" class="mb-3 rounded border border-emerald-500/50 px-3 py-2 text-sm" @click="adding = true; selected = null; newTitle = ''; message = ''">+ Aggiungi variante</button>
                    <button type="button" :disabled="busy || !comparison.aliexpress.some(v => v.available)" class="mb-3 ml-2 rounded border border-sky-400/50 bg-sky-400/10 px-3 py-2 text-sm disabled:opacity-40" @click="attempt">Tenta abbinamento</button>
                    <button type="button" :disabled="busy" class="mb-3 ml-2 rounded border border-amber-400 bg-amber-400/15 px-3 py-2 text-sm font-medium text-amber-200 disabled:opacity-40" @click="recreate">RICREA VARIANTI</button>
                    <a :href="`/imported-products/${productId}/sheet`" target="_blank" rel="noopener noreferrer" class="mb-3 ml-2 inline-flex rounded border px-3 py-2 text-sm">Apri scheda prodotto ↗</a>
                    <p class="mb-2 text-xs text-amber-400">Ricrea nel formato XCore RAM ROM, oppure RAM ROM se i core non sono leggibili. Esclude DVR e 360; a parità di configurazione sceglie il costo più basso. Le varianti senza RAM/ROM sono evidenziate in giallo e non importate.</p>
                    <p class="mb-3 text-xs text-muted-foreground">Tenta abbinamento usa nome, RAM/memoria e caratteristiche: esclude DVR/360 e salva solo corrispondenze univoche, con prezzi ricalcolati dalla regola.</p>
                    <div v-if="adding" class="mb-3 rounded border border-emerald-500 bg-emerald-500/10 p-3">
                        <label class="block text-sm">Nome nuova variante (facoltativo)<input v-model="newTitle" maxlength="255" :disabled="busy" class="mt-1 w-full rounded border bg-background p-2" placeholder="8Core 8GB 128GB" /></label>
                        <p class="mt-2 text-xs">Clicca una variante AliExpress a destra per abbinarla ora, oppure aggiungila senza abbinamento. Per aggiungerla senza abbinamento serve il nome; il prezzo potrai impostarlo dopo.</p>
                        <div class="mt-2 flex items-center gap-3"><button type="button" :disabled="busy || !newTitle.trim()" class="rounded bg-emerald-600 px-3 py-2 text-sm text-white disabled:opacity-40" @click="addManual">{{ busy ? 'Aggiunta…' : 'Aggiungi' }}</button><button type="button" :disabled="busy" class="text-xs underline" @click="adding = false">Annulla aggiunta</button></div>
                    </div>
                    <div v-for="v in comparison.app" :key="v.id" class="mb-2 flex items-start gap-2 rounded-lg border p-3" :class="selected === v.id ? 'border-amber-400 bg-amber-400/20' : ''">
                    <div class="min-w-0 flex-1">
                    <button type="button" :disabled="busy" :aria-pressed="selected === v.id" class="block w-full text-left disabled:opacity-50" @click="selected = v.id; adding = false; message = ''">
                        <span class="block font-medium">{{ v.label }}</span><span class="block text-xs text-muted-foreground">SKU {{ v.sku }}</span>
                        <span class="mt-1 block text-xs text-emerald-500">Nome variante abbinata: {{ v.sku_id ? (v.supplier_label || comparison.aliexpress.find(a => a.source_id === v.source_id && a.sku_id === v.sku_id)?.label || 'Nome non disponibile') : 'Non abbinata' }}</span>
                        <span v-if="v.sku_id" class="block break-all text-xs text-muted-foreground">SKU AliExpress: {{ v.sku_id }}</span>
                    </button>
                    <label class="mt-2 block text-xs">Nome variante<input v-model="v.label" type="text" required maxlength="255" :disabled="busy" class="mt-1 w-full rounded border bg-background p-2" :aria-label="`Nome variante ${v.id}`" @click.stop @change="saveName(v)" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()" /></label>
                    <p class="mt-2 text-xs">Costo prodotto: {{ v.supplier_cost != null ? `${v.supplier_cost} €` : 'Non disponibile' }}</p>
                    <p v-if="v.rule_price" class="mt-2 text-xs text-muted-foreground">Prezzo calcolato dalla regola: {{ v.rule_price }} €</p>
                    <label class="mt-2 flex flex-wrap items-center gap-2 text-xs">Prezzo finale €<input v-model="v.price" type="number" min="0" max="999999.99" step="0.01" :disabled="busy" :aria-label="`Prezzo finale ${v.label}`" class="w-28 rounded border bg-background p-2" @click.stop @change="savePrice(v)" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()" /></label>
                    </div>
                    <button type="button" :disabled="busy || comparison.app.length < 2" class="shrink-0 rounded border border-red-500/50 px-2 py-1 text-xs text-red-500 disabled:opacity-40" :aria-label="`Elimina variante ${v.label}`" :title="comparison.app.length < 2 ? 'Deve rimanere almeno una variante' : 'Elimina variante app'" @click.stop.prevent="removeVariant(v)">Elimina</button>
                    </div>
                </div>
                <div tabindex="0" aria-label="Scorri varianti AliExpress" class="min-w-0 md:max-h-[calc(100dvh-12rem)] md:overflow-y-auto md:overscroll-contain md:pr-2"><h3 class="mb-2 font-semibold">Varianti AliExpress</h3>
                    <div v-for="v in comparison.aliexpress" :key="v.source_id + ':' + v.sku_id" class="mb-2 rounded-lg border p-3" :class="v.available && !v.recreate_name ? 'border-amber-400 bg-amber-400/15' : ''">
                        <div class="mb-1 flex items-center justify-between gap-2"><span class="select-text font-medium">{{ v.label }}</span><CopyProductTitle :title="v.label" text="Copia nome" /></div>
                        <p v-if="v.available && !v.recreate_name" class="mb-1 text-xs text-amber-300">RAM/ROM non leggibili: esclusa da RICREA VARIANTI.</p>
                        <button type="button" :disabled="busy || (selected === null && !adding) || !v.available" class="block w-full text-left enabled:hover:text-emerald-500 disabled:opacity-50" @click="match(v)"><span class="block text-xs">{{ v.available ? `Costo ${v.cost} € · minimo formula ${v.minimum} € · clicca per abbinare` : 'Non disponibile con costo EUR verificabile' }}</span>
                        <span class="block text-xs text-muted-foreground">Prodotto {{ v.source_id }} · SKU {{ v.sku_id }}</span>
                        </button>
                    </div>
                    <p v-if="!comparison.aliexpress.length" class="text-amber-500">Nessuna variante leggibile dal fornitore. Usa l’elenco da ricercare in AutoGenesi.</p>
                </div>
            </div>
        </template>
    </div>
</template>
