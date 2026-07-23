<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    models: Array<{
        brand: string;
        model: string;
        products_count: number;
        min_year: number | null;
        max_year: number | null;
        min_price: string | null;
    }>;
    brands: string[];
    years: number[];
    filters: {
        brand: string | null;
        year: number | null;
    };
}>();

const selectedBrand = ref(props.filters.brand ?? '');
const selectedYear = ref<number | ''>(props.filters.year ?? '');

const applyFilters = () => {
    router.get(
        '/models',
        {
            brand: selectedBrand.value || undefined,
            year: selectedYear.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const formatYears = (model: (typeof props.models)[number]) => {
    if (model.min_year && model.max_year) {
        return `${model.min_year} - ${model.max_year}`;
    }

    return model.min_year ? `${model.min_year}+` : 'N/D';
};

const viewProducts = (model: (typeof props.models)[number]) => {
    router.get('/imported-products', {
        category: 'screen',
        search: `${model.brand} ${model.model}`,
    });
};

const editModel = (model: (typeof props.models)[number]) => {
    router.get('/models/edit', {
        brand: model.brand,
        model: model.model,
    });
};
</script>

<template>
    <Head title="Modelli" />

    <section class="rounded-xl border border-sidebar-border/70 bg-card">
        <div class="border-b border-sidebar-border/70 p-6">
            <h1 class="text-xl font-semibold">Modelli</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Riepilogo dei modelli auto associati ai prodotti schermo importati.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Marca</span>
                    <select
                        v-model="selectedBrand"
                        class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5"
                        @change="applyFilters"
                    >
                        <option value="">Tutte le marche</option>
                        <option v-for="brand in props.brands" :key="brand" :value="brand">
                            {{ brand }}
                        </option>
                    </select>
                </label>

                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Anno</span>
                    <select
                        v-model="selectedYear"
                        class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5"
                        @change="applyFilters"
                    >
                        <option value="">Tutti gli anni</option>
                        <option v-for="year in props.years" :key="year" :value="year">
                            {{ year }}
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-sidebar-border/70 text-sm">
                <thead class="bg-muted/40 text-left text-muted-foreground">
                    <tr>
                        <th class="px-6 py-3 font-medium">Marca</th>
                        <th class="px-6 py-3 font-medium">Modello</th>
                        <th class="px-6 py-3 font-medium">Prodotti</th>
                        <th class="px-6 py-3 font-medium">Anni</th>
                        <th class="px-6 py-3 font-medium">Prezzo da</th>
                        <th class="px-6 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70">
                    <tr v-for="model in props.models" :key="`${model.brand}-${model.model}`">
                        <td class="px-6 py-4 text-muted-foreground">{{ model.brand }}</td>
                        <td class="px-6 py-4 font-medium">{{ model.model }}</td>
                        <td class="px-6 py-4">{{ model.products_count }}</td>
                        <td class="px-6 py-4 text-muted-foreground">{{ formatYears(model) }}</td>
                        <td class="px-6 py-4">
                            {{ model.min_price ? `${model.min_price} €` : 'N/D' }}
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded-lg border border-sidebar-border/70 px-3 py-2 transition hover:bg-accent" @click="editModel(model)">
                                    Modifica
                                </button>
                                <button type="button" class="rounded-lg border border-sidebar-border/70 px-3 py-2 transition hover:bg-accent" @click="viewProducts(model)">
                                    Vedi prodotti
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.models.length === 0">
                        <td colspan="6" class="px-6 py-10 text-center text-muted-foreground">
                            Nessun modello corrisponde ai filtri selezionati.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
