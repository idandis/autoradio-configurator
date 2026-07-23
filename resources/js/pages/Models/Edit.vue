<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    vehicleModel: {
        brand: string;
        model: string;
        year_from: number;
        year_to: number;
        products_count: number;
        image_url: string | null;
    };
}>();

const form = useForm({
    _method: 'put',
    source_brand: props.vehicleModel.brand,
    source_model: props.vehicleModel.model,
    brand: props.vehicleModel.brand,
    model: props.vehicleModel.model,
    year_from: props.vehicleModel.year_from,
    year_to: props.vehicleModel.year_to,
    image: null as File | null,
});

const updateImage = (event: Event) => {
    const target = event.target as HTMLInputElement;
    form.image = target.files?.[0] ?? null;
};

const submit = () => {
    form.post('/models', {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Modifica ${props.vehicleModel.brand} ${props.vehicleModel.model}`" />

    <section class="rounded-xl border border-sidebar-border/70 bg-card">
        <div class="border-b border-sidebar-border/70 p-6">
            <h1 class="text-xl font-semibold">Modifica modello</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Le modifiche saranno applicate a {{ props.vehicleModel.products_count }} prodotti associati.
            </p>
        </div>

        <form class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_320px]" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Marca</span>
                    <input v-model="form.brand" class="rounded-lg border bg-background px-3 py-2.5" />
                    <span v-if="form.errors.brand" class="text-red-500">{{ form.errors.brand }}</span>
                </label>

                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Modello</span>
                    <input v-model="form.model" class="rounded-lg border bg-background px-3 py-2.5" />
                    <span v-if="form.errors.model" class="text-red-500">{{ form.errors.model }}</span>
                </label>

                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Anno iniziale</span>
                    <input v-model.number="form.year_from" type="number" min="1900" max="2100" class="rounded-lg border bg-background px-3 py-2.5" />
                    <span v-if="form.errors.year_from" class="text-red-500">{{ form.errors.year_from }}</span>
                </label>

                <label class="grid gap-2 text-sm">
                    <span class="font-medium">Anno finale</span>
                    <input v-model.number="form.year_to" type="number" min="1900" max="2100" class="rounded-lg border bg-background px-3 py-2.5" />
                    <span v-if="form.errors.year_to" class="text-red-500">{{ form.errors.year_to }}</span>
                </label>

                <label class="grid gap-2 text-sm sm:col-span-2">
                    <span class="font-medium">Foto del veicolo</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="rounded-lg border bg-background px-3 py-2.5" @change="updateImage" />
                    <span class="text-muted-foreground">PNG, JPG o WebP, massimo 5 MB.</span>
                    <span v-if="form.errors.image" class="text-red-500">{{ form.errors.image }}</span>
                </label>

                <div class="flex gap-3 sm:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-5 py-2.5 font-medium text-primary-foreground disabled:opacity-50">
                        {{ form.processing ? 'Salvataggio...' : 'Salva modifiche' }}
                    </button>
                    <Link href="/models" class="rounded-lg border px-5 py-2.5 font-medium hover:bg-accent">
                        Annulla
                    </Link>
                </div>
            </div>

            <div class="rounded-xl border bg-muted/30 p-4">
                <p class="mb-3 text-sm font-medium">Foto attuale</p>
                <div class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg bg-black">
                    <img v-if="props.vehicleModel.image_url" :src="props.vehicleModel.image_url" :alt="`${props.vehicleModel.brand} ${props.vehicleModel.model}`" class="h-full w-full object-contain" />
                    <span v-else class="text-sm text-muted-foreground">Nessuna foto</span>
                </div>
            </div>
        </form>
    </section>
</template>
