import { LocateFixed, MapPin } from 'lucide-react';
import { useState } from 'react';
import { Field } from './ui';

export async function postLocationJson(url, payload, signal) {
    const cookie = document.cookie.split('; ').find(entry => entry.startsWith('XSRF-TOKEN='));
    const timeout = signal ? null : new AbortController();
    const timer = timeout ? setTimeout(() => timeout.abort(), 12000) : null;
    let response;
    try { response = await fetch(url, {
        method: 'POST', signal: signal ?? timeout?.signal, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.slice(11) ?? '') },
        body: JSON.stringify(payload),
    }); } catch {
        throw new Error('Chưa kết nối được với tiệm. Thông tin bạn nhập vẫn được giữ; hãy kiểm tra kết nối và thử lại.');
    } finally { if (timer) clearTimeout(timer); }
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        const failure = new Error(response.status === 422 ? 'Vui lòng kiểm tra thông tin bên dưới.' : 'Chưa kết nối được với tiệm. Thông tin bạn nhập vẫn được giữ; hãy thử lại.');
        failure.errors = data?.errors ?? {};
        throw failure;
    }
    return data;
}

export function LocationPin({ pin, onChange, error, id = 'location-pin' }) {
    const [finding, setFinding] = useState(false);
    const [status, setStatus] = useState('');
    const coordinates = pin ?? { lat: '', lng: '', confirmed: false };
    const hasCoordinates = coordinates.lat !== '' && coordinates.lng !== '' && coordinates.lat != null && coordinates.lng != null;
    function locate() {
        if (!navigator.geolocation) {
            setStatus('Trình duyệt chưa hỗ trợ lấy vị trí. Bạn vẫn có thể nhập địa chỉ và khu vực thủ công.');
            return;
        }
        setFinding(true);
        setStatus('Đang xin quyền lấy vị trí. Chỉ dùng ghim sau khi bạn xác nhận.');
        navigator.geolocation.getCurrentPosition(position => {
            onChange({ lat: Number(position.coords.latitude.toFixed(6)), lng: Number(position.coords.longitude.toFixed(6)), confirmed: false });
            setFinding(false);
            setStatus('Đã lấy vị trí. Kiểm tra ghim và xác nhận bên dưới; vị trí không tự thay địa chỉ bạn nhập.');
        }, failure => {
            setFinding(false);
            setStatus(failure.code === 1 ? 'Bạn chưa cấp quyền vị trí. Hãy nhập địa chỉ và khu vực thủ công; vẫn có thể đặt bánh.' :
                'Chưa lấy được vị trí. Hãy thử lại hoặc nhập địa chỉ và khu vực thủ công.');
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 });
    }
    return <div className="location-pin form-stack" id={id} tabIndex={-1}>
        <button type="button" className="button secondary compact" disabled={finding} onClick={locate}><LocateFixed size={17} aria-hidden="true" />{finding ? 'Đang lấy vị trí…' : 'Dùng vị trí của tôi (tùy chọn)'}</button>
        <p className="muted" role="status" aria-live="polite">{status}</p>
        {error && <p className="field-error" role="alert">{error}</p>}
        <p className="muted">Không bắt buộc cấp vị trí. Ghim chỉ được gửi khi bạn xác nhận và bấm tiếp tục; không thay địa chỉ giao bánh.</p>
        <details className="fold"><summary>Nhập tọa độ ghim thủ công</summary><div className="two-fields">
            <Field label="Vĩ độ"><input inputMode="decimal" type="number" min="-90" max="90" step="any" value={coordinates.lat ?? ''} onChange={event => onChange({ ...coordinates, lat: event.target.value, confirmed: false })} /></Field>
            <Field label="Kinh độ"><input inputMode="decimal" type="number" min="-180" max="180" step="any" value={coordinates.lng ?? ''} onChange={event => onChange({ ...coordinates, lng: event.target.value, confirmed: false })} /></Field>
        </div></details>
        {hasCoordinates && <div className="location-pin-confirm"><p><MapPin size={16} aria-hidden="true" />Ghim: {coordinates.lat}, {coordinates.lng}</p>
            <label className="checkbox"><input type="checkbox" checked={Boolean(coordinates.confirmed)} onChange={event => onChange({ ...coordinates, confirmed: event.target.checked })} />Tôi đã kiểm tra và đồng ý dùng ghim này cho yêu cầu đang thực hiện.</label>
            <button type="button" className="text-action" onClick={() => { onChange(null); setStatus('Đã bỏ ghim. Bạn có thể tiếp tục bằng địa chỉ thủ công.'); }}>Bỏ ghim</button>
        </div>}
        <p role="status" aria-live="polite" aria-atomic="true" className="muted">{status}</p>
        {error && <p className="field-error" role="alert">{error}</p>}
    </div>;
}

export function confirmedPin(pin) {
    return pin?.confirmed && pin.lat !== '' && pin.lng !== '' ? { lat: Number(pin.lat), lng: Number(pin.lng), confirmed: true } : null;
}
