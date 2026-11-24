import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Banknote, CalendarClock, Check, Clipboard, CreditCard, MapPin, Search, ShoppingBag, ShoppingCart, Store, Trash2, Truck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Breadcrumb, ButtonLink, date as formatDate, dateTime, Empty, ErrorList, Field, Image, Layout, money, Pagination, Pill } from './ui';

function CartLine({ line }) {
    const form = useForm({ variant_id: line.variant_id, quantity: line.quantity, message: line.message ?? '', note: line.note ?? '', accessories: (line.accessories ?? []).map(item => Number(item.id)) });
    const variant = line.choices.find(choice => Number(choice.id) === Number(form.data.variant_id));
    const choices = line.accessory_choices ?? [];
    const stockUnavailable = line.stock_available === undefined ? !line.available : !line.stock_available;
    const accessoryChoices = [...choices, ...(line.accessories ?? []).filter(item => !choices.some(choice => Number(choice.id) === Number(item.id))).map(item => ({ ...item, unavailable: true }))];
    const fieldIds = Object.fromEntries(['variant_id', 'quantity', 'message', 'note', 'accessories'].map(field => [field, `cart-${line.key}-${field}`]));
    const accessoryError = form.errors.accessories ?? Object.entries(form.errors).find(([field]) => field.startsWith('accessories.'))?.[1];
    Object.keys(form.errors).filter(field => field.startsWith('accessories.')).forEach(field => { fieldIds[field] = fieldIds.accessories; });
    return <article className="panel cart-line">
        <Image src={line.image} alt="" />
        <form className="form-stack" aria-label={`Cập nhật ${line.product_name}`} onSubmit={e => { e.preventDefault(); form.patch('/gio-hang/' + line.key, { preserveScroll: true, onError: () => focusCheckout(`cart-${line.key}-errors`) }); }}>
            <div className="section-head"><h3>{line.slug ? <Link href={'/banh/' + line.slug}>{line.product_name}</Link> : line.product_name}</h3><strong>{money(line.subtotal)}</strong></div>
            {stockUnavailable && <p className="warning">Bánh hoặc số lượng này không còn khả dụng. Hãy đổi lựa chọn hoặc bỏ khỏi giỏ.</p>}
            {line.accessories_available === false && <p className="warning" role="status">Một phụ kiện đã chọn không còn được cung cấp. Bấm Cập nhật để bỏ phụ kiện đó và xem lại giá; các lựa chọn còn lại được giữ.</p>}
            <div className="two-fields">
                <Field label="Phiên bản bánh" error={form.errors.variant_id}><select id={fieldIds.variant_id} value={form.data.variant_id} onChange={e => form.setData('variant_id', e.target.value)}>
                    {!line.choices.some(choice => Number(choice.id) === Number(line.variant_id)) && <option value={line.variant_id}>{line.variant_name || 'Ngừng bán'}</option>}
                    {line.choices.map(choice => <option key={choice.id} value={choice.id}>{choice.name} – {money(choice.price)}{choice.stock <= 0 ? ' (Hết hàng)' : ''}</option>)}
                </select></Field>
                <Field label="Số lượng" error={form.errors.quantity}><input id={fieldIds.quantity} type="number" min="1" max={Math.min(30, variant?.stock || 1)} required value={form.data.quantity} onChange={e => form.setData('quantity', e.target.value)} /></Field>
            </div>
            {line.customizable && <Field label="Lời chúc" error={form.errors.message} hint={line.message_fee ? 'Phụ phí ' + money(line.message_fee) + ' mỗi bánh' : undefined}><input id={fieldIds.message} maxLength={60} value={form.data.message} onChange={e => form.setData('message', e.target.value)} /></Field>}
            {accessoryChoices.length > 0 && <fieldset className="accessory-choices" id={fieldIds.accessories} tabIndex={-1} aria-invalid={accessoryError ? true : undefined} aria-describedby={accessoryError ? `${fieldIds.accessories}-error` : undefined}>
                <legend>Phụ kiện đi kèm mỗi bánh</legend>
                {accessoryChoices.map(choice => {
                    const checked = form.data.accessories.includes(Number(choice.id));
                    return <label key={choice.id} className="checkbox"><input type="checkbox" checked={checked} disabled={choice.unavailable && !checked} onChange={event => form.setData('accessories', event.target.checked ? [...form.data.accessories, Number(choice.id)] : form.data.accessories.filter(id => id !== Number(choice.id)))} />
                        <span>{choice.name} · {money(choice.price)}{choice.unavailable ? ' (Ngừng bán, hãy bỏ chọn)' : ''}</span>
                    </label>;
                })}
                {accessoryError && <small id={`${fieldIds.accessories}-error`} className="field-error">{accessoryError}</small>}
            </fieldset>}
            <Field label="Ghi chú" error={form.errors.note}><input id={fieldIds.note} maxLength={250} value={form.data.note} onChange={e => form.setData('note', e.target.value)} /></Field>
            {form.isDirty && <p className="muted" role="status">Bấm Cập nhật để lưu lựa chọn và xem lại giá từ tiệm.</p>}
            <ErrorList errors={form.errors} fieldIds={fieldIds} id={`cart-${line.key}-errors`} focusable />
            <div className="row-actions"><button className="button secondary compact" disabled={(!form.isDirty && line.accessories_available !== false) || form.processing}>{form.processing ? 'Đang lưu…' : 'Cập nhật'}</button>
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
        {lines.length ? <div className="order-grid"><div className="form-stack">{lines.map(line => <CartLine key={line.key + JSON.stringify([line.variant_id, line.quantity, line.message, line.note, line.accessories])} line={line} />)}</div>
            <aside className="panel order-summary"><h2>Tóm tắt đơn</h2><Totals subtotal={subtotal} />
                <p className="muted">Mã giảm giá và phí giao được tính khi bạn chọn cách nhận bánh.</p>
                {shop.accepting_orders && lines.every(line => line.available) && <ButtonLink href="/dat-banh">Tiến hành đặt bánh <ArrowRight size={18} /></ButtonLink>}
                <Link className="subtle-link" href="/thuc-don"><ArrowLeft size={16} />Chọn thêm bánh</Link>
            </aside>
        </div> : <Empty title="Giỏ bánh đang trống" text="Chọn món bạn yêu thích từ thực đơn của tiệm." href="/thuc-don" label="Xem thực đơn" />}
    </div></Layout>;
}

function focusCheckout(id) {
    requestAnimationFrame(() => {
        const target = document.getElementById(id);
        target?.focus();
        target?.scrollIntoView({ block: 'center' });
    });
}

const checkoutFieldIds = {
    name: 'checkout-name', phone: 'checkout-phone', email: 'checkout-email',
    fulfillment: 'checkout-fulfillment', region: 'checkout-region', address: 'checkout-address',
    slot: 'checkout-schedule', method: 'checkout-method', coupon: 'checkout-coupon',
    cart: 'checkout-items', quote_token: 'checkout-feedback', key: 'checkout-feedback',
};

function CheckoutSchedule({ slots, form, selectedSlot, refreshing, onRefresh, onChoose }) {
    const [day, setDay] = useState(() => form.data.slot.slice(0, 10));
    const days = [...new Set(slots.map(slot => slot.slice(0, 10)))];
    const selectedDay = days.includes(day) ? day : '';
    const times = selectedDay ? slots.filter(slot => slot.startsWith(selectedDay)) : [];
    return <fieldset className="checkout-schedule form-stack" id="checkout-schedule" tabIndex={-1} aria-busy={refreshing}>
        <legend>Ngày và giờ giao hoặc nhận</legend>
        {!days.length ? <p className="warning">Tiệm chưa có lịch nhận phù hợp trong 14 ngày tới. Bạn có thể chọn bánh khác hoặc liên hệ tiệm.</p> : <div className="two-fields">
            <Field label="Ngày giao hoặc nhận" error={!selectedDay ? form.errors.slot : undefined}>
                <select id="checkout-day" name="day" required value={selectedDay} onChange={event => { setDay(event.target.value); form.setData('slot', ''); form.clearErrors('slot'); onChoose(); }}>
                    <option value="">Chọn ngày</option>{days.map(value => <option key={value} value={value}>{formatDate(value)}</option>)}
                </select>
            </Field>
            <Field label="Giờ giao hoặc nhận" error={selectedDay ? form.errors.slot : undefined} hint={!selectedDay ? 'Chọn ngày trước để xem giờ còn chỗ.' : undefined}>
                <select id="checkout-slot" name="slot" required disabled={!selectedDay} value={selectedSlot} onChange={event => { form.setData('slot', event.target.value); form.clearErrors('slot'); onChoose(); }}>
                    <option value="">Chọn giờ</option>{times.map(slot => <option key={slot} value={slot}>{slot.slice(11)}</option>)}
                </select>
            </Field>
        </div>}
        <button type="button" className="text-action schedule-refresh" disabled={refreshing} onClick={onRefresh}>{refreshing ? 'Đang cập nhật lịch…' : 'Cập nhật giờ còn chỗ'}</button>
    </fieldset>;
}

export function Checkout({ lines, slots, regions, bankEnabled, keyToken, policy, privacy }) {
    const { auth, shop } = usePage().props;
    const subtotal = lines.reduce((sum, line) => sum + Number(line.subtotal), 0);
    const form = useForm({ key: keyToken, name: auth?.name ?? '', email: auth?.email ?? '', phone: '', fulfillment: 'pickup', region: '', address: '', slot: '', method: 'cod', coupon: '' });
    const { fulfillment, region } = form.data;
    const [appliedCode, setAppliedCode] = useState('');
    const [quoteAttempt, setQuoteAttempt] = useState(0);
    const [quoteResult, setQuoteResult] = useState(null);
    const [scheduleNotice, setScheduleNotice] = useState('');
    const [refreshingSlots, setRefreshingSlots] = useState(false);
    const [submissionError, setSubmissionError] = useState('');
    const submissionActive = useRef(false);
    const scheduleRefreshActive = useRef(false);
    const quoteKey = JSON.stringify({ fulfillment, region, coupon: appliedCode });
    const attemptKey = JSON.stringify([keyToken, quoteKey, quoteAttempt]);
    const waitingForRegion = fulfillment === 'delivery' && !region;
    const displayed = quoteResult?.key === attemptKey ? quoteResult : null;
    const availableSlots = Array.isArray(displayed?.data?.slots) ? displayed.data.slots : slots;
    const selectedSlot = availableSlots.includes(form.data.slot) ? form.data.slot : '';
    const staleSlot = Boolean(form.data.slot && !selectedSlot);
    const quoteErrors = displayed?.error && displayed.field ? { [displayed.field]: displayed.error } : {};
    const fieldErrors = { ...quoteErrors, ...form.errors };
    const canPlace = shop.accepting_orders && !form.processing && !refreshingSlots && displayed?.data && selectedSlot;
    const retryQuote = () => setQuoteAttempt(value => value + 1);

    useEffect(() => router.on('exception', event => {
        const exception = event.detail.exception;
        const networkFailure = !exception.response && ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'].includes(exception.code);
        if (submissionActive.current && networkFailure) {
            event.preventDefault();
            setSubmissionError('Chưa nhận được xác nhận đặt bánh. Thông tin vẫn được giữ. Bạn có thể thử gửi lại; nếu tiệm đã nhận yêu cầu, mã đơn sẽ được giữ nguyên.');
            focusCheckout('checkout-feedback');
        } else if (scheduleRefreshActive.current && networkFailure) {
            event.preventDefault();
            setScheduleNotice('Chưa cập nhật được lịch. Thông tin vẫn được giữ; hãy thử cập nhật lại khi có kết nối.');
            focusCheckout('checkout-schedule');
        }
    }), []);

    useEffect(() => {
        if (waitingForRegion || !shop.accepting_orders) return;
        const abort = new AbortController();
        const connectionError = 'Chưa tính được tổng tiền. Kiểm tra kết nối rồi thử lại.';
        const timeout = setTimeout(() => {
            abort.abort();
            setQuoteResult({ key: attemptKey, error: connectionError });
        }, 12000);
        const cookie = document.cookie.split('; ').find(entry => entry.startsWith('XSRF-TOKEN='));
        fetch('/dat-banh/bao-gia', {
            method: 'POST', signal: abort.signal,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.slice(11) ?? '') },
            body: quoteKey,
        }).then(async response => {
            if (response.status !== 422 && !response.ok) return { error: connectionError };
            const data = await response.json();
            if (response.ok && typeof data.quote_token === 'string') return { data };
            const field = ['coupon', 'cart', 'region', 'fulfillment'].find(name => data.errors?.[name]);
            const error = field && data.errors[field];
            return { field, error: (Array.isArray(error) ? error[0] : error) || connectionError };
        }).then(result => {
            if (!abort.signal.aborted) {
                setQuoteResult({ key: attemptKey, ...result });
                if (result.error && result.field === 'coupon') focusCheckout('checkout-coupon');
            }
        }).catch(() => {
            if (!abort.signal.aborted) setQuoteResult({ key: attemptKey, error: connectionError });
        }).finally(() => clearTimeout(timeout));
        return () => {
            clearTimeout(timeout);
            abort.abort();
        };
    }, [attemptKey, quoteKey, waitingForRegion, shop.accepting_orders]);

    function refreshSchedule() {
        if (refreshingSlots) return;
        scheduleRefreshActive.current = true;
        setRefreshingSlots(true);
        setScheduleNotice('Đang cập nhật lịch còn chỗ. Thông tin bạn đã nhập vẫn được giữ.');
        retryQuote();
        router.reload({
            only: ['slots'], preserveState: true, preserveScroll: true,
            onSuccess: () => {
                setScheduleNotice('Lịch nhận đã được cập nhật. Chọn lại giờ còn chỗ; thông tin liên hệ và cách thanh toán vẫn được giữ.');
                focusCheckout('checkout-schedule');
            },
            onError: () => setScheduleNotice('Chưa cập nhật được lịch. Thông tin vẫn được giữ; hãy thử cập nhật lại.'),
            onFinish: () => { setRefreshingSlots(false); scheduleRefreshActive.current = false; },
        });
    }

    function applyCoupon() {
        setAppliedCode(form.data.coupon.trim());
        form.clearErrors('coupon');
        retryQuote();
    }

    function place(event) {
        event.preventDefault();
        if (!canPlace) return;
        submissionActive.current = true;
        setSubmissionError('');
        form.transform(fields => ({ ...fields, coupon: appliedCode, quote_token: displayed.data.quote_token }));
        form.post('/dat-banh', {
            preserveScroll: true, preserveState: true,
            onError: errors => {
                if (errors.slot) {
                    refreshSchedule();
                } else if (errors.quote_token || errors.coupon || errors.cart) {
                    retryQuote();
                    focusCheckout('checkout-feedback');
                } else {
                    const field = Object.keys(errors).find(name => checkoutFieldIds[name]);
                    focusCheckout(checkoutFieldIds[field] ?? 'checkout-feedback');
                }
            },
            onFinish: () => { submissionActive.current = false; },
        });
    }

    const quoteStatus = !shop.accepting_orders ? 'Tiệm đang tạm ngừng nhận đơn mới.' : waitingForRegion ? 'Chọn khu vực để tính phí giao và tổng tiền.' :
        displayed?.error ? displayed.error : displayed?.data ? `Tổng thanh toán đã cập nhật: ${money(displayed.data.total)}${appliedCode ? ', mã ' + appliedCode : ''}.` : 'Đang tính tổng tiền từ tiệm…';

    return <Layout title="Đặt bánh"><div className="wrap page checkout-page">
        <Breadcrumb><Link href="/gio-hang">Giỏ hàng</Link><span aria-hidden="true">/</span><span>Đặt bánh</span></Breadcrumb>
        <div className="page-heading"><h1>Đặt bánh</h1><p>Chọn cách nhận bánh và giờ phù hợp với bạn.</p></div>
        <form onSubmit={place} className="order-grid checkout-grid" aria-label="Đặt bánh">
            <div className="form-stack checkout-fields">
                <section className="panel form-stack"><h2>Thông tin liên hệ</h2>
                    <Field label="Họ và tên" error={form.errors.name}><input id="checkout-name" name="name" autoComplete="name" maxLength={120} required value={form.data.name} onChange={e => { form.setData('name', e.target.value); form.clearErrors('name'); }} /></Field>
                    <div className="two-fields">
                        <Field label="Số điện thoại" error={form.errors.phone} hint="Bắt đầu bằng 0 hoặc +84."><input id="checkout-phone" name="phone" type="tel" inputMode="tel" autoComplete="tel" maxLength={20} required value={form.data.phone} onChange={e => { form.setData('phone', e.target.value); form.clearErrors('phone'); }} /></Field>
                        <Field label="Email nhận mã đơn" error={form.errors.email}><input id="checkout-email" name="email" type="email" autoComplete="email" maxLength={254} required value={form.data.email} onChange={e => { form.setData('email', e.target.value); form.clearErrors('email'); }} /></Field>
                    </div>
                    {privacy && <details className="fold"><summary>Tiệm sử dụng thông tin của bạn thế nào?</summary><p className="policy-text">{privacy}</p></details>}
                </section>
                <section className="panel form-stack"><h2>Cách nhận bánh</h2>
                    <fieldset className="checkout-choice-group" id="checkout-fulfillment" tabIndex={-1} aria-describedby={fieldErrors.fulfillment ? 'checkout-fulfillment-error' : undefined}>
                        <legend className="visually-hidden">Chọn cách nhận bánh</legend>
                        <div className="choices">
                            <label><input type="radio" name="fulfillment" value="pickup" checked={fulfillment === 'pickup'} onChange={e => { form.setData('fulfillment', e.target.value); form.clearErrors('fulfillment', 'region', 'address'); }} /><Store size={19} aria-hidden="true" />Nhận tại tiệm</label>
                            <label><input type="radio" name="fulfillment" value="delivery" checked={fulfillment === 'delivery'} onChange={e => { form.setData('fulfillment', e.target.value); form.clearErrors('fulfillment'); }} disabled={!regions.length} /><Truck size={19} aria-hidden="true" />Giao tận nơi</label>
                        </div>
                        {fieldErrors.fulfillment && <small className="field-error" id="checkout-fulfillment-error">{fieldErrors.fulfillment}</small>}
                    </fieldset>
                    {fulfillment === 'pickup' && shop.address && <p className="muted">Địa chỉ nhận bánh: {shop.address}</p>}
                    {!regions.length && <p className="muted">Tiệm hiện nhận bánh tại cửa hàng.</p>}
                    {fulfillment === 'delivery' && <div className="two-fields">
                        <Field label="Khu vực giao" error={fieldErrors.region}><select id="checkout-region" name="region" required value={region} onChange={e => { form.setData('region', e.target.value); form.clearErrors('region'); }}><option value="">Chọn khu vực</option>{regions.map(area => <option key={area.key} value={area.key}>{area.name}</option>)}</select></Field>
                        <Field label="Địa chỉ giao bánh" error={form.errors.address}><input id="checkout-address" name="address" autoComplete="street-address" maxLength={250} required value={form.data.address} onChange={e => { form.setData('address', e.target.value); form.clearErrors('address'); }} /></Field>
                    </div>}
                    <CheckoutSchedule slots={availableSlots} form={form} selectedSlot={selectedSlot} refreshing={refreshingSlots} onRefresh={refreshSchedule} onChoose={() => setScheduleNotice('')} />
                    <p className="schedule-status" role="status" aria-live="polite" aria-atomic="true">{scheduleNotice || (staleSlot ? 'Khung giờ đã chọn không còn chỗ hoặc không còn phù hợp. Hãy chọn lại giờ; các thông tin khác vẫn được giữ.' : selectedSlot ? 'Giờ đang chọn: ' + dateTime(selectedSlot) : '')}</p>
                </section>
                <section className="panel form-stack"><h2>Thanh toán</h2>
                    <fieldset className="checkout-choice-group" id="checkout-method" tabIndex={-1} aria-describedby={form.errors.method ? 'checkout-method-error' : undefined}>
                        <legend className="visually-hidden">Chọn phương thức thanh toán</legend><div className="choices">
                            <label><input type="radio" name="method" value="cod" checked={form.data.method === 'cod'} onChange={e => { form.setData('method', e.target.value); form.clearErrors('method'); }} /><Banknote size={19} aria-hidden="true" />Khi nhận bánh</label>
                            {bankEnabled && <label><input type="radio" name="method" value="bank" checked={form.data.method === 'bank'} onChange={e => { form.setData('method', e.target.value); form.clearErrors('method'); }} /><CreditCard size={19} aria-hidden="true" />Chuyển khoản</label>}
                        </div>
                        {form.errors.method && <small className="field-error" id="checkout-method-error">{form.errors.method}</small>}
                    </fieldset>
                    {form.data.method === 'bank' && <p className="muted">Bạn sẽ thấy thông tin ngân hàng, số tiền và nội dung chuyển khoản sau khi đặt đơn. Tiệm xác nhận sau khi đối soát.</p>}
                </section>
            </div>
            <aside className="panel order-summary">
                <h2>Đơn bánh của bạn</h2>
                <ul className="summary-lines" id="checkout-items" tabIndex={-1}>{lines.map(line => <li key={line.key}><span>{line.quantity} × {line.product_name}<small>{line.variant_name}</small>{(line.accessories ?? []).map(item => <small key={item.id}>Phụ kiện: {item.name}</small>)}</span><strong>{money(displayed?.data?.line_totals?.[line.key] ?? line.subtotal)}</strong></li>)}</ul>
                <div className="coupon-row"><Field label="Mã giảm giá" error={fieldErrors.coupon}><input id="checkout-coupon" name="coupon" maxLength={32} autoCapitalize="characters" value={form.data.coupon} onChange={e => { form.setData('coupon', e.target.value); form.clearErrors('coupon'); }} onKeyDown={event => {
                    if (event.key === 'Enter' && !event.nativeEvent.isComposing) { event.preventDefault(); applyCoupon(); }
                }} placeholder="Nhập mã nếu có" /></Field><button type="button" className="button secondary compact" onClick={applyCoupon}>Áp dụng</button></div>
                {appliedCode && displayed?.data && <p className="muted">Mã đang áp dụng: <strong>{appliedCode}</strong></p>}
                <div aria-busy={shop.accepting_orders && !waitingForRegion && !displayed}><Totals subtotal={displayed?.data?.subtotal ?? subtotal} {...displayed?.data} /></div>
                <div className="checkout-feedback" id="checkout-feedback" tabIndex={-1}>
                    <p className="quote-status" role="status" aria-live="polite" aria-atomic="true">{quoteStatus}</p>
                    {shop.accepting_orders && !waitingForRegion && !displayed && <span className="quote-skeleton" aria-hidden="true"></span>}
                    {displayed?.error && <div className="warning quote-warning"><p role="alert">{displayed.error}</p>
                        {displayed.field === 'cart' ? <Link className="text-action" href="/gio-hang">Mở giỏ hàng</Link> :
                            <button type="button" className="text-action" onClick={() => {
                                if (displayed.field === 'coupon') { setAppliedCode(''); form.setData('coupon', ''); form.clearErrors('coupon'); }
                                retryQuote();
                            }}>{displayed.field === 'coupon' ? 'Bỏ mã giảm giá' : 'Thử tính lại'}</button>}
                    </div>}
                    {submissionError && <p className="warning" role="alert">{submissionError}</p>}
                    <ErrorList errors={form.errors} fieldIds={checkoutFieldIds} id="checkout-errors" focusable />
                </div>
                {policy && <details className="fold"><summary>Chính sách đặt bánh</summary><p className="policy-text">{policy}</p></details>}
            </aside>
            <div className="checkout-actions">
                <p className="checkout-action-total"><small>Tổng thanh toán</small><strong>{displayed?.data ? money(displayed.data.total) : 'Chưa tính'}</strong></p>
                <button className="button" aria-describedby="checkout-submit-help" disabled={!canPlace}><ShoppingBag size={19} aria-hidden="true" />{form.processing ? 'Đang đặt bánh…' : submissionError ? 'Thử gửi lại đơn' : form.errors.quote_token ? 'Xác nhận giá mới' : 'Xác nhận đặt bánh'}</button>
            </div>
            <p id="checkout-submit-help" className="checkout-submit-help">{!shop.accepting_orders ? 'Tiệm đang tạm ngừng nhận đơn mới.' : !selectedSlot ? 'Chọn ngày và giờ nhận bánh để tiếp tục.' : !displayed?.data ? 'Cần tính được tổng tiền trước khi xác nhận.' : 'Kiểm tra bánh, giờ nhận và tổng tiền trước khi xác nhận.'}</p>
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
    return <ul className="summary-lines">{items.map(item => <li key={item.id}><div><strong>{item.quantity} × {item.product_name}</strong>
        <small>{item.variant_name}{item.option_fee ? ' · Phí lời chúc ' + money(item.option_fee) + '/bánh' : ''}</small>
        {(Array.isArray(item.accessories_snapshot) ? item.accessories_snapshot : []).map(accessory => <small key={accessory.id ?? accessory.name}>Phụ kiện: {accessory.name}{accessory.price ? ' · ' + money(accessory.price) + '/bánh' : ''}</small>)}
        {item.message && <small>Lời chúc: {item.message}</small>}{item.note && <small>Ghi chú: {item.note}</small>}
    </div><strong>{money(item.line_total ?? (Number(item.unit_price) + Number(item.option_fee) + Number(item.accessory_fee ?? 0)) * item.quantity)}</strong></li>)}</ul>;
}

function eventTitle(event, statuses, payments) {
    if (event.label) return event.label;
    if (event.kind === 'schedule') return 'Đổi giờ sang ' + dateTime(event.value);
    if (event.kind === 'payment') return payments[event.value] ?? event.value;
    if (event.kind === 'status') return statuses[event.value] ?? event.value;
    if (event.kind === 'request') return { reschedule: 'Khách yêu cầu đổi giờ', cancel: 'Khách yêu cầu hủy đơn', approved: 'Tiệm chấp nhận yêu cầu', rejected: 'Tiệm không chấp nhận yêu cầu' }[event.value] ?? 'Yêu cầu thay đổi đơn';
    const labels = {
        request: 'Yêu cầu thay đổi đơn', request_created: 'Khách gửi yêu cầu',
        request_approved: 'Đã duyệt yêu cầu', request_rejected: 'Đã từ chối yêu cầu',
        prepay_exception: 'Quản lý duyệt ngoại lệ trả trước', lead_time: 'Xác nhận thời gian chuẩn bị',
        inventory: 'Kiểm tra bánh sau hủy', inventory_disposition: 'Đã phân loại bánh sau hủy',
        coupon: 'Xử lý lượt mã giảm giá',
    };
    return labels[event.kind] ?? statuses[event.value] ?? payments[event.value] ?? 'Cập nhật đơn';
}

export function OrderEvents({ events, statuses, payments }) {
    return <ol className="timeline">{events.map((event, i) => <li key={event.id ?? i}><span></span><div>
        <strong>{eventTitle(event, statuses, payments)}</strong>
        {event.kind === 'schedule' && event.previous_value && <small>Giờ cũ: {dateTime(event.previous_value)}</small>}
        <small>{dateTime(event.created_at)}{event.actor ? ' · ' + event.actor : ''}</small>{event.note && <p>{event.note}</p>}
    </div></li>)}</ol>;
}

function CopyValue({ value, label }) {
    const [result, setResult] = useState('');
    if (!navigator.clipboard) return null;
    return <span className="copy-value">
        <button className="button secondary compact" type="button" onClick={async () => {
            try {
                await navigator.clipboard.writeText(value);
                setResult('Đã sao chép ' + label);
            } catch {
                setResult('Hãy chọn và sao chép ' + label + ' thủ công.');
            }
        }}>{result.startsWith('Đã') ? <Check size={16} /> : <Clipboard size={16} />} Sao chép {label}</button>
        {result && <small className="muted" role="status">{result}</small>}
    </span>;
}

const requestLabels = { pending: 'Chờ tiệm xử lý', approved: 'Đã chấp nhận', rejected: 'Không chấp nhận' };

export function ChangeRequestHistory({ requests = [] }) {
    return requests.length ? <ul className="summary-lines">{requests.map(request => <li key={request.id}>
        <div className="form-stack">
            <strong>{request.kind === 'reschedule' ? 'Yêu cầu đổi giờ' : 'Yêu cầu hủy đơn'}</strong>
            <span className={'pill state-' + (request.status === 'approved' ? 'completed' : request.status === 'rejected' ? 'cancelled' : 'pending')}>{requestLabels[request.status] ?? request.status}</span>
            <small>Gửi lúc {dateTime(request.created_at)}{request.requester_name ? ' · ' + request.requester_name : ''}</small>
            {!request.requester_name && request.actor && <small>{request.actor}</small>}
            {request.previous_slot && <small>Giờ hiện tại khi gửi: {dateTime(request.previous_slot)}</small>}
            {request.requested_slot && <small>Giờ đề nghị: {dateTime(request.requested_slot)}</small>}
            {request.reason && <p>Lý do: {request.reason}</p>}
            {request.response_message && <p>Tiệm phản hồi: {request.response_message}</p>}
            {request.handled_at && <small>Xử lý lúc {dateTime(request.handled_at)}{request.handler_name ? ' · ' + request.handler_name : ''}</small>}
        </div>
    </li>)}</ul> : <p className="muted">Chưa có yêu cầu đổi giờ hoặc hủy đơn.</p>;
}

function CustomerChangeForm({ changeRequest, expiresAt }) {
    const form = useForm({ request_key: changeRequest.requestKey, kind: 'reschedule', previous_slot: changeRequest.previousSlot, requested_slot: '', reason: '' });
    const slots = changeRequest.slots ?? [];
    const days = [...new Set(slots.map(slot => slot.slice(0, 10)))];
    const [day, setDay] = useState('');
    const selectedDay = days.includes(day) ? day : '';
    return <form className="form-stack" onSubmit={event => {
        event.preventDefault();
        form.post(changeRequest.submitUrl, { preserveScroll: true });
    }}>
        {expiresAt && <p className="warning">Liên kết thao tác dùng một lần, hết hạn lúc {dateTime(expiresAt)}. Liên kết tra cứu cũ vẫn xem được đơn.</p>}
        <p className="muted">Tiệm sẽ xem xét yêu cầu. Giờ nhận và trạng thái đơn chỉ thay đổi sau khi được tiệm chấp nhận.</p>
        {changeRequest.cutoffAt && <p className="muted">Có thể gửi yêu cầu trước {dateTime(changeRequest.cutoffAt)}, khi đơn chưa bắt đầu chuẩn bị.</p>}
        <Field label="Bạn muốn thay đổi gì?" error={form.errors.kind}><select value={form.data.kind} onChange={event => form.setData('kind', event.target.value)}>
            <option value="reschedule">Đổi giờ giao hoặc nhận</option><option value="cancel">Hủy đơn</option>
        </select></Field>
        {form.data.kind === 'reschedule' && (slots.length ? <div className="two-fields">
            <Field label="Ngày mới"><select required value={selectedDay} onChange={event => { setDay(event.target.value); form.setData('requested_slot', ''); }}>
                <option value="">Chọn ngày</option>{days.map(value => <option key={value} value={value}>{formatDate(value)}</option>)}
            </select></Field>
            <Field label="Giờ mới" error={form.errors.requested_slot}><select required disabled={!selectedDay} value={form.data.requested_slot} onChange={event => form.setData('requested_slot', event.target.value)}>
                <option value="">Chọn giờ</option>{slots.filter(slot => slot.startsWith(selectedDay)).map(slot => <option key={slot} value={slot}>{slot.slice(11, 16)}</option>)}
            </select></Field>
        </div> : <p className="warning">Hiện chưa có giờ khác còn chỗ. Bạn có thể liên hệ tiệm hoặc chọn yêu cầu hủy đơn.</p>)}
        <Field label="Lý do yêu cầu" error={form.errors.reason}><textarea required minLength={3} maxLength={200} rows="3" value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></Field>
        <ErrorList errors={form.errors} />
        <button className="button secondary" disabled={form.processing || (form.data.kind === 'reschedule' && !form.data.requested_slot)}>{form.processing ? 'Đang gửi…' : 'Gửi yêu cầu cho tiệm'}</button>
    </form>;
}

function GuestActionLink({ url }) {
    const form = useForm({ email: '' });
    const [sent, setSent] = useState(false);
    return <form className="form-stack" onSubmit={event => {
        event.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: () => { setSent(true); form.reset(); } });
    }}>
        <p>Để yêu cầu đổi giờ hoặc hủy, hãy xác minh bằng email đã đặt đơn. Liên kết tra cứu chỉ dùng để xem đơn.</p>
        <Field label="Email đã đặt đơn" error={form.errors.email}><input required type="email" autoComplete="email" maxLength={254} value={form.data.email} onChange={event => form.setData('email', event.target.value)} /></Field>
        <ErrorList errors={form.errors} />
        <button className="button secondary" disabled={form.processing}>{form.processing ? 'Đang tiếp nhận…' : 'Gửi liên kết thao tác qua email'}</button>
        {sent && <p className="muted" role="status">Nếu thông tin phù hợp, tiệm sẽ gửi liên kết thao tác đến email đã đặt đơn. Hãy kiểm tra cả thư rác.</p>}
    </form>;
}

export function OrderAction({ expired, ...props }) {
    if (expired || !props.order) return <Layout title="Liên kết thao tác đã hết hạn"><div className="wrap page">
        <div className="panel auth-card form-stack"><h1>Liên kết không còn hiệu lực</h1>
            <p>Liên kết thao tác đã hết hạn hoặc đã được sử dụng. Đơn và liên kết tra cứu của bạn vẫn được giữ nguyên.</p>
            <p>Mở đơn từ liên kết tra cứu cũ rồi yêu cầu gửi một liên kết thao tác mới đến email đã đặt đơn.</p>
            <ButtonLink href="/tra-cuu">Tra cứu đơn</ButtonLink>
        </div>
    </div></Layout>;
    return <Order {...props} actionMode />;
}

export function Order({ order, statuses, payments, trackingLink, canReorder, changeRequest, changeRequests = [], expiresAt, actionMode = false }) {
    const { shop } = usePage().props;
    const reorder = useForm({});
    const awaitingTransfer = order.bank_snapshot && order.payment === 'unpaid' && order.status !== 'cancelled';
    const nextStep = {
        pending: 'Tiệm sẽ xác nhận bánh và giờ hẹn. Hãy lưu mã đơn để theo dõi.',
        confirmed: 'Tiệm đã xác nhận đơn. Hãy theo dõi tại đây để biết khi bánh sẵn sàng.',
        preparing: 'Tiệm đang làm bánh. Bạn có thể xem giờ hẹn bên dưới.',
        ready: 'Bánh đã sẵn sàng. Hãy mang mã đơn khi đến tiệm nhận bánh.',
        shipping: 'Bánh đang được giao. Hãy giữ điện thoại để nhận cuộc gọi khi giao bánh.',
        delivery_failed: 'Tiệm chưa giao được bánh. Vui lòng liên hệ tiệm để xác nhận cách giao lại hoặc xử lý đơn.',
        uncollected: 'Tiệm chưa bàn giao được bánh tại giờ hẹn. Vui lòng liên hệ để thống nhất thời gian nhận và cách xử lý.',
        completed: 'Đơn đã hoàn tất. Cảm ơn bạn đã đặt bánh tại tiệm.',
    }[order.status];
    return <Layout title={'Đơn ' + order.code}><div className="wrap page">
        <Breadcrumb>{canReorder ? <Link href="/don-cua-toi">Đơn của tôi</Link> : <Link href="/tra-cuu">Tra cứu đơn</Link>}<span aria-hidden="true">/</span><span>{order.code}</span></Breadcrumb>
        <div className="page-heading order-heading"><h1>Đơn bánh {order.code}</h1><div className="row-actions"><Pill value={order.status} labels={statuses} /><Pill value={order.payment} labels={payments} /></div>
            {!awaitingTransfer && nextStep && <p>{nextStep}</p>}
        </div>
        <div className="order-grid">
            <div className="form-stack">
                {awaitingTransfer && <section className="panel form-stack bank-panel"><h2>Thông tin chuyển khoản</h2>
                    {order.bank_snapshot.demo && <p className="warning">Thông tin chuyển khoản là dữ liệu mẫu. Vui lòng không chuyển tiền.</p>}
                    <p className="bank-amount"><span>Số tiền</span><strong>{money(order.total)}</strong></p>
                    <dl className="totals"><div><dt>Ngân hàng</dt><dd>{order.bank_snapshot.name}</dd></div>
                        <div><dt>Số tài khoản</dt><dd><strong>{order.bank_snapshot.account}</strong><CopyValue value={order.bank_snapshot.account} label="số tài khoản" /></dd></div>
                        <div><dt>Chủ tài khoản</dt><dd>{order.bank_snapshot.holder}</dd></div>
                        <div><dt>Nội dung chuyển khoản</dt><dd><strong>{order.code}</strong><CopyValue value={order.code} label="nội dung" /></dd></div></dl>
                    {order.bank_due_at && <p className="warning">Hạn chuyển khoản · {dateTime(order.bank_due_at)}</p>}
                    <p className="muted">Tiệm cập nhật thanh toán sau khi nhân viên đối soát.</p>
                </section>}
                <section className="panel form-stack"><h2>Cách nhận bánh</h2><p className="icon-text"><MapPin size={19} />{order.fulfillment === 'pickup' ? 'Nhận tại tiệm · ' + shop.address : (order.address || 'Giao tận nơi') + ' · ' + order.region_label}</p><p className="icon-text"><CalendarClock size={19} />{dateTime(order.scheduled_at)}</p></section>
                {order.status === 'cancelled' && order.payment === 'paid' && <p className="warning" role="status">Đơn đã hủy và đang chờ tiệm xử lý hoàn tiền. Tiệm chỉ ghi nhận đã hoàn sau khi xác minh việc hoàn trả.</p>}
                {order.payment === 'refunded' && <p className="warning" role="status">Tiệm đã ghi nhận hoàn tiền. Nếu chưa nhận được khoản hoàn, vui lòng liên hệ tiệm kèm mã đơn.</p>}
                {changeRequest && <section className="panel form-stack"><h2>{actionMode ? 'Gửi yêu cầu thay đổi đơn' : 'Đổi giờ hoặc hủy đơn'}</h2>
                    {changeRequest.canRequest && changeRequest.eligible && changeRequest.submitUrl ?
                        <CustomerChangeForm key={changeRequest.requestKey} changeRequest={changeRequest} expiresAt={expiresAt} /> :
                        changeRequest.actionLinkUrl ? <GuestActionLink url={changeRequest.actionLinkUrl} /> :
                            <p className="muted">Đơn hiện chưa thể gửi thêm yêu cầu trực tuyến. Nếu đơn đang được làm, đã đến hạn xử lý hoặc có yêu cầu đang chờ, hãy liên hệ tiệm để được hỗ trợ.</p>}
                    {shop.phone && <a className="subtle-link" href={`tel:${shop.phone.replace(/[^+\d]/g, '')}`}>Gọi tiệm {shop.phone}</a>}
                </section>}
                {changeRequest && <section className="panel form-stack"><h2>Yêu cầu đã gửi</h2><ChangeRequestHistory requests={changeRequests} /></section>}
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
                {trackingLink && <><Field label="Liên kết tra cứu"><input readOnly value={trackingLink} onFocus={e => e.target.select()} /></Field>
                    <CopyValue value={trackingLink} label="liên kết" /></>}
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
