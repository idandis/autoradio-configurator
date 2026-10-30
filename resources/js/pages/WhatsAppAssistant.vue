<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';
import { Eye, MessageCircle, RefreshCw, Save, ShieldCheck, SlidersHorizontal, Trash2, Zap } from '@lucide/vue';

type Rules = {
    standard_replies: { category: 'general' | 'product'; title?: string; topic?: string; questions: { it: string; es: string; en: string }; answers: { it: string; es: string; en: string }; enabled: boolean }[];
    enabled: boolean; test_number: string; additional_test_numbers?: string[]; language: string; tone: string;
    fallback_language: string; welcome_messages: { it: string; es: string; en: string }; reply_delay_seconds: number; max_words: number; max_questions: number; max_products: number;
    direct_offers: boolean; show_prices: boolean; show_links: boolean; price_disclaimer: boolean;
    handoff_keywords: string; handoff_on_missing: boolean; custom_rules: string;
};
type Runtime = { mail?: { state: string; at: number } | null; running: boolean; state: string; replies: number; expires_at: number | null; number: string | null; handoff: boolean; manual_stop: boolean; local: boolean; connection?: { available: boolean; status: string } };
type Preview = { document?: { id: string; filename: string; download_url: string; download_label?: string; caption: string } | null; text: string; mode: string; vehicle: { brand?: string; model?: string; year?: number }; matches: number };
const props = defineProps<{ rules: Rules; runtime: Runtime; previewPhotoEmailSent?: boolean; previewChat?: (Preview & { question: string })[] }>();
const form = useForm<Rules>({ ...props.rules });
const languages = [{ key: 'it', label: 'Italiano' }, { key: 'es', label: 'Español' }, { key: 'en', label: 'English' }] as const;
const tab = ref('rules');
const runtime = ref(props.runtime);
const saved = ref(false);
const message = ref('');
const chat = ref(props.previewChat ?? []);
const preview = ref<Preview | null>(null);
const busy = ref(false);
const pendingQuestion = ref('');
const photo = ref<File | null>(null);
const audio = ref<File | null>(null);
const audioInput = ref<HTMLInputElement | null>(null);
const selectAudio = (event: Event) => { audio.value = (event.target as HTMLInputElement).files?.[0] ?? null; };
const sendingPhotoEmail = ref(false);
const photoEmailSent = ref(props.previewPhotoEmailSent ?? false);
const photoEmailNotice = ref('');
const sendPhotoEmail = async () => {
    sendingPhotoEmail.value = true; photoEmailNotice.value = '';
    try { await axios.post('/dashboard/whatsapp/preview/photo-email'); photoEmailSent.value = true; }
    catch (e: any) { photoEmailNotice.value = e.response?.data?.message ?? 'Invio email di prova non riuscito.'; }
    finally { sendingPhotoEmail.value = false; }
};
const photoInput = ref<HTMLInputElement | null>(null);
const selectPhoto = (event: Event) => { photo.value = (event.target as HTMLInputElement).files?.[0] ?? null; };
const error = ref('');
const statusError = ref(false);
type ClientChat = { id: number; phone: string; import_label_name: string | null; authorized?: boolean; paused: boolean; pause_reason: string | null; last_activity_at: string | null };
const clients = ref<ClientChat[]>([]);
const chatSearch = ref('');
const chatPage = ref(1);
const chatLastPage = ref(1);
const chatError = ref('');
const changingChat = ref<number | null>(null);
const deletingChat = ref<number | 'all' | null>(null);
let chatVersion = 0;
const syncingChats = ref(false);
const chatLabels = ref<{ id: string; name: string }[]>([]);
const importLabel = ref('');
const loadingLabels = ref(false);
const labelError = ref('');
const syncNotice = ref('');
const loadLabels = async () => {
    if (!runtime.value.local || loadingLabels.value) return;
    loadingLabels.value = true; labelError.value = '';
    try {
        const { data } = await axios.get('/dashboard/whatsapp/chats/labels');
        chatLabels.value = data.labels;
        if (!importLabel.value) importLabel.value = chatLabels.value.find(l => l.name.trim().toLocaleUpperCase() === 'NUOVO CLIENTE')?.id ?? '';
    } catch { labelError.value = 'Etichette non disponibili. Verifica WAHA e riprova.'; }
    finally { loadingLabels.value = false; }
};

const syncChats = async () => {
    if (!importLabel.value) return;
    const labelId = importLabel.value === '__all__' ? null : importLabel.value;
    syncingChats.value = true; chatError.value = ''; syncNotice.value = '';
    try {
        let offset: number | null = 0;
        while (offset !== null) {
            const { data }: { data: { next_offset: number | null } } = await axios.post('/dashboard/whatsapp/chats/sync', { offset, label_id: labelId });
            offset = data.next_offset;
        }
        await loadChats(1);
        syncNotice.value = labelId ? `Importazione completata: ${chatLabels.value.find(l => l.id === labelId)?.name ?? 'etichetta selezionata'}.` : 'Sincronizzazione di tutte le chat completata.';
    } catch { chatError.value = 'Sincronizzazione non riuscita. Verifica che WAHA locale sia collegato.'; }
    finally { syncingChats.value = false; }
};

const loadingChats = ref(false);
const pauseReasons: Record<string, string> = { dashboard: 'Pausa manuale', handoff: 'Passaggio a operatore', photo_review: 'Compatibilità da verificare con foto', technical_handoff: 'Verifica tecnica', operator_message: 'Risposta manuale da WhatsApp', send_unverified: 'Invio da verificare' };
const loadChats = async (page = chatPage.value) => {
    if (loadingChats.value) return;
    loadingChats.value = true;
    const version = chatVersion;
    try {
        const { data } = await axios.get('/dashboard/whatsapp/chats', { params: { page, q: chatSearch.value } });
        if (version !== chatVersion) return;
        clients.value = data.data; chatPage.value = data.current_page; chatLastPage.value = data.last_page; chatError.value = '';
    } catch { chatError.value = 'Impossibile aggiornare le chat. Riprova.'; }
    finally { loadingChats.value = false; }
};
const toggleChat = async (client: ClientChat) => {
    changingChat.value = client.id; chatVersion++; chatError.value = '';
    try {
        const { data } = await axios.patch(`/dashboard/whatsapp/chats/${client.id}`, { paused: !client.paused });
        clients.value = clients.value.map(c => c.id === client.id ? { ...c, ...data } : c);
    } catch { chatError.value = 'Modifica non riuscita. Riprova.'; }
    finally { changingChat.value = null; }
};
const deleteChat = async (client?: ClientChat) => {
    if (!client && !window.confirm('Eliminare tutte le righe, anche quelle nelle altre pagine o nascoste dal filtro? Le chat su WhatsApp restano intatte.')) return;
    deletingChat.value = client?.id ?? 'all'; chatError.value = ''; syncNotice.value = ''; chatVersion++;
    try {
        await axios.delete(client ? `/dashboard/whatsapp/chats/${client.id}` : '/dashboard/whatsapp/chats');
        clients.value = client ? clients.value.filter(c => c.id !== client.id) : [];
        if (!client) { chatPage.value = 1; chatLastPage.value = 1; }
        syncNotice.value = client ? 'Riga eliminata.' : 'Tutte le righe eliminate.';
        await loadChats(1);
    } catch { chatError.value = 'Eliminazione non riuscita. Riprova.'; }
    finally { deletingChat.value = null; }
};
const chatTime = (value: string | null) => value ? new Date(value).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '—';

const restarting = ref(false);
const reconnecting = ref(false);
const restartNotice = ref('');
const restartError = ref('');
const checking = ref(false);
const checkNotice = ref('');
const recoverUnanswered = async () => {
    checking.value = true; checkNotice.value = '';
    const labels: Record<string, string> = { replied: 'risposta inviata', answered: 'nessuna domanda in attesa', paused: 'domanda da gestire con operatore o servizio in pausa', busy: 'risposta già in preparazione', needs_review: 'invio precedente da verificare, nessun duplicato inviato', error: 'controllo non riuscito' };
    try {
        const { data } = await axios.post('/dashboard/whatsapp/recover-unanswered');
        checkNotice.value = data.results.map((r: { number: string; state: string }) => `+${r.number}: ${labels[r.state] ?? r.state}`).join('\n');
        await refresh();
    } catch (e: any) { checkNotice.value = e.response?.data?.message ?? 'Controllo non riuscito. Riprova.'; }
    finally { checking.value = false; }
};
let restartAt = 0;
const connectionLabels: Record<string, string> = { WORKING: 'WhatsApp collegato', STARTING: 'Collegamento in corso…', SCAN_QR_CODE: 'Scansiona nuovamente il QR', STOPPED: 'Sessione ferma', FAILED: 'Sessione da riconnettere', UNREACHABLE: 'WAHA non raggiungibile', UNAVAILABLE: 'Collegamento locale non disponibile' };
const connectionText = computed(() => connectionLabels[runtime.value.connection?.status ?? ''] ?? 'Verifica collegamento…');
const status = computed(() => !runtime.value.running ? 'Servizio fermo' : runtime.value.state === 'busy' ? 'Sta rispondendo' : runtime.value.state === 'connection_error' ? 'Collegamento da verificare' : runtime.value.state === 'paused' || !form.enabled ? 'Risposte in pausa' : 'Servizio attivo');
const expires = computed(() => runtime.value.expires_at ? new Date(runtime.value.expires_at * 1000).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) : null);
const save = () => form.put('/dashboard/whatsapp', { preserveScroll: true, onSuccess: () => { form.defaults(); saved.value = true; preview.value = null; void refresh(); } });
const refresh = async () => {
    void loadChats();
    try {
        runtime.value = (await axios.get('/dashboard/whatsapp/status')).data; statusError.value = false;
        if (reconnecting.value) {
            const state = runtime.value.connection?.status;
            if (state === 'WORKING') { reconnecting.value = false; restartNotice.value = 'Sessione ricollegata. Le regole e la memoria della chat sono conservate.'; }
            else if (state === 'SCAN_QR_CODE') { reconnecting.value = false; restartNotice.value = 'È necessario un nuovo abbinamento: apri il pannello WAHA e scansiona il QR dal telefono.'; }
            else if (['FAILED', 'STOPPED', 'UNREACHABLE'].includes(state ?? '') || Date.now() - restartAt > 60000) { reconnecting.value = false; restartNotice.value = 'Riconnessione non completata. Controlla lo stato nel pannello WAHA.'; }
        }
    }
    catch {
        statusError.value = true;
        if (reconnecting.value && Date.now() - restartAt > 60000) { reconnecting.value = false; restartError.value = 'Impossibile verificare la riconnessione. Controlla WAHA e riprova.'; }
    }
};
const restart = async () => {
    restarting.value = true; restartNotice.value = ''; restartError.value = '';
    try {
        const response = await axios.post('/dashboard/whatsapp/restart');
        runtime.value.connection = response.data.connection;
        reconnecting.value = true; restartAt = Date.now();
        restartNotice.value = 'Riavvio richiesto. Attendo la riconnessione…';
        await refresh();
    } catch (e: any) { restartError.value = e.response?.data?.message ?? 'Riavvio non riuscito. Verifica Docker e WAHA.'; }
    finally { restarting.value = false; }
};
const tryPreview = async () => {
    const question = message.value.trim();
    busy.value = true; error.value = ''; preview.value = null; pendingQuestion.value = question || (audio.value ? '[Audio in trascrizione]' : '[Foto allegata]'); message.value = '';
    try {
        let payload: FormData | { message: string } = { message: question };
        if (photo.value || audio.value) {
            payload = new FormData(); payload.append('message', question);
            if (photo.value) payload.append('photo', photo.value);
            if (audio.value) payload.append('audio', audio.value);
        }
        const data = (await axios.post('/dashboard/whatsapp/preview', payload)).data;
        preview.value = data; chat.value = data.chat; photo.value = null; audio.value = null;
        if (photoInput.value) photoInput.value.value = '';
        if (audioInput.value) audioInput.value.value = '';
    }
    catch (e: any) { message.value ||= question; error.value = e.response?.data?.errors?.audio?.[0] ?? e.response?.data?.errors?.photo?.[0] ?? e.response?.data?.errors?.message?.[0] ?? e.response?.data?.message ?? 'Anteprima non disponibile. Riprova.'; }
    finally { busy.value = false; pendingQuestion.value = ''; }
};
const resetChat = async () => {
    busy.value = true; error.value = '';
    try { await axios.post('/dashboard/whatsapp/preview/reset'); chat.value = []; photoEmailSent.value = false; photoEmailNotice.value = ''; preview.value = null; message.value = ''; photo.value = null; audio.value = null; if (photoInput.value) photoInput.value.value = ''; if (audioInput.value) audioInput.value.value = ''; }
    catch { error.value = 'Reset della simulazione non riuscito. Riprova.'; }
    finally { busy.value = false; }
};

let timer: ReturnType<typeof setInterval>;
onMounted(() => { void refresh(); void loadLabels(); timer = setInterval(() => { if (!document.hidden) void refresh(); }, 5000); });
onUnmounted(() => clearInterval(timer));
const toggleFields = [
    { key: 'show_prices', label: 'Mostra il prezzo “da”', detail: 'Usa il prezzo regionale quando noto, altrimenti il prezzo base di catalogo.' },
    { key: 'price_disclaimer', label: 'Aggiungi “Prezzi di catalogo indicativi”', detail: 'Breve nota alla fine delle proposte con prezzo.' },
] as const;
</script>

<template>
    <Head title="Assistente WhatsApp" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 lg:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="mb-2 flex items-center gap-2 text-sm font-medium text-emerald-500"><MessageCircle class="size-4" /> WHATSAPP</div>
                <h1 class="text-3xl font-semibold tracking-tight">Assistente WhatsApp</h1>
                <p class="mt-2 text-sm text-muted-foreground">Regole, proposte e anteprime in un unico posto.</p>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="form.isDirty" class="text-sm text-amber-500">Modifiche da salvare</span>
                <span v-else-if="saved" role="status" class="text-sm text-emerald-500">Regole salvate</span>
                <button type="submit" form="whatsapp-rules" :disabled="form.processing || !form.isDirty" class="flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"><Save class="size-4" /> {{ form.processing ? 'Salvataggio…' : 'Salva regole' }}</button>
            </div>
        </header>

        <section class="rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="font-semibold">Chat e interventi operatore</h2><p class="mt-1 text-xs text-muted-foreground">Una riga per contatto, senza testo dei messaggi. Scegli un’etichetta per importare soltanto le chat associate. La pausa resta attiva fino a “Riprendi bot”; gli altri clienti continuano normalmente.</p></div>
                <form class="flex flex-wrap gap-2" @submit.prevent="loadChats(1)"><select v-model="importLabel" aria-label="Etichetta WhatsApp da importare" :disabled="syncingChats || loadingLabels || !runtime.local" class="max-w-60 rounded-lg border bg-background px-3 py-2 text-sm disabled:opacity-50"><option disabled value="">{{ loadingLabels ? 'Caricamento etichette…' : 'Seleziona un’etichetta' }}</option><option v-for="label in chatLabels" :key="label.id" :value="label.id">{{ label.name }}</option><option value="__all__">Tutte le chat</option></select><button type="button" :disabled="loadingLabels || syncingChats || !runtime.local" aria-label="Aggiorna etichette WhatsApp" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="loadLabels"><RefreshCw class="size-4" :class="{ 'animate-spin': loadingLabels }" /></button><button type="button" :disabled="syncingChats || deletingChat !== null || !runtime.local || !importLabel" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50" @click="syncChats">{{ syncingChats ? 'Importazione…' : 'Importa chat' }}</button><input v-model="chatSearch" aria-label="Cerca numero cliente" placeholder="Cerca numero" maxlength="30" class="w-44 rounded-lg border bg-background px-3 py-2 text-sm" /><button type="submit" :disabled="loadingChats" class="rounded-lg border px-3 py-2 text-sm disabled:opacity-50">Cerca</button></form>
            </div>
            <div class="mt-3 flex justify-end"><button type="button" :disabled="deletingChat !== null || changingChat !== null || syncingChats" class="flex items-center gap-2 rounded-lg border border-red-500/30 px-3 py-2 text-sm text-red-600 hover:bg-red-500/10 disabled:opacity-50" @click="deleteChat()"><Trash2 class="size-4" />{{ deletingChat === 'all' ? 'Eliminazione…' : 'Elimina tutte le righe' }}</button></div>
            <p v-if="labelError" role="alert" class="mt-3 text-sm text-red-500">{{ labelError }}</p>
            <p v-if="syncNotice" role="status" class="mt-3 text-sm text-emerald-600">{{ syncNotice }}</p>
            <p v-if="chatError" role="alert" class="mt-3 text-sm text-red-500">{{ chatError }}</p>
            <div class="mt-4 max-h-[728px] overflow-auto"><table class="min-w-[760px] w-full text-left text-sm">
                <thead class="sticky top-0 z-10 bg-card"><tr class="h-12 border-b text-muted-foreground"><th scope="col" class="py-3 pr-4">Cliente</th><th scope="col" class="py-3 pr-4" title="Etichetta scelta durante l’importazione">Etichetta</th><th scope="col" class="py-3 pr-4">Stato bot</th><th scope="col" class="py-3 pr-4">Motivo pausa</th><th scope="col" class="py-3 pr-4">Ultima attività</th><th scope="col" class="py-3">Azione</th></tr></thead>
                <tbody><tr v-for="client in clients" :key="client.id" class="h-[68px] border-b last:border-0">
                    <td class="py-3 pr-4 whitespace-nowrap">+{{ client.phone }}<span v-if="!client.authorized" class="mt-1 block text-xs text-muted-foreground">Fuori dai numeri di prova</span></td>
                    <td class="max-w-56 truncate whitespace-nowrap py-3 pr-4" :title="client.import_label_name ?? undefined">{{ client.import_label_name ?? '—' }}</td>
                    <td class="py-3 pr-4 whitespace-nowrap" :class="client.paused ? 'text-amber-600' : 'text-emerald-600'">{{ client.paused ? (['handoff', 'technical_handoff', 'photo_review'].includes(client.pause_reason ?? '') ? 'Operatore · standard attive' : 'In pausa') : 'Attivo' }}</td>
                    <td class="whitespace-nowrap py-3 pr-4">{{ client.pause_reason ? (pauseReasons[client.pause_reason] ?? 'In pausa') : '—' }}</td>
                    <td class="py-3 pr-4 whitespace-nowrap">{{ chatTime(client.last_activity_at) }}</td>
                    <td class="whitespace-nowrap py-3"><button type="button" :disabled="changingChat !== null || deletingChat !== null || syncingChats" :aria-label="`${client.paused ? 'Riprendi bot' : 'Metti in pausa'} per +${client.phone}`" class="whitespace-nowrap rounded-lg border px-3 py-2 font-medium hover:bg-muted disabled:opacity-50" @click="toggleChat(client)">{{ changingChat === client.id ? 'Salvataggio…' : client.paused ? 'Riprendi bot' : 'Metti in pausa' }}</button><button type="button" :disabled="deletingChat !== null || changingChat !== null || syncingChats" :aria-label="`Elimina riga per +${client.phone}`" title="Elimina riga" class="ml-2 inline-flex rounded-lg border border-red-500/30 p-2 text-red-600 hover:bg-red-500/10 disabled:opacity-50" @click="deleteChat(client)"><Trash2 class="size-4" /></button></td>
                </tr><tr v-if="!clients.length"><td colspan="6" class="py-5 text-muted-foreground">{{ loadingChats ? 'Caricamento…' : 'Nessuna chat trovata. Importa le chat da un’etichetta oppure attendi un nuovo contatto gestito dal servizio.' }}</td></tr></tbody>
            </table></div>
            <div v-if="chatLastPage > 1" class="mt-3 flex items-center justify-end gap-3 text-sm"><button type="button" :disabled="chatPage <= 1 || loadingChats" class="rounded border px-3 py-1 disabled:opacity-50" @click="loadChats(chatPage - 1)">Precedente</button><span>{{ chatPage }} / {{ chatLastPage }}</span><button type="button" :disabled="chatPage >= chatLastPage || loadingChats" class="rounded border px-3 py-1 disabled:opacity-50" @click="loadChats(chatPage + 1)">Successiva</button></div>
        </section>

        <div class="grid gap-3 md:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Servizio locale</p>
                <p class="mt-2 flex items-center gap-2 font-semibold"><span class="size-2 rounded-full" :class="runtime.running ? 'bg-emerald-500' : 'bg-neutral-400'" />{{ status }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ statusError ? 'Stato non aggiornato: riprova.' : runtime.running && expires ? `Prova attiva fino alle ${expires} · ${runtime.replies} risposte` : 'Le regole restano salvate anche quando il servizio è fermo.' }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4"><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Modalità prova</p><p class="mt-2 font-semibold">Un solo numero autorizzato</p><p class="mt-1 text-xs text-muted-foreground">{{ runtime.number ? `Numero del servizio: +${runtime.number}` : 'Gli altri contatti restano esclusi.' }}</p></div>
            <div class="rounded-xl border bg-card p-4"><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Catalogo collegato</p><p class="mt-2 font-semibold">Catalogo di questa applicazione</p><p class="mt-1 text-xs text-muted-foreground">Le proposte immediate non consumano token AI.</p></div>
        </div>
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-5">
            <div><h2 class="flex items-center gap-2 font-semibold"><span class="size-2 rounded-full" :class="runtime.connection?.status === 'WORKING' ? 'bg-emerald-500' : 'bg-amber-500'" />{{ connectionText }}</h2><p class="mt-1 max-w-2xl text-xs text-muted-foreground">Riavvia la sessione WAHA per riconnettere WhatsApp. Conserva regole e conversazione; non riavvia Docker né il servizio risposte.</p><p v-if="restartNotice" role="status" class="mt-2 text-sm text-emerald-600">{{ restartNotice }}</p><p v-if="restartError" role="alert" class="mt-2 text-sm text-red-500">{{ restartError }}</p></div>
            <div class="flex flex-wrap gap-2"><a v-if="runtime.local" href="http://localhost:3000/dashboard" class="rounded-lg border px-4 py-2.5 text-sm font-medium hover:bg-muted">Apri WAHA / QR</a><button type="button" :disabled="!runtime.local || restarting || reconnecting" class="flex items-center gap-2 rounded-lg border border-emerald-600/40 px-4 py-2.5 text-sm font-semibold text-emerald-600 hover:bg-emerald-500/10 disabled:opacity-50" @click="restart"><RefreshCw class="size-4" :class="{ 'animate-spin': restarting || reconnecting }" />{{ restarting || reconnecting ? 'Riconnessione…' : 'Riavvia sessione WAHA' }}</button></div>
        </section>
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-5">
            <div><h2 class="font-semibold">Domande senza risposta</h2><p class="mt-1 text-xs text-muted-foreground">Controlla gli ultimi 30 messaggi di ciascun numero autorizzato e risponde a quelli successivi all’ultima risposta. Durante l’attesa dell’operatore risponde alle domande standard; per le altre conferma la presa in carico. Le pause manuali restano escluse.</p><p v-if="checkNotice" role="status" class="mt-2 whitespace-pre-line text-sm">{{ checkNotice }}</p></div>
            <button type="button" :disabled="checking || !runtime.local || !form.enabled || form.isDirty" class="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="recoverUnanswered"><RefreshCw class="size-4" :class="{ 'animate-spin': checking }" />{{ checking ? 'Controllo e invio…' : 'Controlla e rispondi' }}</button>
        </section>
        <p v-if="runtime.manual_stop" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm">Il processo è stato arrestato manualmente. Salvare le regole non riavvia il servizio locale.</p>
        <p v-if="!runtime.local" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm">La prova WhatsApp funziona sul Mac. Da questa installazione puoi gestire regole e anteprime, ma non il processo locale su un altro computer.</p>

        <p v-if="runtime.mail" role="status" class="rounded-lg border p-3 text-sm" :class="runtime.mail.state === 'failed' ? 'border-red-500/40 text-red-500' : 'border-emerald-500/40 text-emerald-600'">{{ runtime.mail.state === 'failed' ? 'Ultimo invio email non riuscito: la segnalazione richiede attenzione.' : 'Ultima segnalazione email accettata dal server SMTP.' }}</p>
        <nav aria-label="Configurazione WhatsApp" class="flex gap-2 border-b pb-3">
            <button v-for="item in [{ key: 'rules', label: 'Regole assistente' }, { key: 'standard', label: 'Risposte standard' }]" :key="item.key" type="button" :aria-pressed="tab === item.key" class="rounded-lg px-4 py-2 text-sm font-medium" :class="tab === item.key ? 'bg-emerald-600 text-white' : 'hover:bg-muted'" @click="tab = item.key">{{ item.label }}</button>
        </nav>
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(340px,0.8fr)]">
            <form id="whatsapp-rules" class="space-y-5" @submit.prevent="save">
                <button type="submit" :disabled="form.processing || !form.isDirty" class="w-full rounded-lg bg-emerald-600 px-4 py-3 font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">{{ form.processing ? 'Salvataggio…' : 'Salva regole' }}</button>
                <section v-if="tab === 'standard'" class="rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Risposte standard</h2>
                    <p class="mt-2 text-sm text-muted-foreground">Inserisci le traduzioni della stessa risposta, includendo il nome della funzione e tutte le condizioni. Il bot sceglie la lingua del cliente (o quella impostata nelle regole), senza AI né ricerca nel catalogo. Italiano obbligatorio; se manca una traduzione, segue il percorso normale.</p>
                    <p class="mt-2 text-xs text-muted-foreground">Argomenti riconosciuti: negozio, indirizzo, orari, contatti, CarPlay, Android Auto, GPS, 4G / SIM, Bluetooth, Wi-Fi, garanzia, spedizione, installazione, comandi al volante, retrocamera, FM, USB, equalizzatore, lingue, schermo touch, comandi vocali e funzioni originali. Usa una sola risposta attiva per argomento; puoi includere più funzioni nella stessa risposta.</p>
                    <p class="mt-2 text-xs text-muted-foreground">Le richieste su prezzi, modelli e compatibilità seguono il percorso normale. Gli argomenti non riconosciuti o ambigui non attivano una risposta standard. Questi testi non vengono aggiunti alle istruzioni AI.</p>
                    <button type="button" :disabled="form.standard_replies.length >= 50" class="mt-4 rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50" @click="form.standard_replies.unshift({ category: 'product', title: '', questions: { it: '', es: '', en: '' }, answers: { it: '', es: '', en: '' }, enabled: true })">Aggiungi risposta</button>
                    <div v-for="(reply, i) in form.standard_replies" :key="i" class="mt-5 rounded-lg border p-4">
                        <div class="mb-3 flex items-center justify-between gap-3"><label class="flex items-center gap-2 text-sm font-medium"><input v-model="reply.enabled" type="checkbox" class="size-4 accent-emerald-600" />{{ reply.title || reply.topic || `Risposta ${i + 1}` }} attiva</label><button type="button" class="text-sm text-red-500" @click="form.standard_replies.splice(i, 1)">Elimina</button></div>
                        <label :for="`standard-category-${i}`" class="mb-3 block text-sm">Tipo di risposta<select :id="`standard-category-${i}`" v-model="reply.category" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5"><option value="general">Informazioni generali</option><option value="product">Funzioni del prodotto</option></select><span class="mt-1 block text-xs text-muted-foreground">{{ reply.category === 'general' ? 'Risponde subito, senza dati dell’auto: negozio, indirizzo, orari e contatti.' : 'Richiede prima marca, modello, anno e proposta del prodotto con link.' }}</span></label>
                        <label :for="`standard-title-${i}`" class="block text-sm">Titolo della risposta</label>
                        <input :id="`standard-title-${i}`" v-model="reply.title" type="text" maxlength="100" placeholder="Es. CarPlay wireless" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5 text-sm" />
                        <p class="mt-1 text-xs text-muted-foreground">Titolo non inviato al cliente. Può riconoscere una frase di almeno due parole presente nella domanda; gli argomenti comuni usano anche i sinonimi previsti.</p>
                        <div v-for="lang in languages" :key="lang.key" class="mt-3">
                            <label :for="`standard-${i}-${lang.key}`" class="text-sm">{{ lang.label }}{{ lang.key === 'it' ? ' *' : '' }}</label>
                            <textarea :id="`standard-${i}-${lang.key}`" v-model="reply.answers[lang.key]" rows="3" maxlength="1000" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5 text-sm" :placeholder="lang.key === 'it' ? 'Es. Il 4G è disponibile solo nelle varianti da 8 core e 6 GB di RAM in su.' : 'Traduzione con le stesse condizioni della risposta italiana'" />
                            <label :for="`standard-questions-${i}-${lang.key}`" class="mt-3 block text-xs">Esempi di domanda in {{ lang.label }} (facoltativi, uno per riga)</label>
                            <textarea :id="`standard-questions-${i}-${lang.key}`" v-model="reply.questions[lang.key]" rows="2" maxlength="1500" class="mt-1 w-full rounded-lg border bg-background px-3 py-2 text-sm" placeholder="Aggiungi qui le formulazioni che vuoi riconoscere. Maiuscole e punteggiatura vengono ignorate." />
                        </div>
                    </div>
                    <p v-if="!form.standard_replies.length" class="mt-5 rounded-lg bg-muted p-4 text-sm text-muted-foreground">Nessuna risposta inserita. Aggiungi le caratteristiche confermate delle tue radio.</p>
                </section>
                <div v-show="tab === 'rules'" class="space-y-5">
                <section class="rounded-xl border bg-card p-5">
                    <h2 class="flex items-center gap-2 text-lg font-semibold"><SlidersHorizontal class="size-5 text-emerald-500" /> Stato e risposte</h2>
                    <div class="mt-5 rounded-lg border p-4">
                        <h3 class="font-medium">Messaggio di benvenuto</h3>
                        <p class="mt-1 text-xs text-muted-foreground">Al primo contatto e dopo 24 ore senza messaggi. Il saluto del cliente viene ricambiato automaticamente prima di questo testo. Le risposte successive non ripetono il benvenuto.</p>
                        <label v-for="lang in languages" :key="lang.key" class="mt-3 block text-sm">{{ lang.label }}<textarea v-model="form.welcome_messages[lang.key]" rows="2" maxlength="500" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5" /></label>
                    </div>
                    <label class="mt-5 flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-4"><span><span class="block font-medium">Consenti risposte automatiche</span><span class="mt-1 block text-xs text-muted-foreground">La pausa si applica al servizio già avviato; puoi riprenderlo finché la prova è in corso.</span></span><input v-model="form.enabled" type="checkbox" class="size-5 accent-emerald-600" /></label>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium sm:col-span-2">Numero autorizzato per la prova<input v-model="form.test_number" type="tel" autocomplete="off" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5" placeholder="+34…" /><span class="mt-1 block text-xs text-muted-foreground">Prefisso internazionale incluso. Se cambi numero, i vecchi messaggi della nuova chat non riceveranno risposte.</span><span v-if="form.additional_test_numbers?.length" class="mt-1 block text-xs text-muted-foreground">Altri numeri autorizzati: {{ form.additional_test_numbers.map(n => `+${n}`).join(', ') }}</span></label>
                        <label class="text-sm font-medium">Lingua<select v-model="form.language" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5"><option value="auto">Lingua del cliente</option><option value="it">Italiano</option><option value="es">Spagnolo</option><option value="en">Inglese</option></select></label>
                        <label class="text-sm font-medium">Attesa prima dell’invio (secondi)<input v-model.number="form.reply_delay_seconds" type="number" min="0" max="15" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5" /><span class="mt-1 block text-xs text-muted-foreground">Si aggiunge al tempo di preparazione di ogni risposta, incluse quelle standard. Non consuma token.</span></label>
                        <label class="text-sm font-medium">Lingua predefinita se il messaggio è incerto<select v-model="form.fallback_language" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5"><option value="es">Español</option><option value="it">Italiano</option><option value="en">English</option></select><span class="mt-1 block text-xs text-muted-foreground">In modalità automatica, conserva la lingua della conversazione se il nuovo messaggio non è chiaro.</span></label>
                        <label class="text-sm font-medium">Tono delle risposte libere<select v-model="form.tone" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5"><option value="diretto">Diretto</option><option value="cordiale">Cordiale</option><option value="professionale">Professionale</option></select></label>
                        <label class="text-sm font-medium">Massimo parole nelle risposte libere<input v-model.number="form.max_words" type="number" min="15" max="120" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5" /></label>
                        <label class="text-sm font-medium">Domande per risposta<select v-model.number="form.max_questions" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5"><option :value="0">Nessuna domanda</option><option :value="1">Al massimo una, se necessaria</option></select></label>
                    </div>
                </section>
                <section class="rounded-xl border bg-card p-5">
                    <h2 class="flex items-center gap-2 text-lg font-semibold"><Zap class="size-5 text-emerald-500" /> Proposte immediate</h2><p class="mt-2 text-sm text-muted-foreground">Ordine obbligatorio: marca, modello e anno → prodotto corrispondente con link → risposte sulle funzioni di quel prodotto. Se mancano dati, il bot chiede solo quelli mancanti.</p>
                    <p class="mt-1 text-xs text-muted-foreground">Formato breve: prodotto, prezzo e link. Su richiesta prepara il preventivo di radio e variante, con sconto del configuratore e link di pagamento disponibile; non chiede installazione o accessori. Il limite parole e il tono si applicano alle risposte libere.</p>
                    <div class="mt-4 divide-y"><label v-for="field in toggleFields" :key="field.key" class="flex cursor-pointer items-start gap-3 py-3"><input v-model="form[field.key]" type="checkbox" class="mt-0.5 size-4 shrink-0 accent-emerald-600" /><span><span class="block text-sm font-medium">{{ field.label }}</span><span class="mt-1 block text-xs text-muted-foreground">{{ field.detail }}</span></span></label></div>
                    <label class="mt-4 block text-sm font-medium">Numero massimo di proposte<select v-model.number="form.max_products" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5"><option :value="1">1 prodotto</option><option :value="2">2 prodotti</option><option :value="3">3 prodotti</option></select></label>
                </section>
                <section class="rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Quando intervieni tu</h2>
                    <p class="mt-1 text-xs text-muted-foreground">L’assistente avvisa il cliente e sospende questa chat. Non invia una notifica separata all’operatore.</p>
                    <label class="mt-4 block text-sm font-medium">Parole o frasi che richiedono un operatore<textarea v-model="form.handoff_keywords" rows="4" maxlength="1000" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5 font-normal" /><span class="mt-1 block text-xs text-muted-foreground">Una per riga. Lascia vuoto per disabilitare il controllo sulle parole.</span></label>
                    <label class="mt-4 flex items-start gap-3 text-sm">Se non risultano schermi specifici, il bot invia il modulo “Radio non trovata” con i dati dell’auto e il numero WhatsApp, senza chiedere nome, email o provincia.</label>
                </section>
                <section class="rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Indicazioni aggiuntive</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Per le risposte libere: stile, informazioni da chiedere o da evitare. Non cambiano il formato delle proposte immediate.</p>
                    <textarea v-model="form.custom_rules" rows="5" maxlength="1500" class="mt-4 w-full rounded-lg border bg-background px-3 py-2.5 text-sm" placeholder="Es. Non salutare a ogni messaggio. Rispondi solo alla domanda del cliente." />
                    <div class="mt-4 flex gap-2 rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"><ShieldCheck class="size-4 shrink-0" /><p>Regole sempre attive: solo fatti del catalogo, nessuna disponibilità o compatibilità inventata, nessun ordine automatico. Gruppi e contatti non autorizzati restano esclusi.</p></div>
                </section>
                </div>
                <div v-if="Object.keys(form.errors).length" role="alert" class="rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-500"><p v-for="(value, key) in form.errors" :key="key">{{ value }}</p></div>
            </form>

            <aside class="space-y-5 xl:sticky xl:top-6">
                <section class="overflow-hidden rounded-xl border bg-card">
                    <div class="border-b p-5"><h2 class="flex items-center gap-2 text-lg font-semibold"><Eye class="size-5 text-emerald-500" /> Anteprima della risposta</h2><button type="button" :disabled="busy || sendingPhotoEmail || !chat.length" class="mt-3 rounded-lg border px-3 py-2 text-sm font-medium disabled:opacity-50" @click="resetChat">Reset chat</button><p class="mt-1 text-xs text-muted-foreground">Usa le regole salvate. Simula le stesse risposte di WhatsApp: benvenuto dopo 24 ore, saluti, memoria, attesa e passaggio a operatore. Reset chat riparte da un nuovo contatto. Non invia messaggi reali.</p></div>
                    <div class="p-5">
                        <label for="preview-question" class="text-sm font-medium">Messaggio del cliente</label>
                        <textarea id="preview-question" v-model="message" maxlength="3000" rows="4" class="mt-2 w-full rounded-lg border bg-background px-3 py-2.5 text-sm" />
                        <label class="mt-3 block text-sm font-medium">Foto del cliente<input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" class="mt-2 block w-full text-sm" @change="selectPhoto" /><span class="mt-1 block text-xs font-normal text-muted-foreground">JPG, PNG o WEBP, massimo 8 MB. Simula la segnalazione con foto, senza analisi AI né invio email reale.</span></label>
                        <label class="mt-3 block text-sm font-medium">Audio del cliente<input ref="audioInput" type="file" accept=".ogg,.opus,.mp3,.wav,.m4a,.webm,audio/*" :disabled="busy || !runtime.local" class="mt-2 block w-full text-sm" @change="selectAudio" /><span class="mt-1 block text-xs font-normal text-muted-foreground">OGG, OPUS, MP3, WAV, M4A o WEBM, massimo 12 MB e 2 minuti. Il Mac trascrive l’audio e mostra la risposta nella chat di prova.</span></label>
                        <p v-if="form.isDirty" class="mt-2 text-xs text-amber-500">Salva le modifiche prima di provare la risposta.</p>
                        <button type="button" :disabled="busy || form.isDirty || (!message.trim() && !photo && !audio)" class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-foreground px-4 py-3 text-sm font-semibold text-background disabled:opacity-50" @click="tryPreview"><RefreshCw v-if="busy" class="size-4 animate-spin" /><Eye v-else class="size-4" />{{ busy ? (audio ? 'Trascrizione audio e preparazione risposta…' : 'Preparazione e attesa prima dell’invio…') : 'Prova senza inviare' }}</button>
                        <p class="mt-2 text-xs text-muted-foreground">Le risposte standard e le proposte dirette non consumano token. Le risposte libere usano l’AI.</p>
                        <p v-if="error" role="alert" class="mt-3 text-sm text-red-500">{{ error }}</p>
                    </div>
                    <div class="min-h-48 border-t bg-emerald-950/10 p-5">
                        <template v-if="chat.length"><div v-for="(preview, i) in chat" :key="i" class="mb-5"><p class="mb-3 whitespace-pre-wrap break-words rounded-xl bg-muted p-3 text-sm">{{ preview.question }}</p><div class="mb-3 flex flex-wrap gap-2 text-xs text-muted-foreground"><span class="rounded-full border px-2 py-1">{{ ['missing_form', 'missing_submitted'].includes(preview.mode) ? 'Modulo radio non trovata · 0 token AI' : preview.mode === 'operator_waiting' ? 'In attesa di operatore · 0 token AI' : preview.mode === 'photo_review' ? 'Foto per verifica · simulazione · 0 token AI' : preview.mode === 'photo_error' ? 'Foto non recuperata · 0 token AI' : preview.mode === 'technical_handoff' ? 'Verifica tecnica · email inviata' : preview.mode === 'greeting' ? 'Saluto · 0 token AI' : preview.mode === 'clarify' ? 'Formato DIN / budget · 0 token AI' : preview.mode === 'vehicle_details' ? 'Dati veicolo mancanti · 0 token AI' : preview.mode === 'quote' ? 'Preventivo · 0 token AI' : preview.mode === 'quote_choice' ? 'Scelta versione · 0 token AI' : preview.mode === 'standard_general' ? 'Informazioni generali · 0 token AI' : preview.mode === 'standard' ? 'Risposta standard · 0 token AI' : preview.mode === 'accessory_offer' ? 'Prodotto richiesto · 0 token AI' : preview.mode === 'offer' ? 'Proposta diretta · 0 token AI' : preview.mode === 'handoff' ? 'Richiesta operatore · 0 token AI' : 'Risposta AI' }}</span><span class="rounded-full border px-2 py-1">{{ preview.mode === 'accessory_offer' ? '1 prodotto trovato' : `${preview.matches} schermi trovati` }}</span></div><p class="whitespace-pre-wrap break-words rounded-xl rounded-tl-none bg-emerald-700/15 p-4 text-sm leading-relaxed">{{ preview.document?.caption ?? preview.text }}</p><a v-if="preview.document" :href="preview.document.download_url" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">{{ preview.document.download_label ?? (preview.document.caption.includes('Aquí tienes') ? 'Descargar presupuesto PDF' : preview.document.caption.includes('Here is') ? 'Download quote PDF' : 'Scarica preventivo PDF') }}</a><p v-if="['missing_submitted', 'technical_handoff'].includes(preview.mode)" class="mt-2 text-xs text-muted-foreground">Segnalazione inviata via email.</p></div></template>
                        <div v-if="chat.some(p => p.mode === 'photo_review')" class="mb-4 rounded-lg border p-3 text-sm">
                            <p class="mb-2 text-xs text-muted-foreground">La simulazione non invia la foto. Questo pulsante invia un’email reale all’amministratore con foto, auto e radio individuate.</p>
                            <button type="button" :disabled="busy || sendingPhotoEmail || photoEmailSent" class="rounded-lg border px-3 py-2 font-medium disabled:opacity-50" @click="sendPhotoEmail">{{ photoEmailSent ? 'Email di prova accettata dal server SMTP' : sendingPhotoEmail ? 'Invio in corso…' : 'Invia email di prova con foto' }}</button>
                            <p v-if="photoEmailNotice" role="alert" class="mt-2 text-red-500">{{ photoEmailNotice }}</p>
                        </div>
                        <div v-if="pendingQuestion" role="status" aria-live="polite"><p class="mb-3 whitespace-pre-wrap break-words rounded-xl bg-muted p-3 text-sm">{{ pendingQuestion }}</p><p class="flex items-center gap-2 rounded-xl bg-emerald-700/15 p-4 text-sm text-muted-foreground"><RefreshCw class="size-4 animate-spin" />L’assistente sta preparando la risposta…</p></div>
                        <p v-if="!chat.length && !pendingQuestion" class="py-10 text-center text-sm text-muted-foreground">La risposta apparirà qui.</p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</template>
