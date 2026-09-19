<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import ItalianCheckoutLayout from '@/layouts/ItalianCheckoutLayout.vue';
import { orderMoney } from '@/types/italian-orders';

defineOptions({ layout: ItalianCheckoutLayout });
const props = defineProps<{
    checkoutLocale: 'it' | 'es';
    order: { number: string; customer_name: string; total_amount: number; currency: string; payment_status: string; fulfillment_status: string; is_test: boolean };
    paymentUrl: string;
    syncError: boolean;
}>();
const cancelled = computed(() => props.order.fulfillment_status === 'cancelled');
const paid = computed(() => props.order.payment_status === 'paid');
const copy = props.checkoutLocale === 'es' ? {
    success: 'Pago realizado', registered: 'Pedido registrado', cancelled: 'Pedido cancelado. No se ha realizado ningún reembolso automático.', thanks: 'Gracias', hello: 'Hola', number: 'Número de pedido', total: 'Total', shipping: 'Entrega', included: 'Incluida', payment: 'Pago', sync: 'Ahora no ha sido posible comprobar el pago. Espera unos segundos y actualiza el estado.', testPaid: 'Stripe ha confirmado el pago simulado.', testPending: 'El pedido está guardado. Puedes completar o reintentar el pago de prueba.', testNotice: 'No hay ningún cargo real ni envío. La confirmación se prepara solo localmente, sin enviar emails.', livePaid: 'Hemos recibido el pago. Recibirás la confirmación de compra por email.', refunded: 'El reembolso ha sido registrado. El plazo depende de tu banco.', livePending: 'El pedido está guardado. Puedes completar o reintentar el pago.', retry: 'Reintentar pago', pay: 'Ir al pago', refresh: 'Actualizar estado', back: 'Volver al configurador',
    labels: { pending: 'Pendiente de pago', paid: 'Pagado', failed: 'Pago fallido o caducado', partially_refunded: 'Reembolsado parcialmente', refunded: 'Reembolsado' } as Record<string, string>,
} : {
    success: 'Pagamento riuscito', registered: 'Ordine registrato', cancelled: 'Ordine annullato. Nessun rimborso automatico.', thanks: 'Grazie', hello: 'Ciao', number: 'Numero ordine', total: 'Totale', shipping: 'Spedizione', included: 'Inclusa', payment: 'Pagamento', sync: 'Non è stato possibile verificare ora il pagamento. Attendi qualche secondo e aggiorna lo stato.', testPaid: 'Stripe ha confermato il pagamento simulato.', testPending: 'L’ordine è salvato. Puoi completare o riprovare il pagamento di prova.', testNotice: 'Nessun addebito reale e nessuna spedizione. La conferma d’acquisto viene preparata solo in locale, senza invio email.', livePaid: 'Abbiamo ricevuto il pagamento. Riceverai la conferma d’acquisto via email.', refunded: 'Il rimborso è stato registrato. I tempi di accredito dipendono dalla tua banca.', livePending: 'L’ordine è salvato. Puoi completare o riprovare il pagamento.', retry: 'Riprova pagamento', pay: 'Vai al pagamento', refresh: 'Aggiorna stato', back: 'Torna al configuratore',
    labels: { pending: 'In attesa di pagamento', paid: 'Pagato', failed: 'Pagamento non riuscito o scaduto', partially_refunded: 'Rimborsato parzialmente', refunded: 'Rimborsato' } as Record<string, string>,
};
</script>

<template>
    <Head :title="paid ? copy.success : copy.registered" />
    <section class="mx-auto max-w-2xl rounded-2xl border border-neutral-700 bg-neutral-900/50 p-6 sm:p-10">
        <p class="font-semibold" :class="paid ? 'text-emerald-300' : 'text-amber-300'">{{ paid ? copy.success : copy.registered }}</p>
        <p v-if="cancelled" class="mt-4 font-semibold text-amber-300">{{ copy.cancelled }}</p>
        <h1 class="mt-3 text-3xl font-bold">{{ paid ? copy.thanks : copy.hello }}, {{ order.customer_name }}.</h1>
        <p class="mt-4 break-all text-sm text-neutral-400">{{ copy.number }}: <strong class="text-white">{{ order.number }}</strong></p>
        <dl class="mt-6 space-y-4 border-y border-neutral-800 py-6">
            <div class="flex justify-between gap-4"><dt>{{ copy.total }}</dt><dd class="font-bold text-amber-400">{{ orderMoney(order.total_amount, order.currency) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>{{ copy.shipping }}</dt><dd>{{ copy.included }}</dd></div>
            <div class="flex justify-between gap-4"><dt>{{ copy.payment }}</dt><dd>{{ copy.labels[order.payment_status] ?? order.payment_status }}</dd></div>
        </dl>
        <p v-if="syncError" role="alert" class="mt-4 text-sm text-amber-300">{{ copy.sync }}</p>
        <p v-if="!cancelled && order.is_test" class="mt-6 text-sm leading-6 text-neutral-400">{{ paid ? copy.testPaid : copy.testPending }} {{ copy.testNotice }}</p>
        <p v-if="!cancelled && !order.is_test" class="mt-6 text-sm leading-6 text-neutral-400">{{ paid ? copy.livePaid : ['refunded', 'partially_refunded'].includes(order.payment_status) ? copy.refunded : copy.livePending }}</p>
        <div v-if="!cancelled && ['pending', 'failed'].includes(order.payment_status)" class="mt-6 flex flex-wrap gap-4">
            <a :href="paymentUrl" class="rounded-lg bg-amber-400 px-5 py-3 font-semibold text-black">{{ order.payment_status === 'failed' ? copy.retry : copy.pay }}</a>
            <button type="button" class="text-sm underline" @click="router.reload()">{{ copy.refresh }}</button>
        </div>
        <a :href="`/configurator?lang=${checkoutLocale}`" class="mt-7 flex h-12 w-full items-center justify-center rounded-lg bg-[#334fb4] text-white transition hover:bg-[#405dc7]" :aria-label="copy.back" :title="copy.back">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg>
            <span class="sr-only">{{ copy.back }}</span>
        </a>
    </section>
</template>
