<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

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
    location?: string | null;
    isStandard?: boolean;
    brand?: string | null;
    model?: string | null;
    yearFrom?: number | null;
    yearTo?: number | null;
};

type InstallationZone = {
    id: number;
    name: string;
    postalRanges: Array<{ from: string; to: string }>;
    productHandles: string[];
};

type SpeakerOption = SimpleOption & {
    handle: string;
    productTitle: string;
    sizes: string[];
    categories: string[];
};

const props = defineProps<{
    vehicles: Vehicle[];
    cameraOptions: SimpleOption[];
    speakerOptions: SpeakerOption[];
    installationOptions: SimpleOption[];
    installationZones: InstallationZone[];
    vehicleImages: string[];
    brandImages: string[];
}>();

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));

const compatibilityEntries = computed(() => [
    ...props.vehicles.map((vehicle) => ({
        brand: vehicle.brand,
        model: vehicle.model,
        yearFrom: vehicle.yearFrom,
        yearTo: vehicle.yearTo,
    })),
    ...props.cameraOptions
        .filter((camera) => !camera.isStandard)
        .map((camera) => ({
            brand: camera.brand ?? null,
            model: camera.model ?? null,
            yearFrom: camera.yearFrom ?? null,
            yearTo: camera.yearTo ?? null,
        })),
]);

const brands = computed(() => [
    ...new Set(compatibilityEntries.value.map((entry) => entry.brand).filter(Boolean)),
]);

const selectedBrand = ref<string | null>(null);
const selectedModel = ref<string | null>(null);
const selectedYear = ref<number | null>(null);
const selectedScreenVariantId = ref<number | null>(null);
const selectedCameraKeys = ref<string[]>([]);
const selectedSpeakerSize = ref<string>('');
const selectedSpeakerCategory = ref<string>('');
const selectedSpeakerKey = ref<string | null>(null);
const selectedInstallationKey = ref<string | null>(null);
const postalCode = ref('');
const checkedPostalCode = ref<string | null>(null);
const postalCodeError = ref<string | null>(null);
const resolvedInstallationArea = ref<{
    name: string;
    productHandles: string[];
} | null>(null);

const models = computed(() => {
    if (selectedBrand.value === null || selectedYear.value === null) {
        return [];
    }

    return [
        ...new Set(
            compatibilityEntries.value
                .filter((entry) => {
                    if (entry.brand !== selectedBrand.value) {
                        return false;
                    }

                    return (
                        entry.yearFrom !== null &&
                        entry.yearTo !== null &&
                        selectedYear.value >= entry.yearFrom &&
                        selectedYear.value <= entry.yearTo
                    );
                })
                .map((entry) => entry.model)
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
    compatibilityEntries.value
        .filter((entry) => entry.brand === selectedBrand.value)
        .forEach((entry) => {
        const from = entry.yearFrom;
        const to = entry.yearTo;

        if (from === null || to === null) {
            return;
        }

        for (let year = from; year <= to; year += 1) {
            years.add(year);
        }
        });

    return [...years].sort((a, b) => a - b);
});

const selectedVehicle = computed(() => {
    if (selectedYear.value === null || selectedModel.value === null) {
        return null;
    }

    return (
        matchingVehicles.value.find((vehicle) => {
            if (vehicle.yearFrom === null || vehicle.yearTo === null) {
                return false;
            }

            return (
                selectedYear.value! >= vehicle.yearFrom &&
                selectedYear.value! <= vehicle.yearTo
            );
        }) ?? null
    );
});

const selectedCompatibilityEntry = computed(() => {
    if (
        selectedBrand.value === null ||
        selectedModel.value === null ||
        selectedYear.value === null
    ) {
        return null;
    }

    return (
        compatibilityEntries.value.find(
            (entry) =>
                entry.brand === selectedBrand.value &&
                entry.model === selectedModel.value &&
                entry.yearFrom !== null &&
                entry.yearTo !== null &&
                selectedYear.value! >= entry.yearFrom &&
                selectedYear.value! <= entry.yearTo,
        ) ?? null
    );
});

const selectedScreen = computed(
    () =>
        selectedVehicle.value?.variants.find(
            (variant) => variant.id === selectedScreenVariantId.value,
        ) ?? selectedVehicle.value?.variants[0] ?? null,
);

const selectedScreenImage = computed(
    () => selectedVehicle.value?.image ?? selectedVehicle.value?.variants[0]?.image ?? null,
);

const selectedScreenProductUrl = computed(() => {
    if (!selectedVehicle.value?.handle) {
        return null;
    }

    return `https://www.autoradiocanario.com/products/${encodeURIComponent(selectedVehicle.value.handle)}`;
});

const failedVehicleImage = ref<string | null>(null);
const failedBrandImage = ref<string | null>(null);

const slugifyVehiclePart = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

const selectedVehicleModelSlugs = computed(() => {
    if (selectedModel.value === null) {
        return [];
    }

    const modelSlug = slugifyVehiclePart(selectedModel.value);

    if (selectedBrand.value !== 'BMW' || selectedYear.value === null) {
        return [modelSlug];
    }

    const bmwAliases: Record<string, string[]> = {
        m3: ['e46'],
        'serie-3': ['e90'],
        'serie-5': ['e60'],
        'serie-7': ['e28'],
    };

    if (modelSlug === 'serie-1') {
        return selectedYear.value <= 2011
            ? [modelSlug, 'e81']
            : [modelSlug, 'f20'];
    }

    return [modelSlug, ...(bmwAliases[modelSlug] ?? [])];
});

const selectedVehicleImageUrl = computed(() => {
    if (
        selectedBrand.value === null ||
        selectedModel.value === null ||
        selectedYear.value === null ||
        selectedCompatibilityEntry.value === null
    ) {
        return null;
    }

    const brandSlug = slugifyVehiclePart(selectedBrand.value);
    const candidates = props.vehicleImages.flatMap((image) => {
        const stem = slugifyVehiclePart(image.replace(/\.[^.]+$/, ''));
        const match = stem.match(/^(.*)-(19\d{2}|20\d{2})-(19\d{2}|20\d{2})$/);

        if (!match) {
            return [];
        }

        const modelSlug = selectedVehicleModelSlugs.value.find(
            (candidate) => match[1] === `${brandSlug}-${candidate}`,
        );
        const yearFrom = Number(match[2]);
        const yearTo = Number(match[3]);

        if (
            !modelSlug ||
            selectedYear.value! < yearFrom ||
            selectedYear.value! > yearTo
        ) {
            return [];
        }

        return [{
            image,
            modelSlug,
            yearFrom,
            yearTo,
        }];
    });

    const filename = candidates
        .sort((a, b) => {
            const exactModelA = a.modelSlug === slugifyVehiclePart(selectedModel.value!) ? 0 : 1;
            const exactModelB = b.modelSlug === slugifyVehiclePart(selectedModel.value!) ? 0 : 1;
            const exactRangeA = a.yearFrom === selectedCompatibilityEntry.value!.yearFrom && a.yearTo === selectedCompatibilityEntry.value!.yearTo ? 0 : 1;
            const exactRangeB = b.yearFrom === selectedCompatibilityEntry.value!.yearFrom && b.yearTo === selectedCompatibilityEntry.value!.yearTo ? 0 : 1;

            return exactModelA - exactModelB || exactRangeA - exactRangeB;
        })[0]?.image;

    return filename ? `/images/vehicles-dark/${encodeURIComponent(filename)}` : null;
});

const selectedBrandImageUrl = computed(() => {
    if (selectedBrand.value === null) {
        return null;
    }

    const brandSlug = slugifyVehiclePart(selectedBrand.value);
    const filename = props.brandImages.find(
        (image) => slugifyVehiclePart(image.replace(/\.[^.]+$/, '')) === brandSlug,
    );

    return filename ? `/images/brands/${encodeURIComponent(filename)}` : null;
});

watch(selectedVehicleImageUrl, () => {
    failedVehicleImage.value = null;
});

watch(selectedBrandImageUrl, () => {
    failedBrandImage.value = null;
});

const visibleCameraOptions = computed(() => {
    if (
        selectedBrand.value === null ||
        selectedModel.value === null ||
        selectedYear.value === null
    ) {
        return [];
    }

    return props.cameraOptions.filter((option) => {
        if (option.isStandard) {
            return true;
        }

        if (
            !option.brand ||
            !option.model ||
            option.yearFrom === null ||
            option.yearFrom === undefined ||
            option.yearTo === null ||
            option.yearTo === undefined
        ) {
            return false;
        }

        return (
            option.brand === selectedBrand.value &&
            option.model === selectedModel.value &&
            selectedYear.value >= option.yearFrom &&
            selectedYear.value <= option.yearTo
        );
    });
});

const hasSpecificCameraOption = computed(() =>
    visibleCameraOptions.value.some((camera) => !camera.isStandard),
);

const selectedCameras = computed(() =>
    visibleCameraOptions.value.filter((option) =>
        selectedCameraKeys.value.includes(option.key),
    ),
);

const toggleCamera = (key: string) => {
    selectedCameraKeys.value = selectedCameraKeys.value.includes(key)
        ? selectedCameraKeys.value.filter((selectedKey) => selectedKey !== key)
        : [...selectedCameraKeys.value, key];
};

const speakerCategories = computed(() =>
    [...new Set(props.speakerOptions.flatMap((speaker) => speaker.categories))]
        .sort((a, b) => a.localeCompare(b)),
);

const speakerSizes = computed(() =>
    [...new Set(props.speakerOptions
        .filter((speaker) => speaker.categories.includes(selectedSpeakerCategory.value))
        .flatMap((speaker) => speaker.sizes))]
        .sort((a, b) => a.localeCompare(b, undefined, { numeric: true })),
);

const formatSpeakerSize = (value: string) => {
    const slug = value
        .replace(/^.*speaker-nominal-size\./i, '')
        .replace(/-(?:pollici|pollice|pulgadas?|inches?)$/i, '');

    const size = /^\d+-\d+$/.test(slug)
        ? slug.replace('-', ',')
        : slug.replace(/-x-/gi, ' × ').replace(/-/g, ' ');

    return `${size}\u2033`;
};

const visibleSpeakerOptions = computed(() =>
    selectedSpeakerCategory.value === '' || selectedSpeakerSize.value === ''
        ? []
        : props.speakerOptions.filter((speaker) =>
            speaker.categories.includes(selectedSpeakerCategory.value) &&
            speaker.sizes.includes(selectedSpeakerSize.value),
        ),
);

const selectedSpeaker = computed(() =>
    visibleSpeakerOptions.value.find(
        (speaker) => speaker.key === selectedSpeakerKey.value,
    ) ?? null,
);

const matchedInstallationZone = computed(() => {
    if (resolvedInstallationArea.value) {
        return {
            id: 0,
            name: resolvedInstallationArea.value.name,
            postalRanges: [],
            productHandles: resolvedInstallationArea.value.productHandles,
        };
    }

    if (checkedPostalCode.value === null) {
        return null;
    }

    return props.installationZones.find((zone) =>
        zone.postalRanges.some(
            (range) =>
                checkedPostalCode.value! >= range.from &&
                checkedPostalCode.value! <= range.to,
        ),
    ) ?? null;
});

const hasSelectedProducts = computed(
    () => Boolean(selectedScreen.value || selectedCameras.value.length || selectedSpeaker.value),
);

const requiredInstallationSubtype = computed(() => {
    const hasScreen = Boolean(selectedScreen.value);
    const hasCamera = selectedCameras.value.length > 0;
    const hasSpeaker = Boolean(selectedSpeaker.value);

    if (hasScreen && hasCamera && !hasSpeaker) return 'screen_camera';
    if (hasScreen && !hasCamera && hasSpeaker) return 'speaker_screen';
    if (hasScreen && !hasCamera && !hasSpeaker) return 'screen_only';
    if (!hasScreen && hasCamera && !hasSpeaker) return 'camera_only';
    if (!hasScreen && !hasCamera && hasSpeaker) return 'speaker_only';

    return null;
});

const visibleInstallationOptions = computed(() => {
    if (!matchedInstallationZone.value || !requiredInstallationSubtype.value) {
        return [];
    }

    return props.installationOptions.filter(
        (option) =>
            matchedInstallationZone.value!.productHandles.includes(option.key) &&
            option.subtype === requiredInstallationSubtype.value,
    );
});

const checkPostalCode = async () => {
    const normalized = postalCode.value.trim();

    if (!/^\d{5}$/.test(normalized)) {
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = 'Inserisci un CAP valido di 5 cifre.';
        return;
    }

    try {
        const response = await fetch(`/configurator/postal-code/${normalized}`);
        const result = await response.json();

        postalCode.value = normalized;
        checkedPostalCode.value = normalized;
        resolvedInstallationArea.value = result.installationArea?.productHandles?.length
            ? result.installationArea
            : null;
        postalCodeError.value = null;
    } catch {
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = 'Impossibile verificare il CAP. Riprova.';
    }
};

const selectedInstallation = computed(
    () =>
        visibleInstallationOptions.value.find(
            (option) => option.key === selectedInstallationKey.value,
        ) ?? null,
);

const productsSubtotal = computed(
    () =>
        (selectedScreen.value?.price ?? 0) +
        selectedCameras.value.reduce((sum, camera) => sum + camera.price, 0) +
        (selectedSpeaker.value?.price ?? 0),
);

const total = computed(
    () => productsSubtotal.value + (selectedInstallation.value?.price ?? 0),
);

const discountTiers = [
    { code: 'StepTwo', threshold: 500, percentage: 3 },
    { code: 'StepOne', threshold: 300, percentage: 2 },
];
const activeDiscount = computed(
    () => discountTiers.find((tier) => total.value >= tier.threshold) ?? null,
);
const nextDiscount = computed(() => {
    if (total.value < 300) return discountTiers[1];
    if (total.value < 500) return discountTiers[0];

    return null;
});
const amountUntilNextDiscount = computed(() =>
    nextDiscount.value
        ? Math.max(0, nextDiscount.value.threshold - total.value)
        : 0,
);
const discountAmount = computed(() =>
    activeDiscount.value
        ? total.value * (activeDiscount.value.percentage / 100)
        : 0,
);
const discountedTotal = computed(() => total.value - discountAmount.value);

const checkoutLineItems = computed(() => {
    const items: Array<{ variantId: string; quantity: number }> = [];

    if (selectedScreen.value?.shopifyVariantId) {
        items.push({
            variantId: selectedScreen.value.shopifyVariantId,
            quantity: 1,
        });
    }

    selectedCameras.value.forEach((camera) => {
        if (camera.shopifyVariantId) {
            items.push({
                variantId: camera.shopifyVariantId,
                quantity: 1,
            });
        }
    });

    if (selectedSpeaker.value?.shopifyVariantId) {
        items.push({
            variantId: selectedSpeaker.value.shopifyVariantId,
            quantity: 1,
        });
    }

    if (
        selectedInstallation.value?.shopifyVariantId
    ) {
        items.push({
            variantId: selectedInstallation.value.shopifyVariantId,
            quantity: 1,
        });
    }

    return items;
});

const canCheckout = computed(() => {
    if (selectedScreen.value && !selectedScreen.value.shopifyVariantId) {
        return false;
    }

    if (selectedCameras.value.some((camera) => !camera.shopifyVariantId)) {
        return false;
    }

    if (selectedSpeaker.value && !selectedSpeaker.value.shopifyVariantId) {
        return false;
    }

    if (
        selectedInstallation.value &&
        !selectedInstallation.value.shopifyVariantId
    ) {
        return false;
    }

    return (
        (selectedScreen.value !== null || selectedCameras.value.length > 0 || selectedSpeaker.value !== null) &&
        checkoutLineItems.value.length > 0
    );
});

const checkoutUrl = computed(() => {
    if (!canCheckout.value) {
        return null;
    }

    const cartPath = checkoutLineItems.value
        .map((item) => `${item.variantId}:${item.quantity}`)
        .join(',');

    if (activeDiscount.value) {
        return `https://www.autoradiocanario.com/discount/${activeDiscount.value.code}?redirect=/cart/${cartPath}`;
    }

    return `https://www.autoradiocanario.com/cart/${cartPath}`;
});

const copyCheckoutStatus = ref<'idle' | 'copied' | 'error'>('idle');

const copyCheckoutUrl = async () => {
    if (!checkoutUrl.value) {
        return;
    }

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(checkoutUrl.value);
        } else {
            const textArea = document.createElement('textarea');
            textArea.value = checkoutUrl.value;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            textArea.remove();
        }

        copyCheckoutStatus.value = 'copied';
    } catch {
        copyCheckoutStatus.value = 'error';
    }

    window.setTimeout(() => {
        copyCheckoutStatus.value = 'idle';
    }, 2500);
};

watch(
    checkoutUrl,
    (nextUrl) => {
        if (nextUrl) {
            console.log('[Configurator] checkout URL:', nextUrl);
        }
    },
    { immediate: true },
);

const goToCheckout = () => {
    if (!checkoutUrl.value) {
        return;
    }

    window.location.href = checkoutUrl.value;
};

watch(
    models,
    (nextModels) => {
        if (
            selectedModel.value !== null &&
            !nextModels.includes(selectedModel.value)
        ) {
            selectedModel.value = null;
        }
    },
    { immediate: true },
);

watch(selectedBrand, (nextBrand, previousBrand) => {
    if (previousBrand !== null && nextBrand !== previousBrand) {
        selectedYear.value = null;
    }

    selectedModel.value = null;
});

watch(selectedYear, () => {
    selectedModel.value = null;
});

watch(postalCode, (nextPostalCode) => {
    if (nextPostalCode !== checkedPostalCode.value) {
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = null;
    }
});

watch(
    visibleCameraOptions,
    (nextOptions) => {
        const visibleKeys = new Set(nextOptions.map((option) => option.key));
        selectedCameraKeys.value = selectedCameraKeys.value.filter((key) =>
            visibleKeys.has(key),
        );
    },
    { immediate: true },
);

watch(selectedSpeakerSize, () => {
    selectedSpeakerKey.value = null;
});

watch(selectedSpeakerCategory, () => {
    selectedSpeakerSize.value = '';
    selectedSpeakerKey.value = null;
});

watch(
    visibleInstallationOptions,
    (nextOptions) => {
        if (!nextOptions.some((option) => option.key === selectedInstallationKey.value)) {
            selectedInstallationKey.value = null;
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

    <div class="min-h-screen bg-[#121212] text-white">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-start justify-between gap-6">
                <div>
                    <p class="text-sm font-medium uppercase tracking-[0.24em] text-amber-400">
                        Configuratore autoradio
                    </p>
                    <h1 class="mt-3 text-4xl font-semibold tracking-tight">
                        Trova la soluzione perfetta per la tua auto
                    </h1>
                    <p class="mt-3 max-w-3xl text-base text-neutral-400">
                        Seleziona marca, anno e modello. Ti mostreremo soltanto schermi,
                        retrocamere e servizi compatibili con il tuo veicolo.
                    </p>
                </div>
                <div v-if="isAdmin" class="flex shrink-0 flex-col gap-2 sm:flex-row">
                    <button
                        type="button"
                        :disabled="!checkoutUrl"
                        class="inline-flex items-center justify-center rounded-md border border-amber-400 px-4 py-2 text-sm font-medium text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:border-neutral-800 disabled:text-neutral-600 disabled:hover:bg-transparent"
                        @click="copyCheckoutUrl"
                    >
                        {{
                            copyCheckoutStatus === 'copied'
                                ? 'Link copiato!'
                                : copyCheckoutStatus === 'error'
                                    ? 'Copia non riuscita'
                                    : 'Copia link carrello'
                        }}
                    </button>
                    <a
                        href="/dashboard"
                        class="inline-flex items-center justify-center rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-200 transition hover:border-neutral-700 hover:bg-neutral-900"
                    >
                        Dashboard import
                    </a>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <section class="rounded-2xl border border-neutral-800 bg-neutral-900/80 p-6">
                    <div class="grid gap-6">
                        <h2 class="text-2xl font-semibold text-amber-400">
                            1. Scegli la tua auto
                        </h2>

                        <div
                            class="grid gap-4"
                            :class="selectedBrand ? 'md:grid-cols-[1fr_220px]' : ''"
                        >
                            <div>
                                <label
                                    for="vehicle-brand"
                                    class="mb-3 block text-sm font-medium text-neutral-300"
                                >
                                    Marca
                                </label>
                                <select
                                    id="vehicle-brand"
                                    v-model="selectedBrand"
                                    class="w-full rounded-lg border border-neutral-800 bg-[#121212] px-4 py-3 text-sm text-white"
                                >
                                    <option :value="null">.</option>
                                    <option
                                        v-for="brand in brands"
                                        :key="brand ?? 'unknown-brand'"
                                        :value="brand"
                                    >
                                        {{ brand }}
                                    </option>
                                </select>
                            </div>

                            <div v-if="selectedBrand">
                                <label class="mb-3 block text-sm font-medium text-neutral-300">Anno</label>
                                <select
                                    v-model="selectedYear"
                                    class="w-full rounded-lg border border-neutral-800 bg-[#121212] px-4 py-3 text-sm text-white"
                                >
                                    <option :value="null">.</option>
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

                        <div v-if="selectedBrand && selectedYear !== null" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_220px] md:items-end">
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
                                                : 'border-neutral-800 bg-[#121212] text-neutral-200 hover:border-neutral-700'
                                        "
                                    >
                                        {{ model }}
                                    </button>
                                </div>
                            </div>
                            <a
                                href="https://www.autoradiocanario.com/it/pages/configura-tu-sistema-de-sonido-perfecto"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-12 items-center justify-center rounded-lg border border-amber-400 px-4 py-3 text-center text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black"
                            >
                                Non trovi il tuo modello? Scrivici
                            </a>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                2. Scegli il tuo schermo
                            </h2>
                            <div
                                v-if="selectedYear !== null && selectedVehicle"
                                class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]"
                            >
                                <div class="relative flex min-h-64 items-center justify-center overflow-hidden rounded-xl border border-neutral-800 bg-[#121212] p-4">
                                    <img
                                        v-if="selectedScreenImage"
                                        :src="selectedScreenImage"
                                        :alt="selectedVehicle?.title ?? 'Schermo'"
                                        class="max-h-72 w-full rounded-lg object-contain object-center"
                                    />
                                    <p v-else class="text-sm text-neutral-500">
                                        Immagine non disponibile
                                    </p>
                                    <a
                                        v-if="selectedScreenProductUrl"
                                        :href="selectedScreenProductUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 -translate-x-1/2 rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg backdrop-blur transition hover:bg-amber-400 hover:text-black"
                                    >
                                        Dettagli scheda
                                    </a>
                                </div>

                                <div class="overflow-x-auto pb-1">
                                    <div class="grid grid-flow-col grid-rows-5 gap-2 [grid-auto-columns:minmax(150px,1fr)]">
                                        <button
                                            v-for="variant in selectedVehicle?.variants ?? []"
                                            :key="variant.id"
                                            type="button"
                                            @click="selectedScreenVariantId = variant.id"
                                            class="min-h-12 rounded-lg border px-3 py-2 text-left text-sm font-medium leading-tight transition"
                                            :class="
                                                selectedScreenVariantId === variant.id
                                                    ? 'border-amber-400 bg-amber-400 text-black'
                                                    : 'border-neutral-800 bg-[#121212] text-neutral-200 hover:border-neutral-600'
                                            "
                                        >
                                            <span class="block">{{ variant.title }}</span>
                                            <span
                                                class="mt-1 block text-xs"
                                                :class="
                                                    selectedScreenVariantId === variant.id
                                                        ? 'text-black/70'
                                                        : 'text-neutral-400'
                                                "
                                            >
                                                {{ variant.price.toFixed(2) }} €
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p
                                v-else-if="selectedBrand && selectedModel && selectedYear"
                                class="mt-4 text-sm text-neutral-300"
                            >
                                <a
                                    href="https://www.autoradiocanario.com/it/pages/configura-tu-sistema-de-sonido-perfecto"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                >Non trovi il tuo schermo? Scrivici</a>, te lo troviamo subito!
                            </p>
                            <p v-else class="mt-4 text-sm text-neutral-500">
                                Seleziona marca, anno e modello per visualizzare gli schermi disponibili.
                            </p>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                3. Aggiungi camera
                            </h2>
                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <div
                                    v-for="camera in visibleCameraOptions"
                                    :key="camera.key"
                                    class="group relative overflow-hidden rounded-xl border transition"
                                    :class="
                                        selectedCameraKeys.includes(camera.key)
                                            ? 'border-amber-400 bg-neutral-900'
                                            : 'border-neutral-800 bg-[#121212] hover:border-neutral-700'
                                    "
                                >
                                    <button
                                        type="button"
                                        @click="toggleCamera(camera.key)"
                                        class="grid h-full w-full gap-3 p-4 text-left"
                                    >
                                        <img
                                            v-if="camera.image"
                                            :src="camera.image"
                                            :alt="camera.title"
                                            class="h-24 w-full rounded-lg bg-[#121212] p-2 object-contain object-center sm:h-32 lg:h-28 xl:h-32"
                                        />
                                        <div>
                                            <p class="font-medium">{{ camera.title }}</p>
                                            <p class="mt-1 text-lg font-semibold">
                                                {{ camera.price.toFixed(2) }} €
                                            </p>
                                        </div>
                                    </button>

                                    <a
                                        :href="`https://www.autoradiocanario.com/products/${encodeURIComponent(camera.key)}`"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg backdrop-blur transition hover:bg-amber-400 hover:text-black"
                                    >
                                        Dettagli scheda
                                    </a>
                                </div>
                            </div>
                            <p
                                v-if="selectedBrand && selectedModel && selectedYear && !hasSpecificCameraOption"
                                class="mt-4 text-sm text-neutral-300"
                            >
                                <a
                                    href="https://www.autoradiocanario.com/it/pages/configura-tu-sistema-de-sonido-perfecto"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                >Non trovi una camera specifica? Scrivici</a>, te la troviamo subito!
                            </p>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                4. Aggiungi altoparlanti
                            </h2>
                            <div class="mt-4 grid max-w-2xl gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="speaker-category" class="mb-2 block text-sm font-medium text-neutral-300">
                                        Tipo di altoparlante
                                    </label>
                                    <select
                                        id="speaker-category"
                                        v-model="selectedSpeakerCategory"
                                        class="w-full rounded-lg border border-neutral-800 bg-[#121212] px-4 py-3 text-sm text-white"
                                    >
                                        <option value="">Seleziona un tipo</option>
                                        <option v-for="category in speakerCategories" :key="category" :value="category">
                                            {{ category }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="selectedSpeakerCategory">
                                <label for="speaker-size" class="mb-2 block text-sm font-medium text-neutral-300">
                                    Misura altoparlante
                                </label>
                                <select
                                    id="speaker-size"
                                    v-model="selectedSpeakerSize"
                                    class="w-full rounded-lg border border-neutral-800 bg-[#121212] px-4 py-3 text-sm text-white"
                                >
                                    <option value="">Seleziona una misura</option>
                                    <option v-for="size in speakerSizes" :key="size" :value="size">
                                        {{ formatSpeakerSize(size) }}
                                    </option>
                                </select>
                                </div>
                            </div>

                            <div v-if="visibleSpeakerOptions.length" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <article
                                    v-for="speaker in visibleSpeakerOptions"
                                    :key="speaker.key"
                                    class="group relative overflow-hidden rounded-xl border transition"
                                    :class="selectedSpeakerKey === speaker.key ? 'border-amber-400 bg-neutral-900' : 'border-neutral-800 bg-[#121212] hover:border-neutral-700'"
                                >
                                    <button
                                        type="button"
                                        class="grid h-full w-full gap-3 p-4 pt-16 text-left"
                                        @click="selectedSpeakerKey = selectedSpeakerKey === speaker.key ? null : speaker.key"
                                    >
                                        <img
                                            v-if="speaker.image"
                                            :src="speaker.image"
                                            :alt="speaker.productTitle"
                                            class="h-36 w-full rounded-lg bg-[#121212] p-2 object-contain object-center sm:h-40"
                                        />
                                        <div>
                                            <p class="font-medium">{{ speaker.productTitle }}</p>
                                            <p v-if="speaker.title !== speaker.productTitle" class="mt-1 text-sm text-neutral-400">{{ speaker.title }}</p>
                                            <p class="mt-2 text-lg font-semibold">{{ speaker.price.toFixed(2) }} €</p>
                                        </div>
                                    </button>
                                    <a
                                        :href="`https://www.autoradiocanario.com/products/${encodeURIComponent(speaker.handle)}`"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg transition hover:bg-amber-400 hover:text-black"
                                    >
                                        Dettagli scheda
                                    </a>
                                </article>
                            </div>
                            <p v-else-if="selectedSpeakerCategory && selectedSpeakerSize" class="mt-4 text-sm text-neutral-500">
                                Nessun altoparlante disponibile per questa misura.
                            </p>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <h2 class="text-2xl font-semibold text-amber-400">
                                5. Installazione
                            </h2>
                            <div class="mt-4 rounded-xl border border-neutral-800 bg-[#121212] p-4">
                                <label for="postal-code" class="block text-sm font-medium text-neutral-200">
                                    Dove desideri l'installazione?
                                </label>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                                    <input
                                        id="postal-code"
                                        v-model="postalCode"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="5"
                                        placeholder="Inserisci CAP"
                                        class="min-w-0 flex-1 rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white placeholder:text-neutral-600"
                                        @keyup.enter="checkPostalCode"
                                    />
                                    <button type="button" class="rounded-lg bg-amber-400 px-5 py-3 text-sm font-semibold text-black transition hover:bg-amber-300" @click="checkPostalCode">
                                        Verifica disponibilità
                                    </button>
                                </div>
                                <p v-if="postalCodeError" class="mt-3 text-sm text-red-400">{{ postalCodeError }}</p>
                                <p v-else-if="checkedPostalCode && matchedInstallationZone && visibleInstallationOptions.length" class="mt-3 text-sm text-emerald-400">
                                    Installazione disponibile nella zona {{ matchedInstallationZone.name }}.
                                </p>
                                <p v-else-if="checkedPostalCode && matchedInstallationZone && hasSelectedProducts" class="mt-3 text-sm text-amber-400">
                                    <span class="text-emerald-400">Installazione disponibile:</span>
                                    <a
                                        href="https://www.autoradiocanario.com/it/pages/contact"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold underline underline-offset-2 hover:text-amber-300"
                                    >consultaci per i dettagli</a>.
                                </p>
                                <p v-else-if="checkedPostalCode && !matchedInstallationZone" class="mt-3 text-sm text-amber-400">
                                    L'installazione potrebbe non essere disponibile:
                                    <a
                                        href="https://www.autoradiocanario.com/it/pages/contact"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold underline underline-offset-2 hover:text-amber-300"
                                    >consultaci per i dettagli</a>.
                                </p>
                                <p v-else-if="checkedPostalCode" class="mt-3 text-sm text-neutral-500">
                                    Seleziona almeno un prodotto per visualizzare il servizio di installazione corrispondente.
                                </p>
                                <p v-else class="mt-3 text-sm text-neutral-500">
                                    Verifica il CAP per visualizzare i servizi disponibili nella tua zona.
                                </p>
                            </div>
                            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <article
                                    v-for="installation in visibleInstallationOptions"
                                    :key="installation.key"
                                    class="relative overflow-hidden rounded-xl border transition"
                                    :class="
                                        selectedInstallationKey === installation.key
                                            ? 'border-amber-400 bg-neutral-900'
                                            : 'border-neutral-800 bg-[#121212] hover:border-neutral-700'
                                    "
                                >
                                    <button
                                        type="button"
                                        class="grid h-full w-full gap-2 p-4 pt-16 text-left"
                                        @click="selectedInstallationKey = selectedInstallationKey === installation.key ? null : installation.key"
                                    >
                                        <p class="text-sm font-medium text-neutral-100">
                                            {{ installation.title }}
                                        </p>
                                        <p class="text-lg font-semibold text-white">
                                            {{ installation.price.toFixed(2) }} €
                                        </p>
                                    </button>
                                    <a
                                        :href="`https://www.autoradiocanario.com/products/${encodeURIComponent(installation.key)}`"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg transition hover:bg-amber-400 hover:text-black"
                                    >
                                        Dettagli scheda
                                    </a>
                                </article>
                            </div>
                        </div>
                    </div>
                </section>

                <aside class="flex flex-col overflow-hidden rounded-2xl border border-neutral-800 bg-[#121212] p-6 lg:sticky lg:top-6 lg:h-[calc(100vh-3rem)] lg:self-start">
                    <div class="shrink-0 overflow-hidden rounded-xl border border-neutral-800 bg-[#121212]">
                        <div
                            v-if="selectedBrand"
                            class="grid"
                        >
                            <div class="flex min-w-0 items-center gap-4 border-b border-neutral-800 bg-[#121212] p-4">
                                <img
                                    v-if="selectedBrandImageUrl && failedBrandImage !== selectedBrandImageUrl"
                                    :src="selectedBrandImageUrl"
                                    :alt="selectedBrand"
                                    class="h-12 w-16 shrink-0 object-contain object-center"
                                    @error="failedBrandImage = selectedBrandImageUrl"
                                />
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-medium uppercase tracking-wider text-neutral-500">
                                        {{ selectedBrand }}
                                    </p>
                                    <div class="mt-1 flex min-w-0 items-baseline gap-2">
                                        <p v-if="selectedModel" class="min-w-0 truncate text-lg font-semibold text-white">
                                            {{ selectedModel }}
                                        </p>
                                        <p v-if="selectedYear" class="shrink-0 text-sm text-neutral-400">
                                            {{ selectedYear }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex h-36 min-w-0 items-center justify-center bg-[#121212] p-3">
                                <img
                                    v-if="selectedVehicleImageUrl && failedVehicleImage !== selectedVehicleImageUrl"
                                    :src="selectedVehicleImageUrl"
                                    :alt="`${selectedBrand} ${selectedModel} ${selectedYear}`"
                                    class="h-full w-full object-contain"
                                    @error="failedVehicleImage = selectedVehicleImageUrl"
                                />
                            </div>
                        </div>
                        <div v-else class="h-52 bg-[#121212]"></div>
                    </div>

                    <div class="lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pr-2">
                    <p class="mt-6 text-sm font-semibold uppercase tracking-[0.24em] text-amber-400">
                        Preventivo
                    </p>

                    <div
                        v-if="selectedScreen || selectedCameras.length || selectedSpeaker"
                        class="mt-4 rounded-xl border px-4 py-3 text-sm"
                        :class="
                            activeDiscount
                                ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
                                : 'border-amber-400/40 bg-amber-400/10 text-amber-300'
                        "
                    >
                        <template v-if="!activeDiscount && nextDiscount">
                            Ti mancano {{ amountUntilNextDiscount.toFixed(2) }} € per ottenere il
                            {{ nextDiscount.percentage }}% di sconto con {{ nextDiscount.code }}.
                        </template>
                        <template v-else-if="activeDiscount">
                            Sconto del {{ activeDiscount.percentage }}% applicato all’intero ordine con
                            {{ activeDiscount.code }}.
                            <span v-if="nextDiscount" class="mt-1 block text-emerald-200/80">
                                Ti mancano {{ amountUntilNextDiscount.toFixed(2) }} € per ottenere il
                                {{ nextDiscount.percentage }}% con {{ nextDiscount.code }}.
                            </span>
                        </template>
                    </div>

                    <div class="mt-6 space-y-4">
                            <div class="rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                                <p class="text-lg font-semibold">
                                    {{ selectedBrand }} {{ selectedModel }}
                                </p>
                            <p class="text-sm text-neutral-400">
                                {{ selectedYear ?? 'Tutti gli anni' }}
                            </p>
                                <p class="mt-3 text-sm text-neutral-500">
                                    {{ selectedVehicle?.title }}
                                </p>
                            </div>

                        <div class="space-y-3 rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div v-if="selectedScreen" class="flex items-start justify-between gap-4">
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

                            <div v-if="selectedSpeaker" class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-neutral-100">
                                        {{ selectedSpeaker?.productTitle ?? 'Nessun altoparlante' }}
                                    </p>
                                    <p class="text-sm text-neutral-500">Altoparlanti</p>
                                </div>
                                <p class="font-semibold">
                                    {{ (selectedSpeaker?.price ?? 0).toFixed(2) }} €
                                </p>
                            </div>

                            <div
                                v-for="camera in selectedCameras"
                                :key="camera.key"
                                class="flex items-start justify-between gap-4"
                            >
                                <div>
                                    <p class="font-medium text-neutral-100">
                                        {{ camera.title }}
                                    </p>
                                    <p class="text-sm text-neutral-500">Camera</p>
                                </div>
                                <p class="font-semibold">
                                    {{ camera.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div v-if="selectedInstallation" class="flex items-start justify-between gap-4">
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
                    </div>
                    </div>

                    <div class="mt-4 shrink-0 space-y-4 border-t border-neutral-800 bg-[#121212] pt-4">
                        <div class="rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div class="flex items-center justify-between text-sm text-neutral-400">
                                <span>Subtotale prodotti</span>
                                <span>{{ productsSubtotal.toFixed(2) }} €</span>
                            </div>
                            <div v-if="selectedInstallation" class="mt-2 flex items-center justify-between text-sm text-neutral-400">
                                <span>Installazione</span>
                                <span>{{ selectedInstallation.price.toFixed(2) }} €</span>
                            </div>
                            <div
                                v-if="discountAmount > 0"
                                class="mt-2 flex items-center justify-between text-sm text-emerald-400"
                            >
                                <span>Sconto {{ activeDiscount?.percentage }}%</span>
                                <span>−{{ discountAmount.toFixed(2) }} €</span>
                            </div>
                            <div class="mt-4 flex items-center justify-between border-t border-neutral-800 pt-4">
                                <span class="text-lg font-semibold text-white">Totale</span>
                                <span class="text-3xl font-semibold text-amber-400">
                                    {{ discountedTotal.toFixed(2) }} €
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
        <p class="pb-6 text-center text-xs text-neutral-700">
            Dati geografici CAP forniti da
            <a href="https://www.geonames.org/" target="_blank" rel="noopener noreferrer" class="underline hover:text-neutral-500">GeoNames</a>
            (CC BY 4.0).
        </p>
    </div>
</template>
