import assert from 'node:assert/strict';
import test from 'node:test';
import { orderLocation } from '../../resources/js/order-location.js';

test('receipt and support use order B snapshot while the customer browses branch A', () => {
    const page = {
        shop: { branch: { id: 1, name: 'Cơ sở A' }, address: 'Điểm nhận A', phone: '0900000001' },
        order: {
            branch_id: 2,
            branch_snapshot: { id: 2, name: 'Cơ sở B lúc đặt', address: 'Điểm nhận B lúc đặt', phone: '0900000002' },
            branch_contact: { id: 2, name: 'Cơ sở B hiện tại', phone: '0900000022', email: 'hotro-b@example.test' },
        },
    };
    const location = orderLocation(page.order);
    assert.equal(location.name, 'Cơ sở B lúc đặt');
    assert.equal(location.pickupText, 'Nhận tại tiệm · Điểm nhận B lúc đặt');
    assert.equal(location.phone, '0900000002');
    assert.equal(location.currentContact, false);
    assert.equal(location.email, 'hotro-b@example.test');
    assert.ok(!location.pickupText.includes(page.shop.address));
    assert.notEqual(location.phone, page.shop.phone);
});

test('unknown historical pickup address stays unverified and support falls back to current contact of order B', () => {
    const order = {
        branch_id: 2,
        branch_snapshot: { id: 2, historical_details_known: false, address: 'Must not become historical evidence', phone: '000' },
        branch_contact: { id: 2, name: 'Cơ sở B hiện tại', phone: '0900000022', email: 'hotro-b@example.test', address: 'Địa chỉ B hiện tại' },
    };
    const location = orderLocation(order);
    assert.equal(location.name, 'Cơ sở B hiện tại');
    assert.equal(location.address, null);
    assert.match(location.pickupText, /Địa chỉ tại lúc đặt chưa được xác minh/);
    assert.ok(!location.pickupText.includes(order.branch_contact.address));
    assert.ok(!location.pickupText.includes(order.branch_snapshot.address));
    assert.equal(location.phone, order.branch_contact.phone);
    assert.equal(location.currentContact, true);
    assert.equal(location.contactName, order.branch_contact.name);
});

test('missing order contact gives an explicit unknown location instead of a selected-store fallback', () => {
    const location = orderLocation({ branch_id: 2 });
    assert.equal(location.name, 'Cơ sở #2');
    assert.equal(location.phone, null);
    assert.equal(location.email, null);
    assert.equal(location.address, null);
    assert.match(location.pickupText, /chưa được xác minh/);
});
