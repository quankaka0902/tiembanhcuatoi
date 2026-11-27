import assert from 'node:assert/strict';
import test from 'node:test';
import { fulfillmentReadyLabel, orderEventTitle, transferSummaryCopy } from '../../resources/js/order-labels.js';

const context = { statuses: { completed: 'Hoàn tất đơn hàng', cancelled: 'Đã hủy đơn hàng' }, payments: { paid: 'Đã thanh toán', refunded: 'Đã hoàn tiền' } };

test('transfer completed is a transfer event even when the order itself remains pending', () => {
    assert.equal(orderEventTitle({ kind: 'transfer', value: 'completed' }, context), 'Đã chuyển cơ sở xử lý đơn');
    assert.equal(orderEventTitle({ kind: 'status', value: 'completed' }, context), 'Hoàn tất đơn hàng');
    assert.equal(orderEventTitle({ kind: 'transfer', value: 'cancelled' }, context), 'Đã hủy đề xuất chuyển cơ sở');
    for (const value of ['proposed', 'customer_approved', 'customer_rejected']) {
        assert.match(orderEventTitle({ kind: 'transfer', value }, context), /chuyển cơ sở/);
    }
});

test('fulfillment actions never inherit order completion or payment labels', () => {
    assert.equal(orderEventTitle({ kind: 'fulfillment', value: 'delivered' }, context), 'Đã bàn giao bánh cho người nhận');
    assert.equal(orderEventTitle({ kind: 'fulfillment', value: 'cancel' }, context), 'Đã hủy công việc giao nhận');
    assert.equal(orderEventTitle({ kind: 'fulfillment', value: 'retry' }, context), 'Đã tạo lại lượt giao nhận');
    assert.equal(orderEventTitle({ kind: 'fulfillment', value: 'completed' }, context), 'Cập nhật công việc giao nhận');
    assert.equal(orderEventTitle({ kind: 'payment', value: 'refunded' }, context), 'Đã hoàn tiền');
});

test('completed transfer summary closes the approval prompt and explains the completed move', () => {
    for (const status of ['completed', 'executed']) {
        const copy = transferSummaryCopy(status);
        assert.equal(copy.title, 'Đơn đã chuyển cơ sở');
        assert.equal(copy.needsResponse, false);
        assert.match(copy.description, /đã chuyển đơn thành công/);
        assert.ok(!copy.description.includes('sau khi bạn đồng ý'));
    }
    assert.equal(transferSummaryCopy('pending').needsResponse, true);
    assert.equal(transferSummaryCopy('approved').needsResponse, false);
});

test('ready delivery is described as handoff while pickup remains ready to collect', () => {
    assert.equal(fulfillmentReadyLabel('delivery'), 'Sẵn sàng bàn giao lúc');
    assert.equal(fulfillmentReadyLabel('pickup'), 'Sẵn sàng nhận lúc');
});
