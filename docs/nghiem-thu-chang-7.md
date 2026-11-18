# Nghiệm thu chặng 7

## Kết quả trên staging kín của workspace

Ngày 18/11/2026, trên mã nguồn chặng 7 dựa trên `917a1ec`. Staging dùng `https://staging.localhost:18860`, chỉ lắng nghe loopback, với MySQL `tiembanh_stage7_test`, khóa ứng dụng, chứng chỉ thử và tài khoản riêng. Đơn, địa chỉ, ngân hàng và hộp thư đều là dữ liệu thử. Công tắc nhận đơn được bật trong ca thử kín và tạm ngừng khi kết thúc.

| Hạng mục | Kết quả đã xác minh |
| --- | --- |
| Truy cập và phân quyền | Preflight staging đạt. Truy cập thiếu HTTP Basic bị chặn, kể cả `/up`. Xác thực TOTP cho quản trị và nhân viên thử. Nhân viên thường xử lý đơn, không bật nhận đơn hoặc xác nhận chuyển khoản. |
| Ca bán | Hoàn tất một đơn COD nhận tại tiệm và một đơn chuyển khoản giả lập giao tận nơi. Đơn giao gồm 360.000 ₫ tiền bánh và 25.000 ₫ tiền giao. Đổi giờ rồi hủy một đơn khác, kiểm tra tồn trở về số trước khi đặt. Đối chiếu cả ba trạng thái qua trang tra cứu sau khi tạm ngừng nhận đơn. |
| Email | Hộp SMTP thử qua TLS nhận bảy thư, gồm thư nhận đơn, thư trạng thái và khôi phục mật khẩu. Liên kết đặt lại mật khẩu và tra cứu dùng đúng tên miền HTTPS của staging. Đơn và yêu cầu khôi phục gửi đến địa chỉ ngoài danh sách đều bị chặn trước khi đến SMTP. |
| Trình duyệt | Chrome ở 390 px và 1366 px hiển thị trang chủ, chi tiết bánh, ba trang tra cứu và màn hình đặt lại mật khẩu. Các trang đã thử vừa chiều rộng màn hình. |
| Cảnh báo và quay lui | Bộ nhận HTTPS thử, chạy trong tiến trình riêng trên cùng máy, ghi nhận tín hiệu định kỳ, khoảng ngừng có chủ ý và lúc tín hiệu trở lại. Bản ứng dụng thử lỗi trả 503 và tạo cảnh báo. Quay lui trả HTTP 200, giữ nguyên ba đơn và tạo thông báo phục hồi. |
| Sao lưu | Chạy qua lịch với tài khoản MySQL chỉ dùng để sao lưu. Hai thư mục thử trên cùng máy khớp SHA-256. Đã phục hồi từ bản thứ hai vào schema `tiembanh_stage7_restore_test` và thư mục ảnh trống, đối chiếu ba đơn và mã băm ảnh. |
| Kiểm thử mã | Pint, ESLint và build Vite đạt. MySQL đạt 35 bài với 914 assertions. SQLite đạt 30 bài với 879 assertions và bỏ qua 5 bài kiểm tra đồng thời dành cho MySQL. |

## Ca nghiệm thu với dịch vụ và nhân sự của tiệm

Các mục dưới đây đang chờ đầu vào của tiệm. Ghi người kiểm tra và thời điểm, kết quả đạt hoặc lỗi, cùng bằng chứng đã bỏ thông tin riêng. Dùng khách và đơn thử, hộp thư đã được duyệt và [hướng dẫn staging kín](van-hanh.md#nghiệm-thu-kín-với-tên-miền-riêng).

| Mục chờ | Tiệm cần cung cấp hoặc xác nhận | Điều kiện nghiệm thu |
| --- | --- | --- |
| Web và truy cập | Máy chủ staging, tên miền và chứng chỉ, mạng riêng hoặc VPN, đầu mối cấp quyền. | Người có quyền truy cập được trang HTTPS. Người ngoài vùng truy cập không mở được web và tệp tĩnh. Laravel vẫn yêu cầu quyền truy cập staging cho `/up` và trang nghiệp vụ. |
| Thư | Nhà cung cấp, địa chỉ gửi đã xác minh, thông tin kết nối SMTP và danh sách hộp thư thử. | Ba loại thư đến hộp đã duyệt qua SMTP thực tế. Liên kết tra cứu, đặt lại mật khẩu mở đúng tên miền. Hộp ngoài danh sách không nhận thư. |
| Dữ liệu và ca bán | Tên, logo, địa chỉ, liên hệ, menu, giá, tồn, dị ứng, thời gian chuẩn bị, giờ nhận, ngày nghỉ, phí và khu vực giao, chính sách đặt bánh và dữ liệu. Nếu dùng chuyển khoản, xác nhận tên ngân hàng, số tài khoản, chủ tài khoản và người đối soát. | Nhân viên và người đối soát của tiệm đăng nhập bằng TOTP, xử lý một đơn nhận tại tiệm, một đơn giao và một đơn đổi giờ rồi hủy. Dùng COD cho đơn giao nếu tiệm chưa dùng chuyển khoản. Đối chiếu tổng tiền, nội dung chuyển khoản nếu bật, lịch, trạng thái và tồn. Tạm ngừng nhận đơn cuối ca. |
| Cảnh báo và trình lập lịch | Kênh cảnh báo, người trực, điểm nhận tín hiệu ở ngoài máy chủ và khoảng thời gian tối đa không nhận tín hiệu. | Người trực xác nhận nhận cảnh báo và thông báo phục hồi. Bộ giám sát độc lập phát hiện web lỗi qua `/up` và phát hiện scheduler ngừng gửi tín hiệu trong khoảng đã chốt. |
| Phục hồi và phát hành | Kho sao lưu ngoài máy chủ, quyền đọc và ghi, thời hạn giữ bản, mức mất dữ liệu và thời gian phục hồi tiệm chấp nhận, người có quyền nạp lại web và worker. | Bản theo lịch xuất hiện ở kho đã duyệt. Khôi phục vào schema và thư mục ảnh riêng, so với đơn và ảnh đã biết, rồi đo thời gian phục hồi. Quay về bản trước trên staging, nạp lại tiến trình và đối chiếu đơn đã nhận. |

Dùng kênh cấp bí mật của tiệm cho mật khẩu, khóa ứng dụng, khóa TOTP, thông tin SMTP và token. Biên bản chỉ lưu kết quả, phiên bản và người xác nhận. Sau nghiệm thu, giữ staging tạm ngừng nhận đơn và để chủ tiệm quyết định thời điểm mở bán riêng.
