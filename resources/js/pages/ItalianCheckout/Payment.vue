<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { loadStripe } from '@stripe/stripe-js/pure';
import type { StripeEmbeddedCheckout } from '@stripe/stripe-js';
import ItalianCheckoutLayout from '@/layouts/ItalianCheckoutLayout.vue';
import { orderMoney } from '@/types/italian-orders';

defineOptions({ layout: ItalianCheckoutLayout });
const props = defineProps<{
    isTest: boolean;
    checkoutLocale: 'it' | 'es';
    token: string;
    publishableKey: string | null;
    confirmationUrl: string;
    order: {
        number: string; total_amount: number; subtotal_amount: number; import_amount: number; discount_amount: number; shipping_amount: number; currency: string;
        items: Array<{ title: string; variant_title: string | null; quantity: number; unit_amount: number; import_unit_amount: number; total_amount: number; import_total_amount: number }>;
    };
}>();
const copy = props.checkoutLocale === 'es' ? {
    title: 'Pago', testTitle: 'Pago de prueba', back: 'Volver al configurador', testIntro: 'Estás utilizando Stripe en modo de prueba. No se cargará ningún importe real.', unavailable: 'El pago todavía no está disponible. Tu pedido está guardado.', stripeUnavailable: 'Stripe no está disponible.', sessionUnavailable: 'La sesión no está disponible.', loadError: 'No se puede cargar el pago. Tu pedido está guardado: inténtalo de nuevo.', loading: 'Cargando el pago…', retry: 'Reintentar', reload: 'Recargar página', order: 'Tu pedido', quantity: 'Cantidad', imports: 'Costes de importación', products: 'Productos', discount: 'Descuento', shipping: 'Entrega', included: 'Incluida', total: 'Total', testCard: 'Para la prueba utiliza la tarjeta 4242 4242 4242 4242, una fecha futura y un CVC de 3 cifras. No utilices una tarjeta real.', verify: 'Comprobar el estado del pedido',
} : {
    title: 'Pagamento', testTitle: 'Pagamento di prova', back: 'Torna al configuratore', testIntro: 'Stai usando Stripe in modalità test. Nessun importo reale verrà addebitato.', unavailable: 'Il pagamento non è ancora disponibile. Il tuo ordine è salvato.', stripeUnavailable: 'Stripe non disponibile.', sessionUnavailable: 'Sessione non disponibile.', loadError: 'Impossibile caricare il pagamento. Il tuo ordine è salvato: riprova tra poco.', loading: 'Caricamento del pagamento…', retry: 'Riprova', reload: 'Ricarica pagina', order: 'Il tuo ordine', quantity: 'Quantità', imports: 'Costi di importazione', products: 'Prodotti', discount: 'Sconto', shipping: 'Spedizione', included: 'Inclusa', total: 'Totale', testCard: 'Per la prova usa la carta 4242 4242 4242 4242, una scadenza futura e un CVC di 3 cifre. Non usare una carta reale.', verify: 'Verifica lo stato dell’ordine',
};
const loading = ref(false);
const error = ref('');
let embedded: StripeEmbeddedCheckout | null = null;
let generation = 0;
const reloadPage = () => window.location.reload();
const mountPayment = async () => {
    if (loading.value) return;
    const current = ++generation;
    embedded?.destroy();
    embedded = null;
    if (!props.publishableKey) {
        error.value = copy.unavailable;
        return;
    }
    loading.value = true;
    error.value = '';
    let redirecting = false;
    try {
        const stripe = await loadStripe(props.publishableKey);
        if (!stripe) throw new Error(copy.stripeUnavailable);
        if (current !== generation) return;
        const checkout = await stripe.createEmbeddedCheckoutPage({
            fetchClientSecret: async () => {
                const { data } = await axios.post(`/checkout/italiano/${props.token}/stripe-session`);
                if (data.redirect) {
                    redirecting = true;
                    window.location.assign(data.redirect);
                    throw new Error('Redirect');
                }
                if (typeof data.clientSecret !== 'string') throw new Error(copy.sessionUnavailable);
                return data.clientSecret;
            },
        });
        if (current !== generation) { checkout.destroy(); return; }
        embedded = checkout;
        checkout.mount('#stripe-checkout');
    } catch {
        if (current === generation && !redirecting) error.value = copy.loadError;
    } finally {
        if (current === generation) loading.value = false;
    }
};
onMounted(mountPayment);
onBeforeUnmount(() => { generation++; embedded?.destroy(); });
</script>

<template>
    <Head :title="isTest ? copy.testTitle : copy.title" />
    <a :href="`/configurator?lang=${checkoutLocale}`" class="mb-7 flex h-12 w-full items-center justify-center rounded-lg bg-[#334fb4] text-white transition hover:bg-[#405dc7]" :aria-label="copy.back" :title="copy.back">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg>
        <span class="sr-only">{{ copy.back }}</span>
    </a>
    <h1 class="text-3xl font-bold">{{ isTest ? copy.testTitle : copy.title }}</h1>
    <p v-if="isTest" class="mt-3 text-neutral-400">{{ copy.testIntro }}</p>
    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="min-w-0 rounded-2xl border border-neutral-700 bg-white p-2 text-neutral-900 sm:p-4" aria-label="Pagamento Stripe">
            <p v-if="loading" role="status" class="p-6 text-center">{{ copy.loading }}</p>
            <div v-if="error" class="space-y-4 p-6" role="alert">
                <p>{{ error }}</p>
                <button v-if="publishableKey" type="button" class="rounded-lg bg-neutral-900 px-4 py-3 text-white" @click="mountPayment">{{ copy.retry }}</button>
                <button v-else type="button" class="rounded-lg bg-neutral-900 px-4 py-3 text-white" @click="reloadPage">{{ copy.reload }}</button>
            </div>
            <div id="stripe-checkout" />
        </section>
        <aside class="space-y-5 rounded-2xl border border-neutral-700 p-5">
            <h2 class="font-semibold">{{ copy.order }}</h2>
            <p class="break-all text-xs text-neutral-400">{{ order.number }}</p>
            <ul class="space-y-4 text-sm"><li v-for="(item, index) in order.items" :key="index"><p>{{ item.title }}</p><p v-if="item.variant_title" class="text-neutral-400">{{ item.variant_title }}</p><p class="mt-1 text-neutral-400">{{ copy.quantity }}: {{ item.quantity }} · {{ orderMoney(item.total_amount) }}</p><p v-if="item.import_total_amount" class="mt-1 text-amber-300">{{ copy.imports }}: {{ item.quantity }} × {{ orderMoney(item.import_unit_amount) }} = {{ orderMoney(item.import_total_amount) }}</p></li></ul>
            <dl class="space-y-3 border-t border-neutral-700 pt-4 text-sm">
                <div class="flex justify-between gap-3"><dt>{{ copy.products }}</dt><dd>{{ orderMoney(order.subtotal_amount) }}</dd></div>
                <div v-if="order.import_amount" class="flex justify-between gap-3"><dt>{{ copy.imports }}</dt><dd>{{ orderMoney(order.import_amount) }}</dd></div>
                <div v-if="order.discount_amount" class="flex justify-between gap-3"><dt>{{ copy.discount }}</dt><dd>−{{ orderMoney(order.discount_amount) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ copy.shipping }}</dt><dd class="text-emerald-300">{{ copy.included }}</dd></div>
                <div class="flex justify-between gap-3 text-xl font-bold"><dt>{{ copy.total }}</dt><dd class="text-amber-400">{{ orderMoney(order.total_amount) }}</dd></div>
            </dl>
            <p v-if="isTest" class="rounded-lg bg-amber-400/10 p-3 text-sm leading-6 text-amber-200">{{ copy.testCard }}</p>
            <a :href="confirmationUrl" class="inline-block text-sm underline">{{ copy.verify }}</a>
        </aside>
    </div>
</template>
