# Tiệm Bánh Nhà Mình

Ứng dụng đặt bánh và quản trị cửa hàng bằng tiếng Việt. Giá dùng số nguyên VND. Lịch, bộ lọc ngày và thời điểm tạo đơn dùng múi giờ Asia/Ho_Chi_Minh.

Trang công khai có sẵn HTML từ PHP, gồm nội dung chính, liên kết và metadata. React/Inertia tiếp tục xử lý tương tác; Node SSR là tùy chọn và có PHP fallback khi tắt hoặc lỗi. Giỏ, checkout, tài khoản, đơn riêng và quản trị giữ luồng bảo vệ hiện có.

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

Chạy worker trong terminal riêng để gửi email báo nhận đơn, email trạng thái và gửi lại liên kết tra cứu. Nếu dùng cấu hình PHP riêng trên Windows, đặt `PHPRC` như ở ví dụ khởi động Laravel bên dưới. Với cấu hình local, email có trong `storage/logs/laravel.log`.

```powershell
php artisan queue:work order-mail --sleep=2
```

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

Tài khoản nội bộ cần khóa TOTP. Sau khi chạy migration, dùng `php artisan bakery:mfa admin@tiembanh.test` để cấp khóa riêng cho quản trị viên mẫu và làm tương tự cho từng nhân viên cần kiểm thử. Nhập khóa vào ứng dụng xác thực theo chế độ dựa trên thời gian, 6 số, chu kỳ 30 giây. [Hướng dẫn cấp và thay khóa](docs/van-hanh.md#xác-thực-hai-bước-cho-tài-khoản-nội-bộ).

1. Chọn bánh, cỡ / phiên bản, số lượng, lời chúc và phụ kiện được tiệm duyệt nếu có. Mở giỏ để cập nhật món hoặc tiếp tục đặt bánh.
2. Chọn giao hàng hoặc nhận tại tiệm và chọn giờ. Dùng thử `NHAMINH10` cho đơn từ 200.000 ₫ hoặc `NGOT30000` cho đơn từ 300.000 ₫.
3. Đặt thử COD hoặc chuyển khoản mẫu. Lưu liên kết tra cứu từ trang xác nhận hoặc lấy trong `storage/logs/laravel.log`. Yêu cầu quên mật khẩu cũng ghi email vào tệp này.
4. Trong quản trị, tìm theo mã đơn, xác nhận, chuẩn bị rồi chuyển sang sẵn sàng nhận hoặc đang giao. Nhân viên ghi nhận COD sau khi giao/nhận. Người có quyền đối soát kiểm tra chuyển khoản và ghi nhận thanh toán. Sau khi đã thanh toán, đơn mới có thể chuyển sang hoàn tất.

Nhân viên mở **Lịch làm bánh** trong quản trị để xem đơn theo ngày và giờ giao hoặc nhận. Mỗi khung tổng hợp số bánh theo phiên bản và hiển thị lời chúc, ghi chú của từng đơn. Trong **Cấu hình → Lịch nhận bánh**, quản trị viên có thể điền số đơn tối đa cho từng giờ. Giới hạn áp dụng riêng cho từng ngày và tính chung cả giao tận nơi lẫn nhận tại tiệm. Để trống ô giới hạn khi muốn nhận không giới hạn. Khi đơn bị hủy, số đơn đã đặt trong khung giảm một.

Nhân viên mở **Đối soát** để xem đơn chuyển khoản chờ thanh toán hoặc lọc các đơn quá hạn. Hạn mặc định là 60 phút từ khi đặt và tối đa đến giờ giao hoặc nhận đã hẹn. Đổi thời hạn cho đơn mới trong **Cấu hình → Chuyển khoản thủ công**. Đơn phát sinh trước migration được tính hạn theo mốc mặc định. Đơn quá hạn vẫn chờ người có quyền đối soát kiểm tra và cập nhật thủ công.

Hệ thống đưa email nhận đơn, thay đổi trạng thái, đổi giờ, hủy, thanh toán/hoàn và kết quả yêu cầu khách vào hàng đợi. Thông báo giữ nội dung tại thời điểm sự kiện và chống lặp theo sự kiện. Khách mở **Tra cứu**, nhập mã đơn và email đã đặt để xin lại liên kết xem đơn. Liên kết này tiếp tục chỉ có quyền xem.

**Đơn cần xử lý** gom đơn chưa xác nhận, chuyển khoản quá hạn, chờ hoàn, yêu cầu khách, chưa giao/nhận và bánh cần kiểm tra sau hủy. Nhân viên đổi giờ trước mốc chuẩn bị, quản lý xử lý ngoại lệ. Khách vẫn tra cứu và gửi yêu cầu đủ điều kiện khi tiệm tạm ngừng. Chỉnh chính sách đặt bánh và thông báo xử lý dữ liệu trong **Cấu hình**.

Khách đã đăng nhập mở **Đơn của tôi**, chọn đơn cũ rồi bấm **Mua lại bánh**. Giỏ giữ các món đã có và thêm số bánh còn bán theo giá hiện tại. Nếu chỉ đủ một phần, hệ thống báo số bánh đã thêm và số bánh chưa thêm được. Khách nhập lời chúc, ghi chú và chọn lại phụ kiện cho đơn mới trong giỏ. Đơn mới giữ tồn khi được đặt thành công.

Quản trị viên mở **Nhật ký tồn kho** để xem tồn trước và sau, nguyên nhân, người thao tác và mã đơn liên quan. Có thể lọc theo loại thay đổi hoặc mở nhật ký riêng từ trang sản phẩm. Khi đổi số lượng tồn trong trang sản phẩm, có thể ghi lý do. Nhật ký bắt đầu ghi các thay đổi phát sinh sau migration, gồm tồn khi tạo phiên bản, điều chỉnh thủ công, giữ tồn và hoàn tồn.

Nhân viên mở **Báo cáo** để chọn kỳ và xem doanh thu theo ngày cùng 10 bánh bán chạy theo số lượng. Báo cáo tính các đơn hoàn tất đã thanh toán theo ngày hoàn tất ở giờ Việt Nam. Doanh thu là tổng tiền sau giảm giá, gồm phí giao hàng. Mặc định xem từ đầu tháng đến hôm nay.

Giờ nhận được tính trong 14 ngày tới từ lịch cửa hàng và thời gian chuẩn bị dài nhất trong giỏ, được chụp lại trên đơn mới. Hủy trước làm giải phóng phần giữ một lần; trả lượt mã theo cấu hình riêng. Hủy sau làm cần quản lý, không tự tăng tồn bán, sau đó phân loại phần chưa làm/còn bán được/tiêu hao. Đã xuất giao thì không được ghi là chưa làm. Đơn đã trả rồi hủy chờ người có quyền hoàn bên ngoài và nhập bằng chứng. Doanh thu tính từ đơn hoàn tất đã thanh toán.

## Chọn bánh, bộ lọc và xác minh menu

Trang chủ dẫn tới thực đơn, gợi ý dịp từ dữ liệu đã xác minh và hiển thị cơ sở đang phục vụ cùng vùng / phí giao đã cấu hình. Thực đơn hỗ trợ tìm tiếng Việt có hoặc không dấu, lọc danh mục, dịp, hương vị, số người, khoảng giá, còn / hết hàng, sắp xếp và phân trang. Bộ lọc nằm trong URL để giữ khi chia sẻ, phân trang, mở chi tiết hoặc quay lại. Khẩu phần, giá và tồn phải khớp cùng một phiên bản. Giá “Từ” ưu tiên lựa chọn còn tồn trong bộ lọc; từng cỡ hiển thị giá và tình trạng rõ ở trang bánh.

Quản trị viên vào **Sản phẩm** để cập nhật dịp, hương vị, nhãn cỡ, khẩu phần, bảo quản và phụ kiện. Xác minh thông tin chi tiết, duyệt bộ ảnh và xác minh khẩu phần từng cỡ là các dấu riêng. Dữ liệu thiếu được ghi rõ chưa xác minh, không suy số người từ tên bánh hay đường kính. Hero chỉ dùng ảnh đã được tiệm duyệt; nếu chưa có sẽ hiển thị placeholder. Ảnh minh họa ở thẻ / chi tiết có chú thích và ảnh lỗi có trạng thái thay thế.

Mỗi loại phụ kiện được chọn tính một đơn vị cho mỗi bánh. Máy chủ kiểm tra lại phụ kiện thuộc đúng sản phẩm, còn hoạt động, đã duyệt và lấy giá hiện hành; đơn giữ bản chụp tên / giá phụ kiện tại lúc mua. Phí lời chúc và phụ kiện thuộc tạm tính dùng để áp dụng mã giảm và xét miễn phí giao sau giảm giá. Bộ lọc giá chỉ tính giá bánh theo cỡ. Tiệm cần chốt quy tắc này và cách theo dõi cung cấp phụ kiện trước khi bán thật.

Xem [hợp đồng dữ liệu, ma trận giao diện và cách nghiệm thu](docs/storefront-mobile.md). Repo hiện chưa có bộ ảnh thật hay bảng khẩu phần, gồm lựa chọn cho 8 người, đã được chủ tiệm xác nhận. Fixture local / test chỉ chứng minh hành vi kỹ thuật. Nghiệm thu bằng thiết bị, trình đọc màn hình và dữ liệu thật được ghi riêng trong hồ sơ, không suy từ kết quả lint / build.

Hai migration ngày 24/11/2026 thêm metadata, phụ kiện, snapshot dòng đơn và nội dung tìm kiếm chuẩn hóa. Khi nâng cấp môi trường đã có dữ liệu, sao lưu, kiểm tra đúng schema môi trường riêng rồi chạy các lệnh dưới đây. Các dấu xác minh của dữ liệu cũ mặc định tắt, phí phụ kiện của đơn cũ bằng 0; tên, giá, tồn và lịch sử đơn được giữ.

```powershell
php artisan migrate
npm run build
php artisan queue:restart
```

Trình quản lý tiến trình cần khởi động lại worker sau lệnh restart. Chỉ nạp seed demo trên schema local / test trống theo phần cài đặt. Sau migration, chạy lại ca chọn / lọc / đặt bánh với dữ liệu được phép trên schema riêng, đối chiếu giá giỏ và snapshot đơn; giữ công tắc nhận đơn thật đóng đến khi người phụ trách duyệt.

## HTML công khai, SEO và ảnh

Xem [hướng dẫn SEO, render HTML, ảnh và nội dung chờ duyệt](docs/seo-toc-do.md). Trang danh mục có URL `/thuc-don/{slug}`; trang giới thiệu, liên hệ, FAQ và một cơ sở lần lượt là `/gioi-thieu`, `/lien-he`, `/cau-hoi-thuong-gap`, `/cua-hang`. Cấu hình cửa hàng quản lý nội dung và các dấu duyệt, thông tin cơ sở, bản đồ và giờ mở cửa có cấu trúc. Repo chưa có dữ liệu thật được chủ tiệm duyệt cho những mục này.

Title, description, canonical, Open Graph và JSON-LD xuất hiện ngay trong HTML. Canonical dùng `APP_URL`, phân trang sạch có URL riêng; trang tìm kiếm/lọc không được lập chỉ mục. Lịch sử slug sản phẩm và danh mục giữ chuyển hướng 301 khi đổi đường dẫn, không tự đổi slug khi chỉ đổi tên. Sitemap chỉ chứa URL công khai đủ điều kiện, không chứa giỏ, tài khoản, tra cứu hay liên kết đơn.

`BAKERY_SEO_INDEXING_ENABLED=false` là mặc định. Local, test, staging, rehearsal và demo không được lập chỉ mục. Bật cờ này chưa đủ để lập chỉ mục nếu chưa đúng môi trường production, HTTPS và các dấu duyệt dữ liệu. Cờ SEO không bật nhận đơn. `/robots.txt` hiện do Laravel tạo theo cấu hình; không thêm lại tệp robots tĩnh hoặc để web server/CDN trả bản cũ làm mất quy tắc đóng của staging.

Hai migration ngày 25/11/2026 thêm lịch sử slug và metadata ảnh nhiều cỡ, giữ URL/bản gốc ảnh cũ. Sau khi sao lưu và chọn đúng schema riêng, chạy migration và tạo các dẫn xuất cần thiết. Lệnh ảnh không duyệt nội dung hoặc quyền dùng ảnh thay chủ tiệm.

```powershell
php artisan migrate
php artisan bakery:images --missing
npm run build
php artisan queue:restart
```

Ảnh mới tải lên được tạo WebP nhiều cỡ bằng GD; `--missing` dùng cho ảnh đã có hoặc sau phục hồi. Không resize khi đọc trang. Ảnh gốc tiếp tục được sao lưu cùng DB và các dẫn xuất. Xem hướng dẫn để kiểm tra ảnh hỏng/thiếu và cache của staging. HTML/Inertia có dữ liệu phiên nên luôn `private, no-store`; không đặt cache chia sẻ cho trang theo URL hoặc User-Agent.

Node SSR tùy chọn, mặc định tắt. Build client và SSR từ cùng bản mã; kiểm tra smoke trước khi khởi động renderer riêng trên loopback.

```powershell
npm run test:ssr
# Sau khi đặt BAKERY_SSR_ENABLED=true và BAKERY_SSR_URL=http://127.0.0.1:13714
# trong cấu hình PHP của môi trường riêng, nạp lại tiến trình PHP.
$env:BAKERY_SSR_PORT = '13714'
node bootstrap/ssr/ssr.js
```

`test:ssr` chạy `build:ssr` và smoke test rồi kết thúc. Không dùng lệnh khởi động SSR vendor cho renderer này và không expose cổng Node ra public. Khi Node tắt/lỗi, PHP vẫn trả nội dung công khai. [Tài liệu SEO](docs/seo-toc-do.md#node-ssr-tùy-chọn) có cấu hình, giới hạn dữ liệu gửi sang Node và cách đối chiếu HTML; không coi kết quả SSR local là phê duyệt mở bán hoặc xác nhận tốc độ trên thiết bị thật.

## Đổi lịch, hủy và xử lý sau bán

Xem [bảng trạng thái, ma trận quyền và hướng dẫn ca bán](docs/vong-doi-don.md). Khách đăng nhập gửi yêu cầu cho đơn của mình. Khách vãng lai xác minh bằng liên kết thao tác riêng qua email, hạn 15 phút và dùng một lần. Yêu cầu chỉ đổi đơn sau khi nhân viên chấp thuận. Máy chủ kiểm tra lại giờ cũ, lịch, công suất và thời gian chuẩn bị trong transaction.

Trong **Cấu hình**, chuyển khoản mặc định yêu cầu trả trước, ngoại lệ quản lý mặc định tắt. Khi yêu cầu trả trước còn hiệu lực, đơn chưa thu đủ bị chặn vào bếp và giao. Đơn cũ thiếu bản chụp lead time cần quản lý xác minh. Ghi đủ tham chiếu và số tiền, xác nhận đã đối chiếu chứng từ trước khi ghi hoàn. Ứng dụng không thực hiện chuyển tiền ngân hàng.

Khi cập nhật môi trường đã có, sao lưu trước, kiểm tra đúng schema rồi chạy `php artisan migrate`, `npm run build` và `php artisan queue:restart`; trình quản lý tiến trình phải khởi động lại worker. Ba migration mới không phát lại các lần hủy cũ. Không quay về mã cũ không hiểu trạng thái mới sau khi đã phát sinh dữ liệu vòng đời mới. Chưa áp dụng migration này lên cơ sở dữ liệu bán thật.

Lần kiểm tra 23/11/2026 đạt Pint, ESLint, build; MySQL 68/68 bài với 1.365 assertions, SQLite 61 bài đạt với 1.308 assertions và 7 ca MySQL được bỏ qua. Gồm hai tiến trình tranh khung cuối và gửi trùng yêu cầu. Giao diện thử ở 390×844 và 1366×900 trên HTTP loopback/schema riêng. SMTP và dữ liệu tiệm thật cần nghiệm thu riêng.

## Nhiều cơ sở

Menu, ảnh và thương hiệu dùng chung; mỗi cơ sở có giá ghi đè, tồn, thời gian chuẩn bị, lịch/công suất, vùng giao, thanh toán và công tắc nhận đơn riêng. Đổi cơ sở khi có giỏ cần xác nhận xóa giỏ. Quản trị chọn cơ sở theo phân công quản lý, nhân viên, bếp hoặc kế toán; chỉ quản trị chuỗi được đổi menu chung và xem tổng hợp toàn chuỗi.

Migration giữ chế độ đọc một cơ sở. Trước khi chuyển, đọc [sao lưu, backfill, verify, activate và quay lại chế độ đọc cũ](docs/multi-branch.md); không sửa cờ DB thủ công. Activate và tạo cơ sở mới đều giữ nhận đơn đóng. Mã giảm chuỗi dùng bộ đếm chung, mã chữ vẫn duy nhất toàn hệ thống. Giai đoạn này chỉ hỗ trợ múi giờ Việt Nam và giữ nhiều cơ sở ở trạng thái `noindex` khi chưa có URL riêng.

Xem [kiến trúc, phạm vi dữ liệu, ma trận quyền và thứ tự khóa](docs/branches-architecture.md). Trang bổ sung gồm **Cơ sở và phân công**, **Thực đơn cơ sở**, phạm vi tại **Tổng quan**, **Báo cáo**, **Mã giảm giá**. Đổi quyền nội bộ yêu cầu đăng nhập/TOTP lại. Dữ liệu thật và mở nhận phải được nghiệm thu riêng từng cơ sở; phần này không tự triển khai public.

## Kiểm thử

Chạy từ thư mục repo sau khi build frontend và tạo khóa ứng dụng.

```powershell
npm run lint
npm run build
php vendor/bin/pint --test
php artisan test
```

Lệnh test mặc định dùng SQLite trong bộ nhớ. Các bài kiểm tra hai tiến trình được chạy khi chọn MySQL. Tạo schema mới có hậu tố `_test`, ví dụ `tiembanh_test`, và cấp quyền cho tài khoản kiểm thử. Bộ test tạo lại schema được chỉ định và chặn cấu hình trỏ đến cơ sở dữ liệu phát triển.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'tiembanh_test'
$env:DB_PORT = '33316' # Sửa theo cổng MySQL của máy
$env:DB_URL = ''
php vendor/bin/phpunit --do-not-cache-result --fail-on-skipped
Remove-Item Env:APP_ENV, Env:DB_CONNECTION, Env:DB_DATABASE, Env:DB_PORT, Env:DB_URL
```

Dùng `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` trong môi trường hoặc trong `.env.testing` nếu thông tin riêng khác `.env`. Bài test HTTP bao phủ tính giá, tồn kho, hạn mức mã giảm, gửi đơn lặp, giờ nhận, hoàn kho, quyền xem đơn và đối soát, lọc CSV, email khôi phục và ảnh tải lên. Bộ test còn kiểm tra hạn đối soát, gửi lại email lỗi, quyền mua lại, nhật ký tồn, báo cáo, TOTP, điều kiện mở nhận đơn, quyền đổi giờ và đơn chờ hoàn tiền. MySQL kiểm tra hai tiến trình tranh tồn cuối, lượt mã cuối, khung giờ cuối, cùng khóa yêu cầu và thao tác đặt đơn tranh chỗ với thao tác đổi giờ.

Các ca menu có cấu trúc kiểm tra quyền sửa metadata, các dấu xác minh độc lập, khoảng khẩu phần, tìm không dấu, bộ lọc giá / số người / tồn khớp cùng phiên bản và phụ kiện. Ca giá đối chiếu việc máy chủ tính lại, thay giá phụ kiện khi checkout còn mở, phụ kiện ngừng cung cấp và snapshot đơn sau khi menu đổi. Ca trình duyệt 320, 390, 768 và 1440 px cùng bàn phím / trạng thái biên được ghi ở [hồ sơ giao diện](docs/storefront-mobile.md); kết quả hiện tại cần đối chiếu đúng bản mã và môi trường ghi trong hồ sơ.

Đã chạy lint PHP/JS và build Vite. MySQL đạt 25 bài với 767 assertions. SQLite đạt 21 bài với 738 assertions và bỏ qua bốn bài kiểm tra đồng thời dành cho MySQL. Chặng 4 dùng schema MySQL staging riêng. Trên trình duyệt đã kiểm tra đặt COD ở 390 px, tạm ngừng nhận đơn từ checkout cũ, hoàn tất COD, đối soát chuyển khoản mẫu và tra cứu. Email báo nhận đơn được kiểm tra bằng mailer log khi worker khởi động lại. Đã phục hồi 9 đơn và ảnh sản phẩm mẫu vào schema và thư mục ảnh riêng, đối chiếu mã băm ảnh và xem lại đơn trên trình duyệt. SMTP thật và tiền chuyển khoản thật cần cấu hình cửa hàng để xác minh.

Chặng 5 đạt 29 bài trên MySQL với 875 assertions và 24 bài trên SQLite với 840 assertions. Nghiệm thu qua HTTPS trên loopback dùng chứng chỉ thử được máy khách kiểm tra và MySQL staging riêng. Đã kiểm tra cookie Secure, trạng thái tạm ngừng ban đầu, đăng nhập TOTP, đổi giờ, tra cứu và danh sách chờ hoàn tiền. Hộp thư SMTP trên loopback nhận thư báo đơn, thư trạng thái và thư đặt lại mật khẩu. Liên kết đặt lại mật khẩu mở đúng trên HTTPS staging. Tên miền và SMTP của cửa hàng cần được xác minh bằng cấu hình thật.

Chặng 6 đạt 32 bài trên MySQL với 894 assertions và 27 bài trên SQLite với 859 assertions. Ca bán staging riêng đã hoàn tất một đơn COD và một đơn chuyển khoản giả lập, cùng thư qua SMTP local. Đã chạy sao lưu theo lịch, xác minh SHA-256 ở cả hai thư mục và phục hồi hai đơn cùng ảnh vào schema và thư mục ảnh riêng. Hộp nhận thử đã ghi cảnh báo và thông báo phục hồi khi worker ngừng phản hồi, bản sao quá hạn và bản phát hành trả HTTP 503. Quay lui trả web về HTTP 200 và giữ cả hai đơn. Các đầu vào cần cấu hình của tiệm được liệt kê trong hướng dẫn vận hành.

Chặng 7 thêm chế độ staging kín có tên miền HTTPS riêng và chỉ gửi thư tới các địa chỉ thử được duyệt. MySQL đạt 35 bài với 914 assertions. SQLite đạt 30 bài với 879 assertions. Xem [biên bản nghiệm thu chặng 7](docs/nghiem-thu-chang-7.md) để biết kết quả ca thử, phục hồi, quay lui và các dịch vụ còn chờ cấu hình của tiệm.

Chặng 8 yêu cầu khách xác nhận lại khi báo giá đổi và bổ sung thử lại khi mất kết nối lúc tính tiền. Checkout cho chọn ngày và giờ riêng. Hai màn hình **Đơn cần xử lý** và **Đối soát** dùng thẻ trên điện thoại. Xem [biên bản chặng 8](docs/nghiem-thu-chang-8.md) trước khi nghiệm thu bằng menu và dịch vụ thật của tiệm.

GitHub chạy [Bakery CI](https://github.com/quankaka0902/tiembanhcuatoi/actions/workflows/ci.yml) khi push lên `main` hoặc mở pull request. Workflow được cấu hình chạy lint, build client, build/smoke SSR qua `npm run test:ssr` và PHPUnit trên SQLite cùng MySQL độc lập. Kết quả cần đối chiếu đúng commit của lần chạy. Các bước chốt phiên bản, chạy ca thử và bàn giao nằm trong [hồ sơ vận hành chặng 9–11](docs/ban-giao-van-hanh.md).

Ca nghiệm thu một cơ sở ngày 23/11/2026 được ghi tại [biên bản mở bán](docs/nghiem-thu-mo-ban-mot-co-so.md), kèm ảnh và kết quả đã loại bí mật. Bản sửa local `5de11c0` bổ sung kiểm tra cookie, SMTP host và khả năng phát hiện sự cố sao lưu, thư lỗi. Trạng thái vẫn **chờ mở bán** vì chưa có dữ liệu, nhân sự và dịch vụ thật được tiệm xác nhận. Sandbox được tạm ngừng nhận đơn sau ca thử.

## Cấu hình cửa hàng thật

Xem [hướng dẫn vận hành, kiểm tra cấu hình và phục hồi bản sao lưu](docs/van-hanh.md).

- Tạo cơ sở dữ liệu trống, cấu hình `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS, `SESSION_SECURE_COOKIE=true`, `BAKERY_DEMO_SEED=false`, khóa ứng dụng và tài khoản DB riêng.
- Cấu hình thư SMTP và địa chỉ gửi qua biến môi trường. Dùng trình quản lý tiến trình để duy trì `php artisan queue:work order-mail --sleep=2`. Kiểm tra thư gửi thất bại bằng `php artisan queue:failed`. Giới hạn truy cập nhật ký vì liên kết tra cứu và đặt lại mật khẩu là thông tin riêng.
- Chạy `php artisan migrate --force`, `php artisan bakery:admin`, `php artisan bakery:mfa EMAIL` và build frontend. Đặt document root máy chủ ở `public/`. Cấp quyền ghi cho `storage/` và `bootstrap/cache/`.
- Đăng nhập quản trị để thiết lập tên, logo, liên hệ, lịch nhận, ngày nghỉ, khu vực và phí giao, giá và tồn thực tế. Điền thông tin ngân hàng thật và tắt cảnh báo mẫu nếu sử dụng chuyển khoản thủ công.
- Sao lưu MySQL và thư mục ảnh `storage/app/public/bakery`. Đơn đã ghi nhận giữ bản chụp tên, giá và các tùy chọn tại thời điểm đặt.
- Môi trường thật mới dựng bắt đầu ở trạng thái tạm ngừng nhận đơn. Chạy `php artisan bakery:preflight` và dùng **Đơn cần xử lý** để chủ động bật nhận đơn khi cấu hình đã sẵn sàng.
- Dùng `php artisan bakery:monitor` để kiểm tra vận hành, `php artisan bakery:backup` để tạo bản sao và `php artisan bakery:backup --verify=THU_MUC` để đối chiếu. Bật lịch và khai báo nơi lưu, kênh cảnh báo theo hướng dẫn vận hành. Dùng `ops/release.php` để kiểm tra, chọn hoặc quay về phiên bản ứng dụng đã chuẩn bị.

## Git và ảnh minh họa

`origin` trỏ đến [quankaka0902/tiembanhcuatoi](https://github.com/quankaka0902/tiembanhcuatoi). Nhánh dự án dùng `main`. Khi muốn đưa mã nguồn lên GitHub, chạy `git push -u origin main`. `.env`, cơ sở dữ liệu, các tệp tải lên và thư mục làm việc đã được loại khỏi Git.

Ảnh bánh kem dâu trong `public/images/strawberry.webp` do ImageGen tạo theo mô tả ảnh chụp bánh kem dâu thủ công trên đĩa sứ, nền hồng, ánh sáng cửa sổ và không có chữ. Các ảnh minh họa mẫu còn lại lấy từ [Unsplash](https://unsplash.com/) và [Pexels](https://www.pexels.com/). Giấy phép phông DM Sans và Playfair Display nằm trong `public/fonts/`.
