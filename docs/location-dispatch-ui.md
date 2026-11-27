# Chọn cơ sở, địa chỉ, chuyển đơn và giao nhận

Tài liệu phần giao diện kiểm thử local ngày 27/11/2026. Không tự mở bán hoặc cấu hình dịch vụ bản đồ/vận chuyển thật. Kết quả tổng, commit, viewport và bằng chứng được chốt trong biên bản của đúng bản mã.

## Khách chọn cơ sở

Thanh cơ sở ở trang chủ, thực đơn, sản phẩm và giỏ dẫn tới `/co-so`. Khách chọn nhận tại tiệm/giao, thanh toán, khu vực và ưu tiên. Địa chỉ nhập thủ công luôn có; không cần tài khoản hoặc quyền lấy vị trí để tìm và đặt bánh.

Máy chủ kiểm tra toàn bộ giỏ, số lượng, phiên bản và tùy chọn. Kết quả có giá bánh, phí giao, tổng dự kiến, giờ sớm nhất và lý do cơ sở chưa đáp ứng. Thứ tự ưu tiên giờ/phí/khoảng cách/di chuyển do máy chủ tính. Thiếu vị trí thì thông báo xếp theo giờ sớm nhất; không suy đoán ETA, khoảng cách hoặc cam kết giao. Adapter giả có nhãn kiểm thử local.

`/co-so/{slug}` công bố địa chỉ, giờ, cách nhận, thanh toán, liên hệ và liên kết bản đồ được máy chủ cho phép. Giờ sớm nhất chỉ hiển thị khi đã kiểm tra phù hợp, không suy ra từ giờ mở cửa.

Trước khi đổi, khách xem giá/phí/giờ cũ–mới và xác nhận giữ toàn bộ giỏ hoặc xóa giỏ để chọn lại. Giỏ/giá/tài nguyên đổi hoặc báo giá hết hạn cần kiểm tra lại; không xóa giỏ âm thầm. Endpoint chọn kiểu cũ vẫn giữ xác nhận xóa giỏ để tương thích.

## Địa chỉ và quyền vị trí

- Địa chỉ, khu vực, chỉ dẫn và ghim dùng POST; giao diện không đưa vào URL, localStorage hoặc telemetry.
- Nút **Dùng vị trí của tôi** mới xin quyền trình duyệt. Từ chối, timeout hoặc không hỗ trợ có thông báo và vẫn cho nhập thủ công.
- Ghim vừa lấy/nhập cần khách kiểm tra và xác nhận. Không gửi ghim chưa xác nhận và không dùng ghim tự thay địa chỉ.
- Mã vùng giao do tiệm cấu hình, không tự coi là mã hành chính chính thức. Cấu hình cũ dùng mã khu vực hiện có để tương thích.
- Checkout giữ khách vãng lai. Máy chủ ràng buộc địa chỉ chuẩn hóa/chỉ dẫn/ghim trong báo giá và bản chụp đơn.
- Sau lựa chọn rõ ràng, bản nháp phục vụ nhận/giao có hạn, được mã hóa và gắn cơ sở. Không tự lưu địa chỉ vào tài khoản.
- Khách đăng nhập có `/tai-khoan/dia-chi`; thêm/sửa cần đồng ý lưu, xóa có xác nhận. Chỉ chủ tài khoản được thao tác. Xóa sổ địa chỉ không xóa bản chụp đơn đã mua.
- Sổ địa chỉ, đơn, phê duyệt chuyển và điều phối không gửi Node SSR. Hai trang cơ sở chỉ gửi dữ liệu công khai ban đầu; bỏ bản nháp, giỏ, phân công và địa chỉ/ghim người nhận.

## Chuyển cơ sở của đơn đã đặt

Chi tiết đơn quản trị có phần **Chuyển cơ sở xử lý đơn**. Chính sách mặc định tắt. Cần duyệt phiên bản `v1-unpaid-before-production`, có tham chiếu và cấu hình ở các cơ sở liên quan.

1. Quản lý có quyền nguồn/đích chọn cơ sở, giờ và lý do; xem báo giá mới.
2. Xác nhận gửi đề xuất cho khách; chưa chuyển đơn/tồn/công suất.
3. Khách đăng nhập mở từ đơn của mình; khách vãng lai dùng liên kết email riêng có hạn. Khách xem nơi nhận, giờ, tiền/chênh lệch rồi đồng ý hoặc từ chối; đồng ý cần xác nhận đã đọc báo giá.
4. Quản lý kiểm tra lại và thực hiện. Máy chủ xác minh quyền, trạng thái, giá và tài nguyên; chỉ chuyển cùng một transaction khi đạt. Thất bại giữ nguồn, thay giá cần đề xuất/phê duyệt mới phù hợp.

Chỉ chuyển đơn chưa thu tiền, chưa chuẩn bị hoặc xuất giao. COD theo điều kiện máy chủ. Chuyển khoản chưa thu cần cùng thông tin nhận tiền, quyền đối soát hai cơ sở và xác nhận/tham chiếu kiểm tra chưa thu. Không tự thu, hoàn hay bù tiền bằng thao tác chuyển. Đơn đã thu/đã làm dùng hủy/hoàn/kiểm kê hiện có.

Địa chỉ nhận và liên hệ xử lý trên trang đơn lấy cơ sở ghi trong đơn, không lấy cơ sở khách đang duyệt menu. Đơn thiếu địa chỉ lịch sử được ghi chưa xác minh; không giả địa chỉ hiện tại thành địa chỉ lúc mua.

## Điều phối nội bộ

`/quan-tri/giao-nhan` cho nhân viên/quản lý tạo công việc đơn cũ đủ điều kiện, chọn đơn giao và phân chuyến cho nhân sự được phân công tại cơ sở. Bộ lọc trạng thái/hình thức và thẻ công việc hỗ trợ điện thoại.

Chỉ hiện thao tác máy chủ cho phép: sẵn sàng, xuất giao, đã giao, thất bại, tạo lại lượt, hủy việc hoặc khách nhận tại tiệm. Thao tác giữ version/request key. Bàn giao cần tham chiếu và xác nhận thực tế. Thất bại/hủy việc không tự hoàn tất đơn, hoàn tiền hoặc nhập bánh lại tồn bán.

Vai trò **Nhân sự giao hàng** không được cấp đối soát. Chỉ thấy việc được giao và dữ liệu cần bàn giao: tên/liên hệ, địa chỉ/chỉ dẫn, giờ, khoản COD cần thu. Không có email, ngân hàng, coupon hoặc ghi chú bánh riêng. Khi việc kết thúc, địa chỉ và điện thoại được ẩn khỏi màn hình người giao.

Đây là phân chuyến nội bộ, không tự thuê shipper hoặc đặt vận chuyển bên ngoài. Trạng thái giao nhận, thanh toán và hoàn tất đơn được xử lý riêng.

## Kiểm tra lại và phần đang chờ

```powershell
npm run lint
npm run build
npm run test:ssr
npm run test:orders
php vendor/bin/pint --test
php vendor/bin/phpunit tests/Feature/LocationUiProjectionTest.php tests/Feature/BranchSelectionTest.php
```

Chạy PHPUnit với `APP_ENV=testing` và SQLite memory hoặc schema MySQL `_test` riêng. Local không thay nghiệm thu dịch vụ thật. Ca giao diện cần ghi 390/1440 px, chọn cơ sở/preview/giữ giỏ, từ chối vị trí, lỗi kết nối, sổ địa chỉ opt-in, phê duyệt/chuyển đơn, nhận tại tiệm và nhân sự giao bị thu hồi quyền; đối chiếu đơn, tồn, công suất, audit và thư.

Chờ dữ liệu thật: cơ sở/địa chỉ/ghim có quyền công bố, vùng/phí giao thống nhất, chính sách/người duyệt chuyển, nhân sự/ca giao, quy trình COD/đối soát, bằng chứng bàn giao, SMTP và dịch vụ vị trí/vận chuyển nếu muốn tích hợp. Không đổi dữ liệu mẫu thành phê duyệt thật để vượt cổng mở bán.
