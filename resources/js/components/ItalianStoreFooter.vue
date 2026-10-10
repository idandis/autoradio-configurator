<script setup lang="ts">
const links = [{ href: '/privacy', label: 'Informativa sulla privacy' }, { href: '/resi-e-rimborsi', label: 'Resi e rimborsi' }, { href: '/termini-del-servizio', label: 'Termini del servizio' }, { href: '/spedizioni', label: 'Spedizioni' }, { href: '/contatti', label: 'Informazioni di contatto' }, { href: '/note-legali', label: 'Note legali' }];
</script>

<template>
    <footer class="border-t border-neutral-800 bg-[#121212] text-white">
        <div class="mx-auto grid max-w-7xl gap-8 px-5 py-12 sm:grid-cols-2 lg:grid-cols-3 lg:px-8">
            <div><a href="/"><img src="/images/logo-it.png" alt="Autoradio Italiano · Home" class="w-52" /></a><p class="mt-5 max-w-xs text-sm leading-6 text-neutral-400">La soluzione per la tua auto, con assistenza prima, durante e dopo la richiesta.</p></div>
            <div class="space-y-3"><h2 class="font-semibold">Hai bisogno di aiuto?</h2><a href="https://wa.me/393514346911" class="block text-emerald-400">WhatsApp +39 351 434 6911</a><a href="mailto:info@autoradioitaliano.it" class="block break-all text-sm text-neutral-300">info@autoradioitaliano.it</a><a href="/chi-siamo" class="block text-sm text-neutral-300 hover:text-white">Chi siamo</a><a href="/marche" class="block text-sm text-neutral-300 hover:text-white">Trova la tua autoradio</a></div>
            <nav aria-label="Informazioni e condizioni" class="space-y-3"><h2 class="font-semibold">Informazioni</h2><a v-for="link in links" :key="link.href" :href="link.href" class="block text-sm text-neutral-400 hover:text-white">{{ link.label }}</a></nav>
        </div>
        <div class="border-t border-neutral-800 px-5 py-5 text-center text-xs leading-6 text-neutral-500">© {{ new Date().getFullYear() }} Autoradio Italiano · ESCUELASOFT, marchio registrato OEPM N0439887<br>Avenida Mencey 49, 35120 Mogán, Las Palmas, Spagna</div>
    </footer>
</template>
