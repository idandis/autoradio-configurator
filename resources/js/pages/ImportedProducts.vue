<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

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
            category: string;
            subtype: string | null;
            brand: string | null;
            model: string | null;
            year_from: number | null;
            year_to: number | null;
            price_min: string | null;
            variants_count: number;
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
    if (value === 'installation') return 'Installazione';

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
                    <option value="installation">Installazioni</option>
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
                        <th class="px-6 py-3 font-medium">Categoria</th>
                        <th class="px-6 py-3 font-medium">Veicolo</th>
                        <th class="px-6 py-3 font-medium">Prezzo base</th>
                        <th class="px-6 py-3 font-medium">Varianti</th>
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
                                    <p class="mt-1 break-all text-xs text-muted-foreground">
                                        {{ product.handle }}
                                    </p>
                                </div>
                            </div>
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
                            {{ product.price_min ? `${product.price_min} €` : 'N/D' }}
                        </td>
                        <td class="px-6 py-4 align-top">
                            {{ product.variants_count }}
                        </td>
                    </tr>
                    <tr v-if="props.products.data.length === 0">
                        <td colspan="5" class="px-6 py-10 text-center text-muted-foreground">
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
</template>
