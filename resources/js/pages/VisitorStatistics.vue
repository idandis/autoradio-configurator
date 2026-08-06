<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

type Item = { label: string; value: number };
type Visitor = {
    id: number; created_at: string; country_code: string | null; region: string | null; city: string | null;
    device_type: string | null; language: string | null; referrer: string | null; utm_source: string | null; utm_campaign: string | null;
};

const props = defineProps<{
    visitors: { data: Visitor[]; from: number | null; to: number | null; total: number; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { date_from: string | null; date_to: string | null; country: string; device: string };
    countries: string[];
    stats: { total: number; today: number; last_7_days: number; last_30_days: number };
    analysis: { timeline: Item[]; countries: Item[]; regions: Item[]; cities: Item[]; devices: Item[]; languages: Item[]; sources: Item[] };
}>();

const filters = reactive({ date_from: '', date_to: '', country: '', device: '', ...props.filters });
const applyFilters = () => router.get('/visitor-statistics', Object.fromEntries(Object.entries(filters).filter(([, value]) => value)), { preserveState: true, replace: true });
const resetFilters = () => { Object.assign(filters, { date_from: '', date_to: '', country: '', device: '' }); applyFilters(); };
const dateTime = (value: string) => new Intl.DateTimeFormat('it-IT', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value));
const source = (visitor: Visitor) => {
    if (visitor.utm_source) return visitor.utm_source;
    if (!visitor.referrer) return 'Diretto';
    try { return new URL(visitor.referrer).hostname; } catch { return 'Altro'; }
};
const maxTimeline = computed(() => Math.max(...props.analysis.timeline.map((item) => item.value), 1));
</script>

<template>
    <Head title="Visitatori configuratore" />
    <div class="flex flex-1 flex-col gap-6 p-4">
        <header><h1 class="text-2xl font-semibold">Visitatori</h1><p class="mt-1 text-sm text-muted-foreground">Utenti unici entrati nel configuratore, senza memorizzare indirizzi IP.</p></header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article v-for="card in [['Visitatori unici',stats.total],['Oggi',stats.today],['Ultimi 7 giorni',stats.last_7_days],['Ultimi 30 giorni',stats.last_30_days]]" :key="String(card[0])" class="rounded-xl border border-sidebar-border/70 bg-card p-5"><p class="text-sm text-muted-foreground">{{ card[0] }}</p><p class="mt-2 text-3xl font-semibold">{{ card[1] }}</p></article>
        </section>

        <section class="rounded-xl border border-sidebar-border/70 bg-card p-5">
            <form class="grid gap-3 md:grid-cols-5" @submit.prevent="applyFilters">
                <label class="grid gap-1 text-sm"><span>Dal</span><input v-model="filters.date_from" type="date" class="field" /></label>
                <label class="grid gap-1 text-sm"><span>Al</span><input v-model="filters.date_to" type="date" class="field" /></label>
                <label class="grid gap-1 text-sm"><span>Paese</span><select v-model="filters.country" class="field"><option value="">Tutti</option><option v-for="item in countries" :key="item" :value="item">{{ item }}</option></select></label>
                <label class="grid gap-1 text-sm"><span>Dispositivo</span><select v-model="filters.device" class="field"><option value="">Tutti</option><option value="desktop">Desktop</option><option value="tablet">Tablet</option><option value="mobile">Mobile</option></select></label>
                <div class="flex items-end gap-2"><button class="rounded-lg bg-primary px-4 py-2.5 text-sm text-primary-foreground">Applica</button><button type="button" class="rounded-lg border px-4 py-2.5 text-sm" @click="resetFilters">Reset</button></div>
            </form>
        </section>

        <section class="rounded-xl border border-sidebar-border/70 bg-card p-5"><h2 class="text-lg font-semibold">Nuovi visitatori negli ultimi 30 giorni</h2><div v-if="analysis.timeline.length" class="mt-5 flex h-56 items-end gap-1"><div v-for="item in analysis.timeline" :key="item.label" class="group relative flex min-w-0 flex-1 items-end" :title="`${item.label}: ${item.value}`"><div class="w-full rounded-t bg-amber-500" :style="{height:`${Math.max(4,(item.value/maxTimeline)*100)}%`}"></div></div></div><p v-else class="py-16 text-center text-muted-foreground">Nessun dato disponibile.</p></section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="group in [{title:'Paesi',items:analysis.countries},{title:'Regioni',items:analysis.regions},{title:'Città',items:analysis.cities},{title:'Dispositivi',items:analysis.devices},{title:'Lingue',items:analysis.languages},{title:'Provenienza',items:analysis.sources}]" :key="group.title" class="rounded-xl border border-sidebar-border/70 bg-card p-5"><h2 class="font-semibold">{{ group.title }}</h2><div class="mt-4 space-y-2"><div v-for="item in group.items" :key="item.label" class="flex justify-between gap-3 border-b border-sidebar-border/50 pb-2 text-sm last:border-0"><span class="truncate">{{ item.label }}</span><strong>{{ item.value }}</strong></div><p v-if="!group.items.length" class="text-sm text-muted-foreground">Nessun dato.</p></div></article>
        </section>

        <section class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card"><div class="overflow-x-auto"><table class="w-full min-w-[1050px] text-sm"><thead class="border-b bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th v-for="head in ['Primo ingresso','Paese','Regione','Città','Dispositivo','Lingua','Provenienza','Campagna']" :key="head" class="px-4 py-3">{{ head }}</th></tr></thead><tbody class="divide-y"><tr v-for="visitor in visitors.data" :key="visitor.id"><td class="whitespace-nowrap px-4 py-3">{{ dateTime(visitor.created_at) }}</td><td class="px-4 py-3">{{ visitor.country_code||'—' }}</td><td class="px-4 py-3">{{ visitor.region||'—' }}</td><td class="px-4 py-3">{{ visitor.city||'—' }}</td><td class="px-4 py-3 capitalize">{{ visitor.device_type||'—' }}</td><td class="px-4 py-3 uppercase">{{ visitor.language||'—' }}</td><td class="max-w-60 truncate px-4 py-3">{{ source(visitor) }}</td><td class="px-4 py-3">{{ visitor.utm_campaign||'—' }}</td></tr><tr v-if="!visitors.data.length"><td colspan="8" class="py-12 text-center text-muted-foreground">Nessun visitatore trovato.</td></tr></tbody></table></div><div class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-4"><p class="text-sm text-muted-foreground">{{ visitors.from??0 }}–{{ visitors.to??0 }} di {{ visitors.total }}</p><nav class="flex flex-wrap gap-1"><template v-for="link in visitors.links" :key="link.label"><Link v-if="link.url" :href="link.url" preserve-state class="rounded border px-3 py-2 text-sm" :class="link.active?'bg-primary text-primary-foreground':'hover:bg-accent'"><span v-html="link.label"></span></Link><span v-else class="rounded border px-3 py-2 text-sm opacity-40" v-html="link.label"></span></template></nav></div></section>
    </div>
</template>

<style scoped>.field { width: 100%; border: 1px solid color-mix(in oklab, var(--sidebar-border) 70%, transparent); border-radius: .5rem; background: var(--background); padding: .625rem .75rem; }</style>
