import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, Banknote, CakeSlice, CalendarDays, ChartNoAxesCombined, ClipboardList, Download, LayoutDashboard, Package, Plus, Settings, Ticket, Users } from 'lucide-react';
import { Brand, ButtonLink, date as formatDate, dateTime, ErrorList, Field, Image, money, Pagination, Pill } from './ui';
import { OrderEvents, OrderItems, Totals } from './checkout';

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

export function AdminInbox({ filter, newCount, overdueCount, refundCount, orders, intake }) {
    const { auth } = usePage().props;
    const isAdmin = auth.role === 'admin';
    const tabs = [
        ['new', 'Chưa xác nhận', newCount, 'Giờ đặt', 'created_at', 'Hiện không có đơn chờ xác nhận.'],
        ['overdue', 'Chuyển khoản quá hạn', overdueCount, 'Hạn chuyển khoản', 'bank_due_at', 'Hiện không có chuyển khoản quá hạn.'],
        ['refund', 'Chờ hoàn tiền', refundCount, 'Giờ hủy', 'updated_at', 'Hiện không có đơn chờ hoàn tiền.'],
    ];
    const [, , , dateLabel, dateField, empty] = tabs.find(([key]) => key === filter);
    return <Admin title="Đơn cần xử lý"
        action={isAdmin && <ButtonLink href={mailUrl} secondary>Email đơn hàng</ButtonLink>}>
        {!intake.accepting_orders && <p className="warning" role="status">Tiệm đang tạm ngừng nhận đơn mới.</p>}
        <section className="panel form-stack">
            <nav className="row-actions" aria-label="Loại đơn cần xử lý">
                {tabs.map(([key, label, count]) => <Link key={key} className={'button compact' + (key === filter ? '' : ' secondary')}
                    aria-current={key === filter ? 'page' : undefined} href={inboxUrl + (key === 'new' ? '' : '?filter=' + key)}>{label} ({count})</Link>)}
            </nav>
            <Table headers={['Mã đơn', 'Thanh toán', 'Tổng tiền', 'Giờ giao hoặc nhận', dateLabel]}
                empty={empty}
                rows={orders.data.map(order => [order.id,
                    <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>,
                    order.method === 'bank' ? 'Chuyển khoản' : 'COD', money(order.total), dateTime(order.scheduled_at),
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
                    <><strong>{item.stock_after > item.stock_before ? '+' : ''}{item.stock_after - item.stock_before}</strong><small>{item.stock_before} → {item.stock_after}</small></>,
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
            <Table headers={['Mã đơn', 'Số tiền', 'Giờ đặt', 'Giao hoặc nhận', 'Hạn chuyển khoản']}
                empty={filter === 'overdue' ? 'Chưa có đơn quá hạn.' : 'Chưa có đơn chuyển khoản chờ đối soát.'}
                rows={orders.data.map(order => [
                    order.id, <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>,
                    money(order.total), dateTime(order.created_at), dateTime(order.scheduled_at),
                    <div className="row-actions">{dateTime(order.bank_due_at)}{order.overdue && <span className="pill state-pending">Quá hạn</span>}</div>,
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
            <button className="button secondary" disabled={form.processing}>Lưu giờ mới</button>
        </form> : <p className="muted">Hiện chưa có khung giờ khác còn chỗ.</p>}
    </section>;
}

export function AdminOrder({ order, statuses, payments, next, reconcile, rescheduleSlots }) {
    const transition = useForm({ kind: 'status', value: '', note: '' });
    const payment = useForm({ kind: 'payment', value: '', note: '' });
    const url = root + '/don-hang/' + order.id;
    const canPay = order.payment === 'unpaid' && order.status !== 'cancelled' && (order.method === 'bank' ? reconcile : ['ready', 'shipping', 'completed'].includes(order.status));
    const canRefund = reconcile && order.payment === 'paid' && order.status === 'cancelled';
    return <Admin title={'Đơn ' + order.code} action={<ButtonLink href={root + '/don-hang'} secondary><ArrowLeft size={17} />Danh sách đơn</ButtonLink>}>
        <div className="row-actions admin-pills"><Pill value={order.status} labels={statuses} /><Pill value={order.payment} labels={payments} /></div>
        <div className="admin-columns">
            <div className="form-stack">
                <section className="panel form-stack"><h2>Khách hàng</h2><strong>{order.name}</strong><p>{order.email} · {order.phone}</p>
                    <p>{order.fulfillment === 'delivery' ? 'Giao đến ' + order.address + ' · ' + order.region_label : 'Nhận tại tiệm'}</p><p>Giờ nhận {dateTime(order.scheduled_at)}</p>
                </section>
                <section className="panel form-stack"><h2>Bánh đã đặt</h2><OrderItems items={order.items} /><Totals {...order} />{order.coupon_code && <p>Mã đã sử dụng <b>{order.coupon_code}</b></p>}</section>
                <section className="panel form-stack"><h2>Lịch sử thay đổi</h2><OrderEvents events={order.events} statuses={statuses} payments={payments} /></section>
            </div>
            <div className="form-stack">
                <section className="panel form-stack"><h2>Xử lý đơn</h2>
                    {next.length ? <form className="form-stack" onSubmit={e => { e.preventDefault(); transition.patch(url, { preserveScroll: true, onSuccess: () => transition.reset() }); }}>
                        <Field label="Trạng thái tiếp theo"><select required value={transition.data.value} onChange={e => transition.setData('value', e.target.value)}>
                            <option value="">Chọn trạng thái</option>{next.map(value => <option key={value} value={value} disabled={value === 'completed' && order.payment !== 'paid'}>{statuses[value]}</option>)}</select></Field>
                        {next.includes('completed') && order.payment !== 'paid' && <p className="muted">Cần xác nhận thanh toán trước khi hoàn tất.</p>}
                        <Field label="Ghi chú nội bộ"><textarea rows="2" maxLength={250} value={transition.data.note} onChange={e => transition.setData('note', e.target.value)} /></Field>
                        <ErrorList errors={transition.errors} /><button className="button" disabled={transition.processing}>Cập nhật trạng thái</button>
                    </form> : <p className="muted">Đơn đã kết thúc.</p>}
                </section>
                {rescheduleSlots && <RescheduleOrder key={order.scheduled_at} schedule={order.scheduled_at} slots={rescheduleSlots} url={url} />}
                <section className="panel form-stack"><h2>Thanh toán</h2>
                    <p>{order.method === 'bank' ? 'Chuyển khoản thủ công' : 'Thanh toán khi nhận bánh'}</p>
                    {order.bank_due_at && order.payment === 'unpaid' && order.status !== 'cancelled' && <p className="muted">Hạn chuyển khoản · {dateTime(order.bank_due_at)}</p>}
                    {order.bank_snapshot && <p className="muted">{order.bank_snapshot.demo ? 'Dữ liệu mẫu · ' : ''}Nội dung {order.code} · {money(order.total)}</p>}
                    {(canPay || canRefund) && <form className="form-stack" onSubmit={e => {
                        e.preventDefault();
                        payment.transform(data => ({ ...data, value: canPay ? 'paid' : 'refunded' }));
                        payment.patch(url, { preserveScroll: true, onSuccess: () => payment.reset() });
                    }}>
                        <Field label="Ghi chú thanh toán"><input maxLength={250} value={payment.data.note} onChange={e => payment.setData('note', e.target.value)} /></Field>
                        <ErrorList errors={payment.errors} /><button className="button secondary" disabled={payment.processing}>{canPay ? 'Xác nhận đã thanh toán' : 'Ghi nhận đã hoàn tiền'}</button>
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
            <section className="panel"><h2>Danh sách bánh</h2><Table headers={['Tên bánh', 'Danh mục', 'Trạng thái']} rows={products.data.map(product => [product.id,
                <Link className="table-link" href={root + '/san-pham/' + product.id}>{product.name}</Link>, product.category, product.active ? 'Đang bán' : 'Đã ẩn'])} /><Pagination page={products} /></section>
            <section className="panel form-stack"><h2>Danh mục</h2>
                {categories.map(category => <details className="fold" key={category.id}><summary>{category.name}</summary><CategoryForm category={category} /></details>)}
                <details className="fold"><summary>Thêm danh mục</summary><CategoryForm /></details>
            </section>
        </div>
    </Admin>;
}

const freshVariant = () => ({ name: '', price: '', stock: '', active: true });

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
        message_fee: product?.message_fee ?? 0, active: Boolean(product?.active ?? true), featured: Boolean(product?.featured), customizable: Boolean(product?.customizable),
        variants: product?.variants.map(({ id, name, price, stock, active }) => ({ id, name, price, stock, active: Boolean(active), expected_stock: stock })) ?? [freshVariant()],
        uploads: [], removed: [],
    });
    function variantChange(index, key, value) {
        form.setData('variants', form.data.variants.map((variant, i) => i === index ? { ...variant, [key]: value } : variant));
    }
    return <Admin title={product ? product.name : 'Thêm sản phẩm'} action={<div className="row-actions">
        {product && <ButtonLink href={inventoryUrl + '?product=' + product.id} secondary><Package size={17} />Nhật ký tồn kho</ButtonLink>}
        <ButtonLink href={root + '/san-pham'} secondary><ArrowLeft size={17} />Sản phẩm</ButtonLink>
    </div>}>
        <form className="form-stack" onSubmit={e => { e.preventDefault(); form.post(root + '/san-pham' + (product ? '/' + product.id : ''), { forceFormData: true, preserveState: 'errors' }); }}>
            <div className="admin-columns">
                <section className="panel form-stack"><h2>Thông tin bánh</h2><Text form={form} field="name" label="Tên bánh" required maxLength={150} />
                    <Field label="Danh mục" error={form.errors.category_id}><select required value={form.data.category_id} onChange={e => form.setData('category_id', e.target.value)}><option value="">Chọn danh mục</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select></Field>
                    <TextArea form={form} field="description" label="Mô tả" required maxLength={3000} />
                    <TextArea form={form} field="ingredients" label="Thành phần" maxLength={1200} />
                    <TextArea form={form} field="allergens" label="Cảnh báo dị ứng" maxLength={800} />
                </section>
                <section className="panel form-stack"><h2>Hình ảnh và hiển thị</h2>
                    {product?.images.length > 0 && <div className="admin-images">{product.images.map(src => <label key={src}><Image src={src} /><input type="checkbox" checked={form.data.removed.includes(src)} onChange={e => form.setData('removed', e.target.checked ? [...form.data.removed, src] : form.data.removed.filter(image => image !== src))} /> Bỏ ảnh</label>)}</div>}
                    <Field label="Tải ảnh lên" hint="Tối đa 5 ảnh JPEG, PNG hoặc WebP, 4 MB mỗi ảnh." error={form.errors.uploads}><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={e => form.setData('uploads', [...e.target.files])} /></Field>
                    <Text form={form} field="prep_minutes" label="Thời gian làm bánh (phút)" type="number" min="0" max="14400" required />
                    <Flag form={form} field="active" label="Hiển thị và bán trên cửa hàng" /><Flag form={form} field="featured" label="Hiển thị trong bánh nổi bật" /><Flag form={form} field="customizable" label="Cho phép ghi lời chúc" />
                    <Text form={form} field="message_fee" label="Phí ghi lời chúc (₫)" type="number" min="0" max="1000000" required />
                </section>
            </div>
            <section className="panel form-stack"><h2>Phiên bản bánh</h2>
                {form.data.variants.map((variant, index) => <div className="variant-edit" key={variant.id ?? index}>
                    {[['name', 'Tên phiên bản', 'text'], ['price', 'Giá (₫)', 'number'], ['stock', 'Tồn kho', 'number']].map(([field, label, type]) => <Field key={field} label={label} error={form.errors['variants.' + index + '.' + field]}><input required type={type} min={type === 'number' ? '0' : undefined} value={variant[field]} onChange={e => variantChange(index, field, e.target.value)} /></Field>)}
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
