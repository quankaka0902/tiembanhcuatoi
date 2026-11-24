# Cửa hàng trên điện thoại, bộ lọc và thông tin bánh

Phạm vi gồm trang chủ, thực đơn, chi tiết bánh, lựa chọn phụ kiện và thông tin cần giữ qua giỏ / đơn. Giao diện giữ tông kem, hồng berry, phông Playfair Display và DM Sans. Dữ liệu dưới đây là hợp đồng của ứng dụng; chưa phải xác nhận menu, ảnh hoặc dịch vụ thật của tiệm. Công tắc nhận đơn thật tiếp tục theo quy trình phê duyệt hiện có.

## Đầu vào cần tiệm xác minh

| Nhóm | Dữ liệu và cách kiểm tra | Trạng thái hiện tại |
| --- | --- | --- |
| Ảnh | Ảnh từng bánh / cỡ, quyền sử dụng và người duyệt. Đối chiếu ảnh với bánh thực tế. | Chưa có bộ ảnh thật của tiệm đã được duyệt trong repo. Ảnh seed không được tự nâng thành ảnh đã duyệt. |
| Thông tin bánh | Thành phần, dị ứng, tiếp xúc chéo nếu tiệm áp dụng, bảo quản, dịp và hương vị. | Cần người am hiểu công thức xác nhận. Dữ liệu cũ thiếu thông tin vẫn hiển thị trạng thái chưa xác minh. |
| Cỡ và khẩu phần | Tên cỡ, `size_label`, số người tối thiểu / tối đa của từng phiên bản. | Chưa có bảng khẩu phần thật được tiệm duyệt, gồm các lựa chọn cho 8 người. Fixture dùng trong test không xác nhận menu bán thật. |
| Giá và tồn | Giá VND, số lượng còn bán, phí lời chúc và thời gian chuẩn bị. | Cần đối chiếu bảng giá và khả năng làm bánh của cơ sở trước mở bán. |
| Phụ kiện | Tên, giá, tình trạng cung cấp và người duyệt từng lựa chọn theo sản phẩm. | Chỉ phụ kiện đang hoạt động và đã duyệt mới xuất hiện để khách chọn. Không tự tạo lựa chọn giả để làm đầy giao diện. |
| Cơ sở và giao hàng | Tên, địa chỉ, giờ mở cửa, vùng giao, phí và ngưỡng miễn phí. | Lấy từ cấu hình hiện tại. Tiệm phải xác minh dữ liệu thật và người xử lý giao hàng. |

Các dấu xác minh độc lập với nhau.

- `products.details_verified` xác nhận nội dung chi tiết. Khi bật, máy chủ yêu cầu thành phần, dị ứng và bảo quản có nội dung. Dịp và hương vị dùng cho bộ lọc lấy từ các sản phẩm đang bán đã xác minh chi tiết.
- `products.images_approved` xác nhận bộ ảnh được duyệt và yêu cầu ít nhất một ảnh. Không suy ra quyền dùng ảnh từ việc tải tệp lên hoặc từ cờ xác minh nội dung.
- `variants.servings_verified` xác nhận khoảng khẩu phần của từng phiên bản, với cả số tối thiểu và tối đa từ 1 đến 200, tối đa không nhỏ hơn tối thiểu. Không suy khẩu phần từ tên cỡ, đường kính 16 cm hay tên bánh.
- `product_accessories.active` và `approved` kiểm soát phụ kiện được phép cung cấp. Xác minh ảnh hoặc thông tin bánh không tự duyệt phụ kiện.

Quản trị viên phải đối chiếu thông tin với người phụ trách trước khi bật dấu tương ứng. Nhãn “chưa xác minh” là thông tin cho người mua, không thay cho quy trình duyệt mở bán.

## Luồng chọn bánh

Trang chủ có nút **Chọn bánh**, gợi ý dịp từ dữ liệu đã xác minh, danh mục, bánh nổi bật và thông tin một cơ sở đang phục vụ. Hero chỉ dùng ảnh nổi bật được duyệt. Nếu chưa có ảnh phù hợp, giao diện hiển thị placeholder với chú thích chờ ảnh xác nhận; không dùng ảnh bánh dâu cứng làm ảnh thật của tiệm.

Thẻ bánh có một liên kết chính cho cả thẻ để người dùng bàn phím không phải đi qua nhiều liên kết giống nhau. Giá, tình trạng hết hàng, trạng thái thông tin và ảnh chưa được xác minh có văn bản; ảnh lỗi chuyển thành placeholder. Thẻ không tự thêm vào giỏ khi người mua chưa chọn phiên bản.

Trang chi tiết cho xem ảnh chính và ảnh phụ bằng nút có trạng thái chọn, chọn cỡ bằng nhóm radio, nhập số bánh, lời chúc, phụ kiện được duyệt và ghi chú. Giá cùng khẩu phần hiển thị theo phiên bản. Cảnh báo dị ứng nằm trước nhóm lựa chọn và nút thêm giỏ. Nội dung thiếu hiển thị rõ chưa được cung cấp / xác minh, không diễn đạt thành “không có dị ứng”.

Thời gian chuẩn bị là thông tin về sản phẩm. Nó không hứa một giờ giao hoặc nhận cụ thể. Lịch còn chỗ, ngày nghỉ, lead time, vùng và phí giao được kiểm tra lại ở bước đặt bánh.

## Tìm kiếm, bộ lọc và URL

| Query | Ý nghĩa |
| --- | --- |
| `q` | Tìm tên, mô tả và nội dung tìm kiếm đã chuẩn hóa, tối đa 100 ký tự. Có hỗ trợ chữ Việt không dấu, ví dụ `banh kem dau`. |
| `category` | Slug danh mục. |
| `occasion`, `flavor` | Giá trị dịp / hương vị trong dữ liệu của tiệm. Chỉ sản phẩm đã xác minh chi tiết được dùng cho lọc này. |
| `guests` | Số người, từ 1 đến 200. Chỉ khớp phiên bản có `servings_verified`, khoảng khẩu phần bao gồm số đã nhập. |
| `min`, `max` | Giá bánh theo phiên bản, số nguyên VND từ 0 đến 100.000.000, chưa gồm tùy chọn và giao hàng. `max` không nhỏ hơn `min`. |
| `availability` | `all`, `available`, `sold_out`. Tình trạng xét trên các phiên bản khớp bộ lọc. |
| `sort` | `featured`, `new`, `price_asc`, `price_desc`. |
| `page` | Phân trang, các điều kiện trước đó được giữ trong liên kết. |

Khẩu phần, khoảng giá và tình trạng phải khớp **cùng một phiên bản**. Ví dụ cỡ nhỏ giá 200.000 ₫ không đủ 8 người và cỡ lớn giá 400.000 ₫ phù hợp 8 người thì bộ lọc 8 người, tối đa 300.000 ₫ không được trả sản phẩm đó chỉ vì từng điều kiện khớp một cỡ khác nhau.

Giá trên thẻ ưu tiên giá thấp nhất của phiên bản còn tồn trong tập khớp, nếu tất cả hết hàng thì dùng giá thấp nhất của tập đó. Khi có chênh lệch giá giữa các phiên bản, giao diện dùng “Từ”. Vì vậy không lấy giá cỡ đã hết làm giá khởi điểm cho các lựa chọn còn đặt được. Cỡ có giá thấp hơn nhưng hết hàng vẫn xem được ở trang chi tiết cùng trạng thái rõ ràng.

Các điều kiện đã áp dụng hiện thành chip có thể bỏ riêng. Xóa chip hoặc áp dụng bộ lọc mới đưa về trang kết quả đầu. Khi phân trang, mở chi tiết rồi dùng Back hoặc breadcrumb, các điều kiện được giữ trong URL; form được đồng bộ lại từ `filters` do máy chủ trả về. Không chỉ giữ bộ lọc bằng state cục bộ của trình duyệt.

Liên kết từ thẻ mang theo điều kiện sang trang bánh. Máy chủ trả `suggestedVariantId` dựa trên khẩu phần đã xác minh và khoảng giá của cùng phiên bản, ưu tiên còn tồn. Giao diện giữ cỡ được gợi ý; nếu nó đã hết thì hiển thị hết hàng, không tự giả định còn đặt được. Nếu không có cỡ phù hợp, giao diện yêu cầu khách xem và chọn lại, không âm thầm coi một cỡ khác là phù hợp.

## Giá phụ kiện, lời chúc và bản chụp đơn

Phụ kiện có tên, giá và cờ hoạt động / duyệt theo từng sản phẩm. Khách gửi danh sách ID lựa chọn, không gửi giá có thẩm quyền. Máy chủ lấy lại giá và kiểm tra phụ kiện vẫn thuộc đúng bánh, đang hoạt động và đã duyệt ở giỏ, báo giá và đặt đơn. Phụ kiện bị gỡ duyệt hoặc ngừng cung cấp làm dòng giỏ cần được cập nhật, không bị coi là hợp lệ theo dữ liệu cũ từ trình duyệt.

Một lựa chọn phụ kiện tính **một đơn vị cho mỗi bánh**. Chọn hai loại phụ kiện và số lượng hai bánh tính hai đơn vị của mỗi loại. Giao diện ghi rõ quy tắc này, không có số lượng phụ kiện lẻ độc lập ở phiên bản hiện tại.

```text
Tạm tính dòng = (giá bánh + phí lời chúc nếu có + tổng giá phụ kiện đã chọn) × số bánh
Tạm tính giỏ = tổng các dòng
Tổng thanh toán = tạm tính giỏ - giảm giá + phí giao
```

Theo quy tắc đang có, phí lời chúc và phụ kiện nằm trong tạm tính dùng để xét mã giảm, tính giảm phần trăm và ngưỡng miễn phí giao sau giảm giá. Tiệm cần xác nhận cách áp dụng này trước mở bán. Giá bộ lọc chỉ là giá bánh theo cỡ, vì phụ kiện / lời chúc là lựa chọn phát sinh sau đó.

Báo giá có chữ ký của máy chủ, bao gồm phụ kiện và phí tương ứng. Nếu giá thay đổi lúc gửi đơn, khách phải xem tổng mới rồi xác nhận lại theo luồng báo giá hiện có. Sửa giá trong trình duyệt không thay được kết quả tính trên máy chủ.

`order_items.accessories_snapshot` lưu tên, ID và giá phụ kiện tại lúc mua, `accessory_fee` lưu tổng phí trên mỗi bánh. Đổi tên, giá, ẩn hoặc gỡ duyệt phụ kiện sau này không viết lại lịch sử đơn. Các dòng đơn cũ có phí phụ kiện 0 và snapshot rỗng / null; không gán phụ kiện hiện tại cho đơn cũ. Mua lại tiếp tục lấy giá hiện hành, khách chọn lại lời chúc và phụ kiện cho đơn mới.

Tồn phụ kiện chưa có sổ số lượng riêng. Người phụ trách phải ngừng cung cấp phụ kiện khi không còn khả năng đáp ứng; thiết kế tồn riêng hoặc phụ kiện mua theo số lượng lẻ là quyết định cần chốt nếu tiệm cần.

## Một cơ sở và dữ liệu cũ

`currentLocation` gồm `name`, `address`, `hours`, `regions`, `free_from` từ cấu hình cơ sở đang phục vụ. Trang không tạo bộ chọn chi nhánh khi chưa có nhiều cơ sở. Hiển thị vùng / phí hiện có, dùng lời nhắc kiểm tra giờ còn chỗ tại checkout, không cam kết giao theo thời gian thực.

Phạm vi đơn vẫn đi qua cơ sở hiện tại như thiết kế vòng đời đơn. Để vận hành nhiều cơ sở, cần thiết kế thêm menu, tồn, khu vực, nhân sự, lịch và lựa chọn cơ sở thực; riêng khối thông tin cơ sở chưa tạo ra nghiệp vụ nhiều chi nhánh.

Hai migration mới bổ sung trường metadata, bảng phụ kiện, snapshot dòng đơn và nội dung tìm kiếm chuẩn hóa. Dữ liệu cũ giữ tên, giá, tồn và khả năng bán hiện có. Các dấu xác minh mới mặc định tắt, khẩu phần / cỡ mới để trống; sản phẩm vẫn xem và đặt được theo quy tắc trước nhưng không tự trở thành bánh có khẩu phần hay ảnh đã xác nhận. Migration nội dung tìm kiếm chỉ chuẩn hóa văn bản, không xác minh dữ liệu tiệm.

Không sửa seed thành menu thật hoặc bật các cờ chỉ để bộ lọc trả nhiều kết quả. Fixture riêng được ghi rõ là kiểm thử chỉ chứng minh hành vi kỹ thuật trên local / test.

## Khả năng sử dụng và ca trình duyệt đã chạy

Home, Catalog và Product có CSS bổ sung riêng trong `resources/css/storefront-mobile.css`. Nút / liên kết chính có vùng chạm tối thiểu 44 px; ảnh phụ và các lựa chọn cỡ có vùng chạm rộng hơn. Nhóm cỡ dùng `fieldset` / `legend`, radio có nhãn, ảnh phụ có `aria-pressed`, kết quả tìm kiếm có vùng thông báo và lỗi có thông báo kèm điểm focus. Bộ lọc mở phần chứa trường lỗi trước khi đưa focus tới trường cần sửa.

Không có thanh mua cố định che biểu mẫu ở trang chi tiết. Nút thêm giỏ nằm trong luồng, tổng tùy chọn hiển thị ngay cạnh bước xác nhận lựa chọn. Các khung hẹp dùng cột phù hợp thay vì buộc người mua cuộn ngang.

Ca kỹ thuật ngày 24/11/2026 do Codex thực hiện trên working tree của bản nguồn được đóng trong commit local `5c8933a8a9e39928815160d24b30c70fde619379`. Web dùng `http://127.0.0.1:18924`, MySQL 8.4.13 tại loopback cổng 33317, schema `tiembanh_mobile_20261124_test`, kho ảnh, cookie và mailer log riêng. Cả cổng web và MySQL chỉ nghe trên `127.0.0.1`. Trình duyệt là Chromium trong IAB, thay đổi viewport desktop để kiểm tra responsive. Đây không phải kết quả trên thiết bị di động thật hoặc HTTPS của tiệm.

Đã đối chiếu chiều rộng cuộn với chiều rộng nội dung ở cả bốn viewport. Phần 15 px của thanh cuộn dọc được phân biệt với tràn ngang; không phát hiện tràn ngang ở các trang trong ma trận. [Bản ghi viewport](evidence/storefront-2026-11-24/viewport-checks.json) gồm lần đo và lần kiểm tra lại sau cùng.

Ảnh ma trận Home / Catalog / Product / Checkout được chụp lại với CSS cuối của commit trên. Các ảnh tình huống lỗi, mất kết nối và đổi giá được chụp trước khi sửa màu đường viền nên còn biên nhạt hơn; chúng làm bằng chứng hành vi, không dùng để kết luận tương phản của CSS cuối.

| Cỡ kiểm tra | Ca đã thực hiện | Kết quả local / bằng chứng |
| --- | --- | --- |
| 320×900 | Home, thực đơn, chi tiết và checkout; kiểm tra cột thẻ, nhãn dài, ảnh, lựa chọn và tổng | ĐẠT. [Home](evidence/storefront-2026-11-24/home-320.jpg), [thực đơn](evidence/storefront-2026-11-24/catalog-320.jpg), [chi tiết](evidence/storefront-2026-11-24/product-320.jpg), [checkout](evidence/storefront-2026-11-24/checkout-320.jpg). |
| 390×900 | Bốn trang; bộ lọc, cỡ, phụ kiện, lời chúc và nhập đơn bằng dữ liệu thử | ĐẠT. [Home](evidence/storefront-2026-11-24/home-390.jpg), [thực đơn](evidence/storefront-2026-11-24/catalog-390.jpg), [chi tiết](evidence/storefront-2026-11-24/product-390.jpg), [checkout](evidence/storefront-2026-11-24/checkout-390.jpg). |
| 768×900 | Bố cục tablet của bốn trang, thông tin bánh, vùng lựa chọn, form và tổng | ĐẠT. [Home](evidence/storefront-2026-11-24/home-768.jpg), [thực đơn](evidence/storefront-2026-11-24/catalog-768.jpg), [chi tiết](evidence/storefront-2026-11-24/product-768.jpg), [checkout](evidence/storefront-2026-11-24/checkout-768.jpg). |
| 1440×900 | Bố cục desktop, cột ảnh / chi tiết, bộ lọc và checkout | ĐẠT. [Home](evidence/storefront-2026-11-24/home-1440.jpg), [thực đơn](evidence/storefront-2026-11-24/catalog-1440.jpg), [chi tiết](evidence/storefront-2026-11-24/product-1440.jpg), [checkout](evidence/storefront-2026-11-24/checkout-1440.jpg). |
| Bàn phím | Dùng Tab / Enter với menu điện thoại và bộ lọc, Space chọn ảnh phụ, phím Down chọn cỡ 8–10 người; đi tiếp qua checkout tới footer | ĐẠT trong phạm vi ca. Menu không giữ kẹt focus. Các điểm focus trong đường đi checkout có outline; [bản ghi thứ tự bàn phím](evidence/storefront-2026-11-24/keyboard-order.json). |
| Ảnh và dữ liệu thiếu | Hero không có ảnh được duyệt, ảnh 404, chọn ảnh thứ hai, legacy hai cỡ hết hàng và khẩu phần chưa xác minh, danh sách trống | ĐẠT. [Ảnh lỗi / ảnh phụ](evidence/storefront-2026-11-24/gallery-image-error-390.jpg), [bánh legacy hết hàng](evidence/storefront-2026-11-24/legacy-sold-out-390.jpg), [kết quả trống](evidence/storefront-2026-11-24/catalog-empty-390.jpg). |
| Quản trị metadata | Đăng nhập mật khẩu và TOTP, mở form đầy đủ của sản phẩm thử số 10, lưu lại dữ liệu không đổi, nhận thông báo thành công; sau đó đăng xuất | ĐẠT. [Form metadata desktop](evidence/storefront-2026-11-24/admin-metadata-1440.jpg). Không đồng nghĩa chủ tiệm đã xác minh dữ liệu thử. |

Ca lọc dùng từ khóa `dau`, danh mục bánh kem, dịp `Sinh nhật`, `guests=8`, hương vị `Dâu`, tối đa 600.000 ₫ và `available`. Các thao tác chọn / gửi bộ lọc được thực hiện bằng điều khiển bàn phím. URL và lựa chọn được giữ khi vào chi tiết rồi Back; ca chuyển trang 2, sắp xếp mới nhất và Back cũng giữ trạng thái. Bộ dữ liệu đáp ứng ca này là fixture có nhãn thử, không phải khẳng định menu thật có bánh phù hợp 8 người.

Đã kiểm tra riêng đường viền điều khiển màu `#94727e`, đo khoảng 4,14:1 trên nền kem và 4,23:1 trên nền trắng, thay cho đường viền cũ khoảng 1,3:1. Đây là kiểm tra màu biên điều khiển, chưa phải kết luận toàn trang tuân thủ WCAG. Chưa chạy nghiệm thu đầy đủ bằng trình đọc màn hình; NVDA không khả dụng trong ca này và Narrator chưa được gọi. Thiết bị iOS / Android thật, menu và ảnh thật vẫn cần nghiệm thu riêng.

## Ca lỗi và đặt đơn xuyên suốt

Fixture sản phẩm số 10 được mô tả rõ là **[THỬ NGHIỆM]**, có cỡ nhỏ 4–6 người giá 300.000 ₫ và cỡ lớn 8–10 người giá 500.000 ₫, mỗi cỡ có 8 bánh ban đầu, thời gian chuẩn bị 120 phút, phí lời chúc 15.000 ₫ và phụ kiện thử 25.000 ₫. Dịp là `Sinh nhật`, hương vị `Dâu`; thành phần, dị ứng và bảo quản đều ghi rõ là dữ liệu giả lập. Cờ xác minh khẩu phần / nội dung và duyệt phụ kiện chỉ phục vụ bài kiểm tra; ảnh vẫn chưa được duyệt. Các sản phẩm seed cũ giữ trạng thái chưa xác minh.

Với một bánh cỡ lớn, lời chúc và phụ kiện thử, tạm tính ban đầu là 540.000 ₫, `NHAMINH10` giảm còn 486.000 ₫. Ca đổi giá tăng giá cỡ lớn lên 510.000 ₫, tổng trước giảm thành 550.000 ₫ và tổng mới sau mã là 495.000 ₫.

| Ca | Kết quả và bằng chứng |
| --- | --- |
| Số điện thoại không hợp lệ | Nhập `123` bị từ chối. Focus chuyển về `checkout-phone`, có `aria-invalid`, liên kết hint / lỗi, nội dung khác được giữ. [Ảnh lỗi điện thoại](evidence/storefront-2026-11-24/checkout-phone-error-390.jpg). |
| Giá đổi trước khi gửi | Máy chủ chặn báo giá cũ, chưa tạo đơn. Focus về vùng phản hồi, giữ liên hệ, ngày, giờ và mã giảm; chỉ tiếp tục sau khi xác nhận giá mới. [Ảnh đổi giá](evidence/storefront-2026-11-24/checkout-price-change-390.jpg). |
| Khung cuối vừa hết chỗ | Một đơn demo khác chiếm chỗ cuối lúc 09:00 ngày 25/11. Danh sách giờ được cập nhật, focus về nhóm lịch, giữ ngày / liên hệ và chỉ xóa giờ đã mất. [Ảnh khung đầy](evidence/storefront-2026-11-24/checkout-slot-full-390.jpg). |
| Lỗi kết nối khi tính tổng | Chỉ tiến trình web local cổng 18924 được dừng để mô phỏng lỗi. Checkout giữ dữ liệu, hiện lỗi báo giá và chặn đặt khi chưa có tổng hợp lệ. Khởi động lại web và thử lại thành công. [Ảnh lỗi kết nối](evidence/storefront-2026-11-24/checkout-network-error-390.jpg). |
| COD nhận tại tiệm | Khách vãng lai tạo đơn `NB-261124-AD449E9B76`, tổng 495.000 ₫, hẹn 11:00 ngày 25/11/2026. Tải lại liên kết riêng vẫn tra cứu được. [Ảnh đơn thử](evidence/storefront-2026-11-24/guest-order-390.jpg). |

Các ca lỗi trên không thay đổi đơn thành công, không ghi nhận thu / hoàn tiền thật và không chứng minh dịch vụ SMTP thật. Ca mất kết nối thực tế chỉ kiểm tra yêu cầu lấy báo giá; chưa cắt kết nối riêng trong POST tạo đơn. Ca khung đầy qua trình duyệt tái hiện việc lịch thay đổi giữa hai lần gửi; các bài kiểm thử đồng thời bằng hai tiến trình vẫn chạy riêng trên MySQL.

[Đối chiếu DB cuối ca](evidence/storefront-2026-11-24/database-audit.json) ghi 8 đơn tổng cộng, trong đó đơn mới ở `pending`, `unpaid`; tạm tính 550.000 ₫, giảm 55.000 ₫, phí giao 0, tổng 495.000 ₫. Snapshot dòng giữ giá bánh 510.000 ₫, phí lời chúc 15.000 ₫ và phụ kiện thử 25.000 ₫. Có đúng một bút toán giữ tồn từ 8 xuống 7 cho dòng này. Worker đã ghi thông báo nhận đơn vào mailer log, `sent_at` có giá trị, một lần thử và không có lỗi; đây chưa phải kết quả giao thư qua SMTP thật.

Kết thúc ca, `accepting_orders=false`, không còn tác vụ chờ hoặc thư thất bại. Console được kiểm tra không có lỗi. Không có ảnh đã duyệt hoặc khẩu phần xác minh ngoài fixture thử trong schema này. Không mở bán public và chưa có phê duyệt dữ liệu thật của tiệm.

## Kết quả bàn giao phiên bản này

| Kiểm tra | Kết quả |
| --- | --- |
| Mã nguồn | `5c8933a8a9e39928815160d24b30c70fde619379`, ngày 24/11/2026, người thử kỹ thuật Codex. |
| Pint | ĐẠT, log local `work/storefront-pint-final.txt`. |
| ESLint | ĐẠT, log local `work/storefront-lint-final.txt`. |
| Build | ĐẠT, log local `work/storefront-build-final.txt`. |
| PHPUnit MySQL | 84/84 bài đạt, 1.735 assertions, 0 bỏ qua, schema riêng `tiembanh_stage7_phpunit_test`; log local `work/storefront-mysql-final.txt`. |
| PHPUnit SQLite | 84 tổng, 77 bài đạt, 7 ca MySQL được bỏ qua, 1.678 assertions; log local `work/storefront-sqlite-final.txt`. |
| Ca trình duyệt | ĐẠT LOCAL trong phạm vi các bảng trên; ảnh và bản ghi bàn phím ở `docs/evidence/storefront-2026-11-24`. |
| Trạng thái kết thúc | Tạm ngừng nhận đơn, 8 đơn, 0 tác vụ chờ, 0 thư thất bại theo đối chiếu DB cuối ca. |
| Menu, ảnh, khẩu phần 8 người và dịch vụ thật | CHỜ chủ tiệm xác nhận, chưa được nghiệm thu bằng fixture local |

[Bằng chứng tổng hợp](evidence/storefront-2026-11-24/validation.json) ghi commit nguồn và mã băm của hồ sơ. Các log công khai đã giới hạn nội dung gồm [MySQL](evidence/storefront-2026-11-24/check-mysql.txt), [SQLite](evidence/storefront-2026-11-24/check-sqlite.txt), [lint](evidence/storefront-2026-11-24/check-lint.txt), [build](evidence/storefront-2026-11-24/check-build.txt), [Pint](evidence/storefront-2026-11-24/check-pint.txt) và [diff check](evidence/storefront-2026-11-24/check-diff.txt).

PHPUnit chạy trước thay đổi cuối chỉ về màu đường viền CSS, mã PHP giữ nguyên sau lần chạy đó. ESLint và build đã chạy lại sau thay đổi CSS; ảnh ma trận cuối cũng được chụp lại sau khi tải bản nguồn đã đóng.

## Cách chạy lại

Cài đặt, migration và dữ liệu mẫu theo [README](../README.md). Trong terminal đã chọn đúng cấu hình PHP, chạy lại các kiểm tra mã như sau. Với MySQL, tạo schema kiểm thử trống có hậu tố `_test` và cấp credential của schema đó qua môi trường / kho bí mật trước khi chạy; bộ test tạo lại bảng trong schema đã chỉ định.

```powershell
npm.cmd run lint
npm.cmd run build
php vendor/bin/pint --test
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
php vendor/bin/phpunit --do-not-cache-result
# Cấu hình DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD cho schema MySQL test riêng.
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'tiembanh_mobile_phpunit_test'
php vendor/bin/phpunit --do-not-cache-result --fail-on-skipped
```

Fixture tự động công khai nằm trong `tests/Feature/StructuredMenuTest.php` và `tests/Feature/StorefrontDiscoveryTest.php`. Có thể chạy tập trung bằng `php vendor/bin/phpunit --filter 'StructuredMenuTest|StorefrontDiscoveryTest'`; MySQL vẫn cần chạy cả bộ để bao gồm các bài đồng thời.

Trong workspace đã chuẩn bị, profile và script ca thủ công nằm dưới `work/mobile-storefront-20261124`, được Git bỏ qua cùng credential. Để đọc trạng thái sandbox hiện có, mở terminal riêng và chạy:

```powershell
. .\work\mobile-storefront-20261124\profile.ps1
php work/mobile-storefront-20261124/control.php state
```

Để thử đặt đơn lại trong đúng sandbox này, có thể dùng `control.php open` rồi mở `http://127.0.0.1:18924`; kết thúc luôn chạy `control.php pause`. Script kiểm tra môi trường local và tên schema trước khi đổi công tắc. Không dùng lệnh này hoặc bật công tắc trên dữ liệu vận hành thật. Đăng nhập quản trị theo tài khoản local và quy trình TOTP trong README; dữ liệu cấp TOTP của sandbox được giữ riêng dưới `work`, không đưa vào hồ sơ công khai.

Các tệp riêng này không được hứa có trong một checkout mới. Từ checkout mới, dựng schema và kho ảnh local riêng, seed demo trên schema trống, nhập fixture có nhãn **[THỬ NGHIỆM]** theo bảng số lượng / giá ở trên qua quản trị rồi chạy lại bộ lọc, bàn phím, giỏ và checkout. Chỉ dùng cờ xác minh cho dữ liệu giả được phép phục vụ ca; không biến chúng thành xác nhận menu thật. Muốn lặp ca lỗi ảnh / đổi giá / tranh chỗ, dùng môi trường thử có quyền điều khiển tệp ảnh, giá và một đơn demo khác, giữ dữ liệu của khách thử khi gửi lại.

Cuối ca, tạm ngừng nhận đơn trong sandbox và đối chiếu trạng thái đã đóng trước bàn giao. Không có phê duyệt mở bán trong hồ sơ này. Quyền đổi / hủy / hoàn tiếp tục theo [hướng dẫn vòng đời đơn](vong-doi-don.md).
