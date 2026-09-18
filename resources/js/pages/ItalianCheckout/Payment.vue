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
    token: string;
    publishableKey: string | null;
    confirmationUrl: string;
    order: {
        number: string; total_amount: number; subtotal_amount: number; discount_amount: number; shipping_amount: number; currency: string;
        items: Array<{ title: string; variant_title: string | null; quantity: number; total_amount: number }>;
    };
}>();
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
        error.value = 'Il pagamento non è ancora disponibile. Il tuo ordine è salvato.';
        return;
    }
    loading.value = true;
    error.value = '';
    let redirecting = false;
    try {
        const stripe = await loadStripe(props.publishableKey);
        if (!stripe) throw new Error('Stripe non disponibile.');
        if (current !== generation) return;
        const checkout = await stripe.createEmbeddedCheckoutPage({
            fetchClientSecret: async () => {
                const { data } = await axios.post(`/checkout/italiano/${props.token}/stripe-session`);
                if (data.redirect) {
                    redirecting = true;
                    window.location.assign(data.redirect);
                    throw new Error('Redirect');
                }
                if (typeof data.clientSecret !== 'string') throw new Error('Sessione non disponibile.');
                return data.clientSecret;
            },
        });
        if (current !== generation) { checkout.destroy(); return; }
        embedded = checkout;
        checkout.mount('#stripe-checkout');
    } catch {
        if (current === generation && !redirecting) error.value = 'Impossibile caricare il pagamento. Il tuo ordine è salvato: riprova tra poco.';
    } finally {
        if (current === generation) loading.value = false;
    }
};
onMounted(mountPayment);
onBeforeUnmount(() => { generation++; embedded?.destroy(); });
</script>

<template>
    <Head :title="isTest ? 'Pagamento di prova' : 'Pagamento'" />
    <h1 class="text-3xl font-bold">{{ isTest ? 'Pagamento di prova' : 'Pagamento' }}</h1>
    <p v-if="isTest" class="mt-3 text-neutral-400">Stai usando Stripe in modalità test. Nessun importo reale verrà addebitato.</p>
    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="min-w-0 rounded-2xl border border-neutral-700 bg-white p-2 text-neutral-900 sm:p-4" aria-label="Pagamento Stripe">
            <p v-if="loading" role="status" class="p-6 text-center">Caricamento del pagamento…</p>
            <div v-if="error" class="space-y-4 p-6" role="alert">
                <p>{{ error }}</p>
                <button v-if="publishableKey" type="button" class="rounded-lg bg-neutral-900 px-4 py-3 text-white" @click="mountPayment">Riprova</button>
                <button v-else type="button" class="rounded-lg bg-neutral-900 px-4 py-3 text-white" @click="reloadPage">Ricarica pagina</button>
            </div>
            <div id="stripe-checkout" />
        </section>
        <aside class="space-y-5 rounded-2xl border border-neutral-700 p-5">
            <h2 class="font-semibold">Il tuo ordine</h2>
            <p class="break-all text-xs text-neutral-400">{{ order.number }}</p>
            <ul class="space-y-4 text-sm"><li v-for="(item, index) in order.items" :key="index"><p>{{ item.title }}</p><p v-if="item.variant_title" class="text-neutral-400">{{ item.variant_title }}</p><p class="mt-1 text-neutral-400">Quantità: {{ item.quantity }} · {{ orderMoney(item.total_amount) }}</p></li></ul>
            <dl class="space-y-3 border-t border-neutral-700 pt-4 text-sm">
                <div v-if="order.discount_amount" class="flex justify-between gap-3"><dt>Sconto</dt><dd>−{{ orderMoney(order.discount_amount) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Spedizione</dt><dd class="text-emerald-300">Gratuita</dd></div>
                <div class="flex justify-between gap-3 text-xl font-bold"><dt>Totale</dt><dd class="text-amber-400">{{ orderMoney(order.total_amount) }}</dd></div>
            </dl>
            <p v-if="isTest" class="rounded-lg bg-amber-400/10 p-3 text-sm leading-6 text-amber-200">Per la prova usa la carta <strong>4242 4242 4242 4242</strong>, una scadenza futura e un CVC di 3 cifre. Non usare una carta reale.</p>
            <a :href="confirmationUrl" class="inline-block text-sm underline">Verifica lo stato dell’ordine</a>
        </aside>
    </div>
</template>
