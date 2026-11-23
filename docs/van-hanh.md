# Vận hành Tiệm Bánh Nhà Mình

Quy tắc đổi/hủy, tồn sau làm, trả trước và bằng chứng hoàn hiện tại nằm trong [vòng đời đơn và ma trận quyền](vong-doi-don.md). Đơn mới có snapshot lead time; đơn cũ cần quản lý xác minh. Hủy sau làm không tự nhập lại tồn bán.

## Chuẩn bị môi trường thật

1. Dựng bằng một cơ sở dữ liệu MySQL riêng và thư mục ảnh bền vững. Trỏ web server vào `public/` và cấp quyền ghi cho `storage/` cùng `bootstrap/cache/`. Nếu dùng proxy TLS, ghi IP của các proxy tin cậy vào `BAKERY_TRUSTED_PROXIES`, cách nhau bằng dấu phẩy. Proxy phải ghi đè `X-Forwarded-Proto` bằng giao thức kết nối thực và chỉ chuyển đúng tên miền đã cấu hình. Hạn chế quyền truy cập cổng ứng dụng phía sau proxy.
2. Cấu hình `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` theo tên miền HTTPS, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax` hoặc `strict`, `BAKERY_DEMO_SEED=false` và thông tin MySQL. Tạo `APP_KEY` một lần cho môi trường mới và lưu khóa trong kho bí mật. Điền `MAIL_MAILER=smtp`, `MAIL_FROM_ADDRESS`, `MAIL_HOST`, `MAIL_PORT` và các trường xác thực/TLS theo nhà cung cấp. Nếu dùng `MAIL_URL`, preflight lấy host từ URL đó. Phải thử gửi thư để xác minh kết nối thực tế.
3. Cài dependency, chạy `npm run build`, `php artisan migrate --force` và `php artisan bakery:admin` nếu chưa có quản trị viên. Cấp TOTP cho từng tài khoản nội bộ. Cấp quyền đối soát cho người phụ trách. Dùng trình quản lý tiến trình để chạy và tự khởi động lại `php artisan queue:work order-mail --sleep=2`. Sau khi cập nhật mã, chạy `php artisan queue:restart` để worker nạp bản mới.
4. Môi trường thật mới dựng tạm ngừng nhận đơn. Điền liên hệ, địa chỉ, chính sách đặt bánh và thông báo xử lý dữ liệu trong **Cấu hình**. Nhập menu, tồn, lịch nhận, giới hạn theo giờ, khu vực và phí giao. Nếu sử dụng chuyển khoản, nhập thông tin ngân hàng đã xác minh và bỏ đánh dấu dữ liệu mẫu.
5. Chạy `php artisan bakery:preflight` và xử lý các mục báo lỗi. Kiểm tra HTTPS, truy cập `/up` và chạy thử một đơn từ lúc đặt đến lúc cập nhật trạng thái và tra cứu. Kiểm tra thư xác nhận, thư trạng thái và khôi phục mật khẩu đến hộp thư thử đã được phép. Lệnh preflight kiểm tra cấu hình và schema. Đơn thử xác minh worker và kết nối SMTP.
6. Dùng **Đơn cần xử lý** để bật nhận đơn sau khi hoàn tất nghiệm thu và có quyết định mở bán. Kiểm tra log ở proxy, ứng dụng và nền tảng giám sát. Dùng tên route hoặc loại thao tác cho đường dẫn tra cứu, `/thao-tac-don/{token}`, yêu cầu đổi/hủy, đặt lại mật khẩu và xác thực. Loại bỏ đường dẫn đầy đủ, chuỗi truy vấn, tiêu đề Referer và nội dung yêu cầu khỏi log các luồng này.

## Xác thực hai bước cho tài khoản nội bộ

Chạy `php artisan bakery:mfa EMAIL` trên máy chủ của đúng môi trường. Lệnh in khóa Base32 một lần. Chuyển khóa cho người được cấp qua kênh riêng của tiệm và nhập vào ứng dụng xác thực với cấu hình TOTP, SHA-1, 6 số và 30 giây. Giữ đồng hồ máy chủ và thiết bị xác thực đồng bộ. Mật khẩu đúng mở bước xác thực trong 5 phút. Mã đã dùng hết hiệu lực cho lần đăng nhập tiếp theo.

Khi mất thiết bị hoặc cần đổi khóa, người có quyền quản trị máy chủ xác minh nhân sự rồi chạy lại lệnh. Khóa cũ và phiên quản trị cũ hết hiệu lực. Hủy quyền đăng nhập trong **Nhân sự** khi nhân viên nghỉ. Giữ `APP_KEY` của môi trường trong kho bí mật vì khóa TOTP được mã hóa bằng khóa này.

## Khi tiệm đang nhận đơn

- Mở **Đơn cần xử lý** đầu mỗi ca. Xem **Chưa xác nhận** theo giờ giao hoặc nhận, rồi xem **Chuyển khoản quá hạn**. Đối chiếu sao kê trước khi xác nhận thanh toán. Các đơn quá hạn chờ người phụ trách xử lý.
- Khi quá tải hoặc bảo trì, quản trị viên bỏ chọn **Cho phép khách đặt đơn mới**, có thể nhập thông báo cho khách rồi lưu. Nhân viên vẫn xử lý được đơn đã nhận. Bật lại khi sẵn sàng.
- Nếu khách yêu cầu đổi giờ trước khi làm bánh, mở chi tiết đơn, chọn một khung còn chỗ và nhập lý do. Khách thấy giờ mới trên trang tra cứu. Hạn chuyển khoản còn hiệu lực chỉ có thể rút ngắn khi dời lịch sớm hơn.
- Xem **Chờ hoàn tiền** trong **Đơn cần xử lý**. Người có quyền đối soát thực hiện hoàn tiền theo quy trình của tiệm rồi ghi nhận trên đơn, kèm ghi chú đối chiếu nếu cần.
- Quản trị viên mở **Email đơn hàng** để xem tác vụ chờ quá 5 phút và các tác vụ đã thất bại. Hàng đợi trống chỉ cho biết chưa có tác vụ chờ. Kiểm tra tiến trình worker và thông tin SMTP khi có lỗi. Sau khi sửa, chạy `php artisan queue:retry <mã-tác-vụ>` đối với tác vụ cụ thể. Kiểm tra lại thư đầu ra, nhất là lỗi phát sinh lúc máy chủ thư đã nhận yêu cầu. Giới hạn quyền đọc log vì có thể chứa dữ liệu liên hệ hoặc liên kết riêng.

## Giám sát và cảnh báo

Đặt `BAKERY_MONITOR_ENABLED=true` rồi cấu hình trình lập lịch chạy `php artisan schedule:run` mỗi phút. Cấp cho worker và scheduler cùng đường dẫn lưu trữ riêng, bền vững và có quyền ghi qua `BAKERY_OPS_ROOT`. Có thể kiểm tra ngay bằng `php artisan bakery:monitor`. Lệnh kiểm tra HTTPS của `APP_URL/up`, kết nối MySQL, phản hồi của một tác vụ thăm dò đi qua hàng đợi `order-mail`, thư bị dồn hoặc thất bại và tuổi của bản sao lưu. Khi đã đặt thư mục bản sao thứ hai, kiểm tra tuổi dùng bản ở thư mục đó. Cho phép khoảng khởi động trước khi phát hiện worker không phản hồi.

Thư `order-mail` trong `failed_jobs` còn chưa xử lý tiếp tục gây cảnh báo dù đã quá 24 giờ. Sau khi sửa nguyên nhân, retry đúng tác vụ và xác nhận thư tới nơi. Việc xóa bản ghi lỗi chỉ được làm sau khi người phụ trách quyết định cách xử lý thư đó.

Cấu hình `BAKERY_ALERT_URL` bằng HTTPS trỏ đến bộ nhận webhook của đầu mối trực. Nếu cần, đặt `BAKERY_ALERT_TOKEN` làm Bearer token. Bộ nhận trả 2xx khi đã nhận, và chuyển thông báo tới người trực đã được duyệt. Payload chỉ gồm `service`, `state`, `checks` và `at`. Trạng thái là `incident` hoặc `recovered`. Các cảnh báo không đổi được nhắc lại sau 30 phút kể từ lần gửi thành công. Cấu hình CA của staging qua `BAKERY_MONITOR_CA`. Với staging có xác thực HTTP Basic, dùng `BAKERY_MONITOR_WEB_USER` và `BAKERY_MONITOR_WEB_PASSWORD`. Kiểm tra ở ngoài máy chủ vẫn cần thiết để phát hiện sự cố mất cả máy hoặc scheduler.

Nếu có bộ nhận độc lập ngoài máy chủ, đặt `BAKERY_HEARTBEAT_URL` bằng HTTPS và đặt `BAKERY_HEARTBEAT_TOKEN` khi bộ nhận yêu cầu Bearer token. Mỗi lần `bakery:monitor` chạy xong phần kiểm tra, kể cả khi phát hiện sự cố, nó gửi JSON gồm `service` và `at`. Bộ nhận trả 2xx, ghi thời điểm thực sự nhận được và báo người trực khi vắng tín hiệu quá khoảng thời gian đã thống nhất. Cấu hình thêm phép kiểm tra HTTPS `/up` từ bộ giám sát độc lập để theo dõi web. Khi diễn tập mất scheduler, tạm dừng lịch và chờ cảnh báo vắng tín hiệu. Chạy lệnh tay cũng gửi tín hiệu, vì vậy hãy chờ hết khoảng kiểm tra trước khi chạy tay.

## Sao lưu và diễn tập phục hồi

Đặt `BAKERY_BACKUP_ROOT` tới thư mục riêng đã tạo, bên ngoài vùng phục vụ web. Dùng `BAKERY_BACKUP_COPY_ROOT` cho nơi lưu bản thứ hai, ưu tiên thiết bị hoặc kho lưu trữ khác máy chủ đã được cấp. Cấp quyền đọc cho tài khoản sao lưu và ghi thông tin kết nối vào tệp MySQL `[client]` riêng, trỏ `BAKERY_BACKUP_MYSQL_DEFAULTS` vào tệp đó. Host, cổng hoặc socket nguồn và tên schema lấy từ kết nối MySQL hiện hành của ứng dụng. Dùng `BAKERY_MYSQLDUMP` nếu công cụ ở ngoài PATH. Cài PHP Zip và MySQL 8.4 client trên máy chạy tác vụ. Giới hạn quyền đọc cả bản sao và cấu hình kết nối. Giữ `APP_KEY` trong kho bí mật riêng cùng chính sách truy cập của tiệm.

Đặt `BAKERY_BACKUP_ENABLED=true` để chạy theo lịch `BAKERY_BACKUP_TIME`, mặc định 03:00 giờ Việt Nam. Tuổi báo quá hạn mặc định là 26 giờ qua `BAKERY_BACKUP_MAX_AGE_HOURS`. Chủ tiệm chốt tần suất, thời gian lưu, quyền truy cập và nơi lưu bản ngoài máy chủ. Có thể chạy trước mỗi lần cập nhật bằng tay.

```console
php artisan bakery:backup
php artisan bakery:backup --verify="/duong-dan/thu-muc-mot-ban-sao"
```

Mỗi bản có `database.sql`, `images.zip` và `manifest.json` với SHA-256. Bản sao xuất hiện ở đường dẫn hoàn chỉnh sau khi ghi và đối chiếu đủ tệp. Dump MySQL chụp dữ liệu giao dịch trước khi sao lưu ảnh. Ứng dụng tải ảnh lên thành tệp mới và giữ các tệp cũ khi bỏ ảnh khỏi sản phẩm. Tránh thay đổi schema hay xóa tệp ảnh bên ngoài ứng dụng trong lúc tác vụ đang chạy.

Manifest mới có `started_at` trước khi gọi dump và `completed_at` khi hoàn thành. `started_at` là giới hạn dưới thời gian tác vụ, chưa phải thời điểm snapshot MySQL chính xác. Khi diễn tập, ghi cả hai mốc, đơn mới nhất trong bản phục hồi, thời gian phục hồi và mức mất dữ liệu tiệm chấp nhận. Bộ kiểm tra vẫn hỗ trợ manifest cũ không có `started_at`, đồng thời từ chối manifest có cấu trúc hoặc mã băm sai kiểu.

Trước khi khôi phục, dùng `--verify` trên bản sẽ dùng, rồi nhập vào **schema trống** và thư mục ảnh mới. Ví dụ dùng Bash và hồ sơ `mysql_config_editor` đã được cấp quyền trên schema khôi phục.

```bash
test -n "$RESTORE_DB" && test "$RESTORE_DB" != "$DB_NAME" && test -d "$RESTORE_MEDIA" || exit 1
mysql --login-path=bakery-restore --database="$RESTORE_DB" < "$archive/database.sql" || exit 1
unzip "$archive/images.zip" -d "$RESTORE_MEDIA"
```

Trên Windows có thể dùng `mysql --database=SCHEMA_TRONG --execute="source C:/duong-dan/database.sql"` và `Expand-Archive`. Cấu hình ứng dụng phục hồi dùng đúng `DB_DATABASE`, `BAKERY_PUBLIC_ROOT` và khóa môi trường dự kiến. Trong diễn tập, giữ môi trường kín và hộp thư local. Đối chiếu số đơn, trạng thái, mục đơn và ảnh tải lên đã biết. Kiểm tra các tác vụ email trong bản phục hồi trước khi cho worker xử lý trong môi trường thật.

## Phát hành và quay lui ứng dụng

Chuẩn bị gói ứng dụng đã cài dependency và build frontend dưới `ROOT/releases/ID`. Dùng chung cấu hình bí mật, MySQL, ảnh, cache vận hành và các thư mục cần lưu bền vững giữa các phiên bản. Đặt trạng thái phát hành ở `ROOT/release.json` ngoài document root. Các lệnh dưới đây kiểm tra preflight trước khi đổi phiên bản hiện hành. Dùng `--staging` với `activate` và `rollback` khi diễn tập trên staging kín hoặc loopback. Kiểm tra bản quay lui hỗ trợ chế độ staging đã chọn trước buổi diễn tập.

```console
php ops/release.php activate ROOT ID
php ops/release.php current ROOT
php ops/release.php rollback ROOT
```

Trước khi đổi, tạm ngừng nhận đơn và xác nhận có bản sao đã kiểm tra. Áp dụng migration tương thích với cả hai phiên bản nếu cần. Dùng đường dẫn từ `current` để đổi document root sang `public/` của phiên bản hiện hành, rồi nạp lại web server, scheduler và worker. Kiểm tra `/up`, đăng nhập và tra cứu đơn đã nhận. Nếu bản mới lỗi, dùng `rollback` và nạp lại các tiến trình theo đường dẫn trước. Quy trình quay lui đổi mã ứng dụng và dùng lại dữ liệu hiện hành. Với migration không tương thích, cần quy trình chuyển đổi và phục hồi riêng trước khi phát hành.

## Nghiệm thu riêng trên máy

Dùng schema MySQL có hậu tố `_test` dành cho nghiệm thu, tách khỏi schema của bộ PHPUnit. Cấu hình thư mục ảnh riêng bằng `BAKERY_PUBLIC_ROOT`, URL và cookie phiên riêng, `APP_ENV=local` và `MAIL_MAILER=log`. Tạo bảng và demo trong schema trống bằng `php artisan migrate --seed` với `BAKERY_DEMO_SEED=true`. Chạy `php artisan bakery:preflight --staging` trước khi thử.

Kiểm tra đơn COD, đơn chuyển khoản mẫu, đối soát và tra cứu. Đặt một đơn trong lúc worker dừng, sau đó khởi động worker và đối chiếu email trong nhật ký. Mở sẵn trang đặt bánh, tạm ngừng nhận đơn bằng một phiên quản trị, rồi gửi trang cũ. Kiểm tra giỏ vẫn còn và đơn đã nhận trước đó vẫn xử lý được. Diễn tập phục hồi vào schema và thư mục ảnh thứ hai.

Để nghiệm thu kiểu chặng 5, dùng thêm `BAKERY_REHEARSAL=true` với hostname loopback qua HTTPS và một hộp thư SMTP nhận thử trên loopback. Dùng MySQL test, dữ liệu giả, khóa ứng dụng và thư mục ảnh riêng. Tắt seed demo và cấu hình các tài khoản thử bằng TOTP riêng. Đặt `SESSION_SECURE_COOKIE=true` và `SESSION_ENCRYPT=true`. Giới hạn staging ở loopback và kiểm tra chứng chỉ bằng CA được cấp riêng cho môi trường này. Chạy `php artisan bakery:preflight --staging` rồi thử công tắc mở nhận đơn, trạng thái worker, ba loại thư, đổi giờ và ghi nhận hoàn tiền. Nghiệm thu tên miền và nhà cung cấp SMTP của cửa hàng cần cấu hình cùng hộp thư do chủ tiệm xác nhận.

## Nghiệm thu kín với tên miền riêng

1. Dùng mạng riêng hoặc VPN để giới hạn toàn bộ tên miền staging, kể cả ảnh và tệp frontend do web server phục vụ. Chỉ máy chủ web hoặc proxy được phép kết nối trực tiếp tới tiến trình ứng dụng. Chuyển đúng tên miền, tiêu đề `Authorization` và giao thức HTTPS từ proxy đã khai báo trong `BAKERY_TRUSTED_PROXIES`. Tắt việc lưu giá trị Authorization, Referer hoặc URL tra cứu và đặt lại mật khẩu đầy đủ vào log.
2. Đặt `APP_ENV=staging`, `APP_URL` là địa chỉ HTTPS của tên miền riêng, `APP_DEBUG=false`, `BAKERY_REHEARSAL=false` và `BAKERY_DEMO_SEED=false`. Dùng MySQL tên schema kết thúc bằng `_test`, tài khoản MySQL chỉ có quyền trên schema staging, khóa ứng dụng và thư mục ảnh riêng. Đặt tên cookie riêng qua `SESSION_COOKIE`, để trống `SESSION_DOMAIN`, đồng thời bật `SESSION_SECURE_COOKIE` và `SESSION_ENCRYPT`.
3. Đặt `BAKERY_STAGING_PASSWORD` là mật khẩu ngẫu nhiên từ 32 ký tự. Trình duyệt dùng tài khoản HTTP Basic `staging` và mật khẩu này ở lớp truy cập Laravel, kể cả đường dẫn `/up`. Sau lớp đó, nhân viên đăng nhập tài khoản của mình và xác thực TOTP như bình thường. Nếu dùng giám sát nội bộ, cấu hình thông tin Basic tương ứng qua `BAKERY_MONITOR_WEB_USER` và `BAKERY_MONITOR_WEB_PASSWORD`.
4. Đặt `MAIL_MAILER=smtp`, điền địa chỉ gửi đã xác minh và các trường SMTP `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` theo nhà cung cấp. Để trống `MAIL_URL`. Staging yêu cầu TLS và kiểm tra chứng chỉ SMTP. Điền các địa chỉ thử được duyệt vào `BAKERY_STAGING_MAIL_RECIPIENTS`, cách nhau bằng dấu phẩy. Ứng dụng bỏ qua cả thư nếu bất kỳ người nhận nào ngoài danh sách hoặc cấu hình gửi sai. Dùng chính các địa chỉ đã duyệt khi thử đặt đơn và khôi phục mật khẩu.
5. Chạy migration, tạo tài khoản và nhập dữ liệu cửa hàng được phép dùng trong staging. Chạy `php artisan bakery:preflight --staging` trước khi bật nhận đơn để chạy ca thử bên trong staging. Kiểm tra truy cập khi thiếu mật khẩu, trạng thái đơn, ba loại thư và các thao tác sự cố theo [biên bản nghiệm thu](nghiem-thu-chang-7.md). Tạm ngừng nhận đơn khi kết thúc ca. Giữ mật khẩu, khóa TOTP, khóa ứng dụng và token trong kho bí mật được cấp.

## Các mục cần cấu hình của tiệm để nghiệm thu lần cuối

| Mục | Đầu vào của tiệm | Việc cần xác nhận |
| --- | --- | --- |
| Ca bán | Danh sách món, giá, tồn, thông tin dị ứng, nguồn ảnh, giờ nhận, khu vực giao, chính sách và tài khoản nhân viên | Nhân viên của tiệm trực tiếp chạy ca thử. Nếu bật chuyển khoản, người có quyền đối soát đối chiếu thông tin tài khoản do tiệm xác nhận. |
| HTTPS và email | Máy chủ hoặc staging kín, tên miền và chứng chỉ, tài khoản SMTP, địa chỉ gửi và hộp thư thử được duyệt | Kiểm tra HTTPS và đủ ba loại thư qua thiết lập thực. Dùng staging có giới hạn truy cập và tạm ngừng nhận đơn khi kết thúc. |
| Cảnh báo | Webhook, quyền truy cập, người trực và kênh nhận thông báo | Phát thử sự cố và thông báo phục hồi tới người trực. Cấu hình phép kiểm tra từ ngoài máy chủ nếu cần phát hiện mất cả máy hoặc scheduler. |
| Sao lưu và phát hành | Tài khoản chỉ dùng cho sao lưu, kho ngoài máy chủ, quyền truy cập, lịch lưu giữ, cách nạp lại web server và worker | Chạy lại sao lưu theo lịch, xác nhận bản sao xuất hiện ở kho đã duyệt và diễn tập phục hồi, quay lui trên máy chủ staging. |

Giữ công tắc nhận đơn tạm ngừng cho đến khi chủ tiệm quyết định mở bán.
