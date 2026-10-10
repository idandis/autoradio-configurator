<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { computed, reactive, ref } from 'vue';
import DescriptionEditor from '@/components/DescriptionEditor.vue';
import StockProductsManager from '@/components/StockProductsManager.vue';

const props = defineProps<{
    filters: {
        category: string;
        search: string;
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
            image_url: string | null;
        }>;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
}>();

const filters = reactive({
    category: props.filters.category || '',
    search: props.filters.search || '',
});
const editingPrice = ref<number | null>(null);
const priceDraft = ref('');
const savingPrice = ref(false);
const deletingProduct = ref<number | null>(null);
const titleProduct = ref<(typeof props.products.data)[number] | null>(null);
const titlesOpen = ref(false);
const titleForm = useForm({ title_it: '', title_en: '' });
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
    router.get(
        '/imported-products',
        {
            category: filters.category || undefined,
            search: filters.search || undefined,
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

            <div class="grid gap-3 sm:grid-cols-[180px_260px_auto]">
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
                                    <p class="font-medium">{{ product.title }}</p>
                                    <p v-if="product.title_it" class="mt-1 text-xs text-muted-foreground"><span class="font-semibold">IT:</span> {{ product.title_it }}</p>
                                    <p v-if="product.title_en" class="mt-1 text-xs text-muted-foreground"><span class="font-semibold">EN:</span> {{ product.title_en }}</p>
                                    <p class="mt-1 break-all text-xs text-muted-foreground">
                                        {{ product.handle }}
                                    </p>
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
                            <button type="button" class="mr-2 rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium transition hover:border-primary hover:text-primary" :aria-label="`Modifica titolo ${product.title}`" @click="startTitleEdit(product)">Modifica titolo</button>
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
    <Dialog v-model:open="titlesOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl" @interact-outside="titleForm.processing && $event.preventDefault()" @escape-key-down="titleForm.processing && $event.preventDefault()">
            <DialogHeader><DialogTitle>Modifica titolo</DialogTitle><DialogDescription>{{ titleProduct?.title }}</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveTitles">
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
