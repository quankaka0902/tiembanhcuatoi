# Vận hành Tiệm Bánh Nhà Mình

## Chuẩn bị môi trường thật

1. Dựng bằng một cơ sở dữ liệu MySQL riêng và thư mục ảnh bền vững. Trỏ web server vào `public/` và cấp quyền ghi cho `storage/` cùng `bootstrap/cache/`. Nếu dùng proxy TLS, cấu hình web server để ứng dụng nhận đúng tên miền HTTPS.
2. Cấu hình `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` theo tên miền HTTPS, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `BAKERY_DEMO_SEED=false` và thông tin MySQL. Tạo `APP_KEY` một lần cho môi trường mới và lưu khóa trong kho bí mật. Điền `MAIL_MAILER=smtp`, `MAIL_FROM_ADDRESS`, `MAIL_HOST`, `MAIL_PORT` và các trường xác thực/TLS theo nhà cung cấp.
3. Cài dependency, chạy `npm run build`, `php artisan migrate --force` và `php artisan bakery:admin` nếu chưa có quản trị viên. Tạo tài khoản riêng cho nhân viên và cấp quyền đối soát cho người phụ trách. Dùng trình quản lý tiến trình để chạy và tự khởi động lại `php artisan queue:work order-mail --sleep=2`. Sau khi cập nhật mã, chạy `php artisan queue:restart` để worker nạp bản mới.
4. Trong **Đơn cần xử lý**, tạm ngừng nhận đơn mới khi cấu hình. Điền liên hệ, địa chỉ, chính sách đổi giờ, hủy và hoàn tiền trong **Cấu hình**. Nhập menu, tồn, lịch nhận, giới hạn theo giờ, khu vực và phí giao. Nếu sử dụng chuyển khoản, nhập thông tin ngân hàng đã xác minh và bỏ đánh dấu dữ liệu mẫu.
5. Chạy `php artisan bakery:preflight` và xử lý các mục báo lỗi. Kiểm tra HTTPS, truy cập `/up` và chạy thử một đơn từ lúc đặt đến lúc cập nhật trạng thái và tra cứu. Kiểm tra thư xác nhận, thư trạng thái và khôi phục mật khẩu đến hộp thư thử đã được phép. Lệnh preflight kiểm tra cấu hình và schema. Đơn thử xác minh worker và kết nối SMTP.

## Khi tiệm đang nhận đơn

- Mở **Đơn cần xử lý** đầu mỗi ca. Xem **Chưa xác nhận** theo giờ giao hoặc nhận, rồi xem **Chuyển khoản quá hạn**. Đối chiếu sao kê trước khi xác nhận thanh toán. Các đơn quá hạn chờ người phụ trách xử lý.
- Khi quá tải hoặc bảo trì, quản trị viên bỏ chọn **Cho phép khách đặt đơn mới**, có thể nhập thông báo cho khách rồi lưu. Nhân viên vẫn xử lý được đơn đã nhận. Bật lại khi sẵn sàng.
- Quản trị viên mở **Email đơn hàng** để xem tác vụ chờ quá 5 phút và các tác vụ đã thất bại. Hàng đợi trống chỉ cho biết chưa có tác vụ chờ. Kiểm tra tiến trình worker và thông tin SMTP khi có lỗi. Sau khi sửa, chạy `php artisan queue:retry <mã-tác-vụ>` đối với tác vụ cụ thể. Kiểm tra lại thư đầu ra, nhất là lỗi phát sinh lúc máy chủ thư đã nhận yêu cầu. Giới hạn quyền đọc log vì có thể chứa dữ liệu liên hệ hoặc liên kết riêng.

## Sao lưu và diễn tập phục hồi

Sao lưu cơ sở dữ liệu và thư mục ảnh trong cùng một đợt. Đặt tần suất theo mức mất dữ liệu tiệm chấp nhận được và tạo thêm một bản trước mỗi thay đổi schema. Chọn thư mục lưu trữ có phân quyền vì dump chứa dữ liệu khách. Giữ một bản ở vị trí khác máy đặt ứng dụng và giữ `APP_KEY` trong kho bí mật riêng.

Để lấy một bản nhất quán, đưa ứng dụng vào chế độ bảo trì, để các yêu cầu đang chạy kết thúc và dừng worker trước khi sao lưu. Bật ứng dụng và worker lại sau khi cả hai tệp đã tạo thành công. Ví dụ dùng Bash và hồ sơ `mysql_config_editor` có quyền lần lượt trên cơ sở dữ liệu nguồn và phục hồi. `DB_NAME` là schema nguồn. `MEDIA_ROOT` là `storage/app/public` hoặc thư mục đặt qua `BAKERY_PUBLIC_ROOT`.

```bash
umask 077
archive="/var/backups/tiembanh/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$archive" || exit 1
mysqldump --login-path=bakery-backup --single-transaction --no-tablespaces --set-gtid-purged=OFF \
  --skip-add-drop-table --result-file="$archive/database.sql" "$DB_NAME" || exit 1
tar -C "$MEDIA_ROOT" -czf "$archive/images.tar.gz" bakery
```

Nếu chưa có ảnh tải lên, tạo thư mục `bakery` rỗng trước khi sao lưu. Kiểm tra cả hai lệnh đều thành công và tệp đọc được. Có thể dùng `--result-file` trên Windows để giữ mã hóa của dump. Khôi phục vào **schema trống** và thư mục ảnh mới, với các tiến trình kết nối đúng môi trường riêng.

```bash
test -n "$RESTORE_DB" && test "$RESTORE_DB" != "$DB_NAME" && test -d "$RESTORE_MEDIA" || exit 1
mysql --login-path=bakery-restore --database="$RESTORE_DB" < "$archive/database.sql" || exit 1
tar -C "$RESTORE_MEDIA" -xzf "$archive/images.tar.gz"
```

Trên Windows có thể dùng `mysql --database=SCHEMA_TRONG --execute="source C:/duong-dan/database.sql"` với cùng hồ sơ kết nối. Cấu hình ứng dụng phục hồi dùng đúng `DB_DATABASE`, `BAKERY_PUBLIC_ROOT` và khóa môi trường dự kiến. Trong buổi diễn tập, lắng nghe loopback và dùng mailer log. Đối chiếu số đơn, trạng thái, mục đơn và ảnh tải lên đã biết. Kiểm tra các tác vụ email trong bản phục hồi trước khi cho worker xử lý trên môi trường thật.

## Nghiệm thu riêng trên máy

Dùng schema MySQL có hậu tố `_test` dành cho nghiệm thu, tách khỏi schema của bộ PHPUnit. Cấu hình thư mục ảnh riêng bằng `BAKERY_PUBLIC_ROOT`, URL và cookie phiên riêng, `APP_ENV=local` và `MAIL_MAILER=log`. Tạo bảng và demo trong schema trống bằng `php artisan migrate --seed` với `BAKERY_DEMO_SEED=true`. Chạy `php artisan bakery:preflight --staging` trước khi thử.

Kiểm tra đơn COD, đơn chuyển khoản mẫu, đối soát và tra cứu. Đặt một đơn trong lúc worker dừng, sau đó khởi động worker và đối chiếu email trong nhật ký. Mở sẵn trang đặt bánh, tạm ngừng nhận đơn bằng một phiên quản trị, rồi gửi trang cũ. Kiểm tra giỏ vẫn còn và đơn đã nhận trước đó vẫn xử lý được. Diễn tập phục hồi vào schema và thư mục ảnh thứ hai.
