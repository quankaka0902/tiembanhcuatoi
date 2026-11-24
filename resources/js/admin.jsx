import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, Banknote, CakeSlice, CalendarDays, ChartNoAxesCombined, ClipboardList, Download, LayoutDashboard, Package, Plus, Settings, Ticket, Users } from 'lucide-react';
import { Brand, ButtonLink, date as formatDate, dateTime, ErrorList, Field, Image, money, Pagination, Pill } from './ui';
import { ChangeRequestHistory, OrderEvents, OrderItems, Totals } from './checkout';

const root = '/quan-tri';
const scheduleUrl = root + '/lich-lam-banh';
const reconciliationUrl = root + '/doi-soat';
const reportsUrl = root + '/bao-cao';
const inventoryUrl = root + '/ton-kho';
const inboxUrl = root + '/viec-can-xu-ly';
const mailUrl = root + '/email-don';
const nav = [
    [root, 'Tổng quan', LayoutDashboard], [root + '/don-hang', 'Đơn hàng', ClipboardList],
    [inboxUrl, 'Đơn cần xử lý', Package],
    [scheduleUrl, 'Lịch làm bánh', CalendarDays],
    [reconciliationUrl, 'Đối soát', Banknote],
    [reportsUrl, 'Báo cáo', ChartNoAxesCombined], [inventoryUrl, 'Nhật ký tồn kho', Package, true],
    [root + '/san-pham', 'Sản phẩm', CakeSlice, true], [root + '/ma-giam', 'Mã giảm giá', Ticket, true],
    [root + '/cai-dat', 'Cấu hình', Settings, true], [root + '/nhan-su', 'Nhân sự', Users, true],
];

function Admin({ title, detail, action, children }) {
    const { auth, notice, shop } = usePage().props;
    const path = usePage().url.split('?')[0];
    return <div className="admin-shell">
        <Head title={title + ' | Quản trị'} />
        <aside className="admin-sidebar">
            <Brand shop={shop} />
            <nav aria-label="Quản trị cửa hàng">
                {nav.filter(item => !item[3] || auth.role === 'admin').map(([url, label, Icon]) => {
                    const selected = path === url || (url !== root && path.startsWith(url + '/'));
                    return <Link key={url} className={selected ? 'selected' : ''} href={url} aria-current={selected ? 'page' : undefined}><Icon size={18} />{label}</Link>;
                })}
            </nav>
            <div className="admin-account"><strong>{auth.name}</strong><small>{auth.role === 'admin' ? 'Quản trị viên' : 'Nhân viên'}</small><Link as="button" method="post" href="/dang-xuat" className="text-action">Đăng xuất</Link></div>
        </aside>
        <main className="admin-main">
            <div className="admin-top"><span>Quản trị cửa hàng</span><Link className="subtle-link" href="/">Xem cửa hàng <ArrowUpRight size={16} /></Link></div>
            {notice && <p className="toast" role="status">{notice}</p>}
            <div className="section-head admin-heading"><div><h1>{title}</h1>{detail && <p>{detail}</p>}</div>{action}</div>
            {children}
        </main>
    </div>;
}

function Table({ headers, rows, empty = 'Chưa có dữ liệu.' }) {
    if (!rows.length) return <p className="inline-empty">{empty}</p>;
    return <div className="table-scroll" tabIndex="0" role="region" aria-label="Bảng dữ liệu"><table><thead><tr>{headers.map(header => <th key={header} scope="col">{header}</th>)}</tr></thead>
        <tbody>{rows.map(([key, ...cells]) => <tr key={key}>{cells.map((value, i) => <td key={headers[i]}>{value}</td>)}</tr>)}</tbody>
    </table></div>;
}

function OrderQueue({ headers, rows, empty }) {
    if (!rows.length) return <p className="inline-empty">{empty}</p>;
    return <>
        <div className="order-queue-table"><Table headers={headers} rows={rows} /></div>
        <ul className="order-queue-cards">{rows.map(([id, orderLink, amount, ...details]) => <li key={id}>
            <div className="queue-card-heading">{orderLink}<strong>{amount}</strong></div>
            <dl>{details.map((value, index) => <div key={headers[index + 2]}><dt>{headers[index + 2]}</dt><dd>{value}</dd></div>)}</dl>
        </li>)}</ul>
    </>;
}

function DateFilters({ filters, url, statuses }) {
    const form = useForm({});
    return <form className="panel admin-filters" onSubmit={e => {
        e.preventDefault();
        const values = new FormData(e.currentTarget);
        form.transform(() => Object.fromEntries([...values].filter(([, value]) => value !== '')));
        form.get(url, { preserveState: true });
    }}>
        {statuses && <><Field label="Mã đơn"><input name="code" defaultValue={filters.code ?? ''} placeholder="NB-" /></Field><Field label="Trạng thái"><select name="status" defaultValue={filters.status ?? ''}><option value="">Tất cả</option>{Object.entries(statuses).map(([key, label]) => <option value={key} key={key}>{label}</option>)}</select></Field></>}
        <Field label="Từ ngày"><input type="date" name="from" defaultValue={filters.from ?? ''} /></Field>
        <Field label="Đến ngày"><input type="date" name="to" defaultValue={filters.to ?? ''} /></Field>
        <button className="button compact" disabled={form.processing}>{form.processing ? 'Đang lọc…' : 'Lọc'}</button><ErrorList errors={form.errors} />
    </form>;
}

export function AdminDashboard({ filters, total, revenue, working, low, lowCount, recent, statuses }) {
    return <Admin title="Tổng quan" detail="Theo dõi đơn bánh, doanh thu và tồn kho."
        action={<ButtonLink href={inboxUrl}>Đơn cần xử lý</ButtonLink>}>
        <DateFilters filters={filters} url={root} />
        <div className="stats">
            {[[ClipboardList, 'Số đơn', total], [Package, 'Đang xử lý', working], [Ticket, 'Doanh thu đã thanh toán', money(revenue)], [CakeSlice, 'Phiên bản sắp hết', lowCount]].map(([Icon, label, value]) =>
                <div className="panel stat" key={label}><span className="service-icon"><Icon size={21} /></span><span>{label}</span><strong>{value}</strong></div>)}
        </div>
        <div className="admin-columns"><section className="panel"><div className="section-head"><h2>Đơn gần đây</h2><Link className="subtle-link" href={root + '/don-hang'}>Xem tất cả</Link></div>
            <Table headers={['Mã đơn', 'Khách hàng', 'Trạng thái', 'Tổng']} rows={recent.map(order => [order.id, <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>, order.name, <Pill value={order.status} labels={statuses} />, money(order.total)])} />
        </section><section className="panel"><h2>Tồn kho thấp</h2><Table headers={['Bánh', 'Phiên bản', 'Còn']} rows={low.map((item, index) => [index, item.product_name, item.name, item.stock])} /></section></div>
    </Admin>;
}

function IntakeControl({ intake }) {
    const form = useForm({ ...intake });
    return <section className="panel form-stack">
        <div className="section-head"><h2>Nhận đơn mới</h2><span className="muted">{intake.accepting_orders ? 'Đang nhận đơn' : 'Đang tạm ngừng'}</span></div>
        <form className="form-stack" onSubmit={event => {
            event.preventDefault();
            form.post(root + '/nhan-don', { preserveScroll: true });
        }}>
            <Flag form={form} field="accepting_orders" label="Cho phép khách đặt đơn mới" />
            <Text form={form} field="pause_message" label="Thông báo cho khách khi tạm ngừng" maxLength={250} />
            <ErrorList errors={form.errors} />
            <button className="button secondary compact" disabled={form.processing || !form.isDirty}>Lưu trạng thái nhận đơn</button>
        </form>
    </section>;
}

export function AdminInbox({ filter, newCount, overdueCount, refundCount, requestCount = 0, exceptionCount = 0, inventoryCount = 0, orders, payments, statuses, intake }) {
    const { auth } = usePage().props;
    const isAdmin = auth.role === 'admin';
    const tabs = [
        ['new', 'Chưa xác nhận', newCount, 'Giờ đặt', 'created_at', 'Hiện không có đơn chờ xác nhận.'],
        ['overdue', 'Chuyển khoản quá hạn', overdueCount, 'Hạn chuyển khoản', 'bank_due_at', 'Hiện không có chuyển khoản quá hạn.'],
        ['refund', 'Chờ hoàn tiền', refundCount, 'Giờ hủy', 'updated_at', 'Hiện không có đơn chờ hoàn tiền.'],
        ['requests', 'Yêu cầu của khách', requestCount, 'Cập nhật lúc', 'updated_at', 'Hiện không có yêu cầu chờ xử lý.'],
        ['exception', 'Chưa giao / chưa nhận', exceptionCount, 'Cập nhật lúc', 'updated_at', 'Hiện không có đơn giao hoặc nhận cần xử lý lại.'],
        ['inventory', 'Bánh sau hủy', inventoryCount, 'Giờ hủy', 'updated_at', 'Hiện không có bánh sau hủy chờ quản lý kiểm tra.'],
    ];
    const [, , , dateLabel, dateField, empty] = tabs.find(([key]) => key === filter) ?? tabs[0];
    return <Admin title="Đơn cần xử lý"
        action={isAdmin && <ButtonLink href={mailUrl} secondary>Email đơn hàng</ButtonLink>}>
        {!intake.accepting_orders && <p className="warning" role="status">Tiệm đang tạm ngừng nhận đơn mới.</p>}
        <section className="panel form-stack">
            <nav className="row-actions" aria-label="Loại đơn cần xử lý">
                {tabs.map(([key, label, count]) => <Link key={key} className={'button compact' + (key === filter ? '' : ' secondary')}
                    aria-current={key === filter ? 'page' : undefined} href={inboxUrl + (key === 'new' ? '' : '?filter=' + key)}>{label} ({count})</Link>)}
            </nav>
            <OrderQueue headers={['Mã đơn', 'Tổng tiền', 'Thanh toán', 'Giờ giao hoặc nhận', dateLabel]}
                empty={empty}
                rows={orders.data.map(order => [order.id,
                    <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>,
                    money(order.total),
                    <>{order.method === 'bank' ? 'Chuyển khoản' : 'COD'}<small><Pill value={order.payment} labels={payments} /></small>{filter === 'exception' && statuses && <small><Pill value={order.status} labels={statuses} /></small>}</>,
                    <>{dateTime(order.scheduled_at)}<small>{order.fulfillment === 'delivery' ? 'Giao tận nơi' : 'Nhận tại tiệm'}</small></>,
                    dateTime(order[dateField]),
                ])} />
            <Pagination page={orders} />
        </section>
        {isAdmin && <IntakeControl key={JSON.stringify(intake)} intake={intake} />}
    </Admin>;
}

export function AdminMail({ queued, delayed, threshold, failures }) {
    return <Admin title="Email đơn hàng" action={<ButtonLink href={inboxUrl} secondary><ArrowLeft size={17} />Đơn cần xử lý</ButtonLink>}>
        <section className="panel form-stack">
            <dl className="totals">
                <div><dt>Trong hàng đợi</dt><dd>{queued}</dd></div>
                <div><dt>Chưa xử lý xong sau {threshold} phút</dt><dd>{delayed}</dd></div>
                <div><dt>Gửi thất bại sau các lần thử</dt><dd>{failures.total}</dd></div>
            </dl>
            {delayed > 0 && <p className="warning">Kiểm tra worker và cấu hình gửi thư. Hàng đợi trống chỉ cho biết hiện không có thư đang chờ.</p>}
        </section>
        <section className="panel form-stack">
            <h2>Thư cần kiểm tra</h2>
            <Table headers={['Mã tác vụ', 'Thất bại lúc']} empty="Hiện không có tác vụ gửi thư thất bại."
                rows={failures.data.map(job => [job.uuid, <code>{job.uuid}</code>, dateTime(job.failed_at)])} />
            <Pagination page={failures} />
            {failures.total > 0 && <p className="muted">Sau khi xử lý nguyên nhân, dùng <code>php artisan queue:retry &lt;mã tác vụ&gt;</code> trên máy chủ để gửi lại.</p>}
        </section>
    </Admin>;
}

export function AdminReports({ filters, totals, days, top }) {
    return <Admin title="Báo cáo kinh doanh" detail="Tính theo ngày hoàn tất của đơn đã thanh toán. Doanh thu là tổng tiền sau giảm giá, gồm phí giao hàng.">
        <DateFilters filters={filters} url={reportsUrl} />
        <section className="panel form-stack report-totals" aria-label="Tổng trong kỳ">
            <div className="stat"><span>Doanh thu trong kỳ</span><strong>{money(totals.revenue)}</strong></div>
            <div className="stat"><span>Đơn đã hoàn tất và thanh toán</span><strong>{totals.order_count}</strong></div>
        </section>
        <div className="admin-columns">
            <section className="panel form-stack"><h2>Doanh thu theo ngày</h2>
                <Table headers={['Ngày hoàn tất', 'Số đơn', 'Doanh thu']} empty="Chưa có doanh thu trong kỳ này."
                    rows={days.data.map(day => [day.day, formatDate(day.day), day.order_count, money(day.revenue)])} />
                <Pagination page={days} />
            </section>
            <section className="panel form-stack"><h2>Bánh bán chạy</h2>
                <Table headers={['Bánh', 'Số bánh', 'Số đơn']} empty="Chưa có bánh bán thành công trong kỳ này."
                    rows={top.map(product => [product.id, product.name, product.quantity, product.order_count])} />
            </section>
        </div>
    </Admin>;
}

export function AdminInventory({ movements, filters, reasons, product }) {
    const form = useForm({ reason: filters.reason ?? '', ...(product && { product: product.id }) });
    return <Admin title="Nhật ký tồn kho" detail={product?.name}
        action={product && <ButtonLink href={inventoryUrl} secondary>Tất cả sản phẩm</ButtonLink>}>
        <form className="panel admin-filters" onSubmit={e => { e.preventDefault(); form.get(inventoryUrl); }}>
            <Field label="Loại thay đổi" error={form.errors.reason}><select value={form.data.reason} onChange={e => form.setData('reason', e.target.value)}>
                <option value="">Tất cả</option>{Object.entries(reasons).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select></Field>
            <button className="button compact" disabled={form.processing}>{form.processing ? 'Đang lọc…' : 'Lọc'}</button>
        </form>
        <section className="panel form-stack">
            <Table headers={['Thời điểm', 'Bánh', 'Thay đổi', 'Nguyên nhân', 'Người thực hiện']} empty="Chưa có thay đổi tồn kho theo bộ lọc."
                rows={movements.data.map(item => [item.id, dateTime(item.created_at),
                    <><Link className="table-link" href={root + '/san-pham/' + item.product_id}>{item.product_name}</Link><small>{item.variant_name}</small></>,
                    <><strong>{item.reason === 'waste' ? `${item.affected_quantity} bánh tiêu hao` : `${item.stock_after > item.stock_before ? '+' : ''}${item.stock_after - item.stock_before}`}</strong><small>Tồn bán {item.stock_before} → {item.stock_after}</small></>,
                    <>{reasons[item.reason]}{item.order_code && <small><Link className="table-link" href={root + '/don-hang/' + item.order_id}>{item.order_code}</Link></small>}{item.note && <small>{item.note}</small>}</>,
                    item.actor ?? 'Hệ thống',
                ])} />
            <Pagination page={movements} />
        </section>
    </Admin>;
}

export function AdminOrders({ orders, filters, statuses, payments }) {
    const url = root + '/don-hang';
    const qs = new URLSearchParams(Object.entries(filters).filter(([, value]) => value != null && value !== ''));
    return <Admin title="Đơn hàng" detail={orders.total + ' đơn theo bộ lọc hiện tại'} action={<a className="button secondary compact" href={url + '/xuat?' + qs}><Download size={17} />Xuất CSV</a>}>
        <DateFilters filters={filters} url={url} statuses={statuses} />
        <div className="panel"><Table empty="Không có đơn nào theo bộ lọc." headers={['Mã đơn', 'Ngày đặt', 'Khách hàng', 'Đơn hàng', 'Thanh toán', 'Tổng tiền']} rows={orders.data.map(order => [order.id,
            <Link className="table-link" href={url + '/' + order.id}>{order.code}</Link>, dateTime(order.created_at), order.name,
            <Pill value={order.status} labels={statuses} />, <><Pill value={order.payment} labels={payments} /><small>{order.method === 'bank' ? 'Chuyển khoản' : 'COD'}</small></>, money(order.total)])} />
            <Pagination page={orders} />
        </div>
    </Admin>;
}

function ScheduleSlot({ slot, statuses }) {
    const count = slot.orders.length;
    const full = slot.limit !== null && count >= slot.limit;

    return <section className="panel form-stack schedule-slot">
        <div className="section-head">
            <h2>{slot.time}</h2>
            <span className={full ? 'pill state-pending' : 'muted'}>{count}{slot.limit !== null && ' / ' + slot.limit} đơn{full && ' · Đầy'}</span>
        </div>
        {count > 0 && <div className="schedule-columns">
            <div>
                <h3>Tổng bánh theo phiên bản</h3>
                <ul className="summary-lines">{slot.products.map(product => (
                    <li key={JSON.stringify([product.variant_id, product.product_name, product.variant_name])}>
                        <span>{product.product_name}<small>{product.variant_name}</small></span>
                        <strong>{product.quantity} bánh</strong>
                    </li>
                ))}</ul>
            </div>
            <div className="form-stack">{slot.orders.map(order => (
                <article className="schedule-order" key={order.id}>
                    <div className="section-head">
                        <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>
                        <Pill value={order.status} labels={statuses} />
                    </div>
                    <p className="muted">{order.fulfillment === 'delivery' ? 'Giao tận nơi' : 'Nhận tại tiệm'}</p>
                    <ul className="summary-lines">{order.items.map(item => (
                        <li key={item.id}>
                            <span>
                                <strong>{item.quantity} × {item.product_name}</strong><small>{item.variant_name}</small>
                                {item.message && <small>Lời chúc: {item.message}</small>}
                                {item.note && <small>Ghi chú: {item.note}</small>}
                            </span>
                        </li>
                    ))}</ul>
                </article>
            ))}</div>
        </div>}
    </section>;
}

export function AdminReconciliation({ orders, filter }) {
    return <Admin title="Đối soát chuyển khoản">
        <section className="panel form-stack">
            <nav className="row-actions" aria-label="Lọc đơn chuyển khoản">
                {[[reconciliationUrl, 'pending', 'Chờ đối soát'], [reconciliationUrl + '?filter=overdue', 'overdue', 'Quá hạn']].map(([href, value, label]) => (
                    <Link key={value} href={href} className={'button compact' + (filter === value ? '' : ' secondary')} aria-current={filter === value ? 'page' : undefined}>{label}</Link>
                ))}
            </nav>
            <OrderQueue headers={['Mã đơn', 'Số tiền', 'Giờ giao hoặc nhận', 'Hạn chuyển khoản', 'Giờ đặt']}
                empty={filter === 'overdue' ? 'Chưa có đơn quá hạn.' : 'Chưa có đơn chuyển khoản chờ đối soát.'}
                rows={orders.data.map(order => [
                    order.id, <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>,
                    money(order.total), dateTime(order.scheduled_at),
                    <div className="row-actions">{dateTime(order.bank_due_at)}{order.overdue && <span className="pill state-pending">Quá hạn</span>}</div>,
                    dateTime(order.created_at),
                ])} />
            <Pagination page={orders} />
        </section>
    </Admin>;
}

export function AdminSchedule({ date, today, tomorrow, schedule, orderCount, cakeCount, statuses }) {
    const form = useForm({});

    return <Admin title="Lịch làm bánh" detail={date.split('-').reverse().join('/') + ' · ' + orderCount + ' đơn · ' + cakeCount + ' bánh'}>
        <div className="panel schedule-filters">
            <nav className="row-actions" aria-label="Ngày làm bánh">
                {[[today, 'Hôm nay'], [tomorrow, 'Ngày mai']].map(([day, label]) => (
                    <Link key={day} href={scheduleUrl + '?date=' + day} className={'button compact' + (date === day ? '' : ' secondary')} aria-current={date === day ? 'date' : undefined}>{label}</Link>
                ))}
            </nav>
            <form key={date} className="admin-filters" onSubmit={e => {
                e.preventDefault();
                const selected = new FormData(e.currentTarget).get('date');
                form.transform(() => ({ date: selected }));
                form.get(scheduleUrl);
            }}>
                <Field label="Ngày giao hoặc nhận" error={form.errors.date}><input type="date" name="date" defaultValue={date} required /></Field>
                <button className="button secondary compact" disabled={form.processing}>{form.processing ? 'Đang tải…' : 'Xem lịch'}</button>
            </form>
        </div>
        <div className="form-stack schedule-list">
            {orderCount === 0 && <p className="panel muted">{schedule.length ? 'Chưa có đơn giao hoặc nhận trong ngày này.' : 'Tiệm nghỉ ngày này.'}</p>}
            {schedule.map(slot => <ScheduleSlot key={slot.time} slot={slot} statuses={statuses} />)}
        </div>
    </Admin>;
}

function RescheduleOrder({ schedule, slots, url }) {
    const form = useForm({ slot: '', previous_slot: schedule.slice(0, 16), note: '' });
    return <section className="panel form-stack"><h2>Đổi giờ giao hoặc nhận</h2>
        {slots.length ? <form className="form-stack" onSubmit={event => {
            event.preventDefault();
            form.patch(url + '/doi-gio', { preserveScroll: true });
        }}>
            <Field label="Giờ mới" error={form.errors.slot}><select required value={form.data.slot} onChange={event => form.setData('slot', event.target.value)}>
                <option value="">Chọn giờ</option>
                {slots.map(slot => <option key={slot} value={slot}>{dateTime(slot)}</option>)}
            </select></Field>
            <Text form={form} field="note" label="Lý do đổi giờ" required maxLength={200} />
            <ErrorList errors={form.errors} />
            <button className="button secondary" disabled={form.processing || !form.data.slot}>{form.processing ? 'Đang lưu…' : 'Lưu giờ mới'}</button>
        </form> : <p className="muted">Hiện chưa có khung giờ khác còn chỗ.</p>}
    </section>;
}

function ResolveChangeRequest({ request }) {
    const form = useForm({ decision: '', note: '' });
    return <form className="form-stack" onSubmit={event => {
        event.preventDefault();
        form.patch(root + '/yeu-cau-don/' + request.id, { preserveScroll: true });
    }}>
        <h3>{request.kind === 'reschedule' ? 'Duyệt đổi giờ' : 'Duyệt hủy đơn'} · #{request.id}</h3>
        {request.kind === 'reschedule' && <p className="muted">Khung giờ sẽ được kiểm tra lại khi duyệt. Nếu đã hết chỗ hoặc đơn đã đổi, giờ cũ được giữ nguyên.</p>}
        <Field label="Quyết định" error={form.errors.decision}><select required value={form.data.decision} onChange={event => form.setData('decision', event.target.value)}>
            <option value="">Chọn cách xử lý</option><option value="approved">Chấp nhận yêu cầu</option><option value="rejected">Không chấp nhận</option>
        </select></Field>
        <Field label="Phản hồi cho khách" error={form.errors.note}><textarea required minLength={3} maxLength={200} rows="2" value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></Field>
        <ErrorList errors={form.errors} /><button className="button secondary" disabled={form.processing || !form.data.decision}>{form.processing ? 'Đang xử lý…' : 'Lưu quyết định và thông báo khách'}</button>
    </form>;
}

function ReviewCancelledInventory({ order, url }) {
    const dispatched = Boolean(order.dispatched_at) || order.events.some(event => event.kind === 'status' && event.value === 'shipping');
    const form = useForm({ items: order.items.map(item => ({ item_id: item.id, unmade: dispatched ? 0 : '', sellable: '', waste: '' })), note: '' });
    const remaining = item => Number(order.items.find(row => row.id === item.item_id).quantity) - Number(item.unmade || 0) - Number(item.sellable || 0) - Number(item.waste || 0);
    const complete = form.data.items.every(item => remaining(item) === 0 && ['unmade', 'sellable', 'waste'].every(field => item[field] !== '' && Number(item[field]) >= 0));
    function change(index, field, value) {
        form.setData('items', form.data.items.map((item, i) => i === index ? { ...item, [field]: value } : item));
    }
    return <section className="panel form-stack"><h2>Kiểm tra bánh sau hủy</h2>
        <p className="warning">Đơn đã vào khâu làm bánh. Tồn bán chưa được tự tăng. Quản lý kiểm tra thực tế rồi phân loại từng bánh, chỉ lưu một lần.</p>
        {dispatched && <p className="warning">Bánh đã được xuất giao. Phần chưa làm phải bằng 0. Chỉ phân loại bánh nhận lại đã kiểm tra còn bán được hoặc phần hỏng / tiêu hao.</p>}
        <form className="form-stack" onSubmit={event => {
            event.preventDefault();
            form.post(url + '/ton-huy', { preserveScroll: true });
        }}>
            {form.data.items.map((item, index) => {
                const original = order.items.find(row => row.id === item.item_id);
                return <fieldset className="form-stack" key={item.item_id}><legend>{original.product_name} · {original.variant_name} · {original.quantity} bánh</legend>
                    <Field label="Chưa làm — giải phóng phần giữ" error={form.errors[`items.${index}.unmade`]}><input required readOnly={dispatched} type="number" min="0" max={dispatched ? 0 : original.quantity} value={item.unmade} onChange={event => change(index, 'unmade', event.target.value)} /></Field>
                    <Field label="Đã làm, còn đủ điều kiện bán — nhập lại" error={form.errors[`items.${index}.sellable`]}><input required type="number" min="0" max={original.quantity} value={item.sellable} onChange={event => change(index, 'sellable', event.target.value)} /></Field>
                    <Field label="Hỏng / tiêu hao / không thể bán lại" error={form.errors[`items.${index}.waste`]}><input required type="number" min="0" max={original.quantity} value={item.waste} onChange={event => change(index, 'waste', event.target.value)} /></Field>
                    <small className={remaining(item) === 0 ? 'muted' : 'field-error'}>{remaining(item) === 0 ? 'Đã phân loại đủ số lượng.' : remaining(item) > 0 ? `Còn ${remaining(item)} bánh chưa phân loại.` : 'Tổng phân loại vượt số bánh trong đơn.'}</small>
                </fieldset>;
            })}
            <Field label="Kết quả kiểm tra và lý do" error={form.errors.note}><textarea required minLength={3} maxLength={250} rows="3" value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></Field>
            <ErrorList errors={form.errors} /><button className="button secondary" disabled={form.processing || !complete}>{form.processing ? 'Đang ghi nhận…' : 'Chốt phân loại và cập nhật tồn'}</button>
        </form>
    </section>;
}

function InventoryDispositionHistory({ order }) {
    return <section className="panel form-stack"><h2>Kết quả kiểm tra bánh sau hủy</h2>
        <ul className="summary-lines">{order.inventory_dispositions.map(row => {
            const item = order.items.find(item => item.id === row.order_item_id);
            return <li key={row.id}><div className="form-stack">
                <strong>{item ? item.product_name + ' · ' + item.variant_name : 'Dòng bánh #' + row.order_item_id}</strong>
                <small>{dateTime(row.created_at)} · {row.actor}</small>
                <p>Chưa làm: {row.unmade} · Còn bán được: {row.sellable} · Hỏng / tiêu hao: {row.waste}</p>
                <p>Lý do: {row.reason}</p>
            </div></li>;
        })}</ul>
    </section>;
}

function PrepaymentException({ url }) {
    const form = useForm({ kind: 'prepay_exception', value: 'granted', note: '' });
    return <form className="form-stack" onSubmit={event => {
        event.preventDefault();
        form.patch(url, { preserveScroll: true });
    }}>
        <Field label="Lý do quản lý cho phép làm / giao khi chưa thu đủ" error={form.errors.note}><textarea required minLength={3} maxLength={250} rows="2" value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></Field>
        <ErrorList errors={form.errors} /><button className="button secondary" disabled={form.processing}>{form.processing ? 'Đang lưu…' : 'Duyệt ngoại lệ trả trước cho đơn này'}</button>
    </form>;
}

function LegacyLeadTime({ url }) {
    const form = useForm({ kind: 'lead_time', value: '', note: '' });
    return <section className="panel form-stack"><h2>Đơn cũ chưa lưu thời gian chuẩn bị</h2>
        <p className="muted">Quản lý cần đối chiếu bánh đã đặt và ghi thời gian chuẩn bị trước khi cho đổi giờ. Việc sửa menu sau này không thay đổi mốc đã xác nhận này.</p>
        <form className="form-stack" onSubmit={event => { event.preventDefault(); form.patch(url, { preserveScroll: true }); }}>
            <Text form={form} field="value" label="Thời gian chuẩn bị (phút)" type="number" required min="0" max="10080" />
            <TextArea form={form} field="note" label="Căn cứ xác nhận" required minLength={3} maxLength={250} />
            <ErrorList errors={form.errors} /><button className="button secondary" disabled={form.processing}>Lưu thời gian cho đơn cũ</button>
        </form>
    </section>;
}

export function AdminOrder({ order, statuses, payments, next, reconcile, rescheduleSlots, manager = false, prepayRequired = false, prepayExceptionAllowed = false, changeRequests = [] }) {
    const transition = useForm({ kind: 'status', value: '', note: '' });
    const payment = useForm({ kind: 'payment', value: '', note: '', refund_method: order.method === 'bank' ? 'bank' : 'cash', refund_reference: '', refund_amount: order.total, refund_verified: false });
    const url = root + '/don-hang/' + order.id;
    const canPay = order.payment === 'unpaid' && order.status !== 'cancelled' && (order.method === 'bank' ? reconcile : ['ready', 'shipping', 'completed', 'delivery_failed', 'uncollected'].includes(order.status));
    const canRefund = reconcile && order.payment === 'paid' && order.status === 'cancelled';
    const started = Boolean(order.production_started_at) || ['preparing', 'ready', 'shipping', 'delivery_failed', 'uncollected'].includes(order.status);
    const availableNext = next.filter(value => value !== 'cancelled' || !started || manager);
    const blockedByPayment = order.method === 'bank' && prepayRequired && order.payment !== 'paid' && !order.prepayment_waived_at;
    const reasonRequired = ['cancelled', 'delivery_failed', 'uncollected'].includes(transition.data.value) || ['delivery_failed', 'uncollected'].includes(order.status);
    return <Admin title={'Đơn ' + order.code} action={<ButtonLink href={root + '/don-hang'} secondary><ArrowLeft size={17} />Danh sách đơn</ButtonLink>}>
        <div className="row-actions admin-pills"><Pill value={order.status} labels={statuses} /><Pill value={order.payment} labels={payments} /></div>
        <div className="admin-columns">
            <div className="form-stack">
                <section className="panel form-stack"><h2>Khách hàng</h2><strong>{order.name}</strong><p>{order.email} · {order.phone}</p>
                    <p>{order.fulfillment === 'delivery' ? 'Giao đến ' + order.address + ' · ' + order.region_label : 'Nhận tại tiệm'}</p><p>Giờ nhận {dateTime(order.scheduled_at)}</p>
                </section>
                <section className="panel form-stack"><h2>Bánh đã đặt</h2><OrderItems items={order.items} /><Totals {...order} />{order.coupon_code && <p>Mã đã sử dụng <b>{order.coupon_code}</b></p>}</section>
                <section className="panel form-stack"><h2>Yêu cầu đổi / hủy của khách</h2>
                    <ChangeRequestHistory requests={changeRequests} />
                    {changeRequests.filter(request => request.status === 'pending').map(request => <ResolveChangeRequest key={request.id} request={request} />)}
                </section>
                <section className="panel form-stack"><h2>Lịch sử thay đổi</h2><OrderEvents events={order.events} statuses={statuses} payments={payments} /></section>
            </div>
            <div className="form-stack">
                <section className="panel form-stack"><h2>Xử lý đơn</h2>
                    {['delivery_failed', 'uncollected'].includes(order.status) && <p className="warning">Liên hệ khách và ghi rõ kết quả. Có thể giao / nhận lại, hoặc chuyển quản lý duyệt hủy sau làm. Hoàn tất khi đã bàn giao và đã thu tiền.</p>}
                    {started && !manager && <p className="muted">Đơn đã vào khâu làm bánh. Chỉ quản lý được duyệt hủy và kiểm tra tồn sau hủy.</p>}
                    {blockedByPayment && order.status !== 'cancelled' && <p className="warning">Đơn chuyển khoản cần được đối soát đã thu trước khi đưa vào làm hoặc giao. Ngoại lệ chỉ được quản lý duyệt khi cấu hình tiệm cho phép.</p>}
                    {order.prepayment_waived_at && <p className="warning">Quản lý đã duyệt ngoại lệ trả trước cho đơn này. Thanh toán vẫn phải được đối chiếu trước khi hoàn tất.</p>}
                    {availableNext.length ? <form className="form-stack" onSubmit={e => {
                        e.preventDefault();
                        transition.transform(data => ({ ...data, expected_status: order.status }));
                        transition.patch(url, { preserveScroll: true, onSuccess: () => transition.reset() });
                    }}>
                        <Field label="Trạng thái tiếp theo"><select required value={transition.data.value} onChange={e => transition.setData('value', e.target.value)}>
                            <option value="">Chọn trạng thái</option>{availableNext.map(value => <option key={value} value={value} disabled={(value === 'completed' && order.payment !== 'paid') || (blockedByPayment && ['preparing', 'shipping'].includes(value))}>{statuses[value]}</option>)}</select></Field>
                        {next.includes('completed') && order.payment !== 'paid' && <p className="muted">Cần xác nhận thanh toán trước khi hoàn tất.</p>}
                        {transition.data.value === 'cancelled' && started && <p className="warning">Hủy sau làm sẽ đưa bánh sang chờ quản lý phân loại. Tồn bán không tự tăng.</p>}
                        <Field label={reasonRequired ? 'Lý do xử lý (bắt buộc)' : 'Ghi chú nội bộ'}><textarea required={reasonRequired} minLength={reasonRequired ? 3 : undefined} rows="2" maxLength={250} value={transition.data.note} onChange={e => transition.setData('note', e.target.value)} /></Field>
                        <ErrorList errors={transition.errors} /><button className="button" disabled={transition.processing || !transition.data.value}>{transition.processing ? 'Đang cập nhật…' : 'Cập nhật trạng thái'}</button>
                    </form> : <p className="muted">Không có bước xử lý tiếp theo cho quyền của bạn ở trạng thái hiện tại.</p>}
                </section>
                {manager && prepayExceptionAllowed && blockedByPayment && ['pending', 'confirmed'].includes(order.status) && <section className="panel form-stack"><h2>Ngoại lệ trả trước</h2><PrepaymentException url={url} /></section>}
                {manager && order.prep_minutes_snapshot == null && ['pending', 'confirmed'].includes(order.status) && <LegacyLeadTime url={url} />}
                {rescheduleSlots && <RescheduleOrder key={order.scheduled_at} schedule={order.scheduled_at} slots={rescheduleSlots} url={url} />}
                {order.status === 'cancelled' && order.inventory_review_required && !order.inventory_processed_at && (manager ? <ReviewCancelledInventory order={order} url={url} /> : <p className="warning">Bánh sau hủy đang chờ quản lý kiểm tra. Chưa có tồn nào tự được nhập lại.</p>)}
                {order.inventory_dispositions?.length > 0 && <InventoryDispositionHistory order={order} />}
                <section className="panel form-stack"><h2>Thanh toán</h2>
                    <p>{order.method === 'bank' ? 'Chuyển khoản thủ công' : 'Thanh toán khi nhận bánh'}</p>
                    {order.bank_due_at && order.payment === 'unpaid' && order.status !== 'cancelled' && <p className="muted">Hạn chuyển khoản · {dateTime(order.bank_due_at)}</p>}
                    {(order.bank_snapshot || canPay || canRefund) && <dl className="totals payment-review">
                        <div><dt>{order.bank_snapshot && order.payment === 'unpaid' ? 'Nội dung chuyển khoản' : 'Mã đơn'}</dt><dd>{order.code}</dd></div>
                        <div><dt>{canRefund ? 'Số tiền hoàn' : 'Tổng thanh toán'}</dt><dd>{money(order.total)}</dd></div>
                    </dl>}
                    {order.bank_snapshot?.demo && <p className="warning">Thông tin chuyển khoản mẫu.</p>}
                    {order.refund_evidence && <dl className="totals payment-review">
                        <div><dt>Phương thức đã hoàn</dt><dd>{order.refund_evidence.method === 'bank' ? 'Chuyển khoản' : 'Tiền mặt'}</dd></div>
                        <div><dt>Số tiền đã hoàn</dt><dd>{money(order.refund_evidence.amount)}</dd></div>
                        <div><dt>Tham chiếu đã đối chiếu</dt><dd>{order.refund_evidence.reference}</dd></div>
                        <div><dt>Ghi nhận lúc</dt><dd>{dateTime(order.refund_evidence.verified_at?.replace('T', ' '))}</dd></div>
                        <div><dt>Kết quả kiểm tra</dt><dd>{order.refund_evidence.note}</dd></div>
                    </dl>}
                    {(canPay || canRefund) && <form className="form-stack" onSubmit={e => {
                        e.preventDefault();
                        payment.transform(data => ({ ...data, value: canPay ? 'paid' : 'refunded' }));
                        payment.patch(url, { preserveScroll: true, onSuccess: () => payment.reset() });
                    }}>
                        {canRefund && <>
                            <p className="warning">Thực hiện hoàn tiền bên ngoài ứng dụng, đối chiếu đúng người nhận và số tiền rồi mới ghi nhận tại đây.</p>
                            <Field label="Phương thức hoàn"><input readOnly value={order.method === 'bank' ? 'Chuyển khoản về người thanh toán' : 'Tiền mặt'} /></Field>
                            <Text form={payment} field="refund_amount" label="Số tiền đã hoàn (₫)" type="number" required min={order.total} max={order.total} />
                            <Text form={payment} field="refund_reference" label={order.method === 'bank' ? 'Mã giao dịch hoàn / chứng từ đối chiếu' : 'Mã phiếu chi / xác nhận nhận tiền mặt'} required minLength={order.method === 'bank' ? 6 : 3} maxLength={120} />
                            <Flag form={payment} field="refund_verified" label="Đã kiểm tra bằng chứng hoàn đúng phương thức và đủ số tiền" />
                        </>}
                        <Field label={canRefund ? 'Ghi chú đối chiếu hoàn tiền' : 'Ghi chú thanh toán'}><input required={canRefund} minLength={canRefund ? 3 : undefined} maxLength={250} value={payment.data.note} onChange={e => payment.setData('note', e.target.value)} /></Field>
                        <ErrorList errors={payment.errors} /><button className="button secondary" disabled={payment.processing || (canRefund && !payment.data.refund_verified)}>{payment.processing ? 'Đang ghi nhận…' : canPay ? 'Xác nhận đã thanh toán' : 'Ghi nhận đã hoàn tiền'}</button>
                    </form>}
                </section>
            </div>
        </div>
    </Admin>;
}

const categoryLabels = { cake: 'Bánh kem', croissant: 'Bánh nướng', cupcake: 'Bánh lạnh', cookie: 'Bánh quy' };

function CategoryForm({ category }) {
    const form = useForm({ name: category?.name ?? '', slug: category?.slug ?? '', icon: category?.icon ?? 'cake' });
    return <form className="form-stack category-edit" onSubmit={e => { e.preventDefault(); form.post(root + '/danh-muc' + (category ? '/' + category.id : ''), { preserveScroll: true, onSuccess: () => { if (!category) form.reset(); } }); }}>
        <Field label="Tên danh mục" error={form.errors.name}><input required maxLength={100} value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></Field>
        <Field label="Đường dẫn ngắn" error={form.errors.slug}><input required pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="banh-kem" value={form.data.slug} onChange={e => form.setData('slug', e.target.value)} /></Field>
        <Field label="Biểu tượng"><select value={form.data.icon} onChange={e => form.setData('icon', e.target.value)}>{Object.entries(categoryLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></Field>
        <button className="button secondary compact" disabled={form.processing}>{category ? 'Lưu danh mục' : 'Thêm danh mục'}</button>
    </form>;
}

export function AdminProducts({ products, categories }) {
    return <Admin title="Sản phẩm" action={<ButtonLink href={root + '/san-pham/them'}><Plus size={18} />Thêm sản phẩm</ButtonLink>}>
        <div className="admin-columns">
            <section className="panel"><h2>Danh sách bánh</h2><Table headers={['Tên bánh', 'Danh mục', 'Trạng thái', 'Nội dung và ảnh']} rows={products.data.map(product => [product.id,
                <Link className="table-link" href={root + '/san-pham/' + product.id}>{product.name}</Link>, product.category, product.active ? 'Đang bán' : 'Đã ẩn',
                <><span>{product.details_verified ? 'Đã xác minh thông tin' : 'Chờ xác minh thông tin'}</span><small>{product.images_approved ? 'Ảnh đã được phép dùng' : 'Ảnh chờ duyệt quyền sử dụng'}</small></>])} /><Pagination page={products} /></section>
            <section className="panel form-stack"><h2>Danh mục</h2>
                {categories.map(category => <details className="fold" key={category.id}><summary>{category.name}</summary><CategoryForm category={category} /></details>)}
                <details className="fold"><summary>Thêm danh mục</summary><CategoryForm /></details>
            </section>
        </div>
    </Admin>;
}

const freshVariant = () => ({ name: '', price: '', stock: '', active: true, size_label: '', servings_min: '', servings_max: '', servings_verified: false });
const freshAccessory = () => ({ name: '', price: '', active: true, approved: false });
const metadataLines = value => value.split(/[\n,]+/).map(line => line.trim()).filter(Boolean);

function Text({ form, field, label, type = 'text', ...props }) {
    return <Field label={label} error={form.errors[field]}><input type={type} value={form.data[field] ?? ''} onChange={e => form.setData(field, e.target.value)} {...props} /></Field>;
}

function TextArea({ form, field, label, ...props }) {
    return <Field label={label} error={form.errors[field]}><textarea rows="3" value={form.data[field] ?? ''} onChange={e => form.setData(field, e.target.value)} {...props} /></Field>;
}

function Flag({ form, field, label }) {
    return <label className="checkbox"><input type="checkbox" checked={Boolean(form.data[field])} onChange={e => form.setData(field, e.target.checked)} />{label}</label>;
}

export function AdminProduct({ product, categories }) {
    const form = useForm({
        name: product?.name ?? '', category_id: product?.category_id ?? '', description: product?.description ?? '',
        ingredients: product?.ingredients ?? '', allergens: product?.allergens ?? '', prep_minutes: product?.prep_minutes ?? 60,
        occasions: (product?.occasions ?? []).join('\n'), flavors: (product?.flavors ?? []).join('\n'),
        storage_instructions: product?.storage_instructions ?? '', details_verified: Boolean(product?.details_verified), images_approved: Boolean(product?.images_approved),
        message_fee: product?.message_fee ?? 0, active: Boolean(product?.active ?? true), featured: Boolean(product?.featured), customizable: Boolean(product?.customizable),
        variants: product?.variants.map(({ id, name, price, stock, active, size_label, servings_min, servings_max, servings_verified }) => ({ id, name, price, stock, active: Boolean(active), expected_stock: stock,
            size_label: size_label ?? '', servings_min: servings_min ?? '', servings_max: servings_max ?? '', servings_verified: Boolean(servings_verified) })) ?? [freshVariant()],
        accessories: product?.accessories.map(({ id, name, price, active, approved }) => ({ id, name, price, active: Boolean(active), approved: Boolean(approved) })) ?? [],
        uploads: [], removed: [],
    });
    function variantChange(index, key, value) {
        form.setData('variants', form.data.variants.map((variant, i) => i === index ? { ...variant, [key]: value,
            ...(['size_label', 'servings_min', 'servings_max'].includes(key) && { servings_verified: false }) } : variant));
    }
    function accessoryChange(index, key, value) {
        form.setData('accessories', form.data.accessories.map((accessory, i) => i === index ? { ...accessory, [key]: value,
            ...(['name', 'price'].includes(key) && { approved: false }) } : accessory));
    }
    return <Admin title={product ? product.name : 'Thêm sản phẩm'} action={<div className="row-actions">
        {product && <ButtonLink href={inventoryUrl + '?product=' + product.id} secondary><Package size={17} />Nhật ký tồn kho</ButtonLink>}
        <ButtonLink href={root + '/san-pham'} secondary><ArrowLeft size={17} />Sản phẩm</ButtonLink>
    </div>}>
        <form className="form-stack" onSubmit={e => {
            e.preventDefault();
            form.transform(data => ({ ...data, occasions: JSON.stringify(metadataLines(data.occasions)), flavors: JSON.stringify(metadataLines(data.flavors)) }));
            form.post(root + '/san-pham' + (product ? '/' + product.id : ''), { forceFormData: true, preserveState: 'errors' });
        }}>
            <div className="admin-columns">
                <section className="panel form-stack"><h2>Thông tin bánh</h2><Text form={form} field="name" label="Tên bánh" required maxLength={150} />
                    <Field label="Danh mục" error={form.errors.category_id}><select required value={form.data.category_id} onChange={e => form.setData('category_id', e.target.value)}><option value="">Chọn danh mục</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select></Field>
                    <TextArea form={form} field="description" label="Mô tả" required maxLength={3000} />
                    <TextArea form={form} field="occasions" label="Dịp dùng bánh" placeholder="Mỗi dòng một dịp do tiệm cung cấp" />
                    <TextArea form={form} field="flavors" label="Hương vị" placeholder="Mỗi dòng một hương vị thực tế" />
                    <TextArea form={form} field="ingredients" label="Thành phần" maxLength={1200} required={form.data.details_verified}
                        onChange={event => form.setData(data => ({ ...data, ingredients: event.target.value, details_verified: false }))} />
                    <TextArea form={form} field="allergens" label="Cảnh báo dị ứng" maxLength={800} required={form.data.details_verified}
                        onChange={event => form.setData(data => ({ ...data, allergens: event.target.value, details_verified: false }))} />
                    <TextArea form={form} field="storage_instructions" label="Bảo quản và sử dụng" maxLength={1600} required={form.data.details_verified}
                        onChange={event => form.setData(data => ({ ...data, storage_instructions: event.target.value, details_verified: false }))} />
                    <Flag form={form} field="details_verified" label="Đã đối chiếu thành phần, dị ứng và cách bảo quản với người phụ trách của tiệm" />
                    <p className="muted">Thông tin chưa xác minh và ảnh minh họa được giữ trạng thái chờ. Chỉ xác nhận khi tiệm đã cung cấp và duyệt dữ liệu.</p>
                </section>
                <section className="panel form-stack"><h2>Hình ảnh và hiển thị</h2>
                    {product?.images.length > 0 && <div className="admin-images">{product.images.map(src => <label key={src}><Image src={src} /><input type="checkbox" checked={form.data.removed.includes(src)} onChange={e => form.setData(data => ({ ...data, removed: e.target.checked ? [...data.removed, src] : data.removed.filter(image => image !== src), images_approved: false }))} /> Bỏ ảnh</label>)}</div>}
                    <Field label="Tải ảnh lên" hint="Tối đa 5 ảnh JPEG, PNG hoặc WebP, 4 MB mỗi ảnh." error={form.errors.uploads}><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={e => form.setData(data => ({ ...data, uploads: [...e.target.files], images_approved: false }))} /></Field>
                    <Flag form={form} field="images_approved" label="Bộ ảnh hiện tại đã được tiệm xác nhận quyền sử dụng" />
                    <Text form={form} field="prep_minutes" label="Thời gian làm bánh (phút)" type="number" min="0" max="14400" required />
                    <Flag form={form} field="active" label="Hiển thị và bán trên cửa hàng" /><Flag form={form} field="featured" label="Hiển thị trong bánh nổi bật" /><Flag form={form} field="customizable" label="Cho phép ghi lời chúc" />
                    <Text form={form} field="message_fee" label="Phí ghi lời chúc (₫)" type="number" min="0" max="1000000" required />
                </section>
            </div>
            <section className="panel form-stack"><h2>Phiên bản bánh</h2>
                {form.data.variants.map((variant, index) => <div className="variant-edit" key={variant.id ?? index}>
                    {[['name', 'Tên phiên bản', 'text'], ['price', 'Giá (₫)', 'number'], ['stock', 'Tồn kho', 'number']].map(([field, label, type]) => <Field key={field} label={label} error={form.errors['variants.' + index + '.' + field]}><input required type={type} min={type === 'number' ? '0' : undefined} value={variant[field]} onChange={e => variantChange(index, field, e.target.value)} /></Field>)}
                    <Field label="Kích thước hoặc khối lượng" error={form.errors['variants.' + index + '.size_label']}><input maxLength={80} value={variant.size_label} onChange={e => variantChange(index, 'size_label', e.target.value)} placeholder="Để trống nếu tiệm chưa xác nhận" /></Field>
                    <Field label="Khẩu phần tối thiểu (người)" error={form.errors['variants.' + index + '.servings_min']}><input type="number" min="1" max="200" required={variant.servings_verified} value={variant.servings_min} onChange={e => variantChange(index, 'servings_min', e.target.value)} /></Field>
                    <Field label="Khẩu phần tối đa (người)" error={form.errors['variants.' + index + '.servings_max']}><input type="number" min="1" max="200" required={variant.servings_verified} value={variant.servings_max} onChange={e => variantChange(index, 'servings_max', e.target.value)} /></Field>
                    <label className="checkbox"><input type="checkbox" checked={variant.servings_verified} onChange={e => variantChange(index, 'servings_verified', e.target.checked)} />Tiệm đã xác nhận khẩu phần này</label>
                    <label className="checkbox"><input type="checkbox" checked={variant.active} onChange={e => variantChange(index, 'active', e.target.checked)} />Đang bán</label>
                    {!variant.id && form.data.variants.length > 1 && <button type="button" className="text-action danger" onClick={() => form.setData('variants', form.data.variants.filter((_, i) => i !== index))}>Bỏ dòng</button>}
                    {variant.stock !== '' && Number(variant.stock) !== Number(variant.expected_stock ?? 0) && <div className="stock-note">
                        <Field label="Lý do thay đổi tồn (không bắt buộc)" error={form.errors['variants.' + index + '.stock_note']}>
                            <input maxLength={250} value={variant.stock_note ?? ''} onChange={e => variantChange(index, 'stock_note', e.target.value)} />
                        </Field>
                    </div>}
                </div>)}
                <button type="button" className="button secondary compact" disabled={form.data.variants.length >= 30} onClick={() => form.setData('variants', [...form.data.variants, freshVariant()])}><Plus size={16} />Thêm phiên bản</button>
            </section>
            <section className="panel form-stack"><h2>Phụ kiện theo bánh</h2>
                <p className="muted">Nhập tên và giá do tiệm cung cấp. Chỉ phụ kiện vừa bật bán vừa được duyệt mới xuất hiện cho khách. Giá tính cho mỗi bánh trong dòng giỏ hàng.</p>
                {form.data.accessories.length === 0 && <p className="inline-empty">Chưa có phụ kiện. Không tự thêm hoặc áp giá khi tiệm chưa duyệt.</p>}
                {form.data.accessories.map((accessory, index) => <fieldset className="form-stack" key={accessory.id ?? 'new-' + index}><legend>Phụ kiện {index + 1}</legend>
                    <div className="two-fields">
                        <Field label="Tên phụ kiện" error={form.errors['accessories.' + index + '.name']}><input required maxLength={100} value={accessory.name} onChange={e => accessoryChange(index, 'name', e.target.value)} /></Field>
                        <Field label="Giá mỗi bánh (₫)" error={form.errors['accessories.' + index + '.price']}><input required type="number" min="0" max="100000000" value={accessory.price} onChange={e => accessoryChange(index, 'price', e.target.value)} /></Field>
                    </div>
                    <label className="checkbox"><input type="checkbox" checked={accessory.active} onChange={e => accessoryChange(index, 'active', e.target.checked)} />Đang bán cùng bánh này</label>
                    <label className="checkbox"><input type="checkbox" checked={accessory.approved} onChange={e => accessoryChange(index, 'approved', e.target.checked)} />Tiệm đã duyệt tên và giá phụ kiện</label>
                    {!accessory.id && <button type="button" className="text-action danger" onClick={() => form.setData('accessories', form.data.accessories.filter((_, i) => i !== index))}>Bỏ dòng phụ kiện</button>}
                </fieldset>)}
                <button type="button" className="button secondary compact" disabled={form.data.accessories.length >= 20} onClick={() => form.setData('accessories', [...form.data.accessories, freshAccessory()])}><Plus size={16} />Thêm phụ kiện</button>
            </section>
            <ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>{form.processing ? 'Đang lưu…' : 'Lưu sản phẩm'}</button>
        </form>
    </Admin>;
}

export function AdminSettings({ settings }) {
    const { logo, weekdays, slots, slot_limits, closed_dates, regions, ...fields } = settings;
    const form = useForm({
        ...fields, logo: null, remove_logo: false, weekdays, closedText: closed_dates.join('\n'), regions,
        slotRows: slots.map(time => ({ time, limit: slot_limits[time] ?? '' })),
    });
    const lines = value => value.split(/[\n,]+/).map(line => line.trim()).filter(Boolean);
    function slotChange(index, key, value) {
        form.setData('slotRows', form.data.slotRows.map((slot, i) => i === index ? { ...slot, [key]: value } : slot));
    }
    function regionChange(index, key, value) {
        form.setData('regions', form.data.regions.map((region, i) => index === i ? { ...region, [key]: value } : region));
    }
    return <Admin title="Cấu hình cửa hàng">
        <form className="form-stack" onSubmit={e => {
            e.preventDefault();
            form.transform(({ slotRows, closedText, ...data }) => ({
                ...data,
                slots: slotRows.map(slot => slot.time),
                slot_limits: Object.fromEntries(slotRows.map(slot => [slot.time, slot.limit])),
                closed_dates: lines(closedText),
            }));
            form.post(root + '/cai-dat', { forceFormData: true, preserveState: 'errors' });
        }}>
            <div className="admin-columns"><section className="panel form-stack"><h2>Thông tin tiệm</h2>
                <Text form={form} field="name" label="Tên cửa hàng" required maxLength={100} /><Text form={form} field="address" label="Địa chỉ" maxLength={250} />
                <div className="two-fields"><Text form={form} field="phone" label="Số điện thoại" /><Text form={form} field="email" label="Email liên hệ" type="email" /></div>
                <TextArea form={form} field="order_policy" label="Chính sách đổi giờ, hủy đơn và hoàn tiền" maxLength={4000} />
                <TextArea form={form} field="privacy_notice" label="Thông báo xử lý dữ liệu khách hàng" maxLength={4000} />
                <Text form={form} field="hours" label="Giờ mở cửa hiển thị" required /><Field label="Logo" hint="JPEG, PNG hoặc WebP, tối đa 4 MB"><input type="file" accept="image/jpeg,image/png,image/webp" onChange={e => form.setData('logo', e.target.files?.[0] ?? null)} /></Field>
                {logo && <><img className="logo-preview" src={logo} alt="Logo hiện tại" /><Flag form={form} field="remove_logo" label="Bỏ logo hiện tại" /></>}
            </section><section className="panel form-stack"><h2>Lịch nhận bánh</h2>
                <div className="weekdays">{[[1, 'T2'], [2, 'T3'], [3, 'T4'], [4, 'T5'], [5, 'T6'], [6, 'T7'], [7, 'CN']].map(([day, name]) => <label className="checkbox" key={day}><input type="checkbox" checked={form.data.weekdays.includes(day)} onChange={e => form.setData('weekdays', e.target.checked ? [...form.data.weekdays, day] : form.data.weekdays.filter(value => value !== day))} />{name}</label>)}</div>
                <div className="form-stack">
                    <h3>Khung giờ giao hoặc nhận</h3>
                    <p className="muted">Mỗi giới hạn tính chung đơn giao tận nơi và nhận tại tiệm. Để trống để nhận không giới hạn.</p>
                    {form.data.slotRows.map((slot, index) => <div key={index} className="slot-edit">
                        <Field label="Giờ (HH:mm)" error={form.errors['slots.' + index]}>
                            <input required placeholder="09:00" maxLength={5} pattern="([01][0-9]|2[0-3]):[0-5][0-9]" value={slot.time} onChange={e => slotChange(index, 'time', e.target.value)} />
                        </Field>
                        <Field label="Số đơn tối đa" error={form.errors['slot_limits.' + slot.time]}>
                            <input type="number" min="1" max="1000" value={slot.limit} onChange={e => slotChange(index, 'limit', e.target.value)} />
                        </Field>
                        <button type="button" className="text-action danger" aria-label={'Bỏ khung giờ ' + (slot.time || index + 1)} disabled={form.data.slotRows.length === 1} onClick={() => form.setData('slotRows', form.data.slotRows.filter((_, i) => i !== index))}>Bỏ</button>
                    </div>)}
                    <button type="button" className="button secondary compact" disabled={form.data.slotRows.length >= 48} onClick={() => form.setData('slotRows', [...form.data.slotRows, { time: '', limit: '' }])}><Plus size={16} />Thêm khung giờ</button>
                </div>
                <Field label="Ngày nghỉ" hint="Mỗi ngày một dòng, định dạng YYYY-MM-DD."><textarea rows="4" value={form.data.closedText} onChange={e => form.setData('closedText', e.target.value)} /></Field>
            </section></div>
            <section className="panel form-stack"><h2>Giao hàng</h2>
                <Text form={form} field="free_from" label="Miễn phí giao hàng từ (₫, sau giảm giá)" type="number" min="0" />
                {form.data.regions.map((area, i) => <div className="variant-edit" key={i}>
                    {[['key', 'Mã khu vực', 'text'], ['name', 'Tên khu vực', 'text'], ['fee', 'Phí giao (₫)', 'number']].map(([key, label, type]) => <Field label={label} key={key}><input required type={type} min={type === 'number' ? '0' : undefined} value={area[key]} onChange={e => regionChange(i, key, e.target.value)} /></Field>)}
                    <button type="button" className="text-action danger" onClick={() => form.setData('regions', form.data.regions.filter((_, index) => index !== i))}>Bỏ khu vực</button>
                </div>)}
                <button type="button" className="button secondary compact" onClick={() => form.setData('regions', [...form.data.regions, { key: '', name: '', fee: 0 }])}><Plus size={16} />Thêm khu vực</button>
            </section>
            <section className="panel form-stack"><h2>Chuyển khoản thủ công</h2><p className="muted">Điền cả ba trường để mở chuyển khoản. Xóa cả ba trường để tắt.</p>
                <div className="three-fields"><Text form={form} field="bank_name" label="Ngân hàng" /><Text form={form} field="bank_account" label="Số tài khoản" /><Text form={form} field="bank_holder" label="Chủ tài khoản" /></div>
                <Text form={form} field="bank_due_minutes" label="Thời hạn chuyển khoản (phút)" type="number" required min="1" max="1440" />
                <Flag form={form} field="bank_demo" label="Hiển thị cảnh báo thông tin chuyển khoản mẫu" />
                <Flag form={form} field="bank_prepay_required" label="Đơn chuyển khoản phải được đối soát đã thu trước khi làm hoặc giao" />
                <Flag form={form} field="allow_payment_override" label="Cho quản lý duyệt ngoại lệ trả trước theo từng đơn, kèm lý do" />
                <p className="muted">Ngoại lệ không ghi nhận đã thanh toán. Đơn vẫn cần thu đủ và đối chiếu trước khi hoàn tất.</p>
            </section>
            <section className="panel form-stack"><h2>Hủy đơn và mã giảm giá</h2>
                <Flag form={form} field="coupon_return_on_early_cancel" label="Trả lượt mã giảm khi hủy trước khi bắt đầu làm" />
                <p className="muted">Lượt mã giảm được xử lý riêng với tồn. Hủy sau khi bắt đầu làm không tự nhập bánh vào tồn bán, quản lý phải kiểm tra và ghi rõ phần chưa làm, còn bán được hoặc hỏng.</p>
            </section>
            <ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>{form.processing ? 'Đang lưu…' : 'Lưu cấu hình'}</button>
        </form>
    </Admin>;
}

export function AdminCoupons({ coupon, coupons }) {
    const url = root + '/ma-giam';
    const form = useForm({ code: coupon?.code ?? '', kind: coupon?.kind ?? 'percent', amount: coupon?.amount ?? '',
        minimum: coupon?.minimum ?? 0, usage_limit: coupon?.usage_limit ?? '', starts_on: coupon?.starts_on ?? '', ends_on: coupon?.ends_on ?? '', active: Boolean(coupon?.active ?? true) });
    return <Admin title="Mã giảm giá">
        <div className="admin-columns">
            <div className="panel"><h2>Danh sách mã</h2><Table headers={['Mã', 'Mức giảm', 'Sử dụng', 'Trạng thái']} rows={coupons.data.map(item => [item.id, <Link className="table-link" href={url + '/' + item.id}>{item.code}</Link>, item.kind === 'percent' ? item.amount + '%' : money(item.amount), item.used + ' / ' + (item.usage_limit ?? '∞'), item.active ? 'Đang bật' : 'Đã tắt'])} /><Pagination page={coupons} /></div>
            <form className="panel form-stack" onSubmit={e => { e.preventDefault(); form.post(url + (coupon ? '/' + coupon.id : ''), { preserveState: 'errors' }); }}>
                <div className="section-head"><h2>{coupon ? 'Sửa mã' : 'Tạo mã'}</h2>{coupon && <Link className="subtle-link" href={url}>Tạo mã mới</Link>}</div>
                <Text form={form} field="code" label="Mã giảm giá" required maxLength={32} />
                <div className="two-fields">
                    <Field label="Loại giảm"><select value={form.data.kind} onChange={e => form.setData('kind', e.target.value)}><option value="percent">Phần trăm</option><option value="fixed">Số tiền</option></select></Field>
                    <Text form={form} field="amount" label={form.data.kind === 'percent' ? 'Giảm (%)' : 'Giảm (₫)'} type="number" required min="1" max={form.data.kind === 'percent' ? '100' : '100000000'} />
                </div>
                <Text form={form} field="minimum" label="Giá trị đơn tối thiểu (₫)" type="number" min="0" required /><Text form={form} field="usage_limit" label="Giới hạn lượt dùng" type="number" min="1" />
                <div className="two-fields"><Text form={form} field="starts_on" label="Bắt đầu" type="date" /><Text form={form} field="ends_on" label="Hết hạn" type="date" /></div>
                <Flag form={form} field="active" label="Cho phép sử dụng" /><ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>Lưu mã giảm</button>
            </form>
        </div>
    </Admin>;
}

export function AdminUsers({ selected, users }) {
    const url = root + '/nhan-su';
    const form = useForm({ name: selected?.name ?? '', email: selected?.email ?? '', password: '', role: selected?.role ?? 'staff', active: Boolean(selected?.active ?? true), can_reconcile: Boolean(selected?.can_reconcile) });
    return <Admin title="Tài khoản nội bộ">
        <div className="admin-columns"><div className="panel"><h2>Nhân sự</h2><Table headers={['Tên', 'Quyền', 'Trạng thái']} rows={users.data.map(user => [user.id,
            <Link className="table-link" href={url + '/' + user.id}>{user.name}<small>{user.email}</small></Link>, user.role === 'admin' ? 'Quản trị viên' : user.can_reconcile ? 'Nhân viên · Đối soát' : 'Nhân viên', user.active ? 'Hoạt động' : 'Đã khóa'])} /><Pagination page={users} /></div>
            <form className="panel form-stack" onSubmit={e => { e.preventDefault(); form.post(url + (selected ? '/' + selected.id : ''), { preserveState: 'errors', onFinish: () => form.reset('password') }); }}>
                <div className="section-head"><h2>{selected ? 'Sửa tài khoản' : 'Tạo tài khoản'}</h2>{selected && <Link className="subtle-link" href={url}>Tạo mới</Link>}</div>
                {selected && <p className="muted">{selected.mfa_ready ? 'Đã cấp khóa xác thực hai bước.' : 'Cấp khóa xác thực hai bước bằng lệnh bakery:mfa trên máy chủ.'}</p>}
                <Text form={form} field="name" label="Họ và tên" required /><Text form={form} field="email" label="Email" type="email" required />
                <Text form={form} field="password" label={selected ? 'Mật khẩu mới (tùy chọn)' : 'Mật khẩu'} type="password" autoComplete="new-password" required={!selected} minLength={10} maxLength={72} />
                <Field label="Vai trò"><select value={form.data.role} onChange={e => form.setData('role', e.target.value)}><option value="staff">Nhân viên</option><option value="admin">Quản trị viên</option></select></Field>
                {form.data.role === 'staff' && <Flag form={form} field="can_reconcile" label="Được đối soát chuyển khoản và ghi nhận hoàn tiền" />}
                <Flag form={form} field="active" label="Cho phép đăng nhập" /><ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>Lưu tài khoản</button>
            </form>
        </div>
    </Admin>;
}
