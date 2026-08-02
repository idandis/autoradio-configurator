<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type EventRow = {
    id: number; created_at: string; event_type: 'quote_downloaded' | 'checkout_clicked';
    brand: string | null; model: string | null; year: number | null; product_type: string | null;
    product_title: string | null; variant_title: string | null; product_price: string | number | null;
    configuration_value: string | number | null; installation_selected: boolean; installation_type: string | null;
    camera_selected: boolean; postal_code: string | null; service_zone: string | null; language: string | null;
};
type AnalysisItem = { label: string; value: number };

const props = defineProps<{
    events: { data: EventRow[]; from: number | null; to: number | null; total: number; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: Record<string, string | null>;
    filterOptions: { brands: string[]; models: string[]; languages: string[]; productTypes: string[]; zones: string[] };
    stats: { quote_downloaded: number; checkout_clicked: number; total: number; quote_value: number; average_quote_value: number; top_brand: string | null; top_model: string | null; top_product: string | null; payment_progression_rate: number };
    analysis: { type: string; visualization: 'table' | 'bar' | 'pie' | 'line'; counting_mode: 'unique' | 'events'; data: AnalysisItem[] };
    insights: string[];
}>();

const filterDefaults = {
    search: '', date_from: '', date_to: '', event_type: '', brand: '', model: '', product_type: '',
    installation: '', camera: '', price_range: '', zone: '', language: '', analysis: 'brands', visualization: 'auto', counting_mode: 'unique',
};
const filters = reactive({ ...filterDefaults, ...props.filters });
const showAnalysisModal = ref(false);
const showDeleteAllModal = ref(false);
const deleteAllConfirmation = ref('');
const selectedIds = ref<number[]>([]);
const analysisChoice = ref(filters.analysis || 'brands');
const visualizationChoice = ref(filters.visualization || 'auto');
const countingModeChoice = ref(filters.counting_mode || 'unique');
const palette = ['#eab308', '#ef4444', '#22c55e', '#3b82f6', '#a855f7', '#f97316', '#06b6d4', '#ec4899'];

const cleanFilters = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));
const applyFilters = () => router.get('/configuration-statistics', cleanFilters(), { preserveState: true, replace: true });
const resetFilters = () => { Object.assign(filters, filterDefaults); applyFilters(); };
const applyAnalysis = () => { filters.analysis = analysisChoice.value; filters.visualization = visualizationChoice.value; filters.counting_mode = countingModeChoice.value; showAnalysisModal.value = false; applyFilters(); };

const analysisLabels: Record<string, string> = {
    brands: 'Marche', models: 'Modelli', years: 'Anni', products: 'Prodotti', variants: 'Varianti', prices: 'Prezzi',
    installations: 'Installazioni', cameras: 'Camere', zones: 'Zone', languages: 'Lingue', conversions: 'Passaggio al pagamento', timeline: 'Andamento temporale',
};
const visualizationLabels: Record<string, string> = { auto: 'Automatico', table: 'Tabella', bar: 'Grafico a barre', pie: 'Grafico a torta', line: 'Grafico a linee' };
const maxAnalysisValue = computed(() => Math.max(...props.analysis.data.map((item) => item.value), 1));
const analysisTotal = computed(() => props.analysis.data.reduce((sum, item) => sum + item.value, 0));
const pieBackground = computed(() => {
    let cursor = 0;
    const segments = props.analysis.data.map((item, index) => {
        const start = cursor;
        cursor += analysisTotal.value ? (item.value / analysisTotal.value) * 100 : 0;
        return `${palette[index % palette.length]} ${start}% ${cursor}%`;
    });
    return `conic-gradient(${segments.join(', ') || '#262626 0 100%'})`;
});
const linePoints = computed(() => props.analysis.data.map((item, index, items) => {
    const x = items.length <= 1 ? 50 : (index / (items.length - 1)) * 100;
    const y = 95 - (item.value / maxAnalysisValue.value) * 85;
    return `${x},${y}`;
}).join(' '));

const money = (value: string | number | null) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(Number(value ?? 0));
const dateTime = (value: string) => new Intl.DateTimeFormat('it-IT', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value));
const eventLabel = (value: EventRow['event_type']) => value === 'quote_downloaded' ? 'Preventivo scaricato' : 'Click Proceder al pago';
const exportUrl = (format: string) => {
    const params = new URLSearchParams(cleanFilters() as Record<string, string>);
    params.delete('analysis'); params.delete('visualization');
    return `/configuration-statistics/export/${format}?${params.toString()}`;
};
const pageIds = computed(() => props.events.data.map((event) => event.id));
const allPageSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selectedIds.value.includes(id)));
const togglePageSelection = () => {
    selectedIds.value = allPageSelected.value
        ? selectedIds.value.filter((id) => !pageIds.value.includes(id))
        : Array.from(new Set([...selectedIds.value, ...pageIds.value]));
};
const deleteEvent = (event: EventRow) => {
    if (!window.confirm(`Eliminare esclusivamente il record statistico #${event.id}?`)) return;
    router.delete(`/configuration-statistics/${event.id}`, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = selectedIds.value.filter((id) => id !== event.id); },
    });
};
const deleteSelected = () => {
    const count = selectedIds.value.length;
    if (!count || !window.confirm(`Stai per eliminare ${count} record statistici selezionati. Continuare?`)) return;
    router.delete('/configuration-statistics/selected', {
        data: { ids: selectedIds.value }, preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; },
    });
};
const deleteAll = () => {
    if (deleteAllConfirmation.value !== 'CANCELLA') return;
    router.delete('/configuration-statistics/all', {
        data: { confirmation: deleteAllConfirmation.value },
        onSuccess: () => { selectedIds.value = []; deleteAllConfirmation.value = ''; showDeleteAllModal.value = false; },
    });
};
</script>

<template>
    <Head title="Statistiche configuratore" />
    <div class="flex flex-1 flex-col gap-6 p-4">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><h1 class="text-2xl font-semibold">Statistiche configuratore</h1><p class="mt-1 text-sm text-muted-foreground">Dashboard commerciale del configuratore.</p></div>
            <div class="flex flex-wrap gap-2">
                <button class="rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" @click="showAnalysisModal = true">Nueva estadística</button>
                <a :href="exportUrl('pdf')" target="_blank" class="rounded-lg border border-sidebar-border/70 px-4 py-2.5 text-sm hover:bg-accent">PDF</a>
                <a :href="exportUrl('xlsx')" class="rounded-lg border border-sidebar-border/70 px-4 py-2.5 text-sm hover:bg-accent">Excel</a>
                <a :href="exportUrl('csv')" class="rounded-lg border border-sidebar-border/70 px-4 py-2.5 text-sm hover:bg-accent">CSV</a>
                <button class="rounded-lg border border-destructive/50 px-4 py-2.5 text-sm text-destructive hover:bg-destructive/10" @click="showDeleteAllModal = true">Cancella tutte le statistiche</button>
            </div>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article v-for="card in [
                ['Preventivi scaricati', stats.quote_downloaded], ['Click “Proceder al pago”', stats.checkout_clicked], ['Totale eventi', stats.total],
                ['Valore preventivi', money(stats.quote_value)], ['Valore medio', money(stats.average_quote_value)], ['Top marca', stats.top_brand || '—'],
                ['Top modello', stats.top_model || '—'], ['Top prodotto', stats.top_product || '—'],
            ]" :key="String(card[0])" class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">{{ card[0] }}</p><p class="mt-2 truncate text-2xl font-semibold" :title="String(card[1])">{{ card[1] }}</p>
            </article>
            <article class="rounded-xl border border-amber-400/40 bg-amber-400/5 p-5 sm:col-span-2 xl:col-span-4">
                <p class="text-sm text-muted-foreground">Tasso di passaggio al pagamento</p>
                <p class="mt-2 text-3xl font-semibold text-amber-500">{{ stats.payment_progression_rate }}%</p>
                <p class="mt-2 text-xs text-muted-foreground">Click su “Proceder al pago” / preventivi scaricati × 100. Indica il passaggio al pagamento, non ordini completati o vendite.</p>
            </article>
        </section>

        <section class="rounded-xl border border-sidebar-border/70 bg-card p-5">
            <form class="grid gap-3 md:grid-cols-3 xl:grid-cols-6" @submit.prevent="applyFilters">
                <label class="grid gap-1 text-sm md:col-span-2"><span>Ricerca</span><input v-model="filters.search" type="search" class="field" placeholder="Prodotto, variante, CAP…" /></label>
                <label class="grid gap-1 text-sm"><span>Dal</span><input v-model="filters.date_from" type="date" class="field" /></label>
                <label class="grid gap-1 text-sm"><span>Al</span><input v-model="filters.date_to" type="date" class="field" /></label>
                <label class="grid gap-1 text-sm"><span>Evento</span><select v-model="filters.event_type" class="field"><option value="">Tutti</option><option value="quote_downloaded">Preventivo</option><option value="checkout_clicked">Checkout</option></select></label>
                <label class="grid gap-1 text-sm"><span>Tipo prodotto</span><select v-model="filters.product_type" class="field"><option value="">Tutti</option><option v-for="item in filterOptions.productTypes" :key="item" :value="item">{{ item }}</option></select></label>
                <label class="grid gap-1 text-sm"><span>Marca</span><select v-model="filters.brand" class="field"><option value="">Tutte</option><option v-for="item in filterOptions.brands" :key="item" :value="item">{{ item }}</option></select></label>
                <label class="grid gap-1 text-sm"><span>Modello</span><select v-model="filters.model" class="field"><option value="">Tutti</option><option v-for="item in filterOptions.models" :key="item" :value="item">{{ item }}</option></select></label>
                <label class="grid gap-1 text-sm"><span>Installazione</span><select v-model="filters.installation" class="field"><option value="">Tutte</option><option value="yes">Sì</option><option value="no">No</option></select></label>
                <label class="grid gap-1 text-sm"><span>Camera</span><select v-model="filters.camera" class="field"><option value="">Tutte</option><option value="yes">Sì</option><option value="no">No</option></select></label>
                <label class="grid gap-1 text-sm"><span>Fascia prezzo</span><select v-model="filters.price_range" class="field"><option value="">Tutte</option><option v-for="range in ['0-100','100-250','250-500','500-1000','1000+']" :key="range" :value="range">{{ range }} €</option></select></label>
                <label class="grid gap-1 text-sm"><span>Zona</span><select v-model="filters.zone" class="field"><option value="">Tutte</option><option v-for="item in filterOptions.zones" :key="item" :value="item">{{ item }}</option></select></label>
                <label class="grid gap-1 text-sm"><span>Lingua</span><select v-model="filters.language" class="field"><option value="">Tutte</option><option v-for="item in filterOptions.languages" :key="item" :value="item">{{ item.toUpperCase() }}</option></select></label>
                <div class="flex items-end gap-2"><button class="rounded-lg bg-primary px-4 py-2.5 text-sm text-primary-foreground">Applica</button><button type="button" class="rounded-lg border px-4 py-2.5 text-sm" @click="resetFilters">Reset</button></div>
            </form>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <article class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ analysisLabels[analysis.type] }}</h2><p class="text-sm text-muted-foreground">{{ visualizationLabels[analysis.visualization] }} · {{ analysis.counting_mode === 'unique' ? 'Configurazioni uniche' : 'Eventi totali' }}</p></div><button class="text-sm text-amber-500" @click="showAnalysisModal = true">Modifica</button></div>
                <div v-if="analysis.data.length === 0" class="py-16 text-center text-muted-foreground">Nessun dato per questa analisi.</div>
                <div v-else-if="analysis.visualization === 'table'" class="mt-5 overflow-hidden rounded-lg border"><div v-for="item in analysis.data" :key="item.label" class="flex justify-between border-b px-4 py-3 last:border-0"><span>{{ item.label }}</span><strong>{{ item.value }}</strong></div></div>
                <div v-else-if="analysis.visualization === 'bar'" class="mt-6 space-y-3"><div v-for="(item,index) in analysis.data" :key="item.label" class="grid grid-cols-[minmax(100px,180px)_1fr_60px] items-center gap-3 text-sm"><span class="truncate" :title="item.label">{{ item.label }}</span><div class="h-7 overflow-hidden rounded bg-muted"><div class="h-full rounded" :style="{ width: `${Math.max(2,(item.value/maxAnalysisValue)*100)}%`, background: palette[index%palette.length] }"></div></div><strong class="text-right">{{ item.value }}</strong></div></div>
                <div v-else-if="analysis.visualization === 'pie'" class="mt-6 grid items-center gap-6 md:grid-cols-[260px_1fr]"><div class="mx-auto aspect-square w-60 rounded-full" :style="{ background: pieBackground }"></div><div class="space-y-2"><div v-for="(item,index) in analysis.data" :key="item.label" class="flex items-center justify-between gap-4 text-sm"><span class="flex min-w-0 items-center gap-2"><i class="h-3 w-3 shrink-0 rounded-sm" :style="{background:palette[index%palette.length]}"></i><span class="truncate">{{ item.label }}</span></span><strong>{{ item.value }} · {{ analysisTotal ? ((item.value/analysisTotal)*100).toFixed(1) : 0 }}%</strong></div></div></div>
                <div v-else class="mt-6"><svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-72 w-full overflow-visible"><line x1="0" y1="95" x2="100" y2="95" stroke="currentColor" class="text-muted-foreground" stroke-width=".4"/><polyline :points="linePoints" fill="none" stroke="#eab308" stroke-width="2" vector-effect="non-scaling-stroke"/><circle v-for="point in linePoints.split(' ')" :key="point" :cx="point.split(',')[0]" :cy="point.split(',')[1]" r="1.2" fill="#eab308"/></svg><div class="mt-2 flex justify-between text-xs text-muted-foreground"><span>{{ analysis.data[0]?.label }}</span><span>{{ analysis.data.at(-1)?.label }}</span></div></div>
            </article>
            <aside class="rounded-xl border border-sidebar-border/70 bg-card p-5"><h2 class="text-lg font-semibold">Insights</h2><div class="mt-4 space-y-3"><p v-for="insight in insights" :key="insight" class="rounded-lg border border-sidebar-border/70 bg-muted/20 p-3 text-sm leading-6">{{ insight }}</p></div></aside>
        </section>

        <section class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"><p class="text-sm text-muted-foreground">{{ selectedIds.length }} record selezionati</p><button :disabled="selectedIds.length === 0" class="rounded-lg bg-destructive px-4 py-2 text-sm text-destructive-foreground disabled:cursor-not-allowed disabled:opacity-40" @click="deleteSelected">Elimina selezionati</button></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[1650px] text-sm"><thead class="border-b bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th class="px-4 py-3"><input type="checkbox" :checked="allPageSelected" aria-label="Seleziona tutti i record della pagina" @change="togglePageSelection" /></th><th v-for="head in ['Data/Ora','Evento','Marca','Modello','Anno','Tipo','Prodotto','Variante','Prezzo','Valore','Installazione','Camera','CAP','Zona','Lingua','Azioni']" :key="head" class="px-4 py-3">{{ head }}</th></tr></thead><tbody class="divide-y"><tr v-for="event in events.data" :key="event.id" class="align-top hover:bg-muted/20"><td class="px-4 py-3"><input v-model="selectedIds" type="checkbox" :value="event.id" :aria-label="`Seleziona record ${event.id}`" /></td><td class="whitespace-nowrap px-4 py-3">{{ dateTime(event.created_at) }}</td><td class="px-4 py-3">{{ eventLabel(event.event_type) }}</td><td class="px-4 py-3">{{ event.brand||'—' }}</td><td class="px-4 py-3">{{ event.model||'—' }}</td><td class="px-4 py-3">{{ event.year||'—' }}</td><td class="px-4 py-3">{{ event.product_type||'—' }}</td><td class="max-w-64 px-4 py-3">{{ event.product_title||'—' }}</td><td class="max-w-56 px-4 py-3">{{ event.variant_title||'—' }}</td><td class="px-4 py-3">{{ money(event.product_price) }}</td><td class="px-4 py-3 font-semibold">{{ money(event.configuration_value ?? event.product_price) }}</td><td class="max-w-64 px-4 py-3">{{ event.installation_selected?(event.installation_type||'Sì'):'No' }}</td><td class="px-4 py-3">{{ event.camera_selected?'Sì':'No' }}</td><td class="px-4 py-3">{{ event.postal_code||'—' }}</td><td class="px-4 py-3">{{ event.service_zone||'—' }}</td><td class="px-4 py-3 uppercase">{{ event.language||'—' }}</td><td class="px-4 py-3"><button class="text-sm text-destructive hover:underline" @click="deleteEvent(event)">Elimina</button></td></tr><tr v-if="!events.data.length"><td colspan="17" class="py-12 text-center text-muted-foreground">Nessun evento trovato.</td></tr></tbody></table></div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-4"><p class="text-sm text-muted-foreground">{{ events.from??0 }}–{{ events.to??0 }} di {{ events.total }}</p><nav class="flex flex-wrap gap-1"><template v-for="link in events.links" :key="link.label"><Link v-if="link.url" :href="link.url" preserve-state class="rounded border px-3 py-2 text-sm" :class="link.active?'bg-primary text-primary-foreground':'hover:bg-accent'"><span v-html="link.label"></span></Link><span v-else class="rounded border px-3 py-2 text-sm opacity-40" v-html="link.label"></span></template></nav></div>
        </section>
    </div>

    <div v-if="showAnalysisModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showAnalysisModal=false"><section class="w-full max-w-lg rounded-2xl border border-sidebar-border bg-card p-6 shadow-2xl"><div class="flex justify-between"><div><h2 class="text-xl font-semibold">Nueva estadística</h2><p class="mt-1 text-sm text-muted-foreground">Scegli cosa analizzare e come visualizzarlo.</p></div><button @click="showAnalysisModal=false">✕</button></div><div class="mt-6 grid gap-4"><label class="grid gap-2 text-sm"><span>Analisi</span><select v-model="analysisChoice" class="field"><option v-for="(label,key) in analysisLabels" :key="key" :value="key">{{ label }}</option></select></label><label class="grid gap-2 text-sm"><span>Conteggio</span><select v-model="countingModeChoice" class="field"><option value="unique">Configurazioni uniche</option><option value="events">Eventi totali</option></select></label><label class="grid gap-2 text-sm"><span>Visualizzazione</span><select v-model="visualizationChoice" class="field"><option v-for="(label,key) in visualizationLabels" :key="key" :value="key">{{ label }}</option></select></label></div><div class="mt-6 flex justify-end gap-2"><button class="rounded-lg border px-4 py-2.5 text-sm" @click="showAnalysisModal=false">Annulla</button><button class="rounded-lg bg-primary px-4 py-2.5 text-sm text-primary-foreground" @click="applyAnalysis">Analizza</button></div></section></div>
    <div v-if="showDeleteAllModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4" @click.self="showDeleteAllModal=false"><section class="w-full max-w-lg rounded-2xl border border-destructive/50 bg-card p-6 shadow-2xl"><h2 class="text-xl font-semibold text-destructive">Cancella tutte le statistiche</h2><p class="mt-3 text-sm leading-6 text-muted-foreground">Questa operazione elimina definitivamente tutti i record statistici. Per confermare, digita <strong class="text-foreground">CANCELLA</strong>.</p><input v-model="deleteAllConfirmation" class="field mt-5" autocomplete="off" placeholder="CANCELLA" @keyup.enter="deleteAll" /><div class="mt-6 flex justify-end gap-2"><button class="rounded-lg border px-4 py-2.5 text-sm" @click="showDeleteAllModal=false; deleteAllConfirmation=''">Annulla</button><button :disabled="deleteAllConfirmation !== 'CANCELLA'" class="rounded-lg bg-destructive px-4 py-2.5 text-sm text-destructive-foreground disabled:cursor-not-allowed disabled:opacity-40" @click="deleteAll">Cancella definitivamente</button></div></section></div>
</template>

<style scoped>
.field { width: 100%; border: 1px solid color-mix(in oklab, var(--sidebar-border) 70%, transparent); border-radius: .5rem; background: var(--background); padding: .625rem .75rem; }
</style>
