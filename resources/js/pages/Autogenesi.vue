<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import DescriptionEditor from '@/components/DescriptionEditor.vue';
import SquareImageCropper from '@/components/SquareImageCropper.vue';
import ImagePainter from '@/components/ImagePainter.vue';
import ImageCleanupMask from '@/components/ImageCleanupMask.vue';
import ImageTextBand from '@/components/ImageTextBand.vue';
import ProductSilhouette from '@/components/ProductSilhouette.vue';

type Variant = { label: string; sku: string; supplier_price: string; currency: string; selling_price: string | null; pricing?: { markup: string; customs_estimate: string } };
type Product = { id: number; title: string; body_html: string; status: string; brand: string; model: string; year_from: number; year_to: number; source_url: string; variants: Variant[]; images: { id: string; ai_edited: boolean; dashboard_selected: boolean; shopify_file_reference: string; ai_generated: boolean; web_sources: string[]; manually_cropped: boolean; local_only: boolean; url: string; source_url: string | null; role: string; width: number; height: number }[]; metadata: { additional_source_urls?: string[]; vehicle_compatibilities?: { brand: string; model: string; year_from: number; year_to: number }[]; configurator_import?: { product_id: number; imported_at: string }; seller?: string; source_title?: string; warnings?: string[]; captured_at?: string; description_source?: { text: string; specifications: { name: string; value: string }[]; images: unknown[] }; description_generation?: { generated_at: string } } };
type Candidate = { title: string; source_url: string; price_text?: string; verified?: boolean };
type Search = { query: string; origin: string; url: string; mode?: 'links' | 'best_price'; selected?: Candidate | null; inspected_count?: number; vehicle: { brand: string; model: string; year_from: number; year_to: number }; candidates: Candidate[] };
type BatchItem = { id: number; query: string; status: string; source_url: string | null; product_id: number | null; error: string | null };
type Batch = { id: number; status: string; total: number; items: BatchItem[] };
type Detection = { year: number; complete?: boolean; target?: number; researched_at: string; compared_at: string; notes: string; import_list: string; counts: { catalog: number; draft: number; missing: number }; sources: string[]; cars: { brand: string; model: string; market: string; rank: number; units: number | null; evidence: string; source_url: string; status: 'catalog' | 'draft' | 'missing' }[] };
const props = defineProps<{ products: Product[]; local: boolean; detection?: Detection | null; detectionMaxYear: number; search?: Search | null; directImport?: { source_url: string } | null; batches: Batch[] }>();
const showList = ref(false);
const listForm = useForm({ list: '' });
const detectionForm = useForm({ year: props.detection?.year ?? Math.min(2020, props.detectionMaxYear) });
const fillDetectedList = () => { listForm.list = props.detection?.import_list ?? ''; showList.value = !!listForm.list; };
const detectMissing = () => detectionForm.post('/autogenesi/detect-missing', { preserveScroll: true, onSuccess: fillDetectedList });

const fileError = ref('');
const chromeImport = useForm({ package: null as File | null, brand: '', model: '', year_from: '' as number | string, year_to: '' as number | string });
const loadChromePackage = (event: Event) => {
    chromeImport.package = (event.target as HTMLInputElement).files?.[0] ?? null;
    chromeImport.clearErrors('package');
    if (chromeImport.package && chromeImport.package.size > 52 * 1024 * 1024) {
        chromeImport.setError('package', 'File troppo grande: massimo 52 MB.');
        chromeImport.package = null;
    }
};
const importFromChrome = () => chromeImport.post('/autogenesi/import-chrome', { forceFormData: true, preserveScroll: true, onSuccess: () => chromeImport.reset('package') });
const retryingList = ref<number | null>(null);
const itemForm = useForm({});
const stopping = ref(false);
const stopError = ref('');
const stopAll = async () => {
    if (stopping.value) return;
    stopping.value = true;
    stopError.value = '';
    try {
        const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        const response = await fetch('/autogenesi/stop', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf || ''), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        if (!response.ok) throw new Error('Impossibile fermare le ricerche. Riprova.');
        poll.stop();
        router.reload({ only: ['batches', 'products'] });
    } catch (error) { stopError.value = error instanceof Error ? error.message : 'Arresto non completato.'; }
    finally { stopping.value = false; }
};
const pendingItem = ref<number | null>(null);
const batchBusy = computed(() => props.batches.some(b => ['queued', 'running', 'queued_single', 'running_single'].includes(b.status)));
const retryItem = (batch: number, item: number) => {
    if (itemForm.processing || batchBusy.value) return;
    pendingItem.value = item;
    itemForm.post(`/autogenesi/batches/${batch}/items/${item}/retry`, { preserveScroll: true, onFinish: () => { pendingItem.value = null; } });
};
const deletingRows = useForm({});
const removeBatchRows = (batch: Batch, item?: number) => {
    if (deletingRows.processing || itemForm.processing || batchBusy.value || retryingList.value !== null) return;
    deletingRows.delete(item ? `/autogenesi/batches/${batch.id}/items/${item}` : `/autogenesi/batches/${batch.id}/completed`, { preserveScroll: true });
};
const browserForm = useForm({});
const browserOpened = ref(false);
const openBrowser = () => browserForm.post('/autogenesi/browser', { preserveScroll: true, onSuccess: () => { browserOpened.value = true; } });
const batchLabels: Record<string, string> = { queued: 'In coda', running: 'In elaborazione', queued_single: 'Richiesta singola in coda', running_single: 'Richiesta singola in elaborazione', paused: 'In attesa di avvio manuale', stopped: 'Fermata manualmente', searching: 'Ricerca miglior prezzo', importing: 'Importazione in bozza', completed: 'Completata', completed_with_errors: 'Terminata con righe da verificare', not_found: 'Non trovata', failed: 'Errore', blocked: 'Interrotta · verifica richiesta' };
const batchLabel = (status: string) => batchLabels[status] ?? status;
const poll = usePoll(5000, { only: ['batches'] }, { autoStart: false });
watch(() => props.batches.some(b => ['queued', 'running', 'queued_single', 'running_single'].includes(b.status)), active => active ? poll.start() : poll.stop(), { immediate: true });
watch(() => props.batches.flatMap(b => b.items.filter(i => i.status === 'completed').map(i => i.product_id)).join(','), (value, old) => {
    if (value !== old) router.reload({ only: ['products'] });
});
const importList = () => listForm.post('/autogenesi/batches', { preserveScroll: true, onSuccess: () => { listForm.reset(); showList.value = false; } });
const loadList = async (event: Event) => {
    fileError.value = '';
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    if (file.size > 30000) { fileError.value = 'Il file deve essere inferiore a 30 KB.'; return; }
    try { listForm.list = await file.text(); } catch { fileError.value = 'File non leggibile: incolla la lista nel campo.'; }
};
const retryList = (id: number) => {
    if (retryingList.value !== null) return;
    retryingList.value = id;
    router.post(`/autogenesi/batches/${id}/retry`, {}, { preserveScroll: true, onFinish: () => { retryingList.value = null; } });
};
const openBatchProduct = (id: number) => {
    const p = props.products.find(p => p.id === id);
    if (p) { open(p); nextTick(() => document.getElementById('autogenesi-review')?.scrollIntoView({ behavior: 'smooth' })); }
};
const searching = useForm({ query: props.directImport?.source_url ?? props.search?.query ?? '', mode: 'links' as 'links' | 'best_price' });
const queryInput = ref<HTMLInputElement | null>(null);
const importForm = ref<HTMLFormElement | null>(null);
const removing = ref(false);
const imageAi = useForm({ image_id: '', points: [] as number[][] });
const sceneTarget = ref<{ index: number; url: string } | null>(null);
const applyScene = (points: number[][]) => {
    if (!selected.value || !sceneTarget.value || imageAi.processing) return;
    imageAi.points = points;
    imageAi.transform(data => data).post(`/autogenesi/${selected.value.id}/images/${sceneTarget.value.index}/ai`, { preserveScroll: true, onSuccess: () => { sceneTarget.value = null; imageAi.points = []; } });
};
const applyAutomaticScene = (mask: File) => {
    if (!selected.value || !sceneTarget.value || imageAi.processing) return;
    imageAi.transform(data => ({ image_id: data.image_id, cutout_mode: 'auto', mask })).post(`/autogenesi/${selected.value.id}/images/${sceneTarget.value.index}/ai`, {
        forceFormData: true, preserveScroll: true, onSuccess: () => { sceneTarget.value = null; } });
};
const layout = useForm({});
const dashboard = useForm({});
const generateDashboard = () => {
    if (!selected.value || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    dashboard.post(`/autogenesi/${selected.value.id}/dashboard-image`, { preserveScroll: true });
};
const uploading = useForm({ photos: [] as File[], role: 'prodotto' });
const uploadKey = ref(0);
const loadPhotos = (event: Event) => {
    uploading.clearErrors();
    uploading.photos = Array.from((event.target as HTMLInputElement).files || []);
    if (uploading.photos.length > 10 || uploading.photos.some(f => f.size > 8 * 1024 * 1024) || uploading.photos.reduce((n, f) => n + f.size, 0) > 60 * 1024 * 1024) {
        uploading.setError('photos', 'Massimo 10 foto, 8 MB ciascuna e 60 MB totali.');
        uploading.photos = [];
    }
};
const uploadPhotos = () => {
    if (!selected.value || !uploading.photos.length || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || removing.value) return;
    uploading.post(`/autogenesi/${selected.value.id}/images`, { forceFormData: true, preserveScroll: true, onSuccess: () => { uploading.reset('photos'); uploadKey.value++; } });
};
const imageAction = ref<'ai' | 'restore' | 'white' | 'clean'>('ai');
const cropTarget = ref<{ index: number; id: string; url: string } | null>(null);
const cropping = useForm({ image_id: '', x: 0, y: 0, size: 0, width: 0, height: 0 });
const startCrop = (index: number, id: string, url: string) => {
    if (dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    cropping.clearErrors();
    cropTarget.value = { index, id, url };
};
const paintTarget = ref<{ index: number; id: string; url: string } | null>(null);
const painting = useForm({ image_id: '', photo: null as File | null });
const cleanup = useForm({ image_id: '', mask: null as File | null, strength: 12 });
const applyCleanup = ({ mask, strength }: { mask: File; strength: number }) => {
    if (!selected.value || !paintTarget.value || cleanup.processing) return;
    cleanup.image_id = paintTarget.value.id; cleanup.mask = mask; cleanup.strength = strength;
    cleanup.post(`/autogenesi/${selected.value.id}/images/${paintTarget.value.index}/cleanup-text`, { forceFormData: true, preserveScroll: true, onSuccess: () => { paintTarget.value = null; cleanup.reset(); } });
};
const paintMode = ref<'paint' | 'text' | 'cover'>('paint');
const startPaint = (index: number, id: string, url: string, mode: 'paint' | 'text' | 'cover' = 'paint') => {
    paintMode.value = mode;
    painting.clearErrors(); cleanup.clearErrors();
    paintTarget.value = { index, id, url };
};
const applyPaint = (file: File) => {
    if (!selected.value || !paintTarget.value || painting.processing) return;
    painting.image_id = paintTarget.value.id;
    painting.photo = file;
    painting.post(`/autogenesi/${selected.value.id}/images/${paintTarget.value.index}/paint`, { forceFormData: true, preserveScroll: true,
        onSuccess: () => { paintTarget.value = null; painting.reset(); } });
};
const applyCrop = (rect: { x: number; y: number; size: number; width: number; height: number }) => {
    if (!selected.value || !cropTarget.value) return;
    Object.assign(cropping, rect, { image_id: cropTarget.value.id });
    cropping.post(`/autogenesi/${selected.value.id}/images/${cropTarget.value.index}/crop`, { preserveScroll: true, onSuccess: () => { cropTarget.value = null; } });
};
const editImage = (index: number, id: string, action: 'ai' | 'restore' | 'white' | 'clean' = 'ai') => {
    if (!selected.value || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    imageAi.image_id = id;
    imageAi.points = [];
    imageAction.value = action;
    imageAi.clearErrors();
    if (action === 'ai') { sceneTarget.value = { index, url: selected.value.images[index].url }; return; }
    imageAi.transform(({ points, ...data }) => data).post(`/autogenesi/${selected.value.id}/images/${index}/${action}`, { preserveScroll: true });
};
const searchProducts = (mode: 'links' | 'best_price') => {
    if (searching.processing || !queryInput.value?.reportValidity()) return;
    searching.mode = mode;
    if (mode === 'best_price') importing.source_url = '';
    searching.post('/autogenesi/search', { preserveScroll: true, onSuccess: page => {
        const direct = page.props.directImport as { source_url: string } | null;
        if (direct) {
            Object.assign(importing, { source_url: direct.source_url, brand: '', model: '', year_from: '', year_to: '' });
            importing.clearErrors();
            nextTick(() => {
                importForm.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                importForm.value?.querySelector<HTMLInputElement>('input[name="brand"]')?.focus({ preventScroll: true });
            });
            return;
        }
        const result = page.props.search as Search | null;
        if (mode === 'best_price' && result?.selected) {
            Object.assign(importing, result.vehicle, { source_url: result.selected.source_url });
            nextTick(() => importForm.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        }
    } });
};
const clearSearch = () => {
    const selectedUrl = props.search?.selected?.source_url;
    router.delete('/autogenesi/search', { preserveScroll: true, onSuccess: () => {
        searching.query = '';
        searching.clearErrors();
        if (selectedUrl && importing.source_url === selectedUrl) importing.source_url = '';
    } });
};
const choose = (url: string) => {
    if (!props.search) return;
    Object.assign(importing, props.search.vehicle, { source_url: url });
};
const deleteProduct = (p: Product) => {
    if (!window.confirm(`Eliminare «${p.title}» e le relative foto ed esportazioni?`)) return;
    router.delete(`/autogenesi/${p.id}`, { preserveScroll: true, onSuccess: () => { if (selectedId.value === p.id) selectedId.value = null; } });
};
const selectedId = ref<number | null>(null);
const selected = computed(() => props.products.find(p => p.id === selectedId.value));
const automatic = props.search?.mode === 'best_price' && props.search.selected ? props.search : null;
const importing = useForm({ source_url: props.directImport?.source_url ?? automatic?.selected?.source_url ?? '', brand: automatic?.vehicle.brand ?? '', model: automatic?.vehicle.model ?? '', year_from: (automatic?.vehicle.year_from ?? '') as number | string, year_to: (automatic?.vehicle.year_to ?? '') as number | string });
const editing = useForm({ title: '', body_html: '', additional_source_urls: [] as string[], vehicles: [] as { brand: string; model: string; year_from: number; year_to: number }[], variants: [] as { sku: string; label: string; selling_price: string | null }[] });
const open = (p: Product) => {
    if (selectedId.value !== p.id) { uploading.reset(); uploading.clearErrors(); uploadKey.value++; }
    selectedId.value = p.id;
    removingVariant.clearErrors();
    editing.additional_source_urls = [...(p.metadata.additional_source_urls ?? [])];
    editing.title = p.title;
    editing.body_html = p.body_html;
    const brands = p.brand.split(' | ');
    editing.vehicles = p.metadata.vehicle_compatibilities?.length
        ? p.metadata.vehicle_compatibilities.map(v => ({ brand: v.brand, model: v.model, year_from: v.year_from, year_to: v.year_to }))
        : p.model.split(' | ').flatMap(value => {
            const match = value.match(/^\s*(\d+)\s*[:：]\s*(.+)$/);
            return (match ? [brands[Number(match[1]) - 1] || ''] : brands).map(brand => ({ brand, model: match ? match[2] : value, year_from: p.year_from, year_to: p.year_to }));
        });
    editing.variants = p.variants.map(v => ({ sku: v.sku, label: v.label, selling_price: v.selling_price === null ? null : String(Math.ceil(Number(v.selling_price))) }));
    editing.clearErrors();
};
const removingVariant = useForm({ sku: '' });
const deleteVariant = (sku: string) => {
    if (!selected.value || editing.processing || removingVariant.processing || selected.value.variants.length < 2) return;
    removingVariant.sku = sku;
    removingVariant.delete(`/autogenesi/${selected.value.id}/variants`, { preserveScroll: true, onSuccess: () => {
        const edits = new Map(editing.variants.map(v => [v.sku, v]));
        editing.variants = (selected.value?.variants ?? []).map(v => edits.get(v.sku) ?? { sku: v.sku, label: v.label, selling_price: v.selling_price });
    } });
};
const catalogImport = useForm({});
const catalogAll = useForm({});
const importAllConfigurator = () => {
    if (catalogAll.processing || catalogImport.processing || editing.processing || layout.processing || imageAi.processing || dashboard.processing || cropping.processing || uploading.processing || removing.value) return;
    const run = () => catalogAll.post('/autogenesi/import-all-configurator', { preserveScroll: true, onSuccess: () => { selectedId.value = null; } });
    if (selected.value) editing.patch(`/autogenesi/${selected.value.id}`, { preserveScroll: true, onSuccess: run });
    else run();
};
const importConfigurator = () => {
    if (!selected.value || editing.processing || catalogImport.processing || removingVariant.processing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing) return;
    const id = selected.value.id;
    catalogImport.clearErrors();
    editing.patch(`/autogenesi/${id}`, { preserveScroll: true, onSuccess: () => {
        if (selected.value?.id === id) open(selected.value);
        catalogImport.post(`/autogenesi/${id}/import-configurator`, { preserveScroll: true });
    } });
};
const save = () => selected.value && editing.patch(`/autogenesi/${selected.value.id}`, { preserveScroll: true, onSuccess: () => { if (selected.value) open(selected.value); } });
const readyToImport = computed(() => Boolean(importing.source_url.trim() && importing.brand.trim() && importing.model.trim()
    && Number.isInteger(Number(importing.year_from)) && Number(importing.year_from) > 0
    && Number.isInteger(Number(importing.year_to)) && Number(importing.year_to) >= Number(importing.year_from)));
const submitPreparedImport = () => { if (!importing.processing) importForm.value?.requestSubmit(); };
const importProduct = () => importing.post('/autogenesi/import', { preserveScroll: true, onSuccess: () => importing.reset('source_url') });
const removeImage = (index: number, id: string) => {
    if (!selected.value || removing.value || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing) return;
    removing.value = true;
    router.delete(`/autogenesi/${selected.value.id}/images/${index}`, { data: { image_id: id }, preserveScroll: true, onFinish: () => { removing.value = false; } });
};
const changeLayout = (data: { order?: string[]; dashboard_image_id?: string | null; dashboard_image_ids?: string[]; reference_image_id?: string; shopify_file_reference?: string | null }) => {
    if (!selected.value || layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    layout.transform(() => data).patch(`/autogenesi/${selected.value.id}/images/layout`, { preserveScroll: true });
};
const moveImage = (index: number, step: number) => {
    if (!selected.value || index + step < 0 || index + step >= selected.value.images.length) return;
    const order = selected.value.images.map(image => image.id);
    [order[index], order[index + step]] = [order[index + step], order[index]];
    changeLayout({ order });
};
const dashboardReference = (id: string, event: Event) => changeLayout({ reference_image_id: id, shopify_file_reference: (event.target as HTMLInputElement).value.trim() || null });
const money = (price: string) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(Number(price));
const imageRoles: Record<string, string> = { prodotto: 'Foto prodotto', pubblicitaria: 'Pubblicitaria', cruscotto_da_verificare: 'Cruscotto da verificare', cruscotto_originale: 'Cruscotto originale', cruscotto_prima_dopo: 'Prima / dopo installazione', accessori: 'Accessori' };
const imageRole = (role: string) => imageRoles[role] ?? 'Da verificare';
</script>

<template>
    <Head title="AutoGenesi · Bozze" />
    <div class="space-y-6 p-4 md:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-2xl font-semibold">AutoGenesi</h1><p class="mt-2 text-sm text-muted-foreground">Prodotti importati in bozza, visibili soltanto agli amministratori. Rivedi foto, caratteristiche e prezzi prima dell’importazione finale.</p></div><div class="flex flex-wrap gap-3"><a v-if="products.some(p => p.status === 'draft')" href="/autogenesi/export-all" class="rounded-lg border px-4 py-2 text-sm font-medium">Esporta tutte le bozze</a><button v-if="local && products.length" type="button" :disabled="catalogAll.processing || catalogImport.processing || editing.processing || layout.processing || imageAi.processing || dashboard.processing || cropping.processing || uploading.processing || removing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50" @click="importAllConfigurator">{{ catalogAll.processing ? 'Importazione di tutte le bozze…' : 'Importa tutte le bozze nel configuratore' }}</button><button v-if="local" type="button" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background" @click="showList = !showList">Importa lista</button></div></div>
        <section v-if="local" class="space-y-4 rounded-xl border bg-card p-5">
            <h2 class="text-lg font-semibold">Rilevamento autoradio mancanti per anno</h2>
            <p class="text-sm text-muted-foreground">Ricerca web fino a 50 auto più vendute dell’anno: priorità alla classifica annuale spagnola, integrata con quella europea se necessario. Confronto con marca, modello e anni del catalogo e delle bozze, poi compilazione automatica di Importa lista.</p>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="detectMissing">
                <label class="text-sm">Anno<input v-model.number="detectionForm.year" required type="number" min="1900" :max="detectionMaxYear" class="mt-1 block w-32 rounded border bg-background px-3 py-2" :disabled="detectionForm.processing" /></label>
                <button type="submit" :disabled="detectionForm.processing || listForm.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm text-background disabled:opacity-50">{{ detectionForm.processing ? 'Ricerca e confronto in corso…' : 'Rileva le autoradio mancanti' }}</button>
            </form>
            <p v-if="detectionForm.processing" class="text-sm text-muted-foreground">La ricerca delle fonti può richiedere alcuni minuti. Mantieni aperta la pagina.</p>
            <p v-for="(error, key) in detectionForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div v-if="detection" class="space-y-3">
                <p class="text-sm font-medium">Anno {{ detection.year }} · {{ detection.cars.length }} modelli · {{ detection.counts.catalog }} già nel catalogo · {{ detection.counts.draft }} già in bozza · {{ detection.counts.missing }} mancanti</p>
                <p v-if="detection.cars.length < 50" class="text-sm text-amber-600">Ricerca parziale: {{ detection.cars.length }} modelli con riferimenti utilizzabili su 50 richiesti. Il confronto e la lista dei mancanti includono solo questi modelli.</p>
                <p v-if="detection.notes" class="text-xs text-muted-foreground">{{ detection.notes }}</p>
                <button v-if="detection.import_list" type="button" class="rounded border px-3 py-2 text-sm" @click="fillDetectedList">Mostra i mancanti in Importa lista</button>
                <p v-else class="text-sm text-emerald-600">Tutti i modelli rilevati sono già presenti per l’anno selezionato.</p>
                <details class="rounded border p-3"><summary class="cursor-pointer text-sm font-medium">Elenco dei {{ detection.cars.length }} modelli e fonti</summary>
                    <div class="mt-3 max-h-96 overflow-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Marca / modello</th><th class="p-2">Mercato / posizione</th><th class="p-2">Immatricolazioni</th><th class="p-2">Stato</th><th class="p-2">Fonte</th></tr></thead><tbody>
                        <tr v-for="car in detection.cars" :key="`${car.brand}-${car.model}`" class="border-t"><td class="p-2">{{ car.brand }} {{ car.model }}</td><td class="p-2">{{ car.market }} · {{ car.rank }}</td><td class="p-2">{{ car.units?.toLocaleString('it-IT') ?? 'Non disponibile' }}</td><td class="p-2" :class="car.status === 'missing' ? 'text-amber-600' : 'text-emerald-600'">{{ car.status === 'catalog' ? 'Nel catalogo' : car.status === 'draft' ? 'In bozza' : 'Mancante' }}</td><td class="p-2"><a :href="car.source_url" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline" :title="car.evidence">Apri fonte ↗</a></td></tr>
                    </tbody></table></div>
                </details>
            </div>
        </section>
        <p v-for="(error, key) in catalogAll.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="products.length" class="text-sm text-muted-foreground">Prima dell’importazione in Shopify, trasferisci con FileZilla anche storage/app/public/autogenesi/catalog/ su Aruba: contiene le foto corrispondenti agli URL dell’Excel.</p>
        <form v-if="local && showList" class="space-y-4 rounded-xl border bg-card p-5" @submit.prevent="importList">
            <h2 class="font-semibold">Importa lista di vetture</h2>
            <p class="text-sm text-muted-foreground">Una vettura per riga, massimo 100. Ricerca miglior prezzo prima in UE, poi Tutti; importazione automatica in bozza per ogni prodotto trovato.</p>
            <label class="block text-sm">Marca, modello e anni<textarea v-model="listForm.list" required maxlength="30000" rows="7" placeholder="Hyundai Kona 2017 2020&#10;Ford Focus 2011 2018&#10;Peugeot 2008 2013 2019" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
            <label class="block text-sm">Oppure carica CSV/TXT<input type="file" accept=".csv,.txt,text/csv,text/plain" class="mt-1 block w-full text-sm" @change="loadList" /></label>
            <p class="text-xs text-muted-foreground">CSV: Marca;Modello;Dal;Al. È accettato anche un solo anno. Puoi chiudere questa pagina: il lavoro continua in background sul Mac.</p>
            <p v-if="fileError" role="alert" class="text-sm text-red-600">{{ fileError }}</p>
            <p v-for="(error, key) in listForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <button :disabled="listForm.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50">{{ listForm.processing ? 'Avvio lista…' : 'Avvia importazione in background' }}</button>
        </form>
        <div v-if="local" class="flex flex-wrap items-center gap-3">
            <button type="button" :disabled="stopping" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="stopAll">{{ stopping ? 'Arresto in corso…' : 'Ferma tutto e chiudi browser' }}</button>
            <span class="text-xs text-muted-foreground">Interrompe le ricerche attive e in coda. Le bozze già importate restano salvate.</span>
            <p v-if="stopError" role="alert" class="text-sm text-red-600">{{ stopError }}</p>
        </div>
        <section v-if="batches.length" class="space-y-3">
            <h2 class="font-semibold">Importazioni da lista</h2>
            <p v-for="(error, key) in deletingRows.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p v-for="(error, key) in browserForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="browserOpened" class="text-sm">Completa personalmente la verifica nella finestra Chrome AutoGenesi e lasciala aperta. Poi avvia una singola richiesta oppure riprendi tutta la lista.</p>
            <details v-for="(batch, n) in batches" :key="batch.id" :open="n === 0" class="rounded-xl border bg-card p-4">
                <summary class="cursor-pointer text-sm font-medium">Lista #{{ batch.id }} · {{ batchLabel(batch.status) }} · {{ batch.items.filter(i => i.status === 'completed').length }}/{{ batch.total }} importate</summary>
                <div class="mt-3 max-h-96 overflow-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Vettura</th><th class="p-2">Stato</th><th class="p-2">Risultato</th><th class="p-2">Azione</th></tr></thead><tbody><tr v-for="item in batch.items" :key="item.id" class="border-t"><td class="p-2">{{ item.query.replace(/^radio /i, '') }}</td><td class="p-2">{{ batchLabel(item.status) }}</td><td class="space-y-1 p-2"><p v-if="item.error" class="text-amber-700">{{ item.error }}</p><button v-if="item.product_id" type="button" class="mr-3 text-blue-600 underline" @click="openBatchProduct(item.product_id)">Apri bozza #{{ item.product_id }}</button><a v-if="item.source_url" :href="item.source_url" target="_blank" rel="noreferrer" class="text-blue-600 underline">Prodotto AliExpress</a></td><td class="p-2"><button v-if="local && ['queued', 'failed', 'not_found', 'blocked', 'stopped'].includes(item.status)" type="button" :disabled="batchBusy || itemForm.processing || retryingList !== null" class="whitespace-nowrap rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="retryItem(batch.id, item.id)">{{ pendingItem === item.id ? 'Avvio…' : 'Avvia solo questa' }}</button><button v-if="local" type="button" :disabled="batchBusy || deletingRows.processing || itemForm.processing || retryingList !== null" class="ml-2 rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 disabled:opacity-50" @click="removeBatchRows(batch, item.id)">Cancella</button></td></tr></tbody></table></div>
                <button v-if="local && batch.items.some(item => item.status === 'completed')" type="button" :disabled="batchBusy || deletingRows.processing || itemForm.processing || retryingList !== null" class="mt-3 rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 disabled:opacity-50" @click="removeBatchRows(batch)">Cancella righe completate</button>
                <div v-if="local && ['blocked', 'completed_with_errors', 'paused', 'stopped'].includes(batch.status)" class="mt-3 flex flex-wrap gap-3">
                    <button v-if="batch.status === 'blocked'" type="button" :disabled="browserForm.processing" class="rounded-lg bg-foreground px-3 py-2 text-sm text-background disabled:opacity-50" @click="openBrowser">{{ browserForm.processing ? 'Apertura browser…' : 'Apri browser per verifica AliExpress' }}</button>
                    <button type="button" :disabled="retryingList !== null || browserForm.processing || itemForm.processing || batchBusy" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="retryList(batch.id)">{{ batch.status === 'blocked' ? 'Verifica completata: riprendi lista' : 'Riprova righe non completate' }}</button>
                </div>
            </details>
        </section>
        <form v-if="local" class="space-y-3 rounded-xl border bg-card p-5" @submit.prevent="searchProducts('links')">
            <h2 class="font-semibold">Cerca autoradio su AliExpress</h2>
            <label class="block text-sm">Testo di ricerca o link prodotto<input ref="queryInput" v-model="searching.query" required maxlength="2048" placeholder="radio Hyundai KONA 2017 oppure https://es.aliexpress.com/item/…html" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
            <p class="text-xs text-muted-foreground">Con un link prodotto prepari direttamente l’importazione. Per le ricerche: prima Unione Europea, poi Tutti se mancano candidati pertinenti. I risultati sono candidati: compatibilità e dotazione restano da verificare.</p>
            <p v-for="(error, key) in searching.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex flex-wrap gap-3">
                <button :disabled="searching.processing" class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50">{{ searching.processing && searching.mode === 'links' ? 'Ricerca links in corso…' : 'Cerca links' }}</button>
                <button type="button" :disabled="searching.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50" @click="searchProducts('best_price')">{{ searching.processing && searching.mode === 'best_price' ? 'Verifica prodotti in ordine di prezzo…' : 'Cerca miglior prezzo' }}</button>
            </div>
        </form>
        <section v-if="search" class="space-y-3 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">{{ search.query }} · {{ search.origin === 'EU' ? 'Unione Europea' : 'Tutti' }}</h2><button type="button" :disabled="searching.processing" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="clearSearch">Cancella ricerca</button></div>
            <a :href="search.url" target="_blank" rel="noreferrer" class="text-sm text-blue-600 underline">Apri ricerca AliExpress</a>
            <div v-if="search.mode === 'best_price'" class="space-y-2 text-sm">
                <template v-if="search.selected"><p class="font-medium">Prodotto selezionato: {{ search.selected.title }}</p><a :href="search.selected.source_url" target="_blank" rel="noreferrer" class="text-blue-600 underline">Verifica prodotto AliExpress</a><p>{{ search.selected.price_text }} · Prezzo della variante esaminata.</p><div v-if="importing.source_url === search.selected.source_url && readyToImport" class="flex flex-wrap items-center gap-3 rounded-lg border border-emerald-500 bg-emerald-500/10 p-3"><span class="font-semibold text-emerald-600 dark:text-emerald-400">Prodotto pronto da importare</span><button type="button" :disabled="importing.processing" class="rounded-lg bg-emerald-600 px-5 py-3 font-semibold text-white shadow hover:bg-emerald-700 disabled:opacity-50" @click="submitPreparedImport">{{ importing.processing ? 'Importazione in corso…' : 'Importa come bozza' }}</button></div><p v-else>Completa i dati nel modulo per importare il prodotto come bozza.</p></template>
                <p v-else>Nessun prodotto specifico verificato nelle pagine analizzate. Prova a modificare marca, modello o anni.</p>
                <p class="text-xs text-muted-foreground">Schede esaminate: {{ search.inspected_count || 0 }}. Selezione del primo prodotto utile nell’ordine di prezzo AliExpress; prezzo e disponibilità delle varianti vengono riletti all’importazione.</p>
            </div>
            <p v-else-if="!search.candidates.length" class="text-sm text-muted-foreground">Nessun candidato specifico trovato nella pagina analizzata. Verifica modello e anni o modifica la ricerca.</p>
            <div v-for="c in (search.mode === 'best_price' ? [] : search.candidates)" :key="c.source_url" class="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                <a :href="c.source_url" target="_blank" rel="noreferrer" class="max-w-3xl text-sm text-blue-600 underline">{{ c.title }}</a>
                <button type="button" class="rounded-lg border px-3 py-2 text-sm" @click="choose(c.source_url)">Usa per importazione</button>
            </div>
        </section>
        <details v-if="local" class="space-y-3 rounded-xl border bg-card p-5">
            <summary class="cursor-pointer font-semibold">Importa dal tuo Chrome · nuovo metodo</summary>
            <p class="mt-3 text-sm">Usa la scheda prodotto già aperta nel tuo Chrome. L’estensione acquisisce il prodotto e scarica un file; caricalo qui per creare la bozza.</p>
            <ol class="list-inside list-decimal space-y-2 text-sm">
                <li><a href="/autogenesi/chrome-extension" class="text-blue-600 underline">Scarica l’estensione ZIP</a> ed estraila.</li>
                <li>Apri <code>chrome://extensions</code>, attiva «Modalità sviluppatore», premi «Carica estensione non pacchettizzata» e scegli la cartella estratta.</li>
                <li>Apri il prodotto in euro (AliExpress o altro negozio con dati strutturati), espandi descrizione e specifiche. Per altri siti premi prima «Abilita altri siti» nel popup. Premi l’icona AutoGenesi → «Acquisisci questo prodotto».</li>
                <li>Carica il file JSON scaricato e indica la vettura compatibile.</li>
            </ol>
            <p class="text-xs text-muted-foreground">Per fermare l’estensione chiudi la scheda prodotto. «Ferma tutto» controlla i processi locali di AutoGenesi.</p>
            <form class="space-y-3 pt-3" @submit.prevent="importFromChrome">
                <input type="file" accept=".json,application/json" required class="block w-full text-sm" @change="loadChromePackage" />
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">Marca<input v-model="chromeImport.brand" required class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                    <label class="text-sm">Modello<input v-model="chromeImport.model" required class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                    <label class="text-sm">Dal<input v-model.number="chromeImport.year_from" required type="number" min="1" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                    <label class="text-sm">Al<input v-model.number="chromeImport.year_to" required type="number" :min="chromeImport.year_from || 1" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                </div>
                <p v-for="(error, key) in chromeImport.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <button :disabled="chromeImport.processing || !chromeImport.package" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50">{{ chromeImport.processing ? 'Lettura immagini e generazione scheda…' : 'Importa acquisizione come bozza' }}</button>
            </form>
        </details>
        <form v-if="local" ref="importForm" class="space-y-3 rounded-xl border p-5 transition-colors" :class="readyToImport ? 'border-yellow-400/60 bg-yellow-400/10 ring-1 ring-yellow-400/20' : 'bg-card'" @submit.prevent="importProduct">
            <h2 class="font-semibold">Importa da una scheda AliExpress</h2>
            <a href="/autogenesi/import-log" class="inline-block text-sm underline underline-offset-2">Scarica log ultima importazione</a><p class="text-xs text-muted-foreground">Il log viene azzerato automaticamente a ogni nuovo tentativo di importazione.</p>
            <p v-if="directImport" class="text-sm">Link riconosciuto. Completa marca, modello e anni compatibili, poi premi «Importa come bozza».</p>
            <label class="block text-sm">Link prodotto AliExpress<input v-model="importing.source_url" required type="url" placeholder="https://es.aliexpress.com/item/…html" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
            <div class="grid gap-3 sm:grid-cols-4">
                <label class="text-sm">Marca<input name="brand" v-model="importing.brand" required class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                <label class="text-sm">Modello<input v-model="importing.model" required class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                <label class="text-sm">Dal<input v-model.number="importing.year_from" required type="number" min="1" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                <label class="text-sm">Al<input v-model.number="importing.year_to" required type="number" :min="importing.year_from" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
            </div>
            <p v-for="(error, key) in importing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="readyToImport && !importing.processing" class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">Prodotto pronto: premi il pulsante verde per importarlo.</p>
            <button :disabled="importing.processing" class="rounded-lg px-5 py-3 text-sm font-semibold transition-colors disabled:opacity-50" :class="readyToImport ? 'bg-emerald-600 text-white shadow ring-2 ring-emerald-500/40 ring-offset-2 ring-offset-background hover:bg-emerald-700' : 'bg-foreground text-background'">{{ importing.processing ? 'Raccolta varianti e immagini in corso…' : 'Importa come bozza' }}</button>
        </form>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted"><tr><th class="p-3">Prodotto</th><th class="p-3">Auto / anni</th><th class="p-3">Stato</th><th class="p-3">Immagini</th><th class="p-3">Varianti</th><th class="p-3">Link prodotto</th><th class="p-3">Azioni</th></tr></thead>
                <tbody><tr v-for="p in products" :key="p.id" class="border-t"><td class="p-3"><button class="text-left font-medium underline underline-offset-4" @click="open(p)">{{ p.title }}</button></td><td class="p-3"><template v-if="p.metadata.vehicle_compatibilities?.length"><p v-for="(vehicle, i) in p.metadata.vehicle_compatibilities" :key="i">{{ vehicle.brand }} {{ vehicle.model }}<br />{{ vehicle.year_from }}–{{ vehicle.year_to }}</p></template><template v-else>{{ p.brand }} {{ p.model }}<br />{{ p.year_from }}–{{ p.year_to }}</template></td><td class="p-3"><span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-900">{{ p.metadata.configurator_import ? 'Importata nel configuratore' : 'Bozza · non visibile' }}</span></td><td class="p-3">{{ p.images.length }}</td><td class="p-3">{{ p.variants.length }}</td><td class="p-3"><a :href="p.source_url" class="break-all text-blue-600 underline" rel="noreferrer">{{ p.source_url }}</a><a v-for="url in p.metadata.additional_source_urls || []" :key="url" :href="url" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-blue-600 underline">{{ url }} ↗</a></td><td class="p-3"><button type="button" class="text-red-600 underline" @click="deleteProduct(p)">Cancella prodotto</button></td></tr><tr v-if="!products.length"><td colspan="7" class="p-6 text-muted-foreground">Nessuna bozza importata.</td></tr></tbody>
            </table>
        </div>
        <section v-if="selected" id="autogenesi-review" class="space-y-5 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">Verifica bozza #{{ selected.id }}</h2><a :href="selected.source_url" class="text-sm text-blue-600 underline" rel="noreferrer">Verifica sul sito originale</a></div>
            <p class="text-sm text-muted-foreground">{{ selected.metadata.seller || 'Venditore da verificare' }} · {{ selected.metadata.captured_at }}</p>
            <a v-if="selected.source_url" :href="selected.source_url" target="_blank" rel="noopener noreferrer" class="block break-all text-sm text-blue-600 underline underline-offset-2">{{ selected.source_url }} ↗</a>
            <form class="space-y-3 rounded-lg border p-4" @submit.prevent="save">
                <h3 class="text-sm font-semibold">Altri link del prodotto</h3>
                <div v-for="(_, i) in editing.additional_source_urls" :key="i" class="flex flex-wrap items-center gap-2">
                    <input v-model="editing.additional_source_urls[i]" type="url" maxlength="4096" placeholder="https://…" aria-label="Link prodotto aggiuntivo" class="min-w-0 flex-1 rounded border bg-background px-3 py-2 text-sm" :disabled="editing.processing" />
                    <a v-if="/^https?:\/\//i.test(editing.additional_source_urls[i])" :href="editing.additional_source_urls[i]" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600 underline">Apri ↗</a>
                    <button type="button" class="rounded border px-3 py-2 text-sm text-red-600" :disabled="editing.processing" @click="editing.additional_source_urls.splice(i, 1)">Rimuovi link</button>
                </div>
                <p v-for="(error, key) in Object.fromEntries(Object.entries(editing.errors).filter(([key]) => key.startsWith('additional_source_urls')))" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3"><button type="button" class="rounded border px-3 py-2 text-sm" :disabled="editing.processing || editing.additional_source_urls.length >= 30" @click="editing.additional_source_urls.push('')">Aggiungi link</button><button type="submit" class="rounded bg-foreground px-3 py-2 text-sm text-background" :disabled="editing.processing">Salva link</button></div>
            </form>
            <details v-if="selected.metadata.description_source" class="rounded-lg border p-3 text-sm">
                <summary class="cursor-pointer font-medium">Testo originale e specifiche lette · {{ selected.metadata.description_source.specifications.length }} voci · {{ selected.metadata.description_source.images.length }} immagini della descrizione</summary>
                <p class="mt-3 whitespace-pre-line">{{ selected.metadata.description_source.text }}</p>
                <dl class="mt-3 grid gap-2 sm:grid-cols-2"><div v-for="(spec, i) in selected.metadata.description_source.specifications" :key="i"><dt class="font-medium">{{ spec.name }}</dt><dd>{{ spec.value }}</dd></div></dl>
            </details>
            <section class="space-y-3 rounded-lg border p-4">
                <h3 class="font-semibold">Auto compatibili · marca, modello e anni</h3>
                <div v-for="(vehicle, i) in editing.vehicles" :key="i" class="grid gap-3 sm:grid-cols-[1fr_1fr_110px_110px_auto]">
                    <label class="text-sm">Marca<input v-model="vehicle.brand" maxlength="80" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                    <label class="text-sm">Modello<input v-model="vehicle.model" maxlength="120" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                    <label class="text-sm">Dal<input v-model.number="vehicle.year_from" type="number" min="1" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                    <label class="text-sm">Al<input v-model.number="vehicle.year_to" type="number" :min="vehicle.year_from || 1" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                    <button type="button" :disabled="editing.vehicles.length === 1 || editing.processing" class="self-end rounded border px-3 py-2 text-sm disabled:opacity-50" @click="editing.vehicles.splice(i, 1)">Rimuovi</button>
                </div>
                <div class="flex flex-wrap gap-3"><button type="button" :disabled="editing.processing" class="rounded border px-3 py-2 text-sm" @click="editing.vehicles.push({ brand: '', model: '', year_from: selected.year_from, year_to: selected.year_to })">Aggiungi auto / modello</button><button type="button" :disabled="editing.processing || removingVariant.processing" class="rounded bg-foreground px-3 py-2 text-sm font-medium text-background disabled:opacity-50" @click="save">Salva modifiche alla bozza</button></div>
                <p class="text-xs text-muted-foreground">Ogni riga mantiene i propri anni. Le associazioni multimarca vengono formattate automaticamente.</p>
                <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </section>
            <p v-for="(error, key) in imageAi.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <form v-if="local" class="space-y-3 rounded-lg border p-3" @submit.prevent="uploadPhotos">
                <h3 class="text-sm font-medium">Aggiungi foto dal computer</h3>
                <input :key="uploadKey" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.avif,.gif,.bmp,.heic,.heif,.tif,.tiff,image/jpeg,image/png,image/webp,image/avif,image/gif,image/bmp,image/heic,image/heif,image/tiff" :disabled="uploading.processing" class="block w-full text-sm" @change="loadPhotos" />
                <p class="text-xs text-muted-foreground">JPG, PNG, WebP, AVIF, GIF, BMP, HEIC/HEIF e TIFF. Massimo 10 foto da 8 MB. Proporzioni originali; GIF e TIFF vengono acquisiti come immagini statiche.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-sm">Tipo foto <select v-model="uploading.role" class="ml-2 rounded border bg-background px-2 py-1"><option value="prodotto">Prodotto</option><option value="accessori">Accessori / package list</option><option value="cruscotto_prima_dopo">Prima / dopo installazione</option></select></label>
                    <button :disabled="!uploading.photos.length || uploading.processing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || removing" class="rounded border px-3 py-2 text-sm disabled:opacity-50">{{ uploading.processing ? 'Caricamento e conversione…' : 'Aggiungi foto' }}</button>
                </div>
                <p v-for="(error, key) in uploading.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </form>
            <div v-if="local" class="space-y-2">
                <button type="button" :disabled="dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing" class="rounded border px-3 py-2 text-sm disabled:opacity-50" @click="generateDashboard">{{ dashboard.processing ? 'Ricerca riferimenti e generazione cruscotto…' : 'Genera foto cruscotto originale' }}</button>
                <p class="text-xs text-muted-foreground">{{ selected.brand }} {{ selected.model }} {{ selected.year_from }}–{{ selected.year_to }} · Ricostruzione AI da riferimenti internet, da verificare.</p>
                <p v-for="(error, key) in dashboard.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </div>
            <p v-for="(error, key) in layout.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p class="text-xs text-muted-foreground">La prima immagine è la foto principale. Usa Prima / Dopo per riordinarle; i flag Cruscotto originale scelgono le foto per il metafield e il configuratore.</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <figure v-for="(image, i) in selected.images" :key="image.id" class="rounded-lg border p-3">
                    <img :src="image.url" :alt="`Foto ${i + 1} — ${selected.title}`" class="h-auto w-full rounded" />
                    <figcaption class="mt-2 space-y-3 text-xs">
                        <p>Foto {{ i + 1 }} · {{ image.width }}×{{ image.height }}<br />{{ imageRole(image.role) }}<span v-if="image.ai_generated" class="ml-2 font-medium text-violet-700">Generata con AI · da verificare</span><span v-else-if="image.ai_edited" class="ml-2 font-medium text-violet-700">Modificata con AI</span></p>
                        <details v-if="image.web_sources?.length"><summary>Fonti cruscotto</summary><a v-for="url in image.web_sources" :key="url" :href="url" target="_blank" rel="noopener noreferrer" class="block break-all underline">{{ url }}</a></details>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :disabled="i === 0 || layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing" class="rounded border px-2 py-1 disabled:opacity-50" @click="moveImage(i, -1)">← Prima</button>
                            <button type="button" :disabled="i === selected.images.length - 1 || layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing" class="rounded border px-2 py-1 disabled:opacity-50" @click="moveImage(i, 1)">Dopo →</button>
                        </div>
                        <label class="flex items-center gap-2"><input type="checkbox" :checked="image.dashboard_selected" :disabled="layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing" @change="changeLayout({ dashboard_image_ids: selected.images.filter(p => p.id === image.id ? !p.dashboard_selected : p.dashboard_selected).map(p => p.id) })" />Cruscotto originale · foto per il metafield</label>
                        <label v-if="image.dashboard_selected" class="block space-y-1"><span>Riferimento Shopify manuale (facoltativo)</span><input :value="image.shopify_file_reference" :disabled="layout.processing" type="text" maxlength="255" placeholder="cruscotto-originale.jpg" class="w-full rounded border bg-background px-2 py-1" @change="dashboardReference(image.id, $event)" /><span class="block text-muted-foreground">Lascia vuoto per usare automaticamente il nome della foto selezionata nell’export. Il link Aruba completo sarà in Image Src. Se il file Shopify ha un nome diverso, inseriscilo qui; il campo si salva quando esci.</span></label>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border border-violet-300 px-2 py-1 text-violet-700 disabled:opacity-50" @click="editImage(i, image.id)">{{ imageAi.processing && imageAi.image_id === image.id && imageAction === 'ai' ? 'Modifica AI in corso…' : 'Modifica con AI' }}</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="editImage(i, image.id, 'clean')">{{ imageAi.processing && imageAi.image_id === image.id && imageAction === 'clean' ? 'Pulizia e miglioramento in corso…' : 'Pulisci e migliora' }}</button>
                            <button v-if="local" type="button" title="Sfoca le scritte selezionate senza AI e senza modificare il resto della foto" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startPaint(i, image.id, image.url, 'cover')">Cancella scritte</button>
                            <button v-if="local && (image.ai_edited || image.manually_cropped)" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="editImage(i, image.id, 'restore')">Ripristina originale</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startCrop(i, image.id, image.url)">Taglia</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startPaint(i, image.id, image.url)">Paint</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startPaint(i, image.id, image.url, 'text')">TESTO</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="editImage(i, image.id, 'white')">{{ imageAi.processing && imageAi.image_id === image.id && imageAction === 'white' ? 'Ricreazione accessori in corso…' : 'Ricrea accessori' }}</button>
                            <button type="button" class="rounded border border-red-200 px-2 py-1 text-red-600 disabled:opacity-50" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" @click="removeImage(i, image.id)">Rimuovi</button>
                        </div>
                    </figcaption>
                </figure>
            </div>
            <p v-if="selected.images.some(image => image.local_only)" class="text-sm text-amber-700">Gli originali restano nella bozza. Le copie per l’export vengono preparate in storage/app/public/autogenesi/catalog/: trasferisci anche questa cartella su Aruba con FileZilla.</p>
            <form class="space-y-4" @submit.prevent="save">
                <label class="block text-sm font-medium">Titolo<input v-model="editing.title" required class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                <div class="space-y-1"><p class="text-sm font-medium">Descrizione</p><DescriptionEditor :key="selected.id" v-model="editing.body_html" label="Descrizione prodotto" /></div>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Variante dichiarata</th><th class="p-2">SKU interno</th><th class="p-2">Prezzo fornitore osservato</th><th class="p-2">Prezzo di vendita EUR</th><th class="p-2">Azione</th></tr></thead><tbody><tr v-for="(v, i) in selected.variants" :key="v.sku" class="border-t"><td class="p-2"><input v-if="editing.variants[i]" v-model="editing.variants[i].label" type="text" required maxlength="500" class="w-72 rounded border bg-background px-2 py-1" /><p class="mt-1 text-xs text-red-600">{{ editing.errors[`variants.${i}.label`] }}</p></td><td class="p-2 text-xs">{{ v.sku }}</td><td class="p-2">{{ money(v.supplier_price) }}<p v-if="v.pricing" class="mt-1 text-xs text-muted-foreground">Ricarico {{ money(v.pricing.markup) }} · Stima dogana {{ money(v.pricing.customs_estimate) }}</p></td><td class="p-2"><input v-if="editing.variants[i]" v-model="editing.variants[i].selling_price" type="number" min="1" step="1" placeholder="Da impostare" class="w-36 rounded border bg-background px-2 py-1" /></td><td class="p-2"><button v-if="local" type="button" :disabled="removingVariant.processing || editing.processing || selected.variants.length < 2" :title="selected.variants.length < 2 ? 'Deve rimanere almeno una variante' : 'Elimina questa variante'" class="whitespace-nowrap rounded border border-red-200 px-2 py-1 text-xs text-red-600 disabled:opacity-50" @click="deleteVariant(v.sku)">{{ removingVariant.processing && removingVariant.sku === v.sku ? 'Eliminazione…' : 'Elimina variante' }}</button></td></tr></tbody></table></div>
                <p v-for="(error, key) in removingVariant.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <button :disabled="editing.processing || removingVariant.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50">Salva modifiche alla bozza</button>
            </form>
            <section v-if="local" class="space-y-3 rounded-lg border p-4">
                <h3 class="text-sm font-semibold">Importazione nel configuratore</h3>
                <p class="text-sm">Salva e approva questa bozza: dati, varianti, prezzi e foto saranno disponibili nel catalogo di questa installazione. L’importazione in Shopify resta separata.</p>
                <p v-if="selected.metadata.configurator_import" class="text-xs text-muted-foreground">Già importata come prodotto #{{ selected.metadata.configurator_import.product_id }}. Premi nuovamente per aggiornare il configuratore con le modifiche attuali.</p>
                <p v-for="(error, key) in catalogImport.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <button type="button" :disabled="catalogImport.processing || editing.processing || removingVariant.processing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50" @click="importConfigurator">{{ catalogImport.processing ? 'Importazione nel configuratore…' : selected.metadata.configurator_import ? 'Aggiorna nel configuratore' : 'Approva e importa nel configuratore' }}</button>
            </section>
            <div class="flex flex-wrap gap-3 border-t pt-4"><a :href="`/autogenesi/${selected.id}/export`" class="rounded-lg border px-4 py-2 text-sm font-medium">Scarica Excel · formato modello</a></div>
            <p class="text-xs text-muted-foreground">Unico formato Excel con le colonne del modello, stato Active. Il link del fornitore resta nel database. Il file contiene soltanto il foglio Products. Gli URL immagini puntano alla produzione Aruba: trasferisci prima le foto con FileZilla e verifica gli indirizzi, poi importa l’Excel in Shopify.</p>
        </section>
        <ProductSilhouette v-if="sceneTarget" :src="sceneTarget.url" :busy="imageAi.processing" :error="Object.values(imageAi.errors).join(' ')" @cancel="sceneTarget = null" @save="applyScene" @automatic="applyAutomaticScene" />
        <ImageTextBand v-if="paintTarget && paintMode === 'text'" :src="paintTarget.url" :busy="painting.processing" :error="Object.values(painting.errors).join(' ')" @cancel="paintTarget = null" @save="applyPaint" />
        <ImageCleanupMask v-if="paintTarget && paintMode === 'cover'" :src="paintTarget.url" :busy="cleanup.processing" :error="Object.values(cleanup.errors).join(' ')" @cancel="paintTarget = null" @save="applyCleanup" />
        <ImagePainter v-if="paintTarget && paintMode === 'paint'" :src="paintTarget.url" :busy="painting.processing" :error="Object.values(painting.errors).join(' ')" @cancel="paintTarget = null" @save="applyPaint" />
        <SquareImageCropper v-if="cropTarget" :src="cropTarget.url" :busy="cropping.processing" :error="Object.values(cropping.errors).join(' ')" @cancel="cropTarget = null" @save="applyCrop" />
    </div>
</template>
