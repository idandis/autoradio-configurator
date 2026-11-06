<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { computed, reactive, ref } from 'vue';
import DescriptionEditor from '@/components/DescriptionEditor.vue';
import StockProductsManager from '@/components/StockProductsManager.vue';

const props = defineProps<{
    importDates?: string[];
    errors?: Record<string, string>;
    filters: {
        category: string;
        search: string;
        date: string;
    };
    products: {
        data: Array<{
            id: number;
            handle: string;
            title: string;
            title_it: string | null;
            title_en: string | null;
            body_html: string;
            body_html_it: string;
            body_html_en: string;
            category: string;
            subtype: string | null;
            brand: string | null;
            model: string | null;
            year_from: number | null;
            year_to: number | null;
            price_min: string | null;
            variants_count: number;
            variants: Array<{ id: number; title: string | null; sku: string | null; price: string | null }>;
            source_url: string | null;
            image_url: string | null;
            images: string[];
            dashboard_image_url: string | null;
            dashboard_image_urls: string[];
        }>;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
}>();

const exportDate = ref(props.filters.date || props.importDates?.[0] || '');
const filters = reactive({
    category: props.filters.category || '',
    search: props.filters.search || '',
    date: props.filters.date || '',
});
const editingPrice = ref<number | null>(null);
const priceDraft = ref('');
const savingPrice = ref(false);
const deletingProduct = ref<number | null>(null);
const dashboardProduct = ref<(typeof props.products.data)[number] | null>(null);
const dashboardOpen = ref(false);
const dashboardForm = useForm({ image_urls: [] as string[] });
const openDashboard = (product: (typeof props.products.data)[number]) => {
    dashboardProduct.value = product;
    dashboardForm.clearErrors();
    dashboardForm.image_urls = [...product.dashboard_image_urls];
    dashboardOpen.value = true;
};
const saveDashboard = () => {
    if (!dashboardProduct.value || dashboardForm.processing) return;
    dashboardForm.patch(`/imported-products/${dashboardProduct.value.id}/dashboard-image`, { preserveScroll: true, onSuccess: () => { dashboardOpen.value = false; } });
};
const vehicleProduct = ref<(typeof props.products.data)[number] | null>(null);
const vehicleOpen = ref(false);
const vehicleForm = useForm({ brand: '', model: '', year_from: null as number | null, year_to: null as number | null });
const vehicleRows = ref<{ brand: string; model: string }[]>([]);
const openVehicle = (product: (typeof props.products.data)[number]) => {
    vehicleProduct.value = product;
    vehicleForm.clearErrors();
    vehicleForm.brand = product.brand || '';
    vehicleForm.model = product.model || '';
    vehicleForm.year_from = product.year_from;
    vehicleForm.year_to = product.year_to;
    const brands = (product.brand || '').split(/\s*\|\s*/).filter(Boolean);
    vehicleRows.value = (product.model || '').split(/\s*\|\s*/).filter(Boolean).flatMap(value => {
        const match = value.match(/^\s*(\d+)\s*[:：]\s*(.+)$/);
        if (match) return [{ brand: brands[Number(match[1]) - 1] || '', model: match[2] }];
        return brands.map(brand => ({ brand, model: value }));
    });
    if (!vehicleRows.value.length) vehicleRows.value = [{ brand: '', model: '' }];
    vehicleOpen.value = true;
};
const transformVehicle = () => {
    const rows = vehicleRows.value.map(row => ({ brand: row.brand.trim().toUpperCase(), model: row.model.trim().toUpperCase() }));
    if (rows.some(row => !row.brand || !row.model)) { vehicleForm.setError('model', 'Compila marca e modello in ogni riga.'); return; }
    const brands = [...new Set(rows.map(row => row.brand))];
    vehicleForm.brand = brands.join(' | ');
    vehicleForm.model = [...new Set(rows.map(row => `${brands.indexOf(row.brand) + 1}:${row.model}`))].join(' | ');
    vehicleForm.clearErrors('brand', 'model');
};
const saveVehicle = () => {
    if (!vehicleProduct.value || vehicleForm.processing) return;
    vehicleForm.patch(`/imported-products/${vehicleProduct.value.id}/vehicle`, { preserveScroll: true, onSuccess: () => { vehicleOpen.value = false; } });
};
const titleProduct = ref<(typeof props.products.data)[number] | null>(null);
const titlesOpen = ref(false);
const titleForm = useForm({ title: '', title_it: '', title_en: '' });
const variantProduct = ref<(typeof props.products.data)[number] | null>(null);
const variantsOpen = ref(false);
const variantForm = useForm({ variants: [] as Array<{ id: number; price: string }> });
const variantError = (i: number) => {
    const errors = variantForm.errors as Record<string, string>;
    return errors[`variants.${i}.price`] ?? errors[`variants.${i}.id`];
};

const descriptionProduct = ref<(typeof props.products.data)[number] | null>(null);
const descriptionOpen = ref(false);
const descriptionLocale = ref<'it' | 'en'>('it');
const descriptionForm = useForm({ body_html_it: '', body_html_en: '' });
const descriptionDraft = computed({
    get: () => descriptionLocale.value === 'it' ? descriptionForm.body_html_it : descriptionForm.body_html_en,
    set: (value: string) => { if (descriptionLocale.value === 'it') descriptionForm.body_html_it = value; else descriptionForm.body_html_en = value; },
});
const openDescription = (product: (typeof props.products.data)[number]) => {
    descriptionProduct.value = product;
    descriptionLocale.value = 'it';
    descriptionForm.clearErrors();
    descriptionForm.body_html_it = product.body_html_it || '';
    descriptionForm.body_html_en = product.body_html_en || '';
    descriptionOpen.value = true;
};
const saveDescription = () => {
    if (!descriptionProduct.value || descriptionForm.processing) return;
    descriptionForm.patch(`/imported-products/${descriptionProduct.value.id}/description`, {
        preserveScroll: true,
        onSuccess: () => { descriptionOpen.value = false; },
    });
};

const openVariants = (product: (typeof props.products.data)[number]) => {
    variantProduct.value = product;
    variantForm.clearErrors();
    variantForm.variants = product.variants.map((v) => ({ id: v.id, price: v.price ?? '' }));
    variantsOpen.value = true;
};

const saveVariants = () => {
    if (!variantProduct.value) return;
    variantForm.patch(`/imported-products/${variantProduct.value.id}/variants`, {
        preserveScroll: true,
        onSuccess: () => { variantsOpen.value = false; },
    });
};

const startTitleEdit = (product: (typeof props.products.data)[number]) => {
    titleProduct.value = product;
    titleForm.clearErrors();
    titleForm.title = product.title;
    titleForm.title_it = product.title_it ?? '';
    titleForm.title_en = product.title_en ?? '';
    titlesOpen.value = true;
};
const saveTitles = () => {
    if (!titleProduct.value || titleForm.processing) return;
    titleForm.patch(`/imported-products/${titleProduct.value.id}/titles`, {
        preserveScroll: true,
        onSuccess: () => { titlesOpen.value = false; },
    });
};

const startPriceEdit = (product: (typeof props.products.data)[number]) => {
    editingPrice.value = product.id;
    priceDraft.value = product.price_min ?? '';
};

const savePrice = (product: (typeof props.products.data)[number]) => {
    savingPrice.value = true;
    router.patch(`/imported-products/${product.id}/price`, { price: priceDraft.value }, {
        preserveScroll: true,
        onFinish: () => { savingPrice.value = false; editingPrice.value = null; },
    });
};

const deleteProduct = (product: (typeof props.products.data)[number]) => {
    const variants = product.variants_count === 1
        ? '1 variante associata'
        : `${product.variants_count} varianti associate`;

    if (!window.confirm(`Eliminare definitivamente “${product.title}” e ${variants}?`)) {
        return;
    }

    deletingProduct.value = product.id;
    router.delete(`/imported-products/${product.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingProduct.value = null;
        },
    });
};

const applyFilters = () => {
    if (filters.date) exportDate.value = filters.date;
    router.get(
        '/imported-products',
        {
            category: filters.category || undefined,
            search: filters.search || undefined,
            date: filters.date || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    filters.category = '';
    filters.search = '';
    filters.date = '';
    applyFilters();
};

const formatCategory = (value: string) => {
    if (value === 'screen') return 'Schermo';
    if (value === 'camera') return 'Camera';
    if (value === 'speaker') return 'Altoparlante';
    if (value === 'accessory') return 'Accesorios';

    return value;
};

const formatVehicle = (product: (typeof props.products.data)[number]) => {
    const bits = [product.brand, product.model].filter(Boolean);
    const years =
        product.year_from && product.year_to
            ? `${product.year_from} - ${product.year_to}`
            : product.year_from
              ? `${product.year_from}+`
              : null;

    if (years) bits.push(years);

    return bits.length > 0 ? bits.join(' • ') : 'N/D';
};
</script>

<template>
    <Head title="Prodotti importati" />
    <StockProductsManager class="mb-6" />

    <section class="rounded-xl border border-sidebar-border/70 bg-card">
        <div class="flex flex-col gap-4 border-b border-sidebar-border/70 p-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-xl font-semibold">Prodotti importati</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Replica del dataset normalizzato del configuratore presente nel DB.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[180px_260px_160px_auto]">
                <select
                    v-model="filters.category"
                    class="rounded-lg border border-sidebar-border/70 bg-background px-4 py-2.5 text-sm"
                    @change="applyFilters"
                >
                    <option value="">Tutte le categorie</option>
                    <option value="screen">Schermi</option>
                    <option value="camera">Camere</option>
                    <option value="speaker">Altoparlanti</option>
                    <option value="accessory">Accesorios</option>
                </select>

                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="Cerca titolo, handle, marca, modello"
                    class="rounded-lg border border-sidebar-border/70 bg-background px-4 py-2.5 text-sm"
                    @keydown.enter.prevent="applyFilters"
                />

                <input v-model="filters.date" type="date" aria-label="Data importazione" title="Data importazione" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5 text-sm" @change="applyFilters" />

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground"
                        @click="applyFilters"
                    >
                        Filtra
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-sidebar-border/70 px-4 py-2.5 text-sm"
                        @click="resetFilters"
                    >
                        Reset
                    </button>
                </div>
            </div>
        </div>

        <form action="/imported-products/export-shopify" method="get" class="flex flex-wrap items-end gap-3 border-b border-sidebar-border/70 px-6 py-4">
            <label class="grid gap-1 text-sm">Esporta prodotti importati in data<input v-model="exportDate" name="date" type="date" required class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2" /></label>
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Esporta Excel Shopify</button>
            <p class="w-full text-xs text-muted-foreground">Tutti i prodotti importati quel giorno, in ora italiana, indipendentemente dai filtri. Per gli import storici senza data registrata si usa la data di creazione. Trasferisci le foto su Aruba prima dell’importazione Shopify.</p>
            <p v-if="errors?.date || errors?.export_date" role="alert" class="w-full text-sm text-red-400">{{ errors?.date || errors?.export_date }}</p>
        </form>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-sidebar-border/70 text-sm">
                <thead class="bg-muted/40 text-left text-muted-foreground">
                    <tr>
                        <th class="px-6 py-3 font-medium">Prodotto</th>
                        <th class="px-6 py-3 font-medium">Descrizione</th>
                        <th class="px-6 py-3 font-medium">Categoria</th>
                        <th class="px-6 py-3 font-medium">Veicolo</th>
                        <th class="px-6 py-3 font-medium">Prezzo base</th>
                        <th class="px-6 py-3 font-medium">Varianti</th>
                        <th class="px-6 py-3 text-right font-medium">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70">
                    <tr v-for="product in props.products.data" :key="product.id">
                        <td class="px-6 py-4 align-top">
                            <div class="flex items-start gap-3">
                                <img
                                    v-if="product.image_url"
                                    :src="product.image_url"
                                    :alt="product.title"
                                    class="h-12 w-12 rounded-md border border-sidebar-border/70 object-cover"
                                />
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-3"><p class="font-medium">{{ product.title }}</p><button type="button" class="shrink-0 rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium transition hover:border-primary hover:text-primary" :aria-label="`Modifica titolo ${product.title}`" @click="startTitleEdit(product)">Modifica titolo</button></div>
                                    <p v-if="product.title_it" class="mt-1 text-xs text-muted-foreground"><span class="font-semibold">IT:</span> {{ product.title_it }}</p>
                                    <p v-if="product.title_en" class="mt-1 text-xs text-muted-foreground"><span class="font-semibold">EN:</span> {{ product.title_en }}</p>
                                    <p class="mt-1 break-all text-xs text-muted-foreground">
                                        {{ product.handle }}
                                    </p>
                                    <a v-if="product.source_url" :href="product.source_url" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-primary underline underline-offset-2">Apri prodotto fornitore ↗</a>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 align-top">
                            <button type="button" class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:border-primary hover:text-primary" :aria-label="`Modifica descrizione ${product.title}`" @click="openDescription(product)">Modifica</button>
                        </td>
                        <td class="px-6 py-4 align-top">
                            <div class="space-y-1">
                                <span class="inline-flex rounded-md bg-muted px-2 py-1 text-xs font-medium">
                                    {{ formatCategory(product.category) }}
                                </span>
                                <p v-if="product.subtype" class="text-xs text-muted-foreground">
                                    {{ product.subtype }}
                                </p>
                            </div>
                        </td>
                        <td class="px-6 py-4 align-top text-muted-foreground">
                            {{ formatVehicle(product) }}
                            <button type="button" class="mt-2 block rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:border-primary hover:text-primary" @click="openVehicle(product)">Modifica veicolo</button>
                            <button type="button" class="mt-2 block rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:border-primary hover:text-primary" @click="openDashboard(product)">{{ product.dashboard_image_url ? 'Modifica foto cruscotto' : 'Imposta foto cruscotto' }}</button>
                        </td>
                        <td class="px-6 py-4 align-top">
                            <div v-if="editingPrice === product.id" class="flex items-center gap-2">
                                <input v-model="priceDraft" type="number" min="0" step="0.01" class="w-28 rounded-md border border-sidebar-border/70 bg-background px-2 py-1" @keydown.enter.prevent="savePrice(product)" />
                                <button type="button" class="rounded-md bg-primary px-2 py-1 text-xs text-primary-foreground" :disabled="savingPrice" @click="savePrice(product)">Salva</button>
                            </div>
                            <button v-else type="button" class="font-medium hover:text-primary" @click="startPriceEdit(product)">
                                {{ product.price_min ? `${product.price_min} €` : 'N/D' }}
                            </button>
                        </td>
                        <td class="px-6 py-4 align-top">
                            <button type="button" class="rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:border-primary hover:text-primary disabled:opacity-50" :disabled="!product.variants_count" @click="openVariants(product)">Varianti ({{ product.variants_count }})</button>
                        </td>
                        <td class="px-6 py-4 text-right align-top">

                            <button
                                type="button"
                                class="rounded-md border border-destructive/40 px-3 py-1.5 text-xs font-medium text-destructive transition hover:bg-destructive hover:text-destructive-foreground disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="deletingProduct === product.id"
                                @click="deleteProduct(product)"
                            >
                                {{ deletingProduct === product.id ? 'Eliminazione…' : 'Elimina' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="props.products.data.length === 0">
                        <td colspan="7" class="px-6 py-10 text-center text-muted-foreground">
                            Nessun prodotto trovato con i filtri correnti.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="props.products.links.length > 3"
            class="flex flex-wrap gap-2 border-t border-sidebar-border/70 p-4"
        >
            <component
                :is="link.url ? 'a' : 'span'"
                v-for="link in props.products.links"
                :key="link.label"
                :href="link.url || undefined"
                class="rounded-md px-3 py-2 text-sm"
                :class="
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : link.url
                          ? 'border border-sidebar-border/70 hover:bg-accent'
                          : 'text-muted-foreground'
                "
                v-html="link.label"
            />
        </div>
    </section>
    <Dialog v-model:open="dashboardOpen">
        <DialogContent class="sm:max-w-3xl max-h-[90vh] overflow-y-auto" @interact-outside="dashboardForm.processing && $event.preventDefault()" @escape-key-down="dashboardForm.processing && $event.preventDefault()">
            <DialogHeader><DialogTitle>Foto cruscotto originale</DialogTitle><DialogDescription>{{ dashboardProduct?.title }} · Seleziona le immagini che mostrano il cruscotto originale della vettura.</DialogDescription></DialogHeader>
            <form class="space-y-4" @submit.prevent="saveDashboard">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="!dashboardForm.image_urls.length" :disabled="dashboardForm.processing" @change="dashboardForm.image_urls = []" />Nessuna foto cruscotto</label>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <label v-for="(url, i) in dashboardProduct?.images || []" :key="url" class="cursor-pointer rounded-lg border p-2" :class="dashboardForm.image_urls.includes(url) ? 'border-primary ring-2 ring-primary' : 'border-sidebar-border/70'">
                        <img :src="url" :alt="`Foto prodotto ${i + 1}`" loading="lazy" class="h-36 w-full rounded object-contain" />
                        <span class="mt-2 flex items-center gap-2 text-sm"><input v-model="dashboardForm.image_urls" type="checkbox" :value="url" :disabled="dashboardForm.processing" />Foto {{ i + 1 }}</span>
                    </label>
                </div>
                <p v-if="!dashboardProduct?.images.length" class="text-sm text-muted-foreground">Il prodotto non ha immagini disponibili.</p>
                <p v-for="(error, key) in dashboardForm.errors" :key="key" role="alert" class="text-sm text-red-400">{{ error }}</p>
                <div class="flex justify-end gap-2"><button type="button" :disabled="dashboardForm.processing" class="rounded border px-4 py-2" @click="dashboardOpen = false">Annulla</button><button type="submit" :disabled="dashboardForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50">{{ dashboardForm.processing ? 'Salvataggio…' : 'Salva foto cruscotto' }}</button></div>
            </form>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="vehicleOpen">
        <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto" @interact-outside="vehicleForm.processing && $event.preventDefault()" @escape-key-down="vehicleForm.processing && $event.preventDefault()">
            <DialogHeader><DialogTitle>Modifica veicolo</DialogTitle><DialogDescription>{{ vehicleProduct?.title }}</DialogDescription></DialogHeader>
            <form class="space-y-4" @submit.prevent="saveVehicle">
                <div class="space-y-2 rounded-lg border p-3">
                    <p class="text-sm">Una riga per ogni coppia marca / modello compatibile.</p>
                    <div v-for="(row, i) in vehicleRows" :key="i" class="flex gap-2">
                        <input v-model="row.brand" :disabled="vehicleForm.processing" placeholder="Marca" :aria-label="`Marca ${i + 1}`" class="min-w-0 flex-1 rounded border bg-background px-3 py-2" />
                        <input v-model="row.model" :disabled="vehicleForm.processing" placeholder="Modello" :aria-label="`Modello ${i + 1}`" class="min-w-0 flex-1 rounded border bg-background px-3 py-2" />
                        <button type="button" :disabled="vehicleRows.length === 1 || vehicleForm.processing" class="rounded border px-2 disabled:opacity-50" @click="vehicleRows.splice(i, 1)">Rimuovi</button>
                    </div>
                    <div class="flex gap-2"><button type="button" :disabled="vehicleForm.processing" class="rounded border px-3 py-2 text-sm" @click="vehicleRows.push({ brand: '', model: '' })">Aggiungi marca / modello</button><button type="button" :disabled="vehicleForm.processing" class="rounded border px-3 py-2 text-sm" @click="transformVehicle">Trasforma</button></div>
                </div>
                <label class="block text-sm">Marche<input v-model="vehicleForm.brand" required class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                <label class="block text-sm">Modelli<input v-model="vehicleForm.model" required class="mt-1 w-full rounded border bg-background px-3 py-2" /></label>
                <p class="text-xs text-muted-foreground">Premi Trasforma per trasferire le righe nei campi: KIA | HYUNDAI e 1:STONIC | 2:KONA. Puoi anche modificare direttamente questi campi.</p>
                <div class="grid grid-cols-2 gap-3"><label class="text-sm">Dal<input v-model.number="vehicleForm.year_from" required type="number" min="1" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label><label class="text-sm">Al<input v-model.number="vehicleForm.year_to" required type="number" :min="vehicleForm.year_from || 1" class="mt-1 w-full rounded border bg-background px-3 py-2" /></label></div>
                <p v-for="(error, key) in vehicleForm.errors" :key="key" role="alert" class="text-sm text-red-400">{{ error }}</p>
                <div class="flex justify-end gap-2"><button type="button" :disabled="vehicleForm.processing" class="rounded border px-4 py-2" @click="vehicleOpen = false">Annulla</button><button type="submit" :disabled="vehicleForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50">{{ vehicleForm.processing ? 'Salvataggio…' : 'Salva veicolo' }}</button></div>
            </form>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="titlesOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl" @interact-outside="titleForm.processing && $event.preventDefault()" @escape-key-down="titleForm.processing && $event.preventDefault()">
            <DialogHeader><DialogTitle>Modifica titolo</DialogTitle><DialogDescription>{{ titleProduct?.title }}</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveTitles">
                <label class="grid gap-1 text-sm">Spagnolo<textarea v-model="titleForm.title" required rows="3" maxlength="255" class="w-full rounded-lg border border-sidebar-border/70 bg-background p-3" :disabled="titleForm.processing" /></label>
                <label class="grid gap-1 text-sm">Italiano<textarea v-model="titleForm.title_it" rows="3" maxlength="1000" class="w-full rounded-lg border border-sidebar-border/70 bg-background p-3" :disabled="titleForm.processing" /></label>
                <label class="grid gap-1 text-sm">Inglese<textarea v-model="titleForm.title_en" rows="3" maxlength="1000" class="w-full rounded-lg border border-sidebar-border/70 bg-background p-3" :disabled="titleForm.processing" /></label>
                <p v-for="(error, key) in titleForm.errors" :key="key" role="alert" class="text-sm text-red-400">{{ error }}</p>
                <div class="flex justify-end gap-2"><button type="button" class="rounded-lg border px-4 py-2 text-sm" :disabled="titleForm.processing" @click="titlesOpen = false">Annulla</button><button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50" :disabled="titleForm.processing">{{ titleForm.processing ? 'Salvataggio…' : 'Salva titolo' }}</button></div>
            </form>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="descriptionOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl" @interact-outside="descriptionForm.processing && $event.preventDefault()" @escape-key-down="descriptionForm.processing && $event.preventDefault()">
            <DialogHeader><DialogTitle>Modifica descrizione</DialogTitle><DialogDescription>{{ descriptionProduct?.title }}</DialogDescription></DialogHeader>
            <form class="grid min-w-0 gap-4" @submit.prevent="saveDescription">
                <label class="grid gap-1 text-sm">Lingua<select v-model="descriptionLocale" class="rounded-lg border border-sidebar-border/70 bg-background p-2" :disabled="descriptionForm.processing"><option value="it">Italiano</option><option value="en">Inglese</option></select></label>
                <DescriptionEditor :key="descriptionLocale" v-model="descriptionDraft" :label="descriptionLocale === 'it' ? 'Descrizione italiana' : 'Descrizione inglese'" />
                <p v-for="(error, key) in descriptionForm.errors" :key="key" role="alert" class="text-sm text-red-400">{{ error }}</p>
                <details v-if="descriptionProduct?.body_html" class="rounded-lg border border-sidebar-border/70 p-3"><summary class="cursor-pointer text-sm">Descrizione originale spagnola</summary><div class="mt-3 max-h-48 overflow-y-auto text-sm" v-html="descriptionProduct.body_html" /></details>
                <p class="text-xs text-muted-foreground">La descrizione vuota usa l’originale spagnolo. Le modifiche vengono conservate nei reimport se l’originale non cambia.</p>
                <div class="flex justify-end gap-2"><button type="button" class="rounded-lg border px-4 py-2 text-sm" :disabled="descriptionForm.processing" @click="descriptionOpen = false">Annulla</button><button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50" :disabled="descriptionForm.processing">{{ descriptionForm.processing ? 'Salvataggio…' : 'Salva descrizione' }}</button></div>
            </form>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="variantsOpen">
        <DialogContent class="sm:max-w-2xl" @interact-outside="variantForm.processing && $event.preventDefault()" @escape-key-down="variantForm.processing && $event.preventDefault()">
            <DialogHeader>
                <DialogTitle>Prezzi delle varianti</DialogTitle>
                <DialogDescription>{{ variantProduct?.title }}</DialogDescription>
            </DialogHeader>
            <form class="grid min-w-0 gap-4" @submit.prevent="saveVariants">
                <div class="max-h-[60vh] space-y-3 overflow-y-auto">
                    <div v-for="(v, i) in variantForm.variants" :key="v.id" class="grid gap-2 rounded-lg border border-sidebar-border/70 p-3 sm:grid-cols-[minmax(0,1fr)_140px]">
                        <div class="min-w-0">
                            <label :for="`variant-price-${v.id}`" class="break-words font-medium">{{ variantProduct?.variants[i]?.title || `Variante ${v.id}` }}</label>
                            <p v-if="variantProduct?.variants[i]?.sku" class="mt-1 break-all text-xs text-muted-foreground">SKU: {{ variantProduct.variants[i].sku }}</p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <input :id="`variant-price-${v.id}`" v-model="v.price" type="number" min="0" max="999999.99" step="0.01" required :disabled="variantForm.processing" class="w-full rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5" />
                                <span>€</span>
                            </div>
                            <p v-if="variantError(i)" class="mt-1 text-xs text-destructive">{{ variantError(i) }}</p>
                        </div>
                    </div>
                </div>
                <p v-if="variantForm.errors.variants" class="text-sm text-destructive">{{ variantForm.errors.variants }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" :disabled="variantForm.processing" class="rounded-md border px-4 py-2 text-sm" @click="variantsOpen = false">Annulla</button>
                    <button type="submit" :disabled="variantForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50">{{ variantForm.processing ? 'Salvataggio…' : 'Salva prezzi' }}</button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
