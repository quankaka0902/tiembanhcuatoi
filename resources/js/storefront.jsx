import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CakeSlice, Check, Clock3, Gift, MapPin, Search, ShoppingBag, SlidersHorizontal, Truck, UsersRound } from 'lucide-react';
import { useRef, useState } from 'react';
import { Breadcrumb, ButtonLink, categoriesIcons, Empty, ErrorList, Field, Layout, money, Pagination } from './ui';

const verified = value => value === true || value === 1 || value === '1';
const catalogFields = ['q', 'category', 'occasion', 'guests', 'flavor', 'min', 'max', 'availability', 'sort', 'page'];

function queryString(filters = {}) {
    return new URLSearchParams(Object.entries(filters).filter(([key, value]) => catalogFields.includes(key) && value !== '' && value != null)).toString();
}

function catalogUrl(filters = {}) {
    const query = queryString(filters);
    return '/thuc-don' + (query ? '?' + query : '');
}

function CakePhoto({ src, alt = '', className = '', loading = 'lazy', priority = false, placeholder = 'Ảnh đang được cập nhật', decorative = false }) {
    const [failedSource, setFailedSource] = useState(null);
    return src && src !== failedSource ? <img src={src} alt={alt} className={className} loading={loading} fetchPriority={priority ? 'high' : undefined} onError={() => setFailedSource(src)} /> :
        <span className={'sf-photo-placeholder ' + className} role={decorative ? undefined : 'img'} aria-hidden={decorative || undefined} aria-label={decorative ? undefined : alt || placeholder}><CakeSlice size={40} strokeWidth={1.3} /><span>{placeholder}</span></span>;
}

function ProductCard({ product, filters = {} }) {
    const query = queryString(filters);
    const href = '/banh/' + encodeURIComponent(product.slug) + (query ? '?' + query : '');
    const hasPrice = product.price !== null && product.price !== undefined;
    const varies = verified(product.price_varies) || (hasPrice && product.max_price != null && Number(product.max_price) > Number(product.price));
    const soldOut = hasPrice && product.stock != null && Number(product.stock) <= 0;
    return <article className="product-card sf-product-card">
        <Link className="sf-card-link" href={href} aria-labelledby={'sf-card-name-' + product.id} aria-describedby={`sf-card-price-${product.id} sf-card-details-${product.id}${soldOut ? ' sf-card-stock-' + product.id : ''}${!verified(product.images_approved) ? ' sf-card-image-' + product.id : ''}`}>
            <div className="product-photo sf-card-photo">
                <CakePhoto src={product.images?.[0]} alt="" decorative />
                {soldOut && <span className="photo-badge" id={'sf-card-stock-' + product.id}>Hết hàng</span>}
                {!verified(product.images_approved) && <span className="sf-image-note" id={'sf-card-image-' + product.id}>Ảnh minh họa / chờ xác minh</span>}
            </div>
            <div className="product-body">
                <span className="product-category">{product.category}</span>
                <h3 id={'sf-card-name-' + product.id}>{product.name}</h3>
                <p>{product.description}</p>
                <div className="sf-card-meta" id={'sf-card-details-' + product.id}><span>{verified(product.details_verified) ? 'Xem cỡ và thông tin bánh' : 'Thông tin chờ tiệm xác minh'}</span></div>
                <div className="product-bottom"><strong id={'sf-card-price-' + product.id}>{hasPrice ? (varies ? 'Từ ' : '') + money(product.price) : 'Chưa mở bán'}</strong><span className="sf-card-arrow" aria-hidden="true"><ArrowRight size={19} /></span></div>
            </div>
        </Link>
    </article>;
}

function CurrentLocation({ location, compact = false }) {
    const { shop } = usePage().props;
    const current = location ?? shop;
    const regions = current.regions ?? [];
    return <section className={'sf-location panel ' + (compact ? 'sf-location-compact' : '')} id={compact ? undefined : 'co-so-dang-phuc-vu'} aria-label="Cơ sở đang phục vụ">
        <div className="sf-location-heading"><MapPin size={21} /><div><small>Cơ sở đang phục vụ</small><h2>{current.name || shop.name}</h2></div></div>
        <div className="sf-location-details">
            <p>{current.address || 'Tiệm chưa cập nhật địa chỉ nhận bánh.'}</p>
            <p><Clock3 size={16} />{current.hours || 'Liên hệ tiệm để xác nhận giờ mở cửa.'}</p>
        </div>
        {compact && <details className="sf-location-delivery"><summary>Vùng giao và phí giao</summary>{regions.length ? <ul>{regions.map(region => <li key={region.key ?? region.name}>{region.name}{region.fee != null && <span> · {money(region.fee)}</span>}</li>)}</ul> : <p>Tiệm chưa công bố vùng giao hàng.</p>}{current.free_from != null && <p>Miễn phí giao từ {money(current.free_from)} tiền bánh sau giảm giá.</p>}</details>}
        {!compact && <div className="sf-location-regions"><strong>Khu vực giao hàng</strong>
            {regions.length ? <><ul>{regions.map(region => <li key={region.key ?? region.name}>{region.name}{region.fee != null && <span> · {money(region.fee)}</span>}</li>)}</ul>
                {current.free_from != null && <p className="muted">Miễn phí giao từ {money(current.free_from)} tiền bánh sau giảm giá.</p>}</> : <p className="muted">Tiệm chưa công bố vùng giao hàng. Xem lựa chọn giao hoặc nhận khi đặt bánh.</p>}
        </div>}
        {!compact && <p className="muted">Giờ nhận còn chỗ và phí giao được kiểm tra lại ở bước đặt bánh.</p>}
    </section>;
}

export function Home({ categories = [], featured = [], occasions = [], currentLocation }) {
    const heroProduct = featured.find(product => verified(product.images_approved) && product.images?.length);
    return <Layout><div className="storefront-home">
        <section className="hero wrap sf-hero">
            <div className="hero-content"><span className="sf-eyebrow">Một chút ngọt cho dịp của bạn</span>
                <h1>Chọn chiếc bánh<br />cho <em>dịp của bạn.</em></h1>
                <p>Xem cỡ bánh, khẩu phần và giá trước khi chọn. Chọn giờ giao hoặc nhận tại tiệm ở bước đặt bánh.</p>
                <div className="hero-actions"><ButtonLink href="/thuc-don">Chọn bánh <ArrowRight size={19} /></ButtonLink><a className="subtle-link" href="#co-so-dang-phuc-vu">Giờ mở cửa và vùng giao</a></div>
            </div>
            <div className="hero-photo sf-hero-photo"><CakePhoto src={heroProduct?.images[0]} alt={heroProduct?.name ?? 'Tiệm đang bổ sung ảnh bánh đã xác nhận'} loading="eager" priority placeholder="Tiệm đang bổ sung ảnh bánh đã xác nhận" />
                {heroProduct && <div className="hero-caption"><div><small>GỢI Ý TỪ TIỆM</small><Link href={'/banh/' + heroProduct.slug}>{heroProduct.name} <ArrowRight size={14} /></Link></div></div>}
            </div>
        </section>
        <div className="wrap sf-service-strip">
            {[[UsersRound, 'Chọn đúng cỡ', 'Xem khẩu phần được tiệm xác nhận'], [Gift, 'Dành riêng cho bạn', 'Lời chúc cho bánh có hỗ trợ'], [Truck, 'Chủ động cách nhận', 'Xem vùng giao và khung giờ khi đặt']].map(([Icon, title, copy]) => <div key={title}><Icon size={22} strokeWidth={1.6} /><div><strong>{title}</strong><p>{copy}</p></div></div>)}
        </div>
        <section className="wrap sf-occasion-section"><div className="section-head"><div><span className="sf-eyebrow">Bắt đầu từ dịp của bạn</span><h2>Bạn chọn bánh cho dịp nào?</h2></div></div>
            {occasions.length ? <nav className="sf-occasion-links" aria-label="Chọn bánh theo dịp">{occasions.map(occasion => <Link key={occasion} href={catalogUrl({ occasion })}><Gift size={19} />{occasion}<ArrowRight size={17} /></Link>)}</nav> : <p className="muted">Tiệm đang bổ sung gợi ý theo dịp. Bạn có thể chọn bánh trong thực đơn.</p>}
        </section>
        <section className="wrap home-categories sf-home-categories"><h2>Hoặc chọn loại bánh bạn thích</h2>
            {categories.length ? <div className="category-grid">{categories.map((category, i) => { const Icon = categoriesIcons[category.icon] ?? CakeSlice; return <Link key={category.id} href={catalogUrl({ category: category.slug })}><span className={`category-icon tint-${i % 4}`}><Icon size={30} strokeWidth={1.5} /></span>{category.name}</Link>; })}</div> : <p className="muted">Tiệm đang cập nhật danh mục.</p>}
        </section>
        <section className="wrap featured sf-featured" id="banh-noi-bat"><div className="section-head"><div><span className="sf-eyebrow">Chọn và xem kỹ trước khi đặt</span><h2>Bánh nổi bật</h2></div><Link href="/thuc-don" className="subtle-link">Xem tất cả <ArrowRight size={16} /></Link></div>
            {featured.length ? <div className="product-grid">{featured.map(product => <ProductCard key={product.id} product={product} />)}</div> : <Empty title="Tiệm đang cập nhật bánh" href="/thuc-don" label="Xem thực đơn" />}
        </section>
        <div className="wrap sf-home-location"><CurrentLocation location={currentLocation} /></div>
    </div></Layout>;
}

function CatalogView({ products, categories, filters, filterOptions, currentLocation }) {
    const form = useForm({ q: filters.q ?? '', category: filters.category ?? '', occasion: filters.occasion ?? '', guests: filters.guests ?? '', flavor: filters.flavor ?? '', min: filters.min ?? '', max: filters.max ?? '', availability: filters.availability ?? 'all', sort: filters.sort ?? 'featured' });
    const filterDetails = useRef(null);
    const formElement = useRef(null);
    const chips = [
        ['q', filters.q && `Tìm: ${filters.q}`],
        ['category', filters.category && (categories.find(category => category.slug === filters.category)?.name ?? filters.category)],
        ['occasion', filters.occasion], ['guests', filters.guests && `${filters.guests} người`], ['flavor', filters.flavor],
        ['min', filters.min != null && filters.min !== '' && `Từ ${money(filters.min)}`], ['max', filters.max != null && filters.max !== '' && `Đến ${money(filters.max)}`],
        ['availability', filters.availability === 'available' ? 'Còn hàng' : filters.availability === 'sold_out' ? 'Hết hàng' : null],
    ].filter(([, label]) => label);
    function search(event) {
        event.preventDefault();
        form.transform(data => Object.fromEntries(Object.entries(data).filter(([, value]) => value !== '')));
        form.get('/thuc-don', { preserveState: true, preserveScroll: true, onError: () => {
            if (filterDetails.current) filterDetails.current.open = true;
            requestAnimationFrame(() => formElement.current?.querySelector('[aria-invalid="true"]')?.focus());
        } });
    }
    return <Layout title="Thực đơn"><div className="wrap page storefront-catalog">
        <Breadcrumb><span>Thực đơn</span></Breadcrumb>
        <div className="page-heading sf-catalog-heading"><span className="sf-eyebrow">Chọn bánh vừa dịp, vừa khẩu vị</span><h1>Hôm nay bạn chọn bánh gì?</h1><p>Lọc theo số người, dịp tặng hoặc khoảng giá. Khẩu phần chỉ dùng thông tin đã được tiệm xác nhận.</p></div>
        <CurrentLocation location={currentLocation} compact />
        <form ref={formElement} onSubmit={search} className="panel sf-catalog-form" aria-label="Tìm và lọc bánh" aria-busy={form.processing}>
            <div className="sf-search-row"><Field label="Tìm bánh" error={form.errors.q}><input type="search" name="q" maxLength={100} placeholder="Tên bánh hoặc điều bạn đang tìm…" value={form.data.q} aria-invalid={Boolean(form.errors.q)} onChange={e => form.setData('q', e.target.value)} /></Field><button className="button" disabled={form.processing}><Search size={18} />{form.processing ? 'Đang tìm…' : 'Tìm bánh'}</button></div>
            <details className="sf-filter-disclosure" ref={filterDetails}><summary><SlidersHorizontal size={19} /><span>Lọc theo dịp, cỡ bánh và giá</span><span className="sf-filter-count">{chips.length ? chips.length + ' đang chọn' : 'Mở bộ lọc'}</span></summary>
                <div className="sf-filter-grid">
                    <Field label="Danh mục" error={form.errors.category}><select name="category" value={form.data.category} aria-invalid={Boolean(form.errors.category)} onChange={e => form.setData('category', e.target.value)}><option value="">Tất cả loại bánh</option>{categories.map(item => <option key={item.id} value={item.slug}>{item.name}</option>)}</select></Field>
                    <Field label="Dịp đặt bánh" error={form.errors.occasion}><select name="occasion" value={form.data.occasion} aria-invalid={Boolean(form.errors.occasion)} onChange={e => form.setData('occasion', e.target.value)}><option value="">Tất cả dịp</option>{(filterOptions.occasions ?? []).map(value => <option key={value} value={value}>{value}</option>)}</select></Field>
                    <Field label="Số người ăn" hint="Chỉ lọc khẩu phần đã xác nhận" error={form.errors.guests}><input type="number" name="guests" min="1" max="200" inputMode="numeric" placeholder="Ví dụ 8" value={form.data.guests} aria-invalid={Boolean(form.errors.guests)} onChange={e => form.setData('guests', e.target.value)} /></Field>
                    <Field label="Hương vị" error={form.errors.flavor}><select name="flavor" value={form.data.flavor} aria-invalid={Boolean(form.errors.flavor)} onChange={e => form.setData('flavor', e.target.value)}><option value="">Tất cả hương vị</option>{(filterOptions.flavors ?? []).map(value => <option key={value} value={value}>{value}</option>)}</select></Field>
                    <Field label="Giá từ (₫)" error={form.errors.min}><input type="number" name="min" min="0" max="100000000" inputMode="numeric" value={form.data.min} aria-invalid={Boolean(form.errors.min)} onChange={e => form.setData('min', e.target.value)} /></Field>
                    <Field label="Giá đến (₫)" error={form.errors.max}><input type="number" name="max" min="0" max="100000000" inputMode="numeric" value={form.data.max} aria-invalid={Boolean(form.errors.max)} onChange={e => form.setData('max', e.target.value)} /></Field>
                    <Field label="Tình trạng" error={form.errors.availability}><select name="availability" value={form.data.availability} aria-invalid={Boolean(form.errors.availability)} onChange={e => form.setData('availability', e.target.value)}><option value="all">Tất cả</option><option value="available">Còn hàng</option><option value="sold_out">Hết hàng</option></select></Field>
                    <Field label="Sắp xếp" error={form.errors.sort}><select name="sort" value={form.data.sort} aria-invalid={Boolean(form.errors.sort)} onChange={e => form.setData('sort', e.target.value)}><option value="featured">Nổi bật</option><option value="new">Mới nhất</option><option value="price_asc">Giá thấp trước</option><option value="price_desc">Giá cao trước</option></select></Field>
                    <p className="muted sf-budget-note">Khoảng giá là giá bánh theo cỡ, chưa gồm lời chúc có phí, phụ kiện và phí giao. Các điều kiện cỡ và giá được áp dụng cho cùng một phiên bản bánh.</p>
                    <div className="row-actions sf-filter-actions"><button className="button" disabled={form.processing}>{form.processing ? 'Đang lọc…' : 'Áp dụng bộ lọc'}</button><Link className="subtle-link" href="/thuc-don">Xóa bộ lọc</Link></div>
                </div>
            </details>
            <ErrorList errors={form.errors} />
        </form>
        {chips.length > 0 && <nav className="sf-active-filters" aria-label="Bộ lọc đang áp dụng">{chips.map(([key, label]) => <Link key={key} href={catalogUrl({ ...filters, [key]: '', page: '' })} aria-label={'Bỏ bộ lọc ' + label}>{label}<span aria-hidden="true">×</span></Link>)}</nav>}
        <div className="sf-results-heading"><p className="results" aria-live="polite">{form.processing ? 'Đang tìm bánh phù hợp…' : `${products.total} loại bánh${filters.q ? ` cho “${filters.q}”` : ''}`}</p>{chips.length > 0 && <Link href="/thuc-don" className="subtle-link">Xem tất cả bánh</Link>}</div>
        <div aria-busy={form.processing}>{products.data.length ? <div className="product-grid">{products.data.map(product => <ProductCard key={product.id} product={product} filters={filters} />)}</div> : <Empty title="Chưa có bánh khớp lựa chọn" text="Thử đổi số người, khoảng giá hoặc bỏ một bộ lọc. Thông tin chưa được tiệm xác nhận sẽ không được dùng để gợi ý khẩu phần." href="/thuc-don" label="Xem tất cả bánh" />}</div>
        <Pagination page={products} />
    </div></Layout>;
}

export function Catalog({ products, categories = [], filters = {}, filterOptions = { occasions: [], flavors: [] }, currentLocation }) {
    return <CatalogView key={JSON.stringify(filters)} products={products} categories={categories} filters={filters} filterOptions={filterOptions} currentLocation={currentLocation} />;
}

function servingLabel(variant) {
    if (!verified(variant.servings_verified) || variant.servings_min == null || variant.servings_max == null) return 'Khẩu phần chưa được tiệm xác nhận';
    return Number(variant.servings_min) === Number(variant.servings_max) ? `Khoảng ${variant.servings_min} người` : `Khoảng ${variant.servings_min}–${variant.servings_max} người`;
}

export function Product({ product, suggestedVariantId }) {
    const { shop } = usePage().props;
    const pageUrl = usePage().url;
    const filters = Object.fromEntries(new URLSearchParams(pageUrl.split('?')[1] ?? ''));
    const hasSelectionFilters = ['guests', 'min', 'max'].some(field => filters[field] != null && filters[field] !== '') || ['available', 'sold_out'].includes(filters.availability);
    const suggested = product.variants.find(choice => Number(choice.id) === Number(suggestedVariantId));
    const initial = suggested ?? (hasSelectionFilters && suggestedVariantId === null ? null : product.variants.find(choice => Number(choice.stock) > 0) ?? product.variants[0]);
    const [photo, setPhoto] = useState(0);
    const formElement = useRef(null);
    const form = useForm({ variant_id: initial?.id ?? '', quantity: 1, message: '', note: '', accessories: [] });
    const variant = product.variants.find(option => Number(option.id) === Number(form.data.variant_id));
    const accessories = product.accessories ?? [];
    const accessoriesPrice = accessories.filter(item => form.data.accessories.includes(item.id)).reduce((sum, item) => sum + Number(item.price), 0);
    const messagePrice = form.data.message.trim() ? Number(product.message_fee) : 0;
    const price = Number(variant?.price ?? 0) + messagePrice + accessoriesPrice;
    const inStock = variant && Number(variant.stock) > 0;
    const pictures = product.images ?? [];
    function submit(event) {
        event.preventDefault();
        form.post('/gio-hang', { preserveScroll: true, onError: () => requestAnimationFrame(() => {
            (formElement.current?.querySelector('[aria-invalid="true"]') ?? formElement.current?.querySelector('[name="variant_id"]'))?.focus();
        }) });
    }
    return <Layout title={product.name}><div className="wrap page storefront-product">
        <Breadcrumb><Link href={catalogUrl(filters)}>Thực đơn</Link><span aria-hidden="true">/</span><span>{product.name}</span></Breadcrumb>
        <div className="detail-grid sf-product-layout">
            <div className="sf-product-gallery"><div className="detail-photo"><CakePhoto src={pictures[photo]} alt={`${product.name}${pictures.length > 1 ? ` — ảnh ${photo + 1}` : ''}`} loading="eager" priority /></div>
                <p className="sf-photo-status">{verified(product.images_approved) ? 'Ảnh đã được tiệm xác nhận.' : 'Ảnh minh họa / chưa được tiệm xác minh. Hãy đối chiếu yêu cầu riêng với tiệm.'}</p>
                {pictures.length > 1 && <div className="gallery sf-gallery" role="group" aria-label="Chọn ảnh bánh">{pictures.map((src, index) => <button type="button" className={photo === index ? 'selected' : ''} key={src + index} aria-label={`Xem ảnh ${index + 1} của ${product.name}`} aria-pressed={photo === index} onClick={() => setPhoto(index)}><CakePhoto src={src} alt="" decorative />{photo === index && <span className="sf-gallery-check" aria-hidden="true"><Check size={13} /></span>}</button>)}</div>}
            </div>
            <section className="sf-product-info"><span className="product-category">{product.category}</span><h1>{product.name}</h1>
                <p className="intro">{product.description}</p>
                <div className="sf-product-price"><strong>{variant ? money(variant.price) : product.variants.length ? 'Chọn cỡ bánh để xem giá' : 'Chưa mở bán'}</strong>{variant && <span>/ bánh, chưa gồm tùy chọn</span>}</div>
                <p className="sf-lead-time"><Clock3 size={17} />Thời gian chuẩn bị: {Number(product.prep_minutes) > 0 ? `${product.prep_minutes} phút` : 'Xem khung giờ khi đặt'}<small>Đây là thời gian chuẩn bị. Giờ giao hoặc nhận còn chỗ được kiểm tra khi đặt bánh.</small></p>
                {hasSelectionFilters && suggestedVariantId === null && <p className="warning" role="status">Chưa có cỡ bánh khớp đầy đủ lựa chọn bạn mang từ thực đơn. Hãy xem và chọn lại cỡ phù hợp bên dưới.</p>}
                {!verified(product.details_verified) && <p className="sf-verification-note">Thông tin chi tiết đang chờ tiệm xác minh. Nếu bạn có yêu cầu về khẩu phần hoặc chế độ ăn, hãy liên hệ tiệm trước khi đặt.</p>}
                <section className="sf-allergen-info"><h2>Cảnh báo dị ứng</h2><p>{product.allergens || 'Tiệm chưa cung cấp cảnh báo dị ứng. Vui lòng hỏi tiệm nếu bạn có dị ứng hoặc chế độ ăn cần lưu ý.'}</p></section>
                <form ref={formElement} className="form-stack sf-product-form" onSubmit={submit} aria-busy={form.processing}>
                    <fieldset className="sf-variant-fieldset" tabIndex={-1} aria-invalid={Boolean(form.errors.variant_id)} aria-describedby={'sf-variant-help' + (form.errors.variant_id ? ' sf-variant-error' : '')}><legend>1. Chọn cỡ bánh</legend><p className="muted" id="sf-variant-help">Giá và khẩu phần được hiển thị theo từng phiên bản.</p>
                        {product.variants.length ? <div className="sf-variant-options">{product.variants.map(option => <label key={option.id} className={'sf-variant-option' + (Number(form.data.variant_id) === Number(option.id) ? ' is-selected' : '') + (Number(option.stock) <= 0 ? ' is-unavailable' : '')}>
                            <input type="radio" name="variant_id" required value={option.id} checked={Number(form.data.variant_id) === Number(option.id)} disabled={Number(option.stock) <= 0} aria-invalid={Boolean(form.errors.variant_id)} onChange={() => form.setData('variant_id', option.id)} />
                            <span><strong>{option.name}</strong>{option.size_label && <small>{option.size_label}</small>}<small>{servingLabel(option)}</small>{Number(option.stock) <= 0 && <small className="sf-stock-warning">Hết hàng</small>}</span><b>{money(option.price)}</b>
                        </label>)}</div> : <p className="warning">Tiệm chưa có phiên bản đang bán cho bánh này.</p>}
                        {form.errors.variant_id && <p id="sf-variant-error" className="field-error" role="alert">{form.errors.variant_id}</p>}
                    </fieldset>
                    {variant && !inStock && <p className="warning" role="status">Cỡ bánh này đang hết hàng. Hãy chọn cỡ khác hoặc liên hệ tiệm.</p>}
                    <div className="sf-quantity-row"><Field label="2. Số lượng bánh" error={form.errors.quantity}><input type="number" name="quantity" min="1" max={Math.min(30, Number(variant?.stock) || 1)} inputMode="numeric" value={form.data.quantity} aria-invalid={Boolean(form.errors.quantity)} onChange={e => form.setData('quantity', e.target.value)} required /></Field>{variant && <p className="muted">{inStock ? `Còn ${variant.stock} bánh ở phiên bản này` : 'Phiên bản đang hết hàng'}</p>}</div>
                    {verified(product.customizable) && <Field label="Lời chúc trên bánh (tùy chọn)" hint={Number(product.message_fee) ? `Thêm ${money(product.message_fee)} cho mỗi bánh có lời chúc.` : 'Không tính thêm phí lời chúc.'} error={form.errors.message}><input name="message" placeholder="Ví dụ: Chúc mừng sinh nhật!" maxLength={60} value={form.data.message} aria-invalid={Boolean(form.errors.message)} onChange={e => form.setData('message', e.target.value)} /></Field>}
                    {accessories.length > 0 && <fieldset className="sf-accessory-fieldset" tabIndex={-1} aria-invalid={Object.keys(form.errors).some(key => key.startsWith('accessories'))}><legend>Thêm phụ kiện (tùy chọn)</legend><p className="muted">Mỗi lựa chọn gồm 1 phụ kiện cho mỗi bánh. Phí nhân theo số bánh.</p><div className="sf-accessory-options">{accessories.map(item => <label key={item.id}><input type="checkbox" name="accessories" value={item.id} checked={form.data.accessories.includes(item.id)} onChange={event => form.setData('accessories', event.target.checked ? [...form.data.accessories, item.id] : form.data.accessories.filter(id => id !== item.id))} /><span>{item.name}</span><strong>+{money(item.price)}/bánh</strong></label>)}</div></fieldset>}
                    <Field label="Ghi chú cho tiệm (tùy chọn)" hint="Tiệm cần xác nhận riêng các yêu cầu làm thay đổi sản phẩm hoặc giá." error={form.errors.note}><textarea name="note" rows="2" maxLength={250} value={form.data.note} aria-invalid={Boolean(form.errors.note)} onChange={e => form.setData('note', e.target.value)} /></Field>
                    <ErrorList errors={form.errors} />
                    <div className="sf-product-buy"><div><small>Tạm tính {Number(form.data.quantity) || 0} bánh</small><strong>{variant ? money(price * Number(form.data.quantity || 0)) : 'Chưa chọn cỡ bánh'}</strong><small>{messagePrice || accessoriesPrice ? 'Đã gồm tùy chọn đã chọn; chưa gồm phí giao.' : 'Chưa gồm phí giao.'}</small></div><button className="button" disabled={form.processing || !inStock}><ShoppingBag size={19} />{form.processing ? 'Đang thêm…' : !variant ? 'Chọn cỡ bánh' : !inStock ? 'Cỡ bánh hết hàng' : 'Thêm vào giỏ'}</button></div>
                </form>
                <section className="sf-product-details" aria-label="Thông tin trước khi đặt"><h2>Trước khi bạn đặt bánh</h2>
                    <details open><summary>Thành phần</summary><p>{product.ingredients || 'Thông tin thành phần đang được tiệm cập nhật.'}</p></details>
                    <details><summary>Bảo quản bánh</summary><p>{product.storage_instructions || 'Hướng dẫn bảo quản chưa được tiệm xác nhận. Hãy hỏi tiệm khi nhận bánh.'}</p></details>
                    {product.flavors?.length > 0 && <p><strong>Hương vị:</strong> {product.flavors.join(', ')}</p>}
                    {product.occasions?.length > 0 && <p><strong>Dịp gợi ý:</strong> {product.occasions.join(', ')}</p>}
                    {shop.phone && <a className="subtle-link" href={`tel:${shop.phone.replace(/[^+\d]/g, '')}`}>Hỏi tiệm về bánh · {shop.phone}</a>}
                </section>
            </section>
        </div>
    </div></Layout>;
}

export function Error({ status }) {
    const titles = { 403: 'Bạn chưa có quyền truy cập', 404: 'Không tìm thấy trang', 429: 'Bạn thao tác quá nhanh', 500: 'Tiệm tạm thời gặp sự cố' };
    return <Layout title={titles[status]}><div className="wrap page"><Empty title={titles[status]} text={status === 404 ? 'Nếu đang tra cứu đơn, hãy kiểm tra liên kết trong email xác nhận.' : 'Vui lòng thử lại sau hoặc trở về trang chủ.'} href="/" label="Về trang chủ" /></div></Layout>;
}

function StaffChallenge() {
    const form = useForm({ code: '' });
    const title = 'Xác thực hai bước';
    return <Layout title={title}><div className="wrap page">
        <div className="panel auth-card"><h1>{title}</h1><p>Nhập mã 6 số trên ứng dụng xác thực của tài khoản nội bộ.</p>
            <form className="form-stack" onSubmit={event => {
                event.preventDefault();
                form.post('/xac-thuc-hai-buoc', { onFinish: () => form.reset('code') });
            }}>
                <Field label="Mã xác thực" error={form.errors.code}>
                    <input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={form.data.code}
                        onChange={event => form.setData('code', event.target.value)} required />
                </Field>
                <button className="button" disabled={form.processing}>{form.processing ? 'Đang kiểm tra…' : 'Xác thực'}<ArrowRight size={18} /></button>
            </form>
            <Link className="subtle-link auth-back" href="/dang-nhap"><ArrowLeft size={16} />Về đăng nhập</Link>
        </div>
    </div></Layout>;
}

function AuthForm({ mode, email, token }) {
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

export function Auth(props) {
    return props.mode === 'two-factor' ? <StaffChallenge /> : <AuthForm {...props} />;
}
