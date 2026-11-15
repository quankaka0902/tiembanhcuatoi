# Tiệm Bánh Nhà Mình

Ứng dụng đặt bánh và quản trị cửa hàng bằng tiếng Việt. Giá dùng số nguyên VND. Lịch, bộ lọc ngày và thời điểm tạo đơn dùng múi giờ Asia/Ho_Chi_Minh.

## Cài đặt

- PHP 8.3 trở lên với các extension Laravel và PHPUnit, gồm PDO MySQL, Mbstring, OpenSSL, Fileinfo, XML, Zip và GD. Bật PDO SQLite nếu muốn chạy bộ test mặc định bằng SQLite.
- Composer 2, Node.js 22.12+ hoặc 24+, npm và MySQL 8.4. Dự án dùng Laravel 13, React 19, Inertia 2, Tailwind CSS 4 và Vite 8. Hai tệp lock cố định phiên bản cài đặt.
- Để tải tối đa năm ảnh cùng lúc, đặt `upload_max_filesize` ít nhất `4M` và `post_max_size` ít nhất `24M` trong cấu hình PHP dùng để phục vụ ứng dụng.

Tạo cơ sở dữ liệu phát triển bằng tài khoản quản lý MySQL. Đổi mật khẩu ví dụ trước khi chạy.

```sql
CREATE DATABASE tiembanh_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'tiembanh'@'localhost' IDENTIFIED BY 'local-change-me';
GRANT ALL ON tiembanh_dev.* TO 'tiembanh'@'localhost';
```

```powershell
composer install
Copy-Item .env.example .env
npm ci
php artisan key:generate
```

Điền `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` và `DB_PASSWORD` trong `.env`. MySQL trong workspace đã thiết lập dùng cổng `33316`. Giữ `MAIL_MAILER=log` để kiểm tra mail local. Để nạp dữ liệu mẫu, đặt `APP_ENV=local` và `BAKERY_DEMO_SEED=true`, rồi chạy trên cơ sở dữ liệu trống.

```powershell
php artisan migrate --seed
npm run build
php artisan serve --host=127.0.0.1 --port=18842
```

- Cửa hàng ở [http://127.0.0.1:18842](http://127.0.0.1:18842).
- Quản trị ở [http://127.0.0.1:18842/quan-tri](http://127.0.0.1:18842/quan-tri).
- Có thể chạy `npm run dev` trong terminal thứ hai khi sửa giao diện. Nếu đổi cổng Laravel, cập nhật `APP_URL` cho khớp.
- Composer CLI đang được lưu ở `work/composer.phar` trong workspace đã thiết lập. Nếu máy chưa có lệnh `composer`, có thể dùng `php work/composer.phar install` tại đây.

Trong workspace Windows đã thiết lập, có thể khởi động lại MySQL riêng bằng khối lệnh dưới đây. Chạy Laravel ở terminal thứ hai. Thư mục dữ liệu hiện có sẽ được dùng tiếp.

```powershell
$mysqlDir = (Resolve-Path work/mysql-8.4.13-winx64).Path
$dataDir = (Resolve-Path work/mysql-dev).Path
& "$mysqlDir/bin/mysqld.exe" --no-defaults "--basedir=$mysqlDir" "--datadir=$dataDir" --port=33316 --bind-address=127.0.0.1 --mysqlx=OFF --console
```

```powershell
$env:PHPRC = (Resolve-Path work/php-dev/php.ini).Path
php artisan serve --host=127.0.0.1 --port=18842 --no-reload
```

## Dữ liệu mẫu và luồng thao tác

Tất cả tài khoản mẫu dùng mật khẩu `BanhDemo2026!` và chỉ được tạo khi seed trong môi trường local/test.

| Tài khoản | Vai trò |
| --- | --- |
| `admin@tiembanh.test` | Toàn quyền quản trị, gồm cấu hình, sản phẩm, mã giảm, nhân sự và đối soát |
| `nhanvien@tiembanh.test` | Xử lý đơn, thu COD, xem tổng quan và xuất CSV |
| `doisoat@tiembanh.test` | Quyền nhân viên, đối soát chuyển khoản và ghi nhận hoàn tiền |
| `khach@tiembanh.test` | Tài khoản khách và lịch sử các đơn mẫu |

1. Chọn bánh, phiên bản, số lượng và lời chúc nếu có. Mở giỏ để cập nhật món hoặc tiếp tục đặt bánh.
2. Chọn giao hàng hoặc nhận tại tiệm và chọn giờ. Dùng thử `NHAMINH10` cho đơn từ 200.000 ₫ hoặc `NGOT30000` cho đơn từ 300.000 ₫.
3. Đặt thử COD hoặc chuyển khoản mẫu. Lưu liên kết tra cứu từ trang xác nhận hoặc lấy trong `storage/logs/laravel.log`. Yêu cầu quên mật khẩu cũng ghi email vào tệp này.
4. Trong quản trị, tìm theo mã đơn, xác nhận, chuẩn bị rồi chuyển sang sẵn sàng nhận hoặc đang giao. Nhân viên ghi nhận COD sau khi giao/nhận. Người có quyền đối soát kiểm tra chuyển khoản và ghi nhận thanh toán. Sau khi đã thanh toán, đơn mới có thể chuyển sang hoàn tất.

Giờ nhận được tính trong 14 ngày tới từ lịch cửa hàng và thời gian chuẩn bị dài nhất trong giỏ. Ngưỡng miễn phí giao tính trên tiền bánh sau giảm giá. Một lần hủy hợp lệ trả lại tồn và lượt dùng mã. Nếu đơn đã thanh toán, nhân sự có quyền ghi nhận hoàn tiền sau khi hoàn trả bên ngoài ứng dụng. Doanh thu tính theo ngày hoàn tất của đơn đã thanh toán. Đơn khách vãng lai được tra cứu bằng liên kết riêng. Lịch sử tài khoản gồm những đơn đặt khi đã đăng nhập.

## Kiểm thử

Chạy từ thư mục repo sau khi build frontend và tạo khóa ứng dụng.

```powershell
npm run lint
npm run build
php vendor/bin/pint --test
php artisan test
```

Lệnh test mặc định dùng SQLite trong bộ nhớ. Ba bài kiểm tra hai tiến trình được chạy khi chọn MySQL. Tạo schema mới có hậu tố `_test`, ví dụ `tiembanh_test`, và cấp quyền cho tài khoản kiểm thử. Bộ test tạo lại schema được chỉ định và chặn cấu hình trỏ đến cơ sở dữ liệu phát triển.

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'tiembanh_test'
$env:DB_PORT = '33316' # Sửa theo cổng MySQL của máy
php artisan test
Remove-Item Env:DB_CONNECTION, Env:DB_DATABASE, Env:DB_PORT
```

Dùng `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` trong môi trường hoặc trong `.env.testing` nếu thông tin riêng khác `.env`. Bài test HTTP bao phủ giá bị gửi sai từ phía khách, tồn kho, hạn mức mã giảm, gửi đơn lặp, giờ nhận, hoàn kho khi hủy, quyền xem đơn và đối soát, lọc CSV, email khôi phục và ảnh tải lên. Các bài test MySQL dùng hai tiến trình để tranh tồn cuối, lượt mã cuối và gửi cùng khóa yêu cầu.

Đã chạy migration và seed trên MySQL dev, lint PHP/JS và build Vite. MySQL test đạt 13/13 bài với 243 assertions. SQLite đạt 10 bài với 222 assertions. Ba bài khóa dữ liệu chạy riêng trên MySQL. Đã kiểm tra trình duyệt với viewport 1440 × 1000 và 390 × 844. Đơn COD nhận tại tiệm và đơn chuyển khoản giao tận nơi đều đã được tạo, xử lý và tra cứu lại ở trạng thái hoàn tất. Email đặt bánh và khôi phục mật khẩu đã xuất hiện trong mailer log. SMTP thật và tiền chuyển khoản thật cần cấu hình cửa hàng để xác minh.

## Cấu hình cửa hàng thật

- Tạo cơ sở dữ liệu trống, cấu hình `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS, `SESSION_SECURE_COOKIE=true`, `BAKERY_DEMO_SEED=false`, khóa ứng dụng và tài khoản DB riêng.
- Cấu hình thư SMTP và địa chỉ gửi qua biến môi trường. Giới hạn truy cập nhật ký theo chính sách cửa hàng vì liên kết tra cứu và đặt lại mật khẩu là thông tin riêng.
- Chạy `php artisan migrate --force`, `php artisan bakery:admin` và build frontend. Đặt document root máy chủ ở `public/`. Cấp quyền ghi cho `storage/` và `bootstrap/cache/`.
- Đăng nhập quản trị để thiết lập tên, logo, liên hệ, lịch nhận, ngày nghỉ, khu vực và phí giao, giá và tồn thực tế. Điền thông tin ngân hàng thật và tắt cảnh báo mẫu nếu sử dụng chuyển khoản thủ công.
- Sao lưu MySQL và thư mục ảnh `storage/app/public/bakery`. Đơn đã ghi nhận giữ bản chụp tên, giá và các tùy chọn tại thời điểm đặt.

## Git và ảnh minh họa

`origin` trỏ đến [quankaka0902/tiembanhcuatoi](https://github.com/quankaka0902/tiembanhcuatoi). Nhánh dự án dùng `main`. Khi muốn đưa mã nguồn lên GitHub, chạy `git push -u origin main`. `.env`, cơ sở dữ liệu, các tệp tải lên và thư mục làm việc đã được loại khỏi Git.

Ảnh bánh kem dâu trong `public/images/strawberry.webp` do ImageGen tạo theo mô tả ảnh chụp bánh kem dâu thủ công trên đĩa sứ, nền hồng, ánh sáng cửa sổ và không có chữ. Các ảnh minh họa mẫu còn lại lấy từ [Unsplash](https://unsplash.com/) và [Pexels](https://www.pexels.com/). Giấy phép phông DM Sans và Playfair Display nằm trong `public/fonts/`.
