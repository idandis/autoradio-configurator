<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import ItalianCheckoutLayout from '@/layouts/ItalianCheckoutLayout.vue';
import { orderMoney } from '@/types/italian-orders';

defineOptions({ layout: ItalianCheckoutLayout });
const props = defineProps<{
    order: { number: string; customer_name: string; total_amount: number; currency: string; payment_status: string; fulfillment_status: string; is_test: boolean };
    paymentUrl: string;
    syncError: boolean;
}>();
const cancelled = computed(() => props.order.fulfillment_status === 'cancelled');
const paid = computed(() => props.order.payment_status === 'paid');
const labels: Record<string, string> = { pending: 'In attesa di pagamento', paid: 'Pagato', failed: 'Pagamento non riuscito o scaduto', partially_refunded: 'Rimborsato parzialmente', refunded: 'Rimborsato' };
</script>

<template>
    <Head :title="paid ? 'Pagamento riuscito' : 'Stato ordine'" />
    <section class="mx-auto max-w-2xl rounded-2xl border border-neutral-700 bg-neutral-900/50 p-6 sm:p-10">
        <p class="font-semibold" :class="paid ? 'text-emerald-300' : 'text-amber-300'">{{ paid ? 'Pagamento riuscito' : 'Ordine registrato' }}</p>
        <p v-if="cancelled" class="mt-4 font-semibold text-amber-300">Ordine annullato. Nessun rimborso automatico.</p>
        <h1 class="mt-3 text-3xl font-bold">{{ paid ? 'Grazie' : 'Ciao' }}, {{ order.customer_name }}.</h1>
        <p class="mt-4 break-all text-sm text-neutral-400">Numero ordine: <strong class="text-white">{{ order.number }}</strong></p>
        <dl class="mt-6 space-y-4 border-y border-neutral-800 py-6">
            <div class="flex justify-between gap-4"><dt>Totale</dt><dd class="font-bold text-amber-400">{{ orderMoney(order.total_amount, order.currency) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Spedizione</dt><dd>Gratuita</dd></div>
            <div class="flex justify-between gap-4"><dt>Pagamento</dt><dd>{{ labels[order.payment_status] ?? order.payment_status }}</dd></div>
        </dl>
        <p v-if="syncError" role="alert" class="mt-4 text-sm text-amber-300">Non è stato possibile verificare ora il pagamento. Attendi qualche secondo e aggiorna lo stato.</p>
        <p v-if="!cancelled && order.is_test" class="mt-6 text-sm leading-6 text-neutral-400">{{ paid ? 'Stripe ha confermato il pagamento simulato.' : 'L’ordine è salvato. Puoi completare o riprovare il pagamento di prova.' }} Nessun addebito reale e nessuna spedizione. La conferma d’acquisto viene preparata solo in locale, senza invio email.</p>
        <p v-if="!cancelled && !order.is_test" class="mt-6 text-sm leading-6 text-neutral-400">{{ paid ? 'Abbiamo ricevuto il pagamento. Riceverai la conferma d’acquisto via email.' : ['refunded', 'partially_refunded'].includes(order.payment_status) ? 'Il rimborso è stato registrato. I tempi di accredito dipendono dalla tua banca.' : 'L’ordine è salvato. Puoi completare o riprovare il pagamento.' }}</p>
        <div v-if="!cancelled && ['pending', 'failed'].includes(order.payment_status)" class="mt-6 flex flex-wrap gap-4">
            <a :href="paymentUrl" class="rounded-lg bg-amber-400 px-5 py-3 font-semibold text-black">{{ order.payment_status === 'failed' ? 'Riprova pagamento' : 'Vai al pagamento' }}</a>
            <button type="button" class="text-sm underline" @click="router.reload()">Aggiorna stato</button>
        </div>
        <a href="/configurator?lang=it" class="mt-7 inline-block text-sm underline">Torna al configuratore</a>
    </section>
</template>
