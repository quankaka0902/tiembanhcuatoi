import assert from 'node:assert/strict';
import test from 'node:test';
import { compareQuoteLines } from '../../resources/js/quote-lines.js';

test('quote rows pair by stable cart line key when the target list has a different order', () => {
    const rows = compareQuoteLines([
        { key: 'cake-a', product_name: 'Bánh A', variant_name: 'Cỡ vừa', quantity: 2, unit_price: 100000, option_fee: 10000, accessory_fee: 5000, subtotal: 230000 },
        { key: 'cake-b', product_name: 'Bánh B', variant_name: 'Cỡ nhỏ', quantity: 1, subtotal: 80000 },
    ], [
        { key: 'cake-b', product_name: 'Bánh B', quantity: 1, subtotal: 90000, available: false },
        { key: 'cake-a', product_name: 'Bánh A', quantity: 2, unit_price: 120000, option_fee: 20000, accessory_fee: 5000, subtotal: 290000, available: true },
    ]);
    assert.deepEqual(rows.map(row => row.key), ['cake-a', 'cake-b']);
    assert.equal(rows[0].quantity, 2);
    assert.equal(rows[0].variantName, 'Cỡ vừa');
    assert.equal(rows[0].before.subtotal, 230000);
    assert.equal(rows[0].after.subtotal, 290000);
    assert.equal(rows[0].after.optionFee, 20000);
    assert.equal(rows[0].availability, 'available');
    assert.equal(rows[1].before.subtotal, 80000);
    assert.equal(rows[1].after.subtotal, 90000);
    assert.equal(rows[1].availability, 'unavailable');
});

test('unknown target data stays unknown rather than inferring zero price or out-of-stock', () => {
    const rows = compareQuoteLines([
        { key: 'cake-a', product_name: 'Bánh A', quantity: 1, subtotal: 0, option_fee: 0 },
        { key: 'cake-b', product_name: 'Bánh B', quantity: 1, subtotal: 50000 },
    ], [{ key: 'cake-a', quantity: 1, available: false }]);
    assert.equal(rows[0].productName, 'Bánh A');
    assert.equal(rows[0].before.subtotal, 0);
    assert.equal(rows[0].after.subtotal, null);
    assert.equal(rows[0].after.optionFee, null);
    assert.equal(rows[0].availability, 'unavailable');
    assert.equal(rows[1].after, null);
    assert.equal(rows[1].availability, 'unknown');
    assert.deepEqual(compareQuoteLines(null, undefined), []);
});
