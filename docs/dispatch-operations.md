# Điều phối giao và nhận bánh nội bộ

Tính năng nằm tại `/quan-tri/giao-nhan`, dùng một cơ sở đang quản trị. Chưa kết nối hãng giao, định vị hay thu tiền tự động. Mặc định dùng adapter `manual`; `fake` chỉ chạy trong local/test. Không có ETA thì giao diện ghi “Chưa có ước tính”. Thời gian hẹn trên đơn là lịch đã đặt, không phải dự báo thời điểm tài xế đến.

## Phân quyền

| Hành động | Quản trị chuỗi | Quản lý / nhân viên cơ sở | Tài xế đã phân công | Bếp / đối soát |
| --- | --- | --- | --- | --- |
| Tiếp nhận công việc đơn cũ, tạo chuyến, sẵn sàng, hủy công việc, giao lại | Có | Cơ sở được giao quyền | Không | Không |
| Xuất giao, xác nhận giao hoặc báo giao thất bại | Có | Cơ sở được giao quyền | Chỉ công việc của mình | Không |
| Xác nhận khách nhận tại tiệm | Có | Cơ sở được giao quyền | Không | Không |
| Thu COD, đối soát, ghi hoàn tiền | Theo quyền đơn hàng hiện có | Theo quyền đơn hàng hiện có | Không | Đối soát theo quyền riêng |
| Xem email, ngân hàng, mã giảm, nội dung ghi trên bánh | Theo quyền đơn hàng hiện có | Theo quyền đơn hàng hiện có | Không | Theo quyền riêng |

Tài xế vẫn là tài khoản nội bộ `users.role=staff`, có phân công cơ sở `role=courier` và TOTP. Cờ `can_reconcile` không cấp quyền tài chính cho bếp hoặc tài xế. Thay phân công làm đổi chứng thực phiên; quyền được đọc lại trong transaction. Chọn cơ sở khác không cho phép xem công việc ngoài phân công.

Tài xế chỉ nhận mã đơn, giờ hẹn, tên người nhận, số điện thoại, địa chỉ / ghi chú giao và tiền COD cần thu của công việc được phân. Khi công việc đã giao, thất bại hoặc hủy, số điện thoại / địa chỉ được loại khỏi phản hồi; tài xế không được ghi nhận thanh toán. Nhân viên phải đối chiếu riêng tiền tài xế bàn giao.

## Ca giao hàng

1. Hoàn thành xác nhận và chuẩn bị đơn theo quy trình hiện có. Đơn mới trong chế độ nhiều cơ sở được tạo công việc cùng transaction đặt đơn. Với đơn cũ, nhân viên bấm tiếp nhận; hệ thống ghi rõ trạng thái được tiếp nhận và không tạo bằng chứng giao hoặc suy đoán thời gian lịch sử.
2. Ghép một hoặc nhiều công việc giao chưa phân chuyến, cùng cơ sở, vào một chuyến của tài xế đang hoạt động. Chuyến là bản ghi phân công bất biến; danh sách đơn và tài xế được giữ lại kể cả khi công việc được giao lại sau đó. Trạng thái chuyến trên màn hình được suy từ các công việc hiện tại; xử lý từng công việc trong chuyến.
3. Nhân viên đánh dấu sẵn sàng bàn giao sau khi bánh được chuẩn bị. Nhân viên hoặc tài xế được phân bấm xuất giao. Máy chủ kiểm tra địa chỉ, tài xế còn quyền, phiên bản công việc và điều kiện trả trước. Đơn chuyển khoản phải được thu đủ hoặc có ngoại lệ hợp lệ trước khi xuất giao. Mã giảm, tồn và giá không được tính lại ở bước này.
4. Khi giao xong, nhập mã / tham chiếu bàn giao và đánh dấu đã đối chiếu người nhận. Không dùng số điện thoại, giấy tờ hay bí mật làm tham chiếu. Lịch sử lưu người xác nhận, mốc máy chủ và bằng chứng mã hóa. Đây là xác nhận giao bánh; đơn COD chưa tự thành đã thanh toán hoặc hoàn tất.
5. Nhân viên đối chiếu tiền bên ngoài, ghi nhận thanh toán bằng quyền hiện có rồi hoàn tất đơn. Đơn chưa có xác nhận giao / nhận hoặc chưa đủ tiền không được đi tắt sang hoàn tất.

## Giao thất bại, hủy công việc và giao lại

| Trạng thái công việc | Bước được phép |
| --- | --- |
| `pending` | Phân chuyến, sẵn sàng, hủy có lý do |
| `assigned` | Sẵn sàng, hủy có lý do |
| `ready` | Xuất giao khi có tài xế / chuyến; nhận tại tiệm nếu là pickup; hủy có lý do |
| `in_transit` | Đã giao có bằng chứng, giao thất bại có lý do, nhân viên hủy công việc có lý do |
| `failed`, `cancelled` | Nhân viên tạo lại lượt xử lý khi đơn chưa hủy / hoàn tất |
| `delivered`, `collected` | Giữ lịch sử; xử lý tiền và đơn theo quy trình riêng |

Hủy công việc không hủy đơn, không giải phóng tồn, không đưa bánh đã làm về bán và không trả lượt mã. Khi dừng một lượt đang giao, đơn chuyển sang giao chưa thành công; bộ phận hỗ trợ phải thống nhất cách xử lý với khách. Nhân viên có thể tạo lượt lại, phân chuyến mới và bàn giao lại. Mỗi lượt đã kết thúc lưu một bản ghi bất biến; không xóa hoặc sửa lượt cũ. Nếu cần hủy đơn, sử dụng quy trình hủy đơn và kiểm kê đã có, giữ hai quyết định độc lập.

Nhận tại tiệm có luồng `pending → ready → collected`. Bấm xác nhận khách đã nhận cần tham chiếu và xác nhận đối chiếu. Tiền COD vẫn do nhân viên ghi nhận riêng. Tài xế không được thực hiện bước nhận tại tiệm.

## Đồng thời, chuyển cơ sở và thông báo

Mỗi thao tác gửi UUID `request_key` và phiên bản `expected_version`. Cùng UUID và nội dung chỉ ghi một lần; đổi nội dung với UUID cũ bị chặn. Phiên bản đã cũ phải tải lại, không tự đè kết quả đồng nghiệp. Trình tự khóa là người / phân công, cấu hình cơ sở đọc chung, yêu cầu, đơn, công việc, rồi lịch sử; ghép nhiều đơn khóa theo ID tăng dần. Chuyến bất biến không tạo mutex toàn chuỗi.

Đơn chỉ được chuyển cơ sở khi công việc chưa phân tài xế / chuyến và chưa có lượt giao. Chuyển công việc diễn ra cùng transaction chuyển đơn; không có thao tác chuyển kho riêng ở màn hình này. Giao lại không giữ hoặc trừ tồn lần nữa.

Ngoại lệ cho phép làm / giao khi chưa thu chuyển khoản chỉ thuộc cơ sở đã cấp. Khi chuyển đơn, hệ thống xóa ngoại lệ cũ và giữ quy tắc trả trước nghiêm hơn giữa bản chụp nguồn và cấu hình đích. Quản lý đích chỉ cấp ngoại lệ mới nếu chính sách cơ sở đó cho phép, có quyền và ghi lý do; chuyển cơ sở không tự nới điều kiện tiền.

Thông báo phân công, xuất giao, giao xong, thất bại, hủy công việc, sẵn sàng nhận và nhận xong dùng hàng đợi hiện có, chống gửi lặp theo sự kiện. Lỗi thư không thay kết quả giao nhận; nhân viên kiểm tra hàng đợi / thư lỗi và thử lại theo hướng dẫn vận hành. Bằng chứng, địa chỉ và ghi chú không đưa vào Git, nhật ký lỗi tổng hoặc thông báo công khai.

## Nghiệm thu

Chạy trên DB test riêng và ghi commit, người thử, cấu hình, kết quả và bằng chứng đã loại dữ liệu riêng. Không coi ca local là xác nhận nhà vận chuyển hoặc cấu hình thật của tiệm.

```powershell
php artisan migrate
php vendor/bin/phpunit tests/Feature/DispatchTest.php --do-not-cache-result
php vendor/bin/pint --test
```

Ca tự động bao phủ phân chuyến cùng cơ sở, chống chéo cơ sở / tài xế khác, phiên bản cũ, gửi hai lần, trả trước chưa thu, chứng cứ bàn giao, hủy công việc và giao lại không hoàn tồn, nhận tại tiệm, thu hồi quyền và tối thiểu hóa dữ liệu. Kết quả thực tế, lint / build và ca trình duyệt do biên bản nghiệm thu của commit đang kiểm thử ghi nhận; không tự xem bảng mô tả này là kết quả đã đạt.

Trước ca thật cần chủ tiệm xác nhận người điều phối / đối soát, quy ước tham chiếu bàn giao, người hỗ trợ khi khách chưa nhận, cách tài xế bàn giao tiền và quy trình xử lý bánh giao thất bại. Chưa mở nhận đơn thật khi các mục này chưa được duyệt.
