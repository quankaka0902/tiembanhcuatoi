import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, CakeSlice, ClipboardList, Download, LayoutDashboard, Package, Plus, Settings, Ticket, Users } from 'lucide-react';
import { Brand, ButtonLink, dateTime, ErrorList, Field, Image, money, Pagination, Pill } from './ui';
import { OrderEvents, OrderItems, Totals } from './checkout';

const root = '/quan-tri';
const nav = [
    [root, 'Tổng quan', LayoutDashboard], [root + '/don-hang', 'Đơn hàng', ClipboardList],
    [root + '/san-pham', 'Sản phẩm', CakeSlice, true], [root + '/ma-giam', 'Mã giảm giá', Ticket, true],
    [root + '/cai-dat', 'Cấu hình', Settings, true], [root + '/nhan-su', 'Nhân sự', Users, true],
];

function Admin({ title, detail, action, children }) {
    const { auth, notice, shop } = usePage().props;
    const path = usePage().url.split('?')[0];
    return <div className="admin-shell">
        <Head title={title + ' | Quản trị'} />
        <aside className="admin-sidebar">
            <Brand shop={shop} />
            <nav aria-label="Quản trị cửa hàng">
                {nav.filter(item => !item[3] || auth.role === 'admin').map(([url, label, Icon]) => {
                    const selected = path === url || (url !== root && path.startsWith(url + '/'));
                    return <Link key={url} className={selected ? 'selected' : ''} href={url} aria-current={selected ? 'page' : undefined}><Icon size={18} />{label}</Link>;
                })}
            </nav>
            <div className="admin-account"><strong>{auth.name}</strong><small>{auth.role === 'admin' ? 'Quản trị viên' : 'Nhân viên'}</small><Link as="button" method="post" href="/dang-xuat" className="text-action">Đăng xuất</Link></div>
        </aside>
        <main className="admin-main">
            <div className="admin-top"><span>Quản trị cửa hàng</span><Link className="subtle-link" href="/">Xem cửa hàng <ArrowUpRight size={16} /></Link></div>
            {notice && <p className="toast" role="status">{notice}</p>}
            <div className="section-head admin-heading"><div><h1>{title}</h1>{detail && <p>{detail}</p>}</div>{action}</div>
            {children}
        </main>
    </div>;
}

function Table({ headers, rows, empty = 'Chưa có dữ liệu.' }) {
    if (!rows.length) return <p className="inline-empty">{empty}</p>;
    return <div className="table-scroll" tabIndex="0" role="region" aria-label="Bảng dữ liệu"><table><thead><tr>{headers.map(header => <th key={header} scope="col">{header}</th>)}</tr></thead>
        <tbody>{rows.map(([key, ...cells]) => <tr key={key}>{cells.map((value, i) => <td key={headers[i]}>{value}</td>)}</tr>)}</tbody>
    </table></div>;
}

function DateFilters({ filters, url, statuses }) {
    const form = useForm({});
    return <form className="panel admin-filters" onSubmit={e => {
        e.preventDefault();
        const values = new FormData(e.currentTarget);
        form.transform(() => Object.fromEntries([...values].filter(([, value]) => value !== '')));
        form.get(url, { preserveState: true });
    }}>
        {statuses && <><Field label="Mã đơn"><input name="code" defaultValue={filters.code ?? ''} placeholder="NB-" /></Field><Field label="Trạng thái"><select name="status" defaultValue={filters.status ?? ''}><option value="">Tất cả</option>{Object.entries(statuses).map(([key, label]) => <option value={key} key={key}>{label}</option>)}</select></Field></>}
        <Field label="Từ ngày"><input type="date" name="from" defaultValue={filters.from ?? ''} /></Field>
        <Field label="Đến ngày"><input type="date" name="to" defaultValue={filters.to ?? ''} /></Field>
        <button className="button compact" disabled={form.processing}>{form.processing ? 'Đang lọc…' : 'Lọc'}</button><ErrorList errors={form.errors} />
    </form>;
}

export function AdminDashboard({ filters, total, revenue, working, low, lowCount, recent, statuses }) {
    return <Admin title="Tổng quan" detail="Theo dõi đơn bánh, doanh thu và tồn kho.">
        <DateFilters filters={filters} url={root} />
        <div className="stats">
            {[[ClipboardList, 'Số đơn', total], [Package, 'Đang xử lý', working], [Ticket, 'Doanh thu đã thanh toán', money(revenue)], [CakeSlice, 'Phiên bản sắp hết', lowCount]].map(([Icon, label, value]) =>
                <div className="panel stat" key={label}><span className="service-icon"><Icon size={21} /></span><span>{label}</span><strong>{value}</strong></div>)}
        </div>
        <div className="admin-columns"><section className="panel"><div className="section-head"><h2>Đơn gần đây</h2><Link className="subtle-link" href={root + '/don-hang'}>Xem tất cả</Link></div>
            <Table headers={['Mã đơn', 'Khách hàng', 'Trạng thái', 'Tổng']} rows={recent.map(order => [order.id, <Link className="table-link" href={root + '/don-hang/' + order.id}>{order.code}</Link>, order.name, <Pill value={order.status} labels={statuses} />, money(order.total)])} />
        </section><section className="panel"><h2>Tồn kho thấp</h2><Table headers={['Bánh', 'Phiên bản', 'Còn']} rows={low.map((item, index) => [index, item.product_name, item.name, item.stock])} /></section></div>
    </Admin>;
}

export function AdminOrders({ orders, filters, statuses, payments }) {
    const url = root + '/don-hang';
    const qs = new URLSearchParams(Object.entries(filters).filter(([, value]) => value != null && value !== ''));
    return <Admin title="Đơn hàng" detail={orders.total + ' đơn theo bộ lọc hiện tại'} action={<a className="button secondary compact" href={url + '/xuat?' + qs}><Download size={17} />Xuất CSV</a>}>
        <DateFilters filters={filters} url={url} statuses={statuses} />
        <div className="panel"><Table empty="Không có đơn nào theo bộ lọc." headers={['Mã đơn', 'Ngày đặt', 'Khách hàng', 'Đơn hàng', 'Thanh toán', 'Tổng tiền']} rows={orders.data.map(order => [order.id,
            <Link className="table-link" href={url + '/' + order.id}>{order.code}</Link>, dateTime(order.created_at), order.name,
            <Pill value={order.status} labels={statuses} />, <><Pill value={order.payment} labels={payments} /><small>{order.method === 'bank' ? 'Chuyển khoản' : 'COD'}</small></>, money(order.total)])} />
            <Pagination page={orders} />
        </div>
    </Admin>;
}

export function AdminOrder({ order, statuses, payments, next, reconcile }) {
    const transition = useForm({ kind: 'status', value: '', note: '' });
    const payment = useForm({ kind: 'payment', value: '', note: '' });
    const url = root + '/don-hang/' + order.id;
    const canPay = order.payment === 'unpaid' && order.status !== 'cancelled' && (order.method === 'bank' ? reconcile : ['ready', 'shipping', 'completed'].includes(order.status));
    const canRefund = reconcile && order.payment === 'paid' && order.status === 'cancelled';
    return <Admin title={'Đơn ' + order.code} action={<ButtonLink href={root + '/don-hang'} secondary><ArrowLeft size={17} />Danh sách đơn</ButtonLink>}>
        <div className="row-actions admin-pills"><Pill value={order.status} labels={statuses} /><Pill value={order.payment} labels={payments} /></div>
        <div className="admin-columns">
            <div className="form-stack">
                <section className="panel form-stack"><h2>Khách hàng</h2><strong>{order.name}</strong><p>{order.email} · {order.phone}</p>
                    <p>{order.fulfillment === 'delivery' ? 'Giao đến ' + order.address + ' · ' + order.region_label : 'Nhận tại tiệm'}</p><p>Giờ nhận {dateTime(order.scheduled_at)}</p>
                </section>
                <section className="panel form-stack"><h2>Bánh đã đặt</h2><OrderItems items={order.items} /><Totals {...order} />{order.coupon_code && <p>Mã đã sử dụng <b>{order.coupon_code}</b></p>}</section>
                <section className="panel form-stack"><h2>Lịch sử thay đổi</h2><OrderEvents events={order.events} statuses={statuses} payments={payments} /></section>
            </div>
            <div className="form-stack">
                <section className="panel form-stack"><h2>Xử lý đơn</h2>
                    {next.length ? <form className="form-stack" onSubmit={e => { e.preventDefault(); transition.patch(url, { preserveScroll: true, onSuccess: () => transition.reset() }); }}>
                        <Field label="Trạng thái tiếp theo"><select required value={transition.data.value} onChange={e => transition.setData('value', e.target.value)}>
                            <option value="">Chọn trạng thái</option>{next.map(value => <option key={value} value={value} disabled={value === 'completed' && order.payment !== 'paid'}>{statuses[value]}</option>)}</select></Field>
                        {next.includes('completed') && order.payment !== 'paid' && <p className="muted">Cần xác nhận thanh toán trước khi hoàn tất.</p>}
                        <Field label="Ghi chú nội bộ"><textarea rows="2" maxLength={250} value={transition.data.note} onChange={e => transition.setData('note', e.target.value)} /></Field>
                        <ErrorList errors={transition.errors} /><button className="button" disabled={transition.processing}>Cập nhật trạng thái</button>
                    </form> : <p className="muted">Đơn đã kết thúc.</p>}
                </section>
                <section className="panel form-stack"><h2>Thanh toán</h2>
                    <p>{order.method === 'bank' ? 'Chuyển khoản thủ công' : 'Thanh toán khi nhận bánh'}</p>
                    {order.bank_snapshot && <p className="muted">{order.bank_snapshot.demo ? 'Dữ liệu mẫu · ' : ''}Nội dung {order.code} · {money(order.total)}</p>}
                    {(canPay || canRefund) && <form className="form-stack" onSubmit={e => {
                        e.preventDefault();
                        payment.transform(data => ({ ...data, value: canPay ? 'paid' : 'refunded' }));
                        payment.patch(url, { preserveScroll: true, onSuccess: () => payment.reset() });
                    }}>
                        <Field label="Ghi chú thanh toán"><input maxLength={250} value={payment.data.note} onChange={e => payment.setData('note', e.target.value)} /></Field>
                        <ErrorList errors={payment.errors} /><button className="button secondary" disabled={payment.processing}>{canPay ? 'Xác nhận đã thanh toán' : 'Ghi nhận đã hoàn tiền'}</button>
                    </form>}
                </section>
            </div>
        </div>
    </Admin>;
}

const categoryLabels = { cake: 'Bánh kem', croissant: 'Bánh nướng', cupcake: 'Bánh lạnh', cookie: 'Bánh quy' };

function CategoryForm({ category }) {
    const form = useForm({ name: category?.name ?? '', slug: category?.slug ?? '', icon: category?.icon ?? 'cake' });
    return <form className="form-stack category-edit" onSubmit={e => { e.preventDefault(); form.post(root + '/danh-muc' + (category ? '/' + category.id : ''), { preserveScroll: true, onSuccess: () => { if (!category) form.reset(); } }); }}>
        <Field label="Tên danh mục" error={form.errors.name}><input required maxLength={100} value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></Field>
        <Field label="Đường dẫn ngắn" error={form.errors.slug}><input required pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="banh-kem" value={form.data.slug} onChange={e => form.setData('slug', e.target.value)} /></Field>
        <Field label="Biểu tượng"><select value={form.data.icon} onChange={e => form.setData('icon', e.target.value)}>{Object.entries(categoryLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></Field>
        <button className="button secondary compact" disabled={form.processing}>{category ? 'Lưu danh mục' : 'Thêm danh mục'}</button>
    </form>;
}

export function AdminProducts({ products, categories }) {
    return <Admin title="Sản phẩm" action={<ButtonLink href={root + '/san-pham/them'}><Plus size={18} />Thêm sản phẩm</ButtonLink>}>
        <div className="admin-columns">
            <section className="panel"><h2>Danh sách bánh</h2><Table headers={['Tên bánh', 'Danh mục', 'Trạng thái']} rows={products.data.map(product => [product.id,
                <Link className="table-link" href={root + '/san-pham/' + product.id}>{product.name}</Link>, product.category, product.active ? 'Đang bán' : 'Đã ẩn'])} /><Pagination page={products} /></section>
            <section className="panel form-stack"><h2>Danh mục</h2>
                {categories.map(category => <details className="fold" key={category.id}><summary>{category.name}</summary><CategoryForm category={category} /></details>)}
                <details className="fold"><summary>Thêm danh mục</summary><CategoryForm /></details>
            </section>
        </div>
    </Admin>;
}

const freshVariant = () => ({ name: '', price: '', stock: '', active: true });

function Text({ form, field, label, type = 'text', ...props }) {
    return <Field label={label} error={form.errors[field]}><input type={type} value={form.data[field] ?? ''} onChange={e => form.setData(field, e.target.value)} {...props} /></Field>;
}

function TextArea({ form, field, label, ...props }) {
    return <Field label={label} error={form.errors[field]}><textarea rows="3" value={form.data[field] ?? ''} onChange={e => form.setData(field, e.target.value)} {...props} /></Field>;
}

function Flag({ form, field, label }) {
    return <label className="checkbox"><input type="checkbox" checked={Boolean(form.data[field])} onChange={e => form.setData(field, e.target.checked)} />{label}</label>;
}

export function AdminProduct({ product, categories }) {
    const form = useForm({
        name: product?.name ?? '', category_id: product?.category_id ?? '', description: product?.description ?? '',
        ingredients: product?.ingredients ?? '', allergens: product?.allergens ?? '', prep_minutes: product?.prep_minutes ?? 60,
        message_fee: product?.message_fee ?? 0, active: Boolean(product?.active ?? true), featured: Boolean(product?.featured), customizable: Boolean(product?.customizable),
        variants: product?.variants.map(({ id, name, price, stock, active }) => ({ id, name, price, stock, active: Boolean(active), expected_stock: stock })) ?? [freshVariant()],
        uploads: [], removed: [],
    });
    function variantChange(index, key, value) {
        form.setData('variants', form.data.variants.map((variant, i) => i === index ? { ...variant, [key]: value } : variant));
    }
    return <Admin title={product ? product.name : 'Thêm sản phẩm'} action={<ButtonLink href={root + '/san-pham'} secondary><ArrowLeft size={17} />Sản phẩm</ButtonLink>}>
        <form className="form-stack" onSubmit={e => { e.preventDefault(); form.post(root + '/san-pham' + (product ? '/' + product.id : ''), { forceFormData: true, preserveState: 'errors' }); }}>
            <div className="admin-columns">
                <section className="panel form-stack"><h2>Thông tin bánh</h2><Text form={form} field="name" label="Tên bánh" required maxLength={150} />
                    <Field label="Danh mục" error={form.errors.category_id}><select required value={form.data.category_id} onChange={e => form.setData('category_id', e.target.value)}><option value="">Chọn danh mục</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select></Field>
                    <TextArea form={form} field="description" label="Mô tả" required maxLength={3000} />
                    <TextArea form={form} field="ingredients" label="Thành phần" maxLength={1200} />
                    <TextArea form={form} field="allergens" label="Cảnh báo dị ứng" maxLength={800} />
                </section>
                <section className="panel form-stack"><h2>Hình ảnh và hiển thị</h2>
                    {product?.images.length > 0 && <div className="admin-images">{product.images.map(src => <label key={src}><Image src={src} /><input type="checkbox" checked={form.data.removed.includes(src)} onChange={e => form.setData('removed', e.target.checked ? [...form.data.removed, src] : form.data.removed.filter(image => image !== src))} /> Bỏ ảnh</label>)}</div>}
                    <Field label="Tải ảnh lên" hint="Tối đa 5 ảnh JPEG, PNG hoặc WebP, 4 MB mỗi ảnh." error={form.errors.uploads}><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={e => form.setData('uploads', [...e.target.files])} /></Field>
                    <Text form={form} field="prep_minutes" label="Thời gian làm bánh (phút)" type="number" min="0" max="14400" required />
                    <Flag form={form} field="active" label="Hiển thị và bán trên cửa hàng" /><Flag form={form} field="featured" label="Hiển thị trong bánh nổi bật" /><Flag form={form} field="customizable" label="Cho phép ghi lời chúc" />
                    <Text form={form} field="message_fee" label="Phí ghi lời chúc (₫)" type="number" min="0" max="1000000" required />
                </section>
            </div>
            <section className="panel form-stack"><h2>Phiên bản bánh</h2>
                {form.data.variants.map((variant, index) => <div className="variant-edit" key={variant.id ?? index}>
                    {[['name', 'Tên phiên bản', 'text'], ['price', 'Giá (₫)', 'number'], ['stock', 'Tồn kho', 'number']].map(([field, label, type]) => <Field key={field} label={label} error={form.errors['variants.' + index + '.' + field]}><input required type={type} min={type === 'number' ? '0' : undefined} value={variant[field]} onChange={e => variantChange(index, field, e.target.value)} /></Field>)}
                    <label className="checkbox"><input type="checkbox" checked={variant.active} onChange={e => variantChange(index, 'active', e.target.checked)} />Đang bán</label>
                    {!variant.id && form.data.variants.length > 1 && <button type="button" className="text-action danger" onClick={() => form.setData('variants', form.data.variants.filter((_, i) => i !== index))}>Bỏ dòng</button>}
                </div>)}
                <button type="button" className="button secondary compact" disabled={form.data.variants.length >= 30} onClick={() => form.setData('variants', [...form.data.variants, freshVariant()])}><Plus size={16} />Thêm phiên bản</button>
            </section>
            <ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>{form.processing ? 'Đang lưu…' : 'Lưu sản phẩm'}</button>
        </form>
    </Admin>;
}

export function AdminSettings({ settings }) {
    const { logo, weekdays, slots, closed_dates, regions, ...fields } = settings;
    const form = useForm({ ...fields, logo: null, remove_logo: false, weekdays, slotText: slots.join('\n'), closedText: closed_dates.join('\n'), regions });
    const lines = value => value.split(/[\n,]+/).map(line => line.trim()).filter(Boolean);
    function regionChange(index, key, value) {
        form.setData('regions', form.data.regions.map((region, i) => index === i ? { ...region, [key]: value } : region));
    }
    return <Admin title="Cấu hình cửa hàng">
        <form className="form-stack" onSubmit={e => {
            e.preventDefault();
            form.transform(({ slotText, closedText, ...data }) => ({ ...data, slots: lines(slotText), closed_dates: lines(closedText) }));
            form.post(root + '/cai-dat', { forceFormData: true, preserveState: 'errors' });
        }}>
            <div className="admin-columns"><section className="panel form-stack"><h2>Thông tin tiệm</h2>
                <Text form={form} field="name" label="Tên cửa hàng" required maxLength={100} /><Text form={form} field="address" label="Địa chỉ" maxLength={250} />
                <div className="two-fields"><Text form={form} field="phone" label="Số điện thoại" /><Text form={form} field="email" label="Email liên hệ" type="email" /></div>
                <Text form={form} field="hours" label="Giờ mở cửa hiển thị" required /><Field label="Logo" hint="JPEG, PNG hoặc WebP, tối đa 4 MB"><input type="file" accept="image/jpeg,image/png,image/webp" onChange={e => form.setData('logo', e.target.files?.[0] ?? null)} /></Field>
                {logo && <><img className="logo-preview" src={logo} alt="Logo hiện tại" /><Flag form={form} field="remove_logo" label="Bỏ logo hiện tại" /></>}
            </section><section className="panel form-stack"><h2>Lịch nhận bánh</h2>
                <div className="weekdays">{[[1, 'T2'], [2, 'T3'], [3, 'T4'], [4, 'T5'], [5, 'T6'], [6, 'T7'], [7, 'CN']].map(([day, name]) => <label className="checkbox" key={day}><input type="checkbox" checked={form.data.weekdays.includes(day)} onChange={e => form.setData('weekdays', e.target.checked ? [...form.data.weekdays, day] : form.data.weekdays.filter(value => value !== day))} />{name}</label>)}</div>
                <Field label="Khung giờ nhận bánh" hint="Mỗi giờ một dòng, định dạng HH:mm."><textarea required rows="5" value={form.data.slotText} onChange={e => form.setData('slotText', e.target.value)} /></Field>
                <Field label="Ngày nghỉ" hint="Mỗi ngày một dòng, định dạng YYYY-MM-DD."><textarea rows="4" value={form.data.closedText} onChange={e => form.setData('closedText', e.target.value)} /></Field>
            </section></div>
            <section className="panel form-stack"><h2>Giao hàng</h2>
                <Text form={form} field="free_from" label="Miễn phí giao hàng từ (₫, sau giảm giá)" type="number" min="0" />
                {form.data.regions.map((area, i) => <div className="variant-edit" key={i}>
                    {[['key', 'Mã khu vực', 'text'], ['name', 'Tên khu vực', 'text'], ['fee', 'Phí giao (₫)', 'number']].map(([key, label, type]) => <Field label={label} key={key}><input required type={type} min={type === 'number' ? '0' : undefined} value={area[key]} onChange={e => regionChange(i, key, e.target.value)} /></Field>)}
                    <button type="button" className="text-action danger" onClick={() => form.setData('regions', form.data.regions.filter((_, index) => index !== i))}>Bỏ khu vực</button>
                </div>)}
                <button type="button" className="button secondary compact" onClick={() => form.setData('regions', [...form.data.regions, { key: '', name: '', fee: 0 }])}><Plus size={16} />Thêm khu vực</button>
            </section>
            <section className="panel form-stack"><h2>Chuyển khoản thủ công</h2><p className="muted">Điền cả ba trường để mở chuyển khoản. Xóa cả ba trường để tắt.</p>
                <div className="three-fields"><Text form={form} field="bank_name" label="Ngân hàng" /><Text form={form} field="bank_account" label="Số tài khoản" /><Text form={form} field="bank_holder" label="Chủ tài khoản" /></div>
                <Flag form={form} field="bank_demo" label="Hiển thị cảnh báo thông tin chuyển khoản mẫu" />
            </section>
            <ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>{form.processing ? 'Đang lưu…' : 'Lưu cấu hình'}</button>
        </form>
    </Admin>;
}

export function AdminCoupons({ coupon, coupons }) {
    const url = root + '/ma-giam';
    const form = useForm({ code: coupon?.code ?? '', kind: coupon?.kind ?? 'percent', amount: coupon?.amount ?? '',
        minimum: coupon?.minimum ?? 0, usage_limit: coupon?.usage_limit ?? '', starts_on: coupon?.starts_on ?? '', ends_on: coupon?.ends_on ?? '', active: Boolean(coupon?.active ?? true) });
    return <Admin title="Mã giảm giá">
        <div className="admin-columns">
            <div className="panel"><h2>Danh sách mã</h2><Table headers={['Mã', 'Mức giảm', 'Sử dụng', 'Trạng thái']} rows={coupons.data.map(item => [item.id, <Link className="table-link" href={url + '/' + item.id}>{item.code}</Link>, item.kind === 'percent' ? item.amount + '%' : money(item.amount), item.used + ' / ' + (item.usage_limit ?? '∞'), item.active ? 'Đang bật' : 'Đã tắt'])} /><Pagination page={coupons} /></div>
            <form className="panel form-stack" onSubmit={e => { e.preventDefault(); form.post(url + (coupon ? '/' + coupon.id : ''), { preserveState: 'errors' }); }}>
                <div className="section-head"><h2>{coupon ? 'Sửa mã' : 'Tạo mã'}</h2>{coupon && <Link className="subtle-link" href={url}>Tạo mã mới</Link>}</div>
                <Text form={form} field="code" label="Mã giảm giá" required maxLength={32} />
                <div className="two-fields">
                    <Field label="Loại giảm"><select value={form.data.kind} onChange={e => form.setData('kind', e.target.value)}><option value="percent">Phần trăm</option><option value="fixed">Số tiền</option></select></Field>
                    <Text form={form} field="amount" label={form.data.kind === 'percent' ? 'Giảm (%)' : 'Giảm (₫)'} type="number" required min="1" max={form.data.kind === 'percent' ? '100' : '100000000'} />
                </div>
                <Text form={form} field="minimum" label="Giá trị đơn tối thiểu (₫)" type="number" min="0" required /><Text form={form} field="usage_limit" label="Giới hạn lượt dùng" type="number" min="1" />
                <div className="two-fields"><Text form={form} field="starts_on" label="Bắt đầu" type="date" /><Text form={form} field="ends_on" label="Hết hạn" type="date" /></div>
                <Flag form={form} field="active" label="Cho phép sử dụng" /><ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>Lưu mã giảm</button>
            </form>
        </div>
    </Admin>;
}

export function AdminUsers({ selected, users }) {
    const url = root + '/nhan-su';
    const form = useForm({ name: selected?.name ?? '', email: selected?.email ?? '', password: '', role: selected?.role ?? 'staff', active: Boolean(selected?.active ?? true), can_reconcile: Boolean(selected?.can_reconcile) });
    return <Admin title="Tài khoản nội bộ">
        <div className="admin-columns"><div className="panel"><h2>Nhân sự</h2><Table headers={['Tên', 'Quyền', 'Trạng thái']} rows={users.data.map(user => [user.id,
            <Link className="table-link" href={url + '/' + user.id}>{user.name}<small>{user.email}</small></Link>, user.role === 'admin' ? 'Quản trị viên' : user.can_reconcile ? 'Nhân viên · Đối soát' : 'Nhân viên', user.active ? 'Hoạt động' : 'Đã khóa'])} /><Pagination page={users} /></div>
            <form className="panel form-stack" onSubmit={e => { e.preventDefault(); form.post(url + (selected ? '/' + selected.id : ''), { preserveState: 'errors', onFinish: () => form.reset('password') }); }}>
                <div className="section-head"><h2>{selected ? 'Sửa tài khoản' : 'Tạo tài khoản'}</h2>{selected && <Link className="subtle-link" href={url}>Tạo mới</Link>}</div>
                <Text form={form} field="name" label="Họ và tên" required /><Text form={form} field="email" label="Email" type="email" required />
                <Text form={form} field="password" label={selected ? 'Mật khẩu mới (tùy chọn)' : 'Mật khẩu'} type="password" autoComplete="new-password" required={!selected} minLength={10} maxLength={72} />
                <Field label="Vai trò"><select value={form.data.role} onChange={e => form.setData('role', e.target.value)}><option value="staff">Nhân viên</option><option value="admin">Quản trị viên</option></select></Field>
                {form.data.role === 'staff' && <Flag form={form} field="can_reconcile" label="Được đối soát chuyển khoản và ghi nhận hoàn tiền" />}
                <Flag form={form} field="active" label="Cho phép đăng nhập" /><ErrorList errors={form.errors} /><button className="button" disabled={form.processing}>Lưu tài khoản</button>
            </form>
        </div>
    </Admin>;
}
