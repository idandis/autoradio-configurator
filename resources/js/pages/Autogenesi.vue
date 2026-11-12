<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import DescriptionEditor from '@/components/DescriptionEditor.vue';
import VehicleCompatibilityEditor from '@/components/VehicleCompatibilityEditor.vue';
import SquareImageCropper from '@/components/SquareImageCropper.vue';
import ImagePainter from '@/components/ImagePainter.vue';
import ImageCleanupMask from '@/components/ImageCleanupMask.vue';
import ImageTextBand from '@/components/ImageTextBand.vue';
import ProductSilhouette from '@/components/ProductSilhouette.vue';
import AutogenesiApiSearch from '@/components/AutogenesiApiSearch.vue';

type Variant = { manual?: boolean; label: string; sku: string; supplier_price: string; currency: string; selling_price: string | null; pricing?: { markup: string; customs_estimate: string } };
type Product = { processing_epoch?: string; vehicle_groups?: { brand: string; model: string; year_from: number; year_to: number }[]; id: number; title: string; body_html: string; status: string; brand: string; model: string; year_from: number; year_to: number; source_url: string; variants: Variant[]; images: { id: string; ai_edited: boolean; image_reframed?: boolean; resolution_upscaled?: boolean; dashboard_selected: boolean; shopify_file_reference: string; ai_generated: boolean; web_sources: string[]; manually_cropped: boolean; local_only: boolean; url: string; source_url: string | null; role: string; width: number; height: number }[]; metadata: { variant_acquisition?: { complete: boolean; detected: number; captured: number; skipped: string[] } | null; additional_source_urls?: string[]; vehicle_compatibilities?: { brand: string; model: string; year_from: number; year_to: number }[]; configurator_import?: { product_id: number; imported_at: string }; seller?: string; source_title?: string; warnings?: string[]; captured_at?: string; description_source?: { text: string; specifications: { name: string; value: string }[]; images: unknown[] }; description_notes?: string[]; description_generation?: { generated_at: string } } };
type Candidate = { title: string; source_url: string; price_text?: string; verified?: boolean };
type Search = { query: string; origin: string; url: string; mode?: 'links' | 'best_price'; selected?: Candidate | null; inspected_count?: number; vehicle: { brand: string; model: string; year_from: number; year_to: number }; candidates: Candidate[] };
type BatchItem = { id: number; query: string; status: string; source_url: string | null; product_id: number | null; error: string | null };
type Batch = { id: number; engine?: 'api' | 'browser'; status: string; total: number; items: BatchItem[] };
type Detection = { mode?: 'year' | 'brand'; checked_count?: number; brand?: string; year_from?: number; year_to?: number; year: number | null; complete?: boolean; target?: number; researched_at: string; compared_at: string; notes: string; import_list: string; counts: { catalog: number; draft: number; missing: number }; sources: string[]; cars: { brand: string; model: string; year_from?: number; year_to?: number; missing_ranges?: number[][]; camera_only?: boolean; cameras_present?: boolean; market: string; rank: number | null; units: number | null; evidence: string; source_url: string; status: 'catalog' | 'draft' | 'missing' }[] };
const props = defineProps<{ errors?: { export?: string }; reimportImagesResult?: string | null; catalogEdit?: boolean; catalogRadio?: boolean; catalogCategory?: string; products: Product[]; local: boolean; apiConnection?: { connected: boolean; expires_at: number | null } | null; workerError?: string | null; detection?: Detection | null; detectionMaxYear: number; search?: Search | null; directImport?: { source_url: string } | null; batches: Batch[] }>();
const sheetUrl = (id: number) => props.catalogEdit ? `/imported-products/${id}/sheet` : `/autogenesi/${id}`;
const vehicleValid = ref(true);
const sheetHeaders = () => ({ 'X-Sheet-Epoch': selected.value?.processing_epoch ?? 'initial' });
const showList = ref(false);
const listForm = useForm({ list: '', engine: 'api' as 'api' | 'browser', sort_by: 'price', origin_policy: 'prefer_eu' });
const detectionForm = useForm({ mode: props.detection?.mode ?? 'year', brand: props.detection?.brand ?? '', year: props.detection?.year ?? Math.min(2020, props.detectionMaxYear) });
const showMissingModels = ref(false);
const missingModels = computed(() => (props.detection?.cars ?? []).filter(c => c.status === 'missing').map(c => `${c.brand} ${c.model} ${c.year_from} ${c.year_to}`).join('\n'));
const fillDetectedList = () => {
    if (props.detection?.mode === 'brand') showMissingModels.value = true;
    listForm.list = props.detection?.import_list ?? ''; showList.value = !!listForm.list;
};
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
const poll = usePoll(5000, { only: ['batches', 'apiConnection'] }, { autoStart: false });
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
    imageAi.transform(data => data).post(`${sheetUrl(selected.value.id)}/images/${sceneTarget.value.index}/ai`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => { sceneTarget.value = null; imageAi.points = []; } });
};
const applyAutomaticScene = (mask: File) => {
    if (!selected.value || !sceneTarget.value || imageAi.processing) return;
    imageAi.transform(data => ({ image_id: data.image_id, cutout_mode: 'auto', mask })).post(`${sheetUrl(selected.value.id)}/images/${sceneTarget.value.index}/ai`, {
        headers: sheetHeaders(), forceFormData: true, preserveScroll: true, onSuccess: () => { sceneTarget.value = null; } });
};
const layout = useForm({});
const dashboard = useForm({});
const generateDashboard = () => {
    if (!selected.value || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    dashboard.post(`${sheetUrl(selected.value.id)}/dashboard-image`, { headers: sheetHeaders(), preserveScroll: true });
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
    uploading.post(`${sheetUrl(selected.value.id)}/images`, { headers: sheetHeaders(), forceFormData: true, preserveScroll: true, onSuccess: () => { uploading.reset('photos'); uploadKey.value++; } });
};
const recreating = useForm({ description_link: '' });
const descriptionRevision = ref(0);
const descriptionMessage = ref('');
let descriptionRequest: AbortController | null = null;
onUnmounted(() => descriptionRequest?.abort());
const recreateDescription = async () => {
    if (!selected.value || recreating.processing) return;
    const product = selected.value;
    descriptionRequest = new AbortController(); recreating.processing = true; recreating.clearErrors(); descriptionMessage.value = '';
    try {
        const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        const response = await fetch(`${sheetUrl(product.id)}/description-link`, { method: 'POST', signal: descriptionRequest.signal, headers: {
            ...sheetHeaders(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(csrf || ''),
        } });
        const data = await response.json().catch(() => ({}));
        if (response.redirected || response.status === 401) throw new Error('Accedi nuovamente alla sessione locale.');
        if (!response.ok || typeof data.body_html !== 'string') throw new Error(data.message || 'Descrizione non aggiornata. Riprova.');
        product.body_html = data.body_html; product.metadata.description_generation = data.generation;
        product.metadata.description_notes = data.notes; product.metadata.description_source = data.source;
        if (selected.value?.id === product.id) {
            editing.body_html = data.body_html;
            if (editingBase) editingBase.body_html = data.body_html;
            descriptionRevision.value++;
            descriptionMessage.value = data.changed ? 'Nuova descrizione salvata e caricata nell’editor.' : 'Elaborazione completata: la descrizione restituita è identica a quella già presente.';
        }
    } catch (e) {
        if (!(e instanceof DOMException && e.name === 'AbortError')) recreating.setError('description_link', e instanceof Error ? e.message : 'Generazione non completata.');
    } finally { recreating.processing = false; descriptionRequest = null; }
};
const reimporting = useForm({});
const reimportImages = () => selected.value && reimporting.post(`${sheetUrl(selected.value.id)}/reimport-images`, { headers: sheetHeaders(), preserveScroll: true });
const imageAction = ref<'ai' | 'restore' | 'white' | 'upscale'>('ai');
const sharpness = ref<Record<string, number>>({});
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
    cleanup.post(`${sheetUrl(selected.value.id)}/images/${paintTarget.value.index}/${paintMode.value === 'enhance' ? 'clean' : 'cleanup-text'}`, { headers: sheetHeaders(), forceFormData: true, preserveScroll: true, onSuccess: () => { paintTarget.value = null; cleanup.reset(); } });
};
const applyAiCleanup = (mask: File) => {
    if (!selected.value || !paintTarget.value || imageAi.processing || cleanup.processing) return;
    imageAi.image_id = paintTarget.value.id;
    imageAi.clearErrors();
    imageAi.transform(({ points, ...data }) => ({ ...data, mask })).post(`${sheetUrl(selected.value.id)}/images/${paintTarget.value.index}/clean-ai`, {
        headers: sheetHeaders(), forceFormData: true, preserveScroll: true, onSuccess: () => { paintTarget.value = null; },
    });
};
const paintMode = ref<'paint' | 'text' | 'cover' | 'enhance'>('paint');
const startPaint = (index: number, id: string, url: string, mode: 'paint' | 'text' | 'cover' | 'enhance' = 'paint') => {
    paintMode.value = mode;
    painting.clearErrors(); cleanup.clearErrors();
    paintTarget.value = { index, id, url };
};
const applyPaint = (file: File) => {
    if (!selected.value || !paintTarget.value || painting.processing) return;
    painting.image_id = paintTarget.value.id;
    painting.photo = file;
    painting.post(`${sheetUrl(selected.value.id)}/images/${paintTarget.value.index}/paint`, { headers: sheetHeaders(), forceFormData: true, preserveScroll: true,
        onSuccess: () => { paintTarget.value = null; painting.reset(); } });
};
const applyCrop = (rect: { x: number; y: number; size: number; width: number; height: number }) => {
    if (!selected.value || !cropTarget.value) return;
    Object.assign(cropping, rect, { image_id: cropTarget.value.id });
    cropping.post(`${sheetUrl(selected.value.id)}/images/${cropTarget.value.index}/crop`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => { cropTarget.value = null; } });
};
const editImage = (index: number, id: string, action: 'ai' | 'restore' | 'white' | 'upscale' = 'ai') => {
    if (!selected.value || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    imageAi.image_id = id;
    imageAi.points = [];
    imageAction.value = action;
    imageAi.clearErrors();
    if (action === 'ai') { sceneTarget.value = { index, url: selected.value.images[index].url }; return; }
    imageAi.transform(({ points, ...data }) => action === 'upscale' ? { ...data, sharpness: sharpness.value[id] ?? 35 } : data).post(`${sheetUrl(selected.value.id)}/images/${index}/${action}`, { headers: sheetHeaders(), preserveScroll: true });
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
    router.delete(`${sheetUrl(p.id)}`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => { if (selectedId.value === p.id) selectedId.value = null; } });
};
const selectedId = ref<number | null>(props.catalogEdit ? props.products[0]?.id ?? null : null);
const selected = computed(() => props.products.find(p => p.id === selectedId.value));
const dashboardQuery = computed(() => {
    const p = selected.value;
    if (!p) return '';
    const years = [p.year_from, ...(p.metadata.vehicle_compatibilities ?? []).map(v => v.year_from)].filter(y => Number.isInteger(Number(y)) && Number(y) > 1900);
    const brand = p.brand.split('|')[0].trim();
    const model = p.model.split('|')[0].trim().replace(/^\d+:/, '');
    return `${brand} ${model} ${years.length ? Math.min(...years) : ''} INTERNI`.toUpperCase();
});
const automatic = props.search?.mode === 'best_price' && props.search.selected ? props.search : null;
const importing = useForm({ source_url: props.directImport?.source_url ?? automatic?.selected?.source_url ?? '', brand: automatic?.vehicle.brand ?? '', model: automatic?.vehicle.model ?? '', year_from: (automatic?.vehicle.year_from ?? '') as number | string, year_to: (automatic?.vehicle.year_to ?? '') as number | string });
const editing = useForm({ title: '', body_html: '', additional_source_urls: [] as string[], vehicles: [] as { brand: string; model: string; year_from: number; year_to: number }[], variants: [] as { sku: string; label: string; supplier_price: string; selling_price: string | null }[] });
type CostQuote = { cost: string; busy: boolean; error?: string; markup?: string; customs_estimate?: string };
const costQuotes = ref<Record<string, CostQuote>>({});
const costTimers = new Map<string, ReturnType<typeof setTimeout>>();
let costEpoch = 0;
onUnmounted(() => { costEpoch++; costTimers.forEach(clearTimeout); });
const recalculateCost = (sku: string, event: Event) => {
    const variant = editing.variants.find(v => v.sku === sku);
    if (!variant) return;
    variant.supplier_price = (event.target as HTMLInputElement).value;
    if (props.catalogEdit && !props.catalogRadio) return;
    clearTimeout(costTimers.get(sku));
    const cost = String(variant.supplier_price);
    const sale = variant.selling_price;
    const epoch = costEpoch;
    costQuotes.value[sku] = { cost, busy: true };
    costTimers.set(sku, setTimeout(async () => {
        try {
            const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
            const r = await fetch('/autogenesi/variant-price', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf || '') }, body: JSON.stringify({ cost }) });
            const data = await r.json().catch(() => ({}));
            if (!r.ok || !data.selling_price) throw new Error(data.errors?.cost?.[0] || 'Calcolo non disponibile. Verifica il costo.');
            const current = editing.variants.find(v => v.sku === sku);
            if (epoch !== costEpoch || !current || Number(current.supplier_price) !== Number(cost)) return;
            if (current.selling_price === sale) current.selling_price = data.selling_price;
            costQuotes.value[sku] = { cost, busy: false, markup: data.markup, customs_estimate: data.customs_estimate };
        } catch (e) {
            if (epoch === costEpoch && Number(editing.variants.find(v => v.sku === sku)?.supplier_price) === Number(cost)) costQuotes.value[sku] = { cost, busy: false, error: e instanceof Error ? e.message : 'Calcolo non disponibile.' };
        }
    }, 350));
};
let editingBase: ReturnType<typeof editing.data> | null = null;
const open = (p: Product, preserveEdits = false) => {
    const previous = preserveEdits && selectedId.value === p.id ? JSON.parse(JSON.stringify(editing.data())) : null;
    const baseline = editingBase;
    costEpoch++; costTimers.forEach(clearTimeout); costTimers.clear(); costQuotes.value = {};
    if (selectedId.value !== p.id) { showAddVariant.value = false; addingVariant.reset(); addingVariant.clearErrors(); uploading.reset(); uploading.clearErrors(); uploadKey.value++; }
    selectedId.value = p.id;
    removingVariant.clearErrors();
    editing.additional_source_urls = [...(p.metadata.additional_source_urls ?? [])];
    editing.title = p.title;
    editing.body_html = p.body_html;
    const brands = p.brand.split(' | ');
    editing.vehicles = props.catalogEdit && (!p.brand?.trim() || !p.model?.trim()) ? [] : p.vehicle_groups?.length ? p.vehicle_groups.map(v => ({ ...v })) : p.metadata.vehicle_compatibilities?.length
        ? p.metadata.vehicle_compatibilities.map(v => ({ brand: v.brand, model: v.model, year_from: v.year_from, year_to: v.year_to }))
        : p.model.split(' | ').flatMap(value => {
            const match = value.match(/^\s*(\d+)\s*[:：]\s*(.+)$/);
            return (match ? [brands[Number(match[1]) - 1] || ''] : brands).map(brand => ({ brand, model: match ? match[2] : value, year_from: p.year_from, year_to: p.year_to }));
        });
    editing.variants = p.variants.map(v => ({ sku: v.sku, label: v.label, supplier_price: v.supplier_price, selling_price: v.selling_price === null ? null : props.catalogEdit ? String(v.selling_price) : String(Math.ceil(Number(v.selling_price))) }));
    editingBase = JSON.parse(JSON.stringify(editing.data()));
    if (previous && baseline) for (const key of Object.keys(previous) as (keyof typeof baseline)[]) {
        if (JSON.stringify(previous[key]) !== JSON.stringify(baseline[key]) && JSON.stringify(editingBase![key]) === JSON.stringify(baseline[key])) Object.assign(editing, { [key]: previous[key] });
    }
    editing.clearErrors();
};
const showAddVariant = ref(false);
const recreatingVariants = ref(false);
const variantsMessage = ref('');
const variantsError = ref('');
const variantChanges = computed(() => !!editingBase && (JSON.stringify(editing.variants) !== JSON.stringify(editingBase.variants)
    || JSON.stringify(editing.additional_source_urls) !== JSON.stringify(editingBase.additional_source_urls)));
const recreateVariants = async () => {
    if (!selected.value || !props.catalogEdit || recreatingVariants.value) return;
    if (!props.catalogRadio) {
        variantsMessage.value = ''; variantsError.value = `Regola prezzi ${props.catalogCategory || 'accessori'} ancora non disponibile`;
        return;
    }
    if (variantChanges.value) return;
    recreatingVariants.value = true; variantsMessage.value = ''; variantsError.value = '';
    try {
        const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        const response = await fetch(`/imported-products/${selected.value.id}/supplier-variants/recreate`, {
            method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(csrf || '') },
        });
        const data = await response.json().catch(() => ({}));
        if (response.redirected || [401, 419].includes(response.status)) throw new Error('Accedi nuovamente alla sessione locale.');
        if (!response.ok) throw new Error(response.status === 429 ? 'Attendi un minuto prima di riprovare.' : String(Object.values(data.errors || {}).flat()[0] || data.message || 'Ricreazione varianti non riuscita.'));
        await new Promise<void>(resolve => router.reload({ only: ['products'], preserveScroll: true, onFinish: resolve }));
        variantsMessage.value = data.message;
        showAddVariant.value = false;
    } catch (e) { variantsError.value = e instanceof Error ? e.message : 'Ricreazione varianti non riuscita.'; }
    finally { recreatingVariants.value = false; }
};
const addingVariant = useForm({ label: '', supplier_price: '', selling_price: '' });
const addVariant = () => {
    if (!selected.value || recreatingVariants.value || addingVariant.processing || editing.processing || removingVariant.processing) return;
    addingVariant.post(`${sheetUrl(selected.value.id)}/variants`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => {
        const edits = new Map(editing.variants.map(v => [v.sku, v]));
        editing.variants = (selected.value?.variants ?? []).map(v => edits.get(v.sku) ?? { sku: v.sku, label: v.label, supplier_price: v.supplier_price, selling_price: v.selling_price });
        addingVariant.reset(); showAddVariant.value = false;
    } });
};
const removingVariant = useForm({ sku: '' });
const deleteVariant = (sku: string) => {
    if (!selected.value || recreatingVariants.value || addingVariant.processing || editing.processing || removingVariant.processing || selected.value.variants.length < 2) return;
    removingVariant.sku = sku;
    removingVariant.delete(`${sheetUrl(selected.value.id)}/variants`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => {
        const edits = new Map(editing.variants.map(v => [v.sku, v]));
        editing.variants = (selected.value?.variants ?? []).map(v => edits.get(v.sku) ?? { sku: v.sku, label: v.label, supplier_price: v.supplier_price, selling_price: v.selling_price });
    } });
};
const catalogImport = useForm({});
const catalogAll = useForm({});
const importAllConfigurator = () => {
    if (catalogAll.processing || catalogImport.processing || editing.processing || layout.processing || imageAi.processing || dashboard.processing || cropping.processing || uploading.processing || removing.value) return;
    const run = () => catalogAll.post('/autogenesi/import-all-configurator', { preserveScroll: true, onSuccess: () => { selectedId.value = null; } });
    if (selected.value) editing.patch(`${sheetUrl(selected.value.id)}`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: run });
    else run();
};
const importConfigurator = () => {
    if (!selected.value || editing.processing || catalogImport.processing || removingVariant.processing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing) return;
    const id = selected.value.id;
    catalogImport.clearErrors();
    editing.patch(`${sheetUrl(id)}`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => {
        if (selected.value?.id === id) open(selected.value);
        catalogImport.post(`${sheetUrl(id)}/import-configurator`, { headers: sheetHeaders(), preserveScroll: true });
    } });
};
const save = () => selected.value && vehicleValid.value && editing.transform(data => props.catalogEdit && !data.vehicles.some(v => v.brand && v.model) ? (({ vehicles, ...rest }) => rest)(data) : data).patch(`${sheetUrl(selected.value.id)}`, { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => { if (selected.value) open(selected.value); } });
const exportProduct = () => {
    if (!selected.value || editing.processing || recreatingVariants.value) return;
    const id = selected.value.id;
    const download = () => { window.location.assign(props.catalogEdit ? `/imported-products/${id}/sheet/export` : `/autogenesi/${id}/export`); };
    if (!props.local) { download(); return; }
    if (!vehicleValid.value) return;
    editing.transform(data => props.catalogEdit && !data.vehicles.some(v => v.brand && v.model) ? (({ vehicles, ...rest }) => rest)(data) : data)
        .patch(sheetUrl(id), { headers: sheetHeaders(), preserveScroll: true, onSuccess: () => {
            if (selected.value?.id === id) open(selected.value);
            download();
        } });
};
const readyToImport = computed(() => Boolean(importing.source_url.trim() && importing.brand.trim() && importing.model.trim()
    && Number.isInteger(Number(importing.year_from)) && Number(importing.year_from) > 0
    && Number.isInteger(Number(importing.year_to)) && Number(importing.year_to) >= Number(importing.year_from)));
const submitPreparedImport = () => { if (!importing.processing) importForm.value?.requestSubmit(); };
const importProduct = () => importing.post('/autogenesi/import', { preserveScroll: true, onSuccess: () => importing.reset('source_url') });
const removeImage = (index: number, id: string) => {
    if (!selected.value || removing.value || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing) return;
    removing.value = true;
    router.delete(`${sheetUrl(selected.value.id)}/images/${index}`, { data: { image_id: id }, preserveScroll: true, onFinish: () => { removing.value = false; } });
};
const changeLayout = (data: { order?: string[]; dashboard_image_id?: string | null; dashboard_image_ids?: string[]; reference_image_id?: string; shopify_file_reference?: string | null }) => {
    if (!selected.value || layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing.value) return;
    layout.transform(() => data).patch(`${sheetUrl(selected.value.id)}/images/layout`, { headers: sheetHeaders(), preserveScroll: true });
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
const sheetStopping = ref(false);
const sheetStopMessage = ref('');
const sheetStopError = ref('');
const interruptSheet = async () => {
    if (!selected.value || sheetStopping.value) return;
    const product = selected.value;
    sheetStopping.value = true; sheetStopMessage.value = ''; sheetStopError.value = '';
    descriptionRequest?.abort();
    router.cancelAll();
    for (const form of [editing, imageAi, layout, dashboard, uploading, recreating, reimporting, cropping, painting, cleanup, addingVariant, removingVariant, catalogImport]) { form.cancel(); form.clearErrors(); }
    removing.value = false; sceneTarget.value = null; cropTarget.value = null; paintTarget.value = null;
    costEpoch++; costTimers.forEach(clearTimeout); costTimers.clear(); costQuotes.value = {};
    try {
        const csrf = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
        const response = await fetch(`${sheetUrl(product.id)}/stop-processing`, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(csrf || '') } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.epoch) throw new Error(data.message || 'Arresto non confermato dal server: riprova.');
        product.processing_epoch = data.epoch;
        sheetStopMessage.value = data.message;
    } catch (e) { sheetStopError.value = e instanceof Error ? e.message : 'Arresto non riuscito.'; }
    finally { sheetStopping.value = false; }
};
watch(() => props.products, products => { if (props.catalogEdit && products[0]) open(products[0], true); }, { immediate: true });
</script>

<template>
    <Head :title="catalogEdit ? 'Modifica scheda prodotto' : 'AutoGenesi · Bozze'" />
    <div class="space-y-6 p-4 md:p-8">
        <div v-if="catalogEdit" class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold">Modifica scheda prodotto</h1><a href="/imported-products" class="rounded border px-4 py-2 text-sm">Torna ai prodotti importati</a></div>
        <template v-if="!catalogEdit">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-2xl font-semibold">AutoGenesi</h1><p class="mt-2 text-sm text-muted-foreground">Prodotti importati in bozza, visibili soltanto agli amministratori. Rivedi foto, caratteristiche e prezzi prima dell’importazione finale.</p></div><div class="flex flex-wrap gap-3"><a v-if="products.some(p => p.status === 'draft')" href="/autogenesi/export-all" class="rounded-lg border px-4 py-2 text-sm font-medium">Esporta tutte le bozze</a><button v-if="local && products.length" type="button" :disabled="catalogAll.processing || catalogImport.processing || editing.processing || layout.processing || imageAi.processing || dashboard.processing || cropping.processing || uploading.processing || removing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50" @click="importAllConfigurator">{{ catalogAll.processing ? 'Importazione di tutte le bozze…' : 'Importa tutte le bozze nel configuratore' }}</button><button v-if="local" type="button" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background" @click="showList = !showList">Importa lista</button></div></div>
        <nav class="flex flex-wrap gap-3 text-sm" aria-label="Sezioni AutoGenesi">
            <a v-if="local" href="#ricerca-api" class="rounded-lg border border-blue-400/40 bg-blue-400/10 px-3 py-2">Ricerca API</a>
            <a href="#autogenesi-drafts" class="rounded-lg border px-3 py-2">Bozze ({{ products.length }})</a>
            <a v-if="local" href="#strumenti-browser" class="rounded-lg border px-3 py-2">Ricerca e importazione con browser</a>
        </nav>
        <details v-if="local" :open="detectionForm.processing || !!detection || Object.keys(detectionForm.errors).length > 0" class="space-y-4 rounded-xl border bg-card p-5">
            <summary class="cursor-pointer text-lg font-semibold">Rilevamento autoradio mancanti</summary>
            <p class="text-sm text-muted-foreground">Per anno: fino a 50 modelli dalle classifiche annuali. Per marca: tutti i modelli prodotti anche solo in parte tra il 1995 e il 2024. Controllo anno per anno delle autoradio in catalogo e in bozza. Mostra soltanto i periodi scoperti, con marca, modello e intervallo di anni. Le sole telecamere non contano come autoradio presenti.</p>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="detectMissing">
                <label class="text-sm">Rileva per<select v-model="detectionForm.mode" class="mt-1 block rounded border bg-background px-3 py-2" :disabled="detectionForm.processing"><option value="year">Anno</option><option value="brand">Marca</option></select></label>
                <label v-if="detectionForm.mode === 'brand'" class="text-sm">Marca<input v-model="detectionForm.brand" required maxlength="80" placeholder="Es. Volkswagen" class="mt-1 block rounded border bg-background px-3 py-2" :disabled="detectionForm.processing" /></label>
                <label v-else class="text-sm">Anno<input v-model.number="detectionForm.year" required type="number" min="1900" :max="detectionMaxYear" class="mt-1 block w-32 rounded border bg-background px-3 py-2" :disabled="detectionForm.processing" /></label>
                <button type="submit" :disabled="detectionForm.processing || listForm.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm text-background disabled:opacity-50">{{ detectionForm.processing ? 'Ricerca e confronto in corso…' : 'Rileva le autoradio mancanti' }}</button>
            </form>
            <p v-if="detectionForm.processing" class="text-sm text-muted-foreground">La ricerca delle fonti può richiedere alcuni minuti. Mantieni aperta la pagina.</p>
            <p v-for="(error, key) in detectionForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div v-if="detection" class="space-y-3">
                <p v-if="detection.mode === 'brand'" role="status" class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-600">Ricerca parziale: il confronto riguarda soltanto i modelli documentati sotto. Il numero dei mancanti non rappresenta tutti i modelli assenti della marca.</p>
                <p class="text-sm font-medium">{{ detection.mode === 'brand' ? detection.brand : 'Anno ' + detection.year }} · {{ detection.checked_count ?? detection.cars.length }} modelli verificati · {{ detection.counts.catalog }} già nel catalogo · {{ detection.counts.draft }} già in bozza · {{ detection.counts.missing }} mancanti</p>
                <p v-if="detection.mode !== 'brand' && detection.cars.length < 50" class="text-sm text-amber-600">Ricerca parziale: {{ detection.cars.length }} modelli con riferimenti utilizzabili su 50 richiesti. Il confronto e la lista dei mancanti includono solo questi modelli.</p>
                <p v-if="detection.notes" class="text-xs text-muted-foreground">{{ detection.notes }}</p>
                <button v-if="detection.import_list" type="button" class="rounded border px-3 py-2 text-sm" @click="fillDetectedList">Mostra i mancanti in Importa lista</button>
                <label v-if="detection.mode === 'brand' && showMissingModels" class="block text-sm">Autoradio mancanti · marca, modello e anni<textarea :value="missingModels" readonly rows="8" class="mt-2 w-full rounded-lg border bg-background p-3" /></label>
                <p v-if="!detection.import_list" class="text-sm text-emerald-600">Nessun intervallo scoperto tra i modelli verificati. Il confronto non comprende eventuali modelli non rilevati dalla ricerca.</p>
                <details class="rounded border p-3"><summary class="cursor-pointer text-sm font-medium">{{ detection.mode === 'brand' ? 'Intervalli senza autoradio' : 'Modelli e fonti' }} ({{ detection.cars.length }})</summary>
                    <div class="mt-3 max-h-96 overflow-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Marca / modello</th><th class="p-2">{{ detection.mode === 'brand' ? 'Anni mancanti' : 'Anno' }}</th><th v-if="detection.mode !== 'brand'" class="p-2">Mercato / posizione</th><th v-if="detection.mode !== 'brand'" class="p-2">Immatricolazioni</th><th class="p-2">Stato</th><th class="p-2">Fonte</th></tr></thead><tbody>
                        <tr v-for="car in detection.cars" :key="`${car.brand}-${car.model}-${car.year_from}-${car.year_to}`" class="border-t"><td class="p-2">{{ car.brand }} {{ car.model }}</td><td class="p-2">{{ detection.mode === 'brand' ? car.year_from + '–' + car.year_to : detection.year }}</td><td v-if="detection.mode !== 'brand'" class="p-2">{{ car.market }}{{ car.rank ? ' · ' + car.rank : '' }}</td><td v-if="detection.mode !== 'brand'" class="p-2">{{ car.units?.toLocaleString('it-IT') ?? 'Non disponibile' }}</td><td class="p-2" :class="car.status === 'missing' ? 'text-amber-600' : 'text-emerald-600'">{{ car.status === 'catalog' ? 'Nel catalogo' : car.status === 'draft' ? 'In bozza' : 'Autoradio assente' }}<p v-if="car.camera_only" class="mt-1 font-semibold">Presenti solo telecamere</p><p v-else-if="car.cameras_present" class="mt-1 text-xs">Telecamere presenti; autoradio non coperta per questi anni</p></td><td class="p-2"><a :href="car.source_url" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline" :title="car.evidence">Apri fonte ↗</a></td></tr>
                    </tbody></table></div>
                </details>
            </div>
        </details>
        <AutogenesiApiSearch v-if="local" id="ricerca-api" :connection="apiConnection" />
        <p v-if="workerError" role="alert" class="text-sm text-red-600">{{ workerError }}</p>
        <p v-for="(error, key) in catalogAll.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <p v-if="products.length" class="text-sm text-muted-foreground">Prima dell’importazione in Shopify, trasferisci con FileZilla anche storage/app/public/autogenesi/catalog/ su Aruba: contiene le foto corrispondenti agli URL dell’Excel.</p>
        <form v-if="local && showList" class="space-y-4 rounded-xl border bg-card p-5" @submit.prevent="importList">
            <h2 class="font-semibold">Importa lista di vetture</h2>
            <div v-if="listForm.engine === 'api'" class="flex flex-wrap gap-4">
                <label class="text-sm">Ordina autoradio per<select v-model="listForm.sort_by" class="mt-1 block rounded-lg border bg-background px-3 py-2"><option value="price">Prezzo variante più basso</option><option value="sales">Più venduti · ordini API</option><option value="best_match">Migliore corrispondenza</option></select></label>
                <p class="self-end py-2 text-sm">Partenza: prima Unione Europea; se non disponibile, Tutti.</p>
            </div>
            <p v-if="listForm.engine === 'api'" class="text-xs text-muted-foreground">Selezione fra i candidati API esaminati: origine verificata nelle varianti e ordini indicativi. Non è un filtro UE nativo della ricerca AliExpress.</p>
            <label class="block text-sm">Metodo<select v-model="listForm.engine" class="mt-1 rounded-lg border bg-background px-3 py-2" :disabled="listForm.processing"><option value="api">API · senza browser</option><option value="browser">Browser · metodo precedente</option></select></label>
            <p class="text-sm text-muted-foreground">{{ listForm.engine === 'api' ? 'Una vettura per riga, massimo 100. API: confronta le varianti dei candidati pertinenti, preferisce la partenza UE quando confermata, poi le altre origini. Crea le bozze in background senza Chrome.' : 'Una vettura per riga, massimo 100. Ricerca tramite browser e importazione automatica in bozza.' }}</p>
            <label class="block text-sm">Marca, modello e anni<textarea v-model="listForm.list" required maxlength="30000" rows="7" placeholder="Hyundai Kona 2017 2020&#10;Ford Focus 2011 2018&#10;Peugeot 2008 2013 2019" class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
            <label class="block text-sm">Oppure carica CSV/TXT<input type="file" accept=".csv,.txt,text/csv,text/plain" class="mt-1 block w-full text-sm" @change="loadList" /></label>
            <p class="text-xs text-muted-foreground">CSV: Marca;Modello;Dal;Al. È accettato anche un solo anno. Puoi chiudere questa pagina: il lavoro continua in background sul Mac.</p>
            <p v-if="fileError" role="alert" class="text-sm text-red-600">{{ fileError }}</p>
            <p v-for="(error, key) in listForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <button :disabled="listForm.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50">{{ listForm.processing ? 'Avvio lista…' : listForm.engine === 'api' ? 'Avvia importazione in background con API' : 'Avvia importazione con browser' }}</button>
        </form>
        <div v-if="local" class="flex flex-wrap items-center gap-3">
            <button type="button" :disabled="stopping" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="stopAll">{{ stopping ? 'Arresto in corso…' : 'Ferma tutto e chiudi browser' }}</button>
            <span class="text-xs text-muted-foreground">Interrompe le ricerche attive e in coda. Le bozze già importate restano salvate.</span>
            <p v-if="stopError" role="alert" class="text-sm text-red-600">{{ stopError }}</p>
        </div>
        <details v-if="batches.length" :open="batchBusy || !!workerError || batches.some(b => b.engine === 'api' && b.status === 'blocked')" class="space-y-3 rounded-xl border p-5">
            <summary class="cursor-pointer font-semibold">Importazioni da lista ({{ batches.length }}) · {{ batchBusy ? 'In elaborazione' : 'Apri storico e richieste' }}</summary>
            <p v-for="(error, key) in deletingRows.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p v-for="(error, key) in browserForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="browserOpened" class="text-sm">Completa personalmente la verifica nella finestra Chrome AutoGenesi e lasciala aperta. Poi avvia una singola richiesta oppure riprendi tutta la lista.</p>
            <details v-for="(batch, n) in batches" :key="batch.id" :open="n === 0" class="rounded-xl border bg-card p-4">
                <summary class="cursor-pointer text-sm font-medium">Lista #{{ batch.id }} · {{ batch.engine === 'api' ? 'API · senza browser' : 'Browser' }} · {{ batch.engine === 'api' && batch.status === 'blocked' ? 'Autorizzazione API richiesta' : batchLabel(batch.status) }} · {{ batch.items.filter(i => i.status === 'completed').length }}/{{ batch.total }} importate</summary>
                <div class="mt-3 max-h-96 overflow-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Vettura</th><th class="p-2">Stato</th><th class="p-2">Risultato</th><th class="p-2">Azione</th></tr></thead><tbody><tr v-for="item in batch.items" :key="item.id" class="border-t"><td class="p-2">{{ item.query.replace(/^radio /i, '') }}</td><td class="p-2">{{ batch.engine === 'api' && item.status === 'blocked' ? 'Autorizzazione API richiesta' : batchLabel(item.status) }}</td><td class="space-y-1 p-2"><p v-if="item.error" class="text-amber-700">{{ item.error }}</p><button v-if="item.product_id" type="button" class="mr-3 text-blue-600 underline" @click="openBatchProduct(item.product_id)">Apri bozza #{{ item.product_id }}</button><a v-if="item.source_url" :href="item.source_url" target="_blank" rel="noreferrer" class="text-blue-600 underline">Prodotto AliExpress</a></td><td class="p-2"><button v-if="local && ['queued', 'failed', 'not_found', 'blocked', 'stopped'].includes(item.status)" type="button" :disabled="batchBusy || itemForm.processing || retryingList !== null" class="whitespace-nowrap rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="retryItem(batch.id, item.id)">{{ pendingItem === item.id ? 'Avvio…' : 'Avvia solo questa' }}</button><button v-if="local" type="button" :disabled="batchBusy || deletingRows.processing || itemForm.processing || retryingList !== null" class="ml-2 rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 disabled:opacity-50" @click="removeBatchRows(batch, item.id)">Cancella</button></td></tr></tbody></table></div>
                <button v-if="local && batch.items.some(item => item.status === 'completed')" type="button" :disabled="batchBusy || deletingRows.processing || itemForm.processing || retryingList !== null" class="mt-3 rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 disabled:opacity-50" @click="removeBatchRows(batch)">Cancella righe completate</button>
                <div v-if="local && ['blocked', 'completed_with_errors', 'paused', 'stopped'].includes(batch.status)" class="mt-3 flex flex-wrap gap-3">
                    <button v-if="batch.status === 'blocked' && batch.engine !== 'api'" type="button" :disabled="browserForm.processing" class="rounded-lg bg-foreground px-3 py-2 text-sm text-background disabled:opacity-50" @click="openBrowser">{{ browserForm.processing ? 'Apertura browser…' : 'Apri browser per verifica AliExpress' }}</button>
                    <button type="button" :disabled="retryingList !== null || browserForm.processing || itemForm.processing || batchBusy" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="retryList(batch.id)">{{ batch.status === 'blocked' ? (batch.engine === 'api' ? 'Autorizzazione completata: riprendi lista' : 'Verifica completata: riprendi lista') : 'Riprova righe non completate' }}</button>
                </div>
            </details>
        </details>
        <details v-if="local" id="strumenti-browser" :open="!!search || !!directImport || readyToImport || Object.keys(importing.errors).length > 0 || Object.keys(searching.errors).length > 0" class="space-y-4 rounded-xl border p-5">
            <summary class="cursor-pointer text-lg font-semibold">Ricerca e importazione con browser</summary>
            <p class="text-sm text-muted-foreground">Ricerca miglior prezzo, importazione da link ed estensione Chrome. Questi strumenti usano il browser; la ricerca API è nella sezione iniziale.</p>
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
            <summary class="cursor-pointer font-semibold">Importa dal tuo Chrome · estensione</summary>
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
        </details>
        <h2 id="autogenesi-drafts" class="scroll-mt-6 text-lg font-semibold">Prodotti in bozza · {{ products.length }}</h2>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted"><tr><th class="p-3">Prodotto</th><th class="p-3">Auto / anni</th><th class="p-3">Stato</th><th class="p-3">Immagini</th><th class="p-3">Varianti</th><th class="p-3">Link prodotto</th><th class="p-3">Azioni</th></tr></thead>
                <tbody><tr v-for="p in products" :key="p.id" class="border-t"><td class="p-3"><button class="text-left font-medium underline underline-offset-4" @click="open(p)">{{ p.title }}</button></td><td class="p-3"><template v-if="p.metadata.vehicle_compatibilities?.length"><p v-for="(vehicle, i) in p.metadata.vehicle_compatibilities" :key="i">{{ vehicle.brand }} {{ vehicle.model }}<br />{{ vehicle.year_from }}–{{ vehicle.year_to }}</p></template><template v-else>{{ p.brand }} {{ p.model }}<br />{{ p.year_from }}–{{ p.year_to }}</template></td><td class="p-3"><span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-900">{{ p.metadata.configurator_import ? 'Importata nel configuratore' : 'Bozza · non visibile' }}</span></td><td class="p-3">{{ p.images.length }}</td><td class="p-3">{{ p.variants.length }}</td><td class="p-3"><a :href="p.source_url" class="break-all text-blue-600 underline" rel="noreferrer">{{ p.source_url }}</a><a v-for="url in p.metadata.additional_source_urls || []" :key="url" :href="url" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-blue-600 underline">{{ url }} ↗</a></td><td class="p-3"><button type="button" class="text-red-600 underline" @click="deleteProduct(p)">Cancella prodotto</button></td></tr><tr v-if="!products.length"><td colspan="7" class="p-6 text-muted-foreground">Nessuna bozza importata.</td></tr></tbody>
            </table>
        </div>
        </template>
        <section v-if="selected" id="autogenesi-review" class="space-y-5 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">{{ catalogEdit ? 'Scheda prodotto' : 'Verifica bozza' }} #{{ selected.id }}</h2><button type="button" :disabled="recreatingVariants || editing.processing || addingVariant.processing || removingVariant.processing || recreating.processing || reimporting.processing || imageAi.processing || dashboard.processing || layout.processing || uploading.processing || cropping.processing || painting.processing || cleanup.processing || removing || sheetStopping || !vehicleValid || Object.values(costQuotes).some(q => q.busy)" class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" @click="save">{{ editing.processing ? 'Salvataggio…' : 'Salva scheda prodotto' }}</button><button type="button" :disabled="sheetStopping" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50" @click="interruptSheet">{{ sheetStopping ? 'Interruzione in corso…' : 'Interrompi elaborazioni' }}</button><a :href="selected.source_url" class="text-sm text-blue-600 underline" rel="noreferrer">Verifica sul sito originale</a></div>
            <p class="text-xs text-muted-foreground">Salva scheda prodotto conserva titolo, descrizione, compatibilità, link e varianti. Le azioni sulle foto si salvano subito.</p>
            <p v-if="editing.recentlySuccessful" role="status" class="text-sm text-emerald-500">Scheda prodotto salvata.</p>
            <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-500">{{ error }}</p>
            <p v-if="sheetStopMessage" role="status" class="text-sm text-emerald-500">{{ sheetStopMessage }}</p><p v-if="sheetStopError" role="alert" class="text-sm text-red-500">{{ sheetStopError }}</p>
            <section v-if="selected.metadata.variant_acquisition?.complete === false || selected.metadata.warnings?.includes('Non tutte le varianti sono state prese: introdurle manualmente.')" class="space-y-2 rounded-lg border border-amber-500 bg-amber-500/10 p-4" role="status">
                <h3 class="font-semibold text-amber-600 dark:text-amber-400">Avvisi del prodotto</h3>
                <p class="text-sm">Non tutte le varianti sono state prese: introdurle manualmente.</p>
                <p v-if="selected.metadata.variant_acquisition" class="text-sm">Acquisite {{ selected.metadata.variant_acquisition.captured }} varianti su {{ selected.metadata.variant_acquisition.detected }} rilevate.</p>
                <details v-if="selected.metadata.variant_acquisition?.skipped.length" class="text-sm"><summary class="cursor-pointer">Varianti non acquisite</summary><ul class="mt-2 list-disc pl-5"><li v-for="label in selected.metadata.variant_acquisition.skipped" :key="label">{{ label }}</li></ul></details>
            </section>
            <p v-if="!catalogEdit" class="text-sm text-muted-foreground">{{ selected.metadata.seller || 'Venditore da verificare' }} · {{ selected.metadata.captured_at }}</p>
            <a v-if="selected.source_url" :href="selected.source_url" target="_blank" rel="noopener noreferrer" class="block break-all text-sm text-blue-600 underline underline-offset-2">{{ selected.source_url }} ↗</a>
            <form class="space-y-3 rounded-lg border p-4" @submit.prevent="save">
                <h3 class="text-sm font-semibold">Altri link del prodotto</h3>
                <div v-for="(_, i) in editing.additional_source_urls" :key="i" class="flex flex-wrap items-center gap-2">
                    <input v-model="editing.additional_source_urls[i]" type="url" maxlength="4096" placeholder="https://…" aria-label="Link prodotto aggiuntivo" class="min-w-0 flex-1 rounded border bg-background px-3 py-2 text-sm" :disabled="editing.processing" />
                    <a v-if="/^https?:\/\//i.test(editing.additional_source_urls[i])" :href="editing.additional_source_urls[i]" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600 underline">Apri ↗</a>
                    <button type="button" class="rounded border px-3 py-2 text-sm text-red-600" :disabled="editing.processing" @click="editing.additional_source_urls.splice(i, 1)">Rimuovi link</button>
                </div>
                <p v-for="(error, key) in Object.fromEntries(Object.entries(editing.errors).filter(([key]) => key.startsWith('additional_source_urls')))" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3"><button type="button" class="rounded border px-3 py-2 text-sm" :disabled="editing.processing || editing.additional_source_urls.length >= 30" @click="editing.additional_source_urls.push('')">Aggiungi link</button></div>
            </form>
            <details v-if="selected.metadata.description_source" class="rounded-lg border p-3 text-sm">
                <summary class="cursor-pointer font-medium">Testo originale e specifiche lette · {{ selected.metadata.description_source.specifications.length }} voci · {{ selected.metadata.description_source.images.length }} immagini della descrizione</summary>
                <p class="mt-3 whitespace-pre-line">{{ selected.metadata.description_source.text }}</p>
                <dl class="mt-3 grid gap-2 sm:grid-cols-2"><div v-for="(spec, i) in selected.metadata.description_source.specifications" :key="i"><dt class="font-medium">{{ spec.name }}</dt><dd>{{ spec.value }}</dd></div></dl>
            </details>
            <section class="space-y-3 rounded-lg border p-4">
                <h3 class="font-semibold">Auto compatibili · TRASFORMA</h3>
                <VehicleCompatibilityEditor :key="selected.id" v-model="editing.vehicles" @valid="vehicleValid = $event" :disabled="editing.processing || sheetStopping" />
                <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </section>
            <p v-for="(error, key) in imageAi.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <form v-if="local" class="space-y-3 rounded-lg border p-3" @submit.prevent="uploadPhotos">
                <div class="flex flex-wrap items-center gap-4"><h3 class="text-sm font-medium">Aggiungi foto dal computer</h3><button type="button" :disabled="reimporting.processing || !selected.source_url || editing.processing || uploading.processing || imageAi.processing || layout.processing || dashboard.processing || cropping.processing || painting.processing || cleanup.processing || removing" class="rounded border border-sky-400/50 bg-sky-400/10 px-3 py-2 text-sm disabled:opacity-50" @click="reimportImages">{{ reimporting.processing ? 'Recupero immagini in corso…' : 'Reimporta immagini prodotto' }}</button></div>
                <p class="text-xs text-muted-foreground">Dal fornitore: 2–3 foto prodotto, anche dalle varianti, una foto accessori e una prima/dopo quando disponibili. Tabelle tecniche e pubblicità escluse.</p>
                <p v-if="reimportImagesResult" role="status" class="text-sm text-emerald-500">{{ reimportImagesResult }}</p>
                <p v-for="(error, key) in reimporting.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <input :key="uploadKey" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.avif,.gif,.bmp,.heic,.heif,.tif,.tiff,image/jpeg,image/png,image/webp,image/avif,image/gif,image/bmp,image/heic,image/heif,image/tiff" :disabled="uploading.processing" class="block w-full text-sm" @change="loadPhotos" />
                <p class="text-xs text-muted-foreground">JPG, PNG, WebP, AVIF, GIF, BMP, HEIC/HEIF e TIFF. Massimo 10 foto da 8 MB. Proporzioni originali; GIF e TIFF vengono acquisiti come immagini statiche.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-sm">Tipo foto <select v-model="uploading.role" class="ml-2 rounded border bg-background px-2 py-1"><option value="prodotto">Prodotto</option><option value="accessori">Accessori / package list</option><option value="cruscotto_prima_dopo">Prima / dopo installazione</option></select></label>
                    <button :disabled="!uploading.photos.length || uploading.processing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || removing" class="rounded border px-3 py-2 text-sm disabled:opacity-50">{{ uploading.processing ? 'Caricamento e conversione…' : 'Aggiungi foto' }}</button>
                </div>
                <p v-for="(error, key) in uploading.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </form>
            <div v-if="local" class="space-y-2">
                <button type="button" :disabled="dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing || removing" class="rounded border px-3 py-2 text-sm disabled:opacity-50" @click="generateDashboard">{{ dashboard.processing ? 'Ricerca foto cruscotto in corso…' : 'Trova foto cruscotto originale' }}</button>
                <p class="text-xs text-muted-foreground">Ricerca: {{ dashboardQuery }} · Importa una foto reale trovata online, da verificare.</p>
                <p v-if="dashboard.wasSuccessful" role="status" class="text-sm text-emerald-500">Foto reale del cruscotto aggiunta alle immagini. Verifica modello e fonte.</p>
                <p v-for="(error, key) in dashboard.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </div>
            <p v-for="(error, key) in layout.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <p class="text-xs text-muted-foreground">La prima immagine è la foto principale. Usa Prima / Dopo per riordinarle; i flag Cruscotto originale scelgono le foto per il metafield e il configuratore.</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <figure v-for="(image, i) in selected.images" :key="image.id" class="rounded-lg border p-3">
                    <img :src="image.url" :alt="`Foto ${i + 1} — ${selected.title}`" @load="image.width = ($event.target as HTMLImageElement).naturalWidth; image.height = ($event.target as HTMLImageElement).naturalHeight" class="h-auto w-full rounded" />
                    <figcaption class="mt-2 space-y-3 text-xs">
                        <p>Foto {{ i + 1 }} · {{ image.width }}×{{ image.height }}<br />{{ imageRole(image.role) }}<span v-if="image.ai_generated" class="ml-2 font-medium text-violet-700">Generata con AI · da verificare</span><span v-else-if="image.ai_edited" class="ml-2 font-medium text-violet-700">Modificata con AI</span><span v-if="image.resolution_upscaled" class="ml-2 font-medium text-emerald-600">Risoluzione aumentata</span><span v-else-if="image.image_reframed" class="ml-2 font-medium text-emerald-600">Impaginata senza AI</span></p>
                        <details v-if="image.web_sources?.length"><summary>Fonti cruscotto</summary><a v-for="url in image.web_sources" :key="url" :href="url" target="_blank" rel="noopener noreferrer" class="block break-all underline">{{ url }}</a></details>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :disabled="i === 0 || layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing" class="rounded border px-2 py-1 disabled:opacity-50" @click="moveImage(i, -1)">← Prima</button>
                            <button type="button" :disabled="i === selected.images.length - 1 || layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing" class="rounded border px-2 py-1 disabled:opacity-50" @click="moveImage(i, 1)">Dopo →</button>
                        </div>
                        <label class="flex items-center gap-2"><input type="checkbox" :checked="image.dashboard_selected" :disabled="layout.processing || dashboard.processing || imageAi.processing || cropping.processing || uploading.processing || removing" @change="changeLayout({ dashboard_image_ids: selected.images.filter(p => p.id === image.id ? !p.dashboard_selected : p.dashboard_selected).map(p => p.id) })" />Cruscotto originale · foto per il metafield</label>
                        <label v-if="image.dashboard_selected" class="block space-y-1"><span>Riferimento Shopify manuale (facoltativo)</span><input :value="image.shopify_file_reference" :disabled="layout.processing" type="text" maxlength="255" placeholder="cruscotto-originale.jpg" class="w-full rounded border bg-background px-2 py-1" @change="dashboardReference(image.id, $event)" /><span class="block text-muted-foreground">Lascia vuoto per usare automaticamente il nome della foto selezionata nell’export. Il link Aruba completo sarà in Image Src. Se il file Shopify ha un nome diverso, inseriscilo qui; il campo si salva quando esci.</span></label>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border border-violet-300 px-2 py-1 text-violet-700 disabled:opacity-50" @click="editImage(i, image.id)">{{ imageAi.processing && imageAi.image_id === image.id && imageAction === 'ai' ? 'Modifica AI in corso…' : 'Modifica con AI' }}</button>
                            <button v-if="local" type="button" title="Sfoca le scritte selezionate senza AI e senza modificare il resto della foto" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startPaint(i, image.id, image.url, 'cover')">Cancella scritte</button>
                            <button v-if="local && (image.ai_edited || image.image_reframed || image.resolution_upscaled || image.manually_cropped)" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="editImage(i, image.id, 'restore')">Ripristina originale</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startCrop(i, image.id, image.url)">Taglia</button>
                            <button v-if="local" type="button" :disabled="Math.max(image.width, image.height) >= 2048 || removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" title="Raddoppia i pixel fino a 2048 sul lato più lungo; non recupera dettagli assenti nell’originale" class="rounded border px-2 py-1 disabled:opacity-50" @click="editImage(i, image.id, 'upscale')">{{ imageAi.processing && imageAi.image_id === image.id && imageAction === 'upscale' ? 'Aumento risoluzione…' : 'Aumenta risoluzione' }}</button>
                            <label v-if="local && Math.max(image.width, image.height) < 2048" class="flex items-center gap-2">Nitidezza <input type="range" min="0" max="100" step="5" :value="sharpness[image.id] ?? 35" :disabled="imageAi.processing" @input="sharpness[image.id] = Number(($event.target as HTMLInputElement).value)" /><span>{{ sharpness[image.id] ?? 35 }}%</span></label>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startPaint(i, image.id, image.url)">Paint</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="startPaint(i, image.id, image.url, 'text')">TESTO</button>
                            <button v-if="local" type="button" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded border px-2 py-1 disabled:opacity-50" @click="editImage(i, image.id, 'white')">{{ imageAi.processing && imageAi.image_id === image.id && imageAction === 'white' ? 'Ricreazione accessori…' : 'Ricrea accessori' }}</button>
                            <button type="button" class="rounded border border-red-200 px-2 py-1 text-red-600 disabled:opacity-50" :disabled="removing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" @click="removeImage(i, image.id)">Rimuovi</button>
                        </div>
                        <p v-if="imageAi.image_id === image.id && imageAi.errors.image_ai" role="alert" class="text-sm text-red-500">{{ imageAi.errors.image_ai }}</p>
                    </figcaption>
                </figure>
            </div>
            <p v-if="selected.images.some(image => image.local_only)" class="text-sm text-amber-700">Gli originali restano nella bozza. Le copie per l’export vengono preparate in storage/app/public/autogenesi/catalog/: trasferisci anche questa cartella su Aruba con FileZilla.</p>
            <form class="space-y-4" @submit.prevent="save">
                <label class="block text-sm font-medium">Titolo<input v-model="editing.title" required class="mt-1 w-full rounded-md border bg-background px-3 py-2" /></label>
                <div class="space-y-1"><div class="flex flex-wrap items-center gap-3"><p class="text-sm font-medium">Descrizione</p><button v-if="local" type="button" :disabled="recreating.processing || editing.processing || !selected.source_url || reimporting.processing" class="rounded border border-sky-400/50 bg-sky-400/10 px-3 py-2 text-sm disabled:opacity-50" @click="recreateDescription">{{ recreating.processing ? 'Lettura e generazione in corso…' : 'Ricrea descrizione da link prodotto' }}</button></div><p v-for="(error, key) in recreating.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p><p v-if="descriptionMessage" role="status" class="text-sm text-emerald-500">{{ descriptionMessage }}</p><details v-if="selected.metadata.description_notes?.length" class="text-sm"><summary class="cursor-pointer text-amber-500">Dati da verificare</summary><ul class="list-disc pl-5"><li v-for="note in selected.metadata.description_notes" :key="note">{{ note }}</li></ul></details><DescriptionEditor :key="`${selected.id}-${descriptionRevision}-${selected.body_html}`" v-model="editing.body_html" label="Descrizione prodotto" /></div>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Variante dichiarata</th><th class="p-2">SKU interno</th><th class="p-2">Costo fornitore EUR</th><th class="p-2">Prezzo di vendita EUR</th><th class="p-2">Azione</th></tr></thead><tbody><tr v-for="(v, i) in selected.variants" :key="v.sku" class="border-t"><td class="p-2"><input v-if="editing.variants[i]" v-model="editing.variants[i].label" type="text" required maxlength="500" class="w-72 rounded border bg-background px-2 py-1" /><p class="mt-1 text-xs text-red-600">{{ editing.errors[`variants.${i}.label`] }}</p></td><td class="p-2 text-xs">{{ v.sku }}</td><td class="p-2"><input v-if="editing.variants[i]" v-model="editing.variants[i].supplier_price" @input="recalculateCost(v.sku, $event)" :required="!catalogEdit" type="number" min="0.01" max="999999" step="0.01" aria-label="Costo fornitore EUR" class="w-36 rounded border bg-background px-2 py-1" /><p class="mt-1 text-xs text-red-600">{{ editing.errors[`variants.${i}.supplier_price`] }}</p><p v-if="v.manual" class="text-xs text-muted-foreground">Inserito manualmente</p><p v-if="costQuotes[v.sku]?.busy" class="mt-1 text-xs text-muted-foreground">Ricalcolo prezzo…</p><p v-if="costQuotes[v.sku]?.error" class="mt-1 text-xs text-red-600">{{ costQuotes[v.sku].error }}</p><p v-if="costQuotes[v.sku]?.markup" class="mt-1 text-xs text-muted-foreground">Ricarico {{ money(costQuotes[v.sku].markup!) }} · Stima dogana {{ money(costQuotes[v.sku].customs_estimate!) }}</p><p v-if="v.pricing && Number(editing.variants[i]?.supplier_price) === Number(v.supplier_price)" class="mt-1 text-xs text-muted-foreground">Ricarico {{ money(v.pricing.markup) }} · Stima dogana {{ money(v.pricing.customs_estimate) }}</p></td><td class="p-2"><input v-if="editing.variants[i]" v-model="editing.variants[i].selling_price" type="number" :min="catalogEdit ? 0.01 : 1" :step="catalogEdit ? 0.01 : 1" placeholder="Da impostare" class="w-36 rounded border bg-background px-2 py-1" /></td><td class="p-2"><button v-if="local" type="button" :disabled="recreatingVariants || addingVariant.processing || removingVariant.processing || editing.processing || selected.variants.length < 2" :title="selected.variants.length < 2 ? 'Deve rimanere almeno una variante' : 'Elimina questa variante'" class="whitespace-nowrap rounded border border-red-200 px-2 py-1 text-xs text-red-600 disabled:opacity-50" @click="deleteVariant(v.sku)">{{ removingVariant.processing && removingVariant.sku === v.sku ? 'Eliminazione…' : 'Elimina variante' }}</button></td></tr></tbody></table></div>
                <p v-if="!catalogEdit || catalogRadio" class="text-xs text-muted-foreground">Modificando il costo, la formula aggiorna automaticamente il prezzo di vendita: ricarico, dogana secondo la regola attiva e arrotondamento in eccesso. Premi Salva per conservare le modifiche. Puoi poi modificare manualmente la vendita.</p>
                <button v-if="local" type="button" :disabled="recreatingVariants || addingVariant.processing || editing.processing || removingVariant.processing" class="rounded border px-4 py-2 text-sm font-medium disabled:opacity-50" @click="showAddVariant = !showAddVariant">{{ showAddVariant ? 'Chiudi nuova variante' : '+ Aggiungi variante' }}</button>
                <button v-if="local && catalogEdit" type="button" :disabled="recreatingVariants || (catalogRadio && variantChanges) || addingVariant.processing || editing.processing || removingVariant.processing" class="ml-3 rounded border border-amber-400 bg-amber-400/15 px-4 py-2 text-sm font-medium text-amber-700 dark:text-amber-200 disabled:opacity-50" @click="recreateVariants">{{ recreatingVariants ? 'Ricreazione varianti…' : 'Ricrea varianti' }}</button>
                <button type="button" :disabled="recreatingVariants || editing.processing || addingVariant.processing || removingVariant.processing || (local && (!vehicleValid || Object.values(costQuotes).some(q => q.busy)))" class="ml-3 rounded border border-emerald-500/50 bg-emerald-500/10 px-4 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-300 disabled:opacity-50" @click="exportProduct">Export · Shopify</button>
                <p v-if="errors?.export" role="alert" class="text-sm text-red-600">{{ errors.export }}</p>
                <p v-if="local && catalogEdit && catalogRadio && variantChanges" class="text-xs text-amber-600">Salva le modifiche alle varianti e ai link prima di ricrearle.</p>
                <p v-if="variantsMessage" role="status" class="text-sm text-emerald-600">{{ variantsMessage }}</p>
                <p v-if="variantsError" role="alert" class="text-sm text-red-600">{{ variantsError }}</p>
                <section v-if="local && showAddVariant" class="space-y-3 rounded-lg border p-4">
                    <h3 class="text-sm font-semibold">Nuova variante manuale</h3>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">Nome variante<input v-model="addingVariant.label" type="text" maxlength="500" placeholder="8Core 8GB 128GB" class="mt-1 w-full rounded border bg-background px-3 py-2" :disabled="addingVariant.processing" /></label>
                        <label class="text-sm">Prezzo fornitore EUR<input v-model="addingVariant.supplier_price" type="number" min="0.01" max="999999" step="0.01" class="mt-1 w-full rounded border bg-background px-3 py-2" :disabled="addingVariant.processing" /></label>
                        <label class="text-sm">Prezzo vendita EUR (facoltativo)<input v-model="addingVariant.selling_price" type="number" min="1" max="999999" step="1" placeholder="Calcolato automaticamente" class="mt-1 w-full rounded border bg-background px-3 py-2" :disabled="addingVariant.processing" /></label>
                    </div>
                    <p v-if="!catalogEdit || catalogRadio" class="text-xs text-muted-foreground">SKU automatico. Lascia vuoto il prezzo di vendita per applicare ricarico e stima doganale. La variante sarà ordinata per prezzo e inclusa nell’export.</p>
                    <p v-for="(error, key) in addingVariant.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button type="button" :disabled="recreatingVariants || addingVariant.processing || editing.processing || removingVariant.processing" class="rounded bg-foreground px-4 py-2 text-sm text-background disabled:opacity-50" @click="addVariant">{{ addingVariant.processing ? 'Aggiunta…' : (catalogEdit ? 'Aggiungi variante al prodotto' : 'Aggiungi variante alla bozza') }}</button>
                </section>
                <p v-for="(error, key) in removingVariant.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            </form>
            <section v-if="local && !catalogEdit" class="space-y-3 rounded-lg border p-4">
                <h3 class="text-sm font-semibold">Importazione nel configuratore</h3>
                <p class="text-sm">Salva e approva questa bozza: dati, varianti, prezzi e foto saranno disponibili nel catalogo di questa installazione. L’importazione in Shopify resta separata.</p>
                <p v-if="selected.metadata.configurator_import" class="text-xs text-muted-foreground">Già importata come prodotto #{{ selected.metadata.configurator_import.product_id }}. Premi nuovamente per aggiornare il configuratore con le modifiche attuali.</p>
                <p v-for="(error, key) in catalogImport.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <button type="button" :disabled="catalogImport.processing || editing.processing || removingVariant.processing || dashboard.processing || catalogAll.processing || layout.processing || imageAi.processing || cropping.processing || uploading.processing" class="rounded-lg bg-foreground px-4 py-2 text-sm font-medium text-background disabled:opacity-50" @click="importConfigurator">{{ catalogImport.processing ? 'Importazione nel configuratore…' : selected.metadata.configurator_import ? 'Aggiorna nel configuratore' : 'Approva e importa nel configuratore' }}</button>
            </section>
            <div v-if="!catalogEdit" class="flex flex-wrap gap-3 border-t pt-4"><a :href="`/autogenesi/${selected.id}/export`" class="rounded-lg border px-4 py-2 text-sm font-medium">Scarica Excel · formato modello</a></div>
            <p v-if="!catalogEdit" class="text-xs text-muted-foreground">Unico formato Excel con le colonne del modello, stato Active. Il link del fornitore resta nel database. Il file contiene soltanto il foglio Products. Gli URL immagini puntano alla produzione Aruba: trasferisci prima le foto con FileZilla e verifica gli indirizzi, poi importa l’Excel in Shopify.</p>
        </section>
        <ProductSilhouette v-if="sceneTarget" :src="sceneTarget.url" :busy="imageAi.processing" :error="Object.values(imageAi.errors).join(' ')" @cancel="sceneTarget = null" @save="applyScene" @automatic="applyAutomaticScene" />
        <ImageTextBand v-if="paintTarget && paintMode === 'text'" :src="paintTarget.url" :busy="painting.processing" :error="Object.values(painting.errors).join(' ')" @cancel="paintTarget = null" @save="applyPaint" />
        <ImageCleanupMask v-if="paintTarget && (paintMode === 'cover' || paintMode === 'enhance')" :enhance="paintMode === 'enhance'" :src="paintTarget.url" :busy="cleanup.processing || imageAi.processing" :error="Object.values(cleanup.errors).join(' ') || Object.values(imageAi.errors).join(' ')" @cancel="paintTarget = null" @save="applyCleanup" @ai="applyAiCleanup" />
        <ImagePainter v-if="paintTarget && paintMode === 'paint'" :src="paintTarget.url" :busy="painting.processing" :error="Object.values(painting.errors).join(' ')" @cancel="paintTarget = null" @save="applyPaint" />
        <SquareImageCropper v-if="cropTarget" :src="cropTarget.url" :busy="cropping.processing" :error="Object.values(cropping.errors).join(' ')" @cancel="cropTarget = null" @save="applyCrop" />
    </div>
</template>
