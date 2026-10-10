<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ItalianStoreHeader from '@/components/ItalianStoreHeader.vue';
import ItalianStoreFooter from '@/components/ItalianStoreFooter.vue';
const page = usePage();
const locale = page.props.checkoutLocale === 'es' ? 'es' : 'it';
const isItalianStore = computed(() => page.props.storeBrand ? page.props.storeBrand === 'italiano' : locale === 'it');
</script>
<template>
    <div class="min-h-screen bg-[#121212] text-neutral-100">
        <ItalianStoreHeader v-if="isItalianStore" />
        <header v-else class="border-b border-neutral-800">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-5">
                <a href="https://www.autoradiocanario.com/" class="text-lg font-bold tracking-wide">AUTORADIO<span class="text-amber-400">CANARIO</span></a>
                <span class="rounded-full border border-amber-400/40 px-3 py-1 text-xs text-amber-300">{{ page.props.isTest ? (locale === 'es' ? 'Modo de prueba · ningún cargo' : 'Modalità prova · nessun addebito') : (locale === 'es' ? 'Pago seguro · Stripe' : 'Pagamento sicuro · Stripe') }}</span>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-5 py-8 sm:py-12"><slot /></main>
        <ItalianStoreFooter v-if="isItalianStore" />
    </div>
</template>
