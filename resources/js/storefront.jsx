import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CakeSlice, Clock3, Gift, Heart, MapPin, Search, ShoppingBag, Sparkles, Truck } from 'lucide-react';
import { useState } from 'react';
import { Breadcrumb, ButtonLink, categoriesIcons, Empty, ErrorList, Field, Image, Layout, money, Pagination } from './ui';

function ProductCard({ product }) {
    return <article className="product-card">
        <Link className="product-photo" href={`/banh/${product.slug}`} aria-label={`Xem ${product.name}`}>
            <Image src={product.images[0]} alt={product.name} />
            {Number(product.stock) <= 0 && <span className="photo-badge">Hết hàng</span>}
        </Link>
        <div className="product-body">
            <span className="product-category">{product.category}</span>
            <h3><Link href={`/banh/${product.slug}`}>{product.name}</Link></h3>
            <p>{product.description}</p>
            <div className="product-bottom">
                <strong>{product.price === null ? 'Chưa mở bán' : money(product.price)}</strong>
                <Link className="mini-button" href={`/banh/${product.slug}`} aria-label={`Chọn ${product.name}`}><ArrowRight size={19} /></Link>
            </div>
        </div>
    </article>;
}

export function Home({ categories, featured }) {
    const { shop } = usePage().props;
    return <Layout>
        <section className="hero wrap">
            <div className="hero-content">
                <span className="hero-label"><Sparkles size={15} /> BÁNH TƯƠI LÀM MỖI NGÀY</span>
                <h1>Ngày thường<br /> <em>cũng ngọt ngào.</em></h1>
                <p>Từ bánh kem cho dịp đặc biệt đến chiếc croissant cho buổi sáng, tiệm có một chút ngọt cho mỗi ngày của bạn.</p>
                <div className="hero-actions"><ButtonLink href="/thuc-don">Khám phá thực đơn <ArrowRight size={19} /></ButtonLink><a className="subtle-link" href="#banh-noi-bat">Bánh nổi bật</a></div>
            </div>
            <div className="hero-photo"><img src={featured[0]?.images[0] ?? '/images/strawberry.webp'} alt={featured[0]?.name ?? 'Bánh kem dâu tươi trên đĩa sứ'} fetchPriority="high" />{featured[0] && <div className="hero-caption"><div><small>BÁNH NỔI BẬT</small><Link href={'/banh/' + featured[0].slug}>{featured[0].name} <ArrowRight size={14} /></Link></div></div>}</div>
        </section>
        <div className="wrap service-strip">
            {[[CakeSlice, 'Nướng mới mỗi ngày', 'Từ căn bếp nhỏ của tiệm'], [Heart, 'Lời chúc riêng', 'Thêm lời chúc trên bánh kem'], [Truck, 'Giao tận nơi', 'Chọn khu vực và giờ nhận'], [Gift, 'Nhận tại tiệm', 'Đặt trước, ghé lấy đúng giờ']].map(([Icon, title, copy]) =>
                <div key={title}><span className="service-icon"><Icon size={22} strokeWidth={1.65} /></span><div><strong>{title}</strong><p>{copy}</p></div></div>)}
        </div>
        <section className="wrap home-categories">
            <h2>Chọn bánh theo danh mục</h2>
            {categories.length ? <div className="category-grid">
                {categories.map((category, i) => {
                    const Icon = categoriesIcons[category.icon] ?? CakeSlice;
                    return <Link key={category.id} href={`/thuc-don?category=${category.slug}`}><span className={`category-icon tint-${i % 4}`}><Icon size={33} strokeWidth={1.5} /></span>{category.name}</Link>;
                })}
            </div> : <p>Tiệm đang cập nhật danh mục.</p>}
        </section>
        <section className="wrap featured" id="banh-noi-bat">
            <div className="section-head"><h2>Bánh nổi bật</h2><Link href="/thuc-don" className="subtle-link">Xem toàn bộ <ArrowRight size={16} /></Link></div>
            {featured.length ? <div className="product-grid">{featured.map(product => <ProductCard key={product.id} product={product} />)}</div> : <Empty title="Tiệm đang cập nhật bánh nổi bật" href="/thuc-don" label="Xem thực đơn" />}
        </section>
        {(shop.address || shop.hours) && <section className="wrap visit"><div><h2>Ghé Tiệm Bánh</h2><p><MapPin size={18} /> {shop.address || shop.name}</p><p><Clock3 size={18} /> {shop.hours}</p></div><ButtonLink href="/thuc-don">Đặt bánh ngay <ArrowRight size={18} /></ButtonLink></section>}
    </Layout>;
}

export function Catalog({ products, categories, filters }) {
    const form = useForm({ q: filters.q ?? '', category: filters.category ?? '', min: filters.min ?? '', max: filters.max ?? '', sort: filters.sort ?? 'featured' });
    function search(event) {
        event.preventDefault();
        form.transform(data => Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '')));
        form.get('/thuc-don', { preserveState: true });
    }
    return <Layout title="Thực đơn"><div className="wrap page">
        <Breadcrumb><span>Thực đơn</span></Breadcrumb>
        <div className="page-heading"><h1>Thực đơn</h1><p>Chọn bánh theo khẩu vị, dịp tặng hoặc khoảng giá của bạn.</p></div>
        <form onSubmit={search} className="panel catalog-filters">
            <Field label="Tìm bánh"><input placeholder="Ví dụ bánh kem dâu" value={form.data.q} onChange={e => form.setData('q', e.target.value)} /></Field>
            <Field label="Danh mục"><select value={form.data.category} onChange={e => form.setData('category', e.target.value)}><option value="">Tất cả</option>{categories.map(item => <option key={item.id} value={item.slug}>{item.name}</option>)}</select></Field>
            <Field label="Giá từ (₫)"><input type="number" min="0" value={form.data.min} onChange={e => form.setData('min', e.target.value)} /></Field>
            <Field label="Giá đến (₫)" error={form.errors.max}><input type="number" min="0" value={form.data.max} onChange={e => form.setData('max', e.target.value)} /></Field>
            <Field label="Sắp xếp"><select value={form.data.sort} onChange={e => form.setData('sort', e.target.value)}><option value="featured">Nổi bật</option><option value="new">Mới nhất</option><option value="price_asc">Giá thấp trước</option><option value="price_desc">Giá cao trước</option></select></Field>
            <button className="button" disabled={form.processing}><Search size={17} />{form.processing ? 'Đang tìm…' : 'Tìm bánh'}</button>
        </form>
        <ErrorList errors={form.errors} />
        <p className="results" aria-live="polite">{products.total} loại bánh{filters.q ? ` cho “${filters.q}”` : ''}</p>
        {products.data.length ? <div className="product-grid">{products.data.map(product => <ProductCard key={product.id} product={product} />)}</div> :
            <Empty title="Chưa tìm thấy bánh" text="Thử một từ khóa hoặc khoảng giá khác." href="/thuc-don" label="Bỏ bộ lọc" />}
        <Pagination page={products} />
    </div></Layout>;
}

export function Product({ product }) {
    const [photo, setPhoto] = useState(0);
    const initial = product.variants.find(choice => choice.stock > 0) ?? product.variants[0];
    const form = useForm({ variant_id: initial?.id ?? '', quantity: 1, message: '', note: '' });
    const variant = product.variants.find(option => Number(option.id) === Number(form.data.variant_id));
    const price = Number(variant?.price ?? 0) + (form.data.message.trim() ? Number(product.message_fee) : 0);
    return <Layout title={product.name}><div className="wrap page">
        <Breadcrumb><Link href="/thuc-don">Thực đơn</Link><span aria-hidden="true">/</span><span>{product.name}</span></Breadcrumb>
        <div className="detail-grid">
            <div><div className="detail-photo"><Image src={product.images[photo]} alt={product.name} loading="eager" /></div>
                {product.images.length > 1 && <div className="gallery">{product.images.map((src, index) => <button type="button" className={photo === index ? 'selected' : ''} key={src} aria-label={`Ảnh ${index + 1}`} onClick={() => setPhoto(index)}><img src={src} alt="" /></button>)}</div>}
            </div>
            <section>
                <span className="product-category">{product.category}</span><h1>{product.name}</h1><p className="intro">{product.description}</p>
                <span className="prep"><Clock3 size={16} /> Chuẩn bị khoảng {product.prep_minutes} phút</span>
                {product.ingredients && <p className="detail-copy"><strong>Thành phần</strong>{product.ingredients}</p>}
                {product.allergens && <p className="warning"><strong>Cảnh báo dị ứng</strong> {product.allergens}</p>}
                <form className="form-stack" onSubmit={e => { e.preventDefault(); form.post('/gio-hang', { preserveScroll: true }); }}>
                    <div className="two-fields">
                        <Field label="Phiên bản bánh" error={form.errors.variant_id}><select value={form.data.variant_id} onChange={e => form.setData('variant_id', e.target.value)} disabled={!product.variants.length}>
                            {!product.variants.length && <option>Chưa mở bán</option>}
                            {product.variants.map(option => <option key={option.id} value={option.id}>{option.name} – {money(option.price)}{option.stock <= 0 ? ' (Hết hàng)' : ''}</option>)}
                        </select></Field>
                        <Field label="Số lượng" error={form.errors.quantity}><input type="number" min="1" max={Math.min(30, variant?.stock || 1)} value={form.data.quantity} onChange={e => form.setData('quantity', e.target.value)} required /></Field>
                    </div>
                    {Boolean(product.customizable) && <Field label="Lời chúc trên bánh" hint={product.message_fee ? `Phụ phí ${money(product.message_fee)} mỗi bánh` : undefined} error={form.errors.message}><input placeholder="Ví dụ Chúc mừng sinh nhật!" maxLength={60} value={form.data.message} onChange={e => form.setData('message', e.target.value)} /></Field>}
                    <Field label="Ghi chú cho tiệm" error={form.errors.note}><textarea rows="2" maxLength={250} value={form.data.note} onChange={e => form.setData('note', e.target.value)} /></Field>
                    <div className="detail-actions"><strong>{variant ? money(price * Number(form.data.quantity || 0)) : 'Chưa mở bán'}</strong><button className="button" disabled={form.processing || !variant || variant.stock <= 0}><ShoppingBag size={19} />{form.processing ? 'Đang thêm…' : variant?.stock > 0 ? 'Thêm vào giỏ' : 'Hết hàng'}</button></div>
                </form>
            </section>
        </div>
    </div></Layout>;
}

export function Error({ status }) {
    const titles = { 403: 'Bạn chưa có quyền truy cập', 404: 'Không tìm thấy trang', 429: 'Bạn thao tác quá nhanh', 500: 'Tiệm tạm thời gặp sự cố' };
    return <Layout title={titles[status]}><div className="wrap page"><Empty title={titles[status]} text={status === 404 ? 'Nếu đang tra cứu đơn, hãy kiểm tra liên kết trong email xác nhận.' : 'Vui lòng thử lại sau hoặc trở về trang chủ.'} href="/" label="Về trang chủ" /></div></Layout>;
}

export function Auth({ mode, email, token }) {
    const form = useForm({ name: '', email: email ?? '', password: '', password_confirmation: '', token: token ?? '' });
    const titles = { login: 'Đăng nhập', register: 'Đăng ký', forgot: 'Khôi phục mật khẩu', reset: 'Đặt lại mật khẩu' };
    const paths = { login: '/dang-nhap', register: '/dang-ky', forgot: '/quen-mat-khau', reset: '/dat-lai-mat-khau' };
    return <Layout title={titles[mode]}><div className="wrap page">
        <div className="panel auth-card"><span className="category-icon"><CakeSlice size={30} /></span><h1>{titles[mode]}</h1>
            <form className="form-stack" onSubmit={e => { e.preventDefault(); form.post(paths[mode], { onFinish: () => form.reset('password', 'password_confirmation') }); }}>
                {mode === 'register' && <Field label="Họ và tên" error={form.errors.name}><input autoComplete="name" value={form.data.name} onChange={e => form.setData('name', e.target.value)} required /></Field>}
                <Field label="Email" error={form.errors.email}><input type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} required /></Field>
                {mode !== 'forgot' && <Field label="Mật khẩu" error={form.errors.password}><input type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} value={form.data.password} onChange={e => form.setData('password', e.target.value)} required /></Field>}
                {['register', 'reset'].includes(mode) && <Field label="Nhập lại mật khẩu"><input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={e => form.setData('password_confirmation', e.target.value)} required /></Field>}
                <button className="button" disabled={form.processing}>{form.processing ? 'Đang xử lý…' : mode === 'forgot' ? 'Gửi liên kết khôi phục' : titles[mode]}<ArrowRight size={18} /></button>
            </form>
            {mode === 'login' ? <div className="auth-links"><Link href="/quen-mat-khau">Quên mật khẩu?</Link><Link href="/dang-ky">Đăng ký</Link></div> : <Link className="subtle-link auth-back" href="/dang-nhap"><ArrowLeft size={16} />Đăng nhập</Link>}
        </div>
    </div></Layout>;
}
