<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';

const props = defineProps<{
    brands: Array<{
        brand: string;
        products_count: number;
        models_count: number;
        min_year: number | null;
        max_year: number | null;
        min_price: string | null;
    }>;
}>();

const formatYears = (brand: (typeof props.brands)[number]) => {
    if (brand.min_year && brand.max_year) {
        return `${brand.min_year} - ${brand.max_year}`;
    }

    if (brand.min_year) {
        return `${brand.min_year}+`;
    }

    return 'N/D';
};

const filterByBrand = (brand: string) => {
    router.get(
        '/imported-products',
        {
            category: 'screen',
            search: brand,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <Head title="Marche" />

    <section class="rounded-xl border border-sidebar-border/70 bg-card">
        <div class="border-b border-sidebar-border/70 p-6">
            <h1 class="text-xl font-semibold">Marche</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Riepilogo dei prodotti schermo raggruppati per marca auto.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-sidebar-border/70 text-sm">
                <thead class="bg-muted/40 text-left text-muted-foreground">
                    <tr>
                        <th class="px-6 py-3 font-medium">Marca</th>
                        <th class="px-6 py-3 font-medium">Modelli</th>
                        <th class="px-6 py-3 font-medium">Prodotti</th>
                        <th class="px-6 py-3 font-medium">Anni</th>
                        <th class="px-6 py-3 font-medium">Prezzo da</th>
                        <th class="px-6 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70">
                    <tr v-for="brand in props.brands" :key="brand.brand">
                        <td class="px-6 py-4 font-medium">{{ brand.brand }}</td>
                        <td class="px-6 py-4">{{ brand.models_count }}</td>
                        <td class="px-6 py-4">{{ brand.products_count }}</td>
                        <td class="px-6 py-4 text-muted-foreground">
                            {{ formatYears(brand) }}
                        </td>
                        <td class="px-6 py-4">
                            {{ brand.min_price ? `${brand.min_price} €` : 'N/D' }}
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button
                                type="button"
                                class="rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm transition hover:bg-accent"
                                @click="filterByBrand(brand.brand)"
                            >
                                Vedi prodotti
                            </button>
                        </td>
                    </tr>
                    <tr v-if="props.brands.length === 0">
                        <td colspan="6" class="px-6 py-10 text-center text-muted-foreground">
                            Nessuna marca importata.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
