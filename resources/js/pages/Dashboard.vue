<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    stats: {
        screens: number;
        cameras: number;
        installations: number;
        vehicles: number;
    };
    flashStatus?: string | null;
}>();

const form = useForm({
    catalog: null as File | null,
});

const updateFile = (event: Event) => {
    const target = event.target as HTMLInputElement | null;

    form.catalog = target?.files?.[0] ?? null;
};

const submit = () => {
    form.post('/dashboard/import-csv', {
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Veicoli</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.vehicles }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Schermi</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.screens }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Camere</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.cameras }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Installazioni</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.installations }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <section class="rounded-xl border border-sidebar-border/70 bg-card p-6">
                <div class="max-w-2xl space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold">Import catalogo configuratore</h1>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Carica l'export CSV di Shopify. Il sistema estrae prodotti schermo,
                            camere e installazioni e aggiorna il configuratore pubblico.
                        </p>
                    </div>

                    <div
                        v-if="props.flashStatus"
                        class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200"
                    >
                        {{ props.flashStatus }}
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-2">
                            <label for="catalog" class="text-sm font-medium">CSV Shopify</label>
                            <input
                                id="catalog"
                                type="file"
                                accept=".csv,text/csv"
                                class="block w-full rounded-lg border border-sidebar-border/70 bg-background px-4 py-3 text-sm"
                                @change="updateFile"
                            />
                            <p v-if="form.errors.catalog" class="text-sm text-red-400">
                                {{ form.errors.catalog }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-5 py-3 text-sm font-medium text-primary-foreground"
                            :disabled="form.processing || !form.catalog"
                        >
                            {{ form.processing ? 'Import in corso...' : 'Importa catalogo' }}
                        </button>
                    </form>
                </div>
            </section>

            <aside class="rounded-xl border border-sidebar-border/70 bg-card p-6">
                <h2 class="text-lg font-semibold">Link utili</h2>
                <div class="mt-4 space-y-3">
                    <a
                        href="/configurator"
                        class="block rounded-lg border border-sidebar-border/70 px-4 py-3 text-sm transition hover:bg-accent"
                    >
                        Apri configuratore pubblico
                    </a>
                    <div class="rounded-lg border border-sidebar-border/70 px-4 py-3 text-sm text-muted-foreground">
                        In alternativa puoi importare da CLI con
                        <code class="ml-1 rounded bg-muted px-1.5 py-0.5 text-foreground">
                            php artisan configurator:import-csv /percorso/file.csv
                        </code>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
