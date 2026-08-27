<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{
    stats: {
        screens: number;
        cameras: number;
        speakers: number;
        vehicles: number;
    };
    postImportTasks: {
        translationCount: number;
        imageCount: number;
        prompt: string;
        fingerprint: string;
        dismissed: boolean;
    };
    flashStatus?: string | null;
}>();

const form = useForm({
    catalog: null as File | null,
    mode: 'add' as 'replace' | 'add',
});

const migrationForm = useForm({});
const taskCopyStatus = ref<'idle' | 'copied' | 'error'>('idle');
const tasksDismissedLocally = ref(false);
const verificationRunning = ref(false);
const verificationCompleted = ref(false);

const syncDismissedState = () => {
    tasksDismissedLocally.value = typeof window !== 'undefined'
        && window.localStorage.getItem(`post-import-tasks:dismissed:${props.postImportTasks.fingerprint}`) === '1';
};

watch(() => props.postImportTasks.fingerprint, syncDismissedState, { immediate: true });

const copyPostImportPrompt = async () => {
    try {
        await navigator.clipboard.writeText(props.postImportTasks.prompt);
        taskCopyStatus.value = 'copied';
    } catch {
        taskCopyStatus.value = 'error';
    }

    window.setTimeout(() => {
        taskCopyStatus.value = 'idle';
    }, 2500);
};

const updateFile = (event: Event) => {
    const target = event.target as HTMLInputElement | null;

    form.catalog = target?.files?.[0] ?? null;
};

const submit = () => {
    form.post('/dashboard/import-csv', {
        forceFormData: true,
    });
};

const migrateDatabase = () => {
    if (!window.confirm('Applicare ora tutti gli aggiornamenti disponibili al database?')) return;

    migrationForm.post('/dashboard/database/migrate', {
        preserveScroll: true,
    });
};

const dismissPostImportTasks = () => {
    if (!window.confirm('Cancellare questa nota dalla Dashboard?')) return;

    window.localStorage.setItem(`post-import-tasks:dismissed:${props.postImportTasks.fingerprint}`, '1');
    tasksDismissedLocally.value = true;
};

const verifyCatalog = () => {
    verificationRunning.value = true;

    router.reload({
        only: ['postImportTasks'],
        onSuccess: () => {
            window.localStorage.removeItem(`post-import-tasks:dismissed:${props.postImportTasks.fingerprint}`);
            tasksDismissedLocally.value = false;
            verificationCompleted.value = true;
        },
        onFinish: () => {
            verificationRunning.value = false;
        },
    });
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Veicoli</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.vehicles }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Schermi</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.screens }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Camere</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.cameras }}</p>
            </div>
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-5">
                <p class="text-sm text-muted-foreground">Altoparlanti</p>
                <p class="mt-2 text-3xl font-semibold">{{ props.stats.speakers }}</p>
            </div>
        </div>

        <section
            v-if="(postImportTasks.translationCount > 0 || postImportTasks.imageCount > 0) && !postImportTasks.dismissed && !tasksDismissedLocally"
            class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-5"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-amber-400">
                        {{ verificationCompleted ? 'Verifica catalogo completata' : 'Attività dopo l’importazione' }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Mancano {{ postImportTasks.translationCount }} traduzioni prodotto e
                        {{ postImportTasks.imageCount }} immagini auto. Copia le istruzioni e incollale direttamente in Codex.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-amber-500/60 px-4 py-2.5 text-sm font-semibold text-amber-300 transition hover:bg-amber-500/10"
                        @click="dismissPostImportTasks"
                    >
                        Segna come completata
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-semibold text-black transition hover:bg-amber-400"
                        @click="copyPostImportPrompt"
                    >
                        {{ taskCopyStatus === 'copied' ? 'Copiato!' : taskCopyStatus === 'error' ? 'Copia non riuscita' : 'Copia per Codex' }}
                    </button>
                </div>
            </div>
            <textarea
                :value="postImportTasks.prompt"
                readonly
                rows="12"
                class="mt-4 w-full resize-y rounded-lg border border-sidebar-border/70 bg-background p-4 font-mono text-xs leading-5 text-foreground outline-none"
                aria-label="Istruzioni post-importazione per Codex"
            />
        </section>

        <section v-else-if="postImportTasks.translationCount === 0 && postImportTasks.imageCount === 0" class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-5 py-4 text-sm text-emerald-300">
            <span class="font-semibold">{{ verificationCompleted ? 'Verifica completata:' : 'Tutto aggiornato:' }}</span>
            tutte le autoradio hanno l’immagine auto corrispondente e tutti i titoli sono tradotti.
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <section class="rounded-xl border border-sidebar-border/70 bg-card p-6">
                <div class="max-w-2xl space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold">Import catalogo configuratore</h1>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Carica l'export CSV o Excel di Shopify. Il sistema estrae prodotti schermo,
                            camere e altoparlanti e aggiorna il configuratore pubblico. Le installazioni
                            vengono gestite separatamente nelle zone di installazione.
                        </p>
                    </div>

                    <div
                        v-if="props.flashStatus"
                        class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200"
                    >
                        {{ props.flashStatus }}
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <fieldset class="grid gap-3">
                            <legend class="text-sm font-medium">Modalità di importazione</legend>
                            <label class="flex cursor-pointer gap-3 rounded-lg border border-sidebar-border/70 p-4" :class="form.mode === 'replace' ? 'border-primary bg-primary/5' : ''">
                                <input v-model="form.mode" type="radio" value="replace" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-medium">Sostituzione completa</span>
                                    <span class="mt-1 block text-xs text-muted-foreground">Cancella il catalogo precedente e conserva solamente i dati del nuovo file.</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer gap-3 rounded-lg border border-sidebar-border/70 p-4" :class="form.mode === 'add' ? 'border-primary bg-primary/5' : ''">
                                <input v-model="form.mode" type="radio" value="add" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-medium">Aggiungi o aggiorna</span>
                                    <span class="mt-1 block text-xs text-muted-foreground">Mantiene gli altri prodotti e aggiorna quelli con lo stesso Handle.</span>
                                </span>
                            </label>
                        </fieldset>

                        <div class="space-y-2">
                            <label for="catalog" class="text-sm font-medium">CSV o Excel Shopify</label>
                            <input
                                id="catalog"
                                type="file"
                                accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                class="block w-full rounded-lg border border-sidebar-border/70 bg-background px-4 py-3 text-sm"
                                @change="updateFile"
                            />
                            <p v-if="form.errors.catalog" class="text-sm text-red-400">
                                {{ form.errors.catalog }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-5 py-3 text-sm font-medium text-primary-foreground"
                            :disabled="form.processing || !form.catalog"
                        >
                            {{ form.processing ? 'Import in corso...' : 'Importa catalogo' }}
                        </button>
                    </form>
                </div>
            </section>

            <aside class="rounded-xl border border-sidebar-border/70 bg-card p-6">
                <h2 class="text-lg font-semibold">Link utili</h2>
                <div class="mt-4 space-y-3">
                    <a
                        href="/configurator"
                        class="block rounded-lg border border-sidebar-border/70 px-4 py-3 text-sm transition hover:bg-accent"
                    >
                        Apri configuratore pubblico
                    </a>
                    <button
                        type="button"
                        class="w-full rounded-lg border border-emerald-500/40 bg-emerald-500/5 px-4 py-3 text-left text-sm font-medium text-emerald-300 transition hover:bg-emerald-500/10 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="verificationRunning"
                        @click="verifyCatalog"
                    >
                        {{ verificationRunning ? 'Verifica in corso…' : 'Verifica immagini e traduzioni' }}
                    </button>
                    <div class="rounded-lg border border-sidebar-border/70 px-4 py-3 text-sm text-muted-foreground">
                        In alternativa puoi importare da CLI con
                        <code class="ml-1 rounded bg-muted px-1.5 py-0.5 text-foreground">
                            php artisan configurator:import-csv /percorso/file.csv --add
                        </code>
                    </div>
                    <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
                        <h3 class="text-sm font-medium">Aggiornamenti database</h3>
                        <p class="mt-2 text-xs leading-5 text-muted-foreground">
                            Utilizza questo comando dopo aver pubblicato una versione che contiene nuove migrazioni.
                        </p>
                        <p v-if="migrationForm.errors.database" class="mt-3 text-xs text-destructive">
                            {{ migrationForm.errors.database }}
                        </p>
                        <button
                            type="button"
                            class="mt-4 w-full rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-medium text-black disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="migrationForm.processing"
                            @click="migrateDatabase"
                        >
                            {{ migrationForm.processing ? 'Aggiornamento in corso…' : 'Aggiorna database' }}
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
