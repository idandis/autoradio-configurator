<script setup lang="ts">
import { ref } from 'vue';
import { Menu, X, ShoppingCart } from '@lucide/vue';
defineProps<{ cartCount?: number }>();
const emit = defineEmits<{ cart: [] }>();
const open = ref(false);
const links = [{ href: '/', label: 'Home' }, { href: '/marche', label: 'Autoradio per la tua auto' }, { href: '/chi-siamo', label: 'Chi siamo' }, { href: '/contatti', label: 'Contattaci' }];
</script>

<template>
    <header class="border-b border-neutral-800 bg-[#121212] text-white">
        <div class="bg-emerald-600 px-4 py-2 text-center text-xs font-semibold tracking-wide">Autoradio, CarPlay e Android Auto · Assistenza dedicata</div>
        <div class="mx-auto grid max-w-7xl grid-cols-[1fr_auto_1fr] items-center gap-3 px-4 py-5 sm:px-6 lg:px-8">
            <button type="button" class="justify-self-start rounded-lg p-2 hover:bg-white/5" :aria-expanded="open" aria-controls="italian-navigation" :aria-label="open ? 'Chiudi menu' : 'Apri menu'" @click="open = !open"><X v-if="open" :size="28" /><Menu v-else :size="28" /></button>
            <a href="/" aria-label="Autoradio Italiano · Home"><img src="/images/logo-it.png" alt="Autoradio Italiano" class="h-auto w-32 sm:w-56" /></a>
            <div class="flex items-center justify-self-end gap-2 sm:gap-5">
                <button v-if="cartCount !== undefined" type="button" class="relative rounded-lg p-1 sm:p-2" aria-label="Apri carrello" @click="emit('cart')"><ShoppingCart :size="20" /><span v-if="cartCount" class="absolute -right-1 -top-1 rounded-full bg-emerald-600 px-1.5 text-xs">{{ cartCount }}</span></button>
                <a v-else href="/configurator?lang=it&cart=1" aria-label="Apri carrello" class="hidden sm:block"><ShoppingCart :size="24" /></a>
                <a href="https://wa.me/393514346911" aria-label="Contatta Autoradio Italiano su WhatsApp" class="text-emerald-400 hover:text-emerald-300"><svg class="h-9 w-9 sm:h-11 sm:w-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M20.5 11.6a8.5 8.5 0 0 1-12.6 7.5L3 20.5l1.4-4.7a8.5 8.5 0 1 1 16.1-4.2Z" /><path d="M8.2 7.3c-.4 0-.9.7-.9 1.5 0 2.9 4.2 6.5 6.8 6.5.9 0 1.7-.6 1.8-1.3l-2-1.1-.9.8c-1.3-.4-3-2-3.4-3.2l.7-.8-.9-2.4Z" /></svg></a>
            </div>
        </div>
        <nav v-if="open" id="italian-navigation" aria-label="Navigazione principale" class="mx-auto flex max-w-7xl flex-col gap-1 border-t border-neutral-800 px-4 py-3 sm:flex-row sm:gap-6 sm:px-6 lg:px-8"><a v-for="link in links" :key="link.href" :href="link.href" class="rounded-lg px-3 py-3 hover:bg-white/5 hover:text-emerald-400">{{ link.label }}</a></nav>
    </header>
</template>
