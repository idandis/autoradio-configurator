<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import ItalianCheckoutLayout from '@/layouts/ItalianCheckoutLayout.vue';
import { orderMoney } from '@/types/italian-orders';

defineOptions({ layout: ItalianCheckoutLayout });
type Quote = {
    items: Array<{ product_handle: string; title: string; variant_title: string | null; sku: string | null; quantity: number; unit_amount: number; total_amount: number }>;
    subtotal_amount: number;
    shipping_amount: number;
    discount_amount: number;
    discount_label: string | null;
    total_amount: number;
};
const props = defineProps<{ isTest: boolean; token: string; quote: Quote | null; quoteHash: string | null; changed: boolean; unavailable: string | null }>();
const form = useForm({
    first_name: '', last_name: '', email: '', phone: '',
    line1: '', line2: '', postal_code: '', city: '', province: '', country: 'IT',
    reviewed: false, quote_hash: props.quoteHash ?? '',
});
watch(() => props.quoteHash, (hash) => {
    form.quote_hash = hash ?? '';
    form.reviewed = false;
});
const submit = () => form.post(`/checkout/italiano/${props.token}`, { preserveScroll: true });
const fields: Array<{ key: 'first_name' | 'last_name' | 'email' | 'phone' | 'line1' | 'line2' | 'postal_code' | 'city' | 'province'; label: string; autocomplete: string; max: number; type?: string; optional?: boolean; wide?: boolean }> = [
    { key: 'first_name', label: 'Nome', autocomplete: 'given-name', max: 100 },
    { key: 'last_name', label: 'Cognome', autocomplete: 'family-name', max: 100 },
    { key: 'email', label: 'Email', autocomplete: 'email', max: 255, type: 'email' },
    { key: 'phone', label: 'Telefono', autocomplete: 'tel', max: 40, type: 'tel' },
    { key: 'line1', label: 'Indirizzo e numero civico', autocomplete: 'address-line1', max: 255, wide: true },
    { key: 'line2', label: 'Interno, scala o altre indicazioni (facoltativo)', autocomplete: 'address-line2', max: 255, optional: true, wide: true },
    { key: 'postal_code', label: 'CAP', autocomplete: 'postal-code', max: 5 },
    { key: 'city', label: 'Città', autocomplete: 'address-level2', max: 100 },
    { key: 'province', label: 'Provincia (sigla)', autocomplete: 'address-level1', max: 2 },
];
</script>

<template>
    <Head title="Checkout italiano" />
    <a href="/configurator?lang=it" class="text-sm text-neutral-400 underline hover:text-white">← Torna al configuratore</a>
    <h1 class="mt-5 text-3xl font-bold">Completa i dati dell’ordine</h1>
    <p class="mt-2 text-neutral-400">Solo prodotti, con spedizione gratuita in Italia.</p>
    <p v-if="isTest" class="mt-4 rounded-lg border border-amber-400/30 bg-amber-400/5 p-4 text-sm text-amber-200">Stai provando il checkout. L’ordine verrà registrato come prova, in attesa di pagamento. Non verrà effettuato alcun addebito né avviata una spedizione.</p>

    <section v-if="unavailable || !quote" class="mt-8 rounded-xl border border-red-400/40 p-6" role="alert">
        <h2 class="text-xl font-semibold">Aggiorna il carrello</h2>
        <p class="mt-3">{{ unavailable ?? 'Il carrello non è disponibile.' }}</p>
        <a href="/configurator?lang=it" class="mt-5 inline-block rounded-lg bg-amber-400 px-5 py-3 font-semibold text-black">Torna ai prodotti</a>
    </section>
    <form v-else class="mt-8 grid items-start gap-8 lg:grid-cols-[1.2fr_1fr]" @submit.prevent="submit">
        <section class="rounded-2xl border border-neutral-800 bg-neutral-900/40 p-5 sm:p-7">
            <h2 class="text-xl font-semibold">Dati cliente e spedizione</h2>
            <div class="mt-6 grid gap-5 sm:grid-cols-2">
                <label v-for="field in fields" :key="field.key" class="grid gap-2 text-sm" :class="field.wide ? 'sm:col-span-2' : ''">
                    {{ field.label }}
                    <input :id="field.key" v-model="form[field.key]" :name="field.key" :type="field.type ?? 'text'" :autocomplete="field.autocomplete" :maxlength="field.max" :required="!field.optional"
                        :inputmode="field.key === 'postal_code' ? 'numeric' : undefined" :pattern="field.key === 'postal_code' ? '[0-9]{5}' : undefined"
                        :aria-invalid="!!form.errors[field.key]" :aria-describedby="form.errors[field.key] ? `${field.key}-error` : undefined"
                        class="w-full min-w-0 rounded-lg border border-neutral-700 bg-[#121212] px-3 py-3 outline-none focus:border-amber-400" :class="field.key === 'province' ? 'uppercase' : ''" />
                    <span v-if="form.errors[field.key]" :id="`${field.key}-error`" class="text-red-300" role="alert">{{ form.errors[field.key] }}</span>
                </label>
                <div class="text-sm"><p>Paese</p><p class="mt-2 rounded-lg border border-neutral-800 px-3 py-3 text-neutral-400">Italia · spedizione gratuita</p></div>
            </div>
        </section>

        <section class="space-y-5 rounded-2xl border border-neutral-700 bg-neutral-900/40 p-5 sm:p-7 lg:sticky lg:top-6">
            <h2 class="text-xl font-semibold">Riepilogo ordine</h2>
            <p v-if="changed || form.errors.quote_hash" role="alert" class="rounded-lg bg-amber-400/10 p-3 text-sm text-amber-200">Il catalogo è cambiato. Controlla gli importi aggiornati prima di confermare.</p>
            <ul class="divide-y divide-neutral-800">
                <li v-for="(item, index) in quote.items" :key="index" class="py-4 first:pt-0">
                    <p class="font-medium">{{ item.title }}</p>
                    <p v-if="item.variant_title" class="mt-1 text-sm text-neutral-400">{{ item.variant_title }}</p>
                    <div class="mt-2 flex justify-between gap-3 text-sm"><span class="text-neutral-400">{{ item.quantity }} × {{ orderMoney(item.unit_amount) }}</span><span>{{ orderMoney(item.total_amount) }}</span></div>
                </li>
            </ul>
            <dl class="space-y-3 border-t border-neutral-700 pt-5 text-sm">
                <div class="flex justify-between gap-4"><dt>Prodotti</dt><dd>{{ orderMoney(quote.subtotal_amount) }}</dd></div>
                <div v-if="quote.discount_amount" class="flex justify-between gap-4 text-emerald-300"><dt>{{ quote.discount_label }}</dt><dd>−{{ orderMoney(quote.discount_amount) }}</dd></div>
                <div class="flex justify-between gap-4"><dt>Spedizione</dt><dd class="font-medium text-emerald-300">Gratuita</dd></div>
                <div class="flex justify-between gap-4 border-t border-neutral-700 pt-4 text-xl font-bold"><dt>Totale</dt><dd class="text-amber-400">{{ orderMoney(quote.total_amount) }}</dd></div>
            </dl>
            <label class="flex items-start gap-3 text-sm leading-6"><input v-model="form.reviewed" type="checkbox" required class="mt-1 h-4 w-4 shrink-0 accent-amber-400" />Ho verificato i prodotti, gli importi e l’indirizzo di spedizione.</label>
            <p v-if="form.errors.reviewed" role="alert" class="text-sm text-red-300">{{ form.errors.reviewed }}</p>
            <p v-if="form.errors.country" role="alert" class="text-sm text-red-300">{{ form.errors.country }}</p>
            <p v-if="(form.errors as Record<string, string>).items" role="alert" class="text-sm text-red-300">{{ (form.errors as Record<string, string>).items }}</p>
            <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-amber-400 px-5 py-4 font-bold text-black hover:bg-amber-300 disabled:opacity-50">{{ form.processing ? 'Creazione ordine…' : (isTest ? 'Vai al pagamento di prova' : 'Vai al pagamento') }}</button>
            <button v-if="form.errors.quote_hash" type="button" class="text-sm underline" @click="router.reload()">Aggiorna riepilogo</button>
            <p v-if="isTest" class="text-center text-xs text-neutral-500">Nel prossimo passaggio usa esclusivamente una carta di prova Stripe.</p>
        </section>
    </form>
</template>
