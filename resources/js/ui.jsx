import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CakeSlice, Check, Clock3, Cookie, Croissant, CupSoda, LayoutDashboard, MapPin, Menu, Phone, Search, ShoppingBag, UserRound } from 'lucide-react';
import { Children, cloneElement, isValidElement, useId, useState } from 'react';

export const money = value => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(Number(value));
export const date = value => value ? `${value.slice(8, 10)}/${value.slice(5, 7)}/${value.slice(0, 4)}` : '';
export const dateTime = value => value ? `${date(value)} · ${value.slice(11, 16)}` : '';
export const categoriesIcons = { cake: CakeSlice, croissant: Croissant, cupcake: CupSoda, cookie: Cookie };

export function Brand({ shop }) {
    const [failedLogo, setFailedLogo] = useState(false);
    const logo = shop.logo_asset;
    return <Link href="/" className="brand" aria-label={shop.name}>
        {shop.logo && !failedLogo ? <img src={logo?.src || shop.logo} srcSet={logo?.srcset || undefined} sizes={logo?.srcset ? '44px' : undefined} width="44" height="44" loading="eager" decoding="async" alt="" onError={() => setFailedLogo(true)} /> : <span className="brand-mark"><CakeSlice size={23} strokeWidth={1.8} /></span>}
        <span>{shop.name}</span>
    </Link>;
}

export function Layout({ title, children }) {
    const { shop, auth, cartCount, notice, seo } = usePage().props;
    const path = usePage().url.split('?')[0];
    const internal = auth && auth.role !== 'customer';
    return <>
        {!seo && <Head title={title ? `${title} | ${shop.name}` : shop.name} />}
        <a className="skip" href="#main">Chuyển đến nội dung</a>
        <header className="site-header">
            <div className="wrap header-inner">
                <Brand shop={shop} />
                <nav className="desktop-nav" aria-label="Điều hướng chính">
                    {[['/', 'Trang chủ'], ['/thuc-don', 'Thực đơn'], ['/tra-cuu', 'Tra cứu đơn']].map(([href, label]) =>
                        <Link key={href} href={href} className={path === href ? 'current' : ''} aria-current={path === href ? 'page' : undefined}>{label}</Link>)}
                </nav>
                <div className="header-actions">
                    <Link className="icon-link search-link" href="/thuc-don" aria-label="Tìm bánh"><Search size={21} /></Link>
                    <Link className="icon-link account-link" href={internal ? '/quan-tri' : auth ? '/don-cua-toi' : '/dang-nhap'} aria-label={internal ? 'Quản trị' : auth ? 'Đơn của tôi' : 'Đăng nhập'}>{internal ? <LayoutDashboard size={21} /> : <UserRound size={21} />}</Link>
                    <Link className="cart-button" href="/gio-hang" aria-label={`Giỏ hàng, ${cartCount} bánh`}><ShoppingBag size={19} /><span className="cart-label">Giỏ hàng</span><b>{cartCount}</b></Link>
                    <details className="mobile-menu">
                        <summary aria-label="Mở điều hướng"><Menu size={22} /></summary>
                        <nav aria-label="Điều hướng điện thoại">
                            <Link href="/">Trang chủ</Link><Link href="/thuc-don">Thực đơn</Link><Link href="/tra-cuu">Tra cứu đơn</Link>
                            <Link href={internal ? '/quan-tri' : auth ? '/don-cua-toi' : '/dang-nhap'}>{internal ? 'Quản trị' : auth ? 'Đơn của tôi' : 'Đăng nhập'}</Link>
                            {auth && <Link as="button" method="post" href="/dang-xuat">Đăng xuất</Link>}
                        </nav>
                    </details>
                </div>
            </div>
        </header>
        <main id="main" tabIndex={-1}>
            {shop.branch_mode && <div className="wrap branch-selector-wrap"><BranchSelector /></div>}
            {!shop.accepting_orders && <div className="wrap pause-wrap"><p className="warning pause-banner" role="status">
                <span>{shop.pause_message}</span>
                {shop.phone && <a className="subtle-link" href={`tel:${shop.phone.replace(/[^+\d]/g, '')}`}>Gọi tiệm {shop.phone}</a>}
            </p></div>}
            {notice && <div className="wrap toast" role="status"><Check size={18} />{notice}</div>}
            {children}
        </main>
        <footer className="site-footer">
            <div className="wrap footer-grid">
                <div><Brand shop={shop} /><p>Bánh tươi cho những dịp đáng nhớ và cả những ngày bình thường.</p></div>
                <div><h3>Ghé tiệm</h3>{shop.address && <p><MapPin size={17} />{shop.address}</p>}{shop.hours && <p><Clock3 size={17} />{shop.hours}</p>}</div>
                <div><h3>Liên hệ</h3>{shop.phone && <p><Phone size={17} /><a href={`tel:${shop.phone.replace(/[^+\d]/g, '')}`}>{shop.phone}</a></p>}{shop.email && <p><a href={`mailto:${shop.email}`}>{shop.email}</a></p>}</div>
                <div><h3>Đặt bánh</h3><p><Link href="/thuc-don">Thực đơn</Link></p><p><Link href="/tra-cuu">Tra cứu đơn</Link></p><p><Link href="/chinh-sach-dat-banh">Chính sách đặt bánh</Link></p><p><Link href="/gioi-thieu">Về tiệm</Link></p><p><Link href="/lien-he">Liên hệ</Link></p><p><Link href="/cau-hoi-thuong-gap">Câu hỏi thường gặp</Link></p><p><Link href="/cua-hang">Cửa hàng</Link></p>{auth && <Link className="text-action" as="button" method="post" href="/dang-xuat">Đăng xuất</Link>}</div>
            </div>
        </footer>
    </>;
}

export function BranchSelector({ admin = false }) {
    const { shop, branchContext, cartCount, cartBranchId = 1 } = usePage().props;
    const choices = admin ? branchContext?.allowed ?? [] : shop.branches ?? [];
    const current = Number(admin ? branchContext?.currentId : shop.branch?.id);
    const form = useForm({ branch_id: current, confirm_clear: false });
    const selected = Number(form.data.branch_id);
    const cartAtAnotherBranch = !admin && cartCount > 0 && Number(cartBranchId) !== current;
    const needsConfirmation = !admin && cartCount > 0 && (selected !== current || cartAtAnotherBranch);
    if (!(admin ? branchContext?.enabled : shop.branch_mode)) return null;
    return <form className="branch-selector" onSubmit={event => {
        event.preventDefault();
        form.post(admin ? '/quan-tri/chon-co-so' : '/co-so/chon', { preserveState: false });
    }}>
        <Field label={admin ? 'Cơ sở đang quản trị' : 'Chọn cơ sở nhận hoặc giao bánh'} error={form.errors.branch_id}>
            <select value={form.data.branch_id} onChange={event => form.setData({ branch_id: Number(event.target.value), confirm_clear: false })}>
                {choices.map(branch => <option key={branch.id} value={branch.id}>{branch.name}{branch.active === false || branch.active === 0 ? ' · Đang ẩn' : ''}</option>)}
            </select>
        </Field>
        {needsConfirmation && <label className="checkbox branch-confirm"><input type="checkbox" checked={form.data.confirm_clear} onChange={event => form.setData('confirm_clear', event.target.checked)} />Tôi đồng ý xóa giỏ hiện tại để đặt ở cơ sở mới. Giá, lịch và phí giao sẽ được tính lại.</label>}
        <button className="button secondary compact" disabled={form.processing || !choices.length || (selected === current && !cartAtAnotherBranch) || (needsConfirmation && !form.data.confirm_clear)}>{form.processing ? 'Đang đổi…' : 'Chọn cơ sở'}</button>
    </form>;
}

export function ButtonLink({ href, children, secondary = false, ...props }) {
    return <Link className={secondary ? 'button secondary' : 'button'} href={href} {...props}>{children}</Link>;
}

export function Breadcrumb({ children }) {
    return <div className="breadcrumbs"><Link href="/">Trang chủ</Link><span aria-hidden="true">/</span>{children}</div>;
}

export function Field({ label, error, children, hint, id }) {
    const generatedId = useId();
    const childList = Children.toArray(children);
    const control = childList.find(child => isValidElement(child) && ['input', 'select', 'textarea'].includes(child.type));
    const controlId = control?.props.id ?? id ?? `field-${generatedId}`;
    const hintId = `${controlId}-hint`;
    const errorId = `${controlId}-error`;
    const describedBy = [...new Set([control?.props['aria-describedby'], hint && hintId, error && errorId].filter(Boolean).join(' ').split(/\s+/).filter(Boolean))].join(' ') || undefined;
    return <div className="field">
        {control ? <label htmlFor={controlId}>{label}</label> : <span>{label}</span>}
        {childList.map(child => child === control ? cloneElement(child, {
            id: controlId,
            'aria-invalid': error ? true : child.props['aria-invalid'],
            'aria-describedby': describedBy,
        }) : child)}
        {hint && <small id={hintId}>{hint}</small>}
        {error && <small id={errorId} className="field-error">{error}</small>}
    </div>;
}

export function ErrorList({ errors = {}, fieldIds = {}, id, focusable = false }) {
    const seen = new Set();
    const entries = Object.entries(errors).flatMap(([field, values]) => [values].flat().map(message => ({ field, message })))
        .filter(({ field, message }) => {
            const key = `${fieldIds[field] ?? ''}:${message}`;
            if (!message || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    return entries.length ? <ul id={id} className="error-list" role="alert" aria-label="Thông tin cần kiểm tra" tabIndex={focusable ? -1 : undefined}>
        {entries.map(({ field, message }) => <li key={`${field}:${message}`}>{fieldIds[field] ? <a href={`#${fieldIds[field]}`} onClick={event => {
            event.preventDefault();
            const control = document.getElementById(fieldIds[field]);
            const target = control?.disabled ? control.closest('[tabindex="-1"]') : control;
            target?.focus();
            target?.scrollIntoView({ block: 'center' });
        }}>{message}</a> : message}</li>)}
    </ul> : null;
}

export function Empty({ title, text, href, label }) {
    return <div className="empty"><span className="category-icon"><CakeSlice size={33} strokeWidth={1.5} /></span><h2>{title}</h2>{text && <p>{text}</p>}{href && <ButtonLink href={href}>{label}<ArrowRight size={17} /></ButtonLink>}</div>;
}

export function Pagination({ page }) {
    if (!page || page.last_page < 2) return null;
    return <nav className="pagination" aria-label="Phân trang">
        {page.prev_page_url && <Link href={page.prev_page_url}>← Trước</Link>}
        <span>Trang {page.current_page} / {page.last_page}</span>
        {page.next_page_url && <Link href={page.next_page_url}>Sau →</Link>}
    </nav>;
}

export function Image({ src, asset, alt = '', className = '', loading = 'lazy', sizes, priority = false, placeholder, placeholderClassName, decorative = false }) {
    return <ImageContent key={(asset?.src || src || '') + (asset?.srcset || '')} src={src} asset={asset} alt={alt} className={className} loading={loading} sizes={sizes} priority={priority} placeholder={placeholder} placeholderClassName={placeholderClassName} decorative={decorative} />;
}

function ImageContent({ src, asset, alt, className, loading, sizes, priority, placeholder, placeholderClassName, decorative }) {
    const [failures, setFailures] = useState([]);
    const primary = asset?.src || src;
    const fallback = asset?.fallback || src;
    const useFallback = failures.includes('primary');
    const selected = useFallback ? fallback : primary;
    const failed = useFallback && (fallback === primary || failures.includes('fallback'));
    const srcset = !useFallback && asset?.srcset;
    const width = useFallback ? asset?.original_width : asset?.width;
    const height = useFallback ? asset?.original_height : asset?.height;
    return selected && !failed ? <img className={className} src={selected} srcSet={srcset || undefined} sizes={srcset ? sizes || '(max-width: 760px) 100vw, 50vw' : undefined} width={width || undefined} height={height || undefined} alt={alt} loading={loading} decoding="async" fetchPriority={priority ? 'high' : undefined} onError={() => setFailures(previous => [...previous, useFallback ? 'fallback' : 'primary'])} /> :
        <span className={`${placeholderClassName || 'image-placeholder'} ${className}`} role={!decorative && alt ? 'img' : undefined} aria-label={!decorative && alt ? alt : undefined} aria-hidden={decorative || !alt ? true : undefined}><CakeSlice size={32} strokeWidth={1.5} aria-hidden="true" />{placeholder && <span>{placeholder}</span>}</span>;
}

export function Pill({ value, labels }) {
    return <span className={`pill state-${value}`}>{labels[value]}</span>;
}
