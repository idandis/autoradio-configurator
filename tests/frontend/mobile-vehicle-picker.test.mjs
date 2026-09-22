import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import { ref, computed, nextTick } from 'vue';

// Exercise the component's actual setup against a browser-history simulation.
function setup() {
    const source = readFileSync(new URL('../../resources/js/components/MobileVehiclePicker.vue', import.meta.url), 'utf8')
        .split('<script setup lang="ts">')[1].split('</script>')[0].replace(/import .* from 'vue';/, '');
    const events = {};
    const emissions = [];
    const history = [{ inertia: 'preserved' }];
    let cursor = 0;
    const window = { innerWidth: 390, location: { href: 'http://localhost/configurator' }, addEventListener: (name, fn) => events[name] = fn, removeEventListener() {} };
    window.history = {
        get state() { return history[cursor]; },
        pushState(state) { history.splice(++cursor); history.push(state); },
        replaceState(state) { history[cursor] = state; },
        go(delta) { cursor += delta; events.popstate({ state: history[cursor], stopImmediatePropagation() {} }); },
        back() { this.go(-1); },
    };
    const props = {
        value: { brand: 'KIA', model: 'Picanto', year: 2015 },
        entries: [
            { brand: 'KIA', model: 'Picanto', yearFrom: 2014, yearTo: 2016 },
            { brand: 'KIA', model: 'Rio', yearFrom: 2018, yearTo: 2019 },
            { brand: 'Fiat', model: 'Panda', yearFrom: 2020, yearTo: 2021 },
        ],
        displayModel: value => value,
        missingLabels: ['brand', 'model', 'year'],
    };
    const context = vm.createContext({ ref, computed, nextTick, onMounted: fn => fn(), onBeforeUnmount() {}, defineExpose() {},
        defineProps: () => props, defineEmits: () => (...args) => emissions.push(args), window,
        document: { body: { style: { overflow: 'auto' } }, activeElement: { focus() {} } },
    });
    const js = ts.transpile(source + '\n globalThis.api = { open, choose, back, close, missing, keyboard, options, selected, step, draft, query, active, opened };', { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None });
    vm.runInContext(js, context);
    return { ...context.api, emissions, window, context };
}

test('brand → model → filtered year; Back preserves choices and completion restores history', async () => {
    const picker = setup();
    await picker.open();
    assert.equal(picker.window.history.state.inertia, 'preserved');
    picker.choose('KIA');
    await nextTick();
    assert.deepEqual(Array.from(picker.options.value, option => option.value), ['Picanto', 'Rio']);
    picker.choose('Picanto');
    await nextTick();
    assert.deepEqual(Array.from(picker.options.value, option => option.value), [2014, 2015, 2016]);
    picker.back();
    await nextTick();
    assert.equal(picker.selected.value, 'Picanto');
    picker.choose('Rio');
    await nextTick();
    assert.equal(picker.draft.value.year, null);
    assert.deepEqual(Array.from(picker.options.value, option => option.value), [2018, 2019]);
    picker.choose(2019);
    assert.equal(picker.opened.value, false);
    assert.equal(picker.emissions[0][0], 'complete');
    assert.equal(picker.emissions[0][1].year, 2019);
    assert.equal(picker.window.history.state.autoradioVehiclePicker, undefined);
    assert.equal(picker.context.document.body.style.overflow, 'auto');
});

test('completing the year does not navigate browser history', async () => {
    const picker = setup();
    await picker.open();
    picker.choose('KIA');
    picker.choose('Picanto');
    picker.window.history.go = () => assert.fail('Completion must not traverse browser history');
    picker.choose(2015);
    assert.equal(picker.opened.value, false);
    assert.equal(picker.emissions[0][0], 'complete');
    assert.equal(picker.window.history.state.autoradioVehiclePicker, undefined);
});

test('changing brand clears dependent fields; cancellation emits no configuration change', async () => {
    const picker = setup();
    await picker.open();
    picker.choose('Fiat');
    assert.equal(picker.draft.value.model, null);
    assert.equal(picker.draft.value.year, null);
    picker.back();
    picker.back();
    assert.equal(picker.opened.value, false);
    assert.equal(picker.emissions.length, 0);
});

test('search, keyboard selection and missing model preserve the chosen brand', async () => {
    const picker = setup();
    await picker.open();
    picker.query.value = 'ki';
    picker.active.value = 0;
    assert.equal(picker.options.value.length, 1);
    picker.keyboard({ key: 'Enter', preventDefault() {} });
    await nextTick();
    assert.equal(picker.step.value, 1);
    picker.window.history.go = () => assert.fail('Missing-vehicle actions must not traverse browser history');
    picker.missing();
    assert.equal(picker.emissions[0][0], 'missing');
    assert.equal(picker.emissions[0][1].brand, 'KIA');
    assert.equal(picker.emissions[0][1].model, null);
    assert.equal(picker.opened.value, false);
});

test('missing brand, model and year emit the fields already selected', async () => {
    const brandPicker = setup();
    await brandPicker.open();
    brandPicker.missing();
    assert.deepEqual(JSON.parse(JSON.stringify(brandPicker.emissions[0][1])), { brand: null, model: null, year: null });

    const modelPicker = setup();
    await modelPicker.open();
    modelPicker.choose('KIA');
    modelPicker.missing();
    assert.deepEqual(JSON.parse(JSON.stringify(modelPicker.emissions[0][1])), { brand: 'KIA', model: null, year: null });

    const yearPicker = setup();
    await yearPicker.open();
    yearPicker.choose('KIA');
    yearPicker.choose('Picanto');
    yearPicker.missing();
    assert.deepEqual(JSON.parse(JSON.stringify(yearPicker.emissions[0][1])), { brand: 'KIA', model: 'Picanto', year: null });
});


test('blue Back changes the step without browser navigation; phone Back also stays in the selector', async () => {
    const picker = setup();
    await picker.open();
    picker.choose('KIA');
    picker.choose('Picanto');
    const originalGo = picker.window.history.go;
    picker.window.history.go = () => assert.fail('Step navigation must not traverse browser history');
    picker.back();
    assert.equal(picker.step.value, 1);
    assert.equal(picker.selected.value, 'Picanto');
    picker.window.history.go = originalGo;
    picker.window.history.back();
    assert.equal(picker.step.value, 0);
    assert.equal(picker.selected.value, 'KIA');
    assert.equal(picker.opened.value, true);
    picker.window.history.back();
    assert.equal(picker.opened.value, false);
    assert.equal(picker.window.history.state.inertia, 'preserved');
});
