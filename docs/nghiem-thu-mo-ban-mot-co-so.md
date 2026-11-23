# Nghiệm thu mở bán một cơ sở

Ngày kiểm tra 23/11/2026, múi giờ Asia/Ho_Chi_Minh. Các bản sửa ứng dụng được đóng trong commit local `5de11c0c694ea8e784680e8c39e7bc2dc0b6eb31`, nối tiếp `ef35bd2`. Người thử kỹ thuật là Codex. Đại diện tiệm chưa được chỉ định để xác nhận dữ liệu và nghiệm thu dịch vụ thật.

**Quyết định hiện tại là CHỜ mở bán.** Bộ dữ liệu demo dùng cho ca local đã được tạo bằng seeder của dự án. Môi trường `production` hoặc staging dùng dữ liệu thật chưa được cấp. Preflight của staging có dữ liệu demo báo lỗi đúng theo thiết kế. Công tắc nhận đơn trong sandbox được tạm ngừng sau ca thử.

## Đầu vào thật và người chịu trách nhiệm

Từng dòng đều đang **CHỜ** chủ tiệm xác nhận. Vai trò trong bảng là đầu mối cần được tiệm chỉ định bằng tên người và người thay thế trước ca đầu.

| Mục | Người chịu trách nhiệm | Đầu vào đang thiếu và cách kiểm tra | Tác động tới quyết định mở bán |
| --- | --- | --- | --- |
| Thương hiệu | Chủ tiệm | Tên chính thức, logo, liên hệ. Chủ tiệm đối chiếu trên trang chủ, footer, email và quản trị. | Chờ xác nhận nhận diện và đầu mối khách liên hệ. |
| Ảnh | Chủ tiệm hoặc người quản lý menu | Ảnh từng bánh, logo và xác nhận quyền sử dụng. Kiểm tra hiển thị trên điện thoại và desktop. Ảnh trong ca này là tài sản demo của repo. | Chờ bộ ảnh được phép dùng khi bán thật. |
| Menu và giá | Người quản lý menu, chủ tiệm duyệt | Danh mục, món, phiên bản, giá VND, phí lời chúc. Thử mỗi kiểu biến thể và đối chiếu báo giá, đơn, lịch làm bánh. | Chờ bảng giá và món thực bán. |
| Tồn và năng lực | Trưởng ca hoặc phụ trách làm bánh | Tồn đầu ca, nguyên tắc sản xuất theo đơn và sức chứa từng khung. Đối chiếu nhật ký tồn với đặt, đổi giờ, hủy. | Chờ mức tồn và số đơn mà cơ sở có thể nhận. |
| Khẩu phần | Người quản lý menu | Số người ăn hoặc khối lượng nếu tiệm áp dụng. Hiện có thể ghi trong tên phiên bản, mô tả. Chưa có trường khẩu phần cấu trúc riêng. | Tiệm chốt nội dung hiển thị hoặc yêu cầu trường riêng trước khi bán món cần thông tin này. |
| Dị ứng | Phụ trách làm bánh, chủ tiệm duyệt | Thành phần, dị ứng và tiếp xúc chéo của từng món. Đối chiếu công thức và màn hình chi tiết bánh. | Chờ người am hiểu sản phẩm xác nhận. |
| Giờ cắt đơn | Trưởng ca, chủ tiệm duyệt | Hệ thống hiện dùng thời gian chuẩn bị theo bánh và lịch nhận 14 ngày. Tiệm cần chốt có mốc cắt đơn cố định độc lập hay không. Thử ngay trước và sau mốc mà tiệm chốt. | Nếu cần mốc cắt đơn riêng, phải bổ sung và nghiệm thu quy tắc đó. |
| Lịch và nghỉ | Trưởng ca | Ngày mở cửa, ngày nghỉ, khung nhận và giới hạn mỗi khung. Thử ngày nghỉ và hai người tranh chỗ cuối. | Chờ lịch bán thật và năng lực ca. |
| Địa chỉ cơ sở | Chủ tiệm | Địa chỉ lấy bánh, hướng dẫn tìm tiệm và số hỗ trợ. Đối chiếu cửa hàng, email và trang tra cứu. | Địa chỉ trong seeder là mẫu, cần xác nhận trước nhận đơn. |
| Vùng và phí giao | Phụ trách giao, chủ tiệm duyệt | Vùng phục vụ, phí, ngưỡng miễn phí, cách xử lý ngoài vùng. Thử ở mỗi vùng, đơn dưới và trên ngưỡng sau giảm giá. | Chờ quy tắc và khả năng giao của một cơ sở. |
| COD | Thu ngân hoặc trưởng ca | Chốt người thu và thời điểm ghi nhận thu. Hiện nhân viên thường được ghi COD ở trạng thái sẵn sàng nhận hoặc đang giao. | Chờ phân công và đối chiếu cuối ca. |
| Chuyển khoản | Người đối soát, chủ tiệm duyệt | Chốt có bật phương thức này, ngân hàng, tài khoản và tên chủ tài khoản, hạn chuyển khoản. Đối chiếu thông tin do tiệm cấp, số tiền và mã đơn. | Chờ xác nhận nếu bật. Có thể chốt chỉ dùng COD trước. Chuyển khoản của ca này luôn là mẫu. |
| Hủy, đổi và hoàn | Chủ tiệm, người đối soát | Hiện nhân viên đổi giờ khi chờ hoặc đã xác nhận. Có thể hủy khi chờ, đã xác nhận, đang làm, sẵn sàng nhận hoặc đang giao. Mọi lần hủy hợp lệ hoàn toàn bộ tồn và lượt mã giảm đúng một lần. Hoàn tiền do người có quyền ghi nhận sau khi hoàn bên ngoài hệ thống. | Chủ tiệm phải xác nhận việc hoàn tồn cả sau khi đã làm. Nếu bánh đã làm được tính hao hụt, cần sửa quy tắc và kiểm thử trước mở bán. |
| Chính sách và dữ liệu | Chủ tiệm, đầu mối hỗ trợ | Nội dung đổi, hủy, hoàn, liên hệ hỗ trợ và thông báo sử dụng dữ liệu khách. Đối chiếu với cách ứng dụng xử lý thực tế. | Preflight đang chặn vì các nội dung này chưa được nhập cho tiệm. |
| Nhân sự và quyền | Chủ tiệm | Danh sách người, vai trò admin/nhân viên, quyền đối soát, TOTP từng người và người thay thế. Kiểm kê tài khoản thử khi dựng môi trường thật. Preflight hiện chỉ nhận diện bốn email seed cố định. | Chờ tên người phụ trách thực tế và kiểm tra quyền bằng tài khoản của họ. |

## Môi trường và cấu hình đã kiểm tra

| Hạng mục | Kết quả local | Mục còn chờ với dịch vụ thật |
| --- | --- | --- |
| MySQL và ảnh | Schema `tiembanh_acceptance_20261123_test`, tài khoản DB chỉ có quyền trên schema thử và schema phục hồi. Thư mục ảnh riêng dưới `work/acceptance-20261123/media`. | Máy chủ, quyền DB và kho ảnh của staging tiệm. |
| HTTPS và thiết bị | API trên `https://staging.localhost:18900` xác minh TLS bằng CA thử được truyền rõ cho máy khách. Chứng chỉ có hạn tới 02/12/2026. | Chứng chỉ và tên miền được thiết bị tiệm tin cậy. Chứng chỉ tự ký local chưa được trình duyệt tiệm tin cậy. |
| Ca trình duyệt | HTTP chỉ trên `127.0.0.1:18904`, cùng schema demo, cookie và tiến trình riêng. Đã thao tác ở 390×844 và 1366×900. | Lặp lại ca bằng HTTPS trên thiết bị được duyệt của tiệm. |
| TOTP và quyền | Tài khoản nhân viên và quản trị mẫu có TOTP riêng. Nhân viên bị chặn bật nhận đơn, đối soát chuyển khoản và ghi nhận hoàn tiền. | Cấp TOTP cho nhân sự được duyệt, chốt quy trình mất thiết bị và thu hồi quyền. |
| Cookie | Trên API HTTPS đã kiểm tra Secure, HttpOnly và SameSite=lax. Preflight đã bổ sung kiểm tra HttpOnly và SameSite. Bộ PHPUnit kiểm tra cả cấu hình thiếu Secure và thiếu mã hóa phiên. | Xác minh cookie trên proxy, tên miền và thiết bị thật. |
| Tệp tĩnh và `/up` | Proxy loopback yêu cầu Basic cho web, ảnh, font, build và `/up`. Các yêu cầu thiếu quyền trả 401, `/up` có quyền trả 200. | Kiểm tra từ ngoài vùng được phép, gồm tệp tĩnh web server phục vụ trực tiếp. Middleware Laravel chỉ kiểm soát yêu cầu đến ứng dụng. |
| Preflight local | Dùng profile local, schema thử, URL loopback và mailer log, lệnh `bakery:preflight --staging` đạt để diễn tập. | Lệnh này kiểm tra môi trường local ở mức nhẹ. |
| Preflight staging kín | Chuyển profile thử sang `APP_ENV=staging` và chạy `--staging`, lệnh thất bại vì demo bật, địa chỉ gửi mẫu, chính sách chưa nhập và tài khoản/ngân hàng mẫu. | Nhập dữ liệu được tiệm duyệt, cấp cấu hình thật và chạy lại preflight. |
| Hộp thư | SMTPS loopback chỉ nhận địa chỉ thử của seeder. Bộ PHPUnit kiểm tra danh sách cho phép của staging, cả To, Cc, Bcc và sai kênh gửi. | SMTP, địa chỉ gửi và hộp thư nhận do tiệm duyệt. |

## Các ca nghiệm thu và bằng chứng

Các dòng dưới đây gắn với mã ứng dụng được đóng trong `5de11c0`, schema và môi trường ở trên, người thử Codex, ngày 23/11/2026. Bằng chứng tổng hợp đã bỏ email đăng nhập, mật khẩu, khóa TOTP, token, cookie và liên kết riêng nằm trong [kết quả máy đọc được](evidence/2026-11-23/acceptance-results.json). Chỉ ảnh giao diện chứa dữ liệu demo đã giới hạn vùng hiển thị được đưa vào hồ sơ.

S01–F06, M01 và U01–U03 chạy trên working tree chứa các bản sửa, trước lúc tạo commit lúc 12:17:06. HEAD của những ca đó vẫn là `ef35bd2` và được giữ nguyên trong nguồn bằng chứng. Các tệp sửa có mốc ghi trước ca đầu, và lần đối chiếu cuối xác nhận mã ứng dụng khớp `5de11c0`. O01–O07, M02, đọc lại đơn sau quay lui, đối chiếu đầy đủ snapshot và toàn bộ kiểm tra mã đã chạy sau khi tạo commit. Bản tổng hợp giữ mốc kết thúc gốc của harness và mốc bổ sung M01 để không làm mất dấu quá trình kiểm chứng.

Ca UI dùng trình duyệt trên HTTP loopback. Các ca giao hàng, chuyển khoản, đổi/hủy và quyền dùng HTTP client qua HTTPS local. Ca tranh chỗ chạy bằng PHPUnit trên MySQL. Cần lặp bộ ca trên HTTPS và thiết bị thật của tiệm, gồm thao tác giao hàng và chuyển khoản qua giao diện.

| Mã | Ca | Trạng thái và kết quả |
| --- | --- | --- |
| S01–S02 | HTTPS, tài nguyên, TOTP và quyền | ĐẠT LOCAL. TLS máy khách kiểm chứng, static và `/up` yêu cầu quyền, hai vai trò nội bộ qua TOTP. Kiểm chứng bổ sung sau commit với ảnh sản phẩm, ảnh tải lên, font và JS build đang tồn tại thật, cùng `/up`, đều trả 401 khi thiếu quyền và 200 khi có quyền. |
| F01 | COD nhận tại tiệm, gửi lại yêu cầu đặt | ĐẠT LOCAL. Hoàn tất đơn và ghi nhận thanh toán. Cùng khóa yêu cầu chỉ tạo một đơn. |
| F02 | Giao và chuyển khoản mẫu | ĐẠT LOCAL. Tiền bánh sau mã giảm 324.000 ₫, phí giao 25.000 ₫, tổng 349.000 ₫. Nhân viên thường bị chặn đối soát. |
| F03 | Đổi giờ rồi hủy trước làm | ĐẠT LOCAL. Giờ mới được lưu, hủy lặp bị từ chối, tồn và lượt mã hoàn đúng một lần. |
| F04 | Hủy sau bắt đầu làm và hoàn tiền | ĐẠT LOCAL theo quy tắc đang có. Đổi giờ bị từ chối sau khâu chuẩn bị, hủy hoàn toàn bộ tồn, chỉ người có quyền ghi nhận hoàn tiền cho đơn đã trả. CHỜ chủ tiệm duyệt chính sách. |
| F05 | Giá đổi khi gửi đơn | ĐẠT LOCAL. Báo giá cũ bị từ chối và chưa tạo đơn. Đặt thành công sau khi nhận báo giá mới. |
| F06 | Tạm ngừng khi checkout còn mở | ĐẠT LOCAL. Yêu cầu bị từ chối, giỏ còn nguyên. Đơn cũ tiếp tục tra cứu được. |
| M01 | Thư, gửi lại liên kết và khôi phục mật khẩu | ĐẠT LOCAL. Worker gửi thư nhận đơn và trạng thái, thông tin sai nhận phản hồi trung tính và không tạo thư, thông tin đúng tạo một thư tới email lưu trên đơn. Mở được liên kết khôi phục qua HTTPS local. |
| M02 | Hoàn tất khôi phục mật khẩu | ĐẠT LOCAL sau commit. Thư mới tới hộp SMTPS thử, đặt mật khẩu mới thành công, dùng lại token bị từ chối, đăng nhập được bằng mật khẩu mới. |
| U01 | Chọn bánh đến tạo COD trên điện thoại | ĐẠT LOCAL. Thao tác qua thực đơn, chi tiết, giỏ, chọn ngày/giờ, nhập dữ liệu mẫu và xác nhận. [Ảnh mã đơn trên điện thoại](evidence/2026-11-23/mobile-cod.jpg). |
| U02 | Nhân viên hoàn tất, khách kiểm tra | ĐẠT LOCAL. Đơn vừa đặt được tìm trong hàng chờ, xác nhận, chuẩn bị, sẵn sàng, ghi nhận COD và hoàn tất qua giao diện desktop. Trang khách hiển thị hoàn tất. [Ảnh desktop](evidence/2026-11-23/desktop-completed.jpg). |
| U03 | Hàng chờ responsive | ĐẠT LOCAL. [Bảng desktop](evidence/2026-11-23/desktop-inbox.jpg) và [thẻ điện thoại](evidence/2026-11-23/mobile-inbox.jpg). |
| T01 | Tranh khung cuối và dữ liệu đồng thời | ĐẠT MYSQL TEST. Chạy đủ năm bài hai tiến trình, gồm khung cuối, tồn cuối, lượt mã cuối, gửi cùng khóa và đặt tranh chỗ với đổi giờ. |

Ca chuyển khoản và thu/hoàn trong hồ sơ dùng dữ liệu mẫu. Người của tiệm chưa chạy ca hay xác nhận việc nhận/hoàn tiền qua dịch vụ thật.

## Vận hành, phục hồi và quay lui

Bộ nhận cảnh báo thử chạy ở tiến trình HTTPS riêng trên cùng máy. Kiểm tra từ máy ngoài, cảnh báo tới người trực và lưu trữ ngoài máy chủ vẫn **CHỜ** quyền truy cập dịch vụ đã được tiệm duyệt.

| Mã | Ca vận hành | Kết quả |
| --- | --- | --- |
| O01 | Scheduler | ĐẠT LOCAL. Chạy `schedule:run` với giờ sao lưu đặt vào phút thử. Kiểm tra bản hoàn chỉnh ở hai thư mục local. |
| O02 | SMTP và thư lỗi | ĐẠT LOCAL. Mô phỏng SMTP trả 451 cho một thư đã hết lượt thử, kiểm tra `failed_jobs`, cảnh báo, retry và tín hiệu phục hồi. |
| O03 | Worker | ĐẠT LOCAL. Mô phỏng tuổi phản hồi quá hạn trong cache local, nhận cảnh báo, chạy worker xử lý probe thật và kiểm tra phục hồi. |
| O04 | MySQL | ĐẠT LOCAL. Riêng tiến trình monitor dùng cổng không có dịch vụ để tạo lỗi. Khôi phục cấu hình tiến trình và kiểm tra tín hiệu phục hồi. |
| O05 | Tín hiệu scheduler | ĐẠT LOCAL. Bộ nhận cùng máy đối chiếu khoảng thiếu tín hiệu và tín hiệu trở lại với ngưỡng thử rút xuống 5 giây. Chưa thử tiến trình scheduler chạy bền vững hay kênh cảnh báo ngoài máy. |
| O06 | Quay lui schema tương thích | ĐẠT LOCAL. Gói thử lỗi chỉ đổi phản hồi `/up` thành 503, không đổi schema. Quay lui dùng chung DB và ảnh, `/up` trở về 200. Kiểm tra bổ sung đọc lại được cả sáu đơn tạo trong ca với đúng mã, trạng thái đơn và thanh toán. Preflight chọn gói dùng chế độ local demo. |
| O07 | DB và ảnh | ĐẠT LOCAL. Khôi phục từ bản thứ hai vào schema `tiembanh_acceptance_20261123_restore_test` và thư mục ảnh trống. Khớp 13 đơn và 13 mục đơn, gồm đối chiếu bổ sung tất cả cột snapshot, tùy chọn và ghi chú của mục đơn. Khớp mã, trạng thái, thanh toán, tổng, giờ hẹn, thời gian tạo và SHA-256 ảnh. Phục hồi và đối chiếu ban đầu mất 2,25 giây với dữ liệu demo nhỏ. Worker của bản khôi phục giữ dừng. |

Manifest mới có `started_at` ghi trước khi gọi dump và `completed_at` lúc hoàn thành sao lưu. Hai mốc là giới hạn thời gian ghi nhận của tác vụ. Bản dump lấy snapshot giao dịch sau mốc bắt đầu. Mức mất dữ liệu cho ca thật cần tính từ mốc snapshot và thời điểm sự cố thực tế, rồi đối chiếu với mức tiệm chấp nhận.

Bản được phục hồi có hai mốc ở 12:20:28 ngày 23/11/2026, độ phân giải một giây. Đơn mới nhất trong bản sao tạo lúc 12:14:23, hẹn 11:00 ngày 24/11/2026. Chưa chốt mục tiêu mất dữ liệu tối đa và thời gian phục hồi tối đa cho ca thật. Khi kết thúc kiểm chứng lúc 12:25, sandbox tạm ngừng, có 13 đơn, 0 tác vụ chờ và 0 thư thất bại.

## Lỗi đã sửa trong phiên bản nghiệm thu

| Lỗi tái hiện | Sửa và kiểm chứng |
| --- | --- |
| Cookie HttpOnly tắt hoặc SameSite không giới hạn vẫn qua kiểm tra cấu hình | Bổ sung điều kiện trong `LaunchReadiness`, dùng chung cho preflight và bật nhận đơn. Hồi quy cho HttpOnly, SameSite, Secure, mã hóa phiên. |
| SMTP host rỗng vẫn qua kiểm tra | Kiểm tra host trực tiếp hoặc host trong `MAIL_URL`, giữ hỗ trợ URL kết nối. Hồi quy cho host rỗng và URL hợp lệ. Việc host truy cập được phải xác minh bằng ca gửi thư. |
| SHA-256 trong manifest sai kiểu làm dừng monitor | Kiểm tra cấu trúc và kiểu dữ liệu trước khi dùng. Manifest hỏng trả lỗi, monitor vẫn gửi incident và heartbeat. Giữ đọc được manifest cũ. |
| Thư thất bại quá 24 giờ có thể mất cảnh báo khi chưa xử lý | Monitor theo dõi tất cả thư lỗi chưa xử lý của `order-mail`. Hồi quy cho lỗi hai ngày tuổi và chỉ phục hồi sau khi được xử lý. |

## Kết quả kiểm tra mã

Chạy ngày 23/11/2026 lúc 12:23–12:24 trên commit local `5de11c0`, tách khỏi schema ca bán. Thời điểm, người thử, mã thoát và mã băm log nằm trong bằng chứng tổng hợp.

| Kiểm tra | Kết quả |
| --- | --- |
| `php vendor/bin/pint --test` | ĐẠT |
| `npm.cmd run lint` | ĐẠT |
| `npm.cmd run build` | ĐẠT |
| PHPUnit SQLite | 36 bài đạt, 5 bài đồng thời chỉ dành cho MySQL được bỏ qua, 966 assertions. |
| PHPUnit MySQL | 41/41 bài đạt, 1001 assertions. Dùng `--fail-on-skipped`, gồm đủ năm bài hai tiến trình. |

Đã đọc CI hiện có. Workflow cài dependency, chạy Pint, ESLint, build, SQLite và MySQL 8.4 độc lập. Commit local này chưa push và chưa có lần GitHub Actions của chính nó. Không dùng kết quả CI của bản trước để kết luận cho bản mới.

## Cách chạy lại

Trong workspace đã cấu hình, các profile, khóa thử, script ca và nhật ký đầy đủ nằm dưới `work/acceptance-20261123` và được Git bỏ qua. Không chạy script trên schema phát triển hoặc production. Ca đặt mới làm thay đổi tồn và giá của bộ demo, nên dùng môi trường/schema thử mới cho lần nghiệm thu độc lập. Phục hồi yêu cầu schema và thư mục ảnh trống.

Các lệnh kiểm thử mã chạy được từ checkout mới sau khi cài theo README.

```powershell
npm.cmd ci
npm.cmd run lint
npm.cmd run build
php vendor/bin/pint --test
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
php vendor/bin/phpunit --do-not-cache-result
# Chọn schema MySQL mới kết thúc bằng _test và đặt credential qua môi trường.
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'tiembanh_acceptance_phpunit_test'
php vendor/bin/phpunit --do-not-cache-result --fail-on-skipped
```

Ở workspace này, PHPUnit dùng schema `tiembanh_stage7_phpunit_test` trên MySQL loopback 33317, tách schema ca bán. Dùng `PHPRC=work/php-dev/php.ini` trên Windows như README.

Để đọc lại ca local hiện có, nạp profile bằng `. .\work\acceptance-20261123\profile.ps1`, dùng `php work/acceptance-20261123/control.php counts` để đối chiếu trạng thái, `php artisan bakery:backup --verify=DUONG_DAN_BAN_SAO` để kiểm băm và xem các tệp đã bỏ bí mật trong hồ sơ. Script `acceptance.cjs` chạy các ca S/F/M trên bộ demo mới. Script `drill.ps1` chạy các ca O với hai gói có sẵn và đích phục hồi trống. `verify-final.cjs` thử khôi phục mật khẩu đầy đủ và đọc lại đơn, `verify-restore.ps1` đối chiếu snapshot và trạng thái tạm ngừng, `verify-code.ps1` ghi lại các kiểm tra mã. Khi kiểm tra thư, chọn thư mới nhất theo thời gian ghi và giải mã quoted-printable trước khi lấy liên kết. Các bước UI U01–U03 thực hiện lại bằng trình duyệt tại URL local nói trên, theo quy trình bán và xử lý đơn ở README.

## Phân công ca bán đầu và quyết định

| Trách nhiệm | Người được chỉ định | Việc phải nhận bàn giao |
| --- | --- | --- |
| Duyệt mở nhận đơn | CHỜ chủ tiệm ghi tên | Chốt dữ liệu, chính sách, kết quả nghiệm thu và thời điểm được bật nhận đơn. |
| Xử lý đơn, làm và giao bánh | CHỜ trưởng ca ghi tên chính và thay thế | Hàng chờ, lịch làm bánh, giới hạn nhận đơn và xử lý quá tải. |
| Thu COD và đối soát chuyển khoản, hoàn tiền | CHỜ người thu/người đối soát | Xác minh số tiền, mã đơn, quyền hạn và đối chiếu cuối ca. |
| Hỗ trợ khách | CHỜ người hỗ trợ và kênh liên hệ | Tra cứu, gửi lại liên kết, yêu cầu đổi/hủy, sự cố giao và thư không tới. |
| Hạ tầng và sự cố | CHỜ người trực và người thay thế | Giám sát ngoài máy, backup ngoài máy, retry thư, pause, phục hồi và quay lui. |

Trước khi quyết định sẵn sàng, người phụ trách điền tên, kết quả và bằng chứng thật cho từng dòng CHỜ. Giữ nhận đơn thật tạm ngừng tới khi người có trách nhiệm phê duyệt ngoài ca thử. Chưa có phê duyệt mở bán trong biên bản này.
