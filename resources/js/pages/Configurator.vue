<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';

type Variant = {
    id: number;
    title: string;
    sku: string | null;
    shopifyVariantId: string | null;
    price: number;
    image: string | null;
};

type Vehicle = {
    id: number;
    handle: string;
    title: string;
    brand: string | null;
    model: string | null;
    yearFrom: number | null;
    yearTo: number | null;
    image: string | null;
    variants: Variant[];
};

type SimpleOption = {
    key: string;
    title: string;
    price: number;
    image?: string | null;
    shopifyVariantId?: string | null;
    subtype?: string | null;
};

const props = defineProps<{
    vehicles: Vehicle[];
    cameraOptions: SimpleOption[];
    installationOptions: SimpleOption[];
}>();

const brands = computed(() =>
    [...new Set(props.vehicles.map((vehicle) => vehicle.brand).filter(Boolean))],
);

const selectedBrand = ref<string | null>(brands.value[0] ?? null);
const selectedModel = ref<string | null>(null);
const selectedYear = ref<number | null>(null);
const selectedScreenVariantId = ref<number | null>(null);
const selectedCameraKey = ref<string>('none');
const selectedInstallationKey = ref<string>('none');

const models = computed(() => {
    return [
        ...new Set(
            props.vehicles
                .filter((vehicle) => vehicle.brand === selectedBrand.value)
                .map((vehicle) => vehicle.model)
                .filter(Boolean),
        ),
    ];
});

const brandVehicles = computed(() =>
    props.vehicles.filter((vehicle) => vehicle.brand === selectedBrand.value),
);

const matchingVehicles = computed(() =>
    brandVehicles.value.filter(
        (vehicle) =>
            selectedModel.value === null || vehicle.model === selectedModel.value,
    ),
);

const availableYears = computed(() => {
    const years = new Set<number>();
    brandVehicles.value.forEach((vehicle) => {
        const from = vehicle.yearFrom ?? new Date().getFullYear();
        const to = vehicle.yearTo ?? from;

        for (let year = from; year <= to; year += 1) {
            years.add(year);
        }
    });

    return [...years].sort((a, b) => a - b);
});

const selectedVehicle = computed(() => {
    return (
        matchingVehicles.value.find((vehicle) => {
            if (selectedYear.value === null) {
                return true;
            }

            const from = vehicle.yearFrom ?? selectedYear.value;
            const to = vehicle.yearTo ?? selectedYear.value;

            return selectedYear.value >= from && selectedYear.value <= to;
        }) ?? matchingVehicles.value[0] ?? null
    );
});

const selectedScreen = computed(
    () =>
        selectedVehicle.value?.variants.find(
            (variant) => variant.id === selectedScreenVariantId.value,
        ) ?? selectedVehicle.value?.variants[0] ?? null,
);

const selectedCamera = computed(
    () =>
        props.cameraOptions.find((option) => option.key === selectedCameraKey.value) ??
        props.cameraOptions[0],
);

const visibleInstallationOptions = computed(() => {
    if (selectedCamera.value.key !== 'none') {
        return props.installationOptions;
    }

    return props.installationOptions.filter(
        (option) =>
            option.key === 'none' ||
            !['camera_only', 'screen_camera'].includes(option.subtype ?? ''),
    );
});

const selectedInstallation = computed(
    () =>
        visibleInstallationOptions.value.find(
            (option) => option.key === selectedInstallationKey.value,
        ) ?? visibleInstallationOptions.value[0],
);

const productsSubtotal = computed(
    () => (selectedScreen.value?.price ?? 0) + (selectedCamera.value?.price ?? 0),
);

const total = computed(
    () => productsSubtotal.value + (selectedInstallation.value?.price ?? 0),
);

const checkoutLineItems = computed(() => {
    const items: Array<{ variantId: string; quantity: number }> = [];

    if (selectedScreen.value?.shopifyVariantId) {
        items.push({
            variantId: selectedScreen.value.shopifyVariantId,
            quantity: 1,
        });
    }

    if (selectedCamera.value.key !== 'none' && selectedCamera.value.shopifyVariantId) {
        items.push({
            variantId: selectedCamera.value.shopifyVariantId,
            quantity: 1,
        });
    }

    if (
        selectedInstallation.value.key !== 'none' &&
        selectedInstallation.value.shopifyVariantId
    ) {
        items.push({
            variantId: selectedInstallation.value.shopifyVariantId,
            quantity: 1,
        });
    }

    return items;
});

const canCheckout = computed(() => {
    if (!selectedScreen.value?.shopifyVariantId) {
        return false;
    }

    if (selectedCamera.value.key !== 'none' && !selectedCamera.value.shopifyVariantId) {
        return false;
    }

    if (
        selectedInstallation.value.key !== 'none' &&
        !selectedInstallation.value.shopifyVariantId
    ) {
        return false;
    }

    return checkoutLineItems.value.length > 0;
});

const checkoutUrl = computed(() => {
    if (!canCheckout.value) {
        return null;
    }

    const cartPath = checkoutLineItems.value
        .map((item) => `${item.variantId}:${item.quantity}`)
        .join(',');

    return `https://www.autoradiocanario.com/cart/${cartPath}`;
});

const goToCheckout = () => {
    if (!checkoutUrl.value) {
        return;
    }

    window.location.href = checkoutUrl.value;
};

watch(
    brands,
    (nextBrands) => {
        selectedBrand.value = nextBrands[0] ?? null;
    },
    { immediate: true },
);

watch(
    models,
    (nextModels) => {
        selectedModel.value = nextModels[0] ?? null;
    },
    { immediate: true },
);

watch(
    availableYears,
    (nextYears) => {
        selectedYear.value = nextYears[nextYears.length - 1] ?? null;
    },
    { immediate: true },
);

watch(
    visibleInstallationOptions,
    (nextOptions) => {
        if (!nextOptions.some((option) => option.key === selectedInstallationKey.value)) {
            selectedInstallationKey.value = nextOptions[0]?.key ?? 'none';
        }
    },
    { immediate: true },
);

watch(
    selectedVehicle,
    (vehicle) => {
        selectedScreenVariantId.value = vehicle?.variants[0]?.id ?? null;
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Configuratore" />

    <div class="min-h-screen bg-neutral-950 text-white">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-start justify-between gap-6">
                <div>
                    <p class="text-sm font-medium uppercase tracking-[0.24em] text-amber-400">
                        Configuratore
                    </p>
                    <h1 class="mt-3 text-4xl font-semibold tracking-tight">
                        Configura la tua schermata in meno di 30 secondi
                    </h1>
                    <p class="mt-3 max-w-3xl text-base text-neutral-400">
                        Seleziona veicolo, schermo, camera e installazione.
                        Il dataset viene importato dal CSV Shopify.
                    </p>
                </div>
                <a
                    href="/dashboard"
                    class="inline-flex items-center rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-200 transition hover:border-neutral-700 hover:bg-neutral-900"
                >
                    Dashboard import
                </a>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <section class="rounded-2xl border border-neutral-800 bg-neutral-900/80 p-6">
                    <div class="grid gap-6">
                        <div class="grid gap-4 md:grid-cols-[1fr_220px]">
                            <div class="grid gap-3">
                                <label class="text-sm font-medium text-neutral-300">Marca</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="brand in brands"
                                        :key="brand ?? 'unknown-brand'"
                                        type="button"
                                        @click="selectedBrand = brand"
                                        class="rounded-lg border px-4 py-3 text-sm transition"
                                        :class="
                                            selectedBrand === brand
                                                ? 'border-amber-400 bg-amber-400 text-black'
                                                : 'border-neutral-800 bg-neutral-950 text-neutral-200 hover:border-neutral-700'
                                        "
                                    >
                                        {{ brand }}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="mb-3 block text-sm font-medium text-neutral-300">Anno</label>
                                <select
                                    v-model="selectedYear"
                                    class="w-full rounded-lg border border-neutral-800 bg-neutral-950 px-4 py-3 text-sm text-white"
                                >
                                    <option
                                        v-for="year in availableYears"
                                        :key="year"
                                        :value="year"
                                    >
                                        {{ year }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-3">
                            <label class="text-sm font-medium text-neutral-300">Modello</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="model in models"
                                    :key="model ?? 'unknown-model'"
                                    type="button"
                                    @click="selectedModel = model"
                                    class="rounded-lg border px-4 py-3 text-sm transition"
                                    :class="
                                        selectedModel === model
                                            ? 'border-amber-400 bg-amber-400 text-black'
                                            : 'border-neutral-800 bg-neutral-950 text-neutral-200 hover:border-neutral-700'
                                    "
                                >
                                    {{ model }}
                                </button>
                            </div>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                2. Scegli il tuo schermo
                            </h2>
                            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <button
                                    v-for="variant in selectedVehicle?.variants ?? []"
                                    :key="variant.id"
                                    type="button"
                                    @click="selectedScreenVariantId = variant.id"
                                    class="grid min-h-44 gap-3 rounded-xl border p-4 text-left transition"
                                    :class="
                                        selectedScreenVariantId === variant.id
                                            ? 'border-amber-400 bg-neutral-900 shadow-[0_0_0_1px_rgba(251,191,36,0.35)]'
                                            : 'border-neutral-800 bg-neutral-950 hover:border-neutral-700'
                                    "
                                >
                                    <img
                                        v-if="variant.image"
                                        :src="variant.image"
                                        :alt="variant.title"
                                        class="h-24 w-full rounded-lg object-cover object-center"
                                    />
                                    <div class="space-y-1">
                                        <p class="text-base font-medium">{{ variant.title }}</p>
                                        <p class="text-2xl font-semibold text-white">
                                            {{ variant.price.toFixed(2) }} €
                                        </p>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                3. Aggiungi camera
                            </h2>
                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <button
                                    v-for="camera in cameraOptions"
                                    :key="camera.key"
                                    type="button"
                                    @click="selectedCameraKey = camera.key"
                                    class="grid gap-3 rounded-xl border p-4 text-left transition"
                                    :class="
                                        selectedCameraKey === camera.key
                                            ? 'border-amber-400 bg-neutral-900'
                                            : 'border-neutral-800 bg-neutral-950 hover:border-neutral-700'
                                    "
                                >
                                    <img
                                        v-if="camera.image"
                                        :src="camera.image"
                                        :alt="camera.title"
                                        class="h-20 w-full rounded-lg object-cover object-center"
                                    />
                                    <div>
                                        <p class="font-medium">{{ camera.title }}</p>
                                        <p class="mt-1 text-lg font-semibold">
                                            {{ camera.price.toFixed(2) }} €
                                        </p>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                4. Installazione
                            </h2>
                            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <button
                                    v-for="installation in visibleInstallationOptions"
                                    :key="installation.key"
                                    type="button"
                                    @click="selectedInstallationKey = installation.key"
                                    class="grid gap-2 rounded-xl border p-4 text-left transition"
                                    :class="
                                        selectedInstallationKey === installation.key
                                            ? 'border-amber-400 bg-neutral-900'
                                            : 'border-neutral-800 bg-neutral-950 hover:border-neutral-700'
                                    "
                                >
                                    <p class="text-sm font-medium text-neutral-100">
                                        {{ installation.title }}
                                    </p>
                                    <p class="text-lg font-semibold text-white">
                                        {{ installation.price.toFixed(2) }} €
                                    </p>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <aside class="rounded-2xl border border-neutral-800 bg-neutral-950 p-6">
                    <p class="text-sm font-semibold uppercase tracking-[0.24em] text-amber-400">
                        Preventivo
                    </p>

                    <div class="mt-6 space-y-4">
                            <div class="rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                                <p class="text-lg font-semibold">
                                    {{ selectedBrand }} {{ selectedModel }}
                                </p>
                            <p class="text-sm text-neutral-400">
                                {{ selectedYear }}
                            </p>
                                <p class="mt-3 text-sm text-neutral-500">
                                    {{ selectedVehicle?.title }}
                                </p>
                            </div>

                        <div class="space-y-3 rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-neutral-100">
                                        {{ selectedScreen?.title }}
                                    </p>
                                    <p class="text-sm text-neutral-500">Schermo</p>
                                </div>
                                <p class="font-semibold">
                                    {{ (selectedScreen?.price ?? 0).toFixed(2) }} €
                                </p>
                            </div>

                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-neutral-100">
                                        {{ selectedCamera.title }}
                                    </p>
                                    <p class="text-sm text-neutral-500">Camera</p>
                                </div>
                                <p class="font-semibold">
                                    {{ selectedCamera.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-neutral-100">
                                        {{ selectedInstallation.title }}
                                    </p>
                                    <p class="text-sm text-neutral-500">Installazione</p>
                                </div>
                                <p class="font-semibold">
                                    {{ selectedInstallation.price.toFixed(2) }} €
                                </p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div class="flex items-center justify-between text-sm text-neutral-400">
                                <span>Subtotale prodotti</span>
                                <span>{{ productsSubtotal.toFixed(2) }} €</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-sm text-neutral-400">
                                <span>Installazione</span>
                                <span>{{ selectedInstallation.price.toFixed(2) }} €</span>
                            </div>
                            <div class="mt-4 flex items-center justify-between border-t border-neutral-800 pt-4">
                                <span class="text-lg font-semibold text-white">Totale</span>
                                <span class="text-3xl font-semibold text-amber-400">
                                    {{ total.toFixed(2) }} €
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="goToCheckout"
                            class="w-full rounded-xl bg-red-600 px-5 py-4 text-base font-semibold text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canCheckout"
                        >
                            Aggiungi al carrello e paga
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</template>
