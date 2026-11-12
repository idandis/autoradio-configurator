const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const ts = require('typescript');
const vm = require('node:vm');
const source = ts.transpileModule(fs.readFileSync('resources/js/lib/vehicle-transform.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const mod = { exports: {} };
vm.runInNewContext(source, { exports: mod.exports });
const { expandVehicles, vehicleRow, editVehicles } = mod.exports;
const plain = v => JSON.parse(JSON.stringify(v));
const car = (brand, model, from = 2008, to = 2012) => ({ brand, model, year_from: from, year_to: to });

test('manual uppercase model names remain uppercase', () => {
    assert.equal(vehicleRow([car('JAGUAR', 'XJ | XJL')], true).model, 'XJ | XJL');
    assert.deepEqual(plain(editVehicles(car('JAGUAR', 'XJ | XJL | ABCDEF'), [])), [car('JAGUAR', 'XJ'), car('JAGUAR', 'XJL'), car('JAGUAR', 'ABCDEF')]);
});

test('one brand and multiple models remain one TRASFORMA row', () => {
    assert.deepEqual(plain(vehicleRow([car('alfa romeo', 'MITO'), car('ALFA ROMEO', 'GIULIETTA')])), car('ALFA ROMEO', 'Mito | Giulietta'));
});
test('multiple brands have correctly indexed models and no duplicates', () => {
    assert.deepEqual(plain(vehicleRow([car('fiat', 'ducato'), car('citroen', 'jumper'), car('FIAT', 'Ducato')])), car('FIAT | CITROEN', '1:Ducato | 2:Jumper'));
    assert.deepEqual(plain(expandVehicles([car('FIAT | CITROEN', '1:Ducato | 2:Jumper')])), [car('FIAT', 'Ducato'), car('CITROEN', 'Jumper')]);
});
test('unifying the visible row preserves disjoint and model-specific year ranges', () => {
    const old = [car('Ford', 'Fiesta', 2008, 2012), car('Ford', 'Fiesta', 2014, 2017), car('Ford', 'Focus', 2010, 2015)];
    const row = plain(vehicleRow(old));
    row.model += ' | Kuga';
    assert.deepEqual(plain(editVehicles(row, old)), [car('FORD', 'Fiesta', 2008, 2012), car('FORD', 'Fiesta', 2014, 2017), car('FORD', 'Focus', 2010, 2015), car('FORD', 'Kuga', 2008, 2017)]);
});
test('summary years never overwrite individual model ranges', () => {
    const old = [car('Ford', 'Fiesta'), car('Ford', 'Focus', 2010, 2015)];
    assert.deepEqual(plain(editVehicles({ ...vehicleRow(old), year_from: 2011, year_to: 2014 }, old)), [car('FORD', 'Fiesta'), car('FORD', 'Focus', 2010, 2015)]);
});
test('invalid brand indexes and years cannot be silently merged', () => {
    assert.throws(() => expandVehicles([car('FIAT | CITROEN', 'Ducato | Jumper')]));
    assert.throws(() => expandVehicles([car('FIAT | CITROEN', '3:Ducato')]));
    assert.throws(() => expandVehicles([car('FIAT', 'Panda', 2012, 2008)]));
    assert.throws(() => expandVehicles([car('FIAT', ' | ')]));
    assert.equal(vehicleRow([car('volvo', 'S60 | V70 | XC70')]).model, 'S60 | V70 | XC70');
});
