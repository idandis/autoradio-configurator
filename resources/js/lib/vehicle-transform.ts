export type Vehicle = { brand: string; model: string; year_from: number; year_to: number };
const key = (v: Vehicle) => `${v.brand.trim().toUpperCase()}|${v.model.trim().toUpperCase()}`;
const acronyms = new Set(['MINI', 'BMW', 'AMG', 'GT', 'GTI', 'GTD', 'GTE', 'GTS', 'GT-R', 'RS', 'ST', 'FR', 'FJ', 'TT', 'CC', 'RCZ', 'DS', 'IQ', 'ID', 'EV', 'HEV', 'PHEV', 'SUV', 'SL', 'SLK', 'SLR', 'SLC', 'CLS', 'GLC', 'GLE', 'GLA', 'GLB', 'GLK', 'XJ', 'XJL', 'CR-V', 'HR-V', 'C-HR']);
export function modelName(value: string, preserveUppercase = false): string {
    value = value.trim().replace(/\s+/g, ' ');
    const aliases = value.split(/\s*\/\s*/);
    if (new Set(aliases.map(v => v.replace(/[^a-z0-9]/gi, '').toLowerCase())).size === 1) value = aliases[0];
    if (value.replace(/[^a-z0-9]/gi, '').toLowerCase() === 'smax') return 'S-MAX';
    const upperWords = preserveUppercase ? new Set(value.match(/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/gu)?.filter(word => word.length > 1 && word === word.toUpperCase()) ?? []) : new Set<string>();
    value = value.toLowerCase(); value = value.charAt(0).toUpperCase() + value.slice(1);
    return value.replace(/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/gu, word => acronyms.has(word.toUpperCase()) || upperWords.has(word.toUpperCase()) || (/[a-z]/i.test(word) && /\d/.test(word)) ? word.toUpperCase() : word);
}
export function expandVehicles(rows: Vehicle[], preserveUppercase = false): Vehicle[] {
    const result = new Map<string, Vehicle>();
    for (const row of rows) {
        if (!row.brand.trim() && !row.model.trim()) continue;
        const brands = [...new Set(row.brand.split('|').map(v => v.trim().toUpperCase()).filter(Boolean))];
        if (!brands.length || !row.model.trim()) throw new Error('Inserisci marca e modello.');
        if (!Number.isInteger(Number(row.year_from)) || !Number.isInteger(Number(row.year_to)) || row.year_from < 1 || row.year_to > 65535 || row.year_to < row.year_from) throw new Error('Controlla gli anni della compatibilità.');
        const models = row.model.split('|').map(v => v.trim()).filter(Boolean);
        if (!models.length) throw new Error('Inserisci un modello valido.');
        for (const value of models) {
            const match = value.match(/^(\d+)\s*[:：]\s*(.+)$/);
            if (brands.length > 1 && !match) throw new Error('Con più marche usa 1:Modello | 2:Modello.');
            const brand = match ? brands[Number(match[1]) - 1] : brands[0];
            if (!brand) throw new Error('Il numero del modello non corrisponde a una marca.');
            const v = { brand, model: modelName(match ? match[2] : value, preserveUppercase), year_from: Number(row.year_from), year_to: Number(row.year_to) };
            result.set(`${key(v)}|${v.year_from}|${v.year_to}`, v);
        }
    }
    return [...result.values()];
}
export function vehicleRow(rows: Vehicle[], preserveUppercase = false): Vehicle {
    const entries = expandVehicles(rows, preserveUppercase);
    const brands = [...new Set(entries.map(v => v.brand))];
    return { brand: brands.join(' | '), model: [...new Set(entries.map(v => `${brands.length > 1 ? `${brands.indexOf(v.brand) + 1}:` : ''}${v.model}`))].join(' | '),
        year_from: entries.length ? Math.min(...entries.map(v => v.year_from)) : rows[0]?.year_from,
        year_to: entries.length ? Math.max(...entries.map(v => v.year_to)) : rows[0]?.year_to };
}
export function editVehicles(row: Vehicle, previous: Vehicle[]): Vehicle[] {
    const old = expandVehicles(previous, true);
    return expandVehicles(expandVehicles([row], true).flatMap(v => {
        const ranges = old.filter(p => key(p) === key(v));
        return ranges.length ? ranges : [v];
    }), true);
}
