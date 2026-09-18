<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { orderDate, orderMoney, type ItalianOrderSummary } from '@/types/italian-orders';

const props = defineProps<{
    testOrderCount: number;
    flashStatus?: string | null;
    orders: {
        data: ItalianOrderSummary[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search?: string; payment_status?: string; fulfillment_status?: string };
    paymentStatuses: Record<string, string>;
    fulfillmentStatuses: Record<string, string>;
}>();

const deletion = useForm({});
const deleteTests = () => {
    if (window.confirm(`Eliminare tutti i ${props.testOrderCount} ordini di prova dall’elenco? Le transazioni sandbox restano su Stripe.`)) deletion.delete('/italian-orders/test-orders');
};

const filters = useForm({
    search: props.filters.search ?? '',
    payment_status: props.filters.payment_status ?? '',
    fulfillment_status: props.filters.fulfillment_status ?? '',
});
const search = () => filters.get('/italian-orders', { preserveState: true, replace: true });
</script>

<template>
    <Head title="Ordini italiani" />
    <div class="flex flex-col gap-6 p-4">
        <header>
            <p class="text-sm text-muted-foreground">autoradioitaliano.it</p>
            <h1 class="mt-1 text-2xl font-semibold">Ordini italiani</h1>
            <p class="mt-2 text-sm text-muted-foreground">Consulta gli acquisti e gestisci la preparazione e la spedizione dei prodotti.</p>
        </header>

        <p v-if="flashStatus" role="status" class="text-sm text-green-600">{{ flashStatus }}</p>
        <button v-if="testOrderCount" type="button" :disabled="deletion.processing" class="self-start rounded-md border border-destructive px-4 py-2 text-sm text-destructive disabled:opacity-50" @click="deleteTests">Elimina tutti gli ordini di prova ({{ testOrderCount }})</button>

        <form class="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="search">
            <label class="grid gap-2 text-sm">Cerca ordine o cliente
                <input v-model="filters.search" type="search" maxlength="200" placeholder="Numero, nome o email" class="min-w-0 rounded-md border bg-background px-3 py-2" />
            </label>
            <label class="grid gap-2 text-sm">Pagamento
                <select v-model="filters.payment_status" class="rounded-md border bg-background px-3 py-2">
                    <option value="">Tutti i pagamenti</option>
                    <option v-for="(label, value) in paymentStatuses" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <label class="grid gap-2 text-sm">Spedizione
                <select v-model="filters.fulfillment_status" class="rounded-md border bg-background px-3 py-2">
                    <option value="">Tutte le spedizioni</option>
                    <option v-for="(label, value) in fulfillmentStatuses" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <div class="flex items-end gap-3">
                <button type="submit" :disabled="filters.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">Cerca</button>
                <Link href="/italian-orders" class="px-2 py-2 text-sm underline">Azzera filtri</Link>
            </div>
            <p v-for="(error, key) in filters.errors" :key="key" class="text-sm text-destructive" role="alert">{{ error }}</p>
        </form>

        <section class="overflow-hidden rounded-xl border bg-card" aria-label="Elenco ordini">
            <div class="border-b px-5 py-4 text-sm text-muted-foreground">{{ orders.total }} ordini trovati</div>
            <div v-if="orders.data.length === 0" class="p-10 text-center">
                <h2 class="font-semibold">{{ filters.search || filters.payment_status || filters.fulfillment_status ? 'Nessun ordine corrisponde ai filtri' : 'Nessun ordine italiano ricevuto' }}</h2>
                <p class="mt-2 text-sm text-muted-foreground">{{ filters.search || filters.payment_status || filters.fulfillment_status ? 'Modifica i filtri per ampliare la ricerca.' : 'Qui compariranno gli acquisti effettuati quando il checkout italiano sarà attivo.' }}</p>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/40 text-muted-foreground"><tr>
                        <th class="p-4">Ordine / Data</th><th class="p-4">Cliente</th><th class="p-4">Pagamento</th><th class="p-4">Spedizione</th><th class="p-4 text-right">Totale</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="order in orders.data" :key="order.id" class="border-t hover:bg-muted/20">
                            <td class="p-4"><Link :href="`/italian-orders/${order.id}`" class="font-medium underline underline-offset-4">{{ order.number }}</Link><span v-if="order.is_test" class="ml-2 rounded bg-amber-500/15 px-2 py-1 text-xs text-amber-600">Prova</span><p class="mt-1 text-xs text-muted-foreground">{{ orderDate(order.created_at) }}</p></td>
                            <td class="p-4"><p>{{ order.customer_name }}</p><p class="text-xs text-muted-foreground">{{ order.email }}</p></td>
                            <td class="p-4"><span class="rounded-full bg-muted px-2 py-1 text-xs">{{ paymentStatuses[order.payment_status] ?? order.payment_status }}</span></td>
                            <td class="p-4">{{ fulfillmentStatuses[order.fulfillment_status] ?? order.fulfillment_status }}</td>
                            <td class="whitespace-nowrap p-4 text-right font-medium">{{ orderMoney(order.total_amount, order.currency) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav v-if="orders.last_page > 1" class="flex items-center justify-between gap-3 border-t p-4 text-sm" aria-label="Pagine ordini">
                <Link v-if="orders.prev_page_url" :href="orders.prev_page_url" class="rounded border px-3 py-2">Precedente</Link><span v-else />
                <span>Pagina {{ orders.current_page }} di {{ orders.last_page }}</span>
                <Link v-if="orders.next_page_url" :href="orders.next_page_url" class="rounded border px-3 py-2">Successiva</Link><span v-else />
            </nav>
        </section>
    </div>
</template>
