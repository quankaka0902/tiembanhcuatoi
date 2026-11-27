# Địa chỉ giao, tìm cơ sở và xếp hạng khả năng phục vụ

Phần đang nghiệm thu local ngày 27/11/2026, nối tiếp [kiến trúc nhiều cơ sở](branches-architecture.md). Chưa mở bán hoặc tích hợp dịch vụ bản đồ thật. Commit và kết quả nghiệm thu cuối cần ghi theo đúng bản được thử; fixture không thay phê duyệt địa chỉ, phí giao hoặc thông tin thực tế của tiệm.

## Đầu vào và nguồn được phép dùng

Danh sách `/co-so` và chi tiết `/co-so/{slug}` chỉ công bố cơ sở hoạt động, địa chỉ/liên hệ/giờ, hình thức nhận và thanh toán được cấu hình. Cơ sở tạm dừng vẫn có thông tin, nhưng không trở thành ứng viên đặt đơn. Cơ sở bị ẩn không xuất hiện trong danh sách hoặc kết quả gợi ý. Khi chưa biết cả giỏ và số lượng, trang không hứa một giờ nhận áp dụng cho đơn.

Vùng giao cấu hình theo cơ sở có dạng `{key, name, fee, area_code?}`. `key` vẫn dùng cho hợp đồng checkout cũ; `area_code` nối cùng một vùng phục vụ giữa các cơ sở có key khác nhau. Thiếu `area_code` thì dùng chính `key`. Mã được trim/casefold ASCII, khớp đúng cấu hình; không dò gần đúng theo tên, không tự đoán quận/phường hoặc coi mã cửa hàng là mã hành chính chính thức. Không nhập danh sách hành chính giả để vượt kiểm tra.

| Đầu vào | Nguồn/chủ thể xác nhận |
| --- | --- |
| Địa chỉ/liên hệ/giờ, vùng/phí/ngưỡng miễn phí, lịch và công suất | Chủ tiệm/người quản lý được cấp quyền chốt theo từng cơ sở. |
| `latitude`, `longitude`, `coordinates_approved` | Nhập và duyệt trong quản trị. Thiếu hoặc chưa duyệt thì không dùng làm gốc tính khoảng cách. Không reverse-geocode rồi tự coi địa chỉ là đã xác minh. |
| Địa chỉ giao, ghi chú và mã hành chính tùy chọn | Khách nhập; giữ nguyên dấu tiếng Việt, gom khoảng trắng. Mã hành chính tùy chọn chưa được dịch vụ ngoài xác minh. |
| Pin giao/vị trí khách | Khách chủ động cấp qua giao diện hoặc nhập, rồi xác nhận pin. Không tự lấy vị trí khi tải trang. Pin xác nhận là lựa chọn của khách, không phải bằng chứng GPS chính xác hay địa chỉ đã được đối chiếu bản đồ. |
| Khoảng cách/thời gian đi lại | Adapter có dữ liệu mới trả số ước tính; mặc định không có dịch vụ ngoài. Fake chỉ local/test và ghi nhãn rõ. |

## Quy tắc chọn và thứ tự xếp hạng

`POST /co-so/goi-y` nhận lựa chọn trong JSON/body, không dùng query chứa địa chỉ/pin. Giỏ lấy từ phiên đã có, giá/giảm/phí không lấy từ trình duyệt. Hệ thống thử từng cơ sở hoạt động trong context riêng, rồi phục hồi cơ sở đang chọn. Kết quả gợi ý không đổi phiên, không chuyển giỏ và không giữ kho/khung/lượt mã.

Ứng viên phải phục vụ được **toàn bộ giỏ**: sản phẩm/biến thể/dòng cơ sở đang bán, đủ tổng số lượng theo SKU kể cả hai dòng trùng SKU, đủ phụ kiện được duyệt, đang nhận đơn, hỗ trợ hình thức nhận và phương thức thanh toán, có cấu hình ngân hàng nếu chọn chuyển khoản, đúng vùng giao, coupon hợp lệ và có khung đủ lead time/công suất. Lịch nghỉ, ngoại lệ ngày và slot đang đầy được kiểm tra bằng dịch vụ checkout hiện hành. Nếu khách chỉ định khung, cơ sở phải còn đúng khung đó. Không chia giỏ thành nhiều đơn/cơ sở tự động.

| Mục tiêu | Khóa ưu tiên | Khi thiếu dữ liệu |
| --- | --- | --- |
| `earliest` – nhận sớm | Giờ sớm nhất cho cả giỏ | Không có giờ phù hợp thì cơ sở nằm trong danh sách chưa phục vụ được. |
| `fee` – phí giao thấp | Phí giao sau kiểm tra coupon/ngưỡng miễn phí | Giá bánh khác nhau vẫn hiển thị trong tổng; tiêu chí này không có nghĩa tổng đơn rẻ nhất. |
| `distance` – gần | Khoảng cách ước tính có dữ liệu | Số chưa biết là `null`, xếp sau ứng viên có số; nếu tất cả thiếu, chuyển sang giờ sớm nhất và giải thích. |
| `travel` – đi lại ngắn | Phút đi lại ước tính có dữ liệu | Xử lý thiếu số tương tự khoảng cách; không dùng 0 thay giá trị chưa biết. |

Sau khóa ưu tiên, hòa điểm được giải bằng **giờ sớm nhất → phí giao → ID cơ sở tăng dần** để kết quả ổn định. Cơ sở không đủ điều kiện trả lý do riêng: tạm dừng, thiếu món/tồn, hình thức/thanh toán/vùng, mã giảm hoặc khung giờ. Không che việc thiếu dữ liệu bằng một ETA tự tạo. Kết quả gồm `candidates`, `unavailable`, `ranking.requested_goal`, `ranking.applied_goal` và lời giải thích fallback; không trả lại địa chỉ hoặc tọa độ riêng của khách.

Mọi số và giờ là trạng thái **tại lúc gợi ý**. Khi khách chọn cơ sở cần xác nhận theo luồng giỏ/báo giá hiện hành; trước tạo đơn, máy chủ kiểm tra lại và giữ tài nguyên trong transaction. Gợi ý không phải cam kết giữ chỗ, thời gian giao thực tế hay dữ liệu đã đối soát thanh toán.

## Adapter vị trí và lỗi dịch vụ

`LocationAdapter::estimate(origin, destination, context)` trả `available`, `distance_meters`, `travel_minutes`, `provider`, `estimated` và `reason`. `ManualLocationAdapter` là mặc định: không gọi mạng, số khoảng cách/thời gian để `null`. Chưa có provider/key thật vẫn tìm được cơ sở theo khu vực, cả giỏ, giờ và phí.

`FakeLocationAdapter` chỉ khởi tạo/chạy ở local/testing, dùng bộ số hoặc lỗi được cấu hình rõ theo branch ID. Nó hỗ trợ fixture thiếu dữ liệu, timeout và từ chối quyền; kết quả ghi provider `fake` và chỉ là ước tính thử. Không bật fake ở production, không biến số thử thành dữ liệu khoảng cách thật. Adapter lỗi hoặc không đủ pin/tọa độ đã duyệt khiến kết quả trở về tiêu chí có dữ liệu, không làm ngừng luồng chọn khu vực thủ công. Không có reverse-geocoding, dịch vụ tuyến đường hay cam kết giao đúng ETA được tự thêm ở giai đoạn này.

## Chuẩn hóa và lưu trữ riêng tư

`DeliveryAddress::normalize(input, regions)` chỉ giữ phiên bản dữ liệu, `country_code=VN`, vùng phục vụ đã cấu hình, `address_line` tối đa 250 ký tự, mã tỉnh/quận/phường tùy chọn, ghi chú giao tối đa 500 ký tự và pin đã xác nhận. Địa chỉ không đủ hoặc vùng ngoài cấu hình bị từ chối. Chuẩn hóa không dịch, bỏ dấu hoặc suy ra dữ liệu còn thiếu. Dự đoán theo khu vực có thể dùng `requireLine:false`; tạo bản chụp đơn cần địa chỉ đầy đủ.

`DeliveryAddress::seal/open` dùng mã hóa xác thực theo APP_KEY cho `orders.delivery_snapshot` TEXT. `fingerprint` dùng HMAC của JSON chuẩn hóa với khóa ứng dụng để báo giá ràng buộc nội dung mà không đưa địa chỉ thô vào chữ ký/log. Thứ tự khóa object không làm khác chữ ký. Snapshot cũ `null` vẫn `null`; không giả địa chỉ hoặc pin lịch sử. Các cột đơn cũ được giữ theo hợp đồng tương thích và quyền xem đơn; phần này không khẳng định toàn bộ dữ liệu địa chỉ cũ đã được mã hóa lại.

Gợi ý không lưu địa chỉ/pin vào DB, session, URL, localStorage, log hoặc audit. Endpoint luôn trả lỗi JSON trực tiếp để không flash địa chỉ vào old input. Chỉ thông tin cơ sở và kết quả kiểm tra được gửi lại. Không gửi địa chỉ tới provider khi khách chưa xác nhận pin hoặc cơ sở chưa có tọa độ được duyệt.

Sổ địa chỉ là lựa chọn riêng: khách đăng nhập và tài khoản đang hoạt động phải xác nhận `save_consent`. `customer_delivery_addresses` chỉ có chủ sở hữu, payload mã hóa gồm cả nhãn/địa chỉ/pin, thời điểm đồng ý và timestamps; không đặt nhãn/địa chỉ/pin thô thành cột tìm kiếm. Không tự chép địa chỉ từ đơn hoặc lần gợi ý vào sổ. Có tối đa 20 địa chỉ; đọc/sửa/xóa chỉ theo user ID mới nhất, thao tác ghi khóa user và dòng sở hữu. Gỡ địa chỉ không đổi bản chụp đơn đã mua.

`GET /tai-khoan/dia-chi` phục vụ trang AddressBook hoặc JSON riêng của chính khách; POST tạo, PATCH `/{address}` sửa, DELETE `/{address}` xóa. Lỗi Inertia chỉ gửi lỗi trường, không flash lại dữ liệu nhập; payload mã hóa hỏng hiển thị cần kiểm tra thay vì lộ ciphertext. Không đưa sổ địa chỉ vào props dùng chung hoặc HTML/Node SSR của trang công khai. Giữ phản hồi riêng tư/no-store; SMTP, courier và export chỉ được lấy dữ liệu theo quyền và mốc của luồng tương ứng.

## Kiểm thử và đầu vào còn chờ

Các ca cần ghi theo commit/môi trường/người thử: vùng chung hai key khác nhau; cả giỏ/trùng SKU; hết kho; khung cuối/ngày nghỉ/ngoại lệ; coupon cơ sở và phương thức chưa bật; xếp hạng hòa điểm; không có pin/provider; fake timeout/từ chối; không rò địa chỉ trong phản hồi/session; mã hóa/chữ ký; consent/đọc sửa xóa chéo user và tài khoản bị khóa. Kết quả focused và tích hợp cuối được ghi khi chạy, không suy từ việc có tệp kiểm thử.

Đã chạy focused trên working tree ngày 27/11/2026, người thử Codex: **16 bài SQLite, 165 assertions** cho chuẩn hóa/mã hóa/chữ ký, recommendation và HTTP locations/sổ địa chỉ, dùng bộ nhớ và storage/cache riêng. Bao gồm request HTML lỗi vẫn trả JSON không flash địa chỉ, consent và quyền sở hữu mới nhất, sổ mã hóa hỏng/max20, số ước tính chưa biết và provider fake lỗi. Đã chạy riêng **3 bài MySQL race, 37 assertions, không skip** trên schema `tiembanh_locations_phpunit_test`: tranh slot+SKU cuối giữa checkout và chuyển đơn, execute lặp và propose lặp; nguồn còn giữ nếu chuyển thua. Các số focused này chưa thay full suite, ca trình duyệt hoặc xác nhận provider thật của bản nguồn cuối.

Trước dùng thật, chủ tiệm cần chốt vùng phục vụ chung, phí/ngưỡng, địa chỉ/liên hệ/giờ, tọa độ được phép dùng và người duyệt; người vận hành cần xác minh key/provider nếu chọn tích hợp ngoài, quyền riêng tư và giới hạn dữ liệu/gửi log. Chưa có xác nhận dịch vụ địa lý thật, dữ liệu hành chính chính thức, HTTPS bên ngoài, điện thoại thực hoặc SLA giao bánh. Giữ các cơ sở tạm dừng nhận đơn thật đến khi được phê duyệt riêng.
