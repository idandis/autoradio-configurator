<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Zone = {
    id: number;
    name: string;
    active: boolean;
    postal_ranges: Array<{ from: string; to: string }>;
    product_handles: string[];
    product_prices: Record<string, number | null>;
};

const props = defineProps<{
    zones: Zone[];
    installationProducts: Array<{
        handle: string;
        title: string;
        subtype: string | null;
        price: number;
    }>;
}>();

const editingId = ref<number | null>(null);
const defaultProductPrices = () => Object.fromEntries(
    props.installationProducts.map((product) => [product.handle, product.price]),
) as Record<string, number | string | null>;
const form = useForm({
    name: '',
    active: true,
    postal_ranges: [{ from: '', to: '' }],
    product_handles: [] as string[],
    product_prices: defaultProductPrices(),
});

const resetForm = () => {
    editingId.value = null;
    form.name = '';
    form.active = true;
    form.postal_ranges = [{ from: '', to: '' }];
    form.product_handles = [];
    form.product_prices = defaultProductPrices();
    form.clearErrors();
};

const addPostalRange = () => form.postal_ranges.push({ from: '', to: '' });
const removePostalRange = (index: number) => form.postal_ranges.splice(index, 1);
const installationProduct = (handle: string) => props.installationProducts.find((product) => product.handle === handle);

const editZone = (zone: Zone) => {
    editingId.value = zone.id;
    form.name = zone.name;
    form.active = zone.active;
    form.postal_ranges = zone.postal_ranges.map((range) => ({
        from: range.from,
        to: range.from === range.to ? '' : range.to,
    }));
    form.product_handles = [...zone.product_handles];
    form.product_prices = {
        ...defaultProductPrices(),
        ...Object.fromEntries(Object.entries(zone.product_prices).filter(([, price]) => price !== null)),
    };
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const submit = () => {
    if (editingId.value) {
        form.put(`/installation-zones/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: resetForm,
        });
        return;
    }

    form.post('/installation-zones', {
        preserveScroll: true,
        onSuccess: resetForm,
    });
};

const deleteZone = (zone: Zone) => {
    if (window.confirm(`Eliminare la zona “${zone.name}”?`)) {
        router.delete(`/installation-zones/${zone.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Zone installazione" />

    <div class="grid gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
        <section class="rounded-xl border border-sidebar-border/70 bg-card p-6">
            <h1 class="text-xl font-semibold">
                {{ editingId ? 'Modifica zona' : 'Nuova zona di installazione' }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Collega uno o più intervalli CAP ai servizi disponibili in quella zona.
            </p>

            <form class="mt-6 grid gap-5" @submit.prevent="submit">
                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Nome zona</span>
                    <input v-model="form.name" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5" placeholder="Es. Tenerife Nord" />
                    <span v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</span>
                </label>

                <div class="grid gap-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-medium">CAP e intervalli</span>
                        <button type="button" class="rounded-lg border border-sidebar-border/70 px-3 py-1.5 text-xs hover:bg-accent" @click="addPostalRange">+ Aggiungi CAP/intervallo</button>
                    </div>
                    <div v-for="(range, index) in form.postal_ranges" :key="index" class="grid grid-cols-[1fr_1fr_auto] items-end gap-2 rounded-lg border border-sidebar-border/70 p-3">
                        <label class="grid gap-1">
                            <span class="text-xs text-muted-foreground">Da / CAP singolo</span>
                            <input v-model="range.from" inputmode="numeric" maxlength="5" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 font-mono" placeholder="35001" />
                        </label>
                        <label class="grid gap-1">
                            <span class="text-xs text-muted-foreground">A (facoltativo)</span>
                            <input v-model="range.to" inputmode="numeric" maxlength="5" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 font-mono" placeholder="35999" />
                        </label>
                        <button type="button" class="rounded-lg border border-destructive/40 px-3 py-2 text-destructive hover:bg-destructive/10" aria-label="Rimuovi CAP o intervallo" @click="removePostalRange(index)">×</button>
                    </div>
                    <p v-if="form.postal_ranges.length === 0" class="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">Aggiungi almeno un CAP o intervallo.</p>
                    <span v-if="form.errors.postal_ranges" class="text-xs text-destructive">{{ form.errors.postal_ranges }}</span>
                </div>

                <fieldset class="grid gap-3">
                    <legend class="text-sm font-medium">Servizi disponibili</legend>
                    <label v-for="product in props.installationProducts" :key="product.handle" class="grid grid-cols-[auto_1fr_110px] items-start gap-3 rounded-lg border border-sidebar-border/70 p-3 text-sm">
                        <input v-model="form.product_handles" type="checkbox" :value="product.handle" class="mt-1" />
                        <span>
                            <span class="block font-medium">{{ product.title }}</span>
                            <span class="text-xs text-muted-foreground">Prezzo importato: {{ product.price.toFixed(2) }} €</span>
                        </span>
                        <span class="relative">
                            <input v-model="form.product_prices[product.handle]" type="number" min="0" max="999999.99" step="0.01" :disabled="!form.product_handles.includes(product.handle)" class="w-full rounded-lg border border-sidebar-border/70 bg-background py-2 pl-3 pr-7 text-right disabled:opacity-40" :aria-label="`Prezzo ${product.title}`" />
                            <span class="pointer-events-none absolute right-2 top-2 text-muted-foreground">€</span>
                        </span>
                    </label>
                    <span v-if="form.errors.product_handles" class="text-xs text-destructive">{{ form.errors.product_handles }}</span>
                    <span v-if="form.errors.product_prices" class="text-xs text-destructive">{{ form.errors.product_prices }}</span>
                </fieldset>

                <label class="flex items-center gap-3 text-sm">
                    <input v-model="form.active" type="checkbox" />
                    Zona attiva
                </label>

                <div class="flex gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50">
                        {{ editingId ? 'Salva modifiche' : 'Crea zona' }}
                    </button>
                    <button v-if="editingId" type="button" class="rounded-lg border border-sidebar-border/70 px-4 py-2.5 text-sm" @click="resetForm">Annulla</button>
                </div>
            </form>
        </section>

        <section class="rounded-xl border border-sidebar-border/70 bg-card">
            <div class="border-b border-sidebar-border/70 p-6">
                <h2 class="text-xl font-semibold">Zone configurate</h2>
                <p class="mt-1 text-sm text-muted-foreground">Queste regole non vengono cancellate dagli import CSV/XLS.</p>
            </div>
            <div class="divide-y divide-sidebar-border/70">
                <article v-for="zone in props.zones" :key="zone.id" class="p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold">{{ zone.name }}</h3>
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="zone.active ? 'bg-emerald-500/15 text-emerald-600' : 'bg-muted text-muted-foreground'">
                                    {{ zone.active ? 'Attiva' : 'Disattivata' }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-muted-foreground">
                                CAP: {{ zone.postal_ranges.map((range) => range.from === range.to ? range.from : `${range.from}-${range.to}`).join(', ') }}
                            </p>
                            <div class="mt-2 grid gap-1 text-sm text-muted-foreground">
                                <p v-for="handle in zone.product_handles" :key="handle">
                                    {{ installationProduct(handle)?.title ?? handle }}:
                                    <strong class="font-medium text-foreground">{{ (zone.product_prices[handle] ?? installationProduct(handle)?.price ?? 0).toFixed(2) }} €</strong>
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm hover:bg-accent" @click="editZone(zone)">Modifica</button>
                            <button type="button" class="rounded-lg border border-destructive/40 px-3 py-2 text-sm text-destructive hover:bg-destructive/10" @click="deleteZone(zone)">Elimina</button>
                        </div>
                    </div>
                </article>
                <p v-if="props.zones.length === 0" class="p-10 text-center text-sm text-muted-foreground">Non hai ancora configurato alcuna zona.</p>
            </div>
        </section>
    </div>
</template>
