<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{ initialQuery?: string; connection?: { connected: boolean; expires_at: number | null } | null }>();
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
let statusTimer: ReturnType<typeof setInterval> | undefined;
const connection = ref(props.connection);
watch(() => props.connection, value => { connection.value = value; });
async function refreshConnection() {
    try {
        const r = await fetch('/autogenesi/aliexpress/status', { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (r.ok) connection.value = await r.json();
    } catch {}
}
function onFocus() { if (document.visibilityState === 'visible') void refreshConnection(); }
onMounted(() => {
    timer = setInterval(() => { now.value = Date.now(); }, 1000);
    statusTimer = setInterval(() => { void refreshConnection(); }, 300000);
    document.addEventListener('visibilitychange', onFocus);
});
onUnmounted(() => { if (timer) clearInterval(timer); if (statusTimer) clearInterval(statusTimer); document.removeEventListener('visibilitychange', onFocus); });
const connected = computed(() => !!connection.value?.connected && !!connection.value.expires_at && connection.value.expires_at * 1000 > now.value);
const expiry = computed(() => connection.value?.expires_at ? new Date(connection.value.expires_at * 1000).toLocaleString('it-IT') : '');

type Candidate = { id: string; title: string; price: number | null; url: string; orders?: string };
type Detail = { title: string; description: string; url: string; variants: { label: string; price: string; currency: string }[] };
const query = ref('');
watch(() => props.initialQuery, value => { if (value) query.value = value; });
const sortBy = ref('price');
const busy = ref(false);
const authorizing = ref(false);
const authRetryUntil = ref(0);
const authWait = computed(() => Math.max(0, Math.ceil((authRetryUntil.value - now.value) / 1000)));
const error = ref('');
const results = ref<{ products: Candidate[]; total: number; cached?: boolean } | null>(null);
const detail = ref<Detail | null>(null);
const selected = ref('');
async function request(path: string, body: Record<string, string>) {
    const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
    const r = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(csrf || '') }, body: JSON.stringify(body) });
    if (r.redirected || r.status === 401) throw new Error('Accedi nuovamente alla sessione locale.');
    const data = await r.json().catch(() => ({}));
    if (r.status === 429) {
        const retry = r.headers.get('Retry-After');
        const seconds = Math.max(1, retry && /^\d+$/.test(retry) ? Number(retry) : retry ? Math.ceil((Date.parse(retry) - Date.now()) / 1000) || 60 : 60);
        if (path.endsWith('/authorize')) { now.value = Date.now(); authRetryUntil.value = now.value + seconds * 1000; }
        throw new Error(`Troppe richieste. Riprova tra ${seconds} secondi.`);
    }
    if (!r.ok) throw new Error(data.message || 'Richiesta API non completata.');
    return data;
}
async function search() {
    if (busy.value || !query.value.trim()) return;
    busy.value = true; selected.value = ''; error.value = ''; results.value = null; detail.value = null;
    try { results.value = await request('/autogenesi/aliexpress/search', { query: query.value.trim(), sort_by: sortBy.value }); }
    catch (e) { error.value = e instanceof Error ? e.message : 'Ricerca non completata.'; }
    finally { busy.value = false; }
}
async function authorize() {
    if (authorizing.value || authWait.value > 0) return;
    authorizing.value = true; error.value = '';
    try {
        const data = await request('/autogenesi/aliexpress/authorize', {});
        window.location.assign(data.url);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Autorizzazione non avviata.';
        authorizing.value = false;
    }
}
async function read(id: string) {
    if (busy.value) return;
    busy.value = true; selected.value = id; error.value = ''; detail.value = null;
    try { detail.value = await request('/autogenesi/aliexpress/product', { product_id: id }); }
    catch (e) { error.value = e instanceof Error ? e.message : 'Lettura non completata.'; }
    finally { busy.value = false; }
}
</script>

<template>
    <section class="rounded-xl border border-blue-400/40 bg-blue-400/5 p-5 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Ricerca con API</h2>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="rounded-full px-3 py-1" :class="connected ? 'bg-emerald-500/10 text-emerald-500' : 'bg-amber-500/10 text-amber-500'">{{ connected ? 'AliExpress collegato' : 'Autorizzazione necessaria' }}</span>
            <span v-if="expiry" class="text-muted-foreground">Scadenza: {{ expiry }}</span>
        </div>
        <div v-if="!connected" class="flex flex-wrap items-center gap-3">
            <button type="button" class="rounded-lg bg-amber-500 px-4 py-2 font-medium text-black disabled:opacity-50" :disabled="authorizing || busy || authWait > 0" @click="authorize">{{ authorizing ? 'Apertura AliExpress…' : authWait > 0 ? `Riprova tra ${authWait} s` : 'Autorizza AliExpress' }}</button>
            <p class="text-sm text-amber-500">Autorizza l’account per usare la ricerca API.</p>
        </div>
        <form class="flex flex-wrap gap-3" @submit.prevent="search">
            <label class="flex-1 min-w-56 text-sm">Testo di ricerca
                <input v-model="query" required maxlength="150" placeholder="radio Hyundai Kona 2017 2020" class="mt-2 w-full rounded-lg border bg-transparent px-3 py-2" :disabled="busy">
            </label>
            <label class="text-sm">Ordina per<select v-model="sortBy" :disabled="busy" class="mt-2 block rounded-lg border bg-background px-3 py-2"><option value="price">Prezzo più basso</option><option value="sales">Più venduti</option><option value="best_match">Migliore corrispondenza</option></select></label>
            <button class="self-end rounded-lg bg-blue-600 px-4 py-2 text-white disabled:opacity-50" :disabled="busy || !query.trim()">{{ busy && !selected ? 'Ricerca in corso…' : 'Cerca con API' }}</button>
        </form>
        <p class="text-sm text-muted-foreground">Senza browser · destinazione Spagna · prezzi EUR. Risultati da verificare: compatibilità, origine UE e variante. Non importa automaticamente nelle bozze.</p>
        <p class="text-xs text-muted-foreground">Prezzo e vendite ordinano i risultati ricevuti. Migliore corrispondenza mantiene l’ordine restituito da AliExpress.</p>
        <p v-if="error" role="alert" class="text-sm text-red-500">{{ error }}</p>
        <template v-if="results">
            <p class="text-sm">{{ results.products.length }} risultati visualizzati su {{ results.total }}. {{ !results.products.length ? 'Nessun prodotto trovato.' : '' }}</p>
            <p v-if="results.cached" class="text-sm text-muted-foreground">Risultati recuperati dalla cache degli ultimi 5 minuti.</p>
            <div v-for="p in results.products" :key="p.id" class="flex flex-wrap items-center justify-between gap-3 border-t py-3">
                <div class="min-w-0 flex-1"><a :href="p.url" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-400 underline">{{ p.title }}</a><p class="mt-1 text-sm">{{ p.price !== null ? p.price.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }) + ' · prezzo risultato' : 'Prezzo non disponibile' }}</p></div>
                <span class="text-sm text-muted-foreground">Ordini API: {{ p.orders || 'Non disponibili' }}</span>
                <button type="button" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50" :disabled="busy" @click="read(p.id)">{{ busy && selected === p.id ? 'Lettura…' : 'Leggi scheda e varianti' }}</button>
            </div>
        </template>
        <div v-if="detail" class="rounded-lg border p-4 space-y-3">
            <h3 class="font-semibold">{{ detail.title }}</h3>
            <a :href="detail.url" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-400 underline">Apri prodotto AliExpress</a>
            <p class="text-sm whitespace-pre-wrap">{{ detail.description }}</p>
            <p class="text-sm text-muted-foreground">Prime {{ detail.variants.length }} varianti ricevute.</p>
            <div v-for="(v, i) in detail.variants" :key="i" class="flex flex-wrap justify-between gap-3 border-t py-2 text-sm"><span class="break-all">{{ v.label }}</span><span>{{ v.price }} {{ v.currency }}</span></div>
        </div>
    </section>
</template>
