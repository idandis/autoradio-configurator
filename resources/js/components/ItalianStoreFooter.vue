<script setup lang="ts">
const links = [{ href: '/privacy', label: 'Informativa sulla privacy' }, { href: '/resi-e-rimborsi', label: 'Resi e rimborsi' }, { href: '/termini-del-servizio', label: 'Termini del servizio' }, { href: '/spedizioni', label: 'Spedizioni' }, { href: '/contatti', label: 'Informazioni di contatto' }, { href: '/note-legali', label: 'Note legali' }];
</script>

<template>
    <footer class="border-t border-neutral-800 bg-[#121212] text-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center py-14 text-center sm:py-16"><h2 class="text-base font-medium">Contatti</h2><a href="https://wa.me/393514346911" class="mt-4 block text-sm text-neutral-300 hover:text-white">WhatsApp +39 351 434 6911</a><a href="mailto:info@autoradioitaliano.it" class="mt-3 block break-all text-sm text-neutral-300 hover:text-white">info@autoradioitaliano.it</a></div>
            <nav aria-label="Informazioni e condizioni" class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-neutral-800 py-10 text-[11px] text-neutral-400"><span>© {{ new Date().getFullYear() }}, Autoradio Italiano</span><template v-for="link in links" :key="link.href"><span aria-hidden="true">·</span><a :href="link.href" class="hover:text-white">{{ link.label }}</a></template></nav>
        </div>
        <div class="bg-[#334fb4] px-4 py-12 text-center text-[11px] leading-6 text-black/80 sm:py-16">By Escuelasoft N0439887<br>Avenida Mencey 49, 35120 Mogán, Las Palmas, Spagna</div>
    </footer>
</template>
