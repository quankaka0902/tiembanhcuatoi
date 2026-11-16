import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Banknote, CalendarClock, Check, Clipboard, CreditCard, MapPin, Search, ShoppingBag, ShoppingCart, Store, Trash2, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Breadcrumb, ButtonLink, dateTime, Empty, ErrorList, Field, Image, Layout, money, Pagination, Pill } from './ui';

function CartLine({ line }) {
    const form = useForm({ variant_id: line.variant_id, quantity: line.quantity, message: line.message ?? '', note: line.note ?? '' });
    const variant = line.choices.find(choice => Number(choice.id) === Number(form.data.variant_id));
    return <article className="panel cart-line">
        <Image src={line.image} alt="" />
        <form className="form-stack" onSubmit={e => { e.preventDefault(); form.patch('/gio-hang/' + line.key, { preserveScroll: true }); }}>
            <div className="section-head"><h3>{line.slug ? <Link href={'/banh/' + line.slug}>{line.product_name}</Link> : line.product_name}</h3><strong>{money(line.subtotal)}</strong></div>
            {!line.available && <p className="warning">Bánh hoặc số lượng này không còn khả dụng. Hãy đổi lựa chọn hoặc bỏ khỏi giỏ.</p>}
            <div className="two-fields">
                <Field label="Phiên bản bánh"><select value={form.data.variant_id} onChange={e => form.setData('variant_id', e.target.value)}>
                    {!line.choices.some(choice => Number(choice.id) === Number(line.variant_id)) && <option value={line.variant_id}>{line.variant_name || 'Ngừng bán'}</option>}
                    {line.choices.map(choice => <option key={choice.id} value={choice.id}>{choice.name} – {money(choice.price)}{choice.stock <= 0 ? ' (Hết hàng)' : ''}</option>)}
                </select></Field>
                <Field label="Số lượng"><input type="number" min="1" max={Math.min(30, variant?.stock || 1)} required value={form.data.quantity} onChange={e => form.setData('quantity', e.target.value)} /></Field>
            </div>
            {line.customizable && <Field label="Lời chúc" hint={line.message_fee ? 'Phụ phí ' + money(line.message_fee) + ' mỗi bánh' : undefined}><input maxLength={60} value={form.data.message} onChange={e => form.setData('message', e.target.value)} /></Field>}
            <Field label="Ghi chú"><input maxLength={250} value={form.data.note} onChange={e => form.setData('note', e.target.value)} /></Field>
            <ErrorList errors={form.errors} />
            <div className="row-actions"><button className="button secondary compact" disabled={!form.isDirty || form.processing}>{form.processing ? 'Đang lưu…' : 'Cập nhật'}</button>
                <Link className="text-action danger" as="button" method="delete" href={'/gio-hang/' + line.key} preserveScroll><Trash2 size={16} /> Bỏ bánh</Link></div>
        </form>
    </article>;
}

export function Totals({ subtotal, discount, delivery_fee: deliveryFee, total }) {
    return <dl className="totals">
        <div><dt>Tạm tính</dt><dd>{money(subtotal)}</dd></div>
        <div><dt>Giảm giá</dt><dd>{discount == null ? '—' : discount > 0 ? '−' + money(discount) : money(0)}</dd></div>
        <div><dt>Phí giao hàng</dt><dd>{deliveryFee == null ? 'Chưa tính' : deliveryFee ? money(deliveryFee) : 'Miễn phí'}</dd></div>
        <div className="grand-total"><dt>Tổng thanh toán</dt><dd>{total == null ? 'Chưa tính' : money(total)}</dd></div>
    </dl>;
}

export function Cart({ lines }) {
    const { shop } = usePage().props;
    const subtotal = lines.reduce((sum, line) => sum + Number(line.subtotal), 0);
    return <Layout title="Giỏ hàng"><div className="wrap page">
        <Breadcrumb><span>Giỏ hàng</span></Breadcrumb><div className="page-heading"><h1>Giỏ hàng</h1></div>
        {lines.length ? <div className="order-grid"><div className="form-stack">{lines.map(line => <CartLine key={line.key + JSON.stringify([line.variant_id, line.quantity, line.message, line.note])} line={line} />)}</div>
            <aside className="panel order-summary"><h2>Tóm tắt đơn</h2><Totals subtotal={subtotal} />
                <p className="muted">Mã giảm giá và phí giao được tính khi bạn chọn cách nhận bánh.</p>
                {shop.accepting_orders && lines.every(line => line.available) && <ButtonLink href="/dat-banh">Tiến hành đặt bánh <ArrowRight size={18} /></ButtonLink>}
                <Link className="subtle-link" href="/thuc-don"><ArrowLeft size={16} />Chọn thêm bánh</Link>
            </aside>
        </div> : <Empty title="Giỏ bánh đang trống" text="Chọn món bạn yêu thích từ thực đơn của tiệm." href="/thuc-don" label="Xem thực đơn" />}
    </div></Layout>;
}

export function Checkout({ lines, slots, regions, bankEnabled, keyToken, policy, privacy }) {
    const { auth, shop } = usePage().props;
    const subtotal = lines.reduce((sum, line) => sum + Number(line.subtotal), 0);
    const form = useForm({ key: keyToken, name: auth?.name ?? '', email: auth?.email ?? '', phone: '', fulfillment: 'pickup', region: '', address: '', slot: '', method: 'cod', coupon: '' });
    const { fulfillment, region } = form.data;
    const selectedSlot = slots.includes(form.data.slot) ? form.data.slot : '';
    const [appliedCode, setAppliedCode] = useState('');
    const [quoteResult, setQuoteResult] = useState(null);
    const quoteKey = JSON.stringify({ fulfillment, region, coupon: appliedCode });
    const waitingForRegion = fulfillment === 'delivery' && !region;
    const displayed = quoteResult?.key === quoteKey ? quoteResult : null;

    useEffect(() => {
        if (waitingForRegion || !shop.accepting_orders) return;
        const abort = new AbortController();
        const cookie = document.cookie.split('; ').find(entry => entry.startsWith('XSRF-TOKEN='));
        fetch('/dat-banh/bao-gia', {
            method: 'POST', signal: abort.signal,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.slice(11) ?? '') },
            body: quoteKey,
        }).then(async response => {
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Không lấy được báo giá.');
            return data;
        }).then(data => setQuoteResult({ key: quoteKey, data })).catch(error => {
            if (!abort.signal.aborted) setQuoteResult({ key: quoteKey, error: error.message });
        });
        return () => abort.abort();
    }, [quoteKey, waitingForRegion, shop.accepting_orders]);

    function place(event) {
        event.preventDefault();
        form.transform(fields => ({ ...fields, coupon: appliedCode }));
        form.post('/dat-banh', { preserveScroll: true });
    }

    return <Layout title="Đặt bánh"><div className="wrap page">
        <Breadcrumb><Link href="/gio-hang">Giỏ hàng</Link><span aria-hidden="true">/</span><span>Đặt bánh</span></Breadcrumb>
        <div className="page-heading"><h1>Đặt bánh</h1><p>Chọn cách nhận bánh và giờ phù hợp với bạn.</p></div>
        <form onSubmit={place} className="order-grid">
            <div className="form-stack">
                <section className="panel form-stack"><h2>Thông tin liên hệ</h2>
                    <Field label="Họ và tên" error={form.errors.name}><input autoComplete="name" required value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></Field>
                    <div className="two-fields">
                        <Field label="Số điện thoại" error={form.errors.phone}><input type="tel" autoComplete="tel" required value={form.data.phone} onChange={e => form.setData('phone', e.target.value)} /></Field>
                        <Field label="Email nhận mã đơn" error={form.errors.email}><input type="email" autoComplete="email" required value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></Field>
                    </div>
                    {privacy && <details className="fold"><summary>Tiệm sử dụng thông tin của bạn thế nào?</summary><p className="policy-text">{privacy}</p></details>}
                </section>
                <section className="panel form-stack"><h2>Cách nhận bánh</h2>
                    <div className="choices">
                        <label><input type="radio" name="fulfillment" value="pickup" checked={fulfillment === 'pickup'} onChange={e => form.setData('fulfillment', e.target.value)} /><Store size={19} />Nhận tại tiệm</label>
                        <label><input type="radio" name="fulfillment" value="delivery" checked={fulfillment === 'delivery'} onChange={e => form.setData('fulfillment', e.target.value)} disabled={!regions.length} /><Truck size={19} />Giao tận nơi</label>
                    </div>
                    {fulfillment === 'delivery' && <div className="two-fields">
                        <Field label="Khu vực giao" error={form.errors.region}><select required value={region} onChange={e => form.setData('region', e.target.value)}><option value="">Chọn khu vực</option>{regions.map(area => <option key={area.key} value={area.key}>{area.name}</option>)}</select></Field>
                        <Field label="Địa chỉ giao bánh" error={form.errors.address}><input autoComplete="street-address" required value={form.data.address} onChange={e => form.setData('address', e.target.value)} /></Field>
                    </div>}
                    <Field label="Giờ nhận bánh" error={form.errors.slot}><select required value={selectedSlot} onChange={e => { form.setData('slot', e.target.value); form.clearErrors('slot'); }}>
                        <option value="">Chọn một khung giờ</option>{slots.map(slot => <option value={slot} key={slot}>{dateTime(slot)}</option>)}
                    </select></Field>
                    {!slots.length && <p className="warning">Tiệm chưa có lịch nhận phù hợp trong 14 ngày tới. Vui lòng liên hệ để đặt bánh.</p>}
                </section>
                <section className="panel form-stack"><h2>Thanh toán</h2><div className="choices">
                    <label><input type="radio" name="method" value="cod" checked={form.data.method === 'cod'} onChange={e => form.setData('method', e.target.value)} /><Banknote size={19} />Khi nhận bánh</label>
                    {bankEnabled && <label><input type="radio" name="method" value="bank" checked={form.data.method === 'bank'} onChange={e => form.setData('method', e.target.value)} /><CreditCard size={19} />Chuyển khoản</label>}
                </div>{form.data.method === 'bank' && <p className="muted">Bạn sẽ thấy thông tin ngân hàng và nội dung chuyển khoản sau khi đặt đơn.</p>}</section>
            </div>
            <aside className="panel order-summary">
                <h2>Đơn bánh của bạn</h2>
                <ul className="summary-lines">{lines.map(line => <li key={line.key}><span>{line.quantity} × {line.product_name}<small>{line.variant_name}</small></span><strong>{money(line.subtotal)}</strong></li>)}</ul>
                <div className="coupon-row"><Field label="Mã giảm giá" error={form.errors.coupon}><input maxLength={32} value={form.data.coupon} onChange={e => form.setData('coupon', e.target.value)} placeholder="Nhập mã nếu có" /></Field><button type="button" className="button secondary compact" onClick={() => setAppliedCode(form.data.coupon.trim())}>Áp dụng</button></div>
                <Totals subtotal={subtotal} {...displayed?.data} />
                {shop.accepting_orders && !waitingForRegion && !displayed && <p className="muted" role="status">Đang tính tổng tiền…</p>}
                {displayed?.error && <p className="warning" role="alert">{displayed.error} <button type="button" className="text-action" onClick={() => { setAppliedCode(''); form.setData('coupon', ''); }}>Bỏ mã</button></p>}
                {policy && <details className="fold"><summary>Chính sách đặt bánh</summary><p className="policy-text">{policy}</p></details>}
                <ErrorList errors={form.errors} />
                <button className="button" disabled={!shop.accepting_orders || form.processing || !displayed?.data || !selectedSlot}><ShoppingBag size={19} />{form.processing ? 'Đang đặt bánh…' : 'Xác nhận đặt bánh'}</button>
            </aside>
        </form>
    </div></Layout>;
}

export function OrderPolicy({ policy, privacy }) {
    const { shop } = usePage().props;
    return <Layout title="Chính sách đặt bánh"><div className="wrap page">
        <Breadcrumb><span>Chính sách đặt bánh</span></Breadcrumb>
        <div className="page-heading"><h1>Chính sách đặt bánh</h1></div>
        <section className="panel form-stack">
            <p className="policy-text">{policy || 'Vui lòng liên hệ tiệm để hỏi về đổi giờ nhận, hủy đơn và hoàn tiền trước khi đặt bánh.'}</p>
            {shop.phone && <p><a className="subtle-link" href={`tel:${shop.phone.replace(/[^+\d]/g, '')}`}>Gọi tiệm {shop.phone}</a></p>}
            {shop.email && <p><a className="subtle-link" href={`mailto:${shop.email}`}>{shop.email}</a></p>}
        </section>
        {privacy && <section className="panel form-stack"><h2>Xử lý dữ liệu khách hàng</h2><p className="policy-text">{privacy}</p></section>}
    </div></Layout>;
}

export function Tracking() {
    const form = useForm({ link: '' });
    const recovery = useForm({ code: '', email: '' });
    return <Layout title="Tra cứu đơn"><div className="wrap page"><div className="panel auth-card">
        <span className="category-icon"><Search size={29} /></span><h1>Tra cứu đơn bánh</h1><p>Dán liên kết tra cứu trong email xác nhận để xem tiến độ đơn.</p>
        <form className="form-stack" onSubmit={e => { e.preventDefault(); form.post('/tra-cuu'); }}>
            <Field label="Liên kết tra cứu" error={form.errors.link}><input required maxLength={400} value={form.data.link} onChange={e => form.setData('link', e.target.value)} placeholder="Dán liên kết tại đây" /></Field>
            <button className="button" disabled={form.processing}><Search size={17} />{form.processing ? 'Đang tra cứu…' : 'Tra cứu đơn'}</button>
        </form>
        <section className="form-stack tracking-recovery" aria-labelledby="recovery-heading">
            <h2 id="recovery-heading">Gửi lại liên kết tra cứu</h2>
            <form className="form-stack" onSubmit={e => {
                e.preventDefault();
                recovery.post('/tra-cuu/gui-lai', { onSuccess: () => recovery.reset() });
            }}>
                <Field label="Mã đơn" error={recovery.errors.code}><input required maxLength={22} value={recovery.data.code} onChange={e => recovery.setData('code', e.target.value)} /></Field>
                <Field label="Email đã đặt đơn" error={recovery.errors.email}><input required type="email" autoComplete="email" maxLength={254} value={recovery.data.email} onChange={e => recovery.setData('email', e.target.value)} /></Field>
                <button className="button secondary" disabled={recovery.processing}>{recovery.processing ? 'Đang gửi yêu cầu…' : 'Gửi lại liên kết'}</button>
            </form>
        </section>
    </div></div></Layout>;
}

export function OrderItems({ items }) {
    return <ul className="summary-lines">{items.map(item => <li key={item.id}><div><strong>{item.quantity} × {item.product_name}</strong><small>{item.variant_name}{item.option_fee ? ' · Phí lời chúc ' + money(item.option_fee) + '/bánh' : ''}</small>{item.message && <small>Lời chúc: {item.message}</small>}{item.note && <small>Ghi chú: {item.note}</small>}</div><strong>{money((Number(item.unit_price) + Number(item.option_fee)) * item.quantity)}</strong></li>)}</ul>;
}

export function OrderEvents({ events, statuses, payments }) {
    return <ol className="timeline">{events.map((event, i) => <li key={event.id ?? i}><span></span><div>
        <strong>{event.kind === 'schedule' ? 'Đổi giờ sang ' + dateTime(event.value) : (event.kind === 'payment' ? payments : statuses)[event.value]}</strong>
        <small>{dateTime(event.created_at)}{event.actor ? ' · ' + event.actor : ''}</small>{event.note && <p>{event.note}</p>}
    </div></li>)}</ol>;
}

export function Order({ order, statuses, payments, trackingLink, canReorder }) {
    const { shop } = usePage().props;
    const [copied, setCopied] = useState(false);
    const reorder = useForm({});
    return <Layout title={'Đơn ' + order.code}><div className="wrap page">
        <Breadcrumb>{canReorder ? <Link href="/don-cua-toi">Đơn của tôi</Link> : <Link href="/tra-cuu">Tra cứu đơn</Link>}<span aria-hidden="true">/</span><span>{order.code}</span></Breadcrumb>
        <div className="page-heading"><h1>Đơn bánh {order.code}</h1><div className="row-actions"><Pill value={order.status} labels={statuses} /><Pill value={order.payment} labels={payments} /></div></div>
        <div className="order-grid">
            <div className="form-stack">
                {order.bank_snapshot && order.payment === 'unpaid' && order.status !== 'cancelled' && <section className="panel form-stack bank-panel"><h2>Thông tin chuyển khoản</h2>
                    {order.bank_snapshot.demo && <p className="warning">Thông tin chuyển khoản là dữ liệu mẫu. Vui lòng không chuyển tiền.</p>}
                    <dl className="totals"><div><dt>Ngân hàng</dt><dd>{order.bank_snapshot.name}</dd></div><div><dt>Số tài khoản</dt><dd>{order.bank_snapshot.account}</dd></div>
                        <div><dt>Chủ tài khoản</dt><dd>{order.bank_snapshot.holder}</dd></div><div><dt>Số tiền</dt><dd><strong>{money(order.total)}</strong></dd></div><div><dt>Nội dung chuyển khoản</dt><dd><strong>{order.code}</strong></dd></div></dl>
                    {order.bank_due_at && <p className="muted">Hạn chuyển khoản · {dateTime(order.bank_due_at)}</p>}
                    <p className="muted">Tiệm cập nhật thanh toán sau khi nhân viên đối soát.</p>
                </section>}
                <section className="panel form-stack"><h2>Cách nhận bánh</h2><p className="icon-text"><MapPin size={19} />{order.fulfillment === 'pickup' ? 'Nhận tại tiệm · ' + shop.address : (order.address || 'Giao tận nơi') + ' · ' + order.region_label}</p><p className="icon-text"><CalendarClock size={19} />{dateTime(order.scheduled_at)}</p></section>
                <section className="panel form-stack"><h2>Lịch sử đơn</h2><OrderEvents events={order.events} statuses={statuses} payments={payments} /></section>
            </div>
            <aside className="panel order-summary"><h2>Chi tiết thanh toán</h2><OrderItems items={order.items} /><Totals {...order} />
                {canReorder && <form className="form-stack" onSubmit={e => {
                    e.preventDefault();
                    reorder.post('/don-cua-toi/' + order.code + '/mua-lai');
                }}>
                    <button className="button" disabled={reorder.processing}><ShoppingCart size={17} />{reorder.processing ? 'Đang thêm…' : 'Mua lại bánh'}</button>
                    <small className="muted">Thêm bánh còn bán theo giá và tồn hiện tại. Bạn có thể nhập lời chúc và ghi chú mới trong giỏ.</small>
                </form>}
                <Field label="Liên kết tra cứu"><input readOnly value={trackingLink} onFocus={e => e.target.select()} /></Field>
                {navigator.clipboard && <button className="button secondary" type="button" onClick={async () => { try { await navigator.clipboard.writeText(trackingLink); setCopied(true); } catch { setCopied(false); } }}>{copied ? <Check size={17} /> : <Clipboard size={17} />}{copied ? 'Đã sao chép' : 'Sao chép liên kết'}</button>}
            </aside>
        </div>
    </div></Layout>;
}

export function History({ orders, statuses }) {
    return <Layout title="Đơn của tôi"><div className="wrap page">
        <Breadcrumb><span>Đơn của tôi</span></Breadcrumb><div className="page-heading"><h1>Đơn của tôi</h1></div>
        {orders.data.length ? <div className="panel"><ul className="summary-lines">{orders.data.map(order => <li key={order.code}>
            <Link href={'/don-cua-toi/' + order.code}><strong>{order.code}</strong><small>{dateTime(order.created_at)}</small></Link><Pill value={order.status} labels={statuses} /><strong>{money(order.total)}</strong>
        </li>)}</ul><Pagination page={orders} /></div> : <Empty title="Bạn chưa đặt bánh" href="/thuc-don" label="Xem thực đơn" />}
    </div></Layout>;
}
