import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { dateTime, ErrorList, Field, money } from './ui';
import { postLocationJson } from './location-input';

const uuid = () => globalThis.crypto.randomUUID();
const labels = { pending: 'Chờ khách xác nhận', pending_approval: 'Chờ khách xác nhận', approved: 'Khách đã đồng ý', rejected: 'Khách từ chối', executed: 'Đã chuyển cơ sở', completed: 'Đã chuyển cơ sở', expired: 'Đã hết hạn', cancelled: 'Đã hủy đề xuất' };

function TransferProposal({ proposal }) {
    const form = useForm({ request_key: uuid(), note: '', bank_checked: false, bank_reference: '' });
    return <article className="transfer-proposal form-stack">
        <div className="section-head"><h3>Đề xuất #{proposal.id}</h3><span className="pill">{labels[proposal.status] || proposal.status}</span></div>
        <p>{proposal.from?.name || 'Cơ sở nguồn'} <ArrowRight size={15} aria-hidden="true" /> {proposal.to?.name || 'Cơ sở đích'}</p><p>Giờ đề xuất: {dateTime(proposal.slot)}</p>
        <p>{money(proposal.old_total)} → <strong>{money(proposal.new_total)}</strong> · Chênh lệch {money(proposal.delta)}</p>
        {proposal.expires_at && <p className="muted">Hạn đề xuất: {dateTime(proposal.expires_at)}</p>}
        {(proposal.can_execute || proposal.can_cancel) && <form className="form-stack" onSubmit={event => event.preventDefault()}>
            <Field label="Lý do xử lý đề xuất" error={form.errors.note}><textarea required minLength={3} maxLength={250} rows="2" value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></Field>
            {proposal.can_execute && <details className="fold"><summary>Đối chiếu đơn chuyển khoản chưa thu (nếu áp dụng)</summary><p>Chỉ được chuyển khi máy chủ xác nhận cùng thông tin nhận tiền và người xử lý có quyền ở cả hai cơ sở. Không đánh dấu đã thu hoặc đã hoàn bằng thao tác chuyển cơ sở.</p>
                <label className="checkbox"><input type="checkbox" checked={form.data.bank_checked} onChange={event => form.setData('bank_checked', event.target.checked)} />Tôi đã đối chiếu thực tế chưa nhận khoản chuyển cho đơn này.</label>
                <Field label="Tham chiếu đối chiếu" error={form.errors.bank_reference}><input maxLength={80} value={form.data.bank_reference} onChange={event => form.setData('bank_reference', event.target.value)} /></Field>
            </details>}
            <ErrorList errors={form.errors} /><div className="row-actions">
                {proposal.can_execute && <button type="button" className="button" disabled={form.processing || form.data.note.trim().length < 3} onClick={() => form.post('/quan-tri/chuyen-co-so/' + proposal.id + '/thuc-hien', { preserveScroll: true, preserveState: 'errors' })}>{form.processing ? 'Đang kiểm tra và chuyển…' : 'Kiểm tra lại và thực hiện chuyển'}</button>}
                {proposal.can_cancel && <button type="button" className="button secondary" disabled={form.processing || form.data.note.trim().length < 3} onClick={() => form.post('/quan-tri/chuyen-co-so/' + proposal.id + '/huy', { preserveScroll: true, preserveState: 'errors' })}>Hủy đề xuất</button>}
            </div>
        </form>}
    </article>;
}

export function TransferOrder({ transfer, order }) {
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState('');
    const [previewErrors, setPreviewErrors] = useState({});
    const [accepted, setAccepted] = useState(false);
    const form = useForm({ target_branch_id: '', slot: '', area_code: order.delivery_address?.area_code ?? '', note: '', request_key: uuid() });
    const change = (key, value) => { form.setData(key, value); setPreview(null); setAccepted(false); };
    async function quote(event) {
        event.preventDefault();
        // Native datetime pickers can commit on blur; read the submitted controls,
        // then synchronize React state before using the reviewed quote to propose.
        const controls = new FormData(event.currentTarget);
        const current = {
            target_branch_id: String(controls.get('target_branch_id') ?? form.data.target_branch_id),
            slot: String(controls.get('slot') ?? form.data.slot),
            area_code: String(controls.get('area_code') ?? form.data.area_code),
            note: String(controls.get('note') ?? form.data.note),
        };
        form.setData(data => ({ ...data, ...current }));
        setLoading(true); setFailure(''); setPreviewErrors({}); setAccepted(false); setPreview(null);
        try { setPreview(await postLocationJson(transfer.preview_url, { target_branch_id: current.target_branch_id, slot: current.slot.replace('T', ' '), area_code: current.area_code || null })); }
        catch (error) { setFailure(error.message); setPreviewErrors(error.errors ?? {}); }
        finally { setLoading(false); }
    }
    return <section className="panel form-stack transfer-panel"><h2>Chuyển cơ sở xử lý đơn</h2><p>Đây là chuyển đơn chưa thu tiền, chưa bắt đầu làm hoặc xuất giao. Khách cần đồng ý đề xuất trước; cơ sở nguồn giữ đơn và tài nguyên đến khi thực hiện thành công.</p>
        {transfer.enabled && !transfer.blocked_reason ? <form className="form-stack" onSubmit={quote}>
            <div className="two-fields"><Field label="Cơ sở đích" error={previewErrors.target_branch_id ?? form.errors.target_branch_id}><select required name="target_branch_id" value={form.data.target_branch_id} onChange={event => change('target_branch_id', event.target.value)}><option value="">Chọn cơ sở có quyền xử lý</option>{(transfer.targets ?? []).map(branch => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></Field>
                <Field label="Giờ đề xuất tại cơ sở đích" error={previewErrors.slot ?? form.errors.slot}><input required name="slot" type="datetime-local" value={form.data.slot} onChange={event => change('slot', event.target.value)} onBlur={event => { if (event.currentTarget.value !== form.data.slot) change('slot', event.currentTarget.value); }} /></Field></div>
            {order.fulfillment === 'delivery' && <Field label="Mã khu vực giao (nếu cần đổi)" error={previewErrors.area_code ?? form.errors.area_code}><input name="area_code" maxLength={80} value={form.data.area_code} onChange={event => change('area_code', event.target.value)} /></Field>}
            <Field label="Lý do đề xuất chuyển" error={form.errors.note}><textarea name="note" required minLength={3} maxLength={250} rows="2" value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></Field>
            <button className="button secondary" disabled={loading || form.processing}>{loading ? 'Đang kiểm tra cơ sở đích…' : 'Xem báo giá trước khi gửi khách'}</button><ErrorList errors={previewErrors} />{failure && <p className="warning" role="alert">{failure}</p>}
            {preview && <div className="transfer-preview form-stack"><h3>{preview.from?.name} → {preview.to?.name}</h3><p>Tổng hiện tại {money(preview.old?.total)} → <strong>{money(preview.new?.total)}</strong> · Chênh lệch {money(preview.delta)}</p>
                <p>Giờ mới: {dateTime(form.data.slot.replace('T', ' '))}</p>{preview.changes?.length > 0 && <ul>{preview.changes.map((changeItem, index) => <li key={index}>{typeof changeItem === 'string' ? changeItem : changeItem.label || changeItem.message || 'Báo giá và lịch nhận được cập nhật theo cơ sở đích.'}</li>)}</ul>}
                <p className="muted">Gửi đề xuất chưa chuyển đơn, tồn hoặc công suất. Khách nhận liên kết xác nhận riêng có thời hạn; giá hoặc tài nguyên đổi khi thực hiện có thể cần đề xuất mới.</p>
                <label className="checkbox"><input type="checkbox" checked={accepted} onChange={event => setAccepted(event.target.checked)} />Tôi đã kiểm tra chênh lệch và muốn gửi đề xuất này cho khách.</label>
                <button type="button" className="button" disabled={!accepted || form.processing || form.data.note.trim().length < 3} onClick={() => {
                    form.transform(data => ({ ...data, slot: data.slot.replace('T', ' '), quote_token: preview.quote_token }));
                    form.post(transfer.create_url, { preserveScroll: true, preserveState: 'errors', onError: () => setAccepted(false) });
                }}>{form.processing ? 'Đang gửi đề xuất…' : 'Gửi đề xuất để khách xác nhận'}</button>
            </div>}<ErrorList errors={form.errors} />
        </form> : <p className="warning">{transfer.blocked_reason || 'Chính sách chuyển đơn chưa được người phụ trách duyệt cho cơ sở này. Dùng luồng hủy/hoàn hiện có với đơn đã thu tiền hoặc đã làm.'}</p>}
        {(transfer.proposals ?? []).map(proposal => <TransferProposal key={proposal.id + ':' + proposal.status} proposal={proposal} />)}
    </section>;
}
