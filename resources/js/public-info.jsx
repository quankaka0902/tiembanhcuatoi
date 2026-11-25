import { Link, usePage } from '@inertiajs/react';
import { Breadcrumb, Layout } from './ui';

const dayNames = { 1: 'Thứ Hai', 2: 'Thứ Ba', 3: 'Thứ Tư', 4: 'Thứ Năm', 5: 'Thứ Sáu', 6: 'Thứ Bảy', 7: 'Chủ nhật' };

export function PublicInfo({ info }) {
    const { shop } = usePage().props;
    const store = info.store ?? {};
    return <Layout title={info.title}><article className="wrap page public-info-page">
        <Breadcrumb><span>{info.title}</span></Breadcrumb>
        <div className="page-heading"><h1>{info.title}</h1></div>
        <section className="panel form-stack">
            {!info.approved && <p className="warning">Tiệm đang xác nhận nội dung này. Vui lòng kiểm tra lại trước khi dựa vào thông tin để đặt bánh.</p>}
            {info.intro && <p className="policy-text">{info.intro}</p>}
            {info.kind === 'faq' && (info.faqs?.length ? info.faqs.map((item, index) => <details className="fold" key={index}><summary>{item.question}</summary><p className="policy-text">{item.answer}</p></details>) : <p>Tiệm đang cập nhật các câu hỏi và câu trả lời đã xác nhận.</p>)}
            {['contact', 'location'].includes(info.kind) && <>
                <h2>{store.name || shop.name}</h2>
                {store.address && <p><strong>Địa chỉ:</strong> {store.address}</p>}
                {store.phone && <p><strong>Điện thoại:</strong> <a href={`tel:${store.phone.replace(/[^+\d]/g, '')}`}>{store.phone}</a></p>}
                {store.email && <p><strong>Email:</strong> <a href={`mailto:${store.email}`}>{store.email}</a></p>}
                {store.hours && <p><strong>Giờ mở cửa:</strong> {store.hours}</p>}
                {info.openingHours?.length > 0 && <ul>{info.openingHours.map((period, index) => <li key={index}>{period.days.map(day => dayNames[day]).join(', ')}: {period.opens}–{period.closes}</li>)}</ul>}
                {!store.address && !store.phone && !store.email && <p>Thông tin liên hệ của cơ sở đang chờ tiệm xác nhận.</p>}
                {store.maps_url?.startsWith('https://') && <a className="subtle-link" href={store.maps_url} target="_blank" rel="noopener noreferrer">Mở bản đồ cơ sở</a>}
            </>}
            {info.kind === 'about' && !info.intro && <p>Nội dung giới thiệu đang được tiệm cập nhật.</p>}
            <Link className="subtle-link" href="/thuc-don">Xem thực đơn</Link>
        </section>
    </article></Layout>;
}

export function OrderPolicy({ policy, privacy }) {
    const { shop } = usePage().props;
    return <Layout title="Chính sách đặt bánh"><div className="wrap page">
        <Breadcrumb><span>Chính sách đặt bánh</span></Breadcrumb>
        <div className="page-heading"><h1>Chính sách đặt bánh</h1></div>
        <section className="panel form-stack"><p className="policy-text">{policy || 'Vui lòng liên hệ tiệm để hỏi về đổi giờ nhận, hủy đơn và hoàn tiền trước khi đặt bánh.'}</p>
            {shop.phone && <p><a className="subtle-link" href={`tel:${shop.phone.replace(/[^+\d]/g, '')}`}>Gọi tiệm {shop.phone}</a></p>}
            {shop.email && <p><a className="subtle-link" href={`mailto:${shop.email}`}>{shop.email}</a></p>}
        </section>
        {privacy && <section className="panel form-stack"><h2>Xử lý dữ liệu khách hàng</h2><p className="policy-text">{privacy}</p></section>}
    </div></Layout>;
}
