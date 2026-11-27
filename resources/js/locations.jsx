import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Check, Clock3, MapPin, Phone, ShieldCheck, Store } from 'lucide-react';
import { useRef, useState } from 'react';
import { Breadcrumb, ButtonLink, dateTime, Empty, ErrorList, Field, Layout, money } from './ui';
import { confirmedPin, LocationPin, postLocationJson } from './location-input';
import { compareQuoteLines } from './quote-lines';

const modes = { pickup: 'Nhận tại tiệm', delivery: 'Giao tận nơi' };
const methods = { cod: 'Khi nhận bánh', bank: 'Chuyển khoản' };
const goals = [['earliest', 'Nhận sớm nhất'], ['fee', 'Phí giao thấp nhất'], ['distance', 'Khoảng cách gần nhất'], ['travel', 'Thời gian di chuyển']];
const emptyAddress = { country_code: 'VN', area_code: '', address_line: '', province_code: '', district_code: '', ward_code: '', delivery_note: '', pin: null };

export function AddressInputs({ address, onChange, areas = [], errors = {}, required = false, prefix = 'location', note = true }) {
    const update = (key, value) => onChange({ ...address, [key]: value });
    const pinError = errors.pin ?? errors['address.pin'] ?? Object.entries(errors).find(([field]) => field.startsWith('pin.') || field.startsWith('address.pin.'))?.[1];
    return <>
        <Field label="Khu vực nhận bánh" error={errors.area_code ?? errors['address.area_code']} hint="Chọn khu vực do tiệm công bố; không cần cấp vị trí.">
            <select id={prefix + '-area'} required={required} value={address.area_code ?? ''} onChange={event => update('area_code', event.target.value)}><option value="">Chọn khu vực</option>{areas.map(area => <option value={area.code} key={area.code}>{area.name}</option>)}</select>
        </Field>
        <Field label={required ? 'Địa chỉ giao bánh' : 'Địa chỉ nhận (tùy chọn khi tìm cơ sở)'} error={errors.address_line ?? errors['address.address_line']}>
            <input id={prefix + '-address'} required={required} autoComplete="street-address" maxLength={250} value={address.address_line ?? ''} onChange={event => update('address_line', event.target.value)} placeholder="Số nhà, đường, phường/xã, tỉnh/thành phố" />
        </Field>
        {note && <Field label="Chỉ dẫn giao bánh (tùy chọn)" error={errors.delivery_note ?? errors['address.delivery_note']}><textarea id={prefix + '-note'} maxLength={500} rows="2" value={address.delivery_note ?? ''} onChange={event => update('delivery_note', event.target.value)} placeholder="Ví dụ: gọi khi đến cổng, tầng hoặc lối vào" /></Field>}
        <LocationPin id={prefix + '-pin'} pin={address.pin} error={pinError} onChange={pin => update('pin', pin)} />
    </>;
}

function LocationFacts({ location }) {
    return <div className="location-facts">
        <p><MapPin size={18} aria-hidden="true" /><span>{location.address || 'Địa chỉ đang chờ tiệm xác nhận.'}</span></p>
        <p><Clock3 size={18} aria-hidden="true" /><span>{location.hours || 'Giờ mở cửa đang được cập nhật.'}</span></p>
        <div className="location-tags">{(location.fulfillments ?? []).map(value => <span key={value}>{modes[value] ?? value}</span>)}{(location.payment_methods ?? []).map(value => <span key={value}>{methods[value] ?? value}</span>)}</div>
    </div>;
}

function QuoteComparison({ preview }) {
    const totals = [['subtotal', 'Tiền bánh'], ['discount', 'Giảm giá'], ['delivery_fee', 'Phí giao'], ['total', 'Tổng dự kiến']];
    return <div className="location-comparison">
        <div className="location-comparison-head"><span>Thông tin</span><strong>{preview.from?.name || 'Cơ sở hiện tại'}</strong><strong>{preview.to?.name || 'Cơ sở mới'}</strong></div>
        {totals.filter(([key]) => preview.old?.[key] != null || preview.new?.[key] != null).map(([key, label]) => <div key={key}><span>{label}</span><span>{preview.old?.[key] != null ? money(preview.old[key]) : 'Chưa tính'}</span><strong>{preview.new?.[key] != null ? money(preview.new[key]) : 'Chưa tính'}</strong></div>)}
        {(preview.old?.slot || preview.new?.slot || preview.new?.selected_slot) && <div><span>Giờ nhận</span><span>{dateTime(preview.old?.slot) || 'Chưa chọn'}</span><strong>{dateTime(preview.new?.slot ?? preview.new?.selected_slot) || 'Chọn ở checkout'}</strong></div>}
    </div>;
}

function QuoteLineComparison({ preview }) {
    const lines = compareQuoteLines(preview.old?.lines, preview.new?.lines);
    const value = amount => amount == null ? 'Chưa xác định' : money(amount);
    return lines.length > 0 ? <section className="quote-lines form-stack" aria-label="Từng dòng bánh trước và sau khi đổi cơ sở">
        <h3>Kiểm tra từng dòng bánh</h3><p className="muted">Giá và thành tiền dưới đây do tiệm tính cho đúng giỏ đang chọn. Dòng chưa đáp ứng không thể giữ nguyên tại cơ sở mới.</p>
        <ul className="quote-line-list">{lines.map(line => <li className="quote-line-card" key={line.key}>
            <div className="quote-line-heading"><div><strong>{line.quantity != null ? line.quantity + ' × ' : ''}{line.productName}</strong>{line.variantName && <small>{line.variantName}</small>}</div>
                <span className={'pill state-' + (line.availability === 'available' ? 'completed' : line.availability === 'unavailable' ? 'pending' : 'unknown')}>{line.availability === 'available' ? 'Đáp ứng dòng này' : line.availability === 'unavailable' ? 'Chưa đáp ứng tại cơ sở mới' : 'Chưa đủ thông tin'}</span>
            </div>
            <dl className="quote-line-prices">
                <div><dt>Giá bánh</dt><dd>{value(line.before?.unitPrice)} <span aria-hidden="true">→</span><span className="visually-hidden">sang</span> <strong>{value(line.after?.unitPrice)}</strong></dd></div>
                {['optionFee', 'accessoryFee'].filter(key => Number(line.before?.[key] ?? 0) > 0 || Number(line.after?.[key] ?? 0) > 0).map(key => <div key={key}><dt>{key === 'optionFee' ? 'Phí lời chúc / bánh' : 'Phí phụ kiện / bánh'}</dt><dd>{value(line.before?.[key])} <span aria-hidden="true">→</span><span className="visually-hidden">sang</span> <strong>{value(line.after?.[key])}</strong></dd></div>)}
                <div><dt>Thành tiền dòng</dt><dd>{value(line.before?.subtotal)} <span aria-hidden="true">→</span><span className="visually-hidden">sang</span> <strong>{value(line.after?.subtotal)}</strong></dd></div>
            </dl>
            {line.availability === 'unavailable' && <p className="warning">Cơ sở mới chưa đáp ứng dòng này với số lượng hoặc tùy chọn đang chọn. Cả giỏ chưa thể giữ nguyên; hãy giữ cơ sở cũ hoặc chọn lại bánh.</p>}
            {line.availability === 'unknown' && <p className="muted">Chưa xác định đủ thông tin của dòng này tại cơ sở mới. Hãy kiểm tra lại báo giá trước khi tiếp tục.</p>}
        </li>)}</ul>
    </section> : null;
}

export function BranchPreview({ branchId, context, onClose }) {
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const [mode, setMode] = useState('keep');
    const [attempted, setAttempted] = useState(false);
    const [previewErrors, setPreviewErrors] = useState({});
    const form = useForm({ branch_id: branchId, quote_token: '', confirm: true, mode: 'keep' });
    async function refresh() {
        setLoading(true); setError(''); setConfirmed(false); setAttempted(true); setPreview(null); setPreviewErrors({});
        try {
            const data = await postLocationJson('/co-so/du-kien', { target_branch_id: branchId, ...context });
            setPreview(data);
            setMode(data.can_keep_cart ? 'keep' : 'clear');
        } catch (failure) { setError(failure.message); setPreviewErrors(failure.errors ?? {}); }
        finally { setLoading(false); }
    }
    return <section className="panel form-stack location-preview" tabIndex={-1} aria-label="Kiểm tra trước khi đổi cơ sở">
        <div className="section-head"><h2>Kiểm tra trước khi đổi cơ sở</h2><button type="button" className="text-action" onClick={onClose}>Đóng</button></div>
        <p>Tiệm kiểm tra lại toàn bộ giỏ, giá, phí và khung giờ. Giỏ hiện tại được giữ đến khi bạn xác nhận.</p>
        <button type="button" className="button secondary" disabled={loading || form.processing} onClick={refresh}>{loading ? 'Đang kiểm tra toàn bộ giỏ…' : attempted ? 'Kiểm tra lại' : 'Xem giá và lịch mới'}</button>
        <p className="muted" role="status" aria-live="polite">{loading ? 'Đang kiểm tra giá, tồn và lịch tại cơ sở mới.' : error}</p>
        <ErrorList errors={previewErrors} />
        {preview && !loading && <>
            <QuoteComparison preview={preview} />
            <QuoteLineComparison preview={preview} />
            {preview.changes?.length > 0 && <ul className="location-changes">{preview.changes.map((change, index) => <li key={index}>{typeof change === 'string' ? change : change.label || change.message || 'Giá, lịch hoặc phí đã được cập nhật trong bảng trên.'}</li>)}</ul>}
            {!preview.can_keep_cart && <p className="warning">{preview.blocked_reason || 'Cơ sở này chưa đáp ứng đủ giỏ hiện tại.'} Bạn có thể giữ cơ sở cũ hoặc xác nhận bỏ giỏ để chọn lại bánh.</p>}
            <fieldset className="form-stack"><legend>Cách xử lý giỏ</legend>
                {preview.can_keep_cart && <label className="checkbox"><input type="radio" name="switch-mode" value="keep" checked={mode === 'keep'} onChange={() => { setMode('keep'); setConfirmed(false); }} />Giữ toàn bộ bánh và tùy chọn; chấp nhận giá, phí và lịch mới</label>}
                <label className="checkbox"><input type="radio" name="switch-mode" value="clear" checked={mode === 'clear'} onChange={() => { setMode('clear'); setConfirmed(false); }} />Xóa giỏ hiện tại và chọn lại bánh tại cơ sở mới</label>
            </fieldset>
            <label className="checkbox"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} />Tôi đã xem thay đổi và đồng ý {mode === 'clear' ? 'xóa giỏ khi chuyển cơ sở' : 'chuyển toàn bộ giỏ theo báo giá mới'}.</label>
            <ErrorList errors={form.errors} />
            <button className="button" disabled={!confirmed || form.processing} onClick={() => {
                form.transform(() => ({ branch_id: branchId, quote_token: preview.quote_token, confirm: true, mode }));
                form.post('/co-so/xac-nhan', { preserveState: true, onError: () => { setConfirmed(false); setPreview(null); setError('Thông tin vừa thay đổi hoặc báo giá đã hết hạn. Kiểm tra lại rồi xác nhận; giỏ hiện tại vẫn được giữ.'); } });
            }}>{form.processing ? 'Đang chuyển…' : mode === 'keep' ? 'Xác nhận chuyển và giữ giỏ' : 'Xác nhận chuyển và xóa giỏ'}</button>
        </>}
    </section>;
}

export function Locations({ locations = [], areas = [], current_branch_id, cartSummary = {}, recommendationEndpoint = '/co-so/goi-y', savedAddressEndpoint }) {
    const [address, setAddress] = useState({ ...emptyAddress });
    const [fulfillment, setFulfillment] = useState('pickup');
    const [method, setMethod] = useState('cod');
    const [goal, setGoal] = useState('earliest');
    const [desiredSlot, setDesiredSlot] = useState('');
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState('');
    const [selection, setSelection] = useState(null);
    const resultsRef = useRef(null);
    const payload = () => ({ fulfillment, method, area_code: address.area_code, country_code: 'VN', address_line: address.address_line || null, delivery_note: address.delivery_note || null, pin: confirmedPin(address.pin), desired_slot: desiredSlot ? desiredSlot.replace('T', ' ') : null, goal });
    async function recommend(event) {
        event.preventDefault(); setBusy(true); setErrors({}); setFailure(''); setSelection(null);
        try { setResult(await postLocationJson(recommendationEndpoint, payload())); requestAnimationFrame(() => resultsRef.current?.focus()); }
        catch (error) { setErrors(error.errors ?? {}); setFailure(error.message); }
        finally { setBusy(false); }
    }
    const visible = result ? result.candidates.map(candidate => ({ ...locations.find(location => Number(location.id) === Number(candidate.branch_id)), ...candidate, id: candidate.branch_id })) : locations;
    return <Layout title="Chọn cơ sở"><div className="wrap page locations-page">
        <Breadcrumb><span>Cơ sở nhận bánh</span></Breadcrumb><div className="page-heading"><span className="sf-eyebrow">Chọn nơi thuận tiện cho bạn</span><h1>Nhận bánh ở đâu?</h1><p>So sánh cơ sở theo toàn bộ giỏ của bạn. Giá, tồn và giờ nhận được kiểm tra lại trước khi đổi.</p></div>
        <div className="locations-grid"><aside><form className="panel form-stack location-search" onSubmit={recommend}>
            <h2>Điều gì phù hợp với bạn?</h2><p className="location-cart-note"><ShieldCheck size={18} aria-hidden="true" />{cartSummary.line_count ? 'Kiểm tra ' + cartSummary.line_count + ' dòng bánh · ' + cartSummary.quantity + ' bánh trong giỏ' : 'Bạn chưa có bánh trong giỏ. Có thể xem cơ sở trước rồi chọn bánh.'}</p>
            <Field label="Cách nhận"><select value={fulfillment} onChange={event => { setFulfillment(event.target.value); setResult(null); setSelection(null); }}><option value="pickup">Nhận tại tiệm</option><option value="delivery">Giao tận nơi</option></select></Field>
            <Field label="Thanh toán"><select value={method} onChange={event => { setMethod(event.target.value); setResult(null); setSelection(null); }}><option value="cod">Khi nhận bánh</option><option value="bank">Chuyển khoản</option></select></Field>
            <AddressInputs address={address} onChange={value => { setAddress(value); setResult(null); setSelection(null); }} areas={areas} errors={errors} prefix="recommend" note={false} />
            <Field label="Ưu tiên của bạn"><select value={goal} onChange={event => { setGoal(event.target.value); setResult(null); setSelection(null); }}>{goals.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Field>
            <Field label="Giờ mong muốn (tùy chọn)" error={errors.desired_slot}><input type="datetime-local" value={desiredSlot} onChange={event => { setDesiredSlot(event.target.value); setResult(null); setSelection(null); }} /></Field>
            <ErrorList errors={errors} />{failure && <p className="warning" role="alert">{failure}</p>}
            <button className="button" disabled={busy}>{busy ? 'Đang kiểm tra các cơ sở…' : 'Tìm cơ sở phù hợp'}<ArrowRight size={17} aria-hidden="true" /></button>
            {savedAddressEndpoint && <Link className="subtle-link" href={savedAddressEndpoint}>Quản lý địa chỉ đã lưu</Link>}
            <p className="muted">Địa chỉ/ghim chỉ dùng khi bạn gửi yêu cầu này. Không đưa vào đường dẫn hay tự lưu vào sổ địa chỉ.</p>
        </form></aside>
        <section className="form-stack location-results" ref={resultsRef} tabIndex={-1} aria-label="Các cơ sở phù hợp" aria-busy={busy}>
            <div className="section-head"><h2>{result ? visible.length + ' cơ sở đáp ứng yêu cầu' : 'Các cơ sở của tiệm'}</h2>{busy && <span role="status">Đang cập nhật…</span>}</div>
            {result?.ranking?.fallback_reason && <p className="warning" role="status">{result.ranking.fallback_reason} Kết quả đang xếp theo {goals.find(([key]) => key === result.ranking.applied_goal)?.[1]?.toLowerCase() || 'khả năng nhận bánh'}.</p>}
            {result && <p className="muted">Các thời điểm và khoảng cách là ước tính tại lúc kiểm tra; chưa phải cam kết giao hàng. Dịch vụ bản đồ chưa cung cấp dữ liệu sẽ hiển thị chưa có ước tính.</p>}
            {visible.length ? visible.map((location, index) => <article className="panel location-card" key={location.id}>
                <div className="section-head"><div><span className="sf-eyebrow">{Number(location.id) === Number(current_branch_id) ? 'Cơ sở đang chọn' : result && index === 0 ? 'Phù hợp với ưu tiên của bạn' : 'Cơ sở của tiệm'}</span><h2>{location.name}</h2></div><Store size={25} aria-hidden="true" /></div>
                <LocationFacts location={location} />
                {result ? <dl className="location-metrics"><div><dt>Tiền bánh</dt><dd>{money(location.subtotal)}</dd></div><div><dt>Phí giao</dt><dd>{money(location.delivery_fee)}</dd></div><div><dt>Tổng dự kiến</dt><dd>{money(location.total)}</dd></div><div><dt>Giờ sớm nhất</dt><dd>{dateTime(location.earliest_slot) || 'Chưa có giờ phù hợp'}</dd></div>
                    <div><dt>Khoảng cách</dt><dd>{location.distance_meters == null ? 'Chưa có ước tính' : (location.distance_meters / 1000).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + ' km (ước tính)'}</dd></div><div><dt>Di chuyển</dt><dd>{location.travel_minutes == null ? 'Chưa có ước tính' : location.travel_minutes + ' phút (ước tính)'}</dd></div></dl> :
                    <p className="muted">{location.earliest_slot ? 'Khung nhận hiện có sớm nhất: ' + dateTime(location.earliest_slot) : location.availability_note || 'Chọn yêu cầu để kiểm tra lịch và toàn bộ giỏ.'}</p>}
                {!location.accepting_orders && !result && <p className="warning">Cơ sở đang tạm ngừng nhận đơn mới.</p>}
                {location.estimate_provider === 'fake' && <p className="warning">Ước tính giả lập cho kiểm thử local, không dùng để hẹn giao thật.</p>}
                <div className="row-actions"><button className="button secondary" onClick={() => { setSelection({ id: location.id, slot: location.selected_slot ?? (desiredSlot ? desiredSlot.replace('T', ' ') : null) }); requestAnimationFrame(() => document.getElementById('branch-preview')?.scrollIntoView({ block: 'center' })); }}>Kiểm tra trước khi chọn</button><Link className="subtle-link" href={'/co-so/' + location.slug}>Thông tin cơ sở<ArrowRight size={15} /></Link></div>
            </article>) : <Empty title="Chưa có cơ sở phù hợp" text="Thử đổi giờ, hình thức nhận hoặc kiểm tra bánh trong giỏ. Tiệm giữ nguyên giỏ hiện tại của bạn." href="/gio-hang" label="Xem lại giỏ" />}
            {result?.unavailable?.length > 0 && <section className="panel form-stack"><h2>Cơ sở chưa đáp ứng yêu cầu</h2>{result.unavailable.map(location => <div className="location-unavailable" key={location.branch_id}><strong>{location.name}</strong><p>{location.reason}</p><Link className="subtle-link" href={'/co-so/' + location.slug}>Xem thông tin</Link></div>)}</section>}
            {selection != null && <div id="branch-preview"><BranchPreview key={selection.id} branchId={selection.id} context={{ ...payload(), slot: selection.slot }} onClose={() => setSelection(null)} /></div>}
        </section></div>
    </div></Layout>;
}

export function Location({ location, current_branch_id, areas = [], cartSummary = {} }) {
    const [preview, setPreview] = useState(false);
    const [address, setAddress] = useState({ ...emptyAddress });
    const [fulfillment, setFulfillment] = useState(location.fulfillments?.[0] || 'pickup');
    const [method, setMethod] = useState(location.payment_methods?.[0] || 'cod');
    return <Layout title={location.name}><div className="wrap page location-detail">
        <Breadcrumb><Link href="/co-so">Các cơ sở</Link><span aria-hidden="true">/</span><span>{location.name}</span></Breadcrumb>
        <div className="page-heading"><span className="sf-eyebrow">{Number(current_branch_id) === Number(location.id) ? 'Cơ sở đang chọn' : 'Cơ sở của tiệm'}</span><h1>{location.name}</h1></div>
        <div className="locations-grid"><section className="panel form-stack"><LocationFacts location={location} />
            {location.earliest_slot ? <p className="location-cart-note"><Clock3 size={18} />Khung nhận hiện có: {dateTime(location.earliest_slot)}. Tiệm kiểm tra lại khi đặt.</p> : <p className="muted">{location.availability_note || 'Chưa công bố giờ nhận sớm nhất. Kiểm tra giỏ và lịch trước khi đặt.'}</p>}
            {!location.accepting_orders && <p className="warning">Đang tạm ngừng nhận đơn mới.</p>}
            {location.phone && <a className="subtle-link" href={'tel:' + location.phone.replace(/[^+\d]/g, '')}><Phone size={17} />Gọi cơ sở {location.phone}</a>}
            {location.email && <a className="subtle-link" href={'mailto:' + location.email}>{location.email}</a>}
            {location.maps_url && <a className="button secondary" href={location.maps_url} target="_blank" rel="noopener noreferrer"><MapPin size={17} />Mở bản đồ của cơ sở</a>}
            <ButtonLink href="/co-so" secondary>So sánh cơ sở khác</ButtonLink>
        </section><section className="panel form-stack"><h2>Chọn cơ sở này</h2><p>{cartSummary.line_count ? 'Giỏ của bạn sẽ được kiểm tra đủ bánh, tùy chọn, phí và giờ nhận.' : 'Bạn có thể chọn cơ sở trước rồi xem thực đơn.'}</p>
            <Field label="Cách nhận"><select value={fulfillment} onChange={event => { setFulfillment(event.target.value); setPreview(false); }}>{(location.fulfillments ?? ['pickup']).map(value => <option key={value} value={value}>{modes[value]}</option>)}</select></Field>
            <Field label="Thanh toán"><select value={method} onChange={event => { setMethod(event.target.value); setPreview(false); }}>{(location.payment_methods ?? ['cod']).map(value => <option key={value} value={value}>{methods[value]}</option>)}</select></Field>
            <AddressInputs address={address} onChange={value => { setAddress(value); setPreview(false); }} areas={areas} prefix="detail" note={false} />
            <button className="button" onClick={() => setPreview(true)}>Xem thay đổi trước khi chọn<ArrowRight size={17} /></button>
        </section></div>
        {preview && <BranchPreview branchId={location.id} context={{ fulfillment, method, area_code: address.area_code, country_code: 'VN', address_line: address.address_line || null, pin: confirmedPin(address.pin) }} onClose={() => setPreview(false)} />}
    </div></Layout>;
}

export function AddressBook({ addresses = [], areas = [] }) {
    const [address, setAddress] = useState({ ...emptyAddress });
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const form = useForm({ label: '', address: emptyAddress, save_consent: false });
    return <Layout title="Địa chỉ của tôi"><div className="wrap page address-book"><Breadcrumb><span>Địa chỉ của tôi</span></Breadcrumb><div className="page-heading"><h1>Địa chỉ của tôi</h1><p>Chỉ lưu khi bạn đồng ý. Khách vãng lai vẫn đặt bánh bằng địa chỉ nhập ở checkout.</p></div>
        <div className="locations-grid"><section className="form-stack"><h2>Địa chỉ đã lưu</h2>{addresses.length ? addresses.map(item => <article key={item.id} className="panel form-stack"><h3>{item.label}</h3>{item.readable === false ? <p className="warning">Chưa đọc được địa chỉ đã mã hóa. Hãy thêm lại địa chỉ sau khi được hỗ trợ.</p> : <><p>{item.address?.address_line}</p><small className="muted">Khu vực {areas.find(area => area.code === item.address?.area_code)?.name ?? item.address?.area_code}</small></>}
            <div className="row-actions">{item.readable !== false && <button className="text-action" onClick={() => { setEditing(item.id); setAddress({ ...emptyAddress, ...item.address }); form.setData({ label: item.label, address: emptyAddress, save_consent: false }); document.getElementById('address-book-editor')?.focus(); }}>Sửa địa chỉ</button>}<button className="text-action danger" onClick={() => setDeleting(item.id)}>Xóa địa chỉ</button></div>
            {deleting === item.id && <div className="warning form-stack"><p>Xóa địa chỉ đã lưu này? Địa chỉ trên đơn đã đặt vẫn được giữ.</p><div className="row-actions"><button className="button secondary compact" onClick={() => setDeleting(null)}>Giữ lại</button><button className="button secondary compact" onClick={() => router.delete('/tai-khoan/dia-chi/' + item.id, { preserveScroll: true, onSuccess: () => setDeleting(null) })}>Xác nhận xóa</button></div></div>}</article>) : <p className="panel muted">Bạn chưa lưu địa chỉ nào.</p>}</section>
            <form id="address-book-editor" tabIndex={-1} className="panel form-stack" onSubmit={event => { event.preventDefault(); form.transform(data => ({ ...data, address: { ...address, pin: confirmedPin(address.pin) } })); form.post('/tai-khoan/dia-chi' + (editing ? '/' + editing : ''), { preserveScroll: true, onSuccess: () => { form.reset(); setAddress({ ...emptyAddress }); setEditing(null); } }); }}>
                <h2>{editing ? 'Sửa địa chỉ' : 'Thêm địa chỉ'}</h2>{editing && <button type="button" className="text-action" onClick={() => { setEditing(null); setAddress({ ...emptyAddress }); form.reset(); }}>Hủy sửa và thêm địa chỉ mới</button>}<Field label="Tên gợi nhớ" error={form.errors.label}><input required maxLength={80} value={form.data.label} onChange={event => form.setData('label', event.target.value)} placeholder="Ví dụ: Nhà, công ty" /></Field>
                <AddressInputs address={address} onChange={setAddress} areas={areas} errors={form.errors} required prefix="saved" />
                <label className="checkbox"><input required type="checkbox" checked={form.data.save_consent} onChange={event => form.setData('save_consent', event.target.checked)} />Tôi đồng ý lưu địa chỉ này trong tài khoản để dùng cho lần đặt sau.</label>
                <ErrorList errors={form.errors} /><button className="button" disabled={form.processing || !form.data.save_consent}>{form.processing ? 'Đang lưu…' : 'Lưu địa chỉ'}</button>
            </form>
        </div>
    </div></Layout>;
}

export function TransferApproval({ proposal, order, submit_url, expired = false, approved = false }) {
    const form = useForm({ proposal_id: proposal?.id, decision: 'approved', accept_quote: false, request_key: globalThis.crypto?.randomUUID?.() ?? '' });
    const final = approved || ['approved', 'completed', 'executed', 'rejected', 'cancelled', 'expired'].includes(proposal?.status);
    return <Layout title="Xác nhận đề xuất chuyển cơ sở"><div className="wrap page transfer-approval">
        <div className="panel form-stack"><span className="sf-eyebrow">Đơn {order?.code || 'của bạn'}</span><h1>Xác nhận đề xuất chuyển cơ sở</h1>
            {expired || !proposal ? <><p className="warning">Liên kết đã hết hiệu lực. Đơn hiện tại vẫn được giữ nếu chưa có xác nhận chuyển thành công.</p><ButtonLink href="/tra-cuu" secondary>Tra cứu đơn hiện tại</ButtonLink></> : <>
                <p>Tiệm đề xuất đổi nơi xử lý đơn từ <strong>{proposal.from?.name}</strong> sang <strong>{proposal.to?.name}</strong>. Hãy kiểm tra nơi nhận, giờ và tổng tiền trước khi phản hồi.</p>
                {proposal.to?.address && <p className="icon-text"><MapPin size={18} />Địa chỉ nhận mới: {proposal.to.address}</p>}
                <dl className="totals"><div><dt>Tổng hiện tại</dt><dd>{money(proposal.old_total ?? proposal.old?.total)}</dd></div><div><dt>Tổng đề xuất</dt><dd><strong>{money(proposal.new_total ?? proposal.new?.total)}</strong></dd></div><div><dt>Chênh lệch</dt><dd>{money(proposal.delta)}</dd></div><div><dt>Giờ nhận đề xuất</dt><dd>{dateTime(proposal.slot)}</dd></div></dl>
                {(proposal.approval_expires_at || proposal.expires_at) && <p className="muted">Hạn phản hồi: {dateTime(proposal.approval_expires_at || proposal.expires_at)}</p>}
                <p className="muted">Đồng ý chưa tự chuyển đơn. Tiệm còn kiểm tra lại giá, tồn, lịch và chính sách thu tiền. Nếu thay đổi cần đề xuất mới, tiệm phải xin bạn xác nhận lại. Ứng dụng không tự thu hoặc hoàn tiền.</p>
                {final ? <p className="location-cart-note" role="status"><Check size={19} />{proposal.status === 'rejected' ? 'Đã ghi nhận từ chối. Cơ sở xử lý hiện tại được giữ.' : ['completed', 'executed'].includes(proposal.status) ? 'Đơn đã được chuyển. Xem trạng thái mới trong liên kết tra cứu đơn.' : 'Đã ghi nhận phản hồi. Xem trạng thái đơn hiện tại trong liên kết tra cứu.'}</p> : <form className="form-stack" onSubmit={event => { event.preventDefault(); form.post(submit_url, { preserveState: 'errors' }); }}>
                    <Field label="Phản hồi của bạn" error={form.errors.decision}><select value={form.data.decision} onChange={event => form.setData(data => ({ ...data, decision: event.target.value, accept_quote: false }))}><option value="approved">Đồng ý với đề xuất</option><option value="rejected">Từ chối, giữ cơ sở hiện tại</option></select></Field>
                    {form.data.decision === 'approved' && <label className="checkbox"><input required type="checkbox" checked={form.data.accept_quote} onChange={event => form.setData('accept_quote', event.target.checked)} />Tôi đã xem cơ sở, giờ, tổng tiền và đồng ý với đề xuất này.</label>}
                    <ErrorList errors={form.errors} /><button className="button" disabled={form.processing || (form.data.decision === 'approved' && !form.data.accept_quote)}>{form.processing ? 'Đang gửi phản hồi…' : 'Xác nhận phản hồi'}</button>
                </form>}
            </>}
        </div>
    </div></Layout>;
}
