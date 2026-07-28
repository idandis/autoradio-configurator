<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
const props = defineProps<{ requests: { data: Array<Record<string, any>>; links: Array<{url:string|null,label:string,active:boolean}> } }>();
const selected = ref<Record<string, any> | null>(null);
const remove = (id: number) => { if (confirm('Eliminare questo modulo?')) router.delete(`/missing-vehicle-requests/${id}`); };
</script>
<template>
    <Head title="Moduli inviati" />
    <section class="rounded-xl border border-sidebar-border/70 bg-card p-6">
        <h1 class="text-xl font-semibold">Moduli inviati</h1>
        <p class="mt-1 text-sm text-muted-foreground">Richieste ricevute dal configuratore.</p>
        <div class="mt-6 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">Data</th><th class="p-3">Cliente</th><th class="p-3">Email</th><th class="p-3">Telefono</th><th class="p-3">Veicolo</th><th class="p-3">Foto</th><th class="p-3"></th></tr></thead><tbody><tr v-for="item in requests.data" :key="item.id" class="border-b border-sidebar-border/50"><td class="p-3">{{ item.created_at }}</td><td class="p-3">{{ item.first_name }} {{ item.last_name }}</td><td class="p-3">{{ item.email }}</td><td class="p-3">{{ item.phone }}</td><td class="p-3">{{ item.brand }} {{ item.model }} ({{ item.year }})</td><td class="p-3"><a v-if="item.photo_url" :href="item.photo_url" target="_blank" class="text-primary underline">Apri foto</a></td><td class="p-3"><button type="button" class="rounded border px-3 py-1" @click="selected = item">Dettagli</button></td></tr></tbody></table></div>
        <div v-if="selected" class="mt-6 rounded-lg border border-sidebar-border/70 p-5"><div class="flex justify-between"><h2 class="font-semibold">Dettagli richiesta</h2><button type="button" @click="selected = null">×</button></div><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-muted-foreground">Provincia</dt><dd>{{ selected.province }}</dd></div><div><dt class="text-muted-foreground">Telefono</dt><dd>{{ selected.phone }}</dd></div><div class="sm:col-span-2"><dt class="text-muted-foreground">Commento</dt><dd class="whitespace-pre-wrap">{{ selected.comment || '—' }}</dd></div></dl><a v-if="selected.photo_url" :href="selected.photo_url" target="_blank" class="mt-4 inline-block rounded bg-primary px-4 py-2 text-primary-foreground">Apri foto</a><button type="button" class="ml-3 mt-4 rounded bg-destructive px-4 py-2 text-white" @click="remove(selected.id)">Elimina</button></div>
    </section>
</template>
