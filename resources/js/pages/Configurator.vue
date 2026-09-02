<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

type VariantChoice = {
    id: number;
    title: string;
    color: string | null;
    sku: string | null;
    shopifyVariantId: string | null;
    price: number;
    image: string | null;
    dashboardVariant: string | null;
};

type DashboardImage = { url: string; variant: string | null };

type Variant = VariantChoice & {
    colorOptions: VariantChoice[];
};

type Vehicle = {
    id: number;
    handle: string;
    title: string;
    brand: string | null;
    model: string | null;
    yearFrom: number | null;
    yearTo: number | null;
    din: string | null;
    image: string | null;
    originalDashboardImages: DashboardImage[];
    variants: Variant[];
};

type SimpleOption = {
    key: string;
    productHandle?: string;
    productId?: number | null;
    variantId?: number | null;
    title: string;
    sourceTitle?: string;
    productTitle?: string;
    variantTitle?: string | null;
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
    variants?: VariantChoice[];
};

type InstallationZone = {
    id: number;
    name: string;
    installerAddress: string | null;
    installerPhone: string | null;
    postalRanges: Array<{ from: string; to: string }>;
    productHandles: string[];
    productPrices: Record<string, number>;
    productTitles: Record<string, string>;
};

type SpeakerOption = SimpleOption & {
    handle: string;
    productTitle: string;
    sizes: string[];
    categories: string[];
};

type CustomProduct = {
    key: string;
    productId: number;
    variantId: number | null;
    title: string;
    variantTitle: string | null;
    category: string;
    sku: string | null;
    shopifyVariantId: string | null;
    price: number;
    image: string | null;
    brand: string | null;
    model: string | null;
    yearFrom: number | null;
    yearTo: number | null;
};

type SharedConfigurationPayload = {
    mode?: 'specific' | 'universal' | null;
    din?: '1DIN' | '2DIN' | null;
    brand: string | null;
    model: string | null;
    year: number | null;
    screens: Array<{ product: string; variant: string }>;
    cameras: string[];
    speakers: string[];
    customProducts: string[];
    installation: string | null;
    postalCode: string | null;
    serviceZone: string | null;
    precheck: string | null;
};

type TranslationTree = {
    [key: string]: string | TranslationTree;
};

type VehicleImageMapping = {
    brand: string;
    model: string;
    yearFrom: number;
    yearTo: number;
    image: string;
};

const props = defineProps<{
    locale: 'es' | 'it' | 'en';
    translations: TranslationTree;
    customProducts: CustomProduct[];
    vehicles: Vehicle[];
    universalScreens: Vehicle[];
    cameraOptions: SimpleOption[];
    speakerOptions: SpeakerOption[];
    installationOptions: SimpleOption[];
    installationZones: InstallationZone[];
    vehicleImages: string[];
    vehicleImageMappings: VehicleImageMapping[];
    brandImages: string[];
    sharedConfiguration: SharedConfigurationPayload | null;
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

const displayVariantTitle = (title: string | null | undefined) => {
    if (!title) return title ?? null;

    return /^default title$/i.test(title.trim())
        ? t('screen.single_variant')
        : title;
};

const truncateStepTitle = (title: string, maximumLength = 38) =>
    title.length > maximumLength
        ? `${title.slice(0, maximumLength - 1).trimEnd()}…`
        : title;

const storefrontUrl = (path: string) => {
    const localePrefix = props.locale === 'es' ? '' : `/${props.locale}`;

    return `https://www.autoradiocanario.com${localePrefix}${path}`;
};

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));
const mobileHeaderOpen = ref(false);
const currentYear = new Date().getFullYear();
const headerCopy = computed(() => ({
    es: {
        announcement: 'CONFIGURADOR DE AUTORRADIOS',
        home: 'Home',
        contact: 'Contactos',
        about: 'Quiénes somos',
        language: 'Español',
    },
    it: {
        announcement: 'Ti diamo il benvenuto nel nostro negozio',
        home: 'Home',
        contact: 'Contattaci',
        about: 'Chi siamo',
        language: 'Italiano',
    },
    en: {
        announcement: 'Welcome to our store',
        home: 'Home',
        contact: 'Contact',
        about: 'About us',
        language: 'English',
    },
})[props.locale]);
const headerContactUrl = computed(() => `/?form=autoradio&lang=${props.locale}`);
const footerContact = computed(() => props.locale === 'it'
    ? {
        email: 'info@autoradioitaliano.it',
        phone: '+39 351 434 6911',
        whatsappUrl: 'https://wa.me/393514346911',
    }
    : {
        email: 'Info@AutoRadioCanario.com',
        phone: '+34 694 259 117',
        whatsappUrl: 'https://wa.me/34694259117',
    });
const customQuoteCopy = computed(() => ({
    es: { button: 'Presupuesto personalizado', title: 'Presupuesto personalizado', help: 'Añade todos los productos que quieras y crea el presupuesto al terminar.', search: 'Buscar por producto, variante o SKU…', selected: 'Productos añadidos', empty: 'Ningún producto encontrado', add: 'Añadir', added: 'Añadido', remove: 'Quitar', cancel: 'Cancelar', create: 'Crear presupuesto' },
    it: { button: 'Preventivo custom', title: 'Preventivo custom', help: 'Aggiungi tutti i prodotti che vuoi e crea il preventivo quando hai finito.', search: 'Cerca prodotto, variante o SKU…', selected: 'Prodotti aggiunti', empty: 'Nessun prodotto trovato', add: 'Aggiungi', added: 'Aggiunto', remove: 'Rimuovi', cancel: 'Annulla', create: 'Crea preventivo' },
    en: { button: 'Custom quote', title: 'Custom quote', help: 'Add all the products you need, then create the quote when finished.', search: 'Search by product, variant or SKU…', selected: 'Added products', empty: 'No products found', add: 'Add', added: 'Added', remove: 'Remove', cancel: 'Cancel', create: 'Create quote' },
})[props.locale]);
const adminDiscountCopy = computed(() => ({
    es: { title: 'Descuento personalizado', help: 'El código debe existir en Shopify. El valor solo se usa para calcular este presupuesto.', code: 'Código Shopify', codePlaceholder: 'Ej. CLIENTE10', type: 'Tipo de descuento', percentage: 'Porcentaje', fixed: 'Importe fijo', value: 'Valor', special: 'Descuento especial' },
    it: { title: 'Sconto personalizzato', help: 'Il codice deve essere già presente in Shopify. Il valore serve a calcolare questo preventivo.', code: 'Codice Shopify', codePlaceholder: 'Es. CLIENTE10', type: 'Tipo di sconto', percentage: 'Percentuale', fixed: 'Importo fisso', value: 'Valore', special: 'Sconto speciale' },
    en: { title: 'Custom discount', help: 'The code must already exist in Shopify. The value is used to calculate this quote.', code: 'Shopify code', codePlaceholder: 'E.g. CLIENT10', type: 'Discount type', percentage: 'Percentage', fixed: 'Fixed amount', value: 'Value', special: 'Special discount' },
})[props.locale]);
const paymentMethods = [
    { name: 'American Express', icon: 'americanexpress', color: '016FD0', fallback: 'AMEX' },
    { name: 'Apple Pay', icon: 'applepay', color: '000000', fallback: 'Apple Pay' },
    { name: 'Bancontact', icon: 'bancontact', color: '005498', fallback: 'Bancontact' },
    { name: 'Google Pay', icon: 'googlepay', color: '4285F4', fallback: 'G Pay' },
    { name: 'Klarna', icon: 'klarna', color: 'FFB3C7', fallback: 'Klarna' },
    { name: 'Maestro', icon: 'maestro', color: '009DDD', fallback: 'Maestro' },
    { name: 'Mastercard', icon: 'mastercard', color: 'EB001B', fallback: 'Mastercard' },
    { name: 'PayPal', icon: 'paypal', color: '003087', fallback: 'PayPal' },
    { name: 'Shop Pay', icon: 'shoppay', color: '5A31F4', fallback: 'Shop Pay' },
    { name: 'UnionPay', icon: 'unionpay', color: 'E21836', fallback: 'UnionPay' },
    { name: 'USDC', icon: 'usdc', color: '2775CA', fallback: 'USDC' },
    { name: 'Visa', icon: 'visa', color: '1A1F71', fallback: 'VISA' },
];

const changeHeaderLanguage = (event: Event) => {
    const locale = (event.target as HTMLSelectElement).value;
    const url = new URL(window.location.href);
    url.searchParams.set('lang', locale);
    window.location.href = url.toString();
};

const vehicleFieldValues = (value: string | null | undefined): string[] => {
    if (!value) {
        return [];
    }

    return [...new Set(
        value
            .split('|')
            .map((part) => part.trim())
            .filter(Boolean),
    )];
};

const vehicleBrands = vehicleFieldValues;

const indexedVehicleModel = (value: string): { brandIndex: number | null; model: string } => {
    const match = value.match(/^\s*(\d+)\s*[:：]\s*(.+?)\s*$/u);

    return match
        ? { brandIndex: Number(match[1]) - 1, model: match[2].trim() }
        : { brandIndex: null, model: value.replace(/^\s*\d+\s*[:：]\s*/u, '').trim() };
};

const vehicleBrandModelEntries = (
    brandList: string | null | undefined,
    modelList: string | null | undefined,
): Array<{ brand: string; model: string }> => {
    const availableBrands = vehicleBrands(brandList);

    return vehicleFieldValues(modelList).flatMap((modelValue) => {
        const { brandIndex, model } = indexedVehicleModel(modelValue);
        if (brandIndex !== null) {
            const brand = availableBrands[brandIndex];

            return brand && model ? [{ brand, model }] : [];
        }

        return model ? availableBrands.map((brand) => ({ brand, model })) : [];
    });
};

const supportsVehicleCombination = (
    brandList: string | null | undefined,
    modelList: string | null | undefined,
    selectedBrand: string | null,
    selectedModel: string | null,
) => selectedBrand === null || selectedModel === null || vehicleBrandModelEntries(brandList, modelList)
    .some((entry) => entry.brand === selectedBrand && entry.model === selectedModel);

const supportsVehicleBrand = (
    brandList: string | null | undefined,
    selectedBrand: string | null,
) => selectedBrand === null || vehicleBrands(brandList).includes(selectedBrand);

const compatibilityEntries = computed(() => [
    ...props.vehicles.flatMap((vehicle) =>
        vehicleBrandModelEntries(vehicle.brand, vehicle.model).map(({ brand, model }) => ({
            brand,
            model,
            yearFrom: vehicle.yearFrom,
            yearTo: vehicle.yearTo,
        })),
    ),
    ...props.cameraOptions
        .filter((camera) => !camera.isStandard)
        .flatMap((camera) =>
            vehicleBrandModelEntries(camera.brand, camera.model).map(({ brand, model }) => ({
                brand,
                model,
                yearFrom: camera.yearFrom ?? null,
                yearTo: camera.yearTo ?? null,
            })),
        ),
]);

const brands = computed(() => [
    ...new Set(compatibilityEntries.value.map((entry) => entry.brand).filter(Boolean)),
].sort((first, second) => first.localeCompare(second, props.locale, { sensitivity: 'base' })));

const resolveAvailableBrand = (value: string | null | undefined): string | null => {
    const normalized = value?.trim().toLocaleLowerCase();
    if (!normalized) return null;

    return brands.value.find((brand) => brand.toLocaleLowerCase() === normalized) ?? null;
};

const selectedBrand = ref<string | null>(null);
const selectedModel = ref<string | null>(null);
const selectedYear = ref<number | null>(null);
type ConfiguratorMode = 'specific' | 'universal';
const configuratorMode = ref<ConfiguratorMode | null>(null);
type UniversalDin = '1DIN' | '2DIN';
const universalDinOptions: UniversalDin[] = ['1DIN', '2DIN'];
const selectedUniversalDin = ref<UniversalDin | null>(null);
const isSpecificMode = computed(() => configuratorMode.value === 'specific');
const isUniversalMode = computed(() => configuratorMode.value === 'universal');
const displayVehicleModel = (model: string | null | undefined) => {
    if (!model) return '';

    if (props.locale === 'it') {
        return model.replace(/\bClase\s+([A-Z])\b/giu, 'Classe $1');
    }

    if (props.locale === 'en') {
        return model
            .replace(/\bClase\s+([A-Z])\b/giu, '$1-Class')
            .replace(/\bSerie\s+(\d+(?:\s*\/\s*\d+)*)\b/giu, '$1 Series');
    }

    return model;
};
const vehicleStepTitle = computed(() => {
    if (selectedBrand.value === null) {
        return t('steps.vehicle');
    }

    const normalizedBrand = selectedBrand.value
        .split(/\s+/)
        .map((word) => {
            if (/^[A-Z0-9-]+$/.test(word) && word.length <= 3) return word;

            return word.charAt(0).toLocaleUpperCase(props.locale)
                + word.slice(1).toLocaleLowerCase(props.locale);
        })
        .join(' ');

    return [normalizedBrand, displayVehicleModel(selectedModel.value), selectedYear.value]
        .filter((value) => value !== null && value !== '')
        .join(' ');
});
const screenStepButton = ref<HTMLButtonElement | null>(null);
const modelSelectionSection = ref<HTMLElement | null>(null);

const handleVehicleYearChange = async (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    handleMissingVehicleOption('year', event);
    if (value === missingYearOption || value === '') return;

    await nextTick();
    window.requestAnimationFrame(() => {
        modelSelectionSection.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
};

const selectVehicleModel = async (model: string) => {
    if (selectedModel.value === model) {
        selectedModel.value = null;
        selectedScreenVariantIds.value = [];
        selectedCameraKeys.value = [];
        selectedCameraVariantIds.value = {};
        selectedSpeakerCategory.value = '';
        selectedSpeakerSizeByCategory.value = {};
        selectedSpeakerKeys.value = [];
        selectedCustomProductKeys.value = [];
        selectedInstallationKey.value = null;
        selectedServiceZone.value = null;
        selectedPrecheckMethod.value = null;
        installationRequested.value = false;
        postalCode.value = '';
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = null;
        checkoutConsentAccepted.value = false;
        openSteps.value = ['vehicle'];
        await nextTick();
        window.requestAnimationFrame(() => {
            modelSelectionSection.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        return;
    }

    selectedModel.value = model;
    if (!openSteps.value.includes('screen')) {
        openSteps.value = [...openSteps.value, 'screen'];
    }
    await nextTick();

    window.requestAnimationFrame(() => {
        const firstScreen = displayedScreenVehicles.value[0];
        const target = firstScreen
            ? document.getElementById(`screen-product-${firstScreen.id}`)
            : document.getElementById('screen-alternative-message');

        target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
};
const customerBudget = ref('');
const selectedScreenVariantIds = ref<number[]>([]);
const selectedCameraKeys = ref<string[]>([]);
const selectedCameraVariantIds = ref<Record<string, number>>({});
const selectedSpeakerCategory = ref<string>('');
const selectedSpeakerSizeByCategory = ref<Record<string, string>>({});
const selectedSpeakerKeys = ref<string[]>([]);
const selectedInstallationKey = ref<string | null>(null);
const showCustomQuoteModal = ref(false);
const customProductSearch = ref('');
const selectedCustomProductKeys = ref<string[]>([]);
const selectedCustomProducts = computed(() =>
    (props.customProducts ?? []).filter((product) => selectedCustomProductKeys.value.includes(product.key)),
);
const filteredCustomProducts = computed(() => {
    const search = customProductSearch.value.trim().toLocaleLowerCase();

    if (!search) return props.customProducts ?? [];

    return (props.customProducts ?? []).filter((product) =>
        [product.title, product.variantTitle, product.sku, product.category]
            .filter(Boolean)
            .some((value) => String(value).toLocaleLowerCase().includes(search)),
    );
});
const toggleCustomProduct = (key: string) => {
    selectedCustomProductKeys.value = selectedCustomProductKeys.value.includes(key)
        ? selectedCustomProductKeys.value.filter((selectedKey) => selectedKey !== key)
        : [...selectedCustomProductKeys.value, key];
};
const startCustomQuote = () => {
    selectedBrand.value = null;
    selectedModel.value = null;
    selectedYear.value = null;
    selectedScreenVariantIds.value = [];
    selectedCameraKeys.value = [];
    selectedSpeakerKeys.value = [];
    selectedInstallationKey.value = null;
    selectedPrecheckMethod.value = null;
    showCustomQuoteModal.value = true;
};
const completeCustomQuote = () => {
    showCustomQuoteModal.value = false;
    if (selectedCustomProductKeys.value.length > 0) {
        quoteGenerationError.value = null;
        showQuoteModal.value = true;
    }
};
const installationRequested = ref(false);
let brandTypeaheadBuffer = '';
let brandTypeaheadTimer: ReturnType<typeof setTimeout> | null = null;
const handleBrandTypeahead = (event: KeyboardEvent) => {
    if (event.key.length !== 1 || !/[\p{L}\d]/u.test(event.key)) return;
    brandTypeaheadBuffer += event.key.toLocaleLowerCase();
    if (brandTypeaheadTimer) clearTimeout(brandTypeaheadTimer);
    brandTypeaheadTimer = setTimeout(() => { brandTypeaheadBuffer = ''; }, 700);
    const match = brands.value.find((brand) => brand.toLocaleLowerCase().startsWith(brandTypeaheadBuffer));
    if (match) {
        selectedBrand.value = match;
        handleVehicleBrandChange();
    }
};
const openSteps = ref<string[]>([]);
const modeSensitiveSelectionCount = computed(() => {
    const specificCameraCount = selectedCameraKeys.value.filter((key) =>
        !props.cameraOptions.find((option) => option.key === key)?.isStandard,
    ).length;

    return selectedScreenVariantIds.value.length + specificCameraCount;
});
const setConfiguratorMode = async (mode: ConfiguratorMode) => {
    if (configuratorMode.value === mode) return;

    if (
        configuratorMode.value !== null
        && modeSensitiveSelectionCount.value > 0
        && !window.confirm(t('mode.change_confirmation'))
    ) {
        return;
    }

    selectedScreenVariantIds.value = [];
    selectedDashboardVariants.value = {};
    selectedCameraKeys.value = selectedCameraKeys.value.filter((key) =>
        props.cameraOptions.find((option) => option.key === key)?.isStandard,
    );
    selectedInstallationKey.value = null;
    selectedPrecheckMethod.value = null;
    installationRequested.value = false;
    checkoutConsentAccepted.value = false;
    selectedUniversalDin.value = null;
    variantPickerVehicle.value = null;
    showCart.value = false;
    configuratorMode.value = mode;
    openSteps.value = mode === 'specific' ? ['vehicle'] : ['screen'];

    await nextTick();
    window.requestAnimationFrame(() => {
        document.getElementById(mode === 'specific' ? 'vehicle-brand' : 'screen-step-content')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};
const selectUniversalDin = (din: UniversalDin) => {
    if (selectedUniversalDin.value === din) return;

    if (selectedScreenVariantIds.value.length > 0 && !window.confirm(t('mode.din_change_confirmation'))) {
        return;
    }

    selectedScreenVariantIds.value = [];
    selectedDashboardVariants.value = {};
    selectedInstallationKey.value = null;
    selectedPrecheckMethod.value = null;
    installationRequested.value = false;
    checkoutConsentAccepted.value = false;
    selectedUniversalDin.value = din;
};
const toggleStep = (step: string) => {
    openSteps.value = openSteps.value.includes(step)
        ? openSteps.value.filter((openStep) => openStep !== step)
        : [...openSteps.value, step];
};
const centerConfiguratorTarget = async (targetId: string, focus = false, block: ScrollLogicalPosition = 'center') => {
    await nextTick();
    window.requestAnimationFrame(() => {
        const target = document.getElementById(targetId);
        if (focus) target?.focus({ preventScroll: true });
        target?.scrollIntoView({ behavior: 'smooth', block });
    });
};
const goToFullQuote = async () => {
    await centerConfiguratorTarget('full-quote-summary');
};
const goToPrecheckStep = async () => {
    installationRequested.value = true;
    if (!openSteps.value.includes('installation')) {
        openSteps.value = [...openSteps.value, 'installation'];
    }
    await centerConfiguratorTarget('installation-precheck-step');
};
const toggleStepAndCenter = async (step: string, targetId: string, focus = false, block: ScrollLogicalPosition = 'center') => {
    const isOpening = !openSteps.value.includes(step);
    toggleStep(step);
    if (isOpening) await centerConfiguratorTarget(targetId, focus, block);
};
const stepHasSelections = (step: string) => {
    switch (step) {
        case 'vehicle':
            return selectedBrand.value !== null
                || selectedYear.value !== null
                || selectedModel.value !== null;
        case 'screen':
            return selectedScreenVariantIds.value.length > 0;
        case 'camera':
            return selectedCameraKeys.value.length > 0;
        case 'speaker':
            return selectedSpeakerKeys.value.length > 0;
        case 'installation':
            return selectedInstallationKey.value !== null;
        default:
            return false;
    }
};
const mainStepButtonClass = (step: string) => [
    'relative mx-auto block w-64 max-w-full overflow-visible rounded-lg border-2 py-4 pl-5 pr-16 text-base font-semibold uppercase tracking-wide transition',
    stepHasSelections(step)
        ? 'border-black bg-amber-400 text-black ring-2 ring-amber-400 hover:bg-amber-300'
        : 'border-amber-400 bg-transparent text-amber-400 hover:border-black hover:bg-amber-400 hover:text-black hover:ring-2 hover:ring-amber-400',
];
const stepContextLabel = (step: 'vehicle' | 'screen' | 'camera' | 'speaker' | 'installation') => {
    const labels = {
        es: { vehicle: 'Coche', screen: 'Pantalla', camera: 'Cámara', speaker: 'Altavoz', installation: 'Instalación' },
        it: { vehicle: 'Auto', screen: 'Schermo', camera: 'Camera', speaker: 'Altoparlante', installation: 'Installazione' },
        en: { vehicle: 'Car', screen: 'Screen', camera: 'Camera', speaker: 'Speaker', installation: 'Installation' },
    };

    return labels[props.locale]?.[step] ?? labels.es[step];
};
const showMissingVehicleForm = ref(false);
const missingVehicleSending = ref(false);
const missingVehicleSent = ref(false);
const missingVehicleError = ref('');
const quoteTotals = ref<HTMLElement | null>(null);
const checkoutConsentSection = ref<HTMLElement | null>(null);
const cartCheckoutConsentSection = ref<HTMLElement | null>(null);
const mobileQuoteTotals = ref<HTMLElement | null>(null);
const quotePanel = ref<HTMLElement | null>(null);
const quoteSummaryScroll = ref<HTMLElement | null>(null);
const showMobileQuoteTotals = ref(false);
let quoteTotalsObserver: IntersectionObserver | null = null;
const updateMobileQuoteTotals = () => {
    if (!hasSelectedProducts.value || !checkoutConsentSection.value || !mobileQuoteTotals.value || window.innerWidth >= 1024) {
        showMobileQuoteTotals.value = false;
        return;
    }

    const viewportHeight = window.visualViewport?.height ?? document.documentElement.clientHeight;
    const consentRect = checkoutConsentSection.value.getBoundingClientRect();

    // Keep the compact total visible through the quote and hide it when the
    // service-conditions consent reaches the viewport. Keep it hidden below it.
    showMobileQuoteTotals.value = consentRect.top >= viewportHeight;
};
const observeQuoteTotals = async () => {
    await nextTick();
    quoteTotalsObserver?.disconnect();
    quoteTotalsObserver = null;

    if (!checkoutConsentSection.value || typeof IntersectionObserver === 'undefined') {
        updateMobileQuoteTotals();
        return;
    }

    quoteTotalsObserver = new IntersectionObserver(
        () => updateMobileQuoteTotals(),
        { root: null, threshold: [0, 0.01] },
    );
    quoteTotalsObserver.observe(checkoutConsentSection.value);
    updateMobileQuoteTotals();
};
const missingVehicleForm = ref({ first_name: '', last_name: '', email: '', phone: '', province: '', brand: '', model: '', year: '', comment: '', photo: null as File | null });
type MissingVehicleField = 'first_name' | 'last_name' | 'email' | 'phone' | 'province' | 'brand' | 'model' | 'year' | 'comment' | 'photo';
const missingVehicleFieldErrors = ref<Partial<Record<MissingVehicleField, boolean>>>({});
const missingVehicleFields: MissingVehicleField[] = ['first_name', 'last_name', 'email', 'phone', 'province', 'brand', 'model', 'year', 'comment', 'photo'];
const missingVehiclePhotoMaxBytes = 2 * 1024 * 1024;

const clearMissingVehicleFieldError = (field: MissingVehicleField) => {
    delete missingVehicleFieldErrors.value[field];
};

const selectMissingVehiclePhoto = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const photo = input.files?.[0] ?? null;
    clearMissingVehicleFieldError('photo');
    missingVehicleError.value = '';

    if (photo && photo.size > missingVehiclePhotoMaxBytes) {
        missingVehicleForm.value.photo = null;
        missingVehicleFieldErrors.value.photo = true;
        missingVehicleError.value = 'La foto supera el límite máximo de 2 MB.';
        input.value = '';
        return;
    }

    missingVehicleForm.value.photo = photo;
};

const openMissingVehicleForm = () => {
    missingVehicleSent.value = false;
    missingVehicleError.value = '';
    missingVehicleFieldErrors.value = {};
    showMissingVehicleForm.value = true;
};

const missingBrandOption = '__missing_brand__';
const missingYearOption = '__missing_year__';

const handleMissingVehicleOption = (field: 'brand' | 'year', event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    if (value !== (field === 'brand' ? missingBrandOption : missingYearOption)) return;

    if (field === 'brand') {
        selectedBrand.value = null;
    } else {
        selectedYear.value = null;
        missingVehicleForm.value.brand = selectedBrand.value ?? '';
    }

    openMissingVehicleForm();
};

const handleVehicleBrandChange = async (event?: Event) => {
    const value = event ? (event.target as HTMLSelectElement).value : (selectedBrand.value ?? '');
    if (event) handleMissingVehicleOption('brand', event);
    if (value === missingBrandOption || value === '') return;

    await centerConfiguratorTarget('vehicle-year', true);
};

const openMissingModelForm = () => {
    missingVehicleForm.value.brand = selectedBrand.value ?? '';
    missingVehicleForm.value.year = selectedYear.value === null ? '' : String(selectedYear.value);
    missingVehicleForm.value.model = '';
    openMissingVehicleForm();
};

const openMissingScreenForm = () => {
    missingVehicleForm.value.brand = selectedBrand.value ?? '';
    missingVehicleForm.value.model = selectedModel.value ?? '';
    missingVehicleForm.value.year = selectedYear.value === null ? '' : String(selectedYear.value);
    openMissingVehicleForm();
};

const submitMissingVehicleForm = async () => {
    const requiredFields = ['first_name', 'last_name', 'email', 'phone', 'province', 'brand', 'model', 'year'] as const;
    const fieldErrors: Partial<Record<MissingVehicleField, boolean>> = {};
    requiredFields.forEach((field) => {
        if (!String(missingVehicleForm.value[field]).trim()) fieldErrors[field] = true;
    });
    if (missingVehicleForm.value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(missingVehicleForm.value.email)) {
        fieldErrors.email = true;
    }
    const year = Number(missingVehicleForm.value.year);
    if (missingVehicleForm.value.year && (!Number.isInteger(year) || year < 1900 || year > 2100)) {
        fieldErrors.year = true;
    }
    const maximumLengths: Partial<Record<MissingVehicleField, number>> = {
        first_name: 100,
        last_name: 100,
        email: 255,
        phone: 50,
        province: 100,
        brand: 100,
        model: 255,
        comment: 5000,
    };
    Object.entries(maximumLengths).forEach(([field, maximum]) => {
        if (String(missingVehicleForm.value[field as keyof typeof missingVehicleForm.value] ?? '').length > maximum) {
            fieldErrors[field as MissingVehicleField] = true;
        }
    });
    if (missingVehicleForm.value.photo && missingVehicleForm.value.photo.size > missingVehiclePhotoMaxBytes) {
        fieldErrors.photo = true;
    }
    missingVehicleFieldErrors.value = fieldErrors;
    if (Object.keys(fieldErrors).length > 0) {
        missingVehicleError.value = fieldErrors.photo
            ? 'La foto supera el límite máximo de 2 MB.'
            : 'Revisa los campos marcados en rojo: uno o más valores no son válidos o son demasiado largos.';
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
            if (errorBody.errors && typeof errorBody.errors === 'object') {
                const serverFieldErrors: Partial<Record<MissingVehicleField, boolean>> = {};
                missingVehicleFields.forEach((field) => {
                    if (field in errorBody.errors) serverFieldErrors[field] = true;
                });
                if (Object.keys(serverFieldErrors).length > 0) {
                    missingVehicleFieldErrors.value = serverFieldErrors;
                    const firstError = Object.values(errorBody.errors).flat().find((message) => typeof message === 'string');
                    missingVehicleError.value = typeof firstError === 'string'
                        ? firstError
                        : 'Revisa los campos marcados en rojo.';
                    return;
                }
            }
            throw new Error(errorBody.message || `HTTP ${response.status}`);
        }
        missingVehicleSent.value = true;
    } catch (error) {
        missingVehicleError.value = error instanceof Error && error.message !== 'send' ? error.message : t('vehicle.form_error');
    } finally {
        missingVehicleSending.value = false;
    }
};
const selectedPrecheckMethod = ref<'self' | 'installer' | null>(null);
type ServiceZoneKey = 'north' | 'capital' | 'south' | 'tenerife' | 'fuerteventura' | 'lanzarote' | 'madrid' | 'barcelona' | 'malaga' | 'murcia' | 'configured';
const serviceZoneKeys: ServiceZoneKey[] = ['north', 'capital', 'south', 'tenerife', 'fuerteventura', 'lanzarote', 'madrid', 'barcelona', 'malaga', 'murcia', 'configured'];
const selectedServiceZone = ref<ServiceZoneKey | null>(null);
const postalCode = ref('');
const checkedPostalCode = ref<string | null>(null);
const postalCodeError = ref<string | null>(null);
const resolvedInstallationArea = ref<{
    name: string;
    installerAddress?: string | null;
    installerPhone?: string | null;
    productHandles: string[];
    productPrices?: Record<string, number>;
    productTitles?: Record<string, string>;
} | null>(null);

const models = computed(() => {
    if (selectedBrand.value === null || selectedYear.value === null) {
        return [];
    }

    const brand = selectedBrand.value;
    const year = selectedYear.value;

    return [
        ...new Set(
            compatibilityEntries.value
                .filter((entry) => {
                    if (entry.brand !== brand) {
                        return false;
                    }

                    return (
                        entry.yearFrom !== null &&
                        entry.yearTo !== null &&
                        year >= entry.yearFrom &&
                        year <= entry.yearTo
                    );
                })
                .map((entry) => indexedVehicleModel(entry.model).model)
                .filter(Boolean),
        ),
    ].sort((first, second) => first.localeCompare(second, props.locale, {
        sensitivity: 'base',
        numeric: true,
    }));
});

const brandVehicles = computed(() =>
    props.vehicles.filter((vehicle) =>
        supportsVehicleBrand(vehicle.brand, selectedBrand.value),
    ),
);

const matchingVehicles = computed(() =>
    brandVehicles.value.filter(
        (vehicle) => supportsVehicleCombination(
            vehicle.brand,
            vehicle.model,
            selectedBrand.value,
            selectedModel.value,
        ),
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
    const isOpening = !openSteps.value.includes('screen');
    toggleStep('screen');

    if (isOpening) {
        await centerConfiguratorTarget(
            displayedScreenOptionCount.value > 0
                ? 'screen-availability-message'
                : 'screen-alternative-message',
        );
    }
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
        selectableScreenVehicles.value.find((vehicle) =>
            vehicle.variants.some(
                (variant) => screenVariantChoices(variant).some((choice) =>
                    selectedScreenVariantIds.value.includes(choice.id),
                ),
            ),
        ) ?? null,
);

const selectedScreens = computed(() =>
    selectableScreenVehicles.value.flatMap((vehicle) =>
        vehicle.variants.flatMap((variant) =>
            screenVariantChoices(variant).filter((choice) =>
                selectedScreenVariantIds.value.includes(choice.id),
            ),
        ),
    ),
);
const selectedScreenVariantTitles = computed(() => [
    ...new Set(
        selectedScreens.value.map((screen) =>
            [displayVariantTitle(screen.title), screen.color].filter(Boolean).join(' / '),
        ),
    ),
]);

const variantPickerVehicle = ref<Vehicle | null>(null);
const showCart = ref(false);
const cartQuantities = ref<Record<string, number>>({});
const funnelCopy = computed(() => ({
    es: { choose: 'Elige las variantes', add: 'Añadir al carrito', remove: 'Eliminar del carrito', selected: 'Variantes elegidas', change: 'Cambiar variantes', cart: 'Ver carrito', cartTitle: 'Tu carrito', empty: 'Tu carrito está vacío', quantity: 'Cantidad', back: 'Volver al configurador' },
    it: { choose: 'Scegli le varianti', add: 'Aggiungi al carrello', remove: 'Elimina dal carrello', selected: 'Varianti scelte', change: 'Cambia varianti', cart: 'Vedi carrello', cartTitle: 'Il tuo carrello', empty: 'Il carrello è vuoto', quantity: 'Quantità', back: 'Torna al configuratore' },
    en: { choose: 'Choose variants', add: 'Add to cart', remove: 'Remove from cart', selected: 'Selected variants', change: 'Change variants', cart: 'View cart', cartTitle: 'Your cart', empty: 'Your cart is empty', quantity: 'Quantity', back: 'Back to configurator' },
})[props.locale]);

const cartQuantity = (key: string) => cartQuantities.value[key] ?? 1;
const setCartQuantity = (key: string, quantity: number) => {
    cartQuantities.value = { ...cartQuantities.value, [key]: Math.max(1, quantity) };
};
const setCartQuantityFromInput = (key: string, event: Event) => {
    const input = event.target as HTMLInputElement;
    const quantity = Number.parseInt(input.value, 10);
    setCartQuantity(key, Number.isFinite(quantity) ? quantity : 1);
    input.value = String(cartQuantity(key));
};
const openVariantPicker = (vehicle: Vehicle) => { variantPickerVehicle.value = vehicle; };
const closeVariantPicker = () => { variantPickerVehicle.value = null; };
const returnToConfigurator = async () => {
    variantPickerVehicle.value = null;
    showCart.value = false;
    openSteps.value = [];
    await nextTick();
    window.requestAnimationFrame(() => {
        document.getElementById(isUniversalMode.value ? 'screen-step-content' : 'mobile-vehicle-marker')
            ?.scrollIntoView({ behavior: 'auto', block: 'start' });
    });
};
const openCartFromVariantPicker = () => {
    closeVariantPicker();
    showCart.value = true;
};
const handleCartHistoryBack = () => {
    if (showCart.value) {
        showCart.value = false;
        variantPickerVehicle.value = null;
    }
};
const handlePrintPreviewNavigation = (event: MessageEvent) => {
    if (event.data?.type === 'autoradio:return-to-configurator') void returnToConfigurator();
};
watch(showCart, (isOpen, wasOpen) => {
    if (isOpen && !wasOpen) window.history.pushState({ autoradioCart: true }, '', window.location.href);
});
const isScreenChoiceSelected = (choice: VariantChoice) => selectedScreenVariantIds.value.includes(choice.id);
const toggleScreenChoiceInCart = (choice: VariantChoice) => {
    if (isScreenChoiceSelected(choice)) {
        removeSelectedScreen(choice.id);
        return;
    }

    selectedScreenVariantIds.value = [...selectedScreenVariantIds.value, choice.id];
    setCartQuantity(`screen:${choice.id}`, 1);
};
const selectedScreensForVehicle = (vehicle: Vehicle) =>
    vehicle.variants.flatMap(screenVariantChoices).filter((choice) => selectedScreenVariantIds.value.includes(choice.id));
const screenChoicesForVehicle = (vehicle: Vehicle) =>
    visibleScreenVariants(vehicle).flatMap(screenVariantChoices);
const singleScreenChoice = (vehicle: Vehicle) => {
    const choices = screenChoicesForVehicle(vehicle);
    return choices.length === 1 ? choices[0] : null;
};

const screenVariantChoices = (variant: Variant): VariantChoice[] =>
    variant.colorOptions.length > 0 ? variant.colorOptions : [variant];

const selectedScreenChoice = (variant: Variant): VariantChoice =>
    screenVariantChoices(variant).find((choice) => selectedScreenVariantIds.value.includes(choice.id))
        ?? screenVariantChoices(variant).find((choice) => !isScreenChoiceOverBudget(choice))
        ?? screenVariantChoices(variant)[0];

const normalizedBudget = computed(() => {
    const value = Number.parseFloat(customerBudget.value.replace(',', '.'));

    return Number.isFinite(value) && value > 0 ? value : null;
});

const isScreenChoiceOverBudget = (choice: VariantChoice) =>
    normalizedBudget.value !== null && choice.price > normalizedBudget.value;

const isScreenVariantOverBudget = (variant: Variant) =>
    screenVariantChoices(variant).every(isScreenChoiceOverBudget);

const touchedOverBudgetVariantId = ref<number | null>(null);
let touchedOverBudgetTimer: number | null = null;

const showOverBudgetMessageOnTouch = (variant: Variant, event: PointerEvent) => {
    if (event.pointerType !== 'touch' || !isScreenVariantOverBudget(variant)) return;

    touchedOverBudgetVariantId.value = variant.id;
    if (touchedOverBudgetTimer !== null) window.clearTimeout(touchedOverBudgetTimer);
    touchedOverBudgetTimer = window.setTimeout(() => {
        touchedOverBudgetVariantId.value = null;
        touchedOverBudgetTimer = null;
    }, 2200);
};

onBeforeUnmount(() => {
    if (touchedOverBudgetTimer !== null) window.clearTimeout(touchedOverBudgetTimer);
});

const isPreferredScreenVariant = (variant: Variant) => {
    const title = variant.title.toLocaleLowerCase();

    return /8\s*core/i.test(title)
        && /(?:^|[^0-9])4\s*g(?:[^0-9]|$)/i.test(title)
        && /(?:^|[^0-9])64\s*g(?:[^0-9]|$)/i.test(title);
};

const isRecommendedScreenVariant = (screen: Vehicle, variant: Variant) => {
    const preferredVariants = screen.variants.filter(isPreferredScreenVariant);
    if (preferredVariants.length > 0) {
        return isPreferredScreenVariant(variant);
    }

    const lowestEightCoreVariant = screen.variants
        .filter((candidate) => /8\s*core/i.test(candidate.title))
        .map((candidate) => ({
            variant: candidate,
            price: Math.min(...screenVariantChoices(candidate).map((choice) => choice.price)),
        }))
        .sort((first, second) => first.price - second.price || first.variant.id - second.variant.id)[0]?.variant;

    return lowestEightCoreVariant?.id === variant.id;
};

const screenHasAffordableVariant = (screen: Vehicle) =>
    screen.variants.some((variant) => !isScreenVariantOverBudget(variant));

const affordableSpecificScreens = computed(() =>
    compatibleVehicles.value.filter(screenHasAffordableVariant),
);

const affordableUniversalScreens = computed(() =>
    props.universalScreens.filter(screenHasAffordableVariant),
);

const hasSpecificScreenVariants = computed(() =>
    compatibleVehicles.value.some((screen) =>
        screen.variants.some((variant) => screenVariantChoices(variant).length > 0),
    ),
);

const hasNoSpecificScreenForBudget = computed(() =>
    isSpecificMode.value
    && normalizedBudget.value !== null
    && selectedModel.value !== null
    && hasSpecificScreenVariants.value
    && affordableSpecificScreens.value.length === 0,
);

const lowestSpecificVariantPrice = computed(() => {
    const prices = compatibleVehicles.value.flatMap((screen) =>
        screen.variants.flatMap((variant) =>
            screenVariantChoices(variant).map((choice) => choice.price),
        ),
    );

    return prices.length > 0 ? Math.min(...prices) : null;
});

const useLowestSpecificVariantBudget = () => {
    if (lowestSpecificVariantPrice.value === null) return;

    customerBudget.value = lowestSpecificVariantPrice.value.toFixed(2);
};

const displayedScreenVehicles = computed(() =>
    isUniversalMode.value
        ? selectedUniversalDin.value === null
            ? []
            : affordableUniversalScreens.value.filter((screen) => screen.din === selectedUniversalDin.value)
        : affordableSpecificScreens.value,
);

const displayedScreenOptionCount = computed(() => displayedScreenVehicles.value.length);

const allScreenVehicles = computed(() => [...props.vehicles, ...props.universalScreens]);
const selectableScreenVehicles = computed(() =>
    isUniversalMode.value ? props.universalScreens : compatibleVehicles.value,
);

const isScreenVariantSelected = (variant: Variant) =>
    screenVariantChoices(variant).some((choice) => selectedScreenVariantIds.value.includes(choice.id));

const toggleScreenVariant = (variant: Variant) => {
    if (isScreenVariantOverBudget(variant)) return;

    const choiceIds = screenVariantChoices(variant).map((choice) => choice.id);
    const withoutGroup = selectedScreenVariantIds.value.filter((selectedId) => !choiceIds.includes(selectedId));
    selectedScreenVariantIds.value = isScreenVariantSelected(variant)
        ? withoutGroup
        : [...withoutGroup, selectedScreenChoice(variant).id];
};

const removeSelectedScreen = (variantId: number) => {
    selectedScreenVariantIds.value = selectedScreenVariantIds.value.filter(
        (selectedId) => selectedId !== variantId,
    );
};

const selectScreenColor = (variant: Variant, event: Event) => {
    const variantId = Number((event.target as HTMLSelectElement).value);
    const choice = screenVariantChoices(variant).find((candidate) => candidate.id === variantId);
    if (!choice || isScreenChoiceOverBudget(choice)) return;

    const choiceIds = screenVariantChoices(variant).map((choice) => choice.id);
    selectedScreenVariantIds.value = [
        ...selectedScreenVariantIds.value.filter((selectedId) => !choiceIds.includes(selectedId)),
        variantId,
    ];
};

const vehicleForScreenVariant = (variantId: number) =>
    selectableScreenVehicles.value.find((vehicle) =>
        vehicle.variants.some((variant) =>
            screenVariantChoices(variant).some((choice) => choice.id === variantId),
        ),
    ) ?? null;

const screenImage = (vehicle: Vehicle) =>
    vehicle.image
    ?? vehicle.variants.flatMap(screenVariantChoices).find((variant) => variant.image)?.image
    ?? null;

const selectedDashboardVariants = ref<Record<number, string>>({});

const dashboardChoiceImages = (vehicle: Vehicle) =>
    vehicle.originalDashboardImages
        .filter((image) => image.variant !== null)
        .toSorted((first, second) => first.variant!.localeCompare(second.variant!, undefined, {
            numeric: true,
            sensitivity: 'base',
        }));

const requiresDashboardChoice = (vehicle: Vehicle) =>
    dashboardChoiceImages(vehicle).length >= 2;

const selectedDashboardVariant = (vehicle: Vehicle) =>
    selectedDashboardVariants.value[vehicle.id] ?? null;

const visibleScreenVariants = (vehicle: Vehicle) => {
    if (!requiresDashboardChoice(vehicle)) return vehicle.variants;

    const selected = selectedDashboardVariant(vehicle);
    return selected
        ? vehicle.variants.filter((variant) => variant.dashboardVariant === selected)
        : [];
};

const selectDashboardVariant = (vehicle: Vehicle, variant: string) => {
    const vehicleChoiceIds = vehicle.variants.flatMap((item) => screenVariantChoices(item).map((choice) => choice.id));
    selectedScreenVariantIds.value = selectedScreenVariantIds.value.filter((id) => !vehicleChoiceIds.includes(id));
    selectedDashboardVariants.value = { ...selectedDashboardVariants.value, [vehicle.id]: variant };
};

const changeDashboardVariant = (vehicle: Vehicle) => {
    const vehicleChoiceIds = vehicle.variants.flatMap((item) => screenVariantChoices(item).map((choice) => choice.id));
    selectedScreenVariantIds.value = selectedScreenVariantIds.value.filter((id) => !vehicleChoiceIds.includes(id));

    const selections = { ...selectedDashboardVariants.value };
    delete selections[vehicle.id];
    selectedDashboardVariants.value = selections;
};

const dashboardReferenceImages = (vehicle: Vehicle) => {
    if (!requiresDashboardChoice(vehicle)) return vehicle.originalDashboardImages;

    const selected = selectedDashboardVariant(vehicle);
    const selectedImage = selected
        ? dashboardChoiceImages(vehicle).find((image) => image.variant === selected)
        : null;

    return selectedImage ? [selectedImage] : [];
};

const dashboardReferenceLabel = (vehicle: Vehicle) =>
    t(dashboardReferenceImages(vehicle).length === 1
        ? 'screen.original_radio_compatible_one'
        : 'screen.original_radio_compatible_many');

const dashboardVariantLabel = (variant: string) => {
    const key = 'screen.original_radio_variant';
    const translated = t(key, { variant });

    if (translated !== key) return translated;

    return {
        es: `Variante ${variant}`,
        it: `Variante ${variant}`,
        en: `Variant ${variant}`,
    }[props.locale];
};

const changeDashboardVariantLabel = () => {
    const key = 'screen.change_original_radio_variant';
    const translated = t(key);

    if (translated !== key) return translated;

    return {
        es: 'Cambiar variante',
        it: 'Cambia variante',
        en: 'Change variant',
    }[props.locale];
};

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

const CONFIGURATOR_STATE_KEY = 'autoradiocanario-configurator-state-v2';
const CONFIGURATOR_STATE_TTL = 60 * 60 * 1000;
let configuratorStateHydrated = false;

const restoreConfiguratorState = async () => {
    try {
        const rawState = window.localStorage.getItem(CONFIGURATOR_STATE_KEY);

        if (!rawState) return;

        const stored = JSON.parse(rawState);

        if (!stored?.expiresAt || stored.expiresAt <= Date.now() || !stored?.state) {
            window.localStorage.removeItem(CONFIGURATOR_STATE_KEY);
            return;
        }

        const state = stored.state;
        configuratorMode.value = state.configuratorMode === 'specific' || state.configuratorMode === 'universal'
            ? state.configuratorMode
            : null;
        selectedUniversalDin.value = state.selectedUniversalDin === '1DIN' || state.selectedUniversalDin === '2DIN'
            ? state.selectedUniversalDin
            : null;
        const brand = typeof state.selectedBrand === 'string' && brands.value.includes(state.selectedBrand)
            ? state.selectedBrand
            : null;
        const year = Number.isInteger(state.selectedYear) ? state.selectedYear : null;
        const modelIsValid = brand !== null && year !== null && typeof state.selectedModel === 'string'
            && compatibilityEntries.value.some((entry) =>
                entry.brand === brand
                && entry.model === state.selectedModel
                && entry.yearFrom !== null
                && entry.yearTo !== null
                && year >= entry.yearFrom
                && year <= entry.yearTo
            );

        selectedBrand.value = brand;
        await nextTick();
        selectedYear.value = year;
        customerBudget.value = typeof state.customerBudget === 'string' ? state.customerBudget : '';
        await nextTick();
        selectedModel.value = modelIsValid ? state.selectedModel : null;

        const availableVariantIds = new Set(
            allScreenVehicles.value.flatMap((vehicle) =>
                vehicle.variants.flatMap((variant) => screenVariantChoices(variant).map((choice) => choice.id)),
            ),
        );
        const availableCameraKeys = new Set(props.cameraOptions.map((option) => option.key));
        const availableSpeakerKeys = new Set(props.speakerOptions.map((option) => option.key));
        const availableInstallationKeys = new Set(props.installationOptions.map((option) => option.key));

        selectedScreenVariantIds.value = Array.isArray(state.selectedScreenVariantIds)
            ? state.selectedScreenVariantIds.filter((id: unknown) => typeof id === 'number' && availableVariantIds.has(id))
            : [];
        if (configuratorMode.value === null) {
            const universalVariantIds = new Set(
                props.universalScreens.flatMap((vehicle) =>
                    vehicle.variants.flatMap((variant) => screenVariantChoices(variant).map((choice) => choice.id)),
                ),
            );
            configuratorMode.value = selectedScreenVariantIds.value.some((id) => universalVariantIds.has(id))
                ? 'universal'
                : brand !== null || modelIsValid ? 'specific' : null;
        }
        if (configuratorMode.value === 'universal' && selectedUniversalDin.value === null) {
            const restoredUniversalScreen = props.universalScreens.find((screen) =>
                screen.variants.some((variant) =>
                    screenVariantChoices(variant).some((choice) => selectedScreenVariantIds.value.includes(choice.id)),
                ),
            );
            selectedUniversalDin.value = restoredUniversalScreen?.din === '1DIN' || restoredUniversalScreen?.din === '2DIN'
                ? restoredUniversalScreen.din
                : null;
        }
        const storedCameraKeys: string[] = Array.isArray(state.selectedCameraKeys)
            ? state.selectedCameraKeys.filter((key: unknown): key is string => typeof key === 'string')
            : [];
        selectedCameraKeys.value = storedCameraKeys
            .map((key) => key.replace(/--variant-\d+$/, ''))
            .filter((key) => availableCameraKeys.has(key));
        selectedCameraVariantIds.value = typeof state.selectedCameraVariantIds === 'object' && state.selectedCameraVariantIds !== null
            ? Object.entries(state.selectedCameraVariantIds).reduce<Record<string, number>>((variants, [key, id]) => {
                if (
                    availableCameraKeys.has(key)
                    && typeof id === 'number'
                    && props.cameraOptions.find((option) => option.key === key)?.variants?.some((variant) => variant.id === id)
                ) {
                    variants[key] = id;
                }

                return variants;
            }, {})
            : {};
        storedCameraKeys.forEach((key) => {
            const match = key.match(/^(.*)--variant-(\d+)$/);
            if (match && availableCameraKeys.has(match[1])) {
                selectedCameraVariantIds.value[match[1]] = Number(match[2]);
            }
        });
        selectedSpeakerKeys.value = Array.isArray(state.selectedSpeakerKeys)
            ? state.selectedSpeakerKeys.filter((key: unknown) => typeof key === 'string' && availableSpeakerKeys.has(key))
            : [];
        selectedSpeakerCategory.value = typeof state.selectedSpeakerCategory === 'string'
            ? state.selectedSpeakerCategory
            : '';
        selectedSpeakerSizeByCategory.value = state.selectedSpeakerSizeByCategory
            && typeof state.selectedSpeakerSizeByCategory === 'object'
            ? state.selectedSpeakerSizeByCategory
            : {};
        selectedInstallationKey.value =
            typeof state.selectedInstallationKey === 'string'
            && availableInstallationKeys.has(state.selectedInstallationKey)
                ? state.selectedInstallationKey
                : null;
        installationRequested.value = state.installationRequested === true;
        selectedPrecheckMethod.value = ['self', 'installer'].includes(state.selectedPrecheckMethod)
            ? state.selectedPrecheckMethod
            : null;
        selectedServiceZone.value = serviceZoneKeys.includes(state.selectedServiceZone)
            ? state.selectedServiceZone as ServiceZoneKey
            : null;
        postalCode.value = typeof state.postalCode === 'string' ? state.postalCode : '';
        checkedPostalCode.value = typeof state.checkedPostalCode === 'string'
            ? state.checkedPostalCode
            : null;
        resolvedInstallationArea.value = state.resolvedInstallationArea
            && typeof state.resolvedInstallationArea.name === 'string'
            && Array.isArray(state.resolvedInstallationArea.productHandles)
                ? state.resolvedInstallationArea
                : null;
        openSteps.value = Array.isArray(state.openSteps)
            ? state.openSteps.filter((step: unknown) =>
                typeof step === 'string'
                && ['vehicle', 'screen', 'camera', 'speaker', 'installation'].includes(step)
            )
            : [];

        await nextTick();
    } catch {
        window.localStorage.removeItem(CONFIGURATOR_STATE_KEY);
    }
};

const applySharedConfiguration = async (configuration: SharedConfigurationPayload) => {
    const { brand, model, year } = configuration;
    const resolvedBrand = resolveAvailableBrand(brand);
    const validVehicle = resolvedBrand
        && model
        && year !== null
        && Number.isInteger(year)
        && compatibilityEntries.value.some((entry) =>
            entry.brand === resolvedBrand
            && entry.model === model
            && entry.yearFrom !== null
            && entry.yearTo !== null
            && year >= entry.yearFrom
            && year <= entry.yearTo
        );
    const containsUniversalScreen = configuration.screens.some((screen) =>
        props.universalScreens.some((candidate) => candidate.handle === screen.product),
    );
    configuratorMode.value = configuration.mode === 'universal' || (!validVehicle && containsUniversalScreen)
        ? 'universal'
        : 'specific';
    const sharedUniversalScreen = configuration.screens
        .map((screen) => props.universalScreens.find((candidate) => candidate.handle === screen.product))
        .find((screen): screen is Vehicle => Boolean(screen));
    selectedUniversalDin.value = configuration.din === '1DIN' || configuration.din === '2DIN'
        ? configuration.din
        : sharedUniversalScreen?.din === '1DIN' || sharedUniversalScreen?.din === '2DIN'
            ? sharedUniversalScreen.din
            : null;

    selectedBrand.value = validVehicle ? resolvedBrand : null;
    await nextTick();
    selectedYear.value = validVehicle ? year : null;
    await nextTick();
    selectedModel.value = validVehicle ? model : null;
    await nextTick();

    const restoredDashboardVariants: Record<number, string> = {};
    selectedScreenVariantIds.value = configuration.screens.flatMap((screen) => {
        const vehicle = allScreenVehicles.value.find((candidate) => candidate.handle === screen.product);
        const token = screen.variant;
        const variant = vehicle?.variants
            .flatMap(screenVariantChoices)
            .find((candidate) =>
                String(candidate.id) === token
                || candidate.shopifyVariantId === token
                || candidate.sku === token
            );

        if (vehicle && variant?.dashboardVariant && requiresDashboardChoice(vehicle)) {
            restoredDashboardVariants[vehicle.id] = variant.dashboardVariant;
        }

        return variant ? [variant.id] : [];
    });
    selectedDashboardVariants.value = restoredDashboardVariants;

    const visibleCameraKeys = new Set(visibleCameraOptions.value.map((camera) => camera.key));
    selectedCameraKeys.value = configuration.cameras
        .map((key) => key.replace(/--variant-\d+$/, ''))
        .filter((key) => visibleCameraKeys.has(key));
    configuration.cameras.forEach((key) => {
        const match = key.match(/^(.*)--variant-(\d+)$/);
        if (match && visibleCameraKeys.has(match[1])) {
            selectedCameraVariantIds.value[match[1]] = Number(match[2]);
        }
    });
    const speakerKeys = new Set(props.speakerOptions.map((speaker) => speaker.key));
    selectedSpeakerKeys.value = configuration.speakers.filter((key) => speakerKeys.has(key));
    const customKeys = new Set(props.customProducts.map((product) => product.key));
    selectedCustomProductKeys.value = configuration.customProducts.filter((key) => customKeys.has(key));

    const sharedPostalCode = configuration.postalCode;
    if (sharedPostalCode && /^\d{5}$/.test(sharedPostalCode)) {
        postalCode.value = sharedPostalCode;
        await checkPostalCode();
    }

    const sharedServiceZone = configuration.serviceZone;
    selectedServiceZone.value = serviceZoneKeys.includes(sharedServiceZone as ServiceZoneKey)
        ? sharedServiceZone as ServiceZoneKey
        : null;
    const sharedPrecheck = configuration.precheck;
    selectedPrecheckMethod.value = ['self', 'installer'].includes(sharedPrecheck ?? '')
        ? sharedPrecheck as 'self' | 'installer'
        : null;
    installationRequested.value = Boolean(configuration.installation);
    await nextTick();

    const installationKey = configuration.installation;
    selectedInstallationKey.value = installationKey
        && visibleInstallationOptions.value.some((option) => option.key === installationKey)
        ? installationKey
        : null;
    openSteps.value = selectedScreenVariantIds.value.length > 0 ? ['screen'] : [];
    await nextTick();

};

const restoreSharedConfiguration = async () => {
    if (props.sharedConfiguration) {
        await applySharedConfiguration(props.sharedConfiguration);
        return true;
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('summary') !== '1') return false;

    const productHandles = params.getAll('product');
    const variantTokens = params.getAll('variant');
    await applySharedConfiguration({
        mode: params.get('mode') === 'universal'
            ? 'universal'
            : params.get('mode') === 'specific' ? 'specific' : null,
        din: params.get('din') === '1DIN' || params.get('din') === '2DIN'
            ? params.get('din') as UniversalDin
            : null,
        brand: params.get('marca') ?? params.get('brand'),
        model: params.get('model'),
        year: /^\d{4}$/.test(params.get('year') ?? '') ? Number(params.get('year')) : null,
        screens: productHandles.map((product, index) => ({
            product,
            variant: variantTokens[index] ?? '',
        })),
        cameras: params.getAll('camera'),
        speakers: params.getAll('speaker'),
        customProducts: params.getAll('custom'),
        installation: params.get('installation'),
        postalCode: params.get('postal_code'),
        serviceZone: params.get('service_zone'),
        precheck: params.get('precheck'),
    });

    return true;
};

const persistConfiguratorState = () => {
    if (!configuratorStateHydrated) return;

    try {
        window.localStorage.setItem(CONFIGURATOR_STATE_KEY, JSON.stringify({
            expiresAt: Date.now() + CONFIGURATOR_STATE_TTL,
            state: {
                configuratorMode: configuratorMode.value,
                selectedUniversalDin: selectedUniversalDin.value,
                selectedBrand: selectedBrand.value,
                selectedModel: selectedModel.value,
                selectedYear: selectedYear.value,
                customerBudget: customerBudget.value,
                selectedScreenVariantIds: selectedScreenVariantIds.value,
                selectedCameraKeys: selectedCameraKeys.value,
                selectedCameraVariantIds: selectedCameraVariantIds.value,
                selectedSpeakerCategory: selectedSpeakerCategory.value,
                selectedSpeakerSizeByCategory: selectedSpeakerSizeByCategory.value,
                selectedSpeakerKeys: selectedSpeakerKeys.value,
                selectedInstallationKey: selectedInstallationKey.value,
                installationRequested: installationRequested.value,
                selectedPrecheckMethod: selectedPrecheckMethod.value,
                selectedServiceZone: selectedServiceZone.value,
                postalCode: postalCode.value,
                checkedPostalCode: checkedPostalCode.value,
                resolvedInstallationArea: resolvedInstallationArea.value,
                openSteps: openSteps.value,
            },
        }));
    } catch {
        // Browsers may disable localStorage; the configurator must remain usable.
    }
};

watch(
    [
        selectedBrand,
        configuratorMode,
        selectedUniversalDin,
        selectedModel,
        selectedYear,
        customerBudget,
        selectedScreenVariantIds,
        selectedCameraKeys,
        selectedCameraVariantIds,
        selectedSpeakerCategory,
        selectedSpeakerSizeByCategory,
        selectedSpeakerKeys,
        selectedInstallationKey,
        installationRequested,
        selectedPrecheckMethod,
        selectedServiceZone,
        postalCode,
        checkedPostalCode,
        resolvedInstallationArea,
        openSteps,
    ],
    persistConfiguratorState,
    { deep: true },
);

onMounted(async () => {
    window.addEventListener('popstate', handleCartHistoryBack);
    window.addEventListener('message', handlePrintPreviewNavigation);
    document.documentElement.lang = props.locale;
    void trackVisitorEntry();
    const params = new URLSearchParams(window.location.search);
    const incomingBrand = resolveAvailableBrand(params.get('marca') ?? params.get('brand'));
    if (params.get('form') === 'autoradio') {
        openMissingVehicleForm();
    }
    const sharedConfigurationRestored = await restoreSharedConfiguration();
    if (!sharedConfigurationRestored) {
        await restoreConfiguratorState();

        if (incomingBrand) {
            configuratorMode.value = 'specific';
            selectedYear.value = null;
            selectedModel.value = null;
            selectedScreenVariantIds.value = [];
            selectedCameraKeys.value = [];
            selectedSpeakerCategory.value = '';
            selectedSpeakerSizeByCategory.value = {};
            selectedSpeakerKeys.value = [];
            selectedInstallationKey.value = null;
            selectedBrand.value = incomingBrand;
            openSteps.value = ['vehicle'];
            await nextTick();
        }
    }
    configuratorStateHydrated = true;
    window.addEventListener('keydown', closeImageZoomOnEscape);
    window.addEventListener('scroll', updateMobileQuoteTotals, { passive: true });
    window.addEventListener('resize', updateMobileQuoteTotals);
    window.visualViewport?.addEventListener('resize', updateMobileQuoteTotals);
    requestAnimationFrame(async () => {
        await observeQuoteTotals();
        updateMobileQuoteTotals();

        if (sharedConfigurationRestored) {
            await nextTick();
            await new Promise<void>((resolve) => window.requestAnimationFrame(() => resolve()));
            if (window.innerWidth >= 1024 && quoteSummaryScroll.value) {
                quoteSummaryScroll.value.scrollTop = quoteSummaryScroll.value.scrollHeight;
            }
            const selectedScreenCard = selectedVehicle.value
                ? document.getElementById(`screen-product-${selectedVehicle.value.id}`)
                : null;
            (selectedScreenCard ?? quotePanel.value)?.scrollIntoView({
                behavior: window.innerWidth < 1024 ? 'auto' : 'smooth',
                block: 'start',
            });
        }
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('popstate', handleCartHistoryBack);
    window.removeEventListener('message', handlePrintPreviewNavigation);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', closeImageZoomOnEscape);
    window.removeEventListener('scroll', updateMobileQuoteTotals);
    window.removeEventListener('resize', updateMobileQuoteTotals);
    window.visualViewport?.removeEventListener('resize', updateMobileQuoteTotals);
    quoteTotalsObserver?.disconnect();
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

const selectedVehicleImageUrl = computed(() => {
    if (
        selectedBrand.value === null ||
        selectedModel.value === null ||
        selectedYear.value === null
    ) {
        return null;
    }

    const brandSlug = slugifyVehiclePart(selectedBrand.value);
    const modelSlug = slugifyVehiclePart(selectedModel.value);
    const filename = props.vehicleImageMappings
        .filter((mapping) =>
            slugifyVehiclePart(mapping.brand) === brandSlug &&
            slugifyVehiclePart(mapping.model) === modelSlug &&
            selectedYear.value! >= mapping.yearFrom &&
            selectedYear.value! <= mapping.yearTo,
        )
        .sort((first, second) =>
            (first.yearTo - first.yearFrom) - (second.yearTo - second.yearFrom),
        )[0]?.image;

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

const cameraOptionsWithSelectedVariants = computed(() => props.cameraOptions.map((option) => {
    const variants = option.variants ?? [];
    const selectedVariant = variants.find((variant) => variant.id === selectedCameraVariantIds.value[option.key])
        ?? variants[0];

    return selectedVariant ? {
        ...option,
        variantId: selectedVariant.id,
        variantTitle: displayVariantTitle(selectedVariant.title),
        price: selectedVariant.price,
        image: option.image,
        shopifyVariantId: selectedVariant.shopifyVariantId,
        sku: selectedVariant.sku,
    } : option;
}));

const visibleCameraOptions = computed(() => {
    return cameraOptionsWithSelectedVariants.value.filter((option) => {
        if (option.isStandard) {
            return true;
        }

        if (!isSpecificMode.value) {
            return false;
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
            supportsVehicleCombination(
                option.brand,
                option.model,
                selectedBrand.value,
                selectedModel.value,
            ) &&
            selectedYear.value >= option.yearFrom &&
            selectedYear.value <= option.yearTo
        );
    }).toSorted((first, second) => Number(first.isStandard) - Number(second.isStandard));
});

const selectCameraVariant = (camera: SimpleOption, event: Event) => {
    const variantId = Number((event.target as HTMLSelectElement).value);
    const variant = camera.variants?.find((candidate) => candidate.id === variantId);
    const nextVariantIds = {
        ...selectedCameraVariantIds.value,
        [camera.key]: variantId,
    };

    if (selectedCameraKeys.value.includes(camera.key) && variant && normalizedBudget.value !== null) {
        const projectedTotal = estimatedTotal.value - camera.price + variant.price;
        if (projectedTotal > normalizedBudget.value) {
            pendingCameraSelection.value = {
                keys: [...selectedCameraKeys.value],
                overage: projectedTotal - normalizedBudget.value,
                variantIds: nextVariantIds,
            };
            (event.target as HTMLSelectElement).value = String(camera.variantId ?? camera.variants?.[0]?.id ?? '');
            return;
        }
    }

    selectedCameraVariantIds.value = nextVariantIds;
};

const hasSpecificCameraOption = computed(() =>
    visibleCameraOptions.value.some((camera) => !camera.isStandard),
);

const selectedCameras = computed(() =>
    visibleCameraOptions.value.filter((option) =>
        selectedCameraKeys.value.includes(option.key),
    ),
);
const cameraStepTitles = computed(() =>
    selectedCameras.value.map((camera) => truncateStepTitle(camera.title)),
);

const pendingCameraSelection = ref<{
    keys: string[];
    overage: number;
    variantIds?: Record<string, number>;
} | null>(null);

const cameraKeysAfterToggle = (key: string) => {
    const camera = visibleCameraOptions.value.find((option) => option.key === key);
    const is360 = camera?.productHandle === 'camara-360-para-radios-de-coche-android-con-vista-de-ave';
    const has360 = selectedCameras.value.some(
        (option) => option.productHandle === 'camara-360-para-radios-de-coche-android-con-vista-de-ave',
    );

    if (is360) {
        return has360 ? [] : [key];
    }

    const without360 = selectedCameraKeys.value.filter(
        (selectedKey) => props.cameraOptions.find((option) => option.key === selectedKey)?.productHandle
            !== 'camara-360-para-radios-de-coche-android-con-vista-de-ave',
    );
    return without360.includes(key)
        ? without360.filter((selectedKey) => selectedKey !== key)
        : [
            ...without360.filter(
                (selectedKey) => props.cameraOptions.find((option) => option.key === selectedKey)?.productHandle
                    !== camera?.productHandle,
            ),
            key,
        ];
};

const toggleCamera = (key: string) => {
    const nextKeys = cameraKeysAfterToggle(key);
    const isAdding = !selectedCameraKeys.value.includes(key) && nextKeys.includes(key);
    const currentCameraTotal = selectedCameras.value.reduce((sum, camera) => sum + camera.price, 0);
    const nextCameraTotal = visibleCameraOptions.value
        .filter((camera) => nextKeys.includes(camera.key))
        .reduce((sum, camera) => sum + camera.price, 0);
    const projectedTotal = estimatedTotal.value - currentCameraTotal + nextCameraTotal;

    if (isAdding && normalizedBudget.value !== null && projectedTotal > normalizedBudget.value) {
        pendingCameraSelection.value = {
            keys: nextKeys,
            overage: projectedTotal - normalizedBudget.value,
        };
        return;
    }

    selectedCameraKeys.value = nextKeys;
};

const confirmCameraOverBudget = () => {
    if (!pendingCameraSelection.value) return;
    if (pendingCameraSelection.value.variantIds) {
        selectedCameraVariantIds.value = pendingCameraSelection.value.variantIds;
    }
    selectedCameraKeys.value = pendingCameraSelection.value.keys;
    pendingCameraSelection.value = null;
};

watch(normalizedBudget, () => {
    if (normalizedBudget.value === null) return;

    selectedScreenVariantIds.value = selectedScreenVariantIds.value.filter((variantId) =>
        [...props.vehicles, ...props.universalScreens]
            .flatMap((vehicle) => vehicle.variants)
            .flatMap(screenVariantChoices)
            .some((choice) => choice.id === variantId && !isScreenChoiceOverBudget(choice)),
    );
});

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

const toggleSpeakerCategory = async (category: string) => {
    const isSelecting = selectedSpeakerCategory.value !== category;
    selectedSpeakerCategory.value = isSelecting ? category : '';

    if (isSelecting) await centerConfiguratorTarget('speaker-size-step');
};

const toggleSpeakerSize = async (size: string) => {
    const isSelecting = selectedSpeakerSizes.value !== size;
    selectedSpeakerSizeByCategory.value = {
        ...selectedSpeakerSizeByCategory.value,
        [selectedSpeakerCategory.value]: isSelecting ? size : '',
    };

    if (isSelecting) await centerConfiguratorTarget('speaker-options-step');
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
        'altavoces completos': 'speaker.categories.complete_speakers',
        'kit de altavoces': 'speaker.categories.speaker_kit',
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
const speakerStepTitles = computed(() =>
    selectedSpeakers.value.map((speaker) => truncateStepTitle(speaker.productTitle)),
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
            installerAddress: resolvedInstallationArea.value.installerAddress ?? null,
            installerPhone: resolvedInstallationArea.value.installerPhone ?? null,
            postalRanges: [],
            productHandles: resolvedInstallationArea.value.productHandles,
            productPrices: resolvedInstallationArea.value.productPrices ?? {},
            productTitles: resolvedInstallationArea.value.productTitles ?? {},
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

const isTenerife = computed(() =>
    matchedInstallationZone.value?.name
        .toLocaleLowerCase()
        .includes('tenerife') ?? false,
);

const isFuerteventura = computed(() =>
    matchedInstallationZone.value?.name
        .toLocaleLowerCase()
        .includes('fuerteventura') ?? false,
);

const isLanzarote = computed(() =>
    matchedInstallationZone.value?.name
        .toLocaleLowerCase()
        .includes('lanzarote') ?? false,
);

const isMadrid = computed(() =>
    matchedInstallationZone.value?.name
        .toLocaleLowerCase()
        .includes('madrid') ?? false,
);

const isBarcelona = computed(() =>
    matchedInstallationZone.value?.name
        .toLocaleLowerCase()
        .includes('barcelona') ?? false,
);

const isMalaga = computed(() =>
    normalizeInstallationValue(matchedInstallationZone.value?.name ?? '').includes('malaga'),
);

const isMurcia = computed(() =>
    normalizeInstallationValue(matchedInstallationZone.value?.name ?? '').includes('murcia'),
);

const hasThreeStepInstallationFlow = computed(() => matchedInstallationZone.value !== null);

const precheckProduct = computed(() =>
    props.installationOptions.find((option) =>
        option.subtype === 'precheck' ||
        option.title.toLocaleLowerCase().includes('precheck'),
    ) ?? null,
);

const precheckPrice = computed(() =>
    selectedPrecheckMethod.value === 'installer'
        ? (precheckProduct.value?.price ?? 25)
        : 0,
);

const serviceZones = computed(() => [
    { key: 'north' as const, label: t('installation.zone_north') },
    { key: 'capital' as const, label: t('installation.zone_capital') },
    { key: 'south' as const, label: t('installation.zone_south') },
]);

const toggleServiceZone = async (zone: ServiceZoneKey) => {
    const isSelecting = selectedServiceZone.value !== zone;
    selectedServiceZone.value = isSelecting ? zone : null;
    selectedPrecheckMethod.value = null;
    selectedInstallationKey.value = null;

    if (isSelecting) {
        await centerConfiguratorTarget(
            requiresPrecheck.value ? 'installation-precheck-step' : 'installation-final-step',
        );
    }
};

const togglePrecheckMethod = async (method: 'self' | 'installer') => {
    const isSelecting = selectedPrecheckMethod.value !== method;
    selectedPrecheckMethod.value = isSelecting ? method : null;
    selectedInstallationKey.value = null;

    if (isSelecting) await centerConfiguratorTarget('installation-final-step');
};

const removePrecheck = () => {
    selectedPrecheckMethod.value = null;
    selectedInstallationKey.value = null;
};

const hasSelectedProducts = computed(
    () => Boolean(
        selectedScreens.value.length ||
        selectedCameras.value.length ||
        selectedSpeakers.value.length ||
        selectedCustomProducts.value.length
    ),
);
watch(hasSelectedProducts, async () => {
    await nextTick();
    updateMobileQuoteTotals();
});
const requiresPrecheck = computed(
    () => selectedScreens.value.length > 0,
);
const showsInstallationZoneStep = computed(
    () => Boolean(checkedPostalCode.value && hasThreeStepInstallationFlow.value && hasSelectedProducts.value),
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
        (camera) => camera.productHandle === 'camara-360-para-radios-de-coche-android-con-vista-de-ave',
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

const zoneServiceMatchesSelection = (option: SimpleOption) => {
    const title = normalizeInstallationValue(option.sourceTitle ?? option.title);
    const hasScreen = selectedScreens.value.length > 0;
    const hasCamera = selectedCameras.value.length > 0;
    const hasSpeaker = selectedSpeakers.value.length > 0;
    const mentionsScreen = title.includes('pantalla');
    const mentionsCamera = title.includes('camara');
    const mentionsSpeaker = title.includes('altavoc');

    if (hasScreen !== mentionsScreen || hasCamera !== mentionsCamera || hasSpeaker !== mentionsSpeaker) {
        return false;
    }

    if (hasCamera) {
        const hasCamera360 = selectedCameras.value.some(
            (camera) => camera.productHandle === 'camara-360-para-radios-de-coche-android-con-vista-de-ave',
        );
        if (hasCamera360 !== title.includes('360')) return false;
        if (selectedCameras.value.length >= 2 && !/2\s*camara|trasera\s+y\s+delantera/.test(title)) return false;
        if (selectedCameras.value.length === 1 && /2\s*camara|trasera\s+y\s+delantera/.test(title)) return false;
    }

    if (hasSpeaker) {
        const expectedSpeakers = selectedSpeakers.value.length === 1 ? 2 : 4;
        if (!title.includes(`${expectedSpeakers} altavoc`)) return false;
    }

    return true;
};

const installationIcons = (option: SimpleOption) => {
    const title = normalizeInstallationValue(option.sourceTitle ?? option.title);

    return {
        speaker: title.includes('altavoc'),
        camera: title.includes('camara'),
        screen: title.includes('pantalla'),
    };
};

const visibleInstallationOptions = computed(() => {
    const hasZoneManagedServices = matchedInstallationZone.value?.productHandles.some(
        (handle) => handle.startsWith('zone-service-'),
    ) ?? false;

    if (
        !matchedInstallationZone.value ||
        (hasSelectedProducts.value && !requiredInstallationCombination.value && !hasZoneManagedServices) ||
        (hasSelectedProducts.value && hasThreeStepInstallationFlow.value && !selectedServiceZone.value) ||
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
            (option.key.startsWith('zone-service-')
                ? zoneServiceMatchesSelection(option)
                : !hasSelectedProducts.value ||
                optionInstallationCombination(option) === requiredInstallationCombination.value);
    });

    const uniqueOptions = new Map<string, SimpleOption>();
    matchingOptions.forEach((option) => {
        const key = option.key.startsWith('zone-service-')
            ? option.key
            : `${normalizeInstallationValue(option.location ?? '')}|${optionInstallationCombination(option)}`;
        if (!uniqueOptions.has(key)) uniqueOptions.set(key, option);
    });

    return [...uniqueOptions.values()].map((option) => ({
        ...option,
        title: matchedInstallationZone.value!.productTitles[option.key] ?? option.title,
        price: matchedInstallationZone.value!.productPrices[option.key] ?? option.price,
    }));
});

const handlePostalCodeInput = async () => {
    postalCode.value = postalCode.value.replace(/\D/g, '').slice(0, 5);

    if (postalCode.value.length === 5) {
        await centerConfiguratorTarget('postal-code-check', true);
    }
};

const centerInstallationResult = async () => {
    if (matchedInstallationZone.value && hasThreeStepInstallationFlow.value && hasSelectedProducts.value) {
        await centerConfiguratorTarget('installation-zone-step');
        return;
    }

    if (matchedInstallationZone.value && requiresPrecheck.value) {
        await centerConfiguratorTarget('installation-precheck-step');
        return;
    }

    if (matchedInstallationZone.value) {
        await centerConfiguratorTarget('installation-final-step');
        return;
    }

    await centerConfiguratorTarget('installation-availability-message');
};

const checkPostalCode = async () => {
    const normalized = postalCode.value.trim();

    if (!/^\d{5}$/.test(normalized)) {
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = t('errors.postal_invalid');
        await centerConfiguratorTarget('installation-availability-message');
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
        await centerInstallationResult();
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
        await centerInstallationResult();
    } catch {
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = t('errors.postal_lookup');
        await centerConfiguratorTarget('installation-availability-message');
    }
};

const selectedInstallation = computed(
    () =>
        visibleInstallationOptions.value.find(
            (option) => option.key === selectedInstallationKey.value,
        ) ?? null,
);
const installationStepTitle = computed(() => selectedInstallation.value === null
    ? t('steps.installation')
    : truncateStepTitle(selectedInstallation.value.title));

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

    const scrollKey = type === 'screen'
        ? selectableScreenVehicles.value
            .flatMap((vehicle) => vehicle.variants)
            .find((variant) => screenVariantChoices(variant).some((choice) => choice.id === Number(key)))?.id ?? key
        : key;

    await nextTick();
    window.requestAnimationFrame(() => {
        document
            .getElementById(`product-${type}-${scrollKey}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
};

const productsSubtotal = computed(
    () =>
        selectedScreens.value.reduce((sum, screen) => sum + screen.price * cartQuantity(`screen:${screen.id}`), 0) +
        selectedCameras.value.reduce((sum, camera) => sum + camera.price * cartQuantity(`camera:${camera.key}`), 0) +
        selectedSpeakers.value.reduce((sum, speaker) => sum + speaker.price * cartQuantity(`speaker:${speaker.key}`), 0) +
        selectedCustomProducts.value
            .filter((product) => product.category !== 'installation')
            .reduce((sum, product) => sum + product.price * cartQuantity(`custom:${product.key}`), 0),
);

const cartItemCount = computed(() =>
    selectedScreens.value.reduce((total, screen) => total + cartQuantity(`screen:${screen.id}`), 0)
    + selectedCameras.value.reduce((total, camera) => total + cartQuantity(`camera:${camera.key}`), 0)
    + selectedSpeakers.value.reduce((total, speaker) => total + cartQuantity(`speaker:${speaker.key}`), 0)
    + selectedCustomProducts.value.reduce((total, product) => total + cartQuantity(`custom:${product.key}`), 0)
    + (selectedInstallation.value ? cartQuantity(`installation:${selectedInstallation.value.key}`) : 0)
    + (selectedPrecheckMethod.value === 'installer' ? cartQuantity('precheck:installer') : 0),
);
const cartItemCountLabel = computed(() => cartItemCount.value > 99 ? '99+' : String(cartItemCount.value));

const installationCost = computed(
    () =>
        precheckPrice.value * (selectedPrecheckMethod.value === 'installer' ? cartQuantity('precheck:installer') : 1) +
        (selectedInstallation.value?.price ?? 0) * (selectedInstallation.value ? cartQuantity(`installation:${selectedInstallation.value.key}`) : 1) +
        selectedCustomProducts.value
            .filter((product) => product.category === 'installation')
            .reduce((sum, product) => sum + product.price * cartQuantity(`custom:${product.key}`), 0),
);

type DiscountTier = {
    code: string;
    threshold: number;
    percentage?: number;
    fixedAmount?: number;
};

const discountTiers: DiscountTier[] = [
    { code: 'Vip', threshold: 900, fixedAmount: 50 },
    { code: 'Pro', threshold: 500, percentage: 3 },
    { code: 'Base', threshold: 300, percentage: 2 },
];
const activeDiscount = computed(
    () => discountTiers.find((tier) => productsSubtotal.value >= tier.threshold) ?? null,
);
const nextDiscount = computed(() => {
    return [...discountTiers].reverse().find((tier) => productsSubtotal.value < tier.threshold) ?? null;
});
const quoteDiscountCode = ref('');
const quoteDiscountType = ref<'percentage' | 'fixed'>('percentage');
const quoteDiscountValue = ref('');
const customDiscount = computed(() => {
    const code = quoteDiscountCode.value.trim();
    const value = Number.parseFloat(String(quoteDiscountValue.value).replace(',', '.'));

    if (!code || !Number.isFinite(value) || value <= 0) {
        return null;
    }

    return {
        code,
        type: quoteDiscountType.value,
        value: quoteDiscountType.value === 'percentage' ? Math.min(value, 100) : value,
    };
});
const effectiveDiscountCode = computed(() => customDiscount.value?.code ?? activeDiscount.value?.code ?? null);
const automaticDiscountAmount = (tier: DiscountTier) =>
    tier.fixedAmount ?? productsSubtotal.value * ((tier.percentage ?? 0) / 100);
const automaticDiscountLabel = (tier: DiscountTier) => {
    if (tier.fixedAmount === undefined) {
        return t('quote.discount', { percentage: tier.percentage ?? 0 });
    }

    return {
        es: `Descuento ${tier.fixedAmount} €`,
        it: `Sconto ${tier.fixedAmount} €`,
        en: `€${tier.fixedAmount} discount`,
    }[props.locale];
};
const automaticDiscountAppliedMessage = (tier: DiscountTier) => {
    if (tier.fixedAmount === undefined) {
        return t('quote.discount_applied', { percentage: tier.percentage ?? 0, code: tier.code });
    }

    return {
        es: `¡Ya tienes un descuento fijo de ${tier.fixedAmount} € aplicado!`,
        it: `Hai già uno sconto fisso di ${tier.fixedAmount} € applicato!`,
        en: `You already have a fixed €${tier.fixedAmount} discount applied!`,
    }[props.locale];
};
const automaticDiscountRemainingMessage = (tier: DiscountTier) => {
    const amount = Math.max(0, tier.threshold - productsSubtotal.value).toFixed(2);

    if (tier.fixedAmount === undefined) {
        return t('quote.discount_remaining', {
            amount,
            percentage: tier.percentage ?? 0,
            code: tier.code,
        });
    }

    return {
        es: `Añade solo ${amount} € más y obtén un descuento fijo de ${tier.fixedAmount} € en todo el pedido.`,
        it: `Aggiungi solo altri ${amount} € e ottieni uno sconto fisso di ${tier.fixedAmount} € sull’intero ordine.`,
        en: `Add just €${amount} more and get a fixed €${tier.fixedAmount} discount on the entire order.`,
    }[props.locale];
};
const discountAmount = computed(() => {
    if (customDiscount.value) {
        const amount = customDiscount.value.type === 'percentage'
            ? productsSubtotal.value * (customDiscount.value.value / 100)
            : customDiscount.value.value;

        return Math.min(productsSubtotal.value, amount);
    }

    return activeDiscount.value ? automaticDiscountAmount(activeDiscount.value) : 0;
});
const discountLabel = computed(() => {
    if (customDiscount.value) {
        const value = customDiscount.value.type === 'percentage'
            ? `${customDiscount.value.value}%`
            : euroFormatter.value.format(customDiscount.value.value);

        return `${adminDiscountCopy.value.special} ${value}`;
    }

    return activeDiscount.value ? automaticDiscountLabel(activeDiscount.value) : '';
});
const onlineTotal = computed(() => productsSubtotal.value - discountAmount.value);
const estimatedTotal = computed(() => onlineTotal.value + installationCost.value);

const checkoutLineItems = computed(() => {
    const items: Array<{ variantId: string; quantity: number }> = [];

    selectedScreens.value.forEach((screen) => {
        if (screen.shopifyVariantId) {
            items.push({
                variantId: screen.shopifyVariantId,
                quantity: cartQuantity(`screen:${screen.id}`),
            });
        }
    });

    selectedCameras.value.forEach((camera) => {
        if (camera.shopifyVariantId) {
            items.push({
                variantId: camera.shopifyVariantId,
                quantity: cartQuantity(`camera:${camera.key}`),
            });
        }
    });

    selectedSpeakers.value.forEach((speaker) => {
        if (speaker.shopifyVariantId) {
            items.push({
                variantId: speaker.shopifyVariantId,
                quantity: cartQuantity(`speaker:${speaker.key}`),
            });
        }
    });

    selectedCustomProducts.value.forEach((product) => {
        if (product.category !== 'installation' && product.shopifyVariantId) {
            items.push({ variantId: product.shopifyVariantId, quantity: cartQuantity(`custom:${product.key}`) });
        }
    });

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
    const localePrefix = props.locale === 'es' ? '' : `/${props.locale}`;
    const parameters = new URLSearchParams();

    if (effectiveDiscountCode.value) {
        parameters.set('discount', effectiveDiscountCode.value);
    }

    const query = parameters.toString();

    return `https://www.autoradiocanario.com${localePrefix}/cart/${cartPath}${query ? `?${query}` : ''}`;
});

const statisticSessionUuid = () => {
    try {
        const storageKey = 'autoradiocanario-statistics-session';
        const existing = window.sessionStorage.getItem(storageKey);
        if (existing) return existing;

        const uuid = window.crypto?.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
            const random = Math.floor(Math.random() * 16);
            const value = character === 'x' ? random : (random & 0x3) | 0x8;
            return value.toString(16);
        });
        window.sessionStorage.setItem(storageKey, uuid);
        return uuid;
    } catch {
        return null;
    }
};

const statisticReferrer = () => {
    if (!document.referrer) return null;

    try {
        const referrer = new URL(document.referrer);
        return `${referrer.origin}${referrer.pathname}`.slice(0, 2048);
    } catch {
        return null;
    }
};

const trackVisitorEntry = async () => {
    try {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const searchParams = new URLSearchParams(window.location.search);
        await fetch('/configurator/statistics', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            credentials: 'same-origin',
            keepalive: true,
            body: JSON.stringify({
                session_uuid: statisticSessionUuid(),
                event_type: 'configurator_entered',
                installation_selected: false,
                camera_selected: false,
                language: props.locale,
                referrer: statisticReferrer(),
                utm_source: searchParams.get('utm_source'),
                utm_campaign: searchParams.get('utm_campaign'),
                device_type: window.innerWidth < 768 ? 'mobile' : window.innerWidth < 1024 ? 'tablet' : 'desktop',
            }),
        });
    } catch {
        // Visitor analytics are best-effort and must never affect the configurator.
    }
};

const statisticProduct = computed(() => {
    const screen = selectedScreens.value[0];
    if (screen) {
        const product = vehicleForScreenVariant(screen.id);
        if (product) {
            return {
                productId: product.id,
                variantId: screen.id,
                productTitle: product.title,
                variantTitle: [displayVariantTitle(screen.title), screen.color].filter(Boolean).join(' / '),
                productPrice: screen.price,
                productType: 'screen',
                vehicleSpecific: true,
            };
        }
    }

    const camera = selectedCameras.value[0];
    if (camera) {
        return {
            productId: camera.productId ?? null,
            variantId: camera.variantId ?? null,
            productTitle: camera.productTitle ?? camera.title,
            variantTitle: camera.variantTitle ?? null,
            productPrice: camera.price,
            productType: 'camera',
            vehicleSpecific: !camera.isStandard
                && Boolean(camera.brand && camera.model)
                && camera.yearFrom != null
                && camera.yearTo != null,
        };
    }

    const speaker = selectedSpeakers.value[0];
    if (speaker) {
        return {
            productId: speaker.productId ?? null,
            variantId: speaker.variantId ?? null,
            productTitle: speaker.productTitle,
            variantTitle: speaker.variantTitle ?? null,
            productPrice: speaker.price,
            productType: 'speaker',
            vehicleSpecific: Boolean(speaker.brand && speaker.model)
                && speaker.yearFrom != null
                && speaker.yearTo != null,
        };
    }

    const customProduct = selectedCustomProducts.value.find((product) => product.category !== 'installation');
    if (customProduct) {
        return {
            productId: customProduct.productId,
            variantId: customProduct.variantId,
            productTitle: customProduct.title,
            variantTitle: customProduct.variantTitle,
            productPrice: customProduct.price,
            productType: customProduct.category,
            vehicleSpecific: Boolean(customProduct.brand && customProduct.model)
                && customProduct.yearFrom != null
                && customProduct.yearTo != null,
        };
    }

    const installation = selectedInstallation.value;
    if (installation) {
        return {
            productId: installation.productId ?? null,
            variantId: installation.variantId ?? null,
            productTitle: installation.title,
            variantTitle: installation.variantTitle ?? null,
            productPrice: installation.price,
            productType: 'installation',
            vehicleSpecific: false,
        };
    }

    return null;
});

const trackConfigurationEvent = async (eventType: 'quote_downloaded' | 'checkout_clicked') => {
    const product = statisticProduct.value;
    const installationTypes = [
        selectedInstallation.value?.title,
        selectedPrecheckMethod.value === 'installer'
            ? t('installation.precheck_installer_summary')
            : null,
        ...selectedCustomProducts.value
            .filter((product) => product.category === 'installation')
            .map((product) => product.variantTitle
                ? `${product.title} — ${displayVariantTitle(product.variantTitle)}`
                : product.title),
    ].filter(Boolean).join(' + ');
    const searchParams = new URLSearchParams(window.location.search);
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 1500);

    try {
        const csrfToken = document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content;
        await fetch('/configurator/statistics', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            credentials: 'same-origin',
            keepalive: true,
            signal: controller.signal,
            body: JSON.stringify({
                session_uuid: statisticSessionUuid(),
                event_type: eventType,
                brand: product?.vehicleSpecific ? selectedBrand.value : null,
                model: product?.vehicleSpecific ? selectedModel.value : null,
                year: product?.vehicleSpecific ? selectedYear.value : null,
                product_id: product?.productId ?? null,
                variant_id: product?.variantId ?? null,
                product_type: product?.productType ?? null,
                product_title: product?.productTitle ?? null,
                variant_title: product?.variantTitle ?? null,
                product_price: product?.productPrice ?? null,
                configuration_value: estimatedTotal.value,
                installation_selected: installationCost.value > 0,
                installation_type: installationTypes || null,
                camera_selected: selectedCameraKeys.value.length > 0,
                postal_code: postalCode.value || null,
                service_zone: selectedServiceZone.value ?? matchedInstallationZone.value?.name ?? null,
                language: props.locale,
                referrer: statisticReferrer(),
                utm_source: searchParams.get('utm_source'),
                utm_campaign: searchParams.get('utm_campaign'),
                device_type: window.innerWidth < 768
                    ? 'mobile'
                    : window.innerWidth < 1024
                        ? 'tablet'
                        : 'desktop',
            }),
        });
    } catch {
        // Statistics are best-effort and must never interrupt the requested action.
    } finally {
        window.clearTimeout(timeout);
    }
};

const sharedConfigurationPayload = computed<SharedConfigurationPayload | null>(() => {
    if (!hasSelectedProducts.value) return null;

    const screens = selectedScreenVariantIds.value.flatMap((variantId) => {
        const vehicle = vehicleForScreenVariant(variantId);
        const variant = vehicle?.variants
            .flatMap(screenVariantChoices)
            .find((candidate) => candidate.id === variantId);

        return vehicle && variant ? [{
            product: vehicle.handle,
            variant: variant.shopifyVariantId || String(variant.id),
        }] : [];
    });

    return {
        mode: configuratorMode.value,
        din: isUniversalMode.value ? selectedUniversalDin.value : null,
        brand: isSpecificMode.value ? selectedBrand.value : null,
        model: isSpecificMode.value ? selectedModel.value : null,
        year: isSpecificMode.value ? selectedYear.value : null,
        screens,
        cameras: selectedCameras.value.map((camera) =>
            (camera.variants?.length ?? 0) > 1 && camera.variantId
                ? `${camera.key}--variant-${camera.variantId}`
                : camera.key,
        ),
        speakers: [...selectedSpeakerKeys.value],
        customProducts: [...selectedCustomProductKeys.value],
        installation: selectedInstallationKey.value,
        postalCode: postalCode.value || null,
        serviceZone: selectedServiceZone.value,
        precheck: selectedPrecheckMethod.value,
    };
});

const copySharedConfigurationStatus = ref<'idle' | 'copied' | 'error'>('idle');

const copySharedConfigurationUrl = async () => {
    if (!sharedConfigurationPayload.value) {
        return;
    }

    try {
        const csrfToken = document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content;
        const response = await fetch('/configurator/shared-configurations', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ configuration: sharedConfigurationPayload.value }),
        });

        if (!response.ok) throw new Error('Unable to share configuration.');

        const result = await response.json();
        if (typeof result.uuid !== 'string') throw new Error('Invalid shared configuration UUID.');

        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('c', result.uuid);
        const sharedUrl = url.toString();

        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(sharedUrl);
        } else {
            const textArea = document.createElement('textarea');
            textArea.value = sharedUrl;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            textArea.remove();
        }

        copySharedConfigurationStatus.value = 'copied';
    } catch {
        copySharedConfigurationStatus.value = 'error';
    }

    window.setTimeout(() => {
        copySharedConfigurationStatus.value = 'idle';
    }, 2500);
};

const showQuoteModal = ref(false);
const quoteClientName = ref('');
const quoteClientPhone = ref('');
const quoteClientEmail = ref('');
const quoteCustomsTaxes = ref('');
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

const generateQuote = async (withoutClientData = false, providedPrintWindow?: Window) => {
    if ((!withoutClientData && !quoteClientName.value.trim()) || !hasSelectedProducts.value) {
        return;
    }

    quoteGenerationError.value = null;
    const printWindow = providedPrintWindow ?? window.open('', '_blank');
    const shouldOpenPrintPreview = !window.matchMedia('(max-width: 1023px)').matches;

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
    const clientName = withoutClientData ? '' : quoteClientName.value.trim();
    const clientPhone = withoutClientData ? '' : quoteClientPhone.value.trim();
    const clientEmail = withoutClientData ? '' : quoteClientEmail.value.trim();
    const customsTaxes = withoutClientData ? '' : quoteCustomsTaxes.value.trim();
    const vehicle = isSpecificMode.value
        ? [selectedBrand.value, displayVehicleModel(selectedModel.value), selectedYear.value].filter(Boolean).join(' ')
        : '';
    const vehicleImage = isSpecificMode.value && selectedVehicleImageUrl.value
        ? new URL(selectedVehicleImageUrl.value, window.location.origin).href
        : null;
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
            description: `${vehicle?.title ?? t('print.screen')} — ${t('print.variant', {
                variant: [displayVariantTitle(screen.title), screen.color].filter(Boolean).join(' / '),
            })}`,
            quantity: cartQuantity(`screen:${screen.id}`),
            price: screen.price,
        });
    });

    selectedCameras.value.forEach((camera) => {
        items.push({
            code: camera.sku || camera.key,
            description: camera.title,
            quantity: cartQuantity(`camera:${camera.key}`),
            price: camera.price,
        });
    });

    selectedSpeakers.value.forEach((speaker) => {
        items.push({
            code: speaker.sku || speaker.handle,
            description: speaker.productTitle,
            quantity: cartQuantity(`speaker:${speaker.key}`),
            price: speaker.price,
        });
    });

    selectedCustomProducts.value.forEach((product) => {
        items.push({
            code: product.sku || product.shopifyVariantId || product.key,
            description: (product.variantTitle
                ? `${product.title} — ${displayVariantTitle(product.variantTitle)}`
                : product.title) + (product.category === 'installation'
                ? ` — ${t('quote.installation_direct')}`
                : ''),
            quantity: cartQuantity(`custom:${product.key}`),
            price: product.price,
        });
    });

    if (selectedInstallation.value) {
        items.push({
            code: selectedInstallation.value.sku || selectedInstallation.value.key,
            description: `${selectedInstallation.value.title} — ${t('quote.installation_direct')}`,
            quantity: cartQuantity(`installation:${selectedInstallation.value.key}`),
            price: selectedInstallation.value.price,
        });
    }

    if (selectedPrecheckMethod.value === 'installer') {
        items.push({
            code: precheckProduct.value?.sku || 'PRECHECK',
            description: `${t('installation.precheck_installer_summary')} — ${t('quote.installation_direct')}`,
            quantity: cartQuantity('precheck:installer'),
            price: precheckPrice.value,
        });
    }

    if (effectiveDiscountCode.value && discountAmount.value > 0) {
        items.push({
            code: effectiveDiscountCode.value,
            description: discountLabel.value,
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
        ? `<div class="checkout"><strong>${escapeHtml(t('print.purchase_link'))}:</strong><br><span>${escapeHtml(checkoutUrl.value)}</span><p class="purchase-authorization">${escapeHtml(t('print.purchase_authorization'))}</p></div>`
        : '';

    printWindow.document.write(`<!doctype html>
<html lang="${props.locale}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
        .vehicle-band { display: flex; align-items: center; justify-content: center; width: 100%; height: 100px; margin-top: 14px; background: #121212; }
        .vehicle-band img { display: block; width: 180px; height: 94px; object-fit: contain; }
        .details { display: grid; grid-template-columns: 1fr 1fr; align-items: start; gap: 30px; margin-top: 18px; }
        .details h2 { margin: 0 0 4px; font-size: 15px; }
        .details p { margin: 3px 0; }
        .issuer { text-align: right; }
        .date { margin: 14px 0; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; background: #fff; color: #292727; }
        .table-scroll { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        th, td { border: 1px solid #b8b8b8; padding: 8px 7px; vertical-align: middle; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th { background: #fff; color: #292727; font-size: 12px; font-weight: 700; }
        th:nth-child(1) { width: 16%; }
        th:nth-child(2) { width: 46%; }
        th:nth-child(3) { width: 7%; }
        th:nth-child(4), th:nth-child(5) { width: 15.5%; }
        td { font-size: 10px; font-weight: 600; }
        .code, .center { text-align: center; }
        .money { text-align: right; white-space: nowrap; }
        .notes { margin-top: 22px; font-size: 9px; }
        .notes ul { margin: 5px 0 14px; padding-left: 18px; }
        .customs-taxes { margin-top: 10px; line-height: 1.4; break-inside: avoid; page-break-inside: avoid; }
        .customs-taxes h3 { margin: 0 0 4px; font-size: 10px; }
        .customs-taxes p { margin: 0; white-space: pre-wrap; }
        .checkout { margin-top: 12px; overflow-wrap: anywhere; font-size: 8px; }
        .purchase-authorization { margin: 5px 0 0; font-size: 9px; line-height: 1.4; color: #292727; }
        .totals { margin-top: auto; border: 1px solid #b8b8b8; background: #fff; color: #292727; font-size: 12px; font-weight: 700; }
        .total-row { display: grid; grid-template-columns: 2fr 1fr; border-bottom: 1px solid #d5d5d5; }
        .total-row:last-child { border-bottom: 0; font-size: 14px; font-weight: 800; }
        .total-row div { padding: 9px 10px; }
        .total-row .amount { border-left: 1px solid #b8b8b8; text-align: right; }
        .direct-notice { margin: 10px 0; line-height: 1.4; }
        .shipping-notice { margin: 8px 0 0; border: 1px solid #e4ad00; padding: 8px 10px; background: #fff8d8; color: #292727; font-size: 10px; font-weight: 700; text-align: center; }
        .service-amount-notice { margin: 5px 0 0; font-size: 9px; line-height: 1.4; color: #292727; break-inside: avoid; page-break-inside: avoid; }
        footer { margin: 12px -12mm 0; padding: 18px 12mm; background: #0067a9; color: #fff; text-align: center; font-size: 7px; font-weight: 700; letter-spacing: 1.2px; break-inside: avoid; page-break-inside: avoid; }
        .screen-actions { display: none; }
        @media screen and (max-width: 700px) {
            body { padding: 14px; font-size: 14px; background: #f4f4f4; }
            .screen-actions { position: relative; display: flex; justify-content: center; padding: 0 0 12px; }
            .screen-actions { flex-wrap: wrap; gap: 10px; }
            .screen-actions button { width: 100%; max-width: 420px; border: 0; border-radius: 10px; padding: 14px 18px; background: #d8ae2d; color: #000; font: 700 16px Arial, sans-serif; }
            .screen-actions button.cart { background: #00a875; }
            .screen-actions button.cart, .screen-actions button.configurator { color: #fff; }
            .screen-actions button.configurator { background: #334fb4; }
            .screen-actions button svg { width: 30px; height: 30px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; vertical-align: middle; }
            .screen-actions button.back { background: #334fb4; }
            .page { min-height: 0; gap: 0; background: #fff; overflow: hidden; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,.12); }
            .header { flex-direction: column; gap: 12px; padding: 14px; }
            .brand img { width: 70px; height: 52px; }
            .brand-name { font-size: 18px; }
            .quote-title { width: 100%; text-align: left; border-top: 1px solid #ddd; padding-top: 10px; }
            .quote-title h1 { margin: 0 0 5px; font-size: 21px; }
            .vehicle-band { height: 150px; margin-top: 0; }
            .vehicle-band img { width: 230px; height: 140px; }
            .details { grid-template-columns: 1fr; gap: 16px; margin: 0; padding: 16px; }
            .issuer { text-align: left; border-top: 1px solid #ddd; padding-top: 14px; }
            .date { margin: 0; padding: 0 16px 14px; }
            .table-scroll { border-top: 1px solid #bbb; border-bottom: 1px solid #bbb; }
            table { min-width: 760px; }
            th, td { padding: 10px 8px; }
            .notes { margin-top: 0; padding: 18px 16px; font-size: 12px; line-height: 1.45; }
            .checkout, .purchase-authorization, .service-amount-notice { font-size: 11px; }
            .totals { margin-top: 0; border-left: 0; border-right: 0; font-size: 13px; }
            .total-row { grid-template-columns: minmax(0,1fr) auto; }
            .total-row div { padding: 11px 12px; }
            .total-row:last-child { font-size: 15px; }
            .shipping-notice { margin: 12px; font-size: 12px; }
            footer { margin: 12px 0 0; padding: 18px 12px; font-size: 9px; }
        }
        @media print {
            html, body { height: auto; }
            .screen-actions { display: none !important; }
            .table-scroll { overflow: visible; }
            .page { min-height: auto; break-after: avoid; page-break-after: avoid; }
            .totals { margin-top: 16px; }
            table, th, td, .totals, .total-row .amount, footer, .vehicle-band {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .totals, footer { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="screen-actions"><button type="button" class="cart" aria-label="Cart" onclick="window.close()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg></button><button type="button" class="configurator" aria-label="Configurator" onclick="if(window.opener){window.opener.postMessage({type:'autoradio:return-to-configurator'},'*')}window.close()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"/></svg></button><button type="button" onclick="window.print()">${escapeHtml(props.locale === 'es' ? 'Imprimir o guardar PDF' : props.locale === 'it' ? 'Stampa o salva PDF' : 'Print or save PDF')}</button></div>
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
    ${vehicleImage ? `
        <div class="vehicle-band">
            <img src="${escapeHtml(vehicleImage)}" alt="${escapeHtml(vehicle)}">
        </div>
    ` : ''}
    <section class="details">
        <div>
            <h2>${escapeHtml(t('print.client'))}:</h2>
            ${clientName ? `<p><strong>${escapeHtml(clientName)}</strong></p>` : ''}
            ${clientPhone ? `<p>${escapeHtml(t('print.phone'))}: ${escapeHtml(clientPhone)}</p>` : ''}
            ${clientEmail ? `<p>${escapeHtml(t('print.email'))}: ${escapeHtml(clientEmail)}</p>` : ''}
            <p>${escapeHtml(t('print.vehicle'))}: ${escapeHtml(vehicle || (isUniversalMode.value ? t('mode.universal_title') : t('print.not_specified')))}</p>
        </div>
        <div class="issuer">
            <h2>${escapeHtml(t('print.issued_by'))}:</h2>
            <p><strong>AUTORADIOCANARIO</strong></p>
            <p>Avenida Mencey 49</p>
            <p>35120 Mogán (Las Palmas)</p>
            <p>${escapeHtml(footerContact.value.email)}</p>
            <p>${escapeHtml(footerContact.value.phone)}</p>
        </div>
    </section>
    <p class="date"><strong>${escapeHtml(t('print.date'))}:</strong> ${escapeHtml(quoteDate)}</p>
    <div class="table-scroll"><table>
        <thead><tr><th>ID</th><th>${escapeHtml(t('print.description'))}</th><th>${escapeHtml(t('print.quantity'))}</th><th>${escapeHtml(t('print.unit_price'))}</th><th>${escapeHtml(t('print.amount'))}</th></tr></thead>
        <tbody>${rows}</tbody>
    </table></div>
    <section class="notes">
        <strong>${escapeHtml(t('print.includes'))}:</strong>
        <ul>${includedItems}</ul>
        ${customsTaxes ? `
            <div class="customs-taxes">
                <h3>${escapeHtml(t('quote_form.customs_taxes'))}</h3>
                <p>${escapeHtml(customsTaxes)}</p>
            </div>
        ` : ''}
        <p class="service-amount-notice">${escapeHtml(t('quote.service_amount_notice'))}</p>
        ${installationCost.value > 0 ? `<p class="direct-notice">${escapeHtml(t('quote.installation_payment_notice'))}</p>` : ''}
        ${checkoutLink}
    </section>
    <section class="totals">
        <div class="total-row"><div>${escapeHtml(t('quote.products_online'))}</div><div class="amount">${euroFormatter.value.format(productsSubtotal.value)}</div></div>
        ${discountAmount.value > 0 ? `<div class="total-row"><div>${escapeHtml(discountLabel.value)}</div><div class="amount">−${euroFormatter.value.format(discountAmount.value)}</div></div>` : ''}
        ${installationCost.value > 0 ? `<div class="total-row"><div>${escapeHtml(t('quote.installation_direct'))}</div><div class="amount">${euroFormatter.value.format(installationCost.value)}</div></div>` : ''}
        <div class="total-row"><div>${escapeHtml(t('quote.estimated_total'))}</div><div class="amount">${euroFormatter.value.format(estimatedTotal.value)}</div></div>
        <div class="total-row"><div>${escapeHtml(t('quote.online_total'))}</div><div class="amount">${euroFormatter.value.format(onlineTotal.value)}</div></div>
    </section>
    <p class="shipping-notice">${escapeHtml(t('quote.home_delivery'))}</p>
    <footer>${escapeHtml(footerContact.value.email.toLocaleUpperCase())} &nbsp;&nbsp; WWW.AUTORADIOCANARIO.COM &nbsp;&nbsp; TEL./WHATSAPP: ${escapeHtml(footerContact.value.phone)}</footer>
</main>
${shouldOpenPrintPreview ? '<script>window.addEventListener(\'load\', () => window.print());<\/script>' : ''}
</body>
</html>`);
    printWindow.document.close();
    showQuoteModal.value = false;
};

const downloadQuote = async () => {
    openSteps.value = [];
    const printWindow = window.open('', '_blank');
    await trackConfigurationEvent('quote_downloaded');

    if (!printWindow) {
        quoteGenerationError.value = t('errors.popup_blocked');
        return;
    }

    await generateQuote(true, printWindow);
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

const checkoutConsentAccepted = ref(false);
const showCheckoutConsentWarning = ref(false);
const checkoutConsentAttention = ref(false);
let checkoutConsentAttentionTimer: number | null = null;

const dismissCheckoutConsentWarning = async () => {
    showCheckoutConsentWarning.value = false;
    checkoutConsentAttention.value = true;
    await nextTick();
    (showCart.value ? cartCheckoutConsentSection.value : checkoutConsentSection.value)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (checkoutConsentAttentionTimer !== null) window.clearTimeout(checkoutConsentAttentionTimer);
    checkoutConsentAttentionTimer = window.setTimeout(() => {
        checkoutConsentAttention.value = false;
        checkoutConsentAttentionTimer = null;
    }, 3500);
};
onBeforeUnmount(() => {
    if (checkoutConsentAttentionTimer !== null) window.clearTimeout(checkoutConsentAttentionTimer);
});

const goToCheckout = async () => {
    if (!checkoutUrl.value) {
        return;
    }

    if (!checkoutConsentAccepted.value) {
        showCheckoutConsentWarning.value = true;
        return;
    }

    await trackConfigurationEvent('checkout_clicked');
    window.location.href = checkoutUrl.value;
};

watch(checkoutConsentAccepted, (accepted) => {
    if (accepted) {
        showCheckoutConsentWarning.value = false;
        checkoutConsentAttention.value = false;
        openSteps.value = [];
    }
});

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
        selectedScreenVariantIds.value = [];
        selectedCameraKeys.value = [];
        selectedSpeakerCategory.value = '';
        selectedSpeakerSizeByCategory.value = {};
        selectedSpeakerKeys.value = [];
        selectedCustomProductKeys.value = [];
        selectedInstallationKey.value = null;
        installationRequested.value = false;
        selectedServiceZone.value = null;
        selectedPrecheckMethod.value = null;
        postalCode.value = '';
        checkedPostalCode.value = null;
        resolvedInstallationArea.value = null;
        postalCodeError.value = null;
        openSteps.value = ['vehicle'];
    }

    selectedModel.value = null;
});

watch(selectedYear, () => {
    selectedModel.value = null;
});

watch(hasSelectedProducts, (hasProducts) => {
    if (hasProducts) return;

    installationRequested.value = false;
    selectedInstallationKey.value = null;
    selectedServiceZone.value = null;
    selectedPrecheckMethod.value = null;
    openSteps.value = openSteps.value.filter((step) => step !== 'installation');
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
    () => {
        const availableVariantIds = new Set(
            allScreenVehicles.value.flatMap((vehicle) =>
                vehicle.variants.flatMap((variant) => screenVariantChoices(variant).map((choice) => choice.id)),
            ),
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

    <div class="min-h-screen w-full max-w-full overflow-x-clip bg-[#121212] text-white">
        <header class="border-b border-neutral-800 bg-[#121212]">
            <div class="bg-[#334fb4] text-white">
                <div class="mx-auto grid h-12 max-w-7xl grid-cols-[1fr_auto_1fr] items-center px-4 sm:px-6 lg:px-8">
                    <div class="hidden items-center gap-5 sm:flex">
                        <a
                            href="https://www.facebook.com/autoradiocanario"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Facebook"
                            class="transition hover:text-amber-300"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.5-3.91 3.77-3.91 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.57v1.89h2.77l-.44 2.91h-2.33V22C18.34 21.24 22 17.08 22 12.06Z"/>
                            </svg>
                        </a>
                        <a
                            href="https://www.instagram.com/autoradiocanario/"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Instagram"
                            class="transition hover:text-amber-300"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="5"/>
                                <circle cx="12" cy="12" r="4"/>
                                <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>
                            </svg>
                        </a>
                    </div>
                    <div class="col-start-2 flex items-center text-center text-[11px] font-semibold tracking-[0.12em] sm:text-xs">
                        <span>{{ headerCopy.announcement }}</span>
                    </div>
                </div>
            </div>

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid h-24 grid-cols-[1fr_auto_1fr] items-center gap-5 lg:flex lg:justify-between">
                    <button
                        type="button"
                        class="col-start-1 justify-self-start rounded-md p-2 transition hover:bg-white/10 lg:hidden"
                        :aria-expanded="mobileHeaderOpen"
                        aria-controls="mobile-store-navigation"
                        @click="mobileHeaderOpen = !mobileHeaderOpen"
                    >
                        <span class="sr-only">Menu</span>
                        <svg v-if="!mobileHeaderOpen" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="M4 7h16M4 12h16M4 17h16"/>
                        </svg>
                        <svg v-else class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="m6 6 12 12M18 6 6 18"/>
                        </svg>
                    </button>

                    <a href="https://www.autoradiocanario.com/" aria-label="AutoradioCanario Home" class="col-start-2 shrink-0 justify-self-center lg:col-auto">
                        <img src="/images/logo.png" alt="AutoradioCanario" class="h-16 w-24 object-contain" />
                    </a>

                    <nav class="hidden flex-1 items-center gap-8 pl-4 text-sm lg:flex">
                        <a href="https://www.autoradiocanario.com/" class="border-b border-white pb-1 transition hover:text-amber-400">
                            {{ headerCopy.home }}
                        </a>
                        <a :href="headerContactUrl" class="transition hover:text-amber-400">
                            {{ headerCopy.contact }}
                        </a>
                        <a href="https://www.autoradiocanario.com/pages/quienes-somos" class="transition hover:text-amber-400">
                            {{ headerCopy.about }}
                        </a>
                    </nav>

                    <div class="ml-auto hidden items-center gap-4 lg:flex">
                        <label class="sr-only" for="header-language">Language</label>
                        <select
                            id="header-language"
                            :value="locale"
                            class="cursor-pointer border-0 bg-[#121212] py-2 pl-2 pr-7 text-sm text-white outline-none ring-0 focus:ring-0"
                            @change="changeHeaderLanguage"
                        >
                            <option value="es">Español</option>
                            <option value="en">English</option>
                            <option value="it">Italiano</option>
                        </select>

                        <a
                            :href="checkoutUrl || storefrontUrl('/cart')"
                            :aria-label="t('actions.checkout')"
                            class="rounded-md p-2 transition hover:bg-white/10 hover:text-amber-400"
                        >
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path d="M6.7 8.5h10.6l.75 11H5.95l.75-11Z"/>
                                <path d="M9 9V6.5a3 3 0 0 1 6 0V9"/>
                            </svg>
                        </a>

                    </div>
                </div>

                <nav
                    v-if="mobileHeaderOpen"
                    id="mobile-store-navigation"
                    class="grid gap-1 border-t border-neutral-800 pb-4 pt-3 text-sm lg:hidden"
                >
                    <a href="https://www.autoradiocanario.com/" class="rounded-lg px-3 py-3 hover:bg-white/5 hover:text-amber-400">
                        {{ headerCopy.home }}
                    </a>
                    <a :href="headerContactUrl" class="rounded-lg px-3 py-3 hover:bg-white/5 hover:text-amber-400">
                        {{ headerCopy.contact }}
                    </a>
                    <a href="https://www.autoradiocanario.com/pages/quienes-somos" class="rounded-lg px-3 py-3 hover:bg-white/5 hover:text-amber-400">
                        {{ headerCopy.about }}
                    </a>
                </nav>
            </div>
        </header>

        <main v-if="configuratorMode === null" class="mx-auto flex min-h-[calc(100vh-12rem)] max-w-5xl items-center px-4 py-12 sm:px-6 lg:px-8">
            <div class="w-full text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-400 sm:text-sm">{{ t('mode.eyebrow') }}</p>
                <h1 class="mx-auto mt-3 max-w-3xl text-3xl font-semibold tracking-tight sm:text-5xl">{{ t('mode.title') }}</h1>
                <p class="mx-auto mt-4 max-w-2xl text-sm leading-6 text-neutral-400 sm:text-base">{{ t('mode.description') }}</p>
                <div class="mx-auto mt-10 grid max-w-4xl gap-5 md:grid-cols-2">
                    <button type="button" class="group rounded-2xl border-2 border-neutral-700 bg-neutral-900 p-7 text-left transition hover:border-amber-400 hover:bg-amber-400/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 sm:p-9" @click="setConfiguratorMode('specific')">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-400 text-2xl text-black">🚘</span>
                        <span class="mt-6 block text-xl font-bold text-white sm:text-2xl">{{ t('mode.specific_title') }}</span>
                        <span class="mt-3 block text-sm leading-6 text-neutral-400">{{ t('mode.specific_description') }}</span>
                        <span class="mt-6 inline-flex items-center font-semibold text-amber-400">{{ t('mode.choose') }} <span class="ml-2 transition group-hover:translate-x-1">→</span></span>
                    </button>
                    <button type="button" class="group rounded-2xl border-2 border-neutral-700 bg-neutral-900 p-7 text-left transition hover:border-amber-400 hover:bg-amber-400/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 sm:p-9" @click="setConfiguratorMode('universal')">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-400 text-2xl text-black">📻</span>
                        <span class="mt-6 block text-xl font-bold text-white sm:text-2xl">{{ t('mode.universal_title') }}</span>
                        <span class="mt-3 block text-sm leading-6 text-neutral-400">{{ t('mode.universal_description') }}</span>
                        <span class="mt-6 inline-flex items-center font-semibold text-amber-400">{{ t('mode.choose') }} <span class="ml-2 transition group-hover:translate-x-1">→</span></span>
                    </button>
                </div>
            </div>
        </main>

        <div v-else class="mx-auto max-w-7xl px-4 py-8 pb-28 sm:px-6 lg:px-8 lg:pb-8">
            <div class="mb-8">
                <div v-if="isAdmin" class="mb-8 grid w-full grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <button
                        type="button"
                        class="inline-flex h-11 items-center justify-center whitespace-nowrap rounded-lg border border-amber-400 bg-transparent px-5 text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black"
                        @click="startCustomQuote"
                    >
                        {{ customQuoteCopy.button }}
                    </button>
                    <button
                        type="button"
                        :disabled="!hasSelectedProducts"
                        class="inline-flex h-11 items-center justify-center whitespace-nowrap rounded-lg border border-amber-400 bg-transparent px-5 text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-amber-400"
                        @click="quoteGenerationError = null; showQuoteModal = true"
                    >
                        {{ t('actions.create_quote') }}
                    </button>
                    <button
                        type="button"
                        :disabled="!sharedConfigurationPayload"
                        class="inline-flex h-11 items-center justify-center whitespace-nowrap rounded-lg border border-amber-400 bg-transparent px-5 text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-amber-400"
                        @click="copySharedConfigurationUrl"
                    >
                        {{ copySharedConfigurationStatus === 'copied' ? t('actions.copied') : copySharedConfigurationStatus === 'error' ? t('actions.copy_failed') : t('actions.copy_quote_link') }}
                    </button>
                    <a
                        href="/dashboard"
                        class="inline-flex h-11 items-center justify-center whitespace-nowrap rounded-lg border border-red-600 bg-red-600 px-5 text-sm font-semibold text-white transition hover:border-red-500 hover:bg-red-500"
                    >
                        {{ t('admin.dashboard') }}
                    </a>
                </div>

                <div class="mb-7 flex justify-center sm:justify-start">
                    <div class="inline-grid grid-cols-2 rounded-xl border border-neutral-700 bg-neutral-950 p-1" role="group" :aria-label="t('mode.switch_label')">
                        <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold transition sm:px-5 sm:text-sm" :class="isSpecificMode ? 'bg-amber-400 text-black' : 'text-neutral-300 hover:bg-neutral-800'" @click="setConfiguratorMode('specific')">{{ t('mode.specific_short') }}</button>
                        <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold transition sm:px-5 sm:text-sm" :class="isUniversalMode ? 'bg-amber-400 text-black' : 'text-neutral-300 hover:bg-neutral-800'" @click="setConfiguratorMode('universal')">{{ t('mode.universal_short') }}</button>
                    </div>
                </div>

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
                    <div class="mx-auto mt-4 w-64 max-w-full sm:mx-0 sm:w-full sm:max-w-[508px]">
                        <div class="relative">
                            <label for="customer-budget" class="sr-only">{{ t('budget.label') }}</label>
                            <input
                                id="customer-budget"
                                v-model="customerBudget"
                                type="text"
                                inputmode="decimal"
                                class="min-h-12 w-full rounded-lg border px-4 py-3 pr-11 text-lg font-semibold outline-none transition placeholder:text-sm placeholder:font-normal"
                                :class="normalizedBudget !== null
                                    ? 'border-emerald-400/60 bg-emerald-400/10 text-emerald-300 placeholder:text-emerald-200/60 focus:border-emerald-400'
                                    : 'border-amber-300/60 bg-amber-300/10 text-amber-200 placeholder:text-amber-200/70 focus:border-amber-300'"
                                :placeholder="t('budget.placeholder')"
                            />
                            <span
                                class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-lg font-bold"
                                :class="normalizedBudget !== null ? 'text-emerald-400' : 'text-amber-300'"
                            >€</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <section class="min-w-0 max-w-full rounded-2xl border border-neutral-800 bg-neutral-900/80 p-6">
                    <div
                        v-if="isSpecificMode && selectedBrand && selectedModel && selectedYear"
                        id="mobile-vehicle-marker"
                        class="mb-6 scroll-mt-2 overflow-hidden rounded-xl border border-neutral-700 bg-[#121212] lg:hidden"
                    >
                        <div class="flex min-w-0 items-center justify-center gap-2 border-b border-neutral-800 px-3 py-3">
                            <img
                                v-if="selectedBrandImageUrl && failedBrandImage !== selectedBrandImageUrl"
                                :src="selectedBrandImageUrl"
                                :alt="selectedBrand"
                                class="h-10 w-16 shrink-0 object-contain"
                                @error="failedBrandImage = selectedBrandImageUrl"
                            />
                            <span class="shrink-0 text-xs font-medium uppercase tracking-wider text-neutral-500">{{ selectedBrand }}</span>
                            <span class="min-w-0 truncate text-lg font-bold text-white">{{ displayVehicleModel(selectedModel) }}</span>
                            <span class="shrink-0 text-sm text-neutral-400">{{ selectedYear }}</span>
                        </div>
                        <button
                            v-if="selectedVehicleImageUrl && failedVehicleImage !== selectedVehicleImageUrl"
                            type="button"
                            class="flex h-52 w-full items-center justify-center p-3"
                            :aria-label="t('vehicle.zoom')"
                            @click="openImageZoom(selectedVehicleImageUrl, `${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}`)"
                        >
                            <img
                                :src="selectedVehicleImageUrl"
                                :alt="`${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}`"
                                class="h-full w-full object-contain"
                                @error="failedVehicleImage = selectedVehicleImageUrl"
                            />
                        </button>
                    </div>
                    <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6">
                        <button v-if="isSpecificMode" type="button" :class="mainStepButtonClass('vehicle')" @click="toggleStepAndCenter('vehicle', 'vehicle-brand', true)">
                            <span class="step-context-label">{{ stepContextLabel('vehicle') }}</span>
                            <span class="block max-w-full truncate whitespace-nowrap" :class="selectedBrand ? 'normal-case' : 'uppercase'">{{ selectedBrand ? vehicleStepTitle : `+ ${stepContextLabel('vehicle')}` }}</span>
                            <svg viewBox="0 0 24 24" fill="none" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 stroke-current transition-transform" :class="openSteps.includes('vehicle') ? '' : 'rotate-180'" aria-hidden="true">
                                <path d="m5 15 7-7 7 7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <div v-if="isSpecificMode && openSteps.includes('vehicle')">

                        <div
                            class="grid gap-4"
                            :class="selectedBrand ? 'md:grid-cols-[1fr_220px]' : ''"
                        >
                            <div>
                                <select
                                    id="vehicle-brand"
                                    v-model="selectedBrand"
                                    class="vehicle-option-select mobile-vehicle-select w-full rounded-lg border px-4 py-3 text-base outline-none transition sm:text-sm"
                                    :class="selectedBrand ? 'vehicle-select-complete border-emerald-400/60 bg-emerald-400/10 text-emerald-400' : 'vehicle-select-pending border-red-500/60 bg-red-500/10 text-red-300'"
                                    @change="handleVehicleBrandChange"
                                    @keydown="handleBrandTypeahead"
                                >
                                    <option :value="null">{{ t('fields.select_brand') }}</option>
                                    <option :value="missingBrandOption" class="missing-vehicle-option">
                                        {{ t('vehicle.brand_not_found') }}
                                    </option>
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
                                    id="vehicle-year"
                                    v-model="selectedYear"
                                    class="vehicle-option-select mobile-vehicle-select w-full rounded-lg border px-4 py-3 text-base outline-none transition sm:text-sm"
                                    :class="selectedYear !== null ? 'vehicle-select-complete border-emerald-400/60 bg-emerald-400/10 text-emerald-400' : 'vehicle-select-pending border-red-500/60 bg-red-500/10 text-red-300'"
                                    @change="handleVehicleYearChange"
                                >
                                    <option :value="null">{{ t('fields.select_year') }}</option>
                                    <option :value="missingYearOption" class="missing-vehicle-option">
                                        {{ t('vehicle.year_not_found') }}
                                    </option>
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

                        <div ref="modelSelectionSection" v-if="selectedBrand && selectedYear !== null" class="mobile-model-section grid gap-4 md:grid-cols-[minmax(0,1fr)_220px] md:items-end">
                            <div class="grid gap-3">
                                <label class="text-center text-sm font-medium text-neutral-300 md:text-left">{{ t('fields.model') }}</label>
                                <div class="mobile-model-buttons flex flex-wrap gap-2">
                                    <button
                                        v-for="model in models"
                                        :key="model ?? 'unknown-model'"
                                        type="button"
                                        @click="selectVehicleModel(model)"
                                        class="rounded-lg border px-4 py-3 text-sm transition"
                                        :class="
                                            selectedModel === model
                                                ? 'border-amber-400 bg-amber-400 text-black'
                                                : 'border-amber-400 bg-[#121212] text-amber-400 active:bg-amber-400 active:text-black md:hover:bg-amber-400 md:hover:text-black'
                                        "
                                    >
                                        {{ displayVehicleModel(model) }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mobile-missing-model-button rounded-lg border border-amber-400 bg-[#121212] px-4 py-3 text-sm text-amber-400 transition hover:bg-amber-400 hover:text-black"
                                        @click="openMissingModelForm"
                                    >
                                        {{ t('vehicle.model_not_found') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        </div>
                        <div
                            v-if="isUniversalMode || selectedModel"
                            class="border-t border-neutral-800 pt-6"
                        >
                            <button ref="screenStepButton" type="button" :class="mainStepButtonClass('screen')" @click="toggleScreenStep">
                                <span class="step-context-label">{{ stepContextLabel('screen') }}</span>
                                <span v-if="selectedScreenVariantTitles.length === 0" class="block max-w-full truncate whitespace-nowrap">{{ t('steps.screen') }}</span>
                                <span
                                    v-for="variantTitle in selectedScreenVariantTitles"
                                    v-else
                                    :key="variantTitle"
                                    class="block max-w-full truncate whitespace-nowrap normal-case"
                                >
                                    {{ variantTitle }}
                                </span>
                                <svg viewBox="0 0 24 24" fill="none" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 stroke-current transition-transform" :class="openSteps.includes('screen') ? '' : 'rotate-180'" aria-hidden="true">
                                    <path d="m5 15 7-7 7 7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <div v-if="openSteps.includes('screen')" id="screen-step-content" class="mt-6 min-w-0 max-w-full">
                            <div v-if="isUniversalMode" class="mb-6 rounded-xl border border-neutral-700 bg-[#121212] p-5">
                                <p class="text-center text-lg font-semibold text-white">{{ t('mode.din_question') }}</p>
                                <div class="mx-auto mt-4 grid max-w-lg grid-cols-2 gap-3">
                                    <button
                                        v-for="din in universalDinOptions"
                                        :key="din"
                                        type="button"
                                        class="rounded-xl border-2 px-4 py-5 text-lg font-bold transition"
                                        :class="selectedUniversalDin === din
                                            ? 'border-amber-400 bg-amber-400 text-black ring-2 ring-amber-400/30'
                                            : 'border-neutral-600 bg-neutral-900 text-white hover:border-amber-400 hover:text-amber-400'"
                                        @click="selectUniversalDin(din)"
                                    >
                                        {{ din === '1DIN' ? t('mode.one_din') : t('mode.two_din') }}
                                    </button>
                                </div>
                            </div>
                            <p
                                v-if="displayedScreenOptionCount > 0"
                                id="screen-availability-message"
                                class="mb-4 rounded-xl border border-emerald-400/40 bg-emerald-400/10 px-4 py-3 text-center text-sm font-semibold text-emerald-400"
                            >
                                {{ isUniversalMode
                                    ? (displayedScreenOptionCount === 1 ? t('screen.universal_available_option') : t('screen.universal_available_options', { count: displayedScreenOptionCount }))
                                    : (displayedScreenOptionCount === 1
                                        ? t('screen.available_option', { vehicle: `${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}` })
                                        : t('screen.available_options', { count: displayedScreenOptionCount, vehicle: `${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}` })) }}
                            </p>
                            <div v-if="hasNoSpecificScreenForBudget" id="screen-alternative-message" class="rounded-xl border border-neutral-700 bg-[#121212] p-5">
                                <p class="text-sm leading-6 text-neutral-200">{{ t('budget.no_specific_screen') }}</p>
                                <div v-if="lowestSpecificVariantPrice !== null" class="mt-3 flex flex-wrap items-center gap-3">
                                    <p class="text-sm font-semibold text-amber-400">
                                        {{ t('budget.lowest_specific_price', { amount: lowestSpecificVariantPrice.toFixed(2) }) }}
                                    </p>
                                    <button
                                        type="button"
                                        class="rounded-lg border border-amber-400 px-3 py-2 text-xs font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black"
                                        @click="useLowestSpecificVariantBudget"
                                    >
                                        {{ t('budget.show_lowest_variant') }}
                                    </button>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-3">
                                    <button type="button" class="rounded-lg bg-amber-400 px-5 py-2.5 text-sm font-semibold text-black transition hover:bg-amber-300" @click="setConfiguratorMode('universal')">
                                        {{ t('budget.show_universal_yes') }}
                                    </button>
                                    <button type="button" class="rounded-lg border border-neutral-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-neutral-800" @click="toggleStep('screen')">
                                        {{ t('budget.show_universal_no') }}
                                    </button>
                                </div>
                            </div>
                            <p v-else-if="isUniversalMode && selectedUniversalDin !== null && displayedScreenVehicles.length === 0" id="screen-alternative-message" class="rounded-xl border border-neutral-700 bg-[#121212] p-5 text-sm text-neutral-300">
                                {{ t('budget.no_universal_screen') }}
                            </p>
                            <div v-else-if="(isUniversalMode || selectedYear !== null) && displayedScreenVehicles.length" class="mt-4 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-5">
                                <p v-if="isUniversalMode" class="rounded-lg border border-amber-400/30 bg-amber-400/5 p-3 text-sm leading-6 text-amber-200">{{ t('mode.universal_notice') }}</p>
                                <article
                                    v-for="vehicle in displayedScreenVehicles"
                                    :key="vehicle.id"
                                    :id="`screen-product-${vehicle.id}`"
                                    class="grid min-w-0 max-w-full grid-cols-[minmax(0,1fr)] gap-5 overflow-hidden rounded-xl border-2 p-4 transition lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]"
                                    :class="
                                        selectedVehicle?.id === vehicle.id
                                            ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400'
                                            : 'border-neutral-700 bg-[#121212]'
                                    "
                                >
                                    <div v-if="requiresDashboardChoice(vehicle) && !selectedDashboardVariant(vehicle)" class="lg:col-span-2">
                                        <h3 class="mb-5 text-center text-xl font-semibold text-white">{{ t('screen.original_radio_question') }}</h3>
                                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            <button
                                                v-for="image in dashboardChoiceImages(vehicle)"
                                                :key="image.url"
                                                type="button"
                                                class="group rounded-xl border border-neutral-700 bg-neutral-950 p-3 text-center transition hover:border-amber-400 hover:bg-amber-400/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                                @click="selectDashboardVariant(vehicle, image.variant!)"
                                            >
                                                <img :src="image.url" :alt="dashboardVariantLabel(image.variant!)" class="h-64 w-full rounded-lg object-contain" />
                                                <span class="mt-3 block font-semibold text-amber-400">{{ dashboardVariantLabel(image.variant!) }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <template v-else>
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
                                        <div v-if="dashboardReferenceImages(vehicle).length" class="absolute bottom-3 left-3 right-3 flex min-w-0 flex-col items-start gap-2">
                                            <div class="flex max-w-full gap-2 overflow-x-auto">
                                                <button
                                                    v-for="image in dashboardReferenceImages(vehicle)"
                                                    :key="image.url"
                                                    type="button"
                                                    class="h-16 w-16 shrink-0 cursor-zoom-in overflow-hidden rounded-lg border-2 border-amber-400 bg-black shadow-lg sm:h-20 sm:w-20"
                                                    :aria-label="dashboardReferenceLabel(vehicle)"
                                                    @click.stop="openImageZoom(image.url, dashboardReferenceLabel(vehicle))"
                                                >
                                                    <img :src="image.url" :alt="dashboardReferenceLabel(vehicle)" class="h-full w-full object-cover" />
                                                </button>
                                            </div>
                                            <div class="flex w-full min-w-0 items-center gap-2">
                                                <span class="min-w-0 flex-1 truncate rounded bg-black/80 px-2 py-1 text-left text-[11px] font-semibold text-white backdrop-blur" :title="dashboardReferenceLabel(vehicle)">{{ dashboardReferenceLabel(vehicle) }}</span>
                                                <button
                                                    v-if="requiresDashboardChoice(vehicle) && selectedDashboardVariant(vehicle)"
                                                    type="button"
                                                    class="shrink-0 whitespace-nowrap rounded border border-amber-400 bg-black/85 px-2 py-1 text-[11px] font-semibold text-amber-400 shadow transition hover:bg-amber-400 hover:text-black"
                                                    @click.stop="changeDashboardVariant(vehicle)"
                                                >
                                                    {{ changeDashboardVariantLabel() }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="min-w-0">
                                        <h3 class="mb-3 text-base font-semibold text-white">
                                            {{ vehicle.title }}
                                        </h3>
                                        <div
                                            v-if="singleScreenChoice(vehicle)"
                                            class="rounded-xl border p-4"
                                            :class="isScreenChoiceSelected(singleScreenChoice(vehicle)!) ? 'border-emerald-400 bg-emerald-400/10' : 'border-neutral-700 bg-[#121212]'"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="truncate font-semibold text-white">{{ displayVariantTitle(singleScreenChoice(vehicle)!.title) }}</p>
                                                    <p class="mt-1 text-lg font-bold text-amber-400">{{ singleScreenChoice(vehicle)!.price.toFixed(2) }} €</p>
                                                </div>
                                                <div v-if="isScreenChoiceSelected(singleScreenChoice(vehicle)!)" class="flex shrink-0 items-center gap-1.5">
                                                    <input type="number" min="1" inputmode="numeric" class="h-11 w-14 rounded-lg border border-neutral-600 bg-black px-1 text-center text-lg font-bold text-white outline-none focus:border-emerald-400" :value="cartQuantity(`screen:${singleScreenChoice(vehicle)!.id}`)" @change="setCartQuantityFromInput(`screen:${singleScreenChoice(vehicle)!.id}`, $event)" />
                                                    <span class="grid gap-1"><button type="button" class="flex h-5 w-8 items-center justify-center rounded border border-neutral-600 text-xs" @click="setCartQuantity(`screen:${singleScreenChoice(vehicle)!.id}`, cartQuantity(`screen:${singleScreenChoice(vehicle)!.id}`) + 1)">▲</button><button type="button" class="flex h-5 w-8 items-center justify-center rounded border border-neutral-600 text-xs" @click="setCartQuantity(`screen:${singleScreenChoice(vehicle)!.id}`, cartQuantity(`screen:${singleScreenChoice(vehicle)!.id}`) - 1)">▼</button></span>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                class="mt-4 w-full rounded-lg px-4 py-3 font-bold transition"
                                                :class="isScreenChoiceSelected(singleScreenChoice(vehicle)!) ? 'border border-red-500 bg-red-500/10 text-red-300 hover:bg-red-500 hover:text-white' : 'bg-amber-400 text-black hover:bg-amber-300'"
                                                @click="toggleScreenChoiceInCart(singleScreenChoice(vehicle)!)"
                                            >
                                                {{ isScreenChoiceSelected(singleScreenChoice(vehicle)!) ? funnelCopy.remove : funnelCopy.add }}
                                            </button>
                                        </div>
                                        <div v-else-if="selectedScreensForVehicle(vehicle).length" class="rounded-xl border border-emerald-400/50 bg-emerald-400/10 p-4 lg:hidden">
                                            <p class="text-xs font-semibold uppercase tracking-wider text-emerald-400">{{ funnelCopy.selected }}</p>
                                            <div v-for="choice in selectedScreensForVehicle(vehicle)" :key="choice.id" class="mt-2 flex items-center justify-between gap-3">
                                                <span class="min-w-0 truncate font-semibold text-white">{{ displayVariantTitle(choice.title) }}</span>
                                                <span class="shrink-0 font-bold text-emerald-300">{{ choice.price.toFixed(2) }} € × {{ cartQuantity(`screen:${choice.id}`) }}</span>
                                            </div>
                                            <button type="button" class="mt-4 w-full rounded-lg border border-emerald-400 px-4 py-2.5 font-semibold text-emerald-300 transition hover:bg-emerald-400 hover:text-black" @click="openVariantPicker(vehicle)">
                                                {{ funnelCopy.change }}
                                            </button>
                                        </div>
                                        <button v-else type="button" class="w-full rounded-xl bg-amber-400 px-5 py-4 text-base font-bold text-black transition hover:bg-amber-300 lg:hidden" @click="openVariantPicker(vehicle)">
                                            {{ funnelCopy.choose }}
                                        </button>
                                        <div v-if="!singleScreenChoice(vehicle)" class="hidden space-y-2 lg:block">
                                            <div
                                                v-for="choice in screenChoicesForVehicle(vehicle)"
                                                :key="`desktop-screen-choice-${choice.id}`"
                                                class="flex min-h-12 items-center gap-3 rounded-lg border px-3 py-2 transition"
                                                :class="isScreenChoiceSelected(choice) ? 'border-emerald-400 bg-emerald-400/10' : 'border-neutral-700 bg-[#121212]'"
                                            >
                                                <button type="button" class="flex min-w-0 flex-1 items-center justify-between gap-3 text-left" @click="toggleScreenChoiceInCart(choice)">
                                                    <span class="min-w-0 truncate text-sm font-semibold text-white">{{ displayVariantTitle(choice.title) }}</span>
                                                    <span class="shrink-0 whitespace-nowrap text-sm font-bold" :class="isScreenChoiceSelected(choice) ? 'text-emerald-300' : 'text-amber-400'">{{ choice.price.toFixed(2) }} €</span>
                                                </button>
                                                <div v-if="isScreenChoiceSelected(choice)" class="flex shrink-0 items-center gap-1">
                                                    <button type="button" class="h-7 w-7 rounded border border-neutral-600 bg-black text-sm" @click="setCartQuantity(`screen:${choice.id}`, cartQuantity(`screen:${choice.id}`) - 1)">−</button>
                                                    <input type="number" min="1" inputmode="numeric" class="h-7 w-10 rounded border border-neutral-600 bg-black px-1 text-center text-sm font-bold text-white outline-none focus:border-emerald-400" :value="cartQuantity(`screen:${choice.id}`)" @change="setCartQuantityFromInput(`screen:${choice.id}`, $event)" />
                                                    <button type="button" class="h-7 w-7 rounded border border-neutral-600 bg-black text-sm" @click="setCartQuantity(`screen:${choice.id}`, cartQuantity(`screen:${choice.id}`) + 1)">+</button>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="shrink-0 rounded-md px-3 py-2 text-xs font-bold transition"
                                                    :class="isScreenChoiceSelected(choice) ? 'border border-red-500 bg-red-500/10 text-red-300 hover:bg-red-500 hover:text-white' : 'bg-amber-400 text-black hover:bg-amber-300'"
                                                    @click="toggleScreenChoiceInCart(choice)"
                                                >
                                                    {{ isScreenChoiceSelected(choice) ? '−' : '+' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    </template>
                                </article>
                            </div>
                            <div
                                v-else-if="isSpecificMode && selectedBrand && selectedModel && selectedYear"
                                id="screen-alternative-message"
                                class="mt-4 flex flex-col items-center gap-3 rounded-xl border border-red-500/60 bg-red-500/10 px-4 py-4 text-center text-sm"
                            >
                                <p class="font-semibold text-red-300">{{ t('screen.missing_message') }}</p>
                                <button
                                    type="button"
                                    class="rounded-lg bg-amber-400 px-5 py-3 font-semibold text-black transition hover:bg-amber-300"
                                    @click="openMissingScreenForm"
                                >{{ t('screen.missing_action') }}</button>
                            </div>
                            <p v-else-if="isSpecificMode" id="screen-alternative-message" class="mt-4 text-sm text-neutral-500">
                                {{ t('screen.select_vehicle') }}
                            </p>
                            </div>
                        </div>

                        <div
                            v-if="(isUniversalMode || selectedModel) && visibleCameraOptions.length > 0"
                            class="border-t border-neutral-800 pt-6"
                        >
                            <button type="button" :class="mainStepButtonClass('camera')" @click="toggleStepAndCenter('camera', 'camera-step-options', false, 'start')">
                                <span class="step-context-label">{{ stepContextLabel('camera') }}</span>
                                <span v-if="cameraStepTitles.length === 0" class="block max-w-full truncate whitespace-nowrap uppercase">{{ t('steps.camera') }}</span>
                                <span
                                    v-for="(cameraTitle, index) in cameraStepTitles"
                                    v-else
                                    :key="`${index}-${cameraTitle}`"
                                    class="block max-w-full truncate whitespace-nowrap normal-case sm:max-w-lg"
                                >
                                    {{ cameraTitle }}
                                </span>
                                <svg viewBox="0 0 24 24" fill="none" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 stroke-current transition-transform" :class="openSteps.includes('camera') ? '' : 'rotate-180'" aria-hidden="true">
                                    <path d="m5 15 7-7 7 7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <div v-if="openSteps.includes('camera')" id="camera-step-content" class="mt-6 min-w-0 max-w-full">
                            <div id="camera-step-options" class="mt-4 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-4 md:grid-cols-3">
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
                                    <div class="block min-w-0 w-full max-w-full overflow-hidden rounded-t-xl p-0 text-left">
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
                                    </div>

                                    <div v-if="camera.variants && camera.variants.length > 1" class="px-2 pt-2">
                                        <select
                                            :value="camera.variantId ?? camera.variants[0].id"
                                            class="w-full rounded-lg border border-neutral-700 bg-neutral-900 px-3 py-2 text-xs text-white outline-none focus:border-amber-400"
                                            @click.stop
                                            @change="selectCameraVariant(camera, $event)"
                                        >
                                            <option v-for="variant in camera.variants" :key="variant.id" :value="variant.id">
                                                {{ displayVariantTitle(variant.title) }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="block min-w-0 w-full max-w-full overflow-hidden rounded-b-xl p-3 text-left">
                                        <span class="flex min-w-0 items-center justify-between gap-1">
                                            <span class="min-w-0 flex-1 truncate whitespace-nowrap text-xs font-medium">{{ camera.isStandardFront ? t('camera.standard_front') : camera.title }}</span>
                                            <span class="shrink-0 whitespace-nowrap text-sm font-semibold">{{ (camera.price * cartQuantity(`camera:${camera.key}`)).toFixed(2) }} €</span>
                                        </span>
                                        <div v-if="selectedCameraKeys.includes(camera.key)" class="mt-3 flex items-center justify-end gap-1.5"><input type="number" min="1" max="4" inputmode="numeric" class="h-10 w-14 rounded-lg border border-neutral-600 bg-black text-center font-bold text-white" :value="cartQuantity(`camera:${camera.key}`)" @change="setCartQuantityFromInput(`camera:${camera.key}`, $event)" /><span class="grid gap-1"><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity(`camera:${camera.key}`, cartQuantity(`camera:${camera.key}`) + 1)">▲</button><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity(`camera:${camera.key}`, cartQuantity(`camera:${camera.key}`) - 1)">▼</button></span></div>
                                        <button type="button" class="mt-3 w-full rounded-lg px-3 py-2.5 text-sm font-bold transition" :class="selectedCameraKeys.includes(camera.key) ? 'border border-red-500 bg-red-500/10 text-red-300' : 'bg-amber-400 text-black'" @click="toggleCamera(camera.key)">{{ selectedCameraKeys.includes(camera.key) ? funnelCopy.remove : funnelCopy.add }}</button>
                                    </div>

                                    <div
                                        v-if="!camera.isStandard"
                                        class="pointer-events-none absolute -left-8 top-5 z-20 w-28 -rotate-45 bg-amber-400 px-2 py-1.5 text-center text-[10px] font-bold uppercase tracking-wide text-black shadow-lg"
                                    >
                                        {{ t('camera.specific_for_vehicle') }}
                                    </div>

                                    <a
                                        :href="productUrl(camera.productHandle ?? camera.key)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="absolute left-1/2 top-3 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg border border-amber-400 bg-[#121212]/90 px-3 py-2 text-xs font-semibold text-amber-400 shadow-lg backdrop-blur transition hover:bg-amber-400 hover:text-black"
                                    >
                                        {{ t('screen.product_details') }}
                                    </a>
                                </div>
                            </div>
                            <p
                                v-if="isSpecificMode && selectedBrand && selectedModel && selectedYear && !hasSpecificCameraOption"
                                class="mt-4 flex w-full flex-col items-center gap-1 text-center text-sm text-neutral-300"
                            >
                                <span>{{ t('camera.missing_question') }}</span>
                                <button
                                    type="button"
                                    class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    @click="openMissingVehicleForm"
                                >{{ t('camera.missing_action') }}</button>
                            </p>
                            </div>
                        </div>

                        <div
                            v-if="isUniversalMode || isSpecificMode"
                            class="border-t border-neutral-800 pt-6"
                        >
                            <button type="button" :class="mainStepButtonClass('speaker')" @click="toggleStepAndCenter('speaker', 'speaker-step-controls')">
                                <span class="step-context-label">{{ stepContextLabel('speaker') }}</span>
                                <span v-if="speakerStepTitles.length === 0" class="block max-w-full truncate whitespace-nowrap uppercase">{{ t('steps.speaker') }}</span>
                                <span
                                    v-for="(speakerTitle, index) in speakerStepTitles"
                                    v-else
                                    :key="`${index}-${speakerTitle}`"
                                    class="block max-w-full truncate whitespace-nowrap normal-case sm:max-w-lg"
                                >
                                    {{ speakerTitle }}
                                </span>
                                <svg viewBox="0 0 24 24" fill="none" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 stroke-current transition-transform" :class="openSteps.includes('speaker') ? '' : 'rotate-180'" aria-hidden="true">
                                    <path d="m5 15 7-7 7 7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <div v-if="openSteps.includes('speaker')" id="speaker-step-content" class="mt-6 min-w-0 max-w-full">
                            <div id="speaker-step-controls" class="mobile-model-section mt-4 grid min-w-0 max-w-2xl grid-cols-[minmax(0,1fr)] gap-4">
                                <div>
                                    <div class="mobile-model-buttons flex flex-wrap gap-2">
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
                                <div v-if="selectedSpeakerCategory" id="speaker-size-step">
                                <p class="mb-2 block text-sm font-medium text-neutral-300">
                                    {{ t('speaker.size') }}
                                </p>
                                <div class="mobile-model-buttons flex flex-wrap gap-2">
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

                            <div v-if="visibleSpeakerOptions.length" id="speaker-options-step" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <article
                                    v-for="speaker in visibleSpeakerOptions"
                                    :key="speaker.key"
                                    :id="`product-speaker-${speaker.key}`"
                                    class="group relative overflow-hidden rounded-xl border transition"
                                    :class="selectedSpeakerKeys.includes(speaker.key) ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-800 bg-[#121212] hover:border-neutral-700'"
                                >
                                    <div class="grid h-full w-full gap-3 p-4 pt-16 text-left">
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
                                            <p class="mt-2 text-lg font-semibold">{{ (speaker.price * cartQuantity(`speaker:${speaker.key}`)).toFixed(2) }} €</p>
                                        </div>
                                        <div v-if="selectedSpeakerKeys.includes(speaker.key)" class="flex items-center justify-end gap-1.5"><input type="number" min="1" max="4" inputmode="numeric" class="h-10 w-14 rounded-lg border border-neutral-600 bg-black text-center font-bold text-white" :value="cartQuantity(`speaker:${speaker.key}`)" @change="setCartQuantityFromInput(`speaker:${speaker.key}`, $event)" /><span class="grid gap-1"><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity(`speaker:${speaker.key}`, cartQuantity(`speaker:${speaker.key}`) + 1)">▲</button><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity(`speaker:${speaker.key}`, cartQuantity(`speaker:${speaker.key}`) - 1)">▼</button></span></div>
                                        <button type="button" class="w-full rounded-lg px-3 py-2.5 text-sm font-bold transition" :class="selectedSpeakerKeys.includes(speaker.key) ? 'border border-red-500 bg-red-500/10 text-red-300' : 'bg-amber-400 text-black'" @click="toggleSpeaker(speaker.key)">{{ selectedSpeakerKeys.includes(speaker.key) ? funnelCopy.remove : funnelCopy.add }}</button>
                                    </div>
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
                            <p v-else-if="selectedSpeakerCategory && selectedSpeakerSizes" id="speaker-options-step" class="mt-4 text-sm text-neutral-500">
                                {{ t('speaker.no_options') }}
                            </p>
                            </div>
                        </div>

                        <div
                            v-if="hasSelectedProducts"
                            class="border-t border-neutral-800 pt-6"
                        >
                            <button type="button" :class="mainStepButtonClass('installation')" @click="toggleStepAndCenter('installation', 'postal-code', true); installationRequested = true">
                                <span class="step-context-label">{{ stepContextLabel('installation') }}</span>
                                <span class="block max-w-full truncate whitespace-nowrap sm:max-w-lg" :class="selectedInstallation ? 'normal-case' : 'uppercase'">{{ installationStepTitle }}</span>
                                <svg viewBox="0 0 24 24" fill="none" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 stroke-current transition-transform" :class="openSteps.includes('installation') ? '' : 'rotate-180'" aria-hidden="true">
                                    <path d="m5 15 7-7 7 7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <div v-if="openSteps.includes('installation')" id="installation-step-content" class="mt-6">
                            <p v-if="hasSelectedProducts" class="mt-2 max-w-3xl text-sm leading-6 text-neutral-400">
                                {{ t('installation.intro') }}
                            </p>
                            <p
                                v-if="hasSelectedProducts"
                                class="mt-3 max-w-3xl rounded-lg border border-amber-400/30 bg-amber-400/5 p-3 text-sm leading-6 text-amber-200"
                            >
                                {{ t('quote.installation_payment_notice') }}
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
                                        @input="handlePostalCodeInput"
                                        @keyup.enter="checkPostalCode"
                                    />
                                    <button id="postal-code-check" type="button" class="rounded-lg bg-amber-400 px-5 py-3 text-sm font-semibold text-black transition hover:bg-amber-300" @click="checkPostalCode">
                                        {{ t('installation.check') }}
                                    </button>
                                </div>
                                <p v-if="postalCodeError" id="installation-availability-message" class="mt-3 text-sm text-red-400">{{ postalCodeError }}</p>
                                <div v-else-if="checkedPostalCode && matchedInstallationZone" id="installation-availability-message" class="mt-3 text-sm text-emerald-400">
                                    <p>{{ t('installation.available_zone', { zone: matchedInstallationZone.name }) }}</p>
                                    <div v-if="matchedInstallationZone.installerAddress || matchedInstallationZone.installerPhone" class="mt-2 grid gap-1 text-neutral-300">
                                        <p v-if="matchedInstallationZone.installerAddress">📍 {{ matchedInstallationZone.installerAddress }}</p>
                                        <a v-if="matchedInstallationZone.installerPhone" :href="`tel:${matchedInstallationZone.installerPhone}`" class="w-fit font-semibold text-amber-400 underline underline-offset-2">☎ {{ matchedInstallationZone.installerPhone }}</a>
                                    </div>
                                </div>
                                <p v-else-if="checkedPostalCode" id="installation-availability-message" class="mt-3 rounded-lg border border-red-500/60 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                                    {{ t('installation.unavailable') }}
                                    <a
                                        :href="headerContactUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold underline underline-offset-2 hover:text-red-200"
                                    >{{ t('installation.contact_details_link') }}</a>
                                    {{ t('installation.contact_details_suffix') }}.
                                </p>
                                <p v-else class="mt-3 text-sm text-neutral-500">
                                    {{ t('installation.check_to_view') }}
                                </p>
                            </div>

                            <div class="mt-4 grid gap-5 lg:grid-cols-[1.05fr_.95fr]">
                            <div v-if="checkedPostalCode && hasThreeStepInstallationFlow && hasSelectedProducts" class="contents">
                                <div v-if="hasSelectedProducts" id="installation-zone-step" class="rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:p-6">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-400">
                                        {{ t('installation.zone_step', { step: 1 }) }}
                                    </p>
                                    <h3 v-if="isGranCanaria" class="mt-2 text-xl font-semibold text-white">
                                        {{ t('installation.zone_question') }}
                                    </h3>
                                    <p v-if="isGranCanaria" class="mt-2 text-sm leading-6 text-neutral-400">
                                        {{ t('installation.zone_help') }}
                                    </p>

                                    <div v-if="isGranCanaria" class="relative mx-auto mt-5 max-w-sm overflow-hidden rounded-xl">
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

                                    <div v-if="isGranCanaria" class="mt-4 grid gap-2">
                                        <button
                                            v-for="zone in serviceZones"
                                            :key="zone.key"
                                            type="button"
                                            class="rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === zone.key ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone(zone.key)"
                                        >
                                            <span class="block">{{ zone.label }}</span>
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide" :class="selectedServiceZone === zone.key ? 'text-black/70' : 'text-amber-400'">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </div>

                                    <template v-else-if="isTenerife">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.tenerife_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.tenerife_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'tenerife' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('tenerife')"
                                        >
                                            <img src="/images/installation/tenerife-san-isidro-zone.png" :alt="t('installation.tenerife_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'tenerife' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('tenerife')"
                                        >
                                            {{ t('installation.tenerife_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else-if="isFuerteventura">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.fuerteventura_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.fuerteventura_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'fuerteventura' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('fuerteventura')"
                                        >
                                            <img src="/images/installation/fuerteventura-zone.png" :alt="t('installation.fuerteventura_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'fuerteventura' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('fuerteventura')"
                                        >
                                            {{ t('installation.fuerteventura_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else-if="isLanzarote">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.lanzarote_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.lanzarote_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'lanzarote' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('lanzarote')"
                                        >
                                            <img src="/images/installation/lanzarote-zone.png" :alt="t('installation.lanzarote_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'lanzarote' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('lanzarote')"
                                        >
                                            {{ t('installation.lanzarote_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else-if="isMadrid">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.madrid_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.madrid_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'madrid' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('madrid')"
                                        >
                                            <img src="/images/installation/madrid-humanes-zone.png" :alt="t('installation.madrid_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'madrid' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('madrid')"
                                        >
                                            {{ t('installation.madrid_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else-if="isBarcelona">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.barcelona_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.barcelona_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'barcelona' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('barcelona')"
                                        >
                                            <img src="/images/installation/barcelona-hospitalet-zone.png" :alt="t('installation.barcelona_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'barcelona' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('barcelona')"
                                        >
                                            {{ t('installation.barcelona_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else-if="isMalaga">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.malaga_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.malaga_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'malaga' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('malaga')"
                                        >
                                            <img src="/images/installation/malaga-moraima-zone.png" :alt="t('installation.malaga_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'malaga' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('malaga')"
                                        >
                                            {{ t('installation.malaga_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else-if="isMurcia">
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.murcia_zone_title') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('installation.murcia_zone_help') }}</p>
                                        <button
                                            type="button"
                                            class="relative mx-auto mt-5 block max-w-sm overflow-hidden rounded-xl border transition"
                                            :class="selectedServiceZone === 'murcia' ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400' : 'border-neutral-700 hover:border-amber-400'"
                                            @click="toggleServiceZone('murcia')"
                                        >
                                            <img src="/images/installation/murcia-center-zone.png" :alt="t('installation.murcia_map_label')" class="h-auto w-full" />
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-4 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'murcia' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('murcia')"
                                        >
                                            {{ t('installation.murcia_zone_option') }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </button>
                                    </template>
                                    <template v-else>
                                        <h3 class="mt-2 text-xl font-semibold text-white">{{ t('installation.zone_question') }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-neutral-400">
                                            {{ matchedInstallationZone?.name }}
                                            <span class="mt-1 block text-xs font-bold uppercase tracking-wide">{{ t('installation.click_here') }}</span>
                                        </p>
                                        <button
                                            type="button"
                                            class="mt-5 w-full rounded-xl border px-4 py-4 text-left text-sm font-medium transition"
                                            :class="selectedServiceZone === 'configured' ? 'border-amber-400 bg-amber-400 text-black' : 'border-neutral-700 bg-neutral-900 text-neutral-200 hover:border-amber-400'"
                                            @click="toggleServiceZone('configured')"
                                        >
                                            {{ matchedInstallationZone?.name }}
                                        </button>
                                    </template>
                                </div>

                                <div v-if="requiresPrecheck" id="installation-precheck-step" class="rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:p-6">
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
                                            <span class="mt-3 block text-sm font-bold text-emerald-400">{{ t('installation.precheck_free_action') }}</span>
                                        </button>
                                        <div
                                            class="rounded-xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                                            :class="selectedPrecheckMethod === 'installer' ? 'border-amber-400 bg-amber-400/10' : 'border-neutral-700 bg-neutral-900 hover:border-amber-400'"
                                        >
                                            <span class="block font-semibold text-white">{{ t('installation.precheck_installer_title') }}</span>
                                            <span class="mt-1 block text-sm leading-5 text-neutral-400">{{ t('installation.precheck_installer_description') }}</span>
                                            <span class="mt-3 block whitespace-nowrap text-sm font-bold text-amber-400">{{ (precheckProduct?.price ?? 25).toFixed(2) }} €</span>
                                            <div v-if="selectedPrecheckMethod === 'installer'" class="mt-3 flex items-center justify-end gap-1.5"><input type="number" min="1" max="4" inputmode="numeric" class="h-10 w-14 rounded-lg border border-neutral-600 bg-black text-center font-bold text-white" :value="cartQuantity('precheck:installer')" @change="setCartQuantityFromInput('precheck:installer', $event)" /><span class="grid gap-1"><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity('precheck:installer', cartQuantity('precheck:installer') + 1)">▲</button><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity('precheck:installer', cartQuantity('precheck:installer') - 1)">▼</button></span></div>
                                            <button type="button" class="mt-3 w-full rounded-lg px-3 py-2.5 text-sm font-bold transition disabled:opacity-40" :disabled="!selectedServiceZone" :class="selectedPrecheckMethod === 'installer' ? 'border border-red-500 bg-red-500/10 text-red-300' : 'bg-amber-400 text-black'" @click="togglePrecheckMethod('installer')">{{ selectedPrecheckMethod === 'installer' ? funnelCopy.remove : funnelCopy.add }}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                id="installation-final-step"
                                v-if="
                                    (!hasSelectedProducts && checkedPostalCode && matchedInstallationZone) ||
                                    (
                                        hasSelectedProducts &&
                                        checkedPostalCode &&
                                        matchedInstallationZone &&
                                        (!hasThreeStepInstallationFlow || selectedServiceZone) &&
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
                                    class="relative overflow-hidden rounded-xl border transition"
                                    :class="
                                        selectedInstallationKey === installation.key
                                            ? 'border-amber-400 bg-amber-400/10 ring-1 ring-amber-400'
                                            : 'border-neutral-700 bg-neutral-900 hover:border-amber-400'
                                    "
                                >
                                    <div class="absolute left-4 top-4 flex items-center gap-2" aria-hidden="true">
                                        <span
                                            v-if="installationIcons(installation).speaker"
                                            class="grid size-10 place-items-center rounded-lg bg-amber-400 text-black shadow-sm shadow-black/30"
                                        >
                                            <svg viewBox="0 0 32 32" class="size-7" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <circle cx="16" cy="16" r="10.5" stroke="currentColor" stroke-width="2" />
                                                <circle cx="16" cy="16" r="6.5" stroke="currentColor" stroke-width="2" />
                                                <circle cx="16" cy="16" r="2.2" fill="currentColor" />
                                                <path d="M10.4 8.1 21.6 23.9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity=".7" />
                                            </svg>
                                        </span>
                                        <span
                                            v-if="installationIcons(installation).camera"
                                            class="grid size-10 place-items-center rounded-lg bg-amber-400 text-black shadow-sm shadow-black/30"
                                        >
                                            <svg viewBox="0 0 32 32" class="size-7" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <rect x="6" y="7" width="20" height="18" rx="5" stroke="currentColor" stroke-width="2" />
                                                <circle cx="16" cy="16" r="4.5" stroke="currentColor" stroke-width="2" />
                                                <circle cx="23" cy="10.5" r="1.4" fill="currentColor" />
                                            </svg>
                                        </span>
                                        <span
                                            v-if="installationIcons(installation).screen"
                                            class="grid size-10 place-items-center rounded-lg bg-amber-400 text-black shadow-sm shadow-black/30"
                                        >
                                            <svg viewBox="0 0 32 32" class="size-7" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <rect x="5" y="7" width="22" height="15" rx="2" stroke="currentColor" stroke-width="2" />
                                                <path d="M16 22v4M11.5 26h9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="grid min-h-40 w-full content-end gap-2 p-4 pt-16 text-left">
                                        <p class="text-sm font-medium text-neutral-100">
                                            {{ installation.title }}
                                        </p>
                                        <p class="text-lg font-semibold text-white">
                                            {{ (installation.price * cartQuantity(`installation:${installation.key}`)).toFixed(2) }} €
                                        </p>
                                        <div v-if="selectedInstallationKey === installation.key" class="flex items-center justify-end gap-1.5"><input type="number" min="1" max="4" inputmode="numeric" class="h-10 w-14 rounded-lg border border-neutral-600 bg-black text-center font-bold text-white" :value="cartQuantity(`installation:${installation.key}`)" @change="setCartQuantityFromInput(`installation:${installation.key}`, $event)" /><span class="grid gap-1"><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity(`installation:${installation.key}`, cartQuantity(`installation:${installation.key}`) + 1)">▲</button><button type="button" class="h-[18px] w-7 rounded border border-neutral-600 text-[10px]" @click="setCartQuantity(`installation:${installation.key}`, cartQuantity(`installation:${installation.key}`) - 1)">▼</button></span></div>
                                        <button type="button" class="mt-1 w-full rounded-lg px-3 py-2.5 text-sm font-bold transition" :class="selectedInstallationKey === installation.key ? 'border border-red-500 bg-red-500/10 text-red-300' : 'bg-amber-400 text-black'" @click="selectedInstallationKey = selectedInstallationKey === installation.key ? null : installation.key">{{ selectedInstallationKey === installation.key ? funnelCopy.remove : funnelCopy.add }}</button>
                                    </div>
                                </article>
                            </div>
                                <p v-if="hasSelectedProducts && !visibleInstallationOptions.length" class="mt-4 text-sm text-amber-400">
                                    {{ t('installation.contact_for_combination') }}
                                </p>
                                <div v-if="hasSelectedProducts && !visibleInstallationOptions.length" class="mt-3 flex flex-wrap gap-3 text-sm">
                                    <a
                                        :href="`mailto:${footerContact.email}`"
                                        class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    >{{ t('installation.contact_email') }}: {{ footerContact.email }}</a>
                                    <a
                                        :href="footerContact.whatsappUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-semibold text-amber-400 underline underline-offset-2 hover:text-amber-300"
                                    >{{ t('installation.contact_whatsapp') }}: {{ footerContact.phone }}</a>
                                </div>

                            </div>
                            </div>
                            </div>
                        </div>
                    </div>
                </section>

                <aside ref="quotePanel" class="hidden flex-col overflow-hidden rounded-2xl border border-neutral-800 bg-[#121212] p-4 lg:sticky lg:top-6 lg:flex lg:h-[calc(100vh-3rem)] lg:self-start">
                    <div class="shrink-0 overflow-hidden rounded-xl border border-neutral-800 bg-[#121212]">
                        <div
                            v-if="isSpecificMode && selectedBrand"
                            class="grid"
                        >
                            <div class="flex min-w-0 flex-col items-center gap-2 border-b border-neutral-800 bg-[#121212] p-4 sm:flex-row sm:gap-4">
                                <img
                                    v-if="selectedBrandImageUrl && failedBrandImage !== selectedBrandImageUrl"
                                    :src="selectedBrandImageUrl"
                                    :alt="selectedBrand"
                                    class="h-16 w-24 shrink-0 object-contain object-center sm:h-12 sm:w-16"
                                    @error="failedBrandImage = selectedBrandImageUrl"
                                />
                                <div class="hidden min-w-0 sm:block">
                                    <p class="truncate text-xs font-medium uppercase tracking-wider text-neutral-500">
                                        {{ selectedBrand }}
                                    </p>
                                    <div class="mt-1 flex min-w-0 items-baseline gap-2">
                                        <p v-if="selectedModel" class="min-w-0 truncate text-lg font-semibold text-white">
                                            {{ displayVehicleModel(selectedModel) }}
                                        </p>
                                        <p v-if="selectedYear" class="shrink-0 text-sm text-neutral-400">
                                            {{ selectedYear }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex min-w-0 max-w-full items-baseline justify-center gap-2 sm:hidden">
                                    <p class="shrink-0 truncate text-xs font-medium uppercase tracking-wider text-neutral-500">{{ selectedBrand }}</p>
                                    <p v-if="selectedModel" class="min-w-0 truncate text-lg font-semibold text-white">{{ displayVehicleModel(selectedModel) }}</p>
                                    <p v-if="selectedYear" class="shrink-0 text-sm text-neutral-400">{{ selectedYear }}</p>
                                </div>
                            </div>

                            <div class="flex h-44 min-w-0 items-center justify-center bg-[#121212] p-1 lg:h-24 xl:h-28">
                                <button
                                    v-if="selectedVehicleImageUrl && failedVehicleImage !== selectedVehicleImageUrl"
                                    type="button"
                                    class="flex h-full w-full cursor-zoom-in items-center justify-center"
                                    :aria-label="t('vehicle.zoom')"
                                    @click="openImageZoom(selectedVehicleImageUrl, `${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}`)"
                                >
                                    <img
                                        :src="selectedVehicleImageUrl"
                                        :alt="`${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}`"
                                        class="h-full w-full object-contain"
                                        @error="failedVehicleImage = selectedVehicleImageUrl"
                                    />
                                </button>
                                <img
                                    v-else
                                    src="/images/logo.png"
                                    alt=""
                                    class="h-full w-full object-contain p-6 opacity-80"
                                />
                            </div>
                        </div>
                        <div v-else class="flex h-44 items-center justify-center bg-[#121212] p-1 lg:h-24 xl:h-28">
                            <img
                                src="/images/logo.png"
                                alt=""
                                class="h-full w-full object-contain p-6 opacity-80"
                            />
                        </div>
                    </div>

                    <div ref="quoteSummaryScroll" class="quote-scrollbar quote-summary-scrollbar lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pr-2">
                    <div>
                    <p id="full-quote-summary" class="mt-6 text-sm font-semibold uppercase tracking-[0.24em] text-amber-400">
                        {{ t('quote.title') }}
                    </p>

                    <div
                        v-if="selectedScreens.length || selectedCameras.length || selectedSpeakers.length"
                        class="mt-4 rounded-xl border px-4 py-3 text-sm"
                        :class="
                            customDiscount || activeDiscount
                                ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
                                : 'border-amber-400/40 bg-amber-400/10 text-amber-300'
                        "
                    >
                        <template v-if="customDiscount">
                            {{ discountLabel }}
                        </template>
                        <template v-else-if="!activeDiscount && nextDiscount">
                            {{ automaticDiscountRemainingMessage(nextDiscount) }}
                        </template>
                        <template v-else-if="activeDiscount">
                            {{ automaticDiscountAppliedMessage(activeDiscount) }}
                            <span v-if="nextDiscount" class="mt-1 block text-emerald-200/80">
                                {{ automaticDiscountRemainingMessage(nextDiscount) }}
                            </span>
                        </template>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div class="space-y-3 rounded-xl border border-neutral-800 bg-neutral-900 p-4">
                            <div
                                v-for="screen in selectedScreens"
                                :key="screen.id"
                                class="flex items-start justify-between gap-4"
                            >
                                <button type="button" class="summary-remove-button" :aria-label="t('actions.remove_product')" @click="removeSelectedScreen(screen.id)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        class="summary-product-link"
                                        @click="goToSelectedProduct('screen', screen.id)"
                                    >
                                        <span>{{ displayVariantTitle(screen.title) }}</span>
                                    </button>
                                    <p class="text-sm text-neutral-500">
                                        {{ t('screen.label') }}<span v-if="screen.color"> · {{ t('screen.color') }}: {{ screen.color }}</span>
                                    </p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ screen.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div
                                v-for="speaker in selectedSpeakers"
                                :key="speaker.key"
                                class="flex items-start justify-between gap-4"
                            >
                                <button type="button" class="summary-remove-button" :aria-label="t('actions.remove_product')" @click="toggleSpeaker(speaker.key)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        class="summary-product-link"
                                        @click="goToSelectedProduct('speaker', speaker.key)"
                                    >
                                        <span>{{ speaker.productTitle }}</span>
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('speaker.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ speaker.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div
                                v-for="camera in selectedCameras"
                                :key="camera.key"
                                class="flex items-start justify-between gap-4"
                            >
                                <button type="button" class="summary-remove-button" :aria-label="t('actions.remove_product')" @click="toggleCamera(camera.key)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        class="summary-product-link"
                                        @click="goToSelectedProduct('camera', camera.key)"
                                    >
                                        <span>{{ camera.title }}</span>
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('camera.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ camera.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div
                                v-for="product in selectedCustomProducts"
                                :key="product.key"
                                class="flex items-start justify-between gap-4"
                            >
                                <button type="button" class="summary-remove-button" :aria-label="t('actions.remove_product')" @click="toggleCustomProduct(product.key)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-neutral-100">
                                        {{ product.title }}<span v-if="product.variantTitle"> — {{ displayVariantTitle(product.variantTitle) }}</span>
                                    </p>
                                    <p class="text-sm text-neutral-500">{{ product.sku || product.category }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">{{ product.price.toFixed(2) }} €</p>
                            </div>

                            <div v-if="selectedInstallation" class="flex items-start justify-between gap-4">
                                <button type="button" class="summary-remove-button" :aria-label="t('actions.remove_product')" @click="selectedInstallationKey = null">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        class="summary-product-link"
                                        @click="goToSelectedProduct('installation', selectedInstallation.key)"
                                    >
                                        <span>{{ selectedInstallation.title }}</span>
                                    </button>
                                    <p class="text-sm text-neutral-500">{{ t('installation.label') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold">
                                    {{ selectedInstallation.price.toFixed(2) }} €
                                </p>
                            </div>

                            <div v-if="selectedPrecheckMethod === 'installer'" class="flex items-start justify-between gap-4">
                                <button type="button" class="summary-remove-button lg:hidden" :aria-label="t('actions.remove_product')" @click="removePrecheck">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button type="button" class="summary-product-link lg:hidden" @click="goToPrecheckStep">
                                        {{ t('installation.precheck_installer_summary') }}
                                    </button>
                                    <p class="hidden font-medium text-neutral-100 lg:block">{{ t('installation.precheck_installer_summary') }}</p>
                                </div>
                                <p class="shrink-0 whitespace-nowrap font-semibold text-white">
                                    {{ precheckPrice.toFixed(2) }} €
                                </p>
                            </div>
                        </div>

                    </div>
                    </div>

                    <div ref="quoteTotals" class="mt-2 border-t border-neutral-800 bg-[#121212] pt-2">
                        <div class="shrink-0 rounded-xl border border-neutral-800 bg-neutral-900 p-2.5">
                            <div class="flex items-center justify-between text-sm text-neutral-400">
                                <span>{{ t('quote.subtotal') }}</span>
                                <span>{{ productsSubtotal.toFixed(2) }} €</span>
                            </div>
                            <div
                                v-if="discountAmount > 0"
                                class="mt-2 flex items-center justify-between text-sm text-emerald-400"
                            >
                                <span>{{ discountLabel }}</span>
                                <span>−{{ discountAmount.toFixed(2) }} €</span>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3 border-t border-neutral-700 pt-3">
                                <span class="text-base font-semibold text-white">{{ t('quote.estimated_total') }}</span>
                                <span class="shrink-0 whitespace-nowrap text-xl font-bold text-white">
                                    {{ estimatedTotal.toFixed(2) }} €
                                </span>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-amber-400/50 bg-amber-400/10 px-3 py-3">
                                <span class="text-base font-semibold text-white">{{ t('quote.online_total') }}</span>
                                <span class="shrink-0 whitespace-nowrap text-2xl font-bold text-amber-400">
                                    {{ onlineTotal.toFixed(2) }} €
                                </span>
                            </div>
                            <div v-if="installationCost > 0" class="mt-2 rounded-lg border border-sky-400/30 bg-sky-400/5 px-3 py-3">
                                <div class="flex items-center justify-between gap-4 text-sm text-sky-200">
                                    <span class="font-semibold">{{ t('quote.installation_direct') }}</span>
                                    <span class="shrink-0 whitespace-nowrap text-lg font-bold">{{ installationCost.toFixed(2) }} €</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2 pt-2">
                        <div class="flex flex-col items-center justify-center rounded-lg border border-amber-400/60 bg-amber-400/10 px-3 py-2 text-center text-xs font-semibold text-amber-300">
                            <span class="flex items-center justify-center gap-2">
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M10 17h4V5H2v12h3" />
                                    <path d="M14 9h4l4 4v4h-3" />
                                    <circle cx="7.5" cy="17.5" r="2.5" />
                                    <circle cx="16.5" cy="17.5" r="2.5" />
                                </svg>
                                <span>{{ t('quote.home_delivery_title') }}</span>
                            </span>
                            <span>{{ t('quote.home_delivery_estimate') }}</span>
                            <span class="mt-0.5 text-amber-200/80">{{ t('quote.shipping_tracking') }}</span>
                        </div>

                        <div class="rounded-lg border border-neutral-700 bg-neutral-900/70 px-3 py-3 text-center">
                            <p class="text-sm font-semibold text-amber-400">{{ t('quote.trust_title') }}</p>
                            <p class="mt-1 text-xs leading-5 text-neutral-400">{{ t('quote.trust_details') }}</p>
                        </div>

                        <label ref="checkoutConsentSection" class="flex cursor-pointer items-start gap-2 rounded-lg px-1 text-xs leading-5 text-neutral-400 transition" :class="checkoutConsentAttention ? 'bg-amber-400/15 p-3 ring-2 ring-amber-400' : ''">
                            <input
                                v-model="checkoutConsentAccepted"
                                type="checkbox"
                                required
                                class="sr-only"
                            />
                            <span
                                class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition"
                                :class="checkoutConsentAccepted ? 'border-neutral-400 bg-neutral-300 text-black' : 'border-neutral-600 bg-transparent'"
                                aria-hidden="true"
                            >
                                <svg v-if="checkoutConsentAccepted" class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3">
                                    <path d="m4 10 4 4 8-9" />
                                </svg>
                            </span>
                            <span>
                                {{ t('checkout_consent.checkbox') }}
                                <a
                                    href="https://www.autoradiocanario.com/policies/terms-of-service"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="ml-1 text-neutral-300 underline underline-offset-2 hover:text-white"
                                    @click.stop
                                >
                                    <br>{{ props.locale === 'es' ? 'Ver condiciones' : props.locale === 'it' ? 'Vedi condizioni' : 'View terms' }}
                                </a>
                            </span>
                        </label>

                        <p class="px-2 text-center text-xs leading-5 text-neutral-400">
                            {{ t('quote.checkout_trust') }}
                        </p>

                        <button
                            type="button"
                            @click="goToCheckout"
                            class="order-2 flex h-12 w-full items-center justify-center rounded-xl bg-red-600 text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canCheckout"
                            :aria-label="t('actions.add_to_cart')"
                            :title="t('actions.add_to_cart')"
                        >
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>
                        </button>

                        <button
                            type="button"
                            class="order-1 flex h-12 w-full items-center justify-center rounded-xl border border-amber-400 text-amber-400 transition hover:bg-amber-400 hover:text-black disabled:cursor-not-allowed disabled:border-neutral-700 disabled:text-neutral-600"
                            :disabled="!hasSelectedProducts"
                            :aria-label="t('actions.download_quote')"
                            :title="t('actions.download_quote')"
                            @click="downloadQuote"
                        >
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m0 0 5-5m-5 5-5-5M5 21h14" /></svg>
                        </button>
                    </div>
                </aside>

                <div
                    ref="mobileQuoteTotals"
                    class="fixed inset-x-0 bottom-0 z-40 border-t-2 border-amber-400 bg-[#121212]/95 p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-[0_-10px_30px_rgba(216,174,45,0.14)] backdrop-blur lg:hidden"
                >
                    <div class="mx-auto max-w-md space-y-1.5">
                        <div v-if="hasSelectedProducts" class="rounded-lg border border-neutral-800 bg-neutral-900 px-3 py-2">
                            <div class="flex items-center justify-between">
                                <span class="text-base font-semibold text-white">{{ t('quote.online_total') }}</span>
                                <span class="shrink-0 whitespace-nowrap text-xl font-semibold text-amber-400">
                                    {{ onlineTotal.toFixed(2) }} €
                                </span>
                            </div>
                        </div>

                        <div>
                            <button type="button" class="flex h-12 w-full items-center justify-center rounded-lg bg-emerald-600 text-white transition hover:bg-emerald-500 disabled:opacity-35" :disabled="!hasSelectedProducts" :aria-label="funnelCopy.cart" :title="funnelCopy.cart" @click="showCart = true">
                                <span class="relative"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6" /><circle cx="10" cy="20" r="1" /><circle cx="18" cy="20" r="1" /></svg><span v-if="cartItemCount > 0" class="absolute -right-3 -top-3 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-black leading-none text-white ring-2 ring-emerald-600">{{ cartItemCountLabel }}</span></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <footer class="w-full max-w-full overflow-x-hidden border-t border-neutral-800 bg-[#121212] text-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center py-14 text-center sm:py-16">
                    <h2 class="text-base font-medium">{{ headerCopy.contact }}</h2>
                    <a
                        :href="`mailto:${footerContact.email}`"
                        class="mt-7 text-sm transition hover:text-amber-400"
                    >
                        {{ footerContact.email }}
                    </a>
                    <a
                        :href="footerContact.whatsappUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-emerald-400 underline decoration-emerald-400/70 underline-offset-4 transition hover:text-emerald-300"
                        :aria-label="`WhatsApp: ${footerContact.phone}`"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12.04 2a9.84 9.84 0 0 0-8.5 14.78L2 22l5.38-1.41A9.96 9.96 0 0 0 12.04 22 10 10 0 0 0 12.04 2Zm0 18.18a8.1 8.1 0 0 1-4.13-1.13l-.3-.18-3.19.84.85-3.1-.2-.32a8.15 8.15 0 1 1 6.97 3.89Zm4.47-6.1c-.24-.12-1.45-.71-1.67-.79-.23-.08-.39-.12-.55.12-.16.25-.63.8-.77.96-.14.17-.29.19-.53.07a6.64 6.64 0 0 1-1.97-1.21 7.38 7.38 0 0 1-1.36-1.7c-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.12-.14.16-.25.24-.41.08-.17.04-.31-.02-.43-.06-.12-.55-1.33-.75-1.82-.2-.48-.4-.41-.55-.42h-.47c-.16 0-.43.06-.65.31-.22.25-.85.83-.85 2.03s.87 2.36.99 2.52c.12.17 1.72 2.63 4.17 3.69.58.25 1.04.4 1.39.51.58.19 1.12.16 1.54.1.47-.07 1.45-.59 1.65-1.16.21-.57.21-1.06.15-1.16-.06-.11-.23-.17-.47-.29Z"/>
                        </svg>
                        <span>WhatsApp · {{ footerContact.phone }}</span>
                    </a>
                    <div class="mt-10 flex items-center gap-6">
                        <a
                            href="https://www.facebook.com/autoradiocanario"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Facebook"
                            class="transition hover:text-amber-400"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.5-3.91 3.77-3.91 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.57v1.89h2.77l-.44 2.91h-2.33V22C18.34 21.24 22 17.08 22 12.06Z"/>
                            </svg>
                        </a>
                        <a
                            href="https://www.instagram.com/autoradiocanario/"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Instagram"
                            class="transition hover:text-amber-400"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="5"/>
                                <circle cx="12" cy="12" r="4"/>
                                <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>
                            </svg>
                        </a>
                    </div>
                </div>

                <div class="border-t border-neutral-800 py-10">
                    <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                        <label class="grid w-36 gap-3 text-xs text-neutral-300">
                            Idioma
                            <select
                                :value="locale"
                                class="border border-neutral-500 bg-[#121212] px-4 py-3 text-sm text-white outline-none"
                                @change="changeHeaderLanguage"
                            >
                                <option value="es">Español</option>
                                <option value="en">English</option>
                                <option value="it">Italiano</option>
                            </select>
                        </label>

                        <div class="flex max-w-xl flex-wrap gap-2 lg:justify-end" aria-label="Formas de pago">
                            <span
                                v-for="payment in paymentMethods"
                                :key="payment.name"
                                class="relative inline-flex h-8 w-14 items-center justify-center overflow-hidden rounded-md border border-neutral-300 bg-white px-1.5 shadow-sm"
                                :title="payment.name"
                            >
                                <span class="text-center text-[8px] font-bold leading-none text-neutral-800">{{ payment.fallback }}</span>
                                <img
                                    :src="`https://cdn.simpleicons.org/${payment.icon}/${payment.color}`"
                                    :alt="payment.name"
                                    class="absolute inset-0 h-full w-full bg-white object-contain p-1.5"
                                    loading="lazy"
                                    @error="($event.currentTarget as HTMLImageElement).remove()"
                                />
                            </span>
                        </div>
                    </div>

                    <div class="mt-10 flex flex-wrap items-center gap-x-3 gap-y-2 text-[11px] text-neutral-400">
                        <span>© {{ currentYear }}, AutoRadioCanario</span>
                        <span>·</span>
                        <a href="https://www.autoradiocanario.com/policies/privacy-policy" class="hover:text-white">Política de privacidad</a>
                        <span>·</span>
                        <a href="https://www.autoradiocanario.com/policies/refund-policy" class="hover:text-white">Política de reembolso</a>
                        <span>·</span>
                        <a href="https://www.autoradiocanario.com/policies/terms-of-service" class="hover:text-white">Términos del servicio</a>
                        <span>·</span>
                        <a href="https://www.autoradiocanario.com/policies/shipping-policy" class="hover:text-white">Política de envío</a>
                        <span>·</span>
                        <a href="https://www.autoradiocanario.com/policies/contact-information" class="hover:text-white">Información de contacto</a>
                        <span>·</span>
                        <a href="https://www.autoradiocanario.com/policies/legal-notice" class="hover:text-white">Aviso legal</a>
                        <span>·</span>
                        <span class="text-neutral-600">
                            {{ t('attribution') }}
                            <a href="https://www.geonames.org/" target="_blank" rel="noopener noreferrer" class="underline hover:text-neutral-400">GeoNames</a>
                            (CC BY 4.0)
                        </span>
                    </div>
                </div>
            </div>

            <div class="bg-[#334fb4] px-4 py-12 text-center text-[11px] text-black/80 sm:py-16">
                By Escuelasoft N0439887
            </div>
        </footer>

        <div
            v-if="variantPickerVehicle"
            class="fixed inset-0 z-[95] overflow-y-auto bg-[#0b0b0b] text-white"
            role="dialog"
            aria-modal="true"
            :aria-label="funnelCopy.choose"
        >
            <div class="mx-auto min-h-full max-w-4xl px-4 py-6 sm:px-8 sm:py-10">
                <div class="flex items-center justify-between gap-4 border-b border-neutral-800 pb-5">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-400">{{ funnelCopy.choose }}</p>
                        <h2 class="mt-2 truncate text-xl font-bold sm:text-3xl">{{ variantPickerVehicle.title }}</h2>
                    </div>
                    <button type="button" class="shrink-0 rounded-full border border-neutral-600 px-4 py-2 text-sm font-semibold hover:border-white" @click="closeVariantPicker">✕</button>
                </div>
                <button type="button" class="mt-4 rounded-lg border border-amber-400 px-4 py-2 text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black" @click="setConfiguratorMode(isSpecificMode ? 'universal' : 'specific')">
                    {{ isSpecificMode ? t('mode.go_universal') : t('mode.go_specific') }}
                </button>
                <div class="mt-6 grid gap-3">
                    <template v-for="variant in visibleScreenVariants(variantPickerVehicle)" :key="variant.id">
                        <div
                            v-for="choice in screenVariantChoices(variant)"
                            :key="choice.id"
                            class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center"
                            :class="isScreenChoiceOverBudget(choice)
                                ? 'border-neutral-800 bg-neutral-900 text-neutral-500'
                                : isScreenChoiceSelected(choice)
                                    ? 'border-emerald-400 bg-emerald-400/10 ring-1 ring-emerald-400'
                                    : 'border-neutral-700 bg-[#121212]'"
                        >
                            <div class="col-span-2 min-w-0 sm:col-span-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ displayVariantTitle(choice.title) }}</span>
                                    <span v-if="choice.color" class="text-sm text-neutral-400">· {{ choice.color }}</span>
                                    <span v-if="isRecommendedScreenVariant(variantPickerVehicle, variant)" class="rounded bg-emerald-400 px-2 py-1 text-[10px] font-black uppercase text-black">{{ t('screen.recommended') }}</span>
                                </div>
                                <p class="mt-1 text-lg font-bold text-amber-400">{{ choice.price.toFixed(2) }} €</p>
                            </div>
                            <button
                                type="button"
                                class="min-w-0 rounded-lg px-3 py-3 text-sm font-bold transition disabled:cursor-not-allowed disabled:bg-neutral-700 disabled:text-neutral-400 sm:px-5 sm:text-base"
                                :class="isScreenChoiceSelected(choice) ? 'border border-red-500 bg-red-500/10 text-red-300 hover:bg-red-500 hover:text-white' : 'bg-amber-400 text-black hover:bg-amber-300'"
                                :disabled="isScreenChoiceOverBudget(choice)"
                                @click="toggleScreenChoiceInCart(choice)"
                            >
                                {{ isScreenChoiceSelected(choice) ? funnelCopy.remove : funnelCopy.add }}
                            </button>
                            <label class="flex shrink-0 items-center justify-end gap-1.5" :class="isScreenChoiceSelected(choice) ? '' : 'pointer-events-none opacity-35'">
                                <span class="sr-only">{{ funnelCopy.quantity }}</span>
                                <input
                                    type="number"
                                    min="1"
                                    inputmode="numeric"
                                    class="h-12 w-16 rounded-lg border border-neutral-600 bg-black px-1 text-center text-lg font-bold text-white outline-none focus:border-emerald-400 sm:w-20 sm:px-2"
                                    :value="cartQuantity(`screen:${choice.id}`)"
                                    :disabled="!isScreenChoiceSelected(choice)"
                                    @change="setCartQuantityFromInput(`screen:${choice.id}`, $event)"
                                />
                                <span class="grid gap-1">
                                    <button type="button" class="flex h-[22px] w-8 items-center justify-center rounded border border-neutral-600 text-xs hover:border-emerald-400" :disabled="!isScreenChoiceSelected(choice)" @click="setCartQuantity(`screen:${choice.id}`, cartQuantity(`screen:${choice.id}`) + 1)">▲</button>
                                    <button type="button" class="flex h-[22px] w-8 items-center justify-center rounded border border-neutral-600 text-xs hover:border-emerald-400" :disabled="!isScreenChoiceSelected(choice)" @click="setCartQuantity(`screen:${choice.id}`, cartQuantity(`screen:${choice.id}`) - 1)">▼</button>
                                </span>
                            </label>
                        </div>
                    </template>
                </div>
                <div class="sticky bottom-0 z-10 -mx-4 mt-10 border-t border-neutral-800 bg-[#0b0b0b]/95 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-15px_35px_rgba(0,0,0,0.55)] backdrop-blur sm:-mx-8 sm:px-8">
                    <div class="mx-auto grid max-w-2xl gap-2 sm:grid-cols-[auto_1fr]">
                        <button type="button" class="order-2 flex h-12 items-center justify-center rounded-xl bg-emerald-600 px-5 text-white transition hover:bg-emerald-500" :aria-label="funnelCopy.cart" @click="openCartFromVariantPicker">
                            <span class="relative"><svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6" /><circle cx="10" cy="20" r="1" /><circle cx="18" cy="20" r="1" /></svg><span v-if="cartItemCount > 0" class="absolute -right-3 -top-3 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-black leading-none text-white ring-2 ring-emerald-600">{{ cartItemCountLabel }}</span></span>
                        </button>
                        <button type="button" class="order-1 flex h-12 items-center justify-center rounded-xl bg-[#334fb4] text-white transition hover:bg-[#405dc7]" :aria-label="funnelCopy.back" :title="funnelCopy.back" @click="returnToConfigurator"><svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg></button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showCart" class="fixed inset-0 z-[96] overflow-y-auto bg-[#0b0b0b] text-white" role="dialog" aria-modal="true" :aria-label="funnelCopy.cartTitle">
            <div class="mx-auto min-h-full max-w-3xl px-4 py-6 sm:px-8 sm:py-10">
                <div class="flex items-center justify-between border-b border-neutral-800 pb-5">
                    <h2 class="text-2xl font-bold sm:text-3xl">{{ funnelCopy.cartTitle }}</h2>
                    <button type="button" class="rounded-full border border-neutral-600 px-4 py-2 text-sm font-semibold hover:border-white" @click="showCart = false">✕</button>
                </div>
                <button type="button" class="mt-4 rounded-lg border border-amber-400 px-4 py-2 text-sm font-semibold text-amber-400 transition hover:bg-amber-400 hover:text-black" @click="setConfiguratorMode(isSpecificMode ? 'universal' : 'specific')">
                    {{ isSpecificMode ? t('mode.go_universal') : t('mode.go_specific') }}
                </button>
                <div v-if="isSpecificMode && selectedBrand" class="mt-6 overflow-hidden rounded-2xl border border-neutral-800 bg-[#121212]">
                    <div class="flex items-center justify-center gap-3 border-b border-neutral-800 p-4">
                        <img v-if="selectedBrandImageUrl && failedBrandImage !== selectedBrandImageUrl" :src="selectedBrandImageUrl" :alt="selectedBrand" class="h-14 w-24 object-contain" @error="failedBrandImage = selectedBrandImageUrl" />
                        <p class="text-sm uppercase tracking-wider text-neutral-500">{{ selectedBrand }}</p>
                        <p v-if="selectedModel" class="text-xl font-bold">{{ displayVehicleModel(selectedModel) }}</p>
                        <p v-if="selectedYear" class="text-neutral-400">{{ selectedYear }}</p>
                    </div>
                    <button v-if="selectedVehicleImageUrl && failedVehicleImage !== selectedVehicleImageUrl" type="button" class="flex h-52 w-full items-center justify-center p-3 sm:h-64" @click="openImageZoom(selectedVehicleImageUrl, `${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}`)">
                        <img :src="selectedVehicleImageUrl" :alt="`${selectedBrand} ${displayVehicleModel(selectedModel)} ${selectedYear}`" class="h-full w-full object-contain" @error="failedVehicleImage = selectedVehicleImageUrl" />
                    </button>
                </div>
                <p class="mt-7 text-sm font-semibold uppercase tracking-[0.24em] text-amber-400">{{ t('quote.title') }}</p>
                <div v-if="customDiscount || activeDiscount || nextDiscount" class="mt-4 rounded-xl border px-4 py-3 text-sm" :class="customDiscount || activeDiscount ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-300' : 'border-amber-400/50 bg-amber-400/10 text-amber-300'">
                    <template v-if="customDiscount">{{ discountLabel }}</template>
                    <template v-else-if="activeDiscount">{{ automaticDiscountAppliedMessage(activeDiscount) }}</template>
                    <template v-else-if="nextDiscount">{{ automaticDiscountRemainingMessage(nextDiscount) }}</template>
                </div>
                <p v-if="!hasSelectedProducts" class="py-16 text-center text-neutral-400">{{ funnelCopy.empty }}</p>
                <div v-else class="mt-6 space-y-3">
                    <div v-for="screen in selectedScreens" :key="`cart-screen-${screen.id}`" class="grid grid-cols-[76px_minmax(0,1fr)] gap-3 rounded-xl border border-neutral-800 bg-[#121212] p-3">
                        <div class="flex h-[76px] w-[76px] items-center justify-center overflow-hidden rounded-lg border border-neutral-700 bg-black"><img v-if="screen.image || vehicleForScreenVariant(screen.id)?.image" :src="screen.image || vehicleForScreenVariant(screen.id)?.image || ''" alt="" class="h-full w-full object-contain" /></div>
                        <div class="min-w-0"><p class="line-clamp-2 font-semibold">{{ vehicleForScreenVariant(screen.id)?.title }}</p><p class="truncate text-sm text-neutral-400">{{ displayVariantTitle(screen.title) }}</p><p class="mt-1 font-bold text-amber-400">{{ (screen.price * cartQuantity(`screen:${screen.id}`)).toFixed(2) }} €</p></div>
                        <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-600 bg-neutral-900 text-white transition hover:border-red-400 hover:text-red-400" :aria-label="t('actions.remove_product')" @click="removeSelectedScreen(screen.id)"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg></button>
                        <div class="flex items-center justify-end gap-2"><span class="mr-1 text-xs text-neutral-500">{{ funnelCopy.quantity }}</span><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`screen:${screen.id}`, cartQuantity(`screen:${screen.id}`) - 1)">−</button><span class="w-6 text-center font-bold">{{ cartQuantity(`screen:${screen.id}`) }}</span><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`screen:${screen.id}`, cartQuantity(`screen:${screen.id}`) + 1)">+</button></div>
                    </div>
                    <div v-for="camera in selectedCameras" :key="`cart-camera-${camera.key}`" class="grid grid-cols-[76px_minmax(0,1fr)] gap-3 rounded-xl border border-neutral-800 bg-[#121212] p-3"><div class="flex h-[76px] w-[76px] items-center justify-center overflow-hidden rounded-lg border border-neutral-700 bg-black"><img v-if="camera.image" :src="camera.image" alt="" class="h-full w-full object-contain" /></div><div class="min-w-0"><p class="line-clamp-2 font-semibold">{{ camera.title }}</p><p class="mt-1 text-amber-400">{{ (camera.price * cartQuantity(`camera:${camera.key}`)).toFixed(2) }} €</p></div><button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-600 bg-neutral-900 text-white hover:text-red-400" @click="toggleCamera(camera.key)"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg></button><div class="flex items-center justify-end gap-2"><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`camera:${camera.key}`, cartQuantity(`camera:${camera.key}`) - 1)">−</button><b class="w-6 text-center">{{ cartQuantity(`camera:${camera.key}`) }}</b><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`camera:${camera.key}`, cartQuantity(`camera:${camera.key}`) + 1)">+</button></div></div>
                    <div v-for="speaker in selectedSpeakers" :key="`cart-speaker-${speaker.key}`" class="grid grid-cols-[76px_minmax(0,1fr)] gap-3 rounded-xl border border-neutral-800 bg-[#121212] p-3"><div class="flex h-[76px] w-[76px] items-center justify-center overflow-hidden rounded-lg border border-neutral-700 bg-black"><img v-if="speaker.image" :src="speaker.image" alt="" class="h-full w-full object-contain" /></div><div class="min-w-0"><p class="line-clamp-2 font-semibold">{{ speaker.productTitle }}</p><p v-if="speaker.title !== speaker.productTitle" class="truncate text-sm text-neutral-400">{{ speaker.title }}</p><p class="mt-1 text-amber-400">{{ (speaker.price * cartQuantity(`speaker:${speaker.key}`)).toFixed(2) }} €</p></div><button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-600 bg-neutral-900 text-white hover:text-red-400" @click="toggleSpeaker(speaker.key)"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg></button><div class="flex items-center justify-end gap-2"><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`speaker:${speaker.key}`, cartQuantity(`speaker:${speaker.key}`) - 1)">−</button><b class="w-6 text-center">{{ cartQuantity(`speaker:${speaker.key}`) }}</b><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`speaker:${speaker.key}`, cartQuantity(`speaker:${speaker.key}`) + 1)">+</button></div></div>
                    <div v-for="product in selectedCustomProducts" :key="`cart-custom-${product.key}`" class="grid grid-cols-[auto_minmax(0,1fr)] gap-3 rounded-xl border border-neutral-800 bg-[#121212] p-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center"><button type="button" @click="toggleCustomProduct(product.key)">🗑</button><div class="min-w-0"><p class="truncate font-semibold">{{ product.title }}</p><p class="text-amber-400">{{ (product.price * cartQuantity(`custom:${product.key}`)).toFixed(2) }} €</p></div><div class="col-span-2 flex items-center justify-end gap-2 sm:col-span-1"><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`custom:${product.key}`, cartQuantity(`custom:${product.key}`) - 1)">−</button><b class="w-6 text-center">{{ cartQuantity(`custom:${product.key}`) }}</b><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`custom:${product.key}`, cartQuantity(`custom:${product.key}`) + 1)">+</button></div></div>
                    <div v-if="selectedInstallation" class="grid grid-cols-[76px_minmax(0,1fr)] gap-3 rounded-xl border border-neutral-800 bg-[#121212] p-3"><div class="relative flex h-[76px] w-[76px] items-center justify-center overflow-hidden rounded-lg bg-amber-400"><img  :src="selectedInstallation.image || '/images/icons/installation-tools.png'" :alt="stepContextLabel('installation')" class="h-full w-full object-cover" /></div><div class="min-w-0"><p class="line-clamp-2 font-semibold">{{ selectedInstallation.title }}</p><p class="mt-1 text-amber-400">{{ (selectedInstallation.price * cartQuantity(`installation:${selectedInstallation.key}`)).toFixed(2) }} €</p></div><button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-600 bg-neutral-900 text-white transition hover:border-red-400 hover:text-red-400" :aria-label="t('actions.remove_product')" @click="selectedInstallationKey = null"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg></button><div class="flex items-center justify-end gap-2"><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`installation:${selectedInstallation.key}`, cartQuantity(`installation:${selectedInstallation.key}`) - 1)">−</button><b class="w-6 text-center">{{ cartQuantity(`installation:${selectedInstallation.key}`) }}</b><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity(`installation:${selectedInstallation.key}`, cartQuantity(`installation:${selectedInstallation.key}`) + 1)">+</button></div></div>
                    <div v-if="selectedPrecheckMethod === 'installer'" class="grid grid-cols-[76px_minmax(0,1fr)] gap-3 rounded-xl border border-neutral-800 bg-[#121212] p-3">
                        <div class="flex h-[76px] w-[76px] items-center justify-center rounded-lg bg-amber-400 text-black">
                            <svg class="h-12 w-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="m8 12 2.6 2.6L16.5 9" /></svg>
                        </div>
                        <div class="min-w-0"><p class="line-clamp-2 font-semibold">{{ t('installation.precheck_installer_title') }}</p><p class="mt-1 text-amber-400">{{ ((precheckProduct?.price ?? 25) * cartQuantity('precheck:installer')).toFixed(2) }} €</p></div>
                        <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-600 bg-neutral-900 text-white transition hover:border-red-400 hover:text-red-400" :aria-label="t('actions.remove_product')" @click="selectedPrecheckMethod = null"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5" /></svg></button>
                        <div class="flex items-center justify-end gap-2"><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity('precheck:installer', cartQuantity('precheck:installer') - 1)">−</button><b class="w-6 text-center">{{ cartQuantity('precheck:installer') }}</b><button type="button" class="h-9 w-9 rounded border border-neutral-600" @click="setCartQuantity('precheck:installer', cartQuantity('precheck:installer') + 1)">+</button></div>
                    </div>
                </div>
                <div v-if="hasSelectedProducts" class="mt-8 space-y-5 border-t border-neutral-800 pt-5">
                    <div class="rounded-xl border border-neutral-800 bg-[#121212] p-4">
                        <div class="flex justify-between text-neutral-400"><span>{{ t('quote.subtotal') }}</span><span>{{ productsSubtotal.toFixed(2) }} €</span></div>
                        <div v-if="discountAmount > 0" class="mt-3 flex justify-between text-emerald-400"><span>{{ discountLabel }}</span><span>−{{ discountAmount.toFixed(2) }} €</span></div>
                        <div class="mt-4 flex items-center justify-between border-t border-neutral-700 pt-4"><span class="text-lg font-bold">{{ t('quote.estimated_total') }}</span><span class="text-2xl font-bold">{{ estimatedTotal.toFixed(2) }} €</span></div>
                        <div v-if="installationCost > 0" class="mt-3 flex justify-between rounded-lg border border-sky-400/30 bg-sky-400/5 px-3 py-3 text-sky-200"><span>{{ t('quote.installation_direct') }}</span><b>{{ installationCost.toFixed(2) }} €</b></div>
                    </div>

                    <div class="rounded-xl border border-amber-400/60 bg-amber-400/10 px-4 py-3 text-center text-sm font-semibold text-amber-300">
                        <p>🚚 &nbsp;{{ t('quote.home_delivery_title') }}</p><p>{{ t('quote.home_delivery_estimate') }}</p><p class="text-xs text-amber-200/80">{{ t('quote.shipping_tracking') }}</p>
                    </div>
                    <div class="rounded-xl border border-neutral-700 bg-[#121212] px-4 py-4 text-center"><p class="font-semibold text-amber-400">{{ t('quote.trust_title') }}</p><p class="mt-2 text-sm leading-6 text-neutral-400">{{ t('quote.trust_details') }}</p></div>

                    <label ref="cartCheckoutConsentSection" class="flex cursor-pointer items-start gap-3 rounded-xl text-sm leading-6 text-neutral-400 transition" :class="checkoutConsentAttention ? 'bg-amber-400/15 p-4 ring-2 ring-amber-400' : ''">
                        <input v-model="checkoutConsentAccepted" type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-amber-400" />
                        <span>{{ t('checkout_consent.checkbox') }} <a href="https://www.autoradiocanario.com/policies/terms-of-service" target="_blank" rel="noopener noreferrer" class="block text-neutral-300 underline">{{ props.locale === 'es' ? 'Ver condiciones' : props.locale === 'it' ? 'Vedi condizioni' : 'View terms' }}</a></span>
                    </label>
                    <p class="text-center text-sm leading-6 text-neutral-400">{{ t('quote.checkout_trust') }}</p>

                    <div class="sticky bottom-0 z-10 -mx-4 flex flex-col gap-2 border-t-2 border-amber-400 bg-[#0b0b0b]/95 px-4 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-[0_-15px_35px_rgba(0,0,0,0.55)] backdrop-blur sm:-mx-8 sm:px-8">
                        <div class="order-0 flex items-center justify-between rounded-lg border border-neutral-700 bg-neutral-900 px-3 py-2"><span class="text-sm font-bold text-white">{{ t('quote.online_total') }}</span><span class="shrink-0 whitespace-nowrap text-xl font-bold text-amber-400">{{ onlineTotal.toFixed(2) }} €</span></div>
                        <button type="button" class="order-4 flex h-11 w-full items-center justify-center rounded-lg bg-red-600 text-white transition hover:bg-red-500 disabled:opacity-50" :disabled="!canCheckout" :aria-label="t('actions.add_to_cart')" :title="t('actions.add_to_cart')" @click="goToCheckout"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg></button>
                        <button type="button" class="order-2 flex h-11 w-full items-center justify-center rounded-lg border border-amber-400 text-amber-400 transition hover:bg-amber-400 hover:text-black" :aria-label="t('actions.download_quote')" :title="t('actions.download_quote')" @click="downloadQuote"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m0 0 5-5m-5 5-5-5M5 21h14" /></svg></button>
                        <button type="button" class="order-1 flex h-11 w-full items-center justify-center rounded-lg bg-[#334fb4] text-white transition hover:bg-[#405dc7]" :aria-label="funnelCopy.back" :title="funnelCopy.back" @click="returnToConfigurator"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg></button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="zoomedImage"
            class="fixed inset-0 z-[110] flex cursor-zoom-out items-center justify-center bg-black/90 p-4 backdrop-blur-sm sm:p-8"
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
            v-if="showCheckoutConsentWarning"
            class="fixed inset-0 z-[110] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            role="alertdialog"
            aria-modal="true"
            @click.self="dismissCheckoutConsentWarning"
        >
            <section class="w-full max-w-sm rounded-2xl border border-neutral-700 bg-[#181818] p-6 shadow-2xl">
                <button
                    type="button"
                    class="w-full rounded-lg bg-amber-400 px-4 py-3 text-sm font-semibold text-black transition hover:bg-amber-300"
                    @click="dismissCheckoutConsentWarning"
                >
                    {{ t('checkout_consent.dismiss') }}
                </button>
            </section>
        </div>

        <div
            v-if="pendingCameraSelection"
            class="fixed inset-0 z-[85] flex items-center justify-center bg-black/75 p-4 backdrop-blur-sm"
            role="alertdialog"
            aria-modal="true"
            @click.self="pendingCameraSelection = null"
        >
            <section class="w-full max-w-md rounded-2xl border border-neutral-700 bg-[#181818] p-6 shadow-2xl">
                <h2 class="text-xl font-semibold text-white">{{ t('budget.camera_title') }}</h2>
                <p class="mt-3 text-sm leading-6 text-neutral-300">
                    {{ t('budget.camera_message', { amount: pendingCameraSelection.overage.toFixed(2) }) }}
                </p>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" class="rounded-lg border border-neutral-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-neutral-800" @click="pendingCameraSelection = null">
                        {{ t('budget.cancel') }}
                    </button>
                    <button type="button" class="rounded-lg bg-amber-400 px-4 py-3 text-sm font-semibold text-black transition hover:bg-amber-300" @click="confirmCameraOverBudget">
                        {{ t('budget.proceed') }}
                    </button>
                </div>
            </section>
        </div>

        <div
            v-if="isAdmin && showCustomQuoteModal"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 p-4"
            @click.self="showCustomQuoteModal = false"
        >
            <section class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl border border-neutral-700 bg-[#121212] p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold text-white">{{ customQuoteCopy.title }}</h2>
                        <p class="mt-1 text-sm text-neutral-400">{{ customQuoteCopy.help }}</p>
                    </div>
                    <button type="button" class="rounded-md px-2 py-1 text-neutral-400 hover:bg-neutral-800 hover:text-white" @click="showCustomQuoteModal = false">✕</button>
                </div>

                <input
                    v-model="customProductSearch"
                    type="search"
                    autofocus
                    class="mt-5 rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white placeholder:text-neutral-500"
                    :placeholder="customQuoteCopy.search"
                />

                <div v-if="selectedCustomProducts.length" class="mt-4 rounded-xl border border-amber-400/40 bg-amber-400/5 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-amber-400">
                        {{ customQuoteCopy.selected }} · {{ selectedCustomProducts.length }}
                    </p>
                    <div class="flex max-h-28 flex-wrap gap-2 overflow-y-auto">
                        <div v-for="product in selectedCustomProducts" :key="`selected-${product.key}`" class="flex items-center gap-2 rounded-lg bg-neutral-800 px-3 py-2 text-xs text-white">
                            <span class="max-w-64 truncate">{{ product.title }}<template v-if="product.variantTitle"> — {{ displayVariantTitle(product.variantTitle) }}</template></span>
                            <button type="button" class="text-neutral-400 hover:text-red-400" :aria-label="customQuoteCopy.remove" @click="toggleCustomProduct(product.key)">✕</button>
                        </div>
                    </div>
                </div>

                <div class="quote-scrollbar quote-summary-scrollbar mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto pr-3">
                    <div
                        v-for="product in filteredCustomProducts"
                        :key="product.key"
                        class="flex items-center gap-4 rounded-xl border p-3 transition"
                        :class="selectedCustomProductKeys.includes(product.key) ? 'border-amber-400 bg-amber-400/10' : 'border-neutral-800 bg-neutral-900 hover:border-neutral-600'"
                    >
                        <img v-if="product.image" :src="product.image" alt="" class="h-12 w-12 rounded-md bg-white object-contain" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-white">{{ product.title }}</p>
                            <p class="truncate text-xs text-neutral-400">
                                {{ [displayVariantTitle(product.variantTitle), product.sku, product.category].filter(Boolean).join(' · ') }}
                            </p>
                        </div>
                        <span class="shrink-0 font-semibold text-amber-400">{{ product.price.toFixed(2) }} €</span>
                        <button
                            type="button"
                            class="w-24 shrink-0 rounded-lg px-3 py-2 text-xs font-semibold transition"
                            :class="selectedCustomProductKeys.includes(product.key) ? 'border border-neutral-600 bg-neutral-800 text-neutral-300 hover:border-red-400 hover:text-red-400' : 'bg-amber-400 text-black hover:bg-amber-300'"
                            @click="toggleCustomProduct(product.key)"
                        >
                            {{ selectedCustomProductKeys.includes(product.key) ? customQuoteCopy.added : customQuoteCopy.add }}
                        </button>
                    </div>
                    <p v-if="filteredCustomProducts.length === 0" class="py-10 text-center text-sm text-neutral-500">{{ customQuoteCopy.empty }}</p>
                </div>

                <div class="mt-5 flex items-center justify-between border-t border-neutral-800 pt-5">
                    <span class="text-sm text-neutral-400">{{ customQuoteCopy.selected }}: {{ selectedCustomProductKeys.length }}</span>
                    <div class="flex gap-3">
                        <button type="button" class="rounded-lg border border-neutral-700 px-4 py-2 text-sm text-neutral-300 hover:bg-neutral-900" @click="showCustomQuoteModal = false">{{ customQuoteCopy.cancel }}</button>
                        <button type="button" :disabled="selectedCustomProductKeys.length === 0" class="rounded-lg bg-amber-400 px-4 py-2 text-sm font-semibold text-black hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40" @click="completeCustomQuote">{{ customQuoteCopy.create }}</button>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-if="isAdmin && showQuoteModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            @click.self="showQuoteModal = false"
        >
            <section class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-neutral-700 bg-[#121212] p-6 shadow-2xl">
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
                    <fieldset class="grid gap-3 rounded-xl border border-amber-400/30 bg-amber-400/5 p-4">
                        <legend class="px-1 text-sm font-semibold text-amber-400">{{ adminDiscountCopy.title }}</legend>
                        <p class="text-xs text-neutral-400">{{ adminDiscountCopy.help }}</p>
                        <label class="grid gap-2 text-sm text-neutral-300">
                            {{ adminDiscountCopy.code }}
                            <input
                                v-model.trim="quoteDiscountCode"
                                type="text"
                                maxlength="255"
                                autocomplete="off"
                                class="rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 uppercase text-white"
                                :placeholder="adminDiscountCopy.codePlaceholder"
                            />
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="grid gap-2 text-sm text-neutral-300">
                                {{ adminDiscountCopy.type }}
                                <select
                                    v-model="quoteDiscountType"
                                    class="rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white"
                                >
                                    <option value="percentage">{{ adminDiscountCopy.percentage }}</option>
                                    <option value="fixed">{{ adminDiscountCopy.fixed }}</option>
                                </select>
                            </label>
                            <label class="grid gap-2 text-sm text-neutral-300">
                                {{ adminDiscountCopy.value }} ({{ quoteDiscountType === 'percentage' ? '%' : '€' }})
                                <input
                                    v-model="quoteDiscountValue"
                                    type="number"
                                    min="0"
                                    :max="quoteDiscountType === 'percentage' ? 100 : undefined"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white"
                                    placeholder="0"
                                />
                            </label>
                        </div>
                    </fieldset>
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
                    <label class="grid gap-2 text-sm text-neutral-300">
                        {{ t('quote_form.customs_taxes') }}
                        <textarea
                            v-model="quoteCustomsTaxes"
                            rows="4"
                            class="resize-y rounded-lg border border-neutral-700 bg-neutral-900 px-4 py-3 text-white"
                            :placeholder="t('quote_form.customs_taxes_placeholder')"
                        ></textarea>
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
                <div class="grid gap-4 sm:grid-cols-2"><input v-model="missingVehicleForm.first_name" required maxlength="100" :placeholder="t('vehicle.first_name')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.first_name }" @input="clearMissingVehicleFieldError('first_name')" /><input v-model="missingVehicleForm.last_name" required maxlength="100" :placeholder="t('vehicle.last_name')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.last_name }" @input="clearMissingVehicleFieldError('last_name')" /></div>
                <input v-model="missingVehicleForm.email" required maxlength="255" type="email" :placeholder="t('vehicle.email')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.email }" @input="clearMissingVehicleFieldError('email')" /><input v-model="missingVehicleForm.phone" required maxlength="50" :placeholder="t('vehicle.phone')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.phone }" @input="clearMissingVehicleFieldError('phone')" /><input v-model="missingVehicleForm.province" required maxlength="100" :placeholder="t('vehicle.province')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.province }" @input="clearMissingVehicleFieldError('province')" />
                <div class="grid gap-4 sm:grid-cols-2"><input v-model="missingVehicleForm.brand" required maxlength="100" :placeholder="t('fields.brand')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.brand }" @input="clearMissingVehicleFieldError('brand')" /><input v-model="missingVehicleForm.model" required maxlength="255" :placeholder="t('fields.model')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.model }" @input="clearMissingVehicleFieldError('model')" /></div>
                <input v-model="missingVehicleForm.year" required type="number" min="1900" max="2100" :placeholder="t('vehicle.year')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.year }" @input="clearMissingVehicleFieldError('year')" /><textarea v-model="missingVehicleForm.comment" maxlength="5000" rows="3" :placeholder="t('vehicle.comment')" class="form-input" :class="{ 'form-input-error': missingVehicleFieldErrors.comment }" @input="clearMissingVehicleFieldError('comment')"></textarea>
                <label class="upload-photo-button" :class="{ 'upload-photo-selected': missingVehicleForm.photo, 'form-input-error': missingVehicleFieldErrors.photo }"><span>{{ missingVehicleForm.photo ? missingVehicleForm.photo.name : t('vehicle.upload_photo') }}</span><input type="file" accept="image/*" @change="selectMissingVehiclePhoto" /></label>
                <p v-if="missingVehicleError" class="text-sm text-red-400">{{ missingVehicleError }}</p><button type="submit" :disabled="missingVehicleSending" class="rounded-lg bg-amber-400 px-4 py-3 font-semibold text-black">{{ missingVehicleSending ? t('vehicle.form_sending') : t('vehicle.form_submit') }}</button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.step-context-label {
    position: absolute;
    left: 50%;
    top: 0;
    z-index: 2;
    max-width: calc(100% - 5rem);
    transform: translate(-50%, -50%);
    overflow: hidden;
    border: 1px solid currentColor;
    border-radius: 9999px;
    background: #121212;
    padding: 0.1rem 0.65rem;
    color: #d8ae2d;
    font-size: 0.625rem;
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: 0.08em;
    text-overflow: ellipsis;
    text-transform: uppercase;
    white-space: nowrap;
}

.quote-scrollbar {
    scrollbar-color: #525252 #171717;
    scrollbar-width: thin;
}
.summary-product-link { display: inline-flex; align-items: flex-start; gap: 0.35rem; text-align: left; font-weight: 500; color: #f5f5f5; text-decoration-line: underline; text-decoration-color: rgba(216, 174, 45, 0.7); text-decoration-thickness: 1px; text-underline-offset: 4px; transition: color 150ms ease, text-decoration-color 150ms ease; }
.summary-product-link:hover, .summary-product-link:focus-visible, .summary-product-link:active { color: #d8ae2d; text-decoration-color: #d8ae2d; }
.summary-remove-button { display: inline-flex; height: 1.5rem; width: 1.5rem; flex: none; align-items: center; justify-content: center; border: 1px solid rgba(216, 174, 45, 0.42); border-radius: 0.375rem; background: rgba(216, 174, 45, 0.08); color: rgba(216, 174, 45, 0.78); transition: background-color 150ms ease, color 150ms ease, border-color 150ms ease; }
.summary-remove-button svg { height: 0.85rem; width: 0.85rem; }
.summary-remove-button:hover, .summary-remove-button:focus-visible, .summary-remove-button:active { border-color: #d8ae2d; background: rgba(216, 174, 45, 0.18); color: #d8ae2d; }
.form-input { width: 100%; border: 1px solid #404040; border-radius: 0.5rem; background: #121212; padding: 0.75rem 1rem; color: white; }
.form-input::placeholder { color: #737373; }
.form-input-error { border-color: #ef4444; box-shadow: 0 0 0 1px #ef4444; }
.form-input-error::placeholder { color: #f87171; }
.upload-photo-button { position: relative; display: flex; min-height: 3.5rem; cursor: pointer; align-items: center; justify-content: center; border: 1px dashed #737373; border-radius: 0.5rem; padding: 0.75rem 1rem; color: #d4d4d4; text-align: center; }
.upload-photo-button:hover, .upload-photo-selected { border-color: #fbbf24; background: rgba(251, 191, 36, 0.12); color: #fbbf24; }
.upload-photo-button input { position: absolute; inset: 0; height: 100%; width: 100%; cursor: pointer; opacity: 0; }
.missing-vehicle-option { background: #fbbf24; color: #000; font-weight: 700; }

@media (max-width: 639px) {
    .mobile-model-section {
        width: 16rem;
        max-width: 100%;
        margin: 1.5rem auto 0;
    }

    .mobile-model-buttons {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
    }

    .mobile-model-buttons > button {
        width: 100%;
        min-width: 0;
        padding-inline: 0.5rem;
        text-align: center;
    }

    .mobile-model-buttons > .mobile-missing-model-button {
        grid-column: 1 / -1;
    }

    .mobile-vehicle-select {
        display: block;
        width: 16rem;
        max-width: 100%;
        margin-inline: auto;
        appearance: none;
        background-repeat: no-repeat;
        background-position: right 0.85rem center;
        padding-right: 2.5rem;
    }

    .mobile-vehicle-select.vehicle-select-pending {
        border: 2px solid rgba(239, 68, 68, 0.6);
        background-color: rgba(239, 68, 68, 0.1);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23FCA5A5' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        color: #fca5a5;
    }

    .mobile-vehicle-select.vehicle-select-complete {
        border: 2px solid rgba(52, 211, 153, 0.6);
        background-color: rgba(52, 211, 153, 0.1);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2334D399' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        color: #34d399;
    }

    .mobile-vehicle-select.vehicle-select-pending:focus {
        outline: 2px solid rgba(239, 68, 68, 0.3);
        outline-offset: 2px;
    }

    .mobile-vehicle-select.vehicle-select-complete:focus {
        outline: 2px solid rgba(52, 211, 153, 0.3);
        outline-offset: 2px;
    }

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

.quote-summary-scrollbar {
    scrollbar-color: #d8ae2d #211d10;
    scrollbar-width: auto;
}

.quote-summary-scrollbar::-webkit-scrollbar {
    width: 14px;
}

.quote-summary-scrollbar::-webkit-scrollbar-track {
    border: 1px solid rgba(216, 174, 45, 0.28);
    border-radius: 9999px;
    background: #211d10;
}

.quote-summary-scrollbar::-webkit-scrollbar-thumb {
    border: 2px solid #211d10;
    border-block-width: 10px;
    border-radius: 9999px;
    background: #d8ae2d;
    background-clip: padding-box;
}

.quote-summary-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #f0c438;
    background-clip: padding-box;
}
</style>
