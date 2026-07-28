<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
    sku?: string | null;
    subtype?: string | null;
    location?: string | null;
    installationRaw?: string | null;
    isStandard?: boolean;
    isStandardFront?: boolean;
    isFront?: boolean;
    isRear?: boolean;
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

type TranslationTree = {
    [key: string]: string | TranslationTree;
};

const props = defineProps<{
    locale: 'es' | 'it' | 'en';
    translations: TranslationTree;
    vehicles: Vehicle[];
    cameraOptions: SimpleOption[];
    speakerOptions: SpeakerOption[];
    installationOptions: SimpleOption[];
    installationZones: InstallationZone[];
    vehicleImages: string[];
    brandImages: string[];
}>();

const t = (key: string, replacements: Record<string, string | number> = {}) => {
    let value: string | TranslationTree = props.translations;

    for (const segment of key.split('.')) {
        if (typeof value === 'string' || !(segment in value)) {
            return key;
        }

        value = value[segment];
    }

    if (typeof value !== 'string') {
        return key;
    }

    return Object.entries(replacements).reduce(
        (translated, [placeholder, replacement]) =>
            translated.replaceAll(`:${placeholder}`, String(replacement)),
        value,
    );
};

const storefrontUrl = (path: string) => {
    const localePrefix = props.locale === 'es' ? '' : `/${props.locale}`;

    return `https://www.autoradiocanario.com${localePrefix}${path}`;
};

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
const selectedScreenVariantIds = ref<number[]>([]);
const selectedCameraKeys = ref<string[]>([]);
const selectedSpeakerCategory = ref<string>('');
const selectedSpeakerSizeByCategory = ref<Record<string, string>>({});
const selectedSpeakerKeys = ref<string[]>([]);
const selectedInstallationKey = ref<string | null>(null);
const installationRequested = ref(false);
const openSteps = ref<string[]>([]);
const toggleStep = (step: string) => {
    openSteps.value = openSteps.value.includes(step)
        ? openSteps.value.filter((openStep) => openStep !== step)
        : [...openSteps.value, step];
};
const showMissingVehicleForm = ref(false);
const missingVehicleSending = ref(false);
const missingVehicleSent = ref(false);
const missingVehicleError = ref('');
const quoteTotals = ref<HTMLElement | null>(null);
const mobileQuoteTotals = ref<HTMLElement | null>(null);
const showMobileQuoteTotals = ref(false);
const updateMobileQuoteTotals = () => {
    if (!quoteTotals.value || !mobileQuoteTotals.value || window.innerWidth >= 1024) {
        showMobileQuoteTotals.value = false;
        return;
    }

    const fixedHeight = mobileQuoteTotals.value.offsetHeight;
    showMobileQuoteTotals.value =
        quoteTotals.value.getBoundingClientRect().top > window.innerHeight - fixedHeight;
};
const missingVehicleForm = ref({ first_name: '', last_name: '', email: '', phone: '', province: '', brand: '', model: '', year: '', comment: '', photo: null as File | null });

const openMissingVehicleForm = () => {
    missingVehicleSent.value = false;
    missingVehicleError.value = '';
    showMissingVehicleForm.value = true;
};

const submitMissingVehicleForm = async () => {
    const requiredFields = ['first_name', 'last_name', 'email', 'phone', 'province', 'brand', 'model', 'year'] as const;
    if (requiredFields.some((field) => !missingVehicleForm.value[field]) || !missingVehicleForm.value.photo) {
        missingVehicleError.value = t('vehicle.form_required');
        return;
    }
    missingVehicleSending.value = true;
    missingVehicleError.value = '';
    const payload = new FormData();
    Object.entries(missingVehicleForm.value).forEach(([key, value]) => {
        if (value) payload.append(key, value as string | Blob);
    });
    try {
        const response = await fetch('/configurator/missing-vehicle', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' },
            body: payload,
        });
        if (!response.ok) {
            const errorBody = await response.json().catch(() => ({}));
            const validationMessage = errorBody.errors
                ? Object.values(errorBody.errors).flat().join(' ')
                : '';
            throw new Error(validationMessage || errorBody.message || `HTTP ${response.status}`);
        }
        missingVehicleSent.value = true;
    } catch (error) {
        missingVehicleError.value = error instanceof Error && error.message !== 'send' ? error.message : t('vehicle.form_error');
    } finally {
        missingVehicleSending.value = false;
    }
};
const selectedPrecheckMethod = ref<'self' | 'installer' | null>(null);
const selectedServiceZone = ref<'north' | 'capital' | 'south' | null>(null);
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

const compatibleVehicles = computed(() => {
    if (selectedYear.value === null || selectedModel.value === null) {
        return [];
    }

    return matchingVehicles.value.filter((vehicle) => {
            // Never treat rear-camera products accidentally imported as screens as compatible screens.
            if (/c[aá]mara|camera|telecamera/i.test(`${vehicle.title} ${vehicle.handle}`)) {
                return false;
            }
            if (vehicle.yearFrom === null || vehicle.yearTo === null) {
                return false;
            }

            return (
                selectedYear.value! >= vehicle.yearFrom &&
                selectedYear.value! <= vehicle.yearTo
            );
        });
});

const toggleScreenStep = async () => {
    const canJumpToFirstScreen =
        window.innerWidth < 1024
        && selectedBrand.value !== null
        && selectedYear.value !== null
        && selectedModel.value !== null
        && compatibleVehicles.value.length > 0;

    if (!canJumpToFirstScreen) {
        toggleStep('screen');
        return;
    }

    if (!openSteps.value.includes('screen')) {
        openSteps.value = [...openSteps.value, 'screen'];
    }

    await nextTick();
    window.requestAnimationFrame(() => {
        document
            .getElementById(`screen-product-${compatibleVehicles.value[0].id}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};

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

const selectedVehicle = computed(
    () =>
        compatibleVehicles.value.find((vehicle) =>
            vehicle.variants.some(
                (variant) => selectedScreenVariantIds.value.includes(variant.id),
            ),
        ) ?? null,
);

const selectedScreens = computed(() =>
    compatibleVehicles.value.flatMap((vehicle) =>
        vehicle.variants.filter((variant) =>
            selectedScreenVariantIds.value.includes(variant.id),
        ),
    ),
);

const toggleScreenVariant = (variantId: number) => {
    selectedScreenVariantIds.value = selectedScreenVariantIds.value.includes(variantId)
        ? selectedScreenVariantIds.value.filter((selectedId) => selectedId !== variantId)
        : [...selectedScreenVariantIds.value, variantId];
};

const vehicleForScreenVariant = (variantId: number) =>
    compatibleVehicles.value.find((vehicle) =>
        vehicle.variants.some((variant) => variant.id === variantId),
    ) ?? null;

const screenImage = (vehicle: Vehicle) =>
    vehicle.image ?? vehicle.variants.find((variant) => variant.image)?.image ?? null;

const screenProductUrl = (vehicle: Vehicle) =>
    storefrontUrl(`/products/${encodeURIComponent(vehicle.handle)}`);

const productUrl = (handle: string) =>
    storefrontUrl(`/products/${encodeURIComponent(handle)}`);

const failedVehicleImage = ref<string | null>(null);
const zoomedImage = ref<{ src: string; alt: string } | null>(null);

const openImageZoom = (src: string, alt: string) => {
    zoomedImage.value = { src, alt };
};

const closeImageZoom = () => {
    zoomedImage.value = null;
};

const closeImageZoomOnEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeImageZoom();
    }
};

let variantScrollElement: HTMLElement | null = null;
let variantScrollVelocity = 0;
let variantScrollFrame: number | null = null;

const stopVariantAutoScroll = () => {
    variantScrollVelocity = 0;
    variantScrollElement = null;

    if (variantScrollFrame !== null) {
        window.cancelAnimationFrame(variantScrollFrame);
        variantScrollFrame = null;
    }
};

const runVariantAutoScroll = () => {
    if (!variantScrollElement || variantScrollVelocity === 0) {
        variantScrollFrame = null;
        return;
    }

    const previousScrollTop = variantScrollElement.scrollTop;
    variantScrollElement.scrollTop += variantScrollVelocity;

    if (variantScrollElement.scrollTop === previousScrollTop) {
        variantScrollVelocity = 0;
        variantScrollFrame = null;
        return;
    }

    variantScrollFrame = window.requestAnimationFrame(runVariantAutoScroll);
};

const updateVariantAutoScroll = (event: PointerEvent) => {
    if (event.pointerType !== 'mouse') {
        stopVariantAutoScroll();
        return;
    }

    const element = event.currentTarget as HTMLElement;
    const bounds = element.getBoundingClientRect();
    const edgeSize = Math.min(72, bounds.height / 3);
    const distanceFromTop = event.clientY - bounds.top;
    const distanceFromBottom = bounds.bottom - event.clientY;

    variantScrollElement = element;

    if (distanceFromBottom < edgeSize) {
        variantScrollVelocity = 1 + (1 - distanceFromBottom / edgeSize) * 7;
    } else if (distanceFromTop < edgeSize) {
        variantScrollVelocity = -(1 + (1 - distanceFromTop / edgeSize) * 7);
    } else {
        variantScrollVelocity = 0;
    }

    if (variantScrollVelocity === 0) {
        if (variantScrollFrame !== null) {
            window.cancelAnimationFrame(variantScrollFrame);
            variantScrollFrame = null;
        }
        return;
    }

    if (variantScrollFrame === null) {
        variantScrollFrame = window.requestAnimationFrame(runVariantAutoScroll);
    }
};

onMounted(() => {
    document.documentElement.lang = props.locale;
    window.addEventListener('keydown', closeImageZoomOnEscape);
    window.addEventListener('scroll', updateMobileQuoteTotals, { passive: true });
    window.addEventListener('resize', updateMobileQuoteTotals);
    requestAnimationFrame(updateMobileQuoteTotals);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', closeImageZoomOnEscape);
    window.removeEventListener('scroll', updateMobileQuoteTotals);
    window.removeEventListener('resize', updateMobileQuoteTotals);
    stopVariantAutoScroll();
});
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
    return props.cameraOptions.filter((option) => {
        if (option.isStandard) {
            return true;
        }

        if (
            selectedBrand.value === null ||
            selectedModel.value === null ||
            selectedYear.value === null
        ) {
            return false;
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
    const is360 = key === 'camara-360-para-radios-de-coche-android-con-vista-de-ave';
    const has360 = selectedCameraKeys.value.includes('camara-360-para-radios-de-coche-android-con-vista-de-ave');

    if (is360) {
        selectedCameraKeys.value = has360 ? [] : [key];
        return;
    }

    const without360 = selectedCameraKeys.value.filter(
        (selectedKey) => selectedKey !== 'camara-360-para-radios-de-coche-android-con-vista-de-ave',
    );
    selectedCameraKeys.value = without360.includes(key)
        ? without360.filter((selectedKey) => selectedKey !== key)
        : [...without360, key];
};

const speakerCategories = computed(() =>
    [
        ...new Set(
            props.speakerOptions
                .flatMap((speaker) => speaker.categories)
                .filter((category) => category.toLocaleLowerCase() !== 'motocicleta'),
        ),
    ]
        .sort((a, b) => a.localeCompare(b)),
);

const speakerSizes = computed(() =>
    [...new Set(props.speakerOptions
        .filter((speaker) => speaker.categories.some((category) =>
            category === selectedSpeakerCategory.value,
        ))
        .flatMap((speaker) => speaker.sizes))]
        .sort((a, b) => a.localeCompare(b, undefined, { numeric: true })),
);

const selectedSpeakerSizes = computed(() =>
    selectedSpeakerSizeByCategory.value[selectedSpeakerCategory.value] ?? '',
);

const toggleSpeakerCategory = (category: string) => {
    selectedSpeakerCategory.value = selectedSpeakerCategory.value === category ? '' : category;
};

const toggleSpeakerSize = (size: string) => {
    selectedSpeakerSizeByCategory.value = {
        ...selectedSpeakerSizeByCategory.value,
        [selectedSpeakerCategory.value]: selectedSpeakerSizes.value === size ? '' : size,
    };
};

const formatSpeakerSize = (value: string) => {
    const slug = value
        .replace(/^.*speaker-nominal-size\./i, '')
        .replace(/-(?:pollici|pollice|pulgadas?|inches?)$/i, '');

    const size = /^\d+-\d+$/.test(slug)
        ? slug.replace('-', ',')
        : slug.replace(/-x-/gi, ' × ').replace(/-/g, ' ');

    return `${size}\u2033`;
};

const formatSpeakerCategory = (category: string) => {
    const normalized = category
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLocaleLowerCase();

    const translationKeys: Record<string, string> = {
        'rango completo': 'speaker.categories.full_range',
        'full range': 'speaker.categories.full_range',
        'gamma completa': 'speaker.categories.full_range',
        'rango medio': 'speaker.categories.midrange',
        midrange: 'speaker.categories.midrange',
        'gamma media': 'speaker.categories.midrange',
        subwoofer: 'speaker.categories.subwoofer',
        tweeter: 'speaker.categories.tweeter',
        woofer: 'speaker.categories.woofer',
    };

    return translationKeys[normalized] ? t(translationKeys[normalized]) : category;
};

const visibleSpeakerOptions = computed(() =>
    selectedSpeakerCategory.value === '' || selectedSpeakerSizes.value === ''
        ? []
        : props.speakerOptions.filter((speaker) =>
            speaker.categories.includes(selectedSpeakerCategory.value) &&
            speaker.sizes.includes(selectedSpeakerSizes.value),
        ),
);

const selectedSpeakers = computed(() =>
    props.speakerOptions.filter((speaker) =>
        selectedSpeakerKeys.value.includes(speaker.key),
    ),
);

const toggleSpeaker = (key: string) => {
    selectedSpeakerKeys.value = selectedSpeakerKeys.value.includes(key)
        ? selectedSpeakerKeys.value.filter((selectedKey) => selectedKey !== key)
        : [...selectedSpeakerKeys.value, key];
};

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

const isGranCanaria = computed(() =>
    matchedInstallationZone.value?.name
        .toLocaleLowerCase()
        .includes('gran canaria') ?? false,
);

const precheckProduct = computed(() =>
    props.installationOptions.find((option) =>
        option.subtype === 'precheck' ||
        option.title.toLocaleLowerCase().includes('precheck'),
    ) ?? null,
);

const precheckPrice = computed(() =>
    selectedPrecheckMethod.value === 'installer'
        ? (precheckProduct.value?.price ?? 40)
        : 0,
);

const serviceZones = computed(() => [
    { key: 'north' as const, label: t('installation.zone_north') },
    { key: 'capital' as const, label: t('installation.zone_capital') },
    { key: 'south' as const, label: t('installation.zone_south') },
]);

const toggleServiceZone = (zone: 'north' | 'capital' | 'south') => {
    selectedServiceZone.value = selectedServiceZone.value === zone ? null : zone;
    selectedPrecheckMethod.value = null;
    selectedInstallationKey.value = null;
};

const togglePrecheckMethod = (method: 'self' | 'installer') => {
    selectedPrecheckMethod.value =
        selectedPrecheckMethod.value === method ? null : method;
    selectedInstallationKey.value = null;
};

const hasSelectedProducts = computed(
    () => Boolean(selectedScreens.value.length || selectedCameras.value.length || selectedSpeakers.value.length),
);
const requiresPrecheck = computed(
    () => selectedScreens.value.length > 0,
);
const showsInstallationZoneStep = computed(
    () => Boolean(checkedPostalCode.value && isGranCanaria.value && hasSelectedProducts.value),
);
const precheckStepNumber = computed(
    () => showsInstallationZoneStep.value ? 2 : 1,
);
const finalInstallationStepNumber = computed(
    () => 1 +
        (showsInstallationZoneStep.value ? 1 : 0) +
        (requiresPrecheck.value ? 1 : 0),
);

const normalizeInstallationValue = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .replace(/\s*\+\s*/g, '+')
        .replace(/\s+/g, ' ')
        .trim();

const requiredInstallationCombination = computed(() => {
    const hasScreen = selectedScreens.value.length > 0;
    const hasCamera = selectedCameras.value.length > 0;
    const hasSpeaker = selectedSpeakers.value.length > 0;
    const hasCamera360 = selectedCameras.value.some(
        (camera) => camera.key === 'camara-360-para-radios-de-coche-android-con-vista-de-ave',
    );

    if (hasCamera && hasSpeaker) return null;
    if (hasSpeaker && !hasScreen) return null;

    if (hasScreen && hasSpeaker) {
        if (selectedSpeakers.value.length === 1) return '2 altavoces+pantalla';
        if (selectedSpeakers.value.length === 2) return '4 altavoces+pantalla';
        return null;
    }

    if (hasScreen && hasCamera360) return 'pantalla+camara 360';
    if (hasScreen && selectedCameras.value.length >= 2) return 'pantalla+2 camara';
    if (hasScreen && hasCamera) return 'pantalla+camara';
    if (hasScreen) return 'pantalla';
    if (hasCamera360) return 'camara 360';
    if (selectedCameras.value.length >= 2) return '2 camara';
    if (hasCamera) return 'camara';

    return null;
});

const optionInstallationCombination = (option: SimpleOption) => {
    const raw = option.installationRaw ?? '';
    const commaPosition = raw.indexOf(',');

    if (commaPosition >= 0) {
        return normalizeInstallationValue(raw.slice(commaPosition + 1));
    }

    return normalizeInstallationValue(option.subtype ?? '');
};

const visibleInstallationOptions = computed(() => {
    if (
        !matchedInstallationZone.value ||
        (hasSelectedProducts.value && !requiredInstallationCombination.value) ||
        (hasSelectedProducts.value && isGranCanaria.value && !selectedServiceZone.value) ||
        (requiresPrecheck.value && !selectedPrecheckMethod.value)
    ) {
        return [];
    }

    const zoneName = normalizeInstallationValue(matchedInstallationZone.value.name);
    const matchingOptions = props.installationOptions.filter((option) => {
        const belongsToZone =
            normalizeInstallationValue(option.location ?? '') === zoneName ||
            matchedInstallationZone.value!.productHandles.includes(option.key);

        return belongsToZone &&
            option !== precheckProduct.value &&
            (!hasSelectedProducts.value ||
                optionInstallationCombination(option) === requiredInstallationCombination.value);
    });

    const uniqueOptions = new Map<string, SimpleOption>();
    matchingOptions.forEach((option) => {
        const key = `${normalizeInstallationValue(option.location ?? '')}|${optionInstallationCombination(option)}`;
        if (!uniqueOptions.has(key)) uniqueOptions.set(key, option);
    });

    return [...uniqueOptions.values()];
});

const checkPostalCode = async () => {
    const normalized = postalCode.value.trim();

    if (!/^\d{5}$/.test(normalized)) {
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = t('errors.postal_invalid');
        return;
    }

    const matchesConfiguredZone = props.installationZones.some((zone) =>
        zone.postalRanges.some(
            (range) => normalized >= range.from && normalized <= range.to,
        ),
    );

    if (matchesConfiguredZone) {
        postalCode.value = normalized;
        checkedPostalCode.value = normalized;
        resolvedInstallationArea.value = null;
        postalCodeError.value = null;
        return;
    }

    try {
        const response = await fetch(`/configurator/postal-code/${normalized}`);

        if (!response.ok) {
            throw new Error('Postal code lookup failed.');
        }

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
        postalCodeError.value = t('errors.postal_lookup');
    }
};

const selectedInstallation = computed(
    () =>
        visibleInstallationOptions.value.find(
            (option) => option.key === selectedInstallationKey.value,
        ) ?? null,
);

const goToSelectedProduct = async (
    type: 'screen' | 'camera' | 'speaker' | 'installation',
    key: string | number,
) => {
    if (!openSteps.value.includes(type)) {
        openSteps.value = [...openSteps.value, type];
    }

    if (type === 'speaker') {
        const speaker = props.speakerOptions.find((option) => option.key === key);

        if (speaker) {
            const categoryWithRememberedSize = speaker.categories.find((candidate) => {
                const rememberedSize = selectedSpeakerSizeByCategory.value[candidate];

                return rememberedSize && speaker.sizes.includes(rememberedSize);
            });
            const category = categoryWithRememberedSize
                ?? (speaker.categories.includes(selectedSpeakerCategory.value)
                    ? selectedSpeakerCategory.value
                    : speaker.categories[0] ?? '');
            const currentSize = selectedSpeakerSizeByCategory.value[category];
            const size = currentSize && speaker.sizes.includes(currentSize)
                ? currentSize
                : speaker.sizes[0] ?? '';

            selectedSpeakerCategory.value = category;
            selectedSpeakerSizeByCategory.value = {
                ...selectedSpeakerSizeByCategory.value,
                [category]: size,
            };
        }
    }

    if (type === 'installation') {
        installationRequested.value = true;
    }

    await nextTick();
    window.requestAnimationFrame(() => {
        document
            .getElementById(`product-${type}-${key}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
};

const productsSubtotal = computed(
    () =>
        selectedScreens.value.reduce((sum, screen) => sum + screen.price, 0) +
        selectedCameras.value.reduce((sum, camera) => sum + camera.price, 0) +
        selectedSpeakers.value.reduce((sum, speaker) => sum + speaker.price, 0),
);

const total = computed(
    () =>
        productsSubtotal.value +
        precheckPrice.value +
        (selectedInstallation.value?.price ?? 0),
);

const discountTiers = [
    { code: 'Vip', threshold: 900, percentage: 5 },
    { code: 'Pro', threshold: 500, percentage: 3 },
    { code: 'Base', threshold: 300, percentage: 2 },
];
const activeDiscount = computed(
    () => discountTiers.find((tier) => total.value >= tier.threshold) ?? null,
);
const nextDiscount = computed(() => {
    return [...discountTiers].reverse().find((tier) => total.value < tier.threshold) ?? null;
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

    selectedScreens.value.forEach((screen) => {
        if (screen.shopifyVariantId) {
            items.push({
                variantId: screen.shopifyVariantId,
                quantity: 1,
            });
        }
    });

    selectedCameras.value.forEach((camera) => {
        if (camera.shopifyVariantId) {
            items.push({
                variantId: camera.shopifyVariantId,
                quantity: 1,
            });
        }
    });

    selectedSpeakers.value.forEach((speaker) => {
        if (speaker.shopifyVariantId) {
            items.push({
                variantId: speaker.shopifyVariantId,
                quantity: 1,
            });
        }
    });

    if (
        selectedInstallation.value?.shopifyVariantId
    ) {
        items.push({
            variantId: selectedInstallation.value.shopifyVariantId,
            quantity: 1,
        });
    }

    if (
        selectedPrecheckMethod.value === 'installer' &&
        precheckProduct.value?.shopifyVariantId
    ) {
        items.push({
            variantId: precheckProduct.value.shopifyVariantId,
            quantity: 1,
        });
    }

    return items;
});

const canCheckout = computed(() => {
    return checkoutLineItems.value.length > 0;
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

const showQuoteModal = ref(false);
const quoteClientName = ref('');
const quoteClientPhone = ref('');
const quoteClientEmail = ref('');
const quoteGenerationError = ref<string | null>(null);

const escapeHtml = (value: string) =>
    value.replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character] ?? character);

const localeTag = computed(() => ({
    es: 'es-ES',
    it: 'it-IT',
    en: 'en-GB',
})[props.locale]);

const euroFormatter = computed(() => new Intl.NumberFormat(localeTag.value, {
    style: 'currency',
    currency: 'EUR',
}));

const nextQuoteNumber = async () => {
    const csrfToken = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
    const response = await fetch('/configurator/quote-number', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        },
        credentials: 'same-origin',
        body: '{}',
    });

    if (!response.ok) {
        throw new Error('Unable to reserve quote number.');
    }

    const result = await response.json();

    return String(result.number);
};

const generateQuote = async (withoutClientData = false) => {
    if ((!withoutClientData && !quoteClientName.value.trim()) || !hasSelectedProducts.value) {
        return;
    }

    quoteGenerationError.value = null;
    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        quoteGenerationError.value = t('errors.popup_blocked');
        return;
    }

    let quoteNumber: string;

    try {
        quoteNumber = await nextQuoteNumber();
    } catch {
        printWindow.close();
        quoteGenerationError.value = t('errors.quote_number');
        return;
    }

    const quoteDate = new Intl.DateTimeFormat(localeTag.value).format(new Date());
    const clientName = withoutClientData
        ? t('print.not_specified')
        : quoteClientName.value.trim();
    const clientPhone = withoutClientData ? '' : quoteClientPhone.value.trim();
    const clientEmail = withoutClientData ? '' : quoteClientEmail.value.trim();
    const vehicle = [selectedBrand.value, selectedModel.value, selectedYear.value]
        .filter(Boolean)
        .join(' ');
    const items: Array<{
        code: string;
        description: string;
        quantity: number;
        price: number;
    }> = [];

    selectedScreens.value.forEach((screen) => {
        const vehicle = vehicleForScreenVariant(screen.id);
        items.push({
            code:
                screen.shopifyVariantId ||
                screen.sku ||
                vehicle?.handle ||
                'PANTALLA',
            description: `${vehicle?.title ?? t('print.screen')} — ${t('print.variant', { variant: screen.title })}`,
            quantity: 1,
            price: screen.price,
        });
    });

    selectedCameras.value.forEach((camera) => {
        items.push({
            code: camera.sku || camera.key,
            description: camera.title,
            quantity: 1,
            price: camera.price,
        });
    });

    selectedSpeakers.value.forEach((speaker) => {
        items.push({
            code: speaker.sku || speaker.handle,
            description: speaker.productTitle,
            quantity: 1,
            price: speaker.price,
        });
    });

    if (selectedInstallation.value) {
        items.push({
            code: selectedInstallation.value.sku || selectedInstallation.value.key,
            description: selectedInstallation.value.title,
            quantity: 1,
            price: selectedInstallation.value.price,
        });
    }

    if (selectedPrecheckMethod.value === 'installer') {
        items.push({
            code: precheckProduct.value?.sku || 'PRECHECK',
            description: t('installation.precheck_installer_summary'),
            quantity: 1,
            price: precheckPrice.value,
        });
    }

    if (activeDiscount.value && discountAmount.value > 0) {
        items.push({
            code: activeDiscount.value.code,
            description: t('print.online_discount', {
                percentage: activeDiscount.value.percentage,
            }),
            quantity: 1,
            price: -discountAmount.value,
        });
    }

    const rows = items.map((item) => `
        <tr>
            <td class="code">${escapeHtml(item.code)}</td>
            <td>${escapeHtml(item.description)}</td>
            <td class="center">${item.quantity}</td>
            <td class="money">${euroFormatter.value.format(item.price)}</td>
            <td class="money">${euroFormatter.value.format(item.price * item.quantity)}</td>
        </tr>
    `).join('');
    const includedItems = items
        .filter((item) => item.price >= 0)
        .map((item) => `<li>${escapeHtml(item.description)}</li>`)
        .join('');
    const checkoutLink = checkoutUrl.value
        ? `<p class="checkout"><strong>${escapeHtml(t('print.purchase_link'))}:</strong><br><span>${escapeHtml(checkoutUrl.value)}</span></p>`
        : '';

    printWindow.document.write(`<!doctype html>
<html lang="${props.locale}">
<head>
    <meta charset="utf-8">
    <title>${escapeHtml(quoteNumber)} — ${escapeHtml(t('print.document_title'))}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body { margin: 0; color: #292727; font-family: Arial, Helvetica, sans-serif; font-size: 11px; }
        .page { width: 100%; min-height: 268mm; display: flex; flex-direction: column; }
        .header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { width: 95px; height: 68px; object-fit: contain; background: #121212; }
        .brand-name { font-size: 22px; font-weight: 800; letter-spacing: .4px; }
        .tagline { margin-top: 5px; font-size: 7px; letter-spacing: 2px; }
        .quote-title { text-align: right; }
        .quote-title h1 { margin: 10px 0 5px; font-size: 24px; text-transform: uppercase; }
        .details { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 18px; }
        .details h2 { margin: 0 0 4px; font-size: 15px; }
        .details p { margin: 3px 0; }
        .issuer { text-align: right; }
        .date { margin: 14px 0; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1.3px solid #292727; padding: 8px 7px; vertical-align: middle; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th { background: #f8d7d7; font-size: 12px; }
        th:nth-child(1) { width: 16%; }
        th:nth-child(2) { width: 46%; }
        th:nth-child(3) { width: 7%; }
        th:nth-child(4), th:nth-child(5) { width: 15.5%; }
        td { font-size: 10px; font-weight: 600; }
        .code, .center { text-align: center; }
        .money { text-align: right; white-space: nowrap; }
        .notes { margin-top: 22px; font-size: 9px; }
        .notes ul { margin: 5px 0 14px; padding-left: 18px; }
        .legal { line-height: 1.35; }
        .checkout { margin-top: 12px; overflow-wrap: anywhere; font-size: 8px; }
        .total { display: grid; grid-template-columns: 2fr 1fr; margin-top: auto; border: 1.3px solid #292727; font-size: 14px; font-weight: 800; }
        .total div { padding: 10px; }
        .total .amount { border-left: 1.3px solid #292727; background: #f8d7d7; text-align: center; }
        footer { margin: 12px -12mm 0; padding: 18px 12mm; background: #f8d7d7; text-align: center; font-size: 7px; letter-spacing: 1.2px; break-inside: avoid; page-break-inside: avoid; }
        @media print {
            html, body { height: auto; }
            .page { min-height: auto; break-after: avoid; page-break-after: avoid; }
            .total { margin-top: 16px; }
            th, .total .amount, footer {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .total, footer { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="header">
        <div class="brand">
            <img src="${window.location.origin}/images/logo.png" alt="AutoRadioCanario">
            <div>
                <div class="brand-name">AUTORADIOCANARIO</div>
                <div class="tagline">${escapeHtml(t('print.tagline'))}</div>
            </div>
        </div>
        <div class="quote-title">
            <h1>${escapeHtml(t('quote.title'))}</h1>
            <div>${escapeHtml(t('print.number'))}: ${escapeHtml(quoteNumber)}</div>
        </div>
    </header>
    <section class="details">
        <div>
            <h2>${escapeHtml(t('print.client'))}:</h2>
            <p><strong>${escapeHtml(clientName)}</strong></p>
            ${clientPhone ? `<p>${escapeHtml(t('print.phone'))}: ${escapeHtml(clientPhone)}</p>` : ''}
            ${clientEmail ? `<p>${escapeHtml(t('print.email'))}: ${escapeHtml(clientEmail)}</p>` : ''}
            <p>${escapeHtml(t('print.vehicle'))}: ${escapeHtml(vehicle || t('print.not_specified'))}</p>
        </div>
        <div class="issuer">
            <h2>${escapeHtml(t('print.issued_by'))}:</h2>
            <p>AutoRadioCanario</p>
            <p>Y9309149M</p>
            <p>Avenida Mencey 49</p>
            <p>35120 Arguineguín</p>
            <p>Las Palmas</p>
        </div>
    </section>
    <p class="date"><strong>${escapeHtml(t('print.date'))}:</strong> ${escapeHtml(quoteDate)}</p>
    <table>
        <thead><tr><th>ID</th><th>${escapeHtml(t('print.description'))}</th><th>${escapeHtml(t('print.quantity'))}</th><th>${escapeHtml(t('print.unit_price'))}</th><th>${escapeHtml(t('print.amount'))}</th></tr></thead>
        <tbody>${rows}</tbody>
    </table>
    <section class="notes">
        <strong>${escapeHtml(t('print.includes'))}:</strong>
        <ul>${includedItems}</ul>
        <p class="legal">${escapeHtml(t('print.legal'))}</p>
        ${checkoutLink}
    </section>
    <section class="total">
        <div>${escapeHtml(t('quote.total'))}</div>
        <div class="amount">${euroFormatter.value.format(discountedTotal.value)}</div>
    </section>
    <footer>INFO@AUTORADIOCANARIO.COM &nbsp;&nbsp; AUTORADIOCANARIO &nbsp;&nbsp; WHATSAPP: +34 694 259 117</footer>
</main>
<script>window.addEventListener('load', () => window.print());<\/script>
</body>
</html>`);
    printWindow.document.close();
    showQuoteModal.value = false;
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
        selectedServiceZone.value = null;
        selectedPrecheckMethod.value = null;
        selectedInstallationKey.value = null;
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
    compatibleVehicles,
    (vehicles) => {
        if (
            selectedModel.value !== null &&
            vehicles.length > 0 &&
            !openSteps.value.includes('screen')
        ) {
            openSteps.value = [...openSteps.value, 'screen'];
        }

        const availableVariantIds = new Set(
            vehicles.flatMap((vehicle) => vehicle.variants.map((variant) => variant.id)),
        );
        selectedScreenVariantIds.value = selectedScreenVariantIds.value.filter((variantId) =>
            availableVariantIds.has(variantId),
        );
    },
    { immediate: true },
);

watch(
    selectedScreens,
    (screens) => {
        if (screens.length > 0 && !openSteps.value.includes('screen')) {
            openSteps.value = [...openSteps.value, 'screen'];
        }
    },
    { immediate: true },
);
</script>

<template>
    <Head :title="t('page_title')" />

    <div class="min-h-screen bg-[#121212] text-white">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-start justify-between gap-6">
                <div class="mx-auto text-center sm:mx-0 sm:text-left">
                    <p class="text-xs font-medium uppercase tracking-[0.2em] text-amber-400 sm:text-sm sm:tracking-[0.24em]">
                        {{ t('intro.eyebrow') }}
                    </p>
                    <h1 class="mx-auto mt-2 max-w-sm text-3xl font-semibold leading-tight tracking-tight sm:mx-0 sm:mt-3 sm:max-w-none sm:text-4xl">
                        {{ t('intro.title') }}
                    </h1>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-5 text-neutral-400 sm:mx-0 sm:mt-3 sm:max-w-3xl sm:text-base sm:leading-normal">
                        {{ t('intro.description') }}
                    </p>
                    <button
                        type="button"
                        @click="openMissingVehicleForm"
                        class="mt-4 inline-flex items-center justify-center rounded-lg border border-amber-400 px-4 py-3 text-center text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black"
                    >
                        {{ t('vehicle.missing') }}
                    </button>
                </div>
                <div v-if="isAdmin" class="flex shrink-0 flex-col gap-2 sm:flex-row">
                    <button
                        type="button"
                        :disabled="!hasSelectedProducts"
                        class="inline-flex items-center justify-center rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition hover:border-amber-400 hover:text-amber-400 disabled:cursor-not-allowed disabled:border-neutral-800 disabled:text-neutral-600"
                        @click="quoteGenerationError = null; showQuoteModal = true"
                    >
                        {{ t('actions.create_quote') }}
                    </button>
                    <button
                        type="button"
                        :disabled="!checkoutUrl"
                        class="inline-flex items-center justify-center rounded-md border border-amber-400 px-4 py-2 text-sm font-medium text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:border-neutral-800 disabled:text-neutral-600 disabled:hover:bg-transparent"
                        @click="copyCheckoutUrl"
                    >
                        {{
                            copyCheckoutStatus === 'copied'
                                ? t('actions.copied')
                                : copyCheckoutStatus === 'error'
                                    ? t('actions.copy_failed')
                                    : t('actions.copy_cart_link')
                        }}
                    </button>
                    <a
                        href="/dashboard"
                        class="inline-flex items-center justify-center rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-200 transition hover:border-neutral-700 hover:bg-neutral-900"
                    >
                        {{ t('admin.dashboard') }}
                    </a>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <section class="rounded-2xl border border-neutral-800 bg-neutral-900/80 p-6">
                    <div class="grid gap-6">
                        <button type="button" class="mx-auto block w-fit min-w-64 rounded-lg border-2 border-black bg-amber-400 px-5 py-4 text-base font-semibold uppercase tracking-wide text-black ring-2 ring-amber-400 transition hover:bg-amber-300" @click="toggleStep('vehicle')">{{ t('steps.vehicle') }}</button>
                        <div v-if="openSteps.includes('vehicle')">

                        <div
                            class="grid gap-4"
                            :class="selectedBrand ? 'md:grid-cols-[1fr_220px]' : ''"
                        >
                            <div>
                                <select
                                    id="vehicle-brand"
                                    v-model="selectedBrand"
                                    class="vehicle-option-select w-full rounded-lg border border-neutral-800 bg-[#121212] px-4 py-3 text-base text-white sm:text-sm"
                                >
                                    <option :value="null">{{ t('fields.select_brand') }}</option>
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
                                <select
                                    v-model="selectedYear"
                                    class="vehicle-option-select w-full rounded-lg border border-neutral-800 bg-[#121212] px-4 py-3 text-base text-white sm:text-sm"
                                >
                                    <option :value="null">{{ t('fields.select_year') }}</option>
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
                                <label class="text-sm font-medium text-neutral-300">{{ t('fields.model') }}</label>
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
                                                : 'border-amber-400 bg-[#121212] text-amber-400 hover:bg-amber-400 hover:text-black'
                                        "
                                    >
                                        {{ model }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        </div>
                        <div class="border-t border-neutral-800 pt-6">
                            <button type="button" class="mx-auto block w-fit min-w-64 rounded-lg border-2 border-black bg-amber-400 px-5 py-4 text-base font-semibold uppercase tracking-wide text-black ring-2 ring-amber-400 transition hover:bg-amber-300" @click="toggleScreenStep">{{ t('steps.screen') }}</button>
                            <div v-if="openSteps.includes('screen') || (selectedModel && compatibleVehicles.length)" class="mt-6">
                            <div v-if="selectedYear !== null && compatibleVehicles.length" class="mt-4 grid gap-5">
                                <article
                                    v-for="vehicle in compatibleVehicles"
                                    :key="vehicle.id"
                                    :id="`screen-product-${vehicle.id}`"
                                    class="grid gap-5 rounded-xl border p-4 transition lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]"
                                    :class="
                                        selectedVehicle?.id === vehicle.id
                                            ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400'
                                            : 'border-neutral-800 bg-[#121212]'
                                    "
                                >
                                    <div class="relative flex min-h-64 items-center justify-center overflow-hidden rounded-xl border border-neutral-800 bg-[#121212] p-4">
                                        <button
                                            v-if="screenImage(vehicle)"
                                            type="button"
                                            class="flex h-full w-full cursor-zoom-in items-center justify-center rounded-lg"
                                            :aria-label="t('screen.zoom')"
                                            @click="openImageZoom(screenImage(vehicle)!, vehicle.title)"
                                        >
                                            <img
                                                :src="screenImage(vehicle)!"
                                                :alt="vehicle.title"
                                                class="max-h-72 w-full rounded-lg object-contain object-center"
                                            />
                                        </button>
                                        <p v-else class="text-sm text-neutral-500">
                                            {{ t('vehicle.image_unavailable') }}
                                        </p>
                                        <a
                                            :href="screenProductUrl(vehicle)"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="absolute left-1/2 top-3 -translate-x-1/2 rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg backdrop-blur transition hover:bg-amber-400 hover:text-black"
                                        >
                                            {{ t('screen.product_details') }}
                                        </a>
                                    </div>

                                    <div class="min-w-0">
                                        <h3 class="mb-3 text-base font-semibold text-white">
                                            {{ vehicle.title }}
                                        </h3>
                                        <div
                                            class="quote-scrollbar max-h-72 overflow-y-auto pr-2"
                                            @pointermove="updateVariantAutoScroll"
                                            @pointerleave="stopVariantAutoScroll"
                                        >
                                            <div class="grid gap-2">
                                                <button
                                                    v-for="variant in vehicle.variants"
                                                    :key="variant.id"
                                                    :id="`product-screen-${variant.id}`"
                                                    type="button"
                                                    @click="toggleScreenVariant(variant.id)"
                                                    class="group flex min-h-10 w-full items-center justify-between gap-4 rounded-lg border px-3 py-2 text-left text-sm font-medium leading-tight transition"
                                                    :class="
                                                        selectedScreenVariantIds.includes(variant.id)
                                                            ? 'border-amber-400 bg-amber-400 text-black'
                                                            : 'border-amber-400 bg-[#121212] text-amber-400 hover:bg-amber-400/10'
                                                    "
                                                >
                                                    <span class="min-w-0 flex-1 truncate">{{ variant.title }}</span>
                                                    <span
                                                        class="shrink-0 whitespace-nowrap text-xs"
                                                        :class="
                                                            selectedScreenVariantIds.includes(variant.id)
                                                                ? 'text-black/70'
                                                                : 'text-amber-400'
                                                        "
                                                    >
                                                        {{ variant.price.toFixed(2) }} €
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </div>
                            <p
                                v-else-if="selectedBrand && selectedModel && selectedYear"
                                class="mt-4 text-sm text-neutral-300"
                            >
                                <button
                                    type="button"
                                    class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    @click="openMissingVehicleForm"
                                >{{ t('screen.missing') }}</button>
                            </p>
                            <p v-else class="mt-4 text-sm text-neutral-500">
                                {{ t('screen.select_vehicle') }}
                            </p>
                            </div>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <button type="button" class="mx-auto block w-fit min-w-64 rounded-lg border-2 border-black bg-amber-400 px-5 py-4 text-base font-semibold uppercase tracking-wide text-black ring-2 ring-amber-400 transition hover:bg-amber-300" @click="toggleStep('camera')">{{ t('steps.camera') }}</button>
                            <div v-if="openSteps.includes('camera')" class="mt-6">
                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <div
                                    v-for="camera in visibleCameraOptions"
                                    :key="camera.key"
                                    :id="`product-camera-${camera.key}`"
                                    class="group relative min-w-0 overflow-visible rounded-xl border transition"
                                    :class="
                                        selectedCameraKeys.includes(camera.key)
                                            ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400'
                                            : 'border-neutral-800 bg-[#121212] hover:border-neutral-700'
                                    "
                                >
                                    <button
                                        type="button"
                                        @click="toggleCamera(camera.key)"
                                        class="grid h-full min-w-0 w-full max-w-full grid-cols-[minmax(0,1fr)] gap-0 overflow-hidden rounded-xl p-0 text-left"
                                    >
                                        <div
                                            v-if="camera.image"
                                            class="relative h-48 min-w-0 w-full max-w-full overflow-hidden rounded-lg transition sm:h-56"
                                            :class="selectedCameraKeys.includes(camera.key) ? 'bg-amber-400/10' : 'bg-[#121212]'"
                                        >
                                            <img
                                                :src="camera.image"
                                                :alt="camera.title"
                                                class="absolute inset-0 h-full w-full object-contain object-center"
                                            />
                                        </div>
                                        <div class="flex min-w-0 w-full max-w-full items-center justify-start gap-1 overflow-hidden p-2">
                                            <p class="min-w-0 flex-1 truncate whitespace-nowrap text-xs font-medium">{{ camera.isStandardFront ? t('camera.standard_front') : camera.title }}</p>
                                            <p class="shrink-0 whitespace-nowrap text-sm font-semibold">
                                                {{ camera.price.toFixed(2) }} €
                                            </p>
                                        </div>
                                    </button>

                                    <div
                                        v-if="!camera.isStandard"
                                        class="pointer-events-none absolute -left-8 top-5 z-20 w-28 -rotate-45 bg-amber-400 px-2 py-1.5 text-center text-[10px] font-bold uppercase tracking-wide text-black shadow-lg"
                                    >
                                        {{ t('camera.specific_for_vehicle') }}
                                    </div>

                                    <a
                                        :href="productUrl(camera.key)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg backdrop-blur transition hover:bg-amber-400 hover:text-black"
                                    >
                                        {{ t('screen.product_details') }}
                                    </a>
                                </div>
                            </div>
                            <p
                                v-if="selectedBrand && selectedModel && selectedYear && !hasSpecificCameraOption"
                                class="mt-4 text-sm text-neutral-300"
                            >
                                <button
                                    type="button"
                                    class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    @click="openMissingVehicleForm"
                                >{{ t('camera.missing') }}</button>
                            </p>
                            </div>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <button type="button" class="mx-auto block w-fit min-w-64 rounded-lg border-2 border-black bg-amber-400 px-5 py-4 text-base font-semibold uppercase tracking-wide text-black ring-2 ring-amber-400 transition hover:bg-amber-300" @click="toggleStep('speaker')">{{ t('steps.speaker') }}</button>
                            <div v-if="openSteps.includes('speaker')" class="mt-6">
                            <div class="mt-4 grid max-w-2xl gap-4">
                                <div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="category in speakerCategories"
                                            :key="category"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-sm transition"
                                            :class="
                                                selectedSpeakerCategory === category
                                                    ? 'border-amber-400 bg-amber-400 text-black'
                                                    : 'border-amber-400 bg-[#121212] text-amber-400 hover:bg-amber-400 hover:text-black'
                                            "
                                            @click="toggleSpeakerCategory(category)"
                                        >
                                            {{ formatSpeakerCategory(category) }}
                                        </button>
                                    </div>
                                </div>
                                <div v-if="selectedSpeakerCategory">
                                <p class="mb-2 block text-sm font-medium text-neutral-300">
                                    {{ t('speaker.size') }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="size in speakerSizes"
                                        :key="size"
                                        type="button"
                                        class="rounded-lg border px-4 py-3 text-sm transition"
                                        :class="selectedSpeakerSizes === size ? 'border-amber-400 bg-amber-400 text-black' : 'border-amber-400 bg-[#121212] text-amber-400 hover:bg-amber-400 hover:text-black'"
                                        @click="toggleSpeakerSize(size)"
                                    >
                                        {{ formatSpeakerSize(size) }}
                                    </button>
                                </div>
                                </div>
                            </div>

                            <div v-if="visibleSpeakerOptions.length" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <article
                                    v-for="speaker in visibleSpeakerOptions"
                                    :key="speaker.key"
                                    :id="`product-speaker-${speaker.key}`"
                                    class="group relative overflow-hidden rounded-xl border transition"
                                    :class="selectedSpeakerKeys.includes(speaker.key) ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-800 bg-[#121212] hover:border-neutral-700'"
                                >
                                    <button
                                        type="button"
                                        class="grid h-full w-full gap-3 p-4 pt-16 text-left"
                                        @click="toggleSpeaker(speaker.key)"
                                    >
                                        <img
                                            v-if="speaker.image"
                                            :src="speaker.image"
                                            :alt="speaker.productTitle"
                                            class="h-36 w-full rounded-lg p-2 object-contain object-center transition sm:h-40"
                                            :class="selectedSpeakerKeys.includes(speaker.key) ? 'bg-amber-400/10' : 'bg-[#121212]'"
                                        />
                                        <div>
                                            <p class="font-medium">{{ speaker.productTitle }}</p>
                                            <p v-if="speaker.title !== speaker.productTitle" class="mt-1 text-sm text-neutral-400">{{ speaker.title }}</p>
                                            <p class="mt-2 text-lg font-semibold">{{ speaker.price.toFixed(2) }} €</p>
                                        </div>
                                    </button>
                                    <a
                                        :href="productUrl(speaker.handle)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg transition hover:bg-amber-400 hover:text-black"
                                    >
                                        {{ t('screen.product_details') }}
                                    </a>
                                </article>
                            </div>
                            <p v-else-if="selectedSpeakerCategory && selectedSpeakerSizes" class="mt-4 text-sm text-neutral-500">
                                {{ t('speaker.no_options') }}
                            </p>
                            </div>
                        </div>

                        <div class="border-t border-neutral-800 pt-6">
                            <button type="button" class="mx-auto block w-fit min-w-64 rounded-lg border-2 border-black bg-amber-400 px-5 py-4 text-base font-semibold uppercase tracking-wide text-black ring-2 ring-amber-400 transition hover:bg-amber-300" @click="toggleStep('installation'); installationRequested = true">{{ t('steps.installation') }}</button>
                            <div v-if="openSteps.includes('installation')" class="mt-6">
                            <p v-if="hasSelectedProducts" class="mt-2 max-w-3xl text-sm leading-6 text-neutral-400">
                                {{ t('installation.intro') }}
                            </p>
                            <div v-if="installationRequested" class="mt-4 rounded-xl border border-neutral-800 bg-[#121212] p-4">
                                <label for="postal-code" class="block text-sm font-medium text-neutral-200">
                                    {{ t('installation.question') }}
                                </label>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                                    <input
                                        id="postal-code"
                                        v-model="postalCode"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="5"
                                        :placeholder="t('installation.postal_placeholder')"
                                        class="min-w-0 flex-1 rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white placeholder:text-neutral-600"
                                        @keyup.enter="checkPostalCode"
                                    />
                                    <button type="button" class="rounded-lg bg-amber-400 px-5 py-3 text-sm font-semibold text-black transition hover:bg-amber-300" @click="checkPostalCode">
                                        {{ t('installation.check') }}
                                    </button>
                                </div>
                                <p v-if="postalCodeError" class="mt-3 text-sm text-red-400">{{ postalCodeError }}</p>
                                <p v-else-if="checkedPostalCode && matchedInstallationZone" class="mt-3 text-sm text-emerald-400">
                                    {{ t('installation.available_zone', { zone: matchedInstallationZone.name }) }}
                                </p>
                                <p v-else-if="checkedPostalCode" class="mt-3 text-sm text-amber-400">
                                    {{ t('installation.unavailable') }}
                                    <a
                                        :href="storefrontUrl('/pages/contact')"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold underline underline-offset-2 hover:text-amber-300"
                                    >{{ t('installation.contact_details') }}</a>.
                                </p>
                                <p v-else class="mt-3 text-sm text-neutral-500">
                                    {{ t('installation.check_to_view') }}
                                </p>
                            </div>

                            <div class="mt-4 grid gap-5 lg:grid-cols-[1.05fr_.95fr]">
                            <div v-if="checkedPostalCode && isGranCanaria && hasSelectedProducts" class="contents">
                                <div v-if="hasSelectedProducts" class="rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:p-6">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-400">
                                        {{ t('installation.zone_step', { step: 1 }) }}
                                    </p>
                                    <h3 class="mt-2 text-xl font-semibold text-white">
                                        {{ t('installation.zone_question') }}
                                    </h3>
                                    <p class="mt-2 text-sm leading-6 text-neutral-400">
                                        {{ t('installation.zone_help') }}
                                    </p>

                                    <div class="relative mx-auto mt-5 max-w-sm overflow-hidden rounded-xl">
                                        <img
                                            src="/images/installation/gran-canaria-zones.png"
                                            :alt="t('installation.map_label')"
                                            class="h-auto w-full"
                                        />
                                        <button
                                            type="button"
                                            :aria-label="t('installation.zone_north')"
                                            class="absolute inset-0 cursor-pointer transition hover:bg-amber-400/10"
                                            :class="selectedServiceZone === 'north' ? 'bg-amber-400/20' : ''"
                                            style="clip-path: polygon(6% 8%, 92% 8%, 64% 41%, 5% 43%);"
                                            @click="toggleServiceZone('north')"
                                        />
                                        <button
                                            type="button"
                                            :aria-label="t('installation.zone_capital')"
                                            class="absolute inset-0 cursor-pointer transition hover:bg-amber-400/10"
                                            :class="selectedServiceZone === 'capital' ? 'bg-amber-400/20' : ''"
                                            style="clip-path: polygon(64% 8%, 96% 15%, 96% 48%, 65% 43%, 45% 39%, 64% 28%);"
                                            @click="toggleServiceZone('capital')"
                                        />
                                        <button
                                            type="button"
                                            :aria-label="t('installation.zone_south')"
                                            class="absolute inset-0 cursor-pointer transition hover:bg-amber-400/10"
                                            :class="selectedServiceZone === 'south' ? 'bg-amber-400/20' : ''"
                                            style="clip-path: polygon(5% 41%, 45% 39%, 65% 43%, 96% 48%, 94% 91%, 9% 91%);"
                                            @click="toggleServiceZone('south')"
                                        />
                                    </div>

                                    <div class="mt-4 grid gap-2">
                                        <button
                                            v-for="zone in serviceZones"
                                            :key="zone.key"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === zone.key ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone(zone.key)"
                                        >
                                            {{ zone.label }}
                                        </button>
                                    </div>
                                </div>

                                <div v-if="requiresPrecheck" class="rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:p-6">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-400">
                                        {{ t('installation.precheck_step', { step: precheckStepNumber }) }}
                                    </p>
                                    <h3 class="mt-2 text-xl font-semibold text-white">
                                        {{ t('installation.precheck_question') }}
                                    </h3>
                                    <p class="mt-2 text-sm leading-6 text-neutral-400">
                                        {{ t('installation.precheck_help') }}
                                    </p>

                                    <div class="mt-5 grid gap-3">
                                        <button
                                            type="button"
                                            :disabled="!selectedServiceZone"
                                            class="rounded-xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                                            :class="selectedPrecheckMethod === 'self' ? 'border-amber-400 bg-amber-400/10' : 'border-neutral-700 bg-neutral-900 hover:border-amber-400'"
                                            @click="togglePrecheckMethod('self')"
                                        >
                                            <span class="block font-semibold text-white">{{ t('installation.precheck_self_title') }}</span>
                                            <span class="mt-1 block text-sm leading-5 text-neutral-400">{{ t('installation.precheck_self_description') }}</span>
                                            <span class="mt-3 block text-sm font-semibold text-emerald-400">{{ t('installation.free') }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="!selectedServiceZone"
                                            class="rounded-xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                                            :class="selectedPrecheckMethod === 'installer' ? 'border-amber-400 bg-amber-400/10' : 'border-neutral-700 bg-neutral-900 hover:border-amber-400'"
                                            @click="togglePrecheckMethod('installer')"
                                        >
                                            <span class="block font-semibold text-white">{{ t('installation.precheck_installer_title') }}</span>
                                            <span class="mt-1 block text-sm leading-5 text-neutral-400">{{ t('installation.precheck_installer_description') }}</span>
                                            <span class="mt-3 block whitespace-nowrap text-lg font-semibold text-white">{{ (precheckProduct?.price ?? 40).toFixed(2) }} €</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="checkedPostalCode && requiresPrecheck" class="rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:p-6">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-400">
                                    {{ t('installation.precheck_step', { step: precheckStepNumber }) }}
                                </p>
                                <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.precheck_self_title') }}</h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-neutral-400">
                                    {{ t('installation.remote_only_description') }}
                                </p>
                                <button
                                    type="button"
                                    class="mt-4 rounded-xl border p-4 text-left transition"
                                    :class="selectedPrecheckMethod === 'self' ? 'border-amber-400 bg-amber-400/10' : 'border-neutral-700 bg-neutral-900 hover:border-amber-400'"
                                    @click="togglePrecheckMethod('self')"
                                >
                                    <span class="font-semibold text-white">{{ t('installation.choose_remote_precheck') }}</span>
                                    <span class="ml-3 text-sm font-semibold text-emerald-400">{{ t('installation.free') }}</span>
                                </button>
                            </div>

                            <div
                                v-if="
                                    (!hasSelectedProducts && checkedPostalCode && matchedInstallationZone) ||
                                    (
                                        hasSelectedProducts &&
                                        checkedPostalCode &&
                                        matchedInstallationZone &&
                                        (!isGranCanaria || selectedServiceZone) &&
                                        (selectedPrecheckMethod || !requiresPrecheck)
                                    )
                                "
                                class="rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:p-6"
                                :class="requiresPrecheck ? 'lg:col-span-2' : ''"
                            >
                                <h3 class="text-sm font-semibold uppercase tracking-[0.24em] text-amber-400">{{ t(hasSelectedProducts ? 'installation.final_installation' : 'installation.standalone_installation', { step: finalInstallationStepNumber }) }}</h3>
                                <p class="mt-1 text-sm text-neutral-400">{{ t(hasSelectedProducts ? 'installation.final_installation_help' : 'installation.standalone_installation_help') }}</p>
                            <div
                                class="mt-4 grid gap-4"
                                :class="requiresPrecheck ? 'md:grid-cols-2 xl:grid-cols-4' : 'grid-cols-1'"
                            >
                                <article
                                    v-for="installation in visibleInstallationOptions"
                                    :key="installation.key"
                                    :id="`product-installation-${installation.key}`"
                                    role="button"
                                    tabindex="0"
                                    class="relative cursor-pointer overflow-hidden rounded-xl border transition"
                                    :class="
                                        selectedInstallationKey === installation.key
                                            ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400'
                                            : 'border-neutral-700 bg-neutral-900 hover:border-amber-400'
                                    "
                                    @click="selectedInstallationKey = selectedInstallationKey === installation.key ? null : installation.key"
                                    @keydown.enter.prevent="selectedInstallationKey = selectedInstallationKey === installation.key ? null : installation.key"
                                    @keydown.space.prevent="selectedInstallationKey = selectedInstallationKey === installation.key ? null : installation.key"
                                >
                                    <div class="grid min-h-40 w-full content-end gap-2 p-4 pt-16 text-left">
                                        <p class="text-sm font-medium text-neutral-100">
                                            {{ installation.title }}
                                        </p>
                                        <p class="text-lg font-semibold text-white">
                                            {{ installation.price.toFixed(2) }} €
                                        </p>
                                    </div>
                                    <a
                                        :href="productUrl(installation.key)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg transition hover:bg-amber-400 hover:text-black"
                                        @click.stop
                                    >
                                        {{ t('screen.product_details') }}
                                    </a>
                                </article>
                            </div>
                                <p v-if="hasSelectedProducts && !visibleInstallationOptions.length" class="mt-4 text-sm text-amber-400">
                                    {{ t('installation.contact_for_combination') }}
                                </p>
                                <div v-if="hasSelectedProducts && !visibleInstallationOptions.length" class="mt-3 flex flex-wrap gap-3 text-sm">
                                    <a
                                        href="mailto:info@autoradiocanario.com"
                                        class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    >{{ t('installation.contact_email') }}: info@autoradiocanario.com</a>
                                    <a
                                        href="https://wa.me/34694259117"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    >{{ t('installation.contact_whatsapp') }}: +34 694 259 117</a>
                                </div>
                            </div>
                            </div>
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

                            <div class="flex h-52 min-w-0 items-center justify-center bg-[#121212] p-1">
                                <button
                                    v-if="selectedVehicleImageUrl && failedVehicleImage !== selectedVehicleImageUrl"
                                    type="button"
                                    class="flex h-full w-full cursor-zoom-in items-center justify-center"
                                    :aria-label="t('vehicle.zoom')"
                                    @click="openImageZoom(selectedVehicleImageUrl, `${selectedBrand} ${selectedModel} ${selectedYear}`)"
                                >
                                    <img
                                        :src="selectedVehicleImageUrl"
                                        :alt="`${selectedBrand} ${selectedModel} ${selectedYear}`"
                                        class="h-full w-full object-contain"
                                        @error="failedVehicleImage = selectedVehicleImageUrl"
                                    />
                                </button>
                            </div>
                        </div>
                        <div v-else class="h-52 bg-[#121212]"></div>
                    </div>

                    <div class="quote-scrollbar lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pr-2">
                    <p class="mt-6 text-sm font-semibold uppercase tracking-[0.24em] text-amber-400">
                        {{ t('quote.title') }}
                    </p>

                    <div
                        v-if="selectedScreens.length || selectedCameras.length || selectedSpeakers.length"
                        class="mt-4 rounded-xl border px-4 py-3 text-sm"
                        :class="
                            activeDiscount
                                ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
                                : 'border-amber-400/40 bg-amber-400/10 text-amber-300'
                        "
                    >
                        <template v-if="!activeDiscount && nextDiscount">
                            {{ t('quote.discount_remaining', {
                                amount: amountUntilNextDiscount.toFixed(2),
                                percentage: nextDiscount.percentage,
                                code: nextDiscount.code,
                            }) }}
                        </template>
                        <template v-else-if="activeDiscount">
                            {{ t('quote.discount_applied', {
                                percentage: activeDiscount.percentage,
                                code: activeDiscount.code,
                            }) }}
                            <span v-if="nextDiscount" class="mt-1 block text-emerald-200/80">
                                {{ t('quote.discount_remaining', {
                                    amount: amountUntilNextDiscount.toFixed(2),
                                    percentage: nextDiscount.percentage,
                                    code: nextDiscount.code,
                                }) }}
                            </span>
                        </template>
                    </div>

                    <div class="mt-6 space-y-4">
                            <div class="rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                                <p class="text-lg font-semibold">
                                    {{ selectedBrand }} {{ selectedModel }}
                                </p>
                            <p class="text-sm text-neutral-400">
                                {{ selectedYear ?? t('vehicle.all_years') }}
                            </p>
                                <p class="mt-3 text-sm text-neutral-500">
                                    {{ selectedVehicle?.title }}
                                </p>
                            </div>

                        <div class="space-y-3 rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div
                                v-for="screen in selectedScreens"
                                :key="screen.id"
                                class="flex items-start justify-between gap-4"
                            >
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        class="text-left font-medium text-neutral-100 underline decoration-transparent underline-offset-4 transition hover:text-amber-400 hover:decoration-amber-400 focus-visible:text-amber-400 focus-visible:decoration-amber-400"
                                        @click="goToSelectedProduct('screen', screen.id)"
                                    >
                                        {{ screen.title }}
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('screen.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ screen.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div
                                v-for="speaker in selectedSpeakers"
                                :key="speaker.key"
                                class="flex items-start justify-between gap-4"
                            >
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        class="text-left font-medium text-neutral-100 underline decoration-transparent underline-offset-4 transition hover:text-amber-400 hover:decoration-amber-400 focus-visible:text-amber-400 focus-visible:decoration-amber-400"
                                        @click="goToSelectedProduct('speaker', speaker.key)"
                                    >
                                        {{ speaker.productTitle }}
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('speaker.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ speaker.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div
                                v-for="camera in selectedCameras"
                                :key="camera.key"
                                class="flex items-start justify-between gap-4"
                            >
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        class="text-left font-medium text-neutral-100 underline decoration-transparent underline-offset-4 transition hover:text-amber-400 hover:decoration-amber-400 focus-visible:text-amber-400 focus-visible:decoration-amber-400"
                                        @click="goToSelectedProduct('camera', camera.key)"
                                    >
                                        {{ camera.title }}
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('camera.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ camera.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div v-if="selectedInstallation" class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        class="text-left font-medium text-neutral-100 underline decoration-transparent underline-offset-4 transition hover:text-amber-400 hover:decoration-amber-400 focus-visible:text-amber-400 focus-visible:decoration-amber-400"
                                        @click="goToSelectedProduct('installation', selectedInstallation.key)"
                                    >
                                        {{ selectedInstallation.title }}
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('installation.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ selectedInstallation.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div v-if="selectedPrecheckMethod === 'installer'" class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-neutral-100">
                                        {{ t('installation.precheck_installer_summary') }}
                                    </p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold text-white">
                                    {{ precheckPrice.toFixed(2) }} €
                                </p>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div ref="quoteTotals" class="mt-4 shrink-0 space-y-4 border-t border-neutral-800 bg-[#121212] pt-4">
                        <div class="rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div class="flex items-center justify-between text-sm text-neutral-400">
                                <span>{{ t('quote.subtotal') }}</span>
                                <span>{{ productsSubtotal.toFixed(2) }} €</span>
                            </div>
                            <div v-if="selectedInstallation" class="mt-2 flex items-center justify-between text-sm text-neutral-400">
                                <span>{{ t('installation.label') }}</span>
                                <span>{{ selectedInstallation.price.toFixed(2) }} €</span>
                            </div>
                            <div v-if="selectedPrecheckMethod === 'installer'" class="mt-2 flex items-center justify-between text-sm text-neutral-400">
                                <span>{{ t('installation.precheck_installer_summary') }}</span>
                                <span>{{ precheckPrice.toFixed(2) }} €</span>
                            </div>
                            <div
                                v-if="discountAmount > 0"
                                class="mt-2 flex items-center justify-between text-sm text-emerald-400"
                            >
                                <span>{{ t('quote.discount', { percentage: activeDiscount?.percentage ?? 0 }) }}</span>
                                <span>−{{ discountAmount.toFixed(2) }} €</span>
                            </div>
                            <div class="mt-4 flex items-center justify-between border-t border-neutral-800 pt-4">
                                <span class="text-lg font-semibold text-white">{{ t('quote.total') }}</span>
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
                            {{ t('actions.add_to_cart') }}
                        </button>

                        <button
                            type="button"
                            class="w-full rounded-xl border border-amber-400 px-5 py-4 text-base font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:border-neutral-700 disabled:text-neutral-600"
                            :disabled="!hasSelectedProducts"
                            @click="generateQuote(true)"
                        >
                            {{ t('actions.download_quote') }}
                        </button>
                    </div>
                </aside>

                <div
                    ref="mobileQuoteTotals"
                    class="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-700 bg-[#121212]/95 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-10px_30px_rgba(0,0,0,0.45)] backdrop-blur lg:hidden"
                    :class="showMobileQuoteTotals ? 'visible opacity-100' : 'pointer-events-none invisible opacity-0'"
                >
                    <div class="mx-auto max-w-md space-y-2">
                        <div class="rounded-xl border border-neutral-800 bg-neutral-900 px-4 py-3">
                            <div class="flex items-center justify-between">
                                <span class="text-lg font-semibold text-white">{{ t('quote.total') }}</span>
                                <span class="text-2xl font-semibold text-amber-400">
                                    {{ discountedTotal.toFixed(2) }} €
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="w-full rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canCheckout"
                            @click="goToCheckout"
                        >
                            {{ t('actions.add_to_cart') }}
                        </button>

                        <button
                            type="button"
                            class="w-full rounded-xl border border-amber-400 px-5 py-3 text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:border-neutral-700 disabled:text-neutral-600"
                            :disabled="!hasSelectedProducts"
                            @click="generateQuote(true)"
                        >
                            {{ t('actions.download_quote') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <p class="pb-6 text-center text-xs text-neutral-700">
            {{ t('attribution') }}
            <a href="https://www.geonames.org/" target="_blank" rel="noopener noreferrer" class="underline hover:text-neutral-500">GeoNames</a>
            (CC BY 4.0).
        </p>

        <div
            v-if="zoomedImage"
            class="fixed inset-0 z-[70] flex cursor-zoom-out items-center justify-center bg-black/90 p-4 backdrop-blur-sm sm:p-8"
            role="dialog"
            aria-modal="true"
            :aria-label="zoomedImage.alt"
            @click="closeImageZoom"
        >
            <img
                :src="zoomedImage.src"
                :alt="zoomedImage.alt"
                class="max-h-full max-w-full rounded-xl object-contain shadow-2xl"
            />
            <span class="absolute right-5 top-4 text-3xl text-white/70" aria-hidden="true">✕</span>
        </div>

        <div
            v-if="isAdmin && showQuoteModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            @click.self="showQuoteModal = false"
        >
            <section class="w-full max-w-lg rounded-2xl border border-neutral-700 bg-[#121212] p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold text-white">{{ t('actions.create_quote') }}</h2>
                        <p class="mt-1 text-sm text-neutral-400">
                            {{ t('quote_form.description') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-neutral-400 hover:bg-neutral-800 hover:text-white"
                        :aria-label="t('actions.close')"
                        @click="showQuoteModal = false"
                    >
                        ✕
                    </button>
                </div>

                <div class="mt-6 grid gap-4">
                    <label class="grid gap-2 text-sm text-neutral-300">
                        {{ t('quote_form.client') }} *
                        <input
                            v-model="quoteClientName"
                            type="text"
                            class="rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white"
                            :placeholder="t('quote_form.client_placeholder')"
                        />
                    </label>
                    <label class="grid gap-2 text-sm text-neutral-300">
                        {{ t('quote_form.phone') }}
                        <input
                            v-model="quoteClientPhone"
                            type="tel"
                            class="rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white"
                            placeholder="+34..."
                        />
                    </label>
                    <label class="grid gap-2 text-sm text-neutral-300">
                        {{ t('quote_form.email') }}
                        <input
                            v-model="quoteClientEmail"
                            type="email"
                            class="rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white"
                            placeholder="cliente@email.com"
                        />
                    </label>
                </div>
                <p v-if="quoteGenerationError" class="mt-4 text-sm text-red-400">
                    {{ quoteGenerationError }}
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-lg border border-neutral-700 px-4 py-2 text-sm text-neutral-300 hover:bg-neutral-900"
                        @click="showQuoteModal = false"
                    >
                        {{ t('actions.cancel') }}
                    </button>
                    <button
                        type="button"
                        :disabled="!quoteClientName.trim()"
                        class="rounded-lg bg-amber-400 px-4 py-2 text-sm font-semibold text-black hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="generateQuote(false)"
                    >
                        {{ t('actions.generate_quote') }}
                    </button>
                </div>
            </section>
        </div>
    </div>
    <div v-if="showMissingVehicleForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4" @click.self="showMissingVehicleForm = false">
        <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-neutral-700 bg-neutral-900 p-6 shadow-2xl">
            <div class="mb-6 flex items-start justify-between"><div><h2 class="text-2xl font-semibold text-amber-400">{{ t('vehicle.form_title') }}</h2><p class="mt-2 text-sm text-neutral-400">{{ t('vehicle.form_description') }}</p></div><button type="button" class="text-2xl text-neutral-400" @click="showMissingVehicleForm = false">×</button></div>
            <div v-if="missingVehicleSent" class="rounded-lg border border-green-500/40 bg-green-500/10 p-4 text-green-300">{{ t('vehicle.form_success') }}</div>
            <form v-else novalidate class="grid gap-4" @submit.prevent="submitMissingVehicleForm">
                <div class="grid gap-4 sm:grid-cols-2"><input v-model="missingVehicleForm.first_name" required :placeholder="t('vehicle.first_name')" class="form-input" /><input v-model="missingVehicleForm.last_name" required :placeholder="t('vehicle.last_name')" class="form-input" /></div>
                <input v-model="missingVehicleForm.email" required type="email" :placeholder="t('vehicle.email')" class="form-input" /><input v-model="missingVehicleForm.phone" required :placeholder="t('vehicle.phone')" class="form-input" /><input v-model="missingVehicleForm.province" required :placeholder="t('vehicle.province')" class="form-input" />
                <div class="grid gap-4 sm:grid-cols-2"><input v-model="missingVehicleForm.brand" required :placeholder="t('fields.brand')" class="form-input" /><input v-model="missingVehicleForm.model" required :placeholder="t('fields.model')" class="form-input" /></div>
                <input v-model="missingVehicleForm.year" required type="number" min="1900" max="2100" :placeholder="t('vehicle.year')" class="form-input" /><textarea v-model="missingVehicleForm.comment" rows="3" :placeholder="t('vehicle.comment')" class="form-input"></textarea>
                <label class="upload-photo-button" :class="{ 'upload-photo-selected': missingVehicleForm.photo }"><span>{{ missingVehicleForm.photo ? missingVehicleForm.photo.name : t('vehicle.upload_photo') }}</span><input required type="file" accept="image/*" @change="missingVehicleForm.photo = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
                <p v-if="missingVehicleError" class="text-sm text-red-400">{{ missingVehicleError }}</p><button type="submit" :disabled="missingVehicleSending" class="rounded-lg bg-amber-400 px-4 py-3 font-semibold text-black">{{ missingVehicleSending ? t('vehicle.form_sending') : t('vehicle.form_submit') }}</button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.quote-scrollbar {
    scrollbar-color: #525252 #171717;
    scrollbar-width: thin;
}
.form-input { width: 100%; border: 1px solid #404040; border-radius: 0.5rem; background: #121212; padding: 0.75rem 1rem; color: white; }
.form-input::placeholder { color: #737373; }
.upload-photo-button { position: relative; display: flex; min-height: 3.5rem; cursor: pointer; align-items: center; justify-content: center; border: 1px dashed #737373; border-radius: 0.5rem; padding: 0.75rem 1rem; color: #d4d4d4; text-align: center; }
.upload-photo-button:hover, .upload-photo-selected { border-color: #fbbf24; background: rgba(251, 191, 36, 0.12); color: #fbbf24; }
.upload-photo-button input { position: absolute; inset: 0; height: 100%; width: 100%; cursor: pointer; opacity: 0; }

@media (max-width: 639px) {
    .vehicle-option-select {
        font-size: 16px;
        line-height: 1.5;
    }

    .vehicle-option-select option {
        min-height: 3rem;
        padding: 0.75rem;
        font-size: 32px;
        line-height: 1.5;
    }
}

.quote-scrollbar::-webkit-scrollbar {
    width: 8px;
}

.quote-scrollbar::-webkit-scrollbar-track {
    border-radius: 9999px;
    background: #171717;
}

.quote-scrollbar::-webkit-scrollbar-thumb {
    border: 2px solid #171717;
    border-radius: 9999px;
    background: #525252;
}

.quote-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #737373;
}
</style>
