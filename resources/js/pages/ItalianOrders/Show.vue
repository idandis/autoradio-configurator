<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { orderDate, orderMoney, type ItalianOrderDetail } from '@/types/italian-orders';

const props = defineProps<{
    order: ItalianOrderDetail;
    paymentStatuses: Record<string, string>;
    fulfillmentStatuses: Record<string, string>;
    allowedFulfillmentStatuses: string[];
    refundsEnabled: boolean;
    purchaseEmail: { status: string; prepared_at: string | null } | null;
    flashStatus?: string | null;
}>();

const refundForm = useForm({ amount: '', version: props.order.version });
const refunded = computed(() => props.order.refunds.filter(r => r.status === 'succeeded').reduce((sum, r) => sum + r.amount, 0));
const available = computed(() => Math.max(0, props.order.total_amount - props.order.refunds.filter(r => !['failed', 'canceled'].includes(r.status)).reduce((sum, r) => sum + r.amount, 0)));
const creating = computed(() => props.order.refunds.find(r => r.status === 'creating'));
const refundLabels: Record<string, string> = { creating: 'Da verificare', pending: 'In attesa di Stripe', requires_action: 'Richiede intervento su Stripe', succeeded: 'Riuscito', failed: 'Non riuscito', canceled: 'Annullato' };
const submitRefund = () => {
    if (!window.confirm(`Inviare a Stripe${props.order.is_test ? ' sandbox' : ''} un rimborso di € ${refundForm.amount}? Lo stato della spedizione non cambierà.`)) return;
    refundForm.version = props.order.version;
    refundForm.post(`/italian-orders/${props.order.id}/refund`, { preserveScroll: true, onSuccess: () => { refundForm.amount = ''; } });
};
const syncRefunds = () => refundForm.post(`/italian-orders/${props.order.id}/refunds/sync`, { preserveScroll: true });
const retryRefund = () => { if (creating.value) { refundForm.amount = (creating.value.amount / 100).toFixed(2); submitRefund(); } };
const cancellation = useForm({ version: props.order.version });
const cancelOrder = () => {
    if (!window.confirm('Annullare questo ordine? Resterà visibile, ma la spedizione sarà bloccata. Nessun rimborso verrà eseguito.')) return;
    cancellation.version = props.order.version;
    cancellation.post(`/italian-orders/${props.order.id}/cancel`, { preserveScroll: true });
};
const deletion = useForm({});
const deleteTest = () => {
    if (window.confirm('Eliminare questo ordine di prova dall’elenco? La transazione sandbox resta su Stripe.')) deletion.delete(`/italian-orders/${props.order.id}`);
};

const form = useForm({
    version: props.order.version,
    fulfillment_status: props.order.fulfillment_status,
    carrier: props.order.carrier ?? '',
    tracking_number: props.order.tracking_number ?? '',
    internal_notes: props.order.internal_notes ?? '',
});
const resetForm = () => {
    const order = props.order;
    form.defaults({
        version: order.version,
        fulfillment_status: order.fulfillment_status,
        carrier: order.carrier ?? '',
        tracking_number: order.tracking_number ?? '',
        internal_notes: order.internal_notes ?? '',
    });
    form.reset();
    form.clearErrors();
};
const save = () => form.patch(`/italian-orders/${props.order.id}`, { preserveScroll: true, onSuccess: resetForm });
const reloadOrder = () => router.reload({ onSuccess: resetForm });
const money = (cents: number) => orderMoney(cents, props.order.currency);
const changeLabels: Record<string, string> = { carrier: 'Corriere', tracking_number: 'Tracciamento', internal_notes: 'Note interne', refund: 'Rimborso' };
</script>

<template>
    <Head :title="`Ordine ${order.number}`" />
    <div class="flex flex-col gap-6 p-4">
        <header>
            <Link href="/italian-orders" class="text-sm underline">← Ordini italiani</Link>
            <h1 class="mt-3 break-all text-xl font-semibold">{{ order.number }}</h1>
            <p v-if="order.is_test" class="mt-2 text-sm font-medium text-amber-600">Ordine di prova · nessun addebito reale</p>
            <p class="mt-1 text-sm text-muted-foreground">Ricevuto il {{ orderDate(order.created_at) }}</p>
        </header>
        <p v-if="flashStatus" role="status" class="rounded-lg border border-green-500/40 bg-green-500/10 p-4 text-sm">{{ flashStatus }}</p>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <section class="overflow-hidden rounded-xl border bg-card">
                    <h2 class="border-b p-5 font-semibold">Prodotti ordinati</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-muted-foreground"><tr><th class="p-4">Prodotto</th><th class="p-4 text-right">Prezzo</th><th class="p-4 text-right">Quantità</th><th class="p-4 text-right">Importo</th></tr></thead>
                            <tbody><tr v-for="item in order.items" :key="item.id" class="border-t">
                                <td class="p-4"><p class="font-medium">{{ item.title }}</p><p v-if="item.variant_title" class="mt-1 text-muted-foreground">{{ item.variant_title }}</p><p v-if="item.sku" class="mt-1 text-xs text-muted-foreground">SKU: {{ item.sku }}</p></td>
                                <td class="whitespace-nowrap p-4 text-right">{{ money(item.unit_amount) }}</td><td class="p-4 text-right">{{ item.quantity }}</td><td class="whitespace-nowrap p-4 text-right">{{ money(item.total_amount) }}</td>
                            </tr></tbody>
                        </table>
                    </div>
                    <dl class="space-y-3 border-t p-5 text-sm">
                        <div class="flex justify-between gap-4"><dt>Prodotti</dt><dd>{{ money(order.subtotal_amount) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>Spedizione</dt><dd>{{ money(order.shipping_amount) }}</dd></div>
                        <div v-if="order.discount_amount" class="flex justify-between gap-4"><dt>Sconto</dt><dd>−{{ money(order.discount_amount) }}</dd></div>
                        <div class="flex justify-between gap-4 border-t pt-3 text-base font-semibold"><dt>Totale ordine</dt><dd>{{ money(order.total_amount) }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Cliente</h2>
                    <p class="mt-3">{{ order.customer_name }}</p><p class="break-all text-sm">{{ order.email }}</p><p v-if="order.phone" class="text-sm">{{ order.phone }}</p>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div v-for="(address, key) in { shipping: order.shipping_address, billing: order.billing_address }" :key="key">
                            <h3 class="text-sm font-medium">{{ key === 'shipping' ? 'Indirizzo di spedizione' : 'Indirizzo di fatturazione' }}</h3>
                            <address v-if="address" class="mt-2 text-sm leading-6 not-italic text-muted-foreground">
                                <p v-if="address.name">{{ address.name }}</p><p>{{ address.line1 }}</p><p v-if="address.line2">{{ address.line2 }}</p><p>{{ address.postal_code }} {{ address.city }} ({{ address.province }})</p><p>{{ address.country }}</p>
                            </address>
                            <p v-else class="mt-2 text-sm text-muted-foreground">Non indicato</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Cronologia gestione ordine</h2>
                    <p v-if="!order.events.length" class="mt-3 text-sm text-muted-foreground">Nessuna modifica registrata.</p>
                    <ol v-else class="mt-4 space-y-4">
                        <li v-for="event in order.events" :key="event.id" class="border-l-2 pl-4 text-sm">
                            <p class="text-xs text-muted-foreground">{{ orderDate(event.created_at) }} · {{ event.kind === 'payment' ? (order.is_test ? 'Stripe sandbox' : 'Stripe') : (event.user?.name ?? 'Operatore non più disponibile') }}</p>
                            <p v-if="event.from_status !== event.to_status" class="mt-1 font-medium">{{ (event.kind === 'payment' ? paymentStatuses : fulfillmentStatuses)[event.from_status] }} → {{ (event.kind === 'payment' ? paymentStatuses : fulfillmentStatuses)[event.to_status] }}</p>
                            <template v-for="(label, key) in changeLabels" :key="key">
                                <p v-if="key in event.changes" class="mt-1 whitespace-pre-wrap break-words">{{ label }}: {{ event.changes[key] || 'Rimosso' }}</p>
                            </template>
                        </li>
                    </ol>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Email conferma acquisto</h2>
                    <template v-if="purchaseEmail">
                        <p class="text-sm">{{ purchaseEmail.status === 'sent' ? 'Inviata' : purchaseEmail.status === 'preview' ? 'Preparata in locale · non inviata' : 'In attesa · non inviata' }}</p>
                        <a :href="`/italian-orders/${order.id}/purchase-email`" target="_blank" rel="noopener" class="text-sm underline">Visualizza email</a>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">Viene generata alla conferma dei nuovi pagamenti.</p>
                </section>
                <section v-if="order.fulfillment_status === 'cancelled'" role="status" class="rounded-xl border p-5">
                    <h2 class="font-semibold">Ordine annullato</h2>
                    <p class="mt-2 text-sm">Gestione della spedizione bloccata. L’annullamento non comporta un rimborso.</p>
                </section>
                <button v-if="['pending', 'processing'].includes(order.fulfillment_status)" type="button" :disabled="cancellation.processing || form.processing" class="w-full rounded-md border border-destructive px-4 py-2 text-sm text-destructive disabled:opacity-50" @click="cancelOrder">Annulla ordine</button>
                <p v-for="(error, key) in cancellation.errors" :key="key" role="alert" class="text-sm text-destructive">{{ error }}</p>
                <button v-if="cancellation.errors.version" class="text-sm underline" @click="reloadOrder">Ricarica ordine</button>
                <button v-if="order.is_test" type="button" :disabled="deletion.processing" class="w-full rounded-md border border-destructive px-4 py-2 text-sm text-destructive disabled:opacity-50" @click="deleteTest">Elimina ordine di prova</button>
                <section class="rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Pagamento</h2>
                    <p class="mt-3 font-medium">{{ paymentStatuses[order.payment_status] ?? order.payment_status }}</p>
                    <p v-if="order.paid_at" class="mt-2 text-sm text-muted-foreground">Pagato il {{ orderDate(order.paid_at) }}</p>
                    <p class="mt-3 text-sm text-muted-foreground">Lo stato del pagamento viene aggiornato dal sistema di pagamento.</p>
                </section>

                <section v-if="refundsEnabled && ['paid', 'partially_refunded', 'refunded'].includes(order.payment_status)" class="space-y-4 rounded-xl border bg-card p-5">
                    <h2 class="font-semibold">Rimborsi · Stripe{{ order.is_test ? ' sandbox' : '' }}</h2>
                    <p class="text-sm">Rimborsato: {{ money(refunded) }} · Disponibile: {{ money(available) }}</p>
                    <p class="text-sm text-muted-foreground">Rimborso totale o parziale sulla carta utilizzata. Non annulla la spedizione.</p>
                    <ul v-if="order.refunds.length" class="space-y-2 text-sm">
                        <li v-for="refund in order.refunds" :key="refund.id">{{ money(refund.amount) }} · {{ refundLabels[refund.status] ?? refund.status }} · {{ orderDate(refund.created_at) }}</li>
                    </ul>
                    <form v-if="available > 0 && !creating" class="space-y-3" @submit.prevent="submitRefund">
                        <label class="grid gap-2 text-sm">Importo da rimborsare (€)<input v-model="refundForm.amount" inputmode="decimal" required placeholder="Es. 20,00" class="rounded-md border bg-background px-3 py-2" /></label>
                        <button type="button" class="text-sm underline" @click="refundForm.amount = (available / 100).toFixed(2)">Tutto il residuo ({{ money(available) }})</button>
                        <button type="submit" :disabled="refundForm.processing" class="w-full rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">Rimborsa</button>
                    </form>
                    <button v-if="creating" type="button" :disabled="refundForm.processing" class="text-sm underline" @click="retryRefund">Verifica / riprova la richiesta di {{ money(creating.amount) }}</button>
                    <button type="button" :disabled="refundForm.processing" class="text-sm underline" @click="syncRefunds">Aggiorna stato rimborsi</button>
                    <p v-for="(error, key) in refundForm.errors" :key="key" role="alert" class="text-sm text-destructive">{{ error }}</p>
                </section>

                <form v-if="order.fulfillment_status !== 'cancelled'" class="space-y-4 rounded-xl border bg-card p-5" @submit.prevent="save">
                    <h2 class="font-semibold">Gestione spedizione</h2>
                    <label class="grid gap-2 text-sm">Stato
                        <select v-model="form.fulfillment_status" class="rounded-md border bg-background px-3 py-2">
                            <option v-for="status in allowedFulfillmentStatuses" :key="status" :value="status">{{ fulfillmentStatuses[status] ?? status }}</option>
                        </select>
                    </label>
                    <p v-if="!['paid', 'partially_refunded'].includes(order.payment_status) && ['pending', 'processing'].includes(order.fulfillment_status)" class="text-sm text-muted-foreground">La preparazione e la spedizione richiedono un pagamento confermato.</p>
                    <label class="grid gap-2 text-sm">Corriere<input v-model="form.carrier" maxlength="100" class="rounded-md border bg-background px-3 py-2" /></label>
                    <label class="grid gap-2 text-sm">Codice di tracciamento<input v-model="form.tracking_number" maxlength="255" class="rounded-md border bg-background px-3 py-2" /></label>
                    <p v-if="order.shipped_at" class="text-sm text-muted-foreground">Spedito il {{ orderDate(order.shipped_at) }}</p>
                    <p v-if="order.delivered_at" class="text-sm text-muted-foreground">Consegnato il {{ orderDate(order.delivered_at) }}</p>
                    <label class="grid gap-2 text-sm">Note interne<textarea v-model="form.internal_notes" rows="5" maxlength="10000" class="rounded-md border bg-background px-3 py-2" /><span class="text-xs text-muted-foreground">Visibili solo agli amministratori.</span></label>
                    <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-destructive">{{ error }}</p>
                    <button v-if="form.errors.version" type="button" class="text-sm underline" @click="reloadOrder">Ricarica ordine</button>
                    <button type="submit" :disabled="form.processing || !!form.errors.version" class="w-full rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{{ form.processing ? 'Salvataggio…' : 'Salva modifiche' }}</button>
                </form>
            </aside>
        </div>
    </div>
</template>
