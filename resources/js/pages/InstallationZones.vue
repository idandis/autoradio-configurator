<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Zone = {
    id: number;
    name: string;
    active: boolean;
    postal_ranges: Array<{ from: string; to: string }>;
    product_handles: string[];
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
const form = useForm({
    name: '',
    active: true,
    postal_ranges: '',
    product_handles: [] as string[],
});

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
};

const editZone = (zone: Zone) => {
    editingId.value = zone.id;
    form.name = zone.name;
    form.active = zone.active;
    form.postal_ranges = zone.postal_ranges
        .map((range) => range.from === range.to ? range.from : `${range.from}-${range.to}`)
        .join('\n');
    form.product_handles = [...zone.product_handles];
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

                <label class="grid gap-2 text-sm">
                    <span class="font-medium">CAP e intervalli</span>
                    <textarea v-model="form.postal_ranges" rows="6" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5 font-mono" placeholder="38001-38999&#10;35001&#10;35002" />
                    <span class="text-xs text-muted-foreground">Uno per riga: singolo CAP o intervallo.</span>
                    <span v-if="form.errors.postal_ranges" class="text-xs text-destructive">{{ form.errors.postal_ranges }}</span>
                </label>

                <fieldset class="grid gap-3">
                    <legend class="text-sm font-medium">Servizi disponibili</legend>
                    <label v-for="product in props.installationProducts" :key="product.handle" class="flex items-start gap-3 rounded-lg border border-sidebar-border/70 p-3 text-sm">
                        <input v-model="form.product_handles" type="checkbox" :value="product.handle" class="mt-1" />
                        <span>
                            <span class="block font-medium">{{ product.title }}</span>
                            <span class="text-muted-foreground">{{ product.price.toFixed(2) }} €</span>
                        </span>
                    </label>
                    <span v-if="form.errors.product_handles" class="text-xs text-destructive">{{ form.errors.product_handles }}</span>
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
                            <p class="mt-1 text-sm text-muted-foreground">{{ zone.product_handles.length }} servizi associati</p>
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
