# Nghiệm thu chặng 8

Staging dùng `https://staging.localhost:18870` với xác thực riêng, chứng chỉ thử và schema MySQL `tiembanh_stage8_test`. Ca nghiệm thu dùng dữ liệu thử. Chỉ bật nhận đơn trong ca thử kín và tạm ngừng khi kết thúc.

## Luồng đặt bánh và xử lý đơn

- Khách tìm bánh trong thực đơn, xem ảnh, giá, phiên bản và cảnh báo dị ứng trước khi thêm vào giỏ. Khi đặt, khách chọn ngày rồi chọn một khung giờ còn chỗ. Thanh xác nhận trên điện thoại hiển thị tổng tiền đã báo.
- Máy chủ đối chiếu báo giá đã hiển thị với giá, phí giao và giảm giá trong giao dịch tạo đơn. Nếu thay đổi, khách xem báo giá mới và bấm xác nhận lại. Nếu khung giờ vừa hết chỗ, khách có thể chọn giờ khác. Lỗi kết nối khi báo giá có nút thử lại. Các tình huống này giữ thông tin liên hệ đã nhập trong lúc khách ở trang đặt bánh.
- Trang tra cứu hiển thị mã đơn và việc tiếp theo theo trạng thái. Với chuyển khoản thủ công, khách xem số tiền, hạn, số tài khoản và nội dung chuyển khoản, đồng thời có thể sao chép số tài khoản và nội dung trên trình duyệt hỗ trợ.
- Nhân viên dùng **Đơn cần xử lý** và **Đối soát**. Hai trang dùng thẻ trên điện thoại và bảng trên desktop. Chi tiết đơn hiển thị mã và số tiền trước khi ghi nhận thanh toán hoặc hoàn tiền. Kiểm tra phân quyền vẫn thực hiện trên máy chủ.

## Kết quả staging local

| Hạng mục | Đã xác minh |
| --- | --- |
| Checkout trên Chrome | Ở 390 px, đi từ thực đơn đến giỏ và checkout. Sau lỗi kết nối giả lập, nút thử lại cập nhật được báo giá. Khi một khách thử khác lấy khung giờ cuối, khách chọn được giờ mới và giữ thông tin liên hệ. Tăng giá từ 360.000 ₫ lên 380.000 ₫ trong lúc checkout đang mở. Chỉ tạo đơn sau khi khách xác nhận báo giá mới. |
| Quản trị trên Chrome | **Đơn cần xử lý** hiển thị dạng thẻ ở 390 px và dạng bảng ở 1366 px. Nhân viên xử lý một đơn COD đến hoàn tất. Ở 390 px, kiểm tra nút sao chép nội dung chuyển khoản và người có quyền đối soát ghi nhận thanh toán đơn thử. Sau khi tạm ngừng nhận đơn, khách vẫn tra cứu được đơn. |
| Ca thử qua HTTP | Hoàn tất COD nhận tại tiệm và chuyển khoản giả lập giao tận nơi. Đổi giờ, hủy và kiểm tra hoàn tồn. Hai vai trò nội bộ dùng TOTP. Hộp SMTP local nhận bảy thư qua TLS trong ca này, gồm thư xác nhận, trạng thái và khôi phục mật khẩu. |
| Vận hành sau ca | Preflight đạt. Đã xác minh sáu đơn thử vẫn tra cứu được và staging đã tạm ngừng nhận đơn. Ảnh đã tải lên vẫn yêu cầu xác thực khi truy cập trên staging. Sao lưu theo lịch chạy thành công, hai bản lưu trên cùng máy khớp SHA-256 và bộ nhận thử nhận tín hiệu định kỳ qua HTTPS. |
| Kiểm thử mã | Pint, ESLint và build Vite đạt. MySQL đạt 37 bài với 974 assertions. SQLite đạt 32 bài với 939 assertions, với 5 bài đồng thời chạy riêng trên MySQL. |

Ca thử phát hiện và đã sửa hai vấn đề điều hướng khi thêm vào giỏ hoặc báo giá bị từ chối, cùng việc báo giá dùng chung giới hạn tần suất với khôi phục mật khẩu. Bộ kiểm thử giữ lại các ca hồi quy cho chuyển hướng, báo giá và giới hạn tần suất.

## Phần cần cấu hình thật

| Mục | Đầu vào của tiệm | Kết quả cần xác nhận |
| --- | --- | --- |
| Hạ tầng staging | Quyền truy cập staging kín, tên miền HTTPS, chứng chỉ, MySQL và nơi lưu ảnh riêng. | Truy cập bằng thiết bị được duyệt và kiểm tra bên ngoài vùng cho phép không mở được trang hoặc tệp tĩnh. |
| Menu và nhận diện | Tên, logo, ảnh bánh được phép dùng, tên và phiên bản, giá, tồn, thành phần, dị ứng và thời gian làm từng bánh. | Chủ tiệm duyệt ảnh, giá và thông tin dị ứng trên điện thoại. Khách thử tự tìm được bánh và phiên bản đúng. |
| Lịch, giao hàng và chính sách | Giờ nhận, ngày nghỉ, giới hạn từng khung giờ, khu vực, phí, ngưỡng miễn phí, liên hệ và nội dung chính sách. | Thử một đơn nhận tại tiệm, một đơn giao, một đơn đổi giờ rồi hủy. Đối chiếu giờ hẹn, phí giao, tổng tiền, trạng thái và tồn. |
| Tài khoản và thanh toán | Nhân viên tham gia, người có quyền đối soát và thông tin ngân hàng đã được tiệm xác minh nếu sử dụng chuyển khoản. | Nhân viên tự tìm và xử lý đơn. Người đối soát xác nhận đúng số tiền và nội dung trên đơn thử. |
| Thư, cảnh báo và phục hồi | SMTP, địa chỉ gửi, hộp thử đã duyệt, kênh và người nhận cảnh báo, bộ giám sát độc lập và kho sao lưu ngoài máy chủ. | Hộp thử nhận thư xác nhận, thư trạng thái và thư khôi phục. Người trực nhận cảnh báo và thông báo phục hồi. Khôi phục dữ liệu và ảnh từ bản sao đã kiểm tra vào nơi riêng. |

Ghi người xác nhận, ngày thử, phiên bản, kết quả và bằng chứng của từng mục. Cấp bí mật qua kênh riêng của tiệm. Các mục dùng dịch vụ thật ghi chờ nghiệm thu đến khi thử trên dịch vụ đó. Xem [cấu hình staging kín](van-hanh.md#nghiệm-thu-kín-với-tên-miền-riêng) để chạy ca nghiệm thu.
