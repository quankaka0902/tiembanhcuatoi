// Receipt location never comes from the customer's currently selected storefront.
export function orderLocation(order) {
    const snapshot = order.branch_snapshot ?? {};
    const current = order.branch_contact ?? {};
    const historicalDetailsKnown = snapshot.historical_details_known !== false;
    const text = value => typeof value === 'string' && value.trim() ? value.trim() : null;
    const historicalName = historicalDetailsKnown ? text(snapshot.name) : null;
    const address = historicalDetailsKnown ? text(snapshot.address) : null;
    const historicalPhone = historicalDetailsKnown ? text(snapshot.phone) : null;
    const name = historicalName || text(current.name) || 'Cơ sở #' + (order.branch_id ?? snapshot.id ?? 1);

    return {
        name,
        address,
        phone: historicalPhone || text(current.phone),
        email: text(current.email),
        currentContact: !historicalPhone,
        contactName: historicalPhone ? name : text(current.name) || name,
        pickupText: address ? 'Nhận tại tiệm · ' + address : 'Nhận tại tiệm · Địa chỉ tại lúc đặt chưa được xác minh. Vui lòng liên hệ cơ sở xử lý đơn để xác nhận.',
    };
}
