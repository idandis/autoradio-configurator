<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

type Customer = {
    id: number;
    shopify_id: string | null;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    contacts: Array<{
        id: number | null;
        type: 'email' | 'phone' | 'note';
        value: string;
    }>;
    language: string | null;
    state: string | null;
    tags: string | null;
    note: string | null;
    attention_color: 'green' | 'yellow' | 'red' | null;
    total_orders: number;
    imported_orders: number;
    total_spent: string;
    imported_total: string | number;
    paid_total: string | number;
    refunded_total: string | number;
    adjustment_total: string | number;
    net_total: string | number;
    last_refund_at: string | null;
    service: {
        key: 'installation' | 'screen' | 'camera' | 'product';
        label: string;
        details: string;
    };
    vehicles: Array<{
        brand: string | null;
        model: string | null;
        year_from: number | null;
        year_to: number | null;
    }>;
    costs: Array<{
        id: number | null;
        description: string;
        amount: string | number;
        currency: 'EUR' | 'USD';
        exchange_rate: string | number;
        amount_eur: string | number;
    }>;
    costs_total: string | number;
    supplier_refunds: Array<{
        id: number | null;
        description: string;
        amount: string | number;
        currency: 'EUR' | 'USD';
        exchange_rate: string | number;
        amount_eur: string | number;
    }>;
    supplier_refunds_total: string | number;
    first_order_at: string | null;
    last_order_at: string | null;
    latest_order: {
        name: string;
        total: string | number;
        current_total: string | number;
        payment_status: string | null;
        fulfillment_status: string | null;
        cancelled_at: string | null;
        products: Array<{
            title: string;
            sku: string | null;
            quantity: number;
            price: string | number;
            total: string | number;
            cancelled: boolean;
        }>;
    } | null;
    address: {
        line_1: string | null;
        city: string | null;
        province: string | null;
        country: string | null;
        zip: string | null;
    } | null;
};

const props = defineProps<{
    customers: {
        data: Customer[];
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    filters: {
        search: string;
        sort: string;
        direction: 'asc' | 'desc';
        attention: string;
    };
    flashStatus?: string | null;
    columnOrder?: string[] | null;
    stats: {
        customers: number;
        orders: number;
        imported_orders: number;
        spent: string | number;
        refunded: string | number;
    };
}>();

const filters = reactive({
    search: props.filters.search || '',
    sort: props.filters.sort || 'last_order_at',
    direction: props.filters.direction || 'desc',
    attention: props.filters.attention || '',
});

const availableColumns = [
    { key: 'customer', label: 'Cliente' },
    { key: 'contacts', label: 'Contatti' },
    { key: 'location', label: 'Località' },
    { key: 'orders', label: 'Ordini' },
    { key: 'first_order_at', label: 'Primo ordine' },
    { key: 'last_order_at', label: 'Ultimo ordine' },
    { key: 'vehicle', label: 'Auto' },
    { key: 'service', label: 'Servizio' },
    { key: 'spent', label: 'Incassato lordo' },
    { key: 'refunds', label: 'Rimborsato' },
    { key: 'adjustments', label: 'Annullato / da regolare' },
    { key: 'refund_date', label: 'Data rimborso' },
    { key: 'latest_purchase', label: 'Prodotti acquistati' },
    { key: 'costs', label: 'Costi' },
    { key: 'supplier_refunds', label: 'Rimborsi da fornitore' },
    { key: 'total_costs', label: 'Totale costi' },
    { key: 'total_supplier_refunds', label: 'Totale rimborsi fornitore' },
    { key: 'notes', label: 'Note' },
    { key: 'net_spent', label: 'Netto ordine' },
];
const columnOrder = ref(availableColumns.map((column) => column.key));
const orderedColumns = computed(() => [
    ...columnOrder.value.filter((key) => key !== 'net_spent'),
    'net_spent',
]
    .map((key) => availableColumns.find((column) => column.key === key))
    .filter((column): column is { key: string; label: string } => Boolean(column)));

const tableScroll = ref<HTMLElement | null>(null);
const scrollPosition = ref(0);
const maxScroll = ref(0);
let resizeObserver: ResizeObserver | null = null;
let syncingScroll = false;

const customerForm = useForm({
    customers: null as File | null,
});

const orderForm = useForm({
    orders: null as File | null,
});

const updateCustomerFile = (event: Event) => {
    const target = event.target as HTMLInputElement | null;
    customerForm.customers = target?.files?.[0] ?? null;
};

const updateOrderFile = (event: Event) => {
    const target = event.target as HTMLInputElement | null;
    orderForm.orders = target?.files?.[0] ?? null;
};

const submitCustomerImport = () => {
    customerForm.post('/customers/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => customerForm.reset(),
    });
};

const submitOrderImport = () => {
    orderForm.post('/customers/import-orders', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => orderForm.reset(),
    });
};

const applySearch = () => {
    router.get('/customers', {
        search: filters.search || undefined,
        sort: filters.sort,
        direction: filters.direction,
        attention: filters.attention || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const sortBy = (column: string) => {
    if (filters.sort === column) {
        filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = column;
        filters.direction = 'asc';
    }
    applySearch();
};

const sortIcon = (column: string) => {
    if (filters.sort !== column) {
        return '↕';
    }

    return filters.direction === 'asc' ? '↑' : '↓';
};

const syncScroll = () => {
    if (syncingScroll || !tableScroll.value) {
        return;
    }
    syncingScroll = true;
    scrollPosition.value = tableScroll.value.scrollLeft;
    requestAnimationFrame(() => {
        syncingScroll = false;
    });
};

const setHorizontalScroll = (value: number) => {
    const position = Math.max(0, Math.min(value, maxScroll.value));
    scrollPosition.value = position;
    if (tableScroll.value) {
        tableScroll.value.scrollLeft = position;
    }
};

const updateHorizontalScroll = (event: Event) => {
    setHorizontalScroll(Number((event.target as HTMLInputElement).value));
};

const noteModalCustomer = ref<Customer | null>(null);
const noteDraft = ref('');
const noteSaving = ref(false);

const openNoteModal = (customer: Customer) => {
    noteModalCustomer.value = customer;
    noteDraft.value = customer.note ?? '';
};

const closeNoteModal = () => {
    if (noteSaving.value) return;
    noteModalCustomer.value = null;
    noteDraft.value = '';
};

const saveNote = () => {
    const customer = noteModalCustomer.value;
    if (!customer) return;

    noteSaving.value = true;
    router.patch(`/customers/${customer.id}/note`, {
        note: noteDraft.value,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            customer.note = noteDraft.value.trim() || null;
            noteModalCustomer.value = null;
            noteDraft.value = '';
        },
        onFinish: () => {
            noteSaving.value = false;
        },
    });
};

const addContactRow = (customer: Customer, type: 'email' | 'phone' | 'note') => {
    customer.contacts.push({ id: null, type, value: '' });
};

const saveContact = (customer: Customer, contact: Customer['contacts'][number]) => {
    const url = contact.id
        ? `/customers/${customer.id}/contacts/${contact.id}`
        : `/customers/${customer.id}/contacts`;
    const data = {
        type: contact.type,
        value: contact.value,
    };
    const options = {
        preserveScroll: true,
        preserveState: true,
    };

    if (contact.id) {
        router.patch(url, data, options);
    } else {
        router.post(url, data, options);
    }
};

const deleteContact = (customer: Customer, contact: Customer['contacts'][number]) => {
    if (!contact.id) {
        customer.contacts.splice(customer.contacts.indexOf(contact), 1);
        return;
    }

    router.delete(`/customers/${customer.id}/contacts/${contact.id}`, {
        preserveScroll: true,
        preserveState: true,
    });
};

const addCostRow = (customer: Customer) => {
    customer.costs.push({
        id: null,
        description: '',
        amount: '',
        currency: 'EUR',
        exchange_rate: 1,
        amount_eur: 0,
    });
};

const saveCost = (customer: Customer, cost: Customer['costs'][number]) => {
    const options = {
        preserveScroll: true,
        preserveState: true,
    };
    const data = {
        description: cost.description,
        amount: cost.amount,
        currency: cost.currency,
    };

    if (cost.id) {
        router.patch(`/customers/${customer.id}/costs/${cost.id}`, data, options);
    } else {
        router.post(`/customers/${customer.id}/costs`, data, options);
    }
};

const costEuroValue = (cost: Customer['costs'][number]) => {
    const amount = Number(cost.amount) || 0;
    const rate = cost.currency === 'USD' ? Number(cost.exchange_rate) : 1;

    return amount * rate;
};

const deleteCost = (customer: Customer, cost: Customer['costs'][number]) => {
    if (!cost.id) {
        customer.costs.splice(customer.costs.indexOf(cost), 1);
        return;
    }
    router.delete(`/customers/${customer.id}/costs/${cost.id}`, {
        preserveScroll: true,
        preserveState: true,
    });
};

const addSupplierRefundRow = (customer: Customer) => {
    customer.supplier_refunds.push({
        id: null,
        description: '',
        amount: '',
        currency: 'EUR',
        exchange_rate: 1,
        amount_eur: 0,
    });
};

const saveSupplierRefund = (
    customer: Customer,
    refund: Customer['supplier_refunds'][number],
) => {
    const options = {
        preserveScroll: true,
        preserveState: true,
    };
    const data = {
        description: refund.description,
        amount: refund.amount,
        currency: refund.currency,
    };

    if (refund.id) {
        router.patch(`/customers/${customer.id}/supplier-refunds/${refund.id}`, data, options);
    } else {
        router.post(`/customers/${customer.id}/supplier-refunds`, data, options);
    }
};

const supplierRefundEuroValue = (refund: Customer['supplier_refunds'][number]) => {
    const amount = Number(refund.amount) || 0;
    const rate = refund.currency === 'USD' ? Number(refund.exchange_rate) : 1;

    return amount * rate;
};

const deleteSupplierRefund = (
    customer: Customer,
    refund: Customer['supplier_refunds'][number],
) => {
    if (!refund.id) {
        customer.supplier_refunds.splice(customer.supplier_refunds.indexOf(refund), 1);
        return;
    }
    router.delete(`/customers/${customer.id}/supplier-refunds/${refund.id}`, {
        preserveScroll: true,
        preserveState: true,
    });
};

const moveColumn = (key: string, offset: -1 | 1) => {
    if (key === 'net_spent') {
        return;
    }
    const movableColumns = columnOrder.value.filter((columnKey) => columnKey !== 'net_spent');
    const currentIndex = movableColumns.indexOf(key);
    const targetIndex = currentIndex + offset;
    if (currentIndex < 0 || targetIndex < 0 || targetIndex >= movableColumns.length) {
        return;
    }
    const nextOrder = [...movableColumns];
    [nextOrder[currentIndex], nextOrder[targetIndex]] = [nextOrder[targetIndex], nextOrder[currentIndex]];
    columnOrder.value = [...nextOrder, 'net_spent'];
    localStorage.setItem('customers-column-order', JSON.stringify(columnOrder.value));
    router.patch('/customers/column-order', {
        columns: columnOrder.value,
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const setAttentionColor = (
    customer: Customer,
    color: 'green' | 'yellow' | 'red',
) => {
    customer.attention_color = customer.attention_color === color ? null : color;
    router.patch(`/customers/${customer.id}/attention-color`, {
        attention_color: customer.attention_color,
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const attentionRowClass = (color: Customer['attention_color']) => ({
    green: 'bg-emerald-500/15 hover:bg-emerald-500/20',
    yellow: 'bg-amber-400/20 hover:bg-amber-400/25',
    red: 'bg-red-500/15 hover:bg-red-500/20',
}[color || ''] || 'hover:bg-muted/30');

onMounted(async () => {
    try {
        const savedOrder = props.columnOrder?.length
            ? props.columnOrder
            : JSON.parse(localStorage.getItem('customers-column-order') || '[]');
        if (Array.isArray(savedOrder)) {
            const validSavedColumns = savedOrder.filter((key) =>
                availableColumns.some((column) => column.key === key),
            );
            const missingColumns = availableColumns
                .map((column) => column.key)
                .filter((key) => !validSavedColumns.includes(key));
            if (validSavedColumns.length > 0) {
                const mergedColumns = [...validSavedColumns];
                missingColumns.forEach((key) => {
                    if (key === 'refund_date' && mergedColumns.includes('refunds')) {
                        mergedColumns.splice(mergedColumns.indexOf('refunds') + 1, 0, key);
                    } else if (key === 'adjustments' && mergedColumns.includes('refunds')) {
                        mergedColumns.splice(mergedColumns.indexOf('refunds') + 1, 0, key);
                    } else if (key === 'supplier_refunds' && mergedColumns.includes('costs')) {
                        mergedColumns.splice(mergedColumns.indexOf('costs') + 1, 0, key);
                    } else if (key === 'total_supplier_refunds' && mergedColumns.includes('supplier_refunds')) {
                        mergedColumns.splice(mergedColumns.indexOf('supplier_refunds') + 1, 0, key);
                    } else if (key === 'net_spent' && mergedColumns.includes('refunds')) {
                        const previousKey = mergedColumns.includes('refund_date') ? 'refund_date' : 'refunds';
                        mergedColumns.splice(mergedColumns.indexOf(previousKey) + 1, 0, key);
                    } else {
                        mergedColumns.push(key);
                    }
                });
                columnOrder.value = mergedColumns;
            }
        }
    } catch {
        localStorage.removeItem('customers-column-order');
    }
    await nextTick();
    const updateWidth = () => {
        maxScroll.value = Math.max(
            0,
            (tableScroll.value?.scrollWidth || 0) - (tableScroll.value?.clientWidth || 0),
        );
        scrollPosition.value = Math.min(scrollPosition.value, maxScroll.value);
    };
    updateWidth();
    resizeObserver = new ResizeObserver(updateWidth);
    if (tableScroll.value) {
        resizeObserver.observe(tableScroll.value);
    }
});

onBeforeUnmount(() => resizeObserver?.disconnect());

const resetSearch = () => {
    filters.search = '';
    filters.attention = '';
    applySearch();
};

const setAttentionFilter = (value: string) => {
    filters.attention = value;
    applySearch();
};

const customerName = (customer: Customer) =>
    [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Senza nome';

const formatPhone = (phone: string | null) => phone?.replace(/^['"]+/, '').trim() || '';

const formatAddress = (customer: Customer) => {
    if (!customer.address) {
        return 'N/D';
    }

    return [
        customer.address.line_1,
        [customer.address.zip, customer.address.city].filter(Boolean).join(' '),
        customer.address.province,
        customer.address.country,
    ].filter(Boolean).join(' • ') || 'N/D';
};

const euro = new Intl.NumberFormat('it-IT', {
    style: 'currency',
    currency: 'EUR',
});

const formatDate = (value: string | null) => value
    ? new Intl.DateTimeFormat('it-IT').format(new Date(value))
    : 'N/D';

</script>

<template>
    <Head title="Clienti" />

    <div class="grid min-w-0 max-w-full gap-6 overflow-hidden pb-20">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Clienti importati</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.customers }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Ordini dichiarati da Shopify</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.orders }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Ordini importati nel pannello</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.imported_orders }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Spesa complessiva</p>
                <p class="mt-2 text-3xl font-semibold">{{ euro.format(Number(props.stats.spent)) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Rimborsi: {{ euro.format(Number(props.stats.refunded)) }}
                </p>
            </div>
        </div>

        <section class="rounded-xl border border-sidebar-border/70 bg-card p-6">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div>
                    <h1 class="text-xl font-semibold">Importa ordini Shopify</h1>
                    <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Carica il CSV o Excel della sezione <strong>Orders</strong> di Matrixify.
                        Clienti, spesa e data dell’ultimo ordine vengono ricavati automaticamente.
                        Righe prodotto, pagamenti, resi e spedizioni vengono collegati allo stesso ordine.
                    </p>

                    <div
                        v-if="props.flashStatus"
                        class="mt-5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
                    >
                        {{ props.flashStatus }}
                    </div>
                </div>

                <form class="grid gap-3" @submit.prevent="submitOrderImport">
                    <label for="orders-file" class="text-sm font-medium">
                        File Orders CSV o Excel
                    </label>
                    <input
                        id="orders-file"
                        type="file"
                        accept=".csv,.txt,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        class="block w-full rounded-lg border border-sidebar-border/70 bg-background px-4 py-3 text-sm"
                        @change="updateOrderFile"
                    />
                    <p v-if="orderForm.errors.orders" class="text-sm text-destructive">
                        {{ orderForm.errors.orders }}
                    </p>
                    <button
                        type="submit"
                        class="rounded-lg bg-primary px-5 py-3 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="orderForm.processing || !orderForm.orders"
                    >
                        {{ orderForm.processing ? 'Importazione…' : 'Importa ordini' }}
                    </button>
                </form>
            </div>
        </section>

        <details class="rounded-xl border border-sidebar-border/70 bg-card p-6">
            <summary class="cursor-pointer font-medium">Importa clienti</summary>
            <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                <p class="text-sm text-muted-foreground">
                    Puoi caricare lo stesso export <strong>Orders</strong>: il sistema riconosce le colonne
                    Customer, gli importi e le date degli ordini. Resta compatibile anche con il precedente
                    export Customers.
                </p>
                <form class="grid gap-3" @submit.prevent="submitCustomerImport">
                    <label for="customers-file" class="text-sm font-medium">File Orders o Customers</label>
                    <input
                        id="customers-file"
                        type="file"
                        accept=".csv,.txt,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        class="block w-full rounded-lg border border-sidebar-border/70 bg-background px-4 py-3 text-sm"
                        @change="updateCustomerFile"
                    />
                    <p v-if="customerForm.errors.customers" class="text-sm text-destructive">
                        {{ customerForm.errors.customers }}
                    </p>
                    <button
                        type="submit"
                        class="rounded-lg border border-sidebar-border/70 px-5 py-3 text-sm font-medium disabled:opacity-50"
                        :disabled="customerForm.processing || !customerForm.customers"
                    >
                        {{ customerForm.processing ? 'Importazione…' : 'Importa clienti' }}
                    </button>
                </form>
            </div>
        </details>

        <section class="min-w-0 max-w-full overflow-hidden rounded-xl border border-sidebar-border/70 bg-card">
            <div class="sticky top-0 z-40 flex flex-col gap-4 border-b border-sidebar-border/70 bg-card/95 p-6 shadow-sm backdrop-blur sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">Anagrafica clienti</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cerca per nome, email, telefono o ID Shopify.
                    </p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div class="mr-2 flex items-center gap-1 rounded-lg border border-sidebar-border/70 bg-background p-1">
                        <button
                            v-for="option in [
                                { value: '', label: 'Tutti', dot: '' },
                                { value: 'colored', label: 'Attenzione', dot: 'bg-gradient-to-r from-emerald-500 via-amber-400 to-red-500' },
                                { value: 'green', label: 'Verde', dot: 'bg-emerald-500' },
                                { value: 'yellow', label: 'Giallo', dot: 'bg-amber-400' },
                                { value: 'red', label: 'Rosso', dot: 'bg-red-500' },
                            ]"
                            :key="option.value"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs transition"
                            :class="filters.attention === option.value
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground'"
                            @click="setAttentionFilter(option.value)"
                        >
                            <span v-if="option.dot" class="h-2.5 w-2.5 rounded-full" :class="option.dot" />
                            {{ option.label }}
                        </button>
                    </div>
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Cerca cliente"
                        class="w-full rounded-lg border border-sidebar-border/70 bg-background px-4 py-2.5 text-sm sm:w-64"
                        @keydown.enter.prevent="applySearch"
                    />
                    <button type="button" class="rounded-lg bg-primary px-4 py-2.5 text-sm text-primary-foreground" @click="applySearch">
                        Cerca
                    </button>
                    <button type="button" class="rounded-lg border border-sidebar-border/70 px-4 py-2.5 text-sm" @click="resetSearch">
                        Reset
                    </button>
                </div>
            </div>

            <Teleport to="body">
            <div class="fixed right-4 bottom-3 left-4 z-[9999] flex min-w-0 items-center gap-3 rounded-xl border-2 border-primary/60 bg-card px-4 py-3 shadow-2xl md:left-[17rem]">
                <button
                    type="button"
                    class="rounded-md border border-sidebar-border/70 bg-background px-3 py-1.5 text-sm disabled:opacity-40"
                    :disabled="scrollPosition <= 0"
                    aria-label="Scorri la tabella a sinistra"
                    @click="setHorizontalScroll(scrollPosition - 400)"
                >
                    ←
                </button>
                <input
                    :value="scrollPosition"
                    type="range"
                    min="0"
                    :max="Math.max(maxScroll, 1)"
                    step="1"
                    class="h-2 min-w-0 flex-1 cursor-ew-resize accent-primary"
                    aria-label="Posizione orizzontale della tabella"
                    @input="updateHorizontalScroll"
                />
                <button
                    type="button"
                    class="rounded-md border border-sidebar-border/70 bg-background px-3 py-1.5 text-sm disabled:opacity-40"
                    :disabled="scrollPosition >= maxScroll"
                    aria-label="Scorri la tabella a destra"
                    @click="setHorizontalScroll(scrollPosition + 400)"
                >
                    →
                </button>
                <span class="hidden whitespace-nowrap text-xs text-muted-foreground sm:inline">
                    Scorri colonne
                </span>
            </div>
            </Teleport>

            <div
                ref="tableScroll"
                class="max-h-[calc(100vh-9rem)] w-full max-w-full overflow-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                @scroll="syncScroll"
            >
                <table class="min-w-[2600px] divide-y divide-sidebar-border/70 text-sm">
                    <thead class="sticky top-0 z-20 bg-muted text-left text-muted-foreground shadow-sm">
                        <tr>
                            <th class="sticky left-0 z-10 w-32 whitespace-nowrap bg-muted/90 px-3 py-3 font-medium">
                                Attenzione
                            </th>
                            <th
                                v-for="(column, columnIndex) in orderedColumns"
                                :key="column.key"
                                class="whitespace-nowrap px-6 py-3 font-medium"
                                :class="{ 'text-right': ['orders', 'spent', 'refunds', 'adjustments', 'net_spent', 'total_costs', 'total_supplier_refunds'].includes(column.key) }"
                            >
                                <div class="inline-flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="rounded px-1.5 py-1 hover:bg-accent disabled:opacity-20"
                                        :disabled="column.key === 'net_spent' || columnIndex === 0"
                                        :aria-label="`Sposta ${column.label} a sinistra`"
                                        @click="moveColumn(column.key, -1)"
                                    >
                                        ←
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded px-1 py-1 hover:bg-accent hover:text-foreground"
                                        :aria-label="`Ordina per ${column.label}`"
                                        @click="sortBy(column.key)"
                                    >
                                        {{ column.label }}
                                        <span
                                            class="text-xs"
                                            :class="filters.sort === column.key ? 'text-primary' : 'text-muted-foreground/60'"
                                        >
                                            {{ sortIcon(column.key) }}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded px-1.5 py-1 hover:bg-accent disabled:opacity-20"
                                        :disabled="column.key === 'net_spent' || columnIndex >= orderedColumns.length - 2"
                                        :aria-label="`Sposta ${column.label} a destra`"
                                        @click="moveColumn(column.key, 1)"
                                    >
                                        →
                                    </button>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70">
                        <tr
                            v-for="customer in props.customers.data"
                            :key="customer.id"
                            class="transition-colors"
                            :class="attentionRowClass(customer.attention_color)"
                        >
                            <td class="sticky left-0 z-[5] w-32 px-3 py-4 align-top" :class="attentionRowClass(customer.attention_color)">
                                <div class="flex gap-2">
                                    <button
                                        v-for="color in (['green', 'yellow', 'red'] as const)"
                                        :key="color"
                                        type="button"
                                        class="h-6 w-6 rounded-full border-2 transition hover:scale-110"
                                        :class="[
                                            color === 'green' ? 'border-emerald-500 bg-emerald-500' : '',
                                            color === 'yellow' ? 'border-amber-400 bg-amber-400' : '',
                                            color === 'red' ? 'border-red-500 bg-red-500' : '',
                                            customer.attention_color === color
                                                ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                                                : 'opacity-55',
                                        ]"
                                        :title="`Evidenzia in ${color === 'green' ? 'verde' : color === 'yellow' ? 'giallo' : 'rosso'}`"
                                        @click="setAttentionColor(customer, color)"
                                    />
                                </div>
                            </td>
                            <template v-for="column in orderedColumns" :key="column.key">
                            <td v-if="column.key === 'customer'" class="px-6 py-4 align-top">
                                <p class="font-medium">{{ customerName(customer) }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Shopify: {{ customer.shopify_id || 'N/D' }}
                                </p>
                            </td>
                            <td v-else-if="column.key === 'contacts'" class="min-w-[560px] px-6 py-4 align-top">
                                <a v-if="customer.email" :href="`mailto:${customer.email}`" class="block hover:text-primary">
                                    {{ customer.email }}
                                </a>
                                <a
                                    v-if="formatPhone(customer.phone)"
                                    :href="`tel:${formatPhone(customer.phone)}`"
                                    class="mt-1 block text-muted-foreground hover:text-primary"
                                >
                                    Tel. {{ formatPhone(customer.phone) }}
                                </a>
                                <span v-if="!customer.email && !customer.phone" class="text-muted-foreground">N/D</span>
                                <div v-if="customer.contacts.length" class="mt-3 grid gap-2 border-t border-sidebar-border/50 pt-3">
                                    <div
                                        v-for="contact in customer.contacts"
                                        :key="contact.id || `new-contact-${customer.contacts.indexOf(contact)}`"
                                        class="grid grid-cols-[92px_minmax(0,1fr)_auto] items-start gap-2"
                                    >
                                        <select v-model="contact.type" class="rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs">
                                            <option value="email">Email</option>
                                            <option value="phone">Telefono</option>
                                            <option value="note">Nota</option>
                                        </select>
                                        <textarea
                                            v-if="contact.type === 'note'"
                                            v-model="contact.value"
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="Nota contatto"
                                            class="min-w-0 resize-y rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs"
                                        />
                                        <input
                                            v-else
                                            v-model="contact.value"
                                            :type="contact.type === 'email' ? 'email' : 'tel'"
                                            :placeholder="contact.type === 'email' ? 'email@esempio.com' : '+34…'"
                                            class="min-w-0 rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs"
                                        />
                                        <div class="flex gap-1">
                                            <button type="button" :disabled="!contact.value.trim()" class="rounded-md bg-primary px-2 py-1 text-xs text-primary-foreground disabled:opacity-40" title="Salva contatto" @click="saveContact(customer, contact)">✓</button>
                                            <button type="button" class="rounded-md border border-destructive/40 px-2 py-1 text-xs text-destructive" title="Elimina contatto" @click="deleteContact(customer, contact)">×</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    <button type="button" class="rounded-md border border-sidebar-border/70 px-2 py-1 text-xs hover:bg-accent" @click="addContactRow(customer, 'email')">+ Email</button>
                                    <button type="button" class="rounded-md border border-sidebar-border/70 px-2 py-1 text-xs hover:bg-accent" @click="addContactRow(customer, 'phone')">+ Telefono</button>
                                    <button type="button" class="rounded-md border border-sidebar-border/70 px-2 py-1 text-xs hover:bg-accent" @click="addContactRow(customer, 'note')">+ Nota</button>
                                </div>
                            </td>
                            <td v-else-if="column.key === 'location'" class="max-w-sm px-6 py-4 align-top text-muted-foreground">
                                {{ formatAddress(customer) }}
                            </td>
                            <td v-else-if="column.key === 'orders'" class="px-6 py-4 text-right align-top font-medium">
                                {{ customer.total_orders }}
                                <span
                                    v-if="customer.imported_orders !== customer.total_orders"
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                >
                                    {{ customer.imported_orders }} nel pannello
                                </span>
                            </td>
                            <td v-else-if="column.key === 'first_order_at'" class="px-6 py-4 align-top">
                                {{ formatDate(customer.first_order_at) }}
                            </td>
                            <td v-else-if="column.key === 'last_order_at'" class="px-6 py-4 align-top">
                                {{ formatDate(customer.last_order_at) }}
                                <span v-if="customer.latest_order" class="mt-1 block text-xs text-muted-foreground">
                                    {{ customer.latest_order.name }}
                                </span>
                            </td>
                            <td v-else-if="column.key === 'vehicle'" class="min-w-60 px-6 py-4 align-top">
                                <div v-if="customer.vehicles.length" class="grid gap-2">
                                    <div
                                        v-for="vehicle in customer.vehicles"
                                        :key="`${vehicle.brand}-${vehicle.model}-${vehicle.year_from}-${vehicle.year_to}`"
                                        class="leading-snug"
                                    >
                                        <p class="font-medium">
                                            {{ [vehicle.brand, vehicle.model].filter(Boolean).join(' ') }}
                                        </p>
                                        <p v-if="vehicle.year_from || vehicle.year_to" class="text-xs text-muted-foreground">
                                            {{ vehicle.year_from || '?' }}–{{ vehicle.year_to || '?' }}
                                        </p>
                                    </div>
                                </div>
                                <span v-else class="text-muted-foreground">N/D</span>
                            </td>
                            <td v-else-if="column.key === 'service'" class="min-w-72 px-6 py-4 align-top">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="customer.service.key === 'installation'
                                        ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-300'
                                        : customer.service.key === 'screen'
                                          ? 'bg-blue-500/15 text-blue-600 dark:text-blue-300'
                                          : 'bg-muted text-muted-foreground'"
                                >
                                    {{ customer.service.label }}
                                </span>
                                <p class="mt-2 max-w-80 text-xs text-muted-foreground">
                                    {{ customer.service.details }}
                                </p>
                            </td>
                            <td v-else-if="column.key === 'spent'" class="px-6 py-4 text-right align-top font-medium">
                                {{ euro.format(Number(customer.paid_total)) }}
                            </td>
                            <td v-else-if="column.key === 'refunds'" class="px-6 py-4 text-right align-top font-medium">
                                {{ euro.format(Number(customer.refunded_total)) }}
                            </td>
                            <td v-else-if="column.key === 'adjustments'" class="px-6 py-4 text-right align-top font-medium">
                                {{ euro.format(Number(customer.adjustment_total)) }}
                            </td>
                            <td v-else-if="column.key === 'refund_date'" class="whitespace-nowrap px-6 py-4 align-top">
                                {{ formatDate(customer.last_refund_at) }}
                            </td>
                            <td v-else-if="column.key === 'net_spent'" class="px-6 py-4 text-right align-top font-medium">
                                {{ euro.format(Number(customer.net_total)) }}
                            </td>
                            <td v-else-if="column.key === 'latest_purchase'" class="min-w-[520px] px-6 py-4 align-top">
                                <div
                                    v-if="customer.latest_order?.products.length"
                                    class="grid divide-y divide-sidebar-border/50"
                                >
                                    <div
                                        v-for="product in customer.latest_order.products"
                                        :key="`${product.sku || product.title}-${product.quantity}`"
                                        class="grid grid-cols-[minmax(0,1fr)_110px] items-center gap-4 py-2 first:pt-0 last:pb-0"
                                        :class="product.cancelled ? 'text-muted-foreground line-through decoration-red-500 decoration-2' : ''"
                                    >
                                        <p class="truncate" :title="product.title">
                                            <span v-if="product.quantity > 1">{{ product.quantity }}× </span>{{ product.title }}
                                        </p>
                                        <div class="text-right">
                                            <p class="whitespace-nowrap font-medium">
                                                {{ euro.format(Number(product.total)) }}
                                            </p>
                                            <p v-if="product.quantity > 1" class="text-xs text-muted-foreground">
                                                {{ euro.format(Number(product.price)) }} cad.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <span v-else class="text-muted-foreground">N/D</span>
                            </td>
                            <td v-else-if="column.key === 'costs'" class="min-w-[620px] px-6 py-4 align-top">
                                <div class="grid gap-2">
                                    <div
                                        v-for="cost in customer.costs"
                                        :key="cost.id || `new-${customer.costs.indexOf(cost)}`"
                                        class="grid grid-cols-[minmax(0,1fr)_100px_75px_140px_auto] items-center gap-2"
                                    >
                                        <input
                                            v-model="cost.description"
                                            type="text"
                                            maxlength="255"
                                            placeholder="Descrizione costo"
                                            class="min-w-0 rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs"
                                        />
                                        <input
                                            v-model="cost.amount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="0,00"
                                            class="w-full rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-right text-xs"
                                        />
                                        <select
                                            v-model="cost.currency"
                                            class="rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs"
                                        >
                                            <option value="EUR">EUR</option>
                                            <option value="USD">USD</option>
                                        </select>
                                        <span class="whitespace-nowrap text-right text-xs text-muted-foreground">
                                            <template v-if="cost.currency === 'EUR' || cost.id">
                                                = {{ euro.format(costEuroValue(cost)) }}
                                            </template>
                                            <template v-else>
                                                Cambio automatico
                                            </template>
                                        </span>
                                        <div class="flex gap-1">
                                            <button
                                                type="button"
                                                class="rounded-md bg-primary px-2 py-1 text-xs text-primary-foreground disabled:opacity-40"
                                                :disabled="!cost.description.trim()
                                                    || cost.amount === ''"
                                                title="Salva costo"
                                                @click="saveCost(customer, cost)"
                                            >
                                                ✓
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md border border-destructive/40 px-2 py-1 text-xs text-destructive"
                                                title="Elimina costo"
                                                @click="deleteCost(customer, cost)"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="w-fit rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:bg-accent"
                                        @click="addCostRow(customer)"
                                    >
                                        + Aggiungi costo
                                    </button>
                                </div>
                            </td>
                            <td v-else-if="column.key === 'total_costs'" class="whitespace-nowrap px-6 py-4 text-right align-top font-semibold">
                                {{ euro.format(Number(customer.costs_total)) }}
                            </td>
                            <td v-else-if="column.key === 'supplier_refunds'" class="min-w-[620px] px-6 py-4 align-top">
                                <div class="grid gap-2">
                                    <div
                                        v-for="refund in customer.supplier_refunds"
                                        :key="refund.id || `new-refund-${customer.supplier_refunds.indexOf(refund)}`"
                                        class="grid grid-cols-[minmax(0,1fr)_100px_75px_140px_auto] items-center gap-2"
                                    >
                                        <input
                                            v-model="refund.description"
                                            type="text"
                                            maxlength="255"
                                            placeholder="Descrizione rimborso"
                                            class="min-w-0 rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs"
                                        />
                                        <input
                                            v-model="refund.amount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="0,00"
                                            class="w-full rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-right text-xs"
                                        />
                                        <select
                                            v-model="refund.currency"
                                            class="rounded-md border border-sidebar-border/70 bg-background px-2 py-1.5 text-xs"
                                        >
                                            <option value="EUR">EUR</option>
                                            <option value="USD">USD</option>
                                        </select>
                                        <span class="whitespace-nowrap text-right text-xs text-muted-foreground">
                                            <template v-if="refund.currency === 'EUR' || refund.id">
                                                = {{ euro.format(supplierRefundEuroValue(refund)) }}
                                            </template>
                                            <template v-else>
                                                Cambio automatico
                                            </template>
                                        </span>
                                        <div class="flex gap-1">
                                            <button
                                                type="button"
                                                class="rounded-md bg-emerald-600 px-2 py-1 text-xs text-white disabled:opacity-40"
                                                :disabled="!refund.description.trim() || refund.amount === ''"
                                                title="Salva rimborso da fornitore"
                                                @click="saveSupplierRefund(customer, refund)"
                                            >
                                                ✓
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md border border-destructive/40 px-2 py-1 text-xs text-destructive"
                                                title="Elimina rimborso da fornitore"
                                                @click="deleteSupplierRefund(customer, refund)"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="w-fit rounded-md border border-emerald-600/50 px-3 py-1.5 text-xs font-medium text-emerald-600 hover:bg-emerald-500/10"
                                        @click="addSupplierRefundRow(customer)"
                                    >
                                        + Aggiungi rimborso
                                    </button>
                                </div>
                            </td>
                            <td v-else-if="column.key === 'total_supplier_refunds'" class="whitespace-nowrap px-6 py-4 text-right align-top font-semibold text-emerald-600">
                                {{ euro.format(Number(customer.supplier_refunds_total)) }}
                            </td>
                            <td v-else-if="column.key === 'notes'" class="min-w-72 px-6 py-4 align-top">
                                <p v-if="customer.note" class="max-w-80 truncate text-sm" :title="customer.note">
                                    {{ customer.note }}
                                </p>
                                <p v-else class="text-sm text-muted-foreground">Nessuna nota</p>
                                <button
                                    type="button"
                                    class="mt-2 rounded-md border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:bg-accent"
                                    @click="openNoteModal(customer)"
                                >
                                    Vedi
                                </button>
                            </td>
                            </template>
                        </tr>
                        <tr v-if="props.customers.data.length === 0">
                            <td colspan="18" class="px-6 py-10 text-center text-muted-foreground">
                                Nessun cliente importato.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="props.customers.links.length > 3"
                class="flex flex-wrap gap-2 border-t border-sidebar-border/70 p-4"
            >
                <component
                    :is="link.url ? 'a' : 'span'"
                    v-for="link in props.customers.links"
                    :key="link.label"
                    :href="link.url || undefined"
                    class="rounded-md px-3 py-2 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : link.url
                              ? 'border border-sidebar-border/70 hover:bg-accent'
                              : 'text-muted-foreground'
                    "
                    v-html="link.label"
                />
            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="noteModalCustomer"
                class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/70 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="customer-note-title"
                @click.self="closeNoteModal"
            >
                <section class="w-full max-w-3xl rounded-2xl border border-sidebar-border/70 bg-card p-6 shadow-2xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 id="customer-note-title" class="text-xl font-semibold">Nota cliente</h2>
                            <p class="mt-1 text-sm text-muted-foreground">{{ customerName(noteModalCustomer) }}</p>
                        </div>
                        <button type="button" :disabled="noteSaving" class="rounded-md px-2 py-1 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-40" aria-label="Chiudi" @click="closeNoteModal">✕</button>
                    </div>

                    <textarea
                        v-model="noteDraft"
                        autofocus
                        rows="14"
                        maxlength="5000"
                        placeholder="Inserisci una nota sul cliente…"
                        class="mt-5 min-h-72 w-full resize-y rounded-xl border border-sidebar-border/70 bg-background px-4 py-3 text-sm leading-6"
                        @keydown.meta.enter.prevent="saveNote"
                        @keydown.ctrl.enter.prevent="saveNote"
                    />
                    <div class="mt-2 text-right text-xs text-muted-foreground">{{ noteDraft.length }}/5000</div>

                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" :disabled="noteSaving" class="rounded-lg border border-sidebar-border/70 px-4 py-2 text-sm hover:bg-accent disabled:opacity-40" @click="closeNoteModal">Annulla</button>
                        <button type="button" :disabled="noteSaving" class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50" @click="saveNote">
                            {{ noteSaving ? 'Salvataggio…' : 'Salva nota' }}
                        </button>
                    </div>
                </section>
            </div>
        </Teleport>
    </div>
</template>
