const amount = value => {
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : null;
};

const priceFields = line => line ? {
    unitPrice: amount(line.unit_price), optionFee: amount(line.option_fee),
    accessoryFee: amount(line.accessory_fee), subtotal: amount(line.subtotal),
} : null;

export function compareQuoteLines(before = [], after = []) {
    const previous = new Map((Array.isArray(before) ? before : []).map((line, index) => [line.key != null ? String(line.key) : 'before-unknown-' + index, line]));
    const next = new Map((Array.isArray(after) ? after : []).map((line, index) => [line.key != null ? String(line.key) : 'after-unknown-' + index, line]));
    const keys = [...new Set([...previous.keys(), ...next.keys()])];
    return keys.map(key => {
        const oldLine = previous.get(key);
        const newLine = next.get(key);
        const quantity = amount(oldLine?.quantity ?? newLine?.quantity);
        const available = newLine?.available;
        return {
            key, productName: oldLine?.product_name || newLine?.product_name || 'Dòng bánh cần kiểm tra',
            variantName: oldLine?.variant_name || newLine?.variant_name || null,
            quantity: quantity > 0 ? quantity : null,
            before: priceFields(oldLine), after: priceFields(newLine),
            availability: available === true || available === 1 || available === '1' ? 'available'
                : available === false || available === 0 || available === '0' ? 'unavailable' : 'unknown',
        };
    });
}
