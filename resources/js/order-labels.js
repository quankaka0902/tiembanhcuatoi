const transferEvents = {
    proposed: 'Tiệm đề xuất chuyển cơ sở',
    customer_approved: 'Khách đồng ý đề xuất chuyển cơ sở',
    customer_rejected: 'Khách từ chối đề xuất chuyển cơ sở',
    cancelled: 'Đã hủy đề xuất chuyển cơ sở',
    completed: 'Đã chuyển cơ sở xử lý đơn',
    expired: 'Đề xuất chuyển cơ sở đã hết hạn',
};

const fulfillmentEvents = {
    assigned: 'Đã phân công giao nhận', ready: 'Sẵn sàng bàn giao bánh',
    start: 'Đã xuất giao bánh', delivered: 'Đã bàn giao bánh cho người nhận',
    failed: 'Giao bánh chưa thành công', cancel: 'Đã hủy công việc giao nhận',
    retry: 'Đã tạo lại lượt giao nhận', collect: 'Khách đã nhận bánh tại tiệm',
    legacy_review: 'Nhân viên tiếp nhận công việc giao nhận cũ',
    branch_transfer: 'Đã cập nhật cơ sở giao nhận',
};

export function orderEventTitle(event, { statuses = {}, payments = {}, formatDateTime = value => value } = {}) {
    if (event.kind === 'transfer') return transferEvents[event.value] ?? 'Cập nhật đề xuất chuyển cơ sở';
    if (event.kind === 'fulfillment') return fulfillmentEvents[event.value] ?? 'Cập nhật công việc giao nhận';
    if (event.label) return event.label;
    if (event.kind === 'schedule') return 'Đổi giờ sang ' + formatDateTime(event.value);
    if (event.kind === 'payment') return payments[event.value] ?? 'Cập nhật thanh toán';
    if (event.kind === 'status') return statuses[event.value] ?? 'Cập nhật trạng thái đơn';
    if (event.kind === 'request') return { reschedule: 'Khách yêu cầu đổi giờ', cancel: 'Khách yêu cầu hủy đơn', approved: 'Tiệm chấp nhận yêu cầu', rejected: 'Tiệm không chấp nhận yêu cầu' }[event.value] ?? 'Yêu cầu thay đổi đơn';
    const labels = {
        request_created: 'Khách gửi yêu cầu', request_approved: 'Đã duyệt yêu cầu', request_rejected: 'Đã từ chối yêu cầu',
        prepay_exception: 'Quản lý duyệt ngoại lệ trả trước', lead_time: 'Xác nhận thời gian chuẩn bị',
        inventory: 'Kiểm tra bánh sau hủy', inventory_disposition: 'Đã phân loại bánh sau hủy', coupon: 'Xử lý lượt mã giảm giá',
    };
    return labels[event.kind] ?? 'Cập nhật đơn';
}

export function transferSummaryCopy(status) {
    const waiting = 'Đề xuất chỉ được thực hiện sau khi bạn đồng ý và tiệm kiểm tra lại tài nguyên. Đơn hiện tại được giữ đến khi chuyển thành công.';
    const labels = {
        pending: ['Chờ bạn xác nhận', waiting, true],
        approved: ['Bạn đã đồng ý, tiệm đang kiểm tra', waiting, false],
        completed: ['Đơn đã chuyển cơ sở', 'Tiệm đã chuyển đơn thành công. Xem cơ sở xử lý và thông tin nhận bánh hiện tại ở phía trên.', false],
        executed: ['Đơn đã chuyển cơ sở', 'Tiệm đã chuyển đơn thành công. Xem cơ sở xử lý và thông tin nhận bánh hiện tại ở phía trên.', false],
        rejected: ['Bạn đã từ chối đề xuất', 'Đề xuất này không được thực hiện. Xem thông tin đơn hiện tại ở phía trên.', false],
        cancelled: ['Tiệm đã hủy đề xuất', 'Đề xuất này không được thực hiện. Xem thông tin đơn hiện tại ở phía trên.', false],
        expired: ['Đề xuất đã hết hạn', 'Liên hệ cơ sở xử lý đơn nếu vẫn cần thay đổi. Xem thông tin đơn hiện tại ở phía trên.', false],
    };
    const [title, description, needsResponse] = labels[status] ?? ['Tiệm đang cập nhật đề xuất', 'Xem trạng thái đơn hiện tại hoặc liên hệ cơ sở xử lý để được hỗ trợ.', false];
    return { title, description, needsResponse };
}

export function fulfillmentReadyLabel(kind) {
    return kind === 'delivery' ? 'Sẵn sàng bàn giao lúc' : 'Sẵn sàng nhận lúc';
}
