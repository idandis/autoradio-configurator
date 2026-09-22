import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const component = readFileSync(
    new URL('../../resources/js/components/ConfiguratorProductCard.vue', import.meta.url),
    'utf8',
);
const configurator = readFileSync(
    new URL('../../resources/js/pages/Configurator.vue', import.meta.url),
    'utf8',
);

test('shared product card preserves the requested content order', () => {
    const details = component.indexOf('class="product-details-button"');
    const image = component.indexOf('class="product-image-button"');
    const title = component.indexOf('class="product-card-title"');
    const price = component.indexOf('class="product-card-price"');
    const primary = component.indexOf('class="product-primary-button"');

    assert.ok(details > 0);
    assert.ok(details < image && image < title && title < price && price < primary);
});

test('titles clamp only after three lines and actions meet touch/accessibility requirements', () => {
    assert.match(component, /-webkit-line-clamp:\s*3/);
    assert.doesNotMatch(component.match(/\.product-card-title\s*\{[^}]+\}/s)?.[0] ?? '', /white-space:\s*nowrap/);
    assert.match(component.match(/\.product-details-button\s*\{[^}]+\}/s)?.[0] ?? '', /min-height:\s*44px/);
    assert.match(component.match(/\.product-details-button\s*\{[^}]+\}/s)?.[0] ?? '', /margin-inline:\s*auto/);
    assert.match(component, /:aria-label="detailsAriaLabel"/);
    assert.equal((component.match(/:aria-label="detailsAriaLabel"/g) ?? []).length, 2);
    assert.doesNotMatch(component, /target="_blank"/);
    assert.equal((component.match(/@click="\$emit\('details'\)"/g) ?? []).length, 2);
});

test('camera and speaker cards use the shared component and pantalla has non-overlaid details', () => {
    assert.match(configurator, /v-for="camera in visibleCameraOptions"[\s\S]*?<\/ConfiguratorProductCard>/);
    assert.match(configurator, /v-for="speaker in visibleSpeakerOptions"[\s\S]*?<\/ConfiguratorProductCard>/);
    assert.match(configurator, /:aria-label="productDetailsAriaLabel\(vehicle\.title\)"/);
    assert.doesNotMatch(configurator, /absolute left-1\/2 top-3[^\n]+product_details/);
    assert.doesNotMatch(configurator, /screenProductUrl\(vehicle\)[\s\S]{0,150}target="_blank"/);
    assert.match(configurator, /v-if="selectedProductView"/);
    assert.match(configurator, /scroll-snap-type:\s*x mandatory/);
    const detailsView = configurator.split('<main v-if="selectedProductView"')[1]
        .split('<div v-else class="min-h-screen w-full max-w-full')[0];
    assert.doesNotMatch(detailsView, /selectedProductView\.details\.variants/);
    assert.doesNotMatch(detailsView, /selectedDetailsPrice|addSelectedProductFromDetails/);
    assert.match(detailsView, /fixed inset-x-0 bottom-0/);
    assert.match(detailsView, /bg-\[#334fb4\]/);
});
