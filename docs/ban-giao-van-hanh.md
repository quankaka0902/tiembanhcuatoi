# Bàn giao vận hành Tiệm Bánh Nhà Mình

Hồ sơ này dùng để chốt bản phát hành, nghiệm thu cùng nhân viên và quyết định khi nào tiệm bắt đầu nhận đơn. Phạm vi đang thực hiện là staging local với dữ liệu thử. Khi kết thúc ca thử, staging giữ trạng thái tạm ngừng nhận đơn.

Hồ sơ mới nhất là [biên bản nghiệm thu một cơ sở ngày 23/11/2026](nghiem-thu-mo-ban-mot-co-so.md). Biên bản gắn kết quả với bản mã local `5de11c0`, tách bằng chứng local khỏi dịch vụ thật và liệt kê từng đầu vào, đầu mối còn chờ. Các kết quả chặng 9–11 bên dưới được giữ làm lịch sử.

Sau biên bản đó, luồng đơn được cập nhật theo [vòng đời đơn, đổi lịch và thông báo](vong-doi-don.md). Dùng bảng quyền và kết quả mới này khi nghiệm thu hủy sau làm, tồn, trả trước, bằng chứng hoàn và yêu cầu khách. Giữ phần thử cũ để đối chiếu lịch sử phiên bản.

## Chặng 9 · Chốt bản dùng để nghiệm thu

1. Ghi mã commit sẽ dùng cho cả kiểm thử và bàn giao. Xem kết quả [Bakery CI](https://github.com/quankaka0902/tiembanhcuatoi/actions/workflows/ci.yml) của chính commit đó. CI chạy Pint, ESLint, build Vite, PHPUnit trên SQLite và MySQL. Các bài hai tiến trình phải chạy trên MySQL.
2. Khi tiệm cấp máy chủ, dựng staging theo [cấu hình tên miền riêng](van-hanh.md#nghiệm-thu-kín-với-tên-miền-riêng). Dùng tên miền HTTPS mở được trên thiết bị của nhân viên bằng chứng chỉ mà các thiết bị đó tin cậy. Kiểm tra mạng riêng hoặc VPN bảo vệ cả tệp tĩnh, MySQL và kho ảnh riêng, worker và lịch chạy đang hoạt động. Tài khoản nội bộ của staging có TOTP riêng.
3. Chạy `php artisan bakery:preflight --staging`. Kiểm tra từ thiết bị được phép và từ ngoài vùng truy cập. Mở cửa hàng và trang quản trị trên điện thoại và desktop trước buổi thử. Bản xem thử trên máy trong workspace dùng [HTTP loopback](http://127.0.0.1:18842). Staging HTTPS local dùng chứng chỉ thử riêng.

## Chặng 10 · Ca nghiệm thu cùng tiệm

Nhập thông tin qua **Cấu hình**, **Sản phẩm** và **Nhân sự**. Kiểm tra từng dòng trong bảng trên chính staging dùng cho ca thử.

| Đầu vào | Nội dung cần chốt | Người cung cấp hoặc xác nhận |
| --- | --- | --- |
| Menu | Danh mục, tên bánh, phiên bản, giá VND, tồn, ảnh được phép dùng, dị ứng, thời gian chuẩn bị và tùy chọn lời chúc | Chủ tiệm hoặc người phụ trách menu |
| Bán và giao | Liên hệ, địa chỉ, lịch nhận, ngày nghỉ, sức chứa từng khung, khu vực giao, phí, ngưỡng miễn phí và chính sách đổi giờ, hủy, hoàn | Chủ tiệm và nhân viên chạy ca |
| Thanh toán | Phương thức sẽ bật. Nếu dùng chuyển khoản, xác nhận ngân hàng, tài khoản nhận, hạn chuyển khoản và người có quyền đối soát | Chủ tiệm và người đối soát |
| Hạ tầng | Máy chủ, HTTPS, quyền truy cập, địa chỉ gửi và hộp thư SMTP thử, người nhận cảnh báo, bộ giám sát ngoài máy, kho sao lưu ngoài máy | Người phụ trách vận hành |

Trong buổi thử, dùng thông tin khách và hộp thư được duyệt. Chỉ đối chiếu chuyển khoản bằng đơn thử và xác nhận dữ liệu ngân hàng với người phụ trách. Kiểm tra theo thứ tự sau.

1. Khách tự chọn bánh trên điện thoại và đặt COD nhận tại tiệm. Nhân viên tìm đơn, xác nhận, chuyển sang chuẩn bị, sẵn sàng nhận, ghi nhận đã thu tiền rồi hoàn tất. Khách mở lại liên kết để xem giờ và trạng thái.
2. Đặt một đơn giao. Đối chiếu khu vực, phí, tổng tiền và giờ hẹn. Nếu bật chuyển khoản, người có quyền đối chiếu tài khoản, số tiền và mã đơn trước khi ghi nhận đã nhận tiền. Kiểm tra tài khoản nhân viên thường bị chặn ở bước này.
3. Đổi giờ một đơn khác rồi hủy. Đối chiếu lịch làm bánh và tồn trước, sau. Thử thêm tình huống hai khách chọn khung cuối hoặc giá đổi lúc đang đặt. Khách còn giữ thông tin và có thể chọn lại hoặc xác nhận báo giá mới.
4. Kiểm tra thư báo nhận đơn, thư trạng thái và thư khôi phục đến đúng hộp thử. Đối chiếu liên kết theo tên miền HTTPS đang dùng và giới hạn gửi thử. Tạm ngừng nhận đơn sau khi thử, rồi mở lại các đơn cũ để xác nhận vẫn tra cứu được.

## Chặng 11 · Diễn tập sự cố và chốt quyết định

| Việc cần làm | Bằng chứng để ghi nhận |
| --- | --- |
| Bàn giao ca bán | Ghi đầu mối xử lý đơn, đối soát, email lỗi và sự cố. Người nhận bàn giao tự đăng nhập và tìm được đơn cần giải quyết. |
| Thử tạm ngừng | Quản trị viên tạm ngừng nhận đơn và đặt nội dung thông báo. Checkout đang mở bị từ chối khi gửi. Nhân viên vẫn xử lý được đơn đã nhận. |
| Thử cảnh báo | Bộ giám sát ngoài máy kiểm tra `/up` và theo dõi tín hiệu định kỳ. Người trực xác nhận nhận cảnh báo lẫn tín hiệu phục hồi trên kênh đã chọn. |
| Thử phục hồi | Kiểm tra mã băm của bản theo lịch ở kho ngoài máy chủ. Phục hồi vào schema trống và thư mục ảnh riêng. Đối chiếu đơn, trạng thái và ảnh. Ghi thời gian phục hồi và mốc dữ liệu của bản sao. |
| Thử quay lui | Dùng bản tương thích với schema, thử phiên bản lỗi rồi quay về bản trước theo hướng dẫn. Nạp lại tiến trình web, worker và lịch theo cấu hình hạ tầng. Kiểm tra `/up`, đăng nhập và đơn đã nhận. |

Thực hiện sao lưu, phục hồi và phát hành theo [hướng dẫn vận hành](van-hanh.md). Ghi mỗi mục theo mẫu **môi trường · mã commit · thời điểm · người thử · kết quả · dẫn chứng**. Dẫn chứng có thể là mã lần chạy CI, ảnh giao diện đã che dữ liệu riêng hoặc kết quả kiểm tra mã băm. Mật khẩu, token, khóa TOTP và `APP_KEY` dùng kho bí mật đã được tiệm cấp.

Chủ tiệm duyệt biên bản trước khi quyết định mở bán. Hạng mục dùng dịch vụ hoặc dữ liệu thật cần kết quả từ chính môi trường đó. Khi có quyết định mở bán, người phụ trách đối chiếu đúng commit, chạy preflight của môi trường chuẩn, kiểm tra trạng thái nhận đơn rồi thực hiện theo quy trình đã duyệt. Sau đó theo dõi ca đầu để xác nhận đơn mới, thư và đối soát hoạt động.

## Kết quả kiểm tra

| Môi trường | Phiên bản | Kết quả |
| --- | --- | --- |
| GitHub Actions | `17a28ef`, gồm mã ứng dụng chặng 8 ở `cf71706` | [Lần chạy trên GitHub](https://github.com/quankaka0902/tiembanhcuatoi/actions/runs/44275279729) đạt Pint, ESLint, build Vite, cả bộ SQLite và MySQL, gồm các bài hai tiến trình. |
| Ca bán local | Mã ứng dụng `cf71706`, schema riêng `tiembanh_stage9_test` | Ngày 18/11/2026, đăng nhập bằng hai vai thử có TOTP, hoàn tất COD nhận tại tiệm và chuyển khoản giả lập giao tận nơi, đổi giờ rồi hủy đơn khác và hoàn tồn. Hộp SMTP thử nhận 7 thư qua TLS. Chrome hiển thị trang chủ, chi tiết bánh, tra cứu và đặt lại mật khẩu ở 390 px và 1366 px. |
| Tạm ngừng và quay lui local | Hai gói ứng dụng cùng mã, gói thử lỗi có `/up` trả HTTP 503 | Checkout đã mở bị từ chối sau khi tạm ngừng, giỏ vẫn còn. Bộ nhận thử ghi nhận mất tín hiệu định kỳ, sau đó nhận lại tín hiệu. Bản lỗi tạo cảnh báo web. Quay lui trả HTTP 200, giữ cả ba đơn và tạo thông báo phục hồi. |
| Sao lưu và phục hồi local | Sao lưu theo lịch từ `tiembanh_stage9_test` | Hai bản lưu trên cùng máy khớp SHA-256. Nhập bản thứ hai vào `tiembanh_stage9_restore_test` và thư mục ảnh riêng. Ba đơn, ảnh tải lên và trạng thái tạm ngừng khớp. Phục hồi và đối chiếu dữ liệu nhỏ này mất 1,3 giây trên máy thử. |

Staging có tên miền và chứng chỉ được tiệm tin cậy, menu thật, người trực tiếp chạy ca, SMTP, bộ giám sát và kho sao lưu ngoài máy chủ vẫn chờ cấu hình và nghiệm thu. Biên bản hiện chưa ghi phê duyệt mở bán.
