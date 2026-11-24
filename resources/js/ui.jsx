import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CakeSlice, Check, Clock3, Cookie, Croissant, CupSoda, LayoutDashboard, MapPin, Menu, Phone, Search, ShoppingBag, UserRound } from 'lucide-react';
import { Children, cloneElement, isValidElement, useId, useState } from 'react';

export const money = value => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(Number(value));
export const date = value => value ? `${value.slice(8, 10)}/${value.slice(5, 7)}/${value.slice(0, 4)}` : '';
export const dateTime = value => value ? `${date(value)} · ${value.slice(11, 16)}` : '';
export const categoriesIcons = { cake: CakeSlice, croissant: Croissant, cupcake: CupSoda, cookie: Cookie };

export function Brand({ shop }) {
    return <Link href="/" className="brand" aria-label={shop.name}>
        {shop.logo ? <img src={shop.logo} alt="" /> : <span className="brand-mark"><CakeSlice size={23} strokeWidth={1.8} /></span>}
        <span>{shop.name}</span>
    </Link>;
}

export function Layout({ title, children }) {
    const { shop, auth, cartCount, notice } = usePage().props;
    const path = usePage().url.split('?')[0];
    const internal = auth && auth.role !== 'customer';
    return <>
        <Head title={title ? `${title} | ${shop.name}` : shop.name} />
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
                <div><h3>Đặt bánh</h3><p><Link href="/thuc-don">Thực đơn</Link></p><p><Link href="/tra-cuu">Tra cứu đơn</Link></p><p><Link href="/chinh-sach-dat-banh">Chính sách đặt bánh</Link></p>{auth && <Link className="text-action" as="button" method="post" href="/dang-xuat">Đăng xuất</Link>}</div>
            </div>
        </footer>
    </>;
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

export function Image({ src, alt = '', className = '', loading = 'lazy' }) {
    return <ImageContent key={src ?? ''} src={src} alt={alt} className={className} loading={loading} />;
}

function ImageContent({ src, alt, className, loading }) {
    const [failed, setFailed] = useState(false);
    return src && !failed ? <img className={className} src={src} alt={alt} loading={loading} onError={() => setFailed(true)} /> :
        <span className={`image-placeholder ${className}`} role={alt ? 'img' : undefined} aria-label={alt || undefined} aria-hidden={alt ? undefined : true}><CakeSlice size={32} strokeWidth={1.5} aria-hidden="true" /></span>;
}

export function Pill({ value, labels }) {
    return <span className={`pill state-${value}`}>{labels[value]}</span>;
}
