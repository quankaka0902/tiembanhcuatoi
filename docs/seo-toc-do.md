# HTML công khai, SEO và ảnh của cửa hàng

Đã nghiệm thu kỹ thuật local ngày 25/11/2026, múi giờ Asia/Ho_Chi_Minh, trên mã nguồn `4a4e1f3705e8f087302b02c1e2e3e6d3ba8e7485`. Biên bản và bằng chứng nằm ở cuối tài liệu. Kết quả này chưa xác nhận dịch vụ hoặc dữ liệu thật của tiệm. Chưa có phê duyệt mở bán hay lập chỉ mục; công tắc nhận đơn thật và SEO vẫn đóng.

## Nội dung có sẵn trong HTML

Trang chủ, thực đơn, danh mục, chi tiết bánh, chính sách và các trang thông tin có bản HTML từ PHP. Người đọc và công cụ thu thập nội dung nhận được tiêu đề, nội dung chính, liên kết và thông tin sản phẩm mà không phải chờ tải React. Bộ lọc GET và liên kết danh mục/phân trang có URL riêng. Giao diện tương tác tiếp tục dùng Inertia và React.

Node SSR là thành phần tùy chọn để render cùng thành phần React cho trang công khai của khách chưa đăng nhập. Khi Node tắt, lỗi hoặc quá thời gian chờ, PHP tiếp tục cung cấp HTML công khai. Giỏ hàng, checkout, tài khoản, đơn riêng, xác thực và quản trị vẫn dùng luồng bảo vệ hiện có. Đây không phải cam kết toàn bộ luồng mua hàng hoạt động khi tắt JavaScript.

Gateway chỉ gửi tới Node các component và prop công khai được cho phép. Tài khoản đã đăng nhập, giỏ có hàng, flash thông báo hoặc lỗi xác thực không đi qua SSR Node. Endpoint SSR dùng HTTP loopback với địa chỉ IP cố định, không theo redirect và có thời gian chờ ngắn. Node chỉ nhận các component công khai, giới hạn kích thước yêu cầu và không ghi payload, token hoặc URL riêng vào log.

HTML và dữ liệu Inertia luôn `private, no-store`, gồm cả trang công khai vì thanh điều hướng phụ thuộc phiên. Không đặt cache chia sẻ theo đường dẫn hay theo User-Agent trên CDN/proxy. Cache công khai chỉ dùng cho byte ảnh phù hợp; staging ghi đè thành `private, no-store`. Bảo vệ staging bằng mạng riêng hoặc VPN cho cả ảnh, font, build, robots, sitemap và `/up`. Middleware Laravel không bảo vệ tệp do web server phục vụ trực tiếp.

## Ma trận lập chỉ mục

`BAKERY_SEO_INDEXING_ENABLED=false` là mặc định. Chỉ có thể lập chỉ mục khi đồng thời dùng `APP_ENV=production`, URL HTTPS không phải loopback, tắt seed demo và rehearsal, bật cấu hình SEO và duyệt dữ liệu cần thiết. Canonical và URL schema lấy từ `APP_URL` hợp lệ, không lấy Host hay Referer của yêu cầu. Việc bật SEO không bật nhận đơn.

| Trang hoặc trường hợp | Điều kiện ngoài cổng SEO chung | Canonical và xử lý |
| --- | --- | --- |
| Trang chủ `/` | Thông tin cơ sở đã xác minh, không có query | Canonical trang chủ. |
| `/thuc-don`, `/thuc-don/{danh-muc}` | Cơ sở đã xác minh, có kết quả, không có bộ lọc | URL sạch của thực đơn/danh mục. |
| Phân trang thực đơn | Các điều kiện thực đơn và số trang hợp lệ | Trang sau dùng canonical riêng với `?page=N`. Trang vượt giới hạn trả 404. |
| Tìm kiếm, lọc, sắp xếp, query bổ sung | Không lập chỉ mục | Canonical về thực đơn hoặc danh mục sạch; không nhân URL bộ lọc vào sitemap. |
| `/banh/{slug}` | Bánh còn hiển thị, chi tiết và ảnh đã được duyệt, có phiên bản bán, cơ sở đã xác minh, không có query | Canonical slug hiện tại. Bánh ẩn không xuất hiện trong sitemap. |
| Slug sản phẩm/danh mục cũ đã lưu lịch sử | Đích còn hợp lệ, sản phẩm đích đang hiển thị | Redirect 301 tới slug hiện tại thuộc `APP_URL`. Không dùng tên miền do khách gửi. |
| `/gioi-thieu` | Cơ sở và nội dung được duyệt, có bài giới thiệu | Canonical URL cố định. |
| `/lien-he`, `/cua-hang` | Cơ sở đã xác minh | Canonical URL cố định; thông tin có cấu trúc chỉ dùng dữ liệu đã xác minh. |
| `/cau-hoi-thuong-gap` | Cơ sở và nội dung được duyệt, có câu hỏi do tiệm cung cấp | Canonical URL cố định. |
| `/chinh-sach-dat-banh` | Cơ sở và nội dung được duyệt, có chính sách | Canonical URL cố định. |
| Giỏ, đặt bánh, tài khoản, tra cứu, link thao tác, khôi phục mật khẩu, xác thực, quản trị, lỗi | Luôn loại khỏi lập chỉ mục | `noindex`, trang riêng dùng `nofollow`; không vào sitemap. Không thêm canonical tới liên kết mang token. |
| Local, test, staging, demo hoặc cổng SEO chưa bật | Không lập chỉ mục | Robots cấm thu thập toàn site khi cổng chung chưa đủ điều kiện; sitemap rỗng. Staging áp dụng header `noindex, nofollow`. |

`robots.txt` và sitemap là chỉ dẫn cho công cụ thu thập nội dung, không thay thế xác thực hoặc giới hạn mạng. Robots không chứa đường dẫn token cụ thể. Sitemap chỉ chứa URL công khai đủ điều kiện tại thời điểm tạo. Không thêm đơn hàng hay danh sách người dùng vào hai đầu ra này.

Các thẻ title, description, canonical, Open Graph và JSON-LD có khóa ổn định để Inertia thay thế khi chuyển trang. Title và nội dung schema cần đối chiếu cả HTML ban đầu và sau chuyển trang bằng React. JSON-LD được escape khi đưa vào HTML; nội dung quản trị hiển thị như văn bản, không thực thi HTML do người nhập cung cấp.

## Nguồn nội dung và quyền duyệt

| Đầu vào | Nơi quản lý | Đầu mối cần xác nhận |
| --- | --- | --- |
| Tên cơ sở, liên hệ, địa chỉ hiển thị, giờ mở cửa, liên kết bản đồ | Cấu hình cửa hàng | Chủ tiệm. |
| Địa chỉ có cấu trúc gồm đường, địa phương, vùng/mã bưu chính nếu có, mã quốc gia và lịch mở cửa | Cấu hình thông tin cơ sở, cờ `business_verified` | Chủ tiệm đối chiếu cơ sở thật. Không suy đoán địa phương từ địa chỉ mẫu. |
| Giới thiệu, nội dung liên hệ và câu hỏi/trả lời | Cấu hình nội dung công khai, cờ `public_content_approved` | Chủ tiệm hoặc người phụ trách nội dung được giao. |
| Logo được phép dùng | Cấu hình logo và `logo_approved` | Chủ tiệm xác nhận quyền sử dụng. |
| Thành phần, dị ứng, bảo quản | Quản trị sản phẩm, `details_verified` | Người làm bánh và chủ tiệm. |
| Ảnh sản phẩm | Quản trị sản phẩm, `images_approved` | Người quản lý ảnh xác nhận đúng bánh và quyền sử dụng. |
| Giá, tồn, trạng thái bán, cỡ và khẩu phần | Quản trị sản phẩm/phiên bản | Người phụ trách menu và tồn; khẩu phần có cờ xác minh riêng. |

Dữ liệu mẫu và dữ liệu cũ sau migration không tự trở thành thông tin đã duyệt. Việc nhập thử để kiểm tra parser, schema hay giao diện chỉ là fixture nghiệm thu. Không dùng fixture để vượt preflight hoặc duyệt mở bán. Khi chỉnh nội dung liên quan mà không xác nhận duyệt lại, cờ xác minh tương ứng được thu hồi theo quy tắc lưu cấu hình/sản phẩm.

Schema hiện có gồm BreadcrumbList, Product cùng các Offer VND khi phù hợp, và Bakery với địa chỉ/giờ mở cửa đã được xác minh. Khi tiệm tạm ngừng, không phát Offer đang bán từ dữ liệu bị tạm ngừng. Không tạo đánh giá, số sao, dữ liệu địa chỉ hoặc nội dung giới thiệu giả. Việc có schema không bảo đảm công cụ tìm kiếm sẽ lập chỉ mục hoặc hiển thị tính năng mở rộng.

Google hướng dẫn không dùng AggregateOffer để mô tả tập biến thể và không bảo đảm tính năng từ dữ liệu có cấu trúc xuất hiện trong kết quả tìm kiếm. Bản này dùng Offer theo từng phiên bản; cần kiểm tra tiếp trên URL thật. [Tài liệu Product snippet của Google](https://developers.google.com/search/docs/appearance/structured-data/product-snippet).

## Nội dung đề xuất chờ duyệt

Đây là bản nháp để chủ tiệm điền và duyệt, chưa được nhập vào DB hoặc bật dấu duyệt. Các chỗ `[...]` là đầu vào còn thiếu; chỉ thay bằng dữ liệu được người phụ trách xác nhận. Tên mẫu **Tiệm Bánh Nhà Mình** chưa thay cho quyết định tên thương hiệu thật.

### Giới thiệu dự thảo

> [Tên cơ sở được duyệt] phục vụ [nhóm bánh thực tế được tiệm xác nhận] tại [địa chỉ đã xác minh]. Khi tiệm mở nhận đơn, bạn có thể xem cỡ bánh, giá và lựa chọn giao hoặc nhận tại tiệm trong thực đơn.
>
> Bạn đang chọn bánh cho [những dịp tiệm thực sự phục vụ]? Hãy xem các phiên bản còn bán và giờ nhận được hiển thị ở bước đặt bánh. Với yêu cầu riêng, vui lòng liên hệ [kênh hỗ trợ được duyệt] để tiệm xác nhận khả năng đáp ứng, lịch nhận và chi phí trước khi thực hiện.

Đầu vào cần chốt cho `about_text` gồm tên, nhóm bánh, địa chỉ, dịp phục vụ và kênh hỗ trợ. Chưa có căn cứ để thêm số năm kinh nghiệm, cam kết bánh tươi mỗi ngày, xuất xứ nguyên liệu, danh hiệu hoặc đánh giá khách hàng.

### FAQ dự thảo

| Câu hỏi | Câu trả lời đề xuất và phần cần tiệm chốt |
| --- | --- |
| Tôi cần đặt bánh trước bao lâu? | Giờ nhận được hiển thị theo thời gian chuẩn bị của món, lịch nhận bánh và chỗ còn lại lúc đặt. Hãy chọn một khung đang còn hiển thị ở bước đặt bánh. Nếu chưa có giờ phù hợp hoặc cần một yêu cầu riêng, liên hệ [kênh hỗ trợ]. **Chờ tiệm chốt** giờ cắt đơn, thời hạn đặt trước và khả năng nhận gấp; chưa hứa nhận trong ngày. |
| Tôi có dị ứng, cần kiểm tra gì trước khi đặt? | Xem thành phần, cảnh báo dị ứng và trạng thái xác minh trên từng bánh. Nếu thông tin còn thiếu hoặc bạn cần tránh một nguyên liệu, hãy liên hệ [người/kênh hỗ trợ] trước khi đặt để tiệm đối chiếu công thức và khả năng tiếp xúc chéo. **Chờ phụ trách làm bánh duyệt** thông tin từng món và cách tư vấn; không đưa lời hứa không có chất gây dị ứng khi chưa có căn cứ. |
| Tiệm giao ở đâu và phí giao thế nào? | Nếu tiệm cung cấp giao hàng, chọn khu vực được hiển thị trong bước đặt bánh. Phí giao và tổng cần thanh toán được tính lại trước khi xác nhận. Nếu địa chỉ không nằm trong lựa chọn, liên hệ [kênh hỗ trợ] để hỏi khả năng phục vụ. **Chờ tiệm chốt** vùng giao, phí, ngưỡng miễn phí và cách xử lý ngoài vùng; bảng phí mẫu chưa phải bảng phí thật. |
| Chuyển khoản xong thì đơn đã thanh toán ngay chưa? | Nếu chuyển khoản được bật, dùng đúng số tiền và nội dung hiển thị trên đơn, kiểm tra hạn chuyển khoản. Nhân viên có quyền sẽ cập nhật sau khi đối soát; việc gửi tiền chưa tự làm trạng thái đổi tức thì. Nếu chưa thấy cập nhật, liên hệ [đầu mối đối soát/hỗ trợ] kèm mã đơn. **Chờ tiệm chốt** tài khoản nhận, người đối soát và thời gian phản hồi; không dùng thông tin ngân hàng mẫu. |
| Tôi có thể đổi giờ hoặc hủy đơn không? | Khi đơn còn đủ điều kiện, bạn có thể gửi yêu cầu từ trang của mình hoặc xác minh qua email để gửi yêu cầu. Gửi yêu cầu chưa làm đơn đổi giờ hoặc bị hủy; tiệm cần xem xét và phản hồi. Khi đơn đã vào khâu làm hoặc quá mốc trực tuyến, liên hệ [kênh hỗ trợ]. **Chờ chủ tiệm chốt** phí, điều kiện đổi/hủy và thời hạn/cách hoàn tiền theo quy tắc ở tài liệu vòng đời đơn. |

Khi đưa vào `faq_items`, người duyệt bỏ phần ghi chú **Chờ...** sau khi đã chốt chính sách, giữ lại câu trả lời đúng với cấu hình hiện hành. Không công bố câu trả lời còn chỗ trống hoặc dùng thời hạn/giá của fixture làm chính sách.

### Liên hệ và hướng dẫn tới tiệm dự thảo

> [Tên cơ sở được duyệt] · [địa chỉ đầy đủ đã xác minh].
>
> Giờ mở cửa: [lịch mở cửa đã được tiệm xác nhận]. Giờ nhận bánh cho đơn của bạn là giờ được ghi trên đơn và có thể khác giờ mở cửa chung.
>
> Điện thoại: [số hỗ trợ được duyệt]. Email: [email được duyệt, nếu dùng]. Xem vị trí tại [liên kết bản đồ của đúng cơ sở đã được kiểm tra].
>
> Khi tới nhận bánh, vui lòng chuẩn bị mã đơn. Hướng dẫn tìm cửa tiệm: [biển hiệu/tầng/lối vào được tiệm cung cấp]. [Hướng dẫn đỗ xe hoặc điểm nhận hàng, chỉ thêm nếu đã được xác nhận].

Điền phần hướng dẫn vào `contact_text`; tên, địa chỉ, điện thoại và giờ hiển thị dùng các trường cấu hình tương ứng. `maps_url` là liên kết HTTPS do tiệm cung cấp và kiểm tra đúng vị trí. Địa chỉ có cấu trúc, mã quốc gia, giờ theo ngày và dấu `business_verified` cần được đối chiếu riêng; không suy tọa độ hay giờ từ địa chỉ mẫu hoặc tự thêm điểm mốc.

| Phần cần duyệt | Đầu vào cụ thể còn chờ | Người xác nhận |
| --- | --- | --- |
| Giới thiệu | Tên chính thức, nhóm bánh, dịp phục vụ, địa chỉ và kênh liên hệ | Chủ tiệm/người được giao nội dung. |
| Đặt trước và giờ nhận | Lead time theo món, lịch nghỉ/công suất, giờ cắt đơn hoặc quy tắc nhận gấp nếu có | Người phụ trách ca và chủ tiệm. |
| Dị ứng | Công thức, thành phần và tiếp xúc chéo thực tế; đầu mối kiểm tra khi khách hỏi | Người làm bánh và chủ tiệm. |
| Giao và thanh toán | Vùng/phí/ngưỡng miễn phí, phương thức bật, tài khoản nhận, người đối soát và thời gian phản hồi | Người giao/thu ngân/đối soát và chủ tiệm. |
| Tới cơ sở | Địa chỉ đầy đủ, bản đồ đúng vị trí, điện thoại/email, giờ theo ngày, hướng dẫn tìm tiệm | Chủ tiệm kiểm tra tại cơ sở. |
| Công bố | Bản văn cuối, người duyệt, ngày duyệt; ảnh/logo đúng quyền sử dụng | Chủ tiệm hoặc người được ủy quyền. |

Giữ `public_content_approved`, `business_verified` và `logo_approved` tắt cho phần chưa được duyệt. Người phụ trách nhập bản đã duyệt vào quản trị sau khi các đầu vào liên quan đã đủ; bước này không phải phê duyệt mở bán hay bật lập chỉ mục.

## Tạo và phục hồi ảnh nhiều kích thước

Sau migration, ảnh mới tải lên được giữ nguyên bản gốc và tạo WebP bằng GD. Các cỡ chiều rộng là 320, 640, 960 và 1440 px, giới hạn bởi chiều rộng thật, không phóng lớn. Đầu ra áp dụng hướng EXIF JPEG, giữ alpha cho ảnh trong suốt và có chiều rộng/chiều cao thật. Mỗi ảnh được giới hạn 4 MB, tối đa 5000 px mỗi chiều và 12 triệu điểm ảnh để giới hạn tài nguyên giải mã.

`product.images` và URL `/anh/{40-ký-tự}.{jpg|jpeg|png|webp}` cũ được giữ. Map `product.image_assets` và `shop.logo_asset` cung cấp `src`, `srcset`, kích thước, ảnh dự phòng và danh sách cỡ cho HTML/React. Ảnh chính được ưu tiên tải, ảnh phía dưới tải chậm theo vị trí dùng. Metadata lưu trong bảng `responsive_images`; đọc trang không gọi GD để resize.

```powershell
php artisan bakery:images --missing
php artisan bakery:images --rebuild
# Ví dụ một ảnh minh họa local của repo, không thay đổi quyền duyệt ảnh.
php artisan bakery:images --missing --source=/images/strawberry.webp
```

Lệnh xử lý theo lô sản phẩm, logo và các ảnh mẫu local được whitelist. URL ngoài, đường dẫn traversal, symlink không an toàn hoặc nguồn hỏng bị từ chối. Tạo ảnh không đổi cờ duyệt, không xóa ảnh gốc. Mặc định `--missing` cũng kiểm tra và sửa tệp dẫn xuất hỏng.

Ảnh dẫn xuất có tên hash trong cùng thư mục `bakery/`, phù hợp quy tắc sao lưu hiện có. Sao lưu cả DB và thư mục ảnh, gồm bản gốc lẫn dẫn xuất. Nếu tệp dẫn xuất thiếu hoặc sai mã băm, route trả bản gốc với cache phù hợp thay vì trả tệp hỏng. Cache metadata thiếu/sai định dạng làm trang quay về URL gốc. Nếu cả ảnh gốc cũng không còn, giao diện cần hiện trạng thái ảnh đang cập nhật; không thay bằng ảnh không được phép dùng.

Sau phục hồi DB/ảnh hoặc chuyển thư mục media, chạy `bakery:images --missing` để kiểm tra tệp và làm mới metadata thời gian sửa. Tệp dẫn xuất hợp lệ dùng cache immutable; staging vẫn phải có lớp bảo vệ truy cập cho cả ảnh và tệp tĩnh. Nguồn gốc ảnh mẫu hiện có được ghi trong README; tiệm cần thay hoặc xác nhận bộ ảnh thật trước mở bán.

## Node SSR tùy chọn

PHP fallback hoạt động khi không chạy Node. Nếu hạ tầng được duyệt có Node phù hợp với package.json, cài dependency và build hai đầu ra từ cùng commit.

```powershell
npm ci
npm run build
npm run build:ssr
node bootstrap/ssr/ssr.js --smoke

# Cấu hình PHP của đúng môi trường; không bật lập chỉ mục hay nhận đơn tại bước này.
$env:BAKERY_SSR_ENABLED = 'true'
$env:BAKERY_SSR_URL = 'http://127.0.0.1:13714'
$env:BAKERY_SSR_PORT = '13714'
node bootstrap/ssr/ssr.js
```

Các biến `BAKERY_SSR_ENABLED` và `BAKERY_SSR_URL` phải được PHP nạp qua `.env` hoặc môi trường của chính dịch vụ PHP; cập nhật cache cấu hình theo quy trình vận hành rồi khởi động lại dịch vụ. Đặt biến trong terminal chạy Node không cập nhật môi trường của tiến trình PHP đang chạy. `BAKERY_SSR_PORT` cấu hình cổng tiến trình Node; hai phía phải trỏ tới cùng endpoint loopback.

Chạy tiến trình Node bằng trình quản lý tiến trình của hạ tầng. Trên Windows, helper chạy nền dùng cửa sổ ẩn; giữ stdout/stderr trong nơi có quyền phù hợp. Không expose hoặc proxy `/render` ra Internet. PHP và Node dùng cùng máy/namespace loopback; nếu container khác nhau, cần thiết kế và nghiệm thu riêng thay vì đổi URL thành địa chỉ bên ngoài để vượt giới hạn gateway.

Dùng trực tiếp `node bootstrap/ssr/ssr.js`, không dùng `php artisan inertia:start-ssr` cho renderer này. Wrapper vendor chờ định dạng thông báo khởi động riêng của server vendor; renderer công khai trong repo dùng giao thức/marker riêng. Có thể kiểm tra `http://127.0.0.1:13714/health` từ chính máy chạy PHP. Khi cập nhật mã hoặc cấu hình, build lại rồi nạp lại Node; dùng smoke test và kiểm tra HTML thật trước khi kết luận SSR đã chạy.

Không đưa URL hoặc cổng Node vào cấu hình web server công khai. Public document root vẫn là `public/`; `.env`, vendor, bootstrap SSR và nơi lưu cache/bản sao nằm ngoài vùng phục vụ tệp. Tiếp tục dùng quy trình backup, phát hành và quay lui ở [hướng dẫn vận hành](van-hanh.md), chỉ chọn bản tương thích schema hiện tại.

## Ghi bằng chứng cho phiên bản nghiệm thu

Mỗi lần đo cần ghi commit hoặc nguồn working tree, môi trường, người thử, thời điểm, chế độ PHP/Node, kích thước viewport và điều kiện cache/mạng. So sánh trên cùng fixture đã được phép dùng; giữ hash của ảnh gốc để phân biệt hiệu quả resize với thay nội dung ảnh.

| Bằng chứng cần ghi | Phạm vi kiểm chứng |
| --- | --- |
| HTML trang chủ/danh mục/sản phẩm với Node tắt và bật | Có nội dung chính, một canonical/title/JSON-LD thích hợp, liên kết thật, ảnh đúng nguồn. |
| Meta/header và sitemap | Ma trận index/noindex, URL riêng không bị liệt kê, Host/query không làm đổi canonical hoặc chèn URL lạ. |
| Điều hướng React | Chuyển qua danh mục, sản phẩm và trang riêng; không còn metadata/schema của trang trước. |
| Payload gateway | Chỉ trang công khai ẩn danh, không có email tài khoản, giỏ, lỗi, thông báo hoặc token/URL riêng. |
| Ảnh | Byte truyền, cỡ được chọn, width/height, cache, fallback khi mất/hỏng tệp, ảnh gốc giữ nguyên. |
| Hiệu năng | Số đo từ công cụ và điều kiện thực tế; ghi riêng trước/sau, không suy ra điểm tốc độ khi chưa đo. |
| Chất lượng mã | Lint, build, build SSR/smoke, Pint và PHPUnit SQLite/MySQL; không thay kiểm thử đồng thời bằng kết quả SQLite. |

Các kết quả local chứng minh hành vi trong cấu hình thử. Tên miền thật, thiết bị, mạng giao nhận, nội dung và ảnh thật, xác nhận doanh nghiệp, công cụ quản lý tìm kiếm và hiệu năng người dùng thật vẫn cần nghiệm thu. Chưa dùng các thay đổi SEO/SSR làm căn cứ tự mở bán hoặc tự bật lập chỉ mục.

## Biên bản nghiệm thu local ngày 25/11/2026

Người thử: **Codex**. Nguồn ứng viên là commit `4a4e1f3705e8f087302b02c1e2e3e6d3ba8e7485`; bản đối chiếu là `2c24e969e94f5f2838cbbf42ca0e7be1a2a8ed10`. Bằng chứng được tạo từ working tree ứng viên trước khi đóng commit nguồn; [manifest phiên nghiệm thu](evidence/seo-2026-11-25/validation.json) ghi nhận commit và mã băm tệp bằng chứng. Đây là sandbox local Windows, PHP 8.3.33, MySQL 8.4.13 và trình duyệt trong Codex, dùng dữ liệu thử được gắn nhãn rõ. Không có thao tác triển khai public hoặc duyệt dữ liệu thật.

### Kết quả kiểm tra

| Kiểm tra | Kết quả cuối | Bằng chứng |
| --- | --- | --- |
| PHPUnit MySQL, schema kiểm thử riêng | 112/112 bài đạt, 2.083 assertions, không bỏ qua; gồm các bài đồng thời | [MySQL](evidence/seo-2026-11-25/check-mysql.txt) |
| PHPUnit SQLite bộ nhớ | 105 bài đạt, 7 bài đồng thời cần MySQL được bỏ qua, 2.026 assertions | [SQLite](evidence/seo-2026-11-25/check-sqlite.txt) |
| ESLint | Đạt | [Lint](evidence/seo-2026-11-25/check-lint.txt) |
| Vite client build | Đạt | [Build](evidence/seo-2026-11-25/check-build.txt) |
| Pint `--test` | Đạt | [Pint](evidence/seo-2026-11-25/check-pint.txt) |
| SSR build và smoke | Đạt 5 trang công khai, từ chối trang riêng | [SSR](evidence/seo-2026-11-25/check-ssr.txt) |
| HTTP thực tế và liên kết | 24 ca đại diện; 28 liên kết công khai cùng origin, 0 liên kết chết; 4 ca kiểm tra lại cuối đều đạt | [HTTP audit](evidence/seo-2026-11-25/http-audit.json) |

HTTP audit giữ lịch sử phát hiện và sửa, gồm lượt kiểm tra chưa đạt; không phải mọi bản ghi lịch sử đều đạt. Một URL thử kết hợp bộ lọc chỉ có một trang kết quả với `page=2` đã được sửa kỳ vọng và tách thành hai URL hợp lệ. Các sửa lỗi đã kiểm tra lại gồm URL tài nguyên Vite dùng nguồn APP_URL tin cậy, URL phân trang chỉ giữ bộ lọc hợp lệ và URL chuyển tới SSR không mang query lạ hoặc danh mục chèn thêm. Tệp robots tĩnh đã được gỡ để yêu cầu thật đi qua route robots có cổng SEO. Kiểm thử tự động bổ sung các trường hợp slug cũ, bánh ẩn, hết hàng và trạng thái xác minh dữ liệu.

### HTML, điều hướng và ảnh

Khi Node chạy, trang bánh thử trả HTTP 200 bằng React SSR với một title, một canonical, nội dung H1 và Product/BreadcrumbList. Sau khi dừng Node, cùng trang tiếp tục trả HTTP 200 và các nội dung đó bằng PHP fallback. Đây là kiểm tra phục hồi chức năng; hai lần đo đơn lẻ không chứng minh Node nhanh hơn hoặc chậm hơn. Đối chiếu [Node chạy](evidence/seo-2026-11-25/render-running.json) và [Node dừng](evidence/seo-2026-11-25/render-stopped-fallback.json).

Điều hướng React qua danh mục, sản phẩm và đăng nhập đã được kiểm tra. Trang riêng xóa canonical, Open Graph và schema của trang công khai trước đó; `/cua-hang` chưa có dữ liệu cơ sở được duyệt nên không tạo Bakery. [Metadata sau điều hướng](evidence/seo-2026-11-25/ui-metadata.json) lưu trạng thái thực tế. Giao diện được quan sát ở viewport giả lập mobile 390 và desktop 1440; chưa phải nghiệm thu trên điện thoại thật. [Ảnh desktop](evidence/seo-2026-11-25/after-product-1440.jpg), [ảnh mobile](evidence/seo-2026-11-25/after-product-390.jpg) và [trang chủ mobile](evidence/seo-2026-11-25/after-home-390.jpg).

Ca ảnh hỏng hiển thị “Ảnh đang được cập nhật”, chọn ảnh trong gallery vẫn hoạt động: [kết quả](evidence/seo-2026-11-25/broken-image.json), [ảnh chụp](evidence/seo-2026-11-25/broken-image-390.jpg). Nguồn ảnh thử gốc có SHA-256 `e8cea015cbe4f5e63f7928a05a5b71bf45afe3f43882c218e47079f55c15d210`, được giữ nguyên sau tạo dẫn xuất. Có 7 nguồn ảnh có metadata responsive; không có ảnh ngoài fixture được tự duyệt.

### Hiệu năng: phần đã đo và giới hạn

So sánh CSR của bản đối chiếu với HTML PHP của ứng viên, dùng cùng DB, giá và ảnh thử 900 × 758 px, gốc 125.160 byte. Hai proxy lab đều thêm độ trễ 150 ms mỗi phản hồi, giới hạn băng thông phần thân dùng chung 200.000 byte/giây, gzip văn bản và `private, no-store`. Viewport danh nghĩa 390 × 844; không giảm tốc CPU. Đã dừng bộ kiểm thử và crawler trước các lượt đo cuối tuần tự. Trang chủ và sản phẩm có 3 mẫu mỗi phía; chính sách có 1 mẫu mỗi phía, chỉ dùng so byte.

Byte JS dưới đây là phần thân script production đã nén; không tính script probe lab giống nhau ở cả hai phía. Byte ảnh là tổng phần thân ảnh ghi nhận tải trên từng trang trong fixture, không phải mức giảm của một tệp ảnh riêng. [Tổng hợp cuối](evidence/seo-2026-11-25/performance-summary.json) và [toàn bộ lượt đo cuối](evidence/seo-2026-11-25/performance-runs.json) là nguồn số liệu. `baseline-metrics.json` là bản trích 6 lượt trang chủ/sản phẩm của phía đối chiếu trong tập đo cuối; dùng tập đầy đủ khi so sánh hai phía.

| Chỉ số | Trang chủ: trước → PHP | Sản phẩm: trước → PHP |
| --- | --- | --- |
| Tổng byte ảnh tải trên trang | 125.160 → 69.296 (**−44,6%**) | 125.160 → 51.502 (**−58,9%**) |
| Byte JS production | 151.472 → 152.995 (**+1,0%**) | 151.472 → 152.995 (**+1,0%**) |
| Byte HTML | 1.290 → 4.436 | 1.487 → 4.745 |
| TTFB trung vị | 683 → 776 ms (**tăng 13,7%**) | 798 → 756 ms (**giảm 5,3%**) |
| Sự kiện `window.onload`, trung vị | 2.115 → 4.191 ms (**tăng**) | 2.242 → 3.988 ms (**tăng**) |
| CLS lab, trung vị | 0,00122 → 0,00130 | 0,00117 → 0,00117 |

Trang chính sách giảm JS từ 154.079 xuống 145.192 byte (**−5,8%**), không có ảnh; HTML tăng từ 783 lên 2.353 byte. Một mẫu không đủ kết luận về độ trễ của trang này. HTML tăng vì chứa nội dung ban đầu thay vì chỉ phần khung CSR.

Kết quả có cả cải thiện và tăng chi phí: ảnh giảm byte, JS hai trang chính tăng nhẹ, TTFB trang chủ tăng, và `window.onload` tăng trên cả hai trang. CSR có thể phát `load` trước khi ảnh được JavaScript chèn vào; HTML từ máy chủ có ảnh ngay trong tài liệu ban đầu nên phạm vi chờ của sự kiện thay đổi. `window.onload` không phải LCP hay mốc sẵn sàng tương đương giữa hai cách render. Không kết luận toàn bộ ứng dụng nhanh hơn từ các số này.

FCP/LCP trong tổng hợp được đánh dấu **không chấp nhận để so sánh** và đặt `null`. Dữ liệu thô có cả giá trị thiếu và giá trị muộn bất thường, không phải tất cả đều thiếu; trình duyệt nhúng báo paint không ổn định dù có API. Chưa xác minh FCP/LCP trên thiết bị thật, Core Web Vitals thực tế, điểm Lighthouse hoặc tốc độ tương đối của Node SSR. CLS lab thấp chưa chứng nhận Core Web Vitals ngoài thực tế.

### Trạng thái sau ca thử

Theo [trạng thái runtime cuối](evidence/seo-2026-11-25/final-runtime.json), địa chỉ local bình thường là **http://127.0.0.1:18926**, schema `tiembanh_seo_20261125_test`. Nhận đơn đang tạm dừng, indexing tắt, `business_verified` và `public_content_approved` tắt; có 7 đơn seed, 0 thư đang đợi và 0 thư lỗi. Robots trả `Disallow: /`, sitemap có 0 URL. Đây là mail/local sandbox, không phải xác nhận SMTP thật. Các proxy benchmark 18927/18928 và tiến trình SSR thử 18929 đã dừng; không dùng các cổng đó làm URL vận hành.

### Cách chạy lại từ bản clone

Làm theo [README](../README.md) để cài PHP/MySQL/Node đúng phiên bản và cấu hình một môi trường local/test mới. Chạy `composer install`, `npm ci`, sao chép `.env.example` rồi tạo khóa riêng; dùng schema, tài khoản DB, media, storage, cookie và cache dành riêng. Giữ `BAKERY_SEO_INDEXING_ENABLED=false`, không dùng dữ liệu thật chưa được phép. Migration/seed chỉ được chạy sau khi xác nhận DB đích là schema thử, không trỏ tới DB đang vận hành.

```powershell
composer install
npm ci
# Chỉ sau khi đã cấu hình và kiểm tra schema local/test riêng:
php artisan migrate --seed
php artisan bakery:images --missing
npm run lint
npm run build
php vendor/bin/pint --test
php vendor/bin/phpunit --do-not-cache-result --colors=never
npm run test:ssr
```

`phpunit.xml` mặc định dùng SQLite bộ nhớ. Để kiểm tra đồng thời, dùng cấu hình PHPUnit/MySQL thử riêng theo [hướng dẫn vận hành](van-hanh.md), đặt `APP_ENV=testing`, schema có hậu tố `_test`, DB_URL phù hợp hoặc rỗng, mail/cache/queue thử và các thư mục storage/cache tách khỏi web đang chạy; chạy với `--fail-on-skipped` để tránh bỏ qua các bài MySQL. Không chạy migration thủ công lên runtime đang phục vụ ca khác. Giữ log đầy đủ, số assertions và commit đang thử.

Để lặp lại A/B, chuẩn bị hai checkout/môi trường độc lập của đúng commit đối chiếu và ứng viên, cùng fixture ảnh/hash/giá và các cổng loopback khác nhau. Công cụ lab công khai [performance-proxy.cjs](../ops/performance-proxy.cjs) và [performance-probe.js](../ops/performance-probe.js) không thuộc JS production. Xem tùy chọn rồi khởi động proxy chỉ trong ca đo:

```powershell
node ops/performance-proxy.cjs --help
node ops/performance-proxy.cjs --baseline=18925 --candidate=18926 --baseline-listen=18927 --candidate-listen=18928 --latency=150 --bandwidth=200000
```

Chọn cổng còn trống, giữ cùng điều kiện cache/mạng, dừng tác vụ nền gây tải và đo ít nhất 3 lượt trang chủ/sản phẩm cho mỗi phía. Lưu byte truyền, TTFB, CLS, `load` và toàn bộ quan sát paint, kể cả kết quả không chấp nhận. Dừng proxy sau đo. Muốn đánh giá SSR, cần ca so sánh riêng đủ mẫu. Các wrapper trong `work/seo-20261125/` chỉ phục vụ workspace hiện tại, chứa cấu hình riêng và không phải dependency của bản clone hay tài liệu công khai; không sao chép bí mật của chúng vào Git.

### Đầu vào thật còn chờ

| Mục | Trạng thái | Người chịu trách nhiệm và bước tiếp |
| --- | --- | --- |
| HTML, meta, bảo vệ URL riêng, ảnh responsive và fallback | **Đạt local** trong phạm vi trên | Kỹ thuật giữ bằng chứng theo commit; chạy lại trên môi trường đích. |
| Thương hiệu, logo, menu/giá/tồn, khẩu phần, thành phần/dị ứng/bảo quản, ảnh | **Chờ dữ liệu và quyền sử dụng thật** | Chủ tiệm cùng người làm bánh/menu duyệt từng phần trong quản trị; không thay bằng fixture. |
| NAP, giờ mở cửa, bản đồ và nội dung giới thiệu/FAQ/chính sách | **Chờ duyệt** | Chủ tiệm cung cấp tên–địa chỉ–điện thoại thống nhất, lịch và văn bản cuối; bản nháp phía trên chưa nằm trong DB. Đối chiếu hồ sơ Google Business nếu tiệm sử dụng. |
| Tên miền, DNS, HTTPS được thiết bị tin cậy và bảo vệ staging toàn bộ tài nguyên | **Chờ hạ tầng thật** | Người phụ trách hạ tầng nghiệm thu từ bên ngoài; thử lại `/up`, tệp tĩnh, ảnh, robots và sitemap với quyền truy cập đúng. |
| Search Console, sitemap và Rich Results trên URL thật | **Chờ chủ tiệm cấp quyền/phê duyệt** | Xác minh quyền sở hữu, kiểm tra robots/sitemap và dữ liệu có cấu trúc trên đúng tên miền; chưa tạo tài khoản hoặc gửi URL public trong ca này. |
| Core Web Vitals và trải nghiệm thiết bị thật | **Chưa xác minh** | Kỹ thuật đo thiết bị/mạng thật, paint đáng tin cậy và dữ liệu hiện trường; đánh giá riêng Node SSR nếu muốn dùng. |
| Nhận đơn và lập chỉ mục | **Giữ đóng** | Chủ tiệm/người được giao phê duyệt ngoài ca thử, sau khi đủ các điều kiện vận hành; không bật tự động theo kết quả kỹ thuật. |

Kết luận ca này: phần kỹ thuật đã đạt các kiểm tra local đã nêu; nghiệm thu dịch vụ thật và quyết định mở bán vẫn chờ đúng các đầu vào ở bảng trên.
