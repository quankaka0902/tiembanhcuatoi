import { Link, useForm } from '@inertiajs/react';
import { CalendarClock, MapPin, Phone, Truck } from 'lucide-react';
import { Admin } from './admin';
import { dateTime, Empty, ErrorList, Field, money, Pagination, Pill } from './ui';

const base = '/quan-tri/giao-nhan';
const uuid = () => globalThis.crypto.randomUUID();

function JobActions({ job }) {
    const form = useForm({ action: '', expected_version: job.version, request_key: uuid(), reason: '', reference: '', verified: false });
    const action = (job.actions ?? []).find(item => item.key === form.data.action);
    return (job.actions ?? []).length > 0 ? <form className="form-stack dispatch-actions" onSubmit={event => {
        event.preventDefault();
        form.patch(base + '/cong-viec/' + job.id, { preserveScroll: true, preserveState: 'errors' });
    }}>
        <Field label="Thao tác tiếp theo" error={form.errors.action}><select required value={form.data.action} onChange={event => form.setData(data => ({ ...data, action: event.target.value, verified: false }))}><option value="">Chọn thao tác</option>{job.actions.map(item => <option value={item.key} key={item.key}>{item.label}</option>)}</select></Field>
        {action?.requires_reason && <Field label="Lý do và hướng xử lý" error={form.errors.reason}><textarea required minLength={3} maxLength={250} rows="2" value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></Field>}
        {action?.requires_reference && <Field label="Bằng chứng hoặc tham chiếu" error={form.errors.reference}><input required maxLength={120} value={form.data.reference} onChange={event => form.setData('reference', event.target.value)} placeholder="Mã chứng từ, biên nhận hoặc căn cứ đã kiểm tra" /></Field>}
        {action?.requires_verified && <label className="checkbox"><input required type="checkbox" checked={form.data.verified} onChange={event => form.setData('verified', event.target.checked)} />Tôi đã kiểm tra việc bàn giao bánh thực tế.</label>}
        {['failed', 'cancel', 'retry'].includes(form.data.action) && <p className="warning">Thao tác này ghi lịch sử giao nhận. Giao chưa thành công không tự hoàn tất đơn, hoàn tiền hoặc nhập lại tồn bán.</p>}
        <ErrorList errors={form.errors} /><button className="button" disabled={form.processing || !action}>{form.processing ? 'Đang lưu…' : 'Xác nhận ' + (action?.label?.toLowerCase() || 'thao tác')}</button>
    </form> : <p className="muted">Hiện không có thao tác tiếp theo trong quyền của bạn.</p>;
}

function DispatchJob({ job, statuses, courierOnly, selected, onSelect }) {
    return <article className="panel form-stack dispatch-job">
        <div className="section-head"><div><span className="sf-eyebrow">{job.kind === 'pickup' ? 'Bàn giao tại tiệm' : 'Giao tận nơi'}</span><h2>{job.order_code}</h2></div><Pill value={job.status} labels={statuses} /></div>
        <p className="icon-text"><CalendarClock size={18} aria-hidden="true" />{dateTime(job.scheduled_at)}</p>
        {job.contact?.name && <p><strong>{job.contact.name}</strong></p>}
        {job.contact?.address && <p className="icon-text"><MapPin size={18} aria-hidden="true" />{job.contact.address}</p>}
        {job.contact?.phone && <a className="subtle-link" href={'tel:' + job.contact.phone.replace(/[^+\d]/g, '')}><Phone size={18} aria-hidden="true" />Gọi người nhận {job.contact.phone}</a>}
        {job.contact?.delivery_note && <p className="dispatch-note"><strong>Chỉ dẫn giao:</strong> {job.contact.delivery_note}</p>}
        {Number(job.cod_amount) > 0 && <p className="dispatch-cod">Cần thu khi nhận: <strong>{money(job.cod_amount)}</strong></p>}
        <p className="muted">{job.eta_label || 'Chưa có ước tính di chuyển'}{job.attempt_count != null && ' · Lượt xử lý ' + job.attempt_count}</p>
        {!courierOnly && <p className="muted">{job.trip_id ? 'Chuyến #' + job.trip_id : 'Chưa xếp chuyến'}{job.courier_name && ' · ' + job.courier_name}</p>}
        {!courierOnly && job.can_assign && <label className="checkbox"><input type="checkbox" checked={Boolean(selected)} onChange={event => onSelect(job.order_id, event.target.checked)} />Chọn đơn này để xếp chuyến</label>}
        <JobActions key={job.id + ':' + job.version} job={job} />
    </article>;
}

function LegacyJob({ order }) {
    const form = useForm({ request_key: uuid() });
    return <div className="dispatch-legacy"><div><strong>{order.code}</strong><small>{order.fulfillment === 'delivery' ? 'Giao tận nơi' : 'Nhận tại tiệm'} · {dateTime(order.scheduled_at)}</small></div>
        <button type="button" className="button secondary compact" disabled={form.processing} onClick={() => form.post(base + '/don/' + order.id + '/tao', { preserveScroll: true, preserveState: 'errors' })}>Tạo việc giao nhận</button><ErrorList errors={form.errors} />
    </div>;
}

export function AdminDispatch({ jobs, trips = [], drivers = [], statuses = {}, tripStatuses = { assigned: 'Đã phân chuyến', in_transit: 'Đang giao', completed: 'Kết thúc', cancelled: 'Đã hủy' }, filters = {}, courierOnly = false, canManage = false, eligibleOrders = [] }) {
    const filtersForm = useForm({ status: filters.status ?? '', kind: filters.kind ?? '' });
    const assign = useForm({ order_ids: [], courier_id: '', request_key: uuid() });
    const selected = new Set(assign.data.order_ids.map(Number));
    const choose = (orderId, checked) => assign.setData('order_ids', checked ? [...new Set([...assign.data.order_ids, Number(orderId)])] : assign.data.order_ids.filter(id => Number(id) !== Number(orderId)));
    return <Admin title={courierOnly ? 'Việc giao của tôi' : 'Điều phối giao nhận'} detail={courierOnly ? 'Chỉ hiển thị công việc được giao cho bạn. Xác nhận từng mốc sau khi thực hiện thực tế.' : 'Tách tiến độ giao nhận khỏi việc thu tiền và xử lý tồn.'}>
        <form className="panel admin-filters" onSubmit={event => { event.preventDefault(); assign.setData('order_ids', []); filtersForm.get(base, { preserveState: true }); }}>
            <Field label="Trạng thái"><select value={filtersForm.data.status} onChange={event => filtersForm.setData('status', event.target.value)}><option value="">Tất cả</option>{Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
            <Field label="Hình thức"><select value={filtersForm.data.kind} onChange={event => filtersForm.setData('kind', event.target.value)}><option value="">Tất cả</option><option value="delivery">Giao tận nơi</option><option value="pickup">Tại tiệm</option></select></Field>
            <button className="button secondary compact" disabled={filtersForm.processing}>Lọc công việc</button><ErrorList errors={filtersForm.errors} />
        </form>
        {canManage && <section className="panel form-stack"><h2>Xếp chuyến giao</h2><p>Chọn các đơn giao đủ điều kiện trong danh sách rồi chọn nhân sự giao hàng của cơ sở này.</p>
            <form className="dispatch-assignment" onSubmit={event => { event.preventDefault(); assign.post(base + '/chuyen', { preserveScroll: true, preserveState: 'errors', onSuccess: () => assign.setData({ order_ids: [], courier_id: '', request_key: uuid() }) }); }}>
                <Field label="Nhân sự giao hàng" error={assign.errors.courier_id}><select required value={assign.data.courier_id} onChange={event => assign.setData('courier_id', event.target.value)}><option value="">Chọn người được phân công</option>{drivers.map(driver => <option key={driver.id} value={driver.id}>{driver.name}</option>)}</select></Field>
                <p className="muted">{selected.size} đơn được chọn</p><button className="button" disabled={assign.processing || !selected.size || !assign.data.courier_id}><Truck size={17} aria-hidden="true" />{assign.processing ? 'Đang xếp chuyến…' : 'Xác nhận xếp chuyến'}</button><ErrorList errors={assign.errors} />
            </form>{drivers.length === 0 && <p className="warning">Chưa có nhân sự giao hàng hoạt động tại cơ sở. Quản trị chuỗi cần tạo tài khoản và phân công vai trò giao hàng.</p>}
        </section>}
        {canManage && eligibleOrders.length > 0 && <section className="panel form-stack"><h2>Đơn cũ cần tạo công việc</h2>{eligibleOrders.map(order => <LegacyJob key={order.id} order={order} />)}</section>}
        {trips.length > 0 && <details className="panel form-stack dispatch-trips"><summary>Các chuyến giao ({trips.length})</summary>{trips.map(trip => <article className="dispatch-trip" key={trip.id}><strong>{trip.code}</strong><Pill value={trip.status} labels={tripStatuses} /><p>{trip.order_codes?.join(' · ')}</p>{!courierOnly && <p className="muted">{trip.courier_name}</p>}</article>)}</details>}
        <section className="dispatch-grid" aria-label="Danh sách giao nhận">{jobs.data.length ? jobs.data.map(job => <DispatchJob key={job.id + ':' + job.version} job={job} statuses={statuses} courierOnly={courierOnly} selected={selected.has(Number(job.order_id))} onSelect={choose} />) : <Empty title="Chưa có công việc trong bộ lọc này" text={courierOnly ? 'Công việc được phân công sẽ xuất hiện ở đây.' : 'Khi đơn đủ điều kiện, tạo hoặc kiểm tra công việc giao nhận.'} />}</section><Pagination page={jobs} />
        {courierOnly && <p className="muted">Sau khi công việc kết thúc, liên hệ và địa chỉ người nhận được ẩn khỏi màn hình giao hàng. Cần hỗ trợ thêm hãy liên hệ quản lý.</p>}
        {!courierOnly && <Link className="subtle-link" href="/quan-tri/don-hang">Trở về danh sách đơn</Link>}
    </Admin>;
}
