<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type PostalRange = { id: number; from: string; to: string };
type InstallationService = { id: number; name: string; price: number };
type Zone = { id: number; name: string; installer_address: string | null; installer_phone: string | null; postal_ranges: PostalRange[]; services: InstallationService[] };

const props = defineProps<{ zones: Zone[] }>();

const selectedZoneId = ref<number | null>(null);
const selectedBranch = ref<'postal' | 'services' | null>(null);
const creatingZone = ref(false);
const editingZoneId = ref<number | null>(null);
const editingPostalId = ref<number | null>(null);
const editingServiceId = ref<number | null>(null);
const showingPostalForm = ref(false);
const showingServiceForm = ref(false);

const selectedZone = computed(() => props.zones.find((zone) => zone.id === selectedZoneId.value) ?? null);

const zoneForm = useForm({ name: '', installer_address: '', installer_phone: '' });
const postalForm = useForm({ from: '', to: '' });
const serviceForm = useForm({ name: '', price: '' as string | number });

const closeDescendants = () => {
    selectedBranch.value = null;
    cancelPostal();
    cancelService();
};

const toggleZone = (zone: Zone) => {
    if (selectedZoneId.value === zone.id) {
        selectedZoneId.value = null;
        closeDescendants();
        return;
    }

    selectedZoneId.value = zone.id;
    editingZoneId.value = null;
    creatingZone.value = false;
    zoneForm.reset();
    closeDescendants();
};

const toggleBranch = (branch: 'postal' | 'services') => {
    selectedBranch.value = selectedBranch.value === branch ? null : branch;
    cancelPostal();
    cancelService();
};

const startCreateZone = () => {
    creatingZone.value = true;
    editingZoneId.value = null;
    zoneForm.reset();
    zoneForm.clearErrors();
};

const startRenameZone = (zone: Zone) => {
    editingZoneId.value = zone.id;
    creatingZone.value = false;
    zoneForm.name = zone.name;
    zoneForm.installer_address = zone.installer_address ?? '';
    zoneForm.installer_phone = zone.installer_phone ?? '';
    zoneForm.clearErrors();
};

const cancelZoneForm = () => {
    creatingZone.value = false;
    editingZoneId.value = null;
    zoneForm.reset();
    zoneForm.clearErrors();
};

const saveZone = () => {
    if (editingZoneId.value) {
        zoneForm.put(`/installation-zones/${editingZoneId.value}`, { preserveScroll: true, onSuccess: cancelZoneForm });
        return;
    }

    zoneForm.post('/installation-zones', { preserveScroll: true, onSuccess: cancelZoneForm });
};

const deleteZone = (zone: Zone) => {
    if (!window.confirm(`¿Eliminar la zona “${zone.name}” y todos sus códigos postales e instalaciones?`)) return;

    router.delete(`/installation-zones/${zone.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (selectedZoneId.value === zone.id) selectedZoneId.value = null;
            closeDescendants();
        },
    });
};

function cancelPostal() {
    showingPostalForm.value = false;
    editingPostalId.value = null;
    postalForm.reset();
    postalForm.clearErrors();
}

const addPostal = () => {
    cancelPostal();
    showingPostalForm.value = true;
};

const editPostal = (range: PostalRange) => {
    showingPostalForm.value = true;
    editingPostalId.value = range.id;
    postalForm.from = range.from;
    postalForm.to = range.from === range.to ? '' : range.to;
};

const savePostal = () => {
    if (!selectedZone.value) return;
    const base = `/installation-zones/${selectedZone.value.id}/postal-codes`;
    const options = { preserveScroll: true, onSuccess: cancelPostal };
    editingPostalId.value ? postalForm.put(`${base}/${editingPostalId.value}`, options) : postalForm.post(base, options);
};

const deletePostal = (range: PostalRange) => {
    if (!selectedZone.value || !window.confirm(`¿Eliminar ${range.from === range.to ? range.from : `${range.from}–${range.to}`}?`)) return;
    router.delete(`/installation-zones/${selectedZone.value.id}/postal-codes/${range.id}`, { preserveScroll: true });
};

function cancelService() {
    showingServiceForm.value = false;
    editingServiceId.value = null;
    serviceForm.reset();
    serviceForm.clearErrors();
}

const addService = () => {
    cancelService();
    showingServiceForm.value = true;
};

const editService = (service: InstallationService) => {
    showingServiceForm.value = true;
    editingServiceId.value = service.id;
    serviceForm.name = service.name;
    serviceForm.price = service.price.toFixed(2);
};

const saveService = () => {
    if (!selectedZone.value) return;
    const base = `/installation-zones/${selectedZone.value.id}/services`;
    const options = { preserveScroll: true, onSuccess: cancelService };
    editingServiceId.value ? serviceForm.put(`${base}/${editingServiceId.value}`, options) : serviceForm.post(base, options);
};

const deleteService = (service: InstallationService) => {
    if (!selectedZone.value || !window.confirm(`¿Eliminar la instalación “${service.name}”?`)) return;
    router.delete(`/installation-zones/${selectedZone.value.id}/services/${service.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Zonas de instalación" />

    <section class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card">
        <div class="border-b border-sidebar-border/70 p-5">
            <h1 class="text-xl font-semibold">Zonas de instalación</h1>
            <p class="mt-1 text-sm text-muted-foreground">Gestiona zonas, códigos postales e instalaciones independientes de los productos importados.</p>
        </div>

        <div class="overflow-x-auto p-5">
            <div class="flex min-h-[540px] w-max items-start gap-4">
                <div class="tree-column">
                    <h2 class="tree-title">ZONAS</h2>
                    <div class="mt-3 grid gap-2">
                        <div v-for="zone in props.zones" :key="zone.id" class="group flex items-center gap-1">
                            <button type="button" class="tree-item min-w-0 flex-1" :class="selectedZoneId === zone.id ? 'tree-item-selected' : ''" @click="toggleZone(zone)">
                                <span class="truncate">{{ zone.name }}</span><span v-if="selectedZoneId === zone.id">→</span>
                            </button>
                            <button type="button" class="tree-icon-button" aria-label="Editar zona" @click="startRenameZone(zone)">✎</button>
                            <button type="button" class="tree-icon-button tree-delete" aria-label="Eliminar zona" @click="deleteZone(zone)">×</button>
                        </div>
                        <p v-if="props.zones.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">No hay zonas configuradas.</p>
                    </div>

                    <form v-if="creatingZone || editingZoneId" class="tree-form mt-3" @submit.prevent="saveZone">
                        <label class="tree-label">Nombre</label>
                        <input v-model="zoneForm.name" autofocus class="tree-input" />
                        <label class="tree-label">Dirección del instalador</label>
                        <textarea v-model="zoneForm.installer_address" class="tree-input min-h-20 resize-y" placeholder="Calle, número, localidad" />
                        <label class="tree-label">Teléfono del instalador</label>
                        <input v-model="zoneForm.installer_phone" type="tel" class="tree-input" placeholder="+34 600 000 000" />
                        <p v-if="zoneForm.errors.name" class="tree-error">{{ zoneForm.errors.name }}</p>
                        <p v-if="zoneForm.errors.installer_address" class="tree-error">{{ zoneForm.errors.installer_address }}</p>
                        <p v-if="zoneForm.errors.installer_phone" class="tree-error">{{ zoneForm.errors.installer_phone }}</p>
                        <div class="tree-actions"><button class="tree-save" type="submit">Guardar</button><button class="tree-cancel" type="button" @click="cancelZoneForm">Cancelar</button></div>
                    </form>
                    <button v-else type="button" class="tree-add" @click="startCreateZone">+ Nueva zona</button>
                </div>

                <div v-if="selectedZone" class="tree-arrow">→</div>

                <div v-if="selectedZone" class="tree-column">
                    <h2 class="tree-title">{{ selectedZone.name.toUpperCase() }}</h2>
                    <div class="mt-3 grid gap-2">
                        <button type="button" class="tree-item" :class="selectedBranch === 'postal' ? 'tree-item-selected' : ''" @click="toggleBranch('postal')"><span>Códigos postales</span><span v-if="selectedBranch === 'postal'">→</span></button>
                        <button type="button" class="tree-item" :class="selectedBranch === 'services' ? 'tree-item-selected' : ''" @click="toggleBranch('services')"><span>Títulos instalaciones</span><span v-if="selectedBranch === 'services'">→</span></button>
                    </div>
                </div>

                <div v-if="selectedZone && selectedBranch" class="tree-arrow">→</div>

                <div v-if="selectedZone && selectedBranch === 'postal'" class="tree-column tree-column-wide">
                    <h2 class="tree-title">CÓDIGOS POSTALES</h2>
                    <div class="mt-3 grid gap-2">
                        <div v-for="range in selectedZone.postal_ranges" :key="range.id" class="tree-record">
                            <span class="font-mono">{{ range.from === range.to ? range.from : `${range.from} – ${range.to}` }}</span>
                            <span class="flex gap-1"><button class="tree-icon-button" type="button" @click="editPostal(range)">✎</button><button class="tree-icon-button tree-delete" type="button" @click="deletePostal(range)">×</button></span>
                        </div>
                        <p v-if="selectedZone.postal_ranges.length === 0" class="tree-empty">Ningún código postal.</p>
                    </div>
                    <form v-if="showingPostalForm" class="tree-form mt-3" @submit.prevent="savePostal">
                        <label class="tree-label">CAP inicial / único</label><input v-model="postalForm.from" inputmode="numeric" maxlength="5" class="tree-input font-mono" placeholder="28001" />
                        <label class="tree-label">CAP final (opcional)</label><input v-model="postalForm.to" inputmode="numeric" maxlength="5" class="tree-input font-mono" placeholder="28020" />
                        <p v-if="postalForm.errors.from || postalForm.errors.to" class="tree-error">{{ postalForm.errors.from || postalForm.errors.to }}</p>
                        <div class="tree-actions"><button class="tree-save" type="submit">Guardar</button><button class="tree-cancel" type="button" @click="cancelPostal">Cancelar</button></div>
                    </form>
                    <button v-else type="button" class="tree-add" @click="addPostal">+ Añadir</button>
                </div>

                <div v-if="selectedZone && selectedBranch === 'services'" class="tree-column tree-column-service">
                    <h2 class="tree-title">TÍTULOS INSTALACIONES</h2>
                    <div class="mt-3 grid gap-2">
                        <div v-for="service in selectedZone.services" :key="service.id" class="tree-record">
                            <span class="min-w-0 flex-1 truncate">{{ service.name }}</span><strong class="shrink-0">{{ service.price.toFixed(2) }} €</strong>
                            <span class="flex gap-1"><button class="tree-icon-button" type="button" @click="editService(service)">✎</button><button class="tree-icon-button tree-delete" type="button" @click="deleteService(service)">×</button></span>
                        </div>
                        <p v-if="selectedZone.services.length === 0" class="tree-empty">Ninguna instalación.</p>
                    </div>
                    <form v-if="showingServiceForm" class="tree-form mt-3" @submit.prevent="saveService">
                        <label class="tree-label">Nombre</label><input v-model="serviceForm.name" class="tree-input" placeholder="Pantalla + cámara trasera" />
                        <label class="tree-label">Precio</label><div class="relative"><input v-model="serviceForm.price" type="number" min="0" step="0.01" class="tree-input pr-8" /><span class="pointer-events-none absolute right-3 top-2.5 text-muted-foreground">€</span></div>
                        <p v-if="serviceForm.errors.name || serviceForm.errors.price" class="tree-error">{{ serviceForm.errors.name || serviceForm.errors.price }}</p>
                        <div class="tree-actions"><button class="tree-save" type="submit">Guardar</button><button class="tree-cancel" type="button" @click="cancelService">Cancelar</button></div>
                    </form>
                    <button v-else type="button" class="tree-add" @click="addService">+ Añadir instalación</button>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.tree-column { width: 17rem; flex: none; border: 1px solid var(--sidebar-border); border-radius: .75rem; background: var(--background); padding: 1rem; }
.tree-column-wide { width: 21rem; }
.tree-column-service { width: 31rem; }
.tree-title { font-size: .75rem; font-weight: 700; letter-spacing: .16em; color: #d8ae2d; }
.tree-arrow { align-self: flex-start; padding-top: 2.75rem; color: #d8ae2d; font-size: 1.35rem; font-weight: 700; }
.tree-item { display: flex; min-height: 2.75rem; align-items: center; justify-content: space-between; gap: .75rem; border: 1px solid var(--sidebar-border); border-radius: .5rem; padding: .65rem .8rem; text-align: left; transition: .15s; }
.tree-item:hover { border-color: rgba(216,174,45,.7); background: rgba(216,174,45,.07); }
.tree-item-selected { border-color: #d8ae2d; background: rgba(216,174,45,.14); color: #d8ae2d; }
.tree-icon-button { display: inline-flex; height: 2rem; width: 2rem; flex: none; align-items: center; justify-content: center; border: 1px solid var(--sidebar-border); border-radius: .4rem; color: var(--muted-foreground); }
.tree-icon-button:hover { border-color: #d8ae2d; color: #d8ae2d; }
.tree-delete:hover { border-color: #ef4444; color: #ef4444; }
.tree-add { margin-top: .75rem; width: 100%; border: 1px dashed rgba(216,174,45,.75); border-radius: .5rem; padding: .7rem; color: #d8ae2d; font-size: .875rem; font-weight: 600; }
.tree-add:hover { background: rgba(216,174,45,.1); }
.tree-record { display: flex; min-height: 2.75rem; align-items: center; gap: .65rem; border-bottom: 1px solid var(--sidebar-border); padding: .55rem .25rem; font-size: .875rem; }
.tree-record > :first-child { flex: 1; }
.tree-empty { border: 1px dashed var(--sidebar-border); border-radius: .5rem; padding: 1rem; text-align: center; font-size: .8rem; color: var(--muted-foreground); }
.tree-form { display: grid; gap: .55rem; border: 1px solid rgba(216,174,45,.4); border-radius: .6rem; background: rgba(216,174,45,.05); padding: .8rem; }
.tree-label { font-size: .72rem; font-weight: 600; color: var(--muted-foreground); }
.tree-input { width: 100%; min-width: 0; border: 1px solid var(--sidebar-border); border-radius: .45rem; background: var(--background); padding: .6rem .7rem; font-size: .875rem; }
.tree-input:focus { border-color: #d8ae2d; outline: none; box-shadow: 0 0 0 2px rgba(216,174,45,.15); }
.tree-actions { display: flex; gap: .5rem; margin-top: .25rem; }
.tree-save, .tree-cancel { border-radius: .45rem; padding: .55rem .8rem; font-size: .8rem; font-weight: 600; }
.tree-save { background: #d8ae2d; color: #000; }
.tree-cancel { border: 1px solid var(--sidebar-border); }
.tree-error { font-size: .75rem; color: #ef4444; }
</style>
