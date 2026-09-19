<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import ItalianCheckoutLayout from '@/layouts/ItalianCheckoutLayout.vue';
import { orderMoney } from '@/types/italian-orders';

defineOptions({ layout: ItalianCheckoutLayout });
type Quote = {
    items: Array<{ product_handle: string; title: string; variant_title: string | null; sku: string | null; quantity: number; unit_amount: number; import_unit_amount: number; total_amount: number; import_total_amount: number }>;
    subtotal_amount: number;
    import_amount: number;
    shipping_amount: number;
    discount_amount: number;
    discount_label: string | null;
    total_amount: number;
};
const props = defineProps<{ isTest: boolean; checkoutLocale: 'it' | 'es'; token: string; quote: Quote | null; quoteHash: string | null; changed: boolean; unavailable: string | null }>();
const copy = props.checkoutLocale === 'es' ? {
    title: 'Completa los datos del pedido', subtitle: 'El carrito reproduce los importes del presupuesto.', back: 'Volver al configurador',
    test: 'Estás probando el pago. No se realizará ningún cargo ni se iniciará un envío.', unavailableTitle: 'Actualiza el carrito', unavailable: 'El carrito no está disponible.', backProducts: 'Volver a los productos',
    customer: 'Datos del cliente y entrega', country: 'País', countryValue: 'España', summary: 'Resumen del pedido', changed: 'El catálogo ha cambiado. Comprueba los importes actualizados antes de confirmar.',
    quantity: 'Cantidad', products: 'Productos', imports: 'Costes de importación', shipping: 'Entrega', included: 'Incluida', total: 'Total', reviewed: 'He comprobado los productos, los importes y la dirección de entrega.',
    creating: 'Creando pedido…', pay: 'Ir al pago', testPay: 'Ir al pago de prueba', refresh: 'Actualizar resumen', testCard: 'En el siguiente paso utiliza exclusivamente una tarjeta de prueba de Stripe.',
    fields: { first_name: 'Nombre', last_name: 'Apellidos', email: 'Email', phone: 'Teléfono', line1: 'Dirección y número', line2: 'Piso, puerta u otras indicaciones (opcional)', postal_code: 'Código postal', city: 'Ciudad', province: 'Provincia' },
} : {
    title: 'Completa i dati dell’ordine', subtitle: 'Il carrello riproduce gli importi del preventivo.', back: 'Torna al configuratore',
    test: 'Stai provando il checkout. Non verrà effettuato alcun addebito né avviata una spedizione.', unavailableTitle: 'Aggiorna il carrello', unavailable: 'Il carrello non è disponibile.', backProducts: 'Torna ai prodotti',
    customer: 'Dati cliente e spedizione', country: 'Paese', countryValue: 'Italia', summary: 'Riepilogo ordine', changed: 'Il catalogo è cambiato. Controlla gli importi aggiornati prima di confermare.',
    quantity: 'Quantità', products: 'Prodotti', imports: 'Costi di importazione', shipping: 'Spedizione', included: 'Inclusa', total: 'Totale', reviewed: 'Ho verificato i prodotti, gli importi e l’indirizzo di spedizione.',
    creating: 'Creazione ordine…', pay: 'Vai al pagamento', testPay: 'Vai al pagamento di prova', refresh: 'Aggiorna riepilogo', testCard: 'Nel prossimo passaggio usa esclusivamente una carta di prova Stripe.',
    fields: { first_name: 'Nome', last_name: 'Cognome', email: 'Email', phone: 'Telefono', line1: 'Indirizzo e numero civico', line2: 'Interno, scala o altre indicazioni (facoltativo)', postal_code: 'CAP', city: 'Città', province: 'Provincia (sigla)' },
};
const form = useForm({
    first_name: '', last_name: '', email: '', phone: '',
    line1: '', line2: '', postal_code: '', city: '', province: '', country: props.checkoutLocale === 'es' ? 'ES' : 'IT',
    reviewed: false, quote_hash: props.quoteHash ?? '',
});
watch(() => props.quoteHash, (hash) => {
    form.quote_hash = hash ?? '';
    form.reviewed = false;
});
const submit = () => form.post(`/checkout/italiano/${props.token}`, { preserveScroll: true });
const fields: Array<{ key: 'first_name' | 'last_name' | 'email' | 'phone' | 'line1' | 'line2' | 'postal_code' | 'city' | 'province'; label: string; autocomplete: string; max: number; type?: string; optional?: boolean; wide?: boolean }> = [
    { key: 'first_name', label: copy.fields.first_name, autocomplete: 'given-name', max: 100 },
    { key: 'last_name', label: copy.fields.last_name, autocomplete: 'family-name', max: 100 },
    { key: 'email', label: copy.fields.email, autocomplete: 'email', max: 255, type: 'email' },
    { key: 'phone', label: copy.fields.phone, autocomplete: 'tel', max: 40, type: 'tel' },
    { key: 'line1', label: copy.fields.line1, autocomplete: 'address-line1', max: 255, wide: true },
    { key: 'line2', label: copy.fields.line2, autocomplete: 'address-line2', max: 255, optional: true, wide: true },
    { key: 'postal_code', label: copy.fields.postal_code, autocomplete: 'postal-code', max: 5 },
    { key: 'city', label: copy.fields.city, autocomplete: 'address-level2', max: 100 },
    { key: 'province', label: copy.fields.province, autocomplete: 'address-level1', max: props.checkoutLocale === 'es' ? 100 : 2 },
];
</script>

<template>
    <Head :title="copy.title" />
    <a :href="`/configurator?lang=${checkoutLocale}`" class="flex h-12 w-full items-center justify-center rounded-lg bg-[#334fb4] text-white transition hover:bg-[#405dc7]" :aria-label="copy.back" :title="copy.back">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg>
        <span class="sr-only">{{ copy.back }}</span>
    </a>
    <h1 class="mt-5 text-3xl font-bold">{{ copy.title }}</h1>
    <p class="mt-2 text-neutral-400">{{ copy.subtitle }}</p>
    <p v-if="isTest" class="mt-4 rounded-lg border border-amber-400/30 bg-amber-400/5 p-4 text-sm text-amber-200">{{ copy.test }}</p>

    <section v-if="unavailable || !quote" class="mt-8 rounded-xl border border-red-400/40 p-6" role="alert">
        <h2 class="text-xl font-semibold">{{ copy.unavailableTitle }}</h2>
        <p class="mt-3">{{ unavailable ?? copy.unavailable }}</p>
        <a :href="`/configurator?lang=${checkoutLocale}`" class="mt-5 flex h-12 w-full items-center justify-center rounded-lg bg-[#334fb4] text-white transition hover:bg-[#405dc7]" :aria-label="copy.backProducts" :title="copy.backProducts">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg>
            <span class="sr-only">{{ copy.backProducts }}</span>
        </a>
    </section>
    <form v-else class="mt-8 grid items-start gap-8 lg:grid-cols-[1.2fr_1fr]" @submit.prevent="submit">
        <section class="rounded-2xl border border-neutral-800 bg-neutral-900/40 p-5 sm:p-7">
            <h2 class="text-xl font-semibold">{{ copy.customer }}</h2>
            <div class="mt-6 grid gap-5 sm:grid-cols-2">
                <label v-for="field in fields" :key="field.key" class="grid gap-2 text-sm" :class="field.wide ? 'sm:col-span-2' : ''">
                    {{ field.label }}
                    <input :id="field.key" v-model="form[field.key]" :name="field.key" :type="field.type ?? 'text'" :autocomplete="field.autocomplete" :maxlength="field.max" :required="!field.optional"
                        :inputmode="field.key === 'postal_code' ? 'numeric' : undefined" :pattern="field.key === 'postal_code' ? '[0-9]{5}' : undefined"
                        :aria-invalid="!!form.errors[field.key]" :aria-describedby="form.errors[field.key] ? `${field.key}-error` : undefined"
                        class="w-full min-w-0 rounded-lg border border-neutral-700 bg-[#121212] px-3 py-3 outline-none focus:border-amber-400" :class="field.key === 'province' && checkoutLocale === 'it' ? 'uppercase' : ''" />
                    <span v-if="form.errors[field.key]" :id="`${field.key}-error`" class="text-red-300" role="alert">{{ form.errors[field.key] }}</span>
                </label>
                <div class="text-sm"><p>{{ copy.country }}</p><p class="mt-2 rounded-lg border border-neutral-800 px-3 py-3 text-neutral-400">{{ copy.countryValue }}</p></div>
            </div>
        </section>

        <section class="space-y-5 rounded-2xl border border-neutral-700 bg-neutral-900/40 p-5 sm:p-7 lg:sticky lg:top-6">
            <h2 class="text-xl font-semibold">{{ copy.summary }}</h2>
            <p v-if="changed || form.errors.quote_hash" role="alert" class="rounded-lg bg-amber-400/10 p-3 text-sm text-amber-200">{{ copy.changed }}</p>
            <ul class="divide-y divide-neutral-800">
                <li v-for="(item, index) in quote.items" :key="index" class="py-4 first:pt-0">
                    <p class="font-medium">{{ item.title }}</p>
                    <p v-if="item.variant_title" class="mt-1 text-sm text-neutral-400">{{ item.variant_title }}</p>
                    <div class="mt-2 flex justify-between gap-3 text-sm"><span class="text-neutral-400">{{ item.quantity }} × {{ orderMoney(item.unit_amount) }}</span><span>{{ orderMoney(item.total_amount) }}</span></div>
                    <div v-if="item.import_unit_amount" class="mt-1 flex justify-between gap-3 text-sm text-amber-300"><span>{{ copy.imports }}: {{ item.quantity }} × {{ orderMoney(item.import_unit_amount) }}</span><span>{{ orderMoney(item.import_total_amount) }}</span></div>
                </li>
            </ul>
            <dl class="space-y-3 border-t border-neutral-700 pt-5 text-sm">
                <div class="flex justify-between gap-4"><dt>{{ copy.products }}</dt><dd>{{ orderMoney(quote.subtotal_amount) }}</dd></div>
                <div v-if="quote.import_amount" class="flex justify-between gap-4"><dt>{{ copy.imports }}</dt><dd>{{ orderMoney(quote.import_amount) }}</dd></div>
                <div v-if="quote.discount_amount" class="flex justify-between gap-4 text-emerald-300"><dt>{{ quote.discount_label }}</dt><dd>−{{ orderMoney(quote.discount_amount) }}</dd></div>
                <div class="flex justify-between gap-4"><dt>{{ copy.shipping }}</dt><dd class="font-medium text-emerald-300">{{ copy.included }}</dd></div>
                <div class="flex justify-between gap-4 border-t border-neutral-700 pt-4 text-xl font-bold"><dt>{{ copy.total }}</dt><dd class="text-amber-400">{{ orderMoney(quote.total_amount) }}</dd></div>
            </dl>
            <label class="flex items-start gap-3 text-sm leading-6"><input v-model="form.reviewed" type="checkbox" required class="mt-1 h-4 w-4 shrink-0 accent-amber-400" />{{ copy.reviewed }}</label>
            <p v-if="form.errors.reviewed" role="alert" class="text-sm text-red-300">{{ form.errors.reviewed }}</p>
            <p v-if="form.errors.country" role="alert" class="text-sm text-red-300">{{ form.errors.country }}</p>
            <p v-if="(form.errors as Record<string, string>).items" role="alert" class="text-sm text-red-300">{{ (form.errors as Record<string, string>).items }}</p>
            <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-amber-400 px-5 py-4 font-bold text-black hover:bg-amber-300 disabled:opacity-50">{{ form.processing ? copy.creating : (isTest ? copy.testPay : copy.pay) }}</button>
            <button v-if="form.errors.quote_hash" type="button" class="text-sm underline" @click="router.reload()">{{ copy.refresh }}</button>
            <p v-if="isTest" class="text-center text-xs text-neutral-500">{{ copy.testCard }}</p>
        </section>
    </form>
</template>
