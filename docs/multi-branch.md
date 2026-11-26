# Nhiều cơ sở và chuyển đổi dữ liệu một cơ sở

Đã hoàn thành triển khai và nghiệm thu **local** ngày 26/11/2026 trên commit nguồn `a874c44c2933b82fef7026b8acb3f23337f73d62`, hiện mới lưu trong máy. Xem [kiến trúc và ma trận quyền](branches-architecture.md), các biên bản bên dưới và [manifest kiểm chứng](evidence/branches-2026-11-26/validation.json). Chưa mở bán, chưa phê duyệt dữ liệu/cơ sở thật và chưa xác nhận dịch vụ thật. Nhận đơn và lập chỉ mục vẫn đóng.

## Phạm vi dữ liệu

Danh mục, sản phẩm, biến thể và phụ kiện là menu chung. `branch_variants` quyết định trạng thái bán, tồn khả dụng, giá ghi đè và thời gian chuẩn bị ghi đè tại cơ sở. Giá và thời gian chuẩn bị `null` dùng giá trị menu chung. Không tự chia tồn cũ cho các cơ sở mới, không tự cộng lại tồn từ đơn đã giữ trước đây.

`branches.settings` chứa cấu hình tại cơ sở; `chain_settings` lưu các trường thương hiệu chung được cho phép. Cơ sở mặc định có ID 1. Migration chỉ mở rộng schema và tạo cơ sở mặc định với nhận đơn tắt; chưa chuyển chế độ đọc. `branch_migration_state.mode` mặc định `legacy`; chỉ lệnh `--activate` sau đối chiếu mới đổi thành `branch`. Giai đoạn này chỉ nhận múi giờ Việt Nam `Asia/Ho_Chi_Minh`; chưa nghiệm thu nhiều múi giờ.

Đơn có `branch_id`, bản chụp `branch_snapshot` và `sales_context`. Con của đơn kế thừa phạm vi qua `order_id`; mã tra cứu/token cũ, nội dung chuyển khoản, tiền và các bản chụp món không thay đổi khi backfill. Coupon có `branch_id=null` áp dụng cấp chuỗi và dùng chung bộ đếm hiện có; `branch_id` cụ thể giới hạn tại cơ sở đó.

Nhân viên có phân công trong `branch_memberships`; quản trị chuỗi vẫn là vai trò toàn cục. Thay đổi quyền làm thay `authorization_version`/bằng chứng phiên và thu hồi phiên theo quy trình quản trị. `branch_audits` ghi người thao tác, cơ sở, hành động và dữ liệu thay đổi; lệnh CLI có actor rỗng, phải ghi bổ sung danh tính người vận hành trong biên bản ca.

## Chuyển đổi theo các bước có thể đối chiếu

Thực hiện trước trên DB kiểm thử riêng và bản sao ảnh riêng. Chuẩn bị bản sao DB/ảnh đã kiểm tra khôi phục, chốt commit ứng dụng tương thích schema và giữ bản phát hành cũ. Không chạy `migrate:fresh`, xóa bảng hoặc rollback schema trên DB đang vận hành.

Trước backfill, đóng nhận đơn trong cấu hình cũ, dừng worker/scheduler có thao tác ghi và đưa web vào chế độ bảo trì hoặc chặn ghi bằng lớp vận hành. Tạm ngừng nhận đơn không tự chặn nhân viên sửa đơn; cần đóng băng cả các thao tác đó trong cửa sổ chuyển đổi. Lệnh kiểm tra cờ tạm dừng nhưng không thay thế việc bảo trì này. Giữ ảnh/tệp tĩnh staging trong mạng kín như quy trình hiện hành.

```powershell
# Sau khi xác nhận đúng schema đã sao lưu và đóng băng ghi:
php artisan migrate
php artisan bakery:branches --backfill --batch=200
php artisan bakery:branches --verify
php artisan bakery:branches --activate
```

Chọn đúng một hành động mỗi lần; batch từ 1 đến 1.000. Backfill, verify, activate và rollback-read dùng transaction, khóa cấu hình cũ và trạng thái chuyển đổi trước dữ liệu cơ sở; migration mở rộng schema thực thi DDL theo MySQL. Batch giới hạn số dòng nạp vào bộ nhớ; toàn bộ lần backfill vẫn cùng một transaction. Với DB lớn cần dự trù cửa sổ bảo trì và dung lượng transaction log, diễn tập trên bản sao trước.

Backfill thực hiện các bước sau:

1. Chụp baseline bất biến của đơn, món, tổng tiền, tồn, lượt dùng mã và lịch sử tồn, gồm số lượng/tổng và SHA-256 dữ liệu cũ. Báo cáo không in email, token, địa chỉ hoặc bí mật; các giá trị đó chỉ tham gia checksum.
2. Sao chép tồn khả dụng từ `variants.stock` nguyên giá trị sang cơ sở 1 nếu chưa có dòng. Không trừ tồn lần nữa theo các đơn cũ và không ghi đè tồn cơ sở đã có.
3. Gán đơn cũ `location_id=1` và lịch sử tồn cho cơ sở mặc định; gặp đơn chưa gán có `location_id` khác 1 thì dừng để đối chiếu. Nhân viên cũ được thêm phân công cơ sở 1 nếu chưa có; không ghi đè quyền đã cấu hình. Phiên của nhân viên vừa được thêm phân công bị thu hồi.
4. Với đơn thiếu bản chụp, ghi nguồn `legacy_location_id_1`, `historical_details_known=false`, tên/địa chỉ/liên hệ/múi giờ lịch sử để `null`. Đây là gán phạm vi dữ liệu cũ, không khẳng định địa chỉ hiện tại là địa chỉ nhận bánh khi mua. Địa chỉ giao hàng đã lưu trên đơn giữ nguyên. Bản chụp sẵn có không bị thay thế.
5. Trong chế độ legacy đang đóng băng, dựng bộ đếm `branch_slot_locks.booked` từ các đơn chưa hủy theo cơ sở/giờ. Bộ đếm không làm thay tồn, không đặt lại trong chế độ branch. Đối chiếu lại baseline sau backfill trước khi commit.

Backfill có thể chạy lặp: không nhân bản dòng hoặc cấp lại quyền/tồn; không tự chụp baseline mới. Nếu có phát sinh làm dữ liệu khác baseline, lệnh từ chối. Đối chiếu và xác định tác động trước khi dùng kế hoạch phục hồi/bản sao mới đã duyệt; không sửa trực tiếp baseline để ép đạt. Nếu chỉ bộ đếm khung sai khi vẫn ở legacy đóng băng, backfill có thể dựng lại rồi verify lại.

Giờ nhận và các chuỗi thời gian lịch sử vẫn giữ nguyên theo quy ước `Asia/Ho_Chi_Minh` của ứng dụng cũ. `timezone=null` trong bản chụp lịch sử biểu thị chưa có bản chụp cơ sở tại lúc mua, không chuyển giờ cũ thành UTC hoặc xác nhận địa chỉ/liên hệ lịch sử. Không đổi lịch sử giờ nhận để suy diễn một cơ sở ở múi giờ khác.

`--verify` đối chiếu baseline, tồn cơ sở mặc định với tồn cũ, đủ phạm vi/bản chụp đơn và lịch sử tồn, phân công nhân viên và bộ đếm khung. Kết quả lưu trong `branch_migration_state.verification` cùng thời điểm, có audit. `--activate` đòi lần verify đạt và kiểm tra lại dữ liệu ngay trong transaction; lần verify trước không cho phép bỏ qua thay đổi sau đó. Activate luôn giữ nhận đơn ở tất cả cơ sở tắt, không mở SEO hoặc triển khai public. Chủ tiệm cần duyệt mở nhận riêng sau nghiệm thu.

## Quay lại chế độ đọc cũ

```powershell
# Đóng nhận đơn mọi cơ sở và đóng băng ghi trước khi chạy:
php artisan bakery:branches --rollback-read
```

Lệnh không xóa dữ liệu mới, không gỡ cột/bảng và không khôi phục DB tự động. Tồn cơ sở mặc định phải còn khớp cột tồn cũ, giá/thời gian chuẩn bị không có ghi đè bất tương thích và cấu hình chuỗi/cơ sở không lệch cấu hình cũ. Các thay đổi tồn cơ sở 1 được ghi đồng thời vào dữ liệu cũ có thể còn tương thích; baseline trước mở cơ sở không phải lý do để tự hoàn lại các đơn đó.

Quay lui bị từ chối khi đã có đơn, lịch sử tồn hoặc coupon thuộc cơ sở khác; cơ sở khác có tồn/cấu hình biến thể riêng; hoặc đã có phân công nhân viên cơ sở khác. Khi bị từ chối, giữ chế độ branch và tạm dừng, xử lý theo kế hoạch khôi phục/chuyển tiếp đã duyệt; không xóa bằng chứng phát sinh để vượt guard. Rollback migration mở rộng bị chặn để bảo toàn bản chụp và audit ở môi trường vận hành. Ngoại lệ thu gọn schema chỉ dành cho teardown tự động với `APP_ENV=testing`, SQLite `:memory:` hoặc MySQL có schema `_test` và không có DB URL ghi đè; ngoại lệ này không phải quy trình quay lui vận hành.

## Ca nghiệm thu cần ghi bằng chứng

| Nhóm | Ca kiểm tra và bằng chứng |
| --- | --- |
| Dữ liệu cũ | Đơn/món/tổng tiền/token/lượt mã/stock checksum trước–sau; đơn hủy cũ không bị hoàn lại; giữ bản chụp có sẵn; đánh dấu lịch sử chưa biết. |
| Chạy lặp | Backfill hai lần cùng kết quả, không tăng tồn/cấp lại quyền; sai baseline và tồn không bị ghi đè; sai counter được phát hiện. |
| Cổng kích hoạt | Thiếu verify, dữ liệu đổi sau verify hoặc đang nhận đơn đều không kích hoạt; kích hoạt thành công vẫn đóng nhận đơn. |
| Phạm vi cơ sở | Giỏ/quote/idempotency, lịch/công suất/tồn/coupon và mọi thao tác quản trị/đơn/email đúng cơ sở. Kiểm thử truy cập chéo và quyền bị thu hồi giữa phiên. |
| Đồng thời | MySQL schema `_test` riêng: tranh tồn/khung trong cùng cơ sở và hai cơ sở, coupon cấp chuỗi dùng chung giới hạn, replay yêu cầu sai cơ sở. SQLite không thay cho ca khóa đồng thời. |
| Quay lui | Chỉ dữ liệu cơ sở 1 tương thích được quay lại đọc cũ; có phát sinh cơ sở khác bị chặn; audit/snapshot còn nguyên. |
| Giao diện | Chọn/đổi cơ sở, xác nhận xóa giỏ khi đổi, hiển thị đúng địa chỉ/giờ/tổng tiền; desktop và viewport mobile. |

Biên bản cuối cần có môi trường, schema thử, commit, người thử, thời điểm, kết quả và tệp bằng chứng đã che thông tin riêng; tách kết quả local khỏi cơ sở/dịch vụ thật. Chủ tiệm cung cấp danh sách cơ sở, người quản lý, menu/giá/tồn, lịch/công suất, địa chỉ/vùng giao/phí và quy tắc thu tiền thực tế trước khi duyệt từng cơ sở.

## Diễn tập nâng cấp và quay lại đọc cũ đã thực hiện

Ngày **26/11/2026**, người thử **Codex**, trên MySQL 8.4.13 loopback `33317`, schema mới `tiembanh_branches_upgrade_test`, tách khỏi DB PHPUnit và các sandbox trước. Ứng dụng cũ lấy từ archive Git chính xác `37ee0a1`; vendor được sao chép vật lý với cùng `composer.lock`, không dùng junction khiến PSR trỏ về mã hiện tại. Diễn tập chạy trên working tree trước các sửa giá/giao diện cuối; thành phần migration không thay đổi. Commit nguồn cuối là `a874c44c2933b82fef7026b8acb3f23337f73d62`; full suite và kiểm tra lại giao diện cuối được đối chiếu với bản này trong manifest.

Đã chạy migration và seed bằng mã cũ, sau đó gọi **Checkout cũ** tạo ba đơn thật trong sandbox: một COD đang giữ, một chuyển khoản chưa thu đang giữ và một COD được **Orders cũ** hủy trước chuẩn bị. Các đơn mới dùng mã `NHAMINH10`; tạo/hủy đi qua dịch vụ thật, không gán trực tiếp trạng thái hoặc giả lịch sử tồn. Tổng dữ liệu trước nâng cấp là 7 đơn seed cộng 3 đơn qua dịch vụ. Tiếp đó đóng nhận đơn, không chạy worker/scheduler hoặc web trong cửa sổ chuyển đổi.

| Đối chiếu | Trước nâng cấp | Sau expand/backfill/kích hoạt | Mã cũ đọc schema mở rộng sau rollback-read |
| --- | --- | --- | --- |
| Đơn / dòng món / số lượng món | 10 / 10 / 10 | Giữ nguyên | Giữ nguyên |
| Tổng các trường `orders.total` | 2.176.000 VND | Giữ nguyên | Giữ nguyên |
| Biến thể / tổng tồn khả dụng | 18 / 204 | Giữ nguyên | Giữ nguyên |
| Mã giảm / tổng lượt đã dùng | 2 / 2 | Giữ nguyên | Giữ nguyên |
| Dòng lịch sử tồn | 4 | Giữ nguyên | Giữ nguyên |
| Checksum đơn, món, biến thể, coupon, lịch sử tồn | Đã chụp SHA-256 | Tất cả khớp | Tất cả khớp |
| Checksum token tra cứu và khóa chống tạo lặp | Đã chụp SHA-256 | Khớp | Khớp |
| Đơn/dòng món đọc qua Orders và tra cứu token của đúng mã ứng dụng | 10 / 10 | 10 / 10 | 10 / 10 |

Tổng tiền trong bảng gồm cả đơn đã hủy, dùng để phát hiện thay đổi dữ liệu; **không phải doanh thu**. Không đưa token, khóa, email, địa chỉ khách hoặc nội dung thư vào bằng chứng. Xem [biên bản tổng hợp](evidence/branches-2026-11-26/migration-upgrade.json), [trước](evidence/branches-2026-11-26/migration-before.json), [sau](evidence/branches-2026-11-26/migration-after.json) và [mã cũ đọc lại](evidence/branches-2026-11-26/migration-old-code-read.json).

Các bước thực tế đều đạt:

1. Chạy migration mở rộng của mã hiện tại trên schema do ứng dụng cũ tạo; [log expand](evidence/branches-2026-11-26/migration-expand.txt).
2. Chạy `--backfill --batch=2` hai lần, rồi `--verify` và `--activate`; đối chiếu toàn bộ fingerprint trước/sau khớp. Không giữ tồn hoặc hoàn tồn lần nữa.
3. Khi chỉ có dữ liệu cơ sở 1, chạy `--rollback-read` thành công. Tiến trình PHP riêng nạp **mã cũ trong archive**, dùng query/tra cứu token/chi tiết của Orders cũ đọc đủ 10 đơn trên schema đã mở rộng; tiền, món và checksum token vẫn khớp. Đây là xác nhận khả năng đọc của đúng bản cũ đã thử, không chứng nhận mọi bản phát hành cũ hay một lần khôi phục DB.
4. Chạy verify và activate lại thành công khi vẫn đóng nhận đơn.
5. Thêm cơ sở B được gắn nhãn **THỬ NGHIỆM**, tồn 4 mỗi phiên bản, giá cao hơn menu chung 100.000 VND, thời gian chuẩn bị 240 phút, khung nhận `10:00`/`15:00`; thêm nhân viên và quản lý riêng cho A/B. Cấu hình, địa chỉ, phí và tài khoản đều là fixture. Cả hai cơ sở giữ nhận đơn tắt.
6. Chạy rollback-read lại: lệnh trả mã lỗi 1, từ chối vì cơ sở khác có tồn/cấu hình biến thể và phân công nhân viên. Không xóa fixture để vượt guard; chế độ branch và dữ liệu được giữ nguyên. [Kết quả từ chối](evidence/branches-2026-11-26/migration-rollback-refused.txt).

Đã chạy riêng **25/25 bài MySQL, 193 assertions, không skip**, gồm 13 bài chuyển đổi, 6 bài checkout cơ sở và 6 bài đồng thời. Các ca đồng thời bao gồm tranh tồn cuối, khung cuối, giới hạn mã cấp chuỗi, gửi lại cùng yêu cầu và khóa SKU của A không chặn đặt tại B. Schema PHPUnit mới là `tiembanh_branches_phpunit_test`, storage/cache tách riêng; [log focused](evidence/branches-2026-11-26/check-branches-mysql-focused.txt). Kết quả này không thay cho full suite cuối của toàn dự án.

Runtime dành cho ca trình duyệt là **http://127.0.0.1:18936**, chạy mã hiện tại với schema nâng cấp trên. Khi bàn giao ban đầu cho ca tiếp theo: `/up` trả 200, mode branch, A/B đều tạm dừng, có 10 đơn, 0 thư chờ và 0 thư lỗi, indexing tắt. Worker chỉ gửi vào **mailer log local**, không xác nhận SMTP thật. Những thay đổi đơn và trạng thái cuối sau ca trình duyệt được ghi riêng, không suy từ ảnh chụp trạng thái ban đầu này.

Để lặp lại từ clone, dùng bản sao Git đúng commit cũ cùng dependency khóa, DB mới có hậu tố `_test`, tài khoản DB và thư mục storage/cache/media/khóa ứng dụng riêng. Chạy migration/seed cũ, tạo các đơn thử qua dịch vụ, chụp checksum, đóng băng ghi rồi chạy chuỗi lệnh chuyển đổi phía trên bằng mã mới. Giữ mã cũ chạy thành tiến trình riêng để kiểm tra khả năng đọc sau rollback-read, trước khi thêm dữ liệu B. Helper và mật khẩu/TOTP trong `work/branches-20261126/` chỉ dùng cho workspace local, bị bỏ qua trong Git và không phải dependency công khai của quy trình.

Chưa có tên miền/HTTPS thật, cơ sở thật, menu/ảnh/chính sách thật hoặc dịch vụ email thật được nghiệm thu qua diễn tập này. Nhận đơn và SEO vẫn cần phê duyệt riêng của chủ tiệm; các fixture không tạo quyền mở bán.

## Nghiệm thu tích hợp cuối trên bản nguồn đã chốt

Ngày 26/11/2026, Codex kiểm tra bản nguồn `a874c44c2933b82fef7026b8acb3f23337f73d62`. Các bộ kiểm thử chạy trên cây mã sau sửa lỗi cuối; cây mã được đóng thành commit sau khi đạt. Đây là kết quả local, chưa phải kết quả CI trên GitHub.

| Kiểm tra cuối | Kết quả |
| --- | --- |
| PHPUnit MySQL, schema `_test` riêng | **163/163 đạt, 2.687 assertions, không skip**; gồm các ca đồng thời MySQL. |
| PHPUnit SQLite bộ nhớ | **145 đạt, 18 ca cần MySQL được bỏ qua, 2.544 assertions**; tổng 163 ca. |
| `npm run test:orders` | **3/3 đạt**, kiểm tra địa điểm/liên hệ của đơn độc lập cơ sở đang chọn. |
| `npm run lint`, `npm run build` | Đạt. |
| Pint `--test` | Đạt. |
| `npm run test:ssr` | Build SSR và smoke 5 trang công khai đạt; trang riêng bị từ chối. |

[Manifest kiểm chứng](evidence/branches-2026-11-26/validation.json) tập hợp log và mã băm bằng chứng. Workflow GitHub được cấu hình chạy lint, build, Pint, SSR, Node, SQLite và MySQL; chưa có lượt CI của commit mới trước khi được phép push. Không có bước deploy trong ca nghiệm thu này.

### Ca trình duyệt khách chọn bánh → cơ sở xử lý → khách tra cứu

Đã chạy bằng trình duyệt trong Codex với viewport giả lập **390 × 844** và **1440 × 900**. Mobile ghi nhận chiều rộng nội dung 375 px và không tràn ngang; đây chưa phải điện thoại vật lý. [Biên bản trình duyệt](evidence/branches-2026-11-26/browser-acceptance.json) ghi các ca và giới hạn.

1. Kiểm tra cùng bánh tại A có giá 360.000 VND, chuẩn bị 120 phút; tại B có giá 460.000 VND, chuẩn bị 240 phút và tồn đầu 4. Đổi từ giỏ A có bánh sang B cần xác nhận bỏ giỏ trước khi chuyển; giá và lựa chọn sau đó theo B.
2. Khách vãng lai đặt COD nhận tại B, lời chúc thêm 15.000 VND. Đơn thử **NB-261126-7F6515FD9B** có tổng **475.000 VND**, nhận **27/11/2026 lúc 10:00**, đúng cấu hình B.
3. Dashboard chuỗi thấy 11 đơn trong khi A có 10. Nút mở đơn B đổi ngữ cảnh quản trị tới B qua bước kiểm tra phía máy chủ; nhân viên xác nhận, chuẩn bị, chuyển sẵn sàng, ghi thu COD thử rồi hoàn tất. Không có thu tiền thật.
4. Khách tra cứu lại thấy hoàn tất/đã thanh toán. Trong khi thực đơn công khai đang chọn A, địa điểm nhận và kênh hỗ trợ của đơn vẫn thuộc B. Ca này phát hiện thẻ nhận bánh từng lấy địa chỉ A; đã sửa bằng dữ liệu địa điểm của đơn và liên hệ đúng cơ sở, thêm 3 kiểm thử Node cùng hồi quy PHP, rồi kiểm tra lại bằng trình duyệt sau build. Tra cứu vẫn hoạt động khi tạm dừng nhận đơn.
5. Kiểm tra quản trị B trên mobile: bộ chọn cơ sở và form giá/chuẩn bị/tồn hiển thị, biến thể dâu còn 3 sau đơn. Console được kiểm tra không ghi lỗi tại phiên khách và quản trị.

Ảnh bằng chứng: [xác nhận đổi cơ sở trên mobile](evidence/branches-2026-11-26/mobile-switch-confirm.jpg), [đơn B trên mobile](evidence/branches-2026-11-26/mobile-order-B.jpg), [quản trị B](evidence/branches-2026-11-26/mobile-admin-B.jpg), [dashboard chuỗi desktop](evidence/branches-2026-11-26/desktop-chain-dashboard.jpg). Không lưu URL mang token tra cứu/thao tác, cookie, mật khẩu hoặc TOTP trong các báo cáo công khai.

### Trạng thái bàn giao sau ca cuối

Runtime local vẫn là **http://127.0.0.1:18936**, schema `tiembanh_branches_upgrade_test`, mode branch. **A và B đều tạm dừng nhận đơn**, indexing tắt và các phê duyệt dữ liệu cơ sở thật vẫn tắt. Có **11 đơn: A 10, B 1**; tổng tồn A 204, B 71; cột tồn cũ được giữ tương thích ở 204. Tổng lượt mã đã dùng vẫn 2. Đơn B chỉ có một chuyển động giữ tồn, đúng cơ sở, từ 4 xuống 3; không làm giảm kho A.

Queue còn 0 và failed jobs 0. Sáu thông báo của đơn B đã được xử lý vào **mailer log local**; việc ghi đã thanh toán là COD giả lập. Các số này không xác nhận email SMTP ngoài máy, chuyển khoản ngân hàng, cổng thanh toán hoặc tiền thật. Chi tiết trạng thái cuối và mã băm nằm trong manifest/bằng chứng runtime của phiên này.

### Chạy lại kiểm tra và phần còn chờ

Từ clone mới, cài dependency và cấu hình theo [README](../README.md) với khóa ứng dụng, DB thử, media, storage/cache và cookie riêng; giữ nhận đơn/SEO đóng. Không dùng helper hoặc bí mật của workspace làm dependency. Migration/seed chỉ chạy sau khi xác nhận đúng schema thử riêng; diễn tập chuyển đổi dùng trình tự và bản archive cũ ở phần trên.

```powershell
composer install
npm ci
# Sau khi đã xác nhận đúng môi trường và schema thử:
php artisan migrate --seed
npm run lint
npm run build
php vendor/bin/pint --test
php vendor/bin/phpunit --do-not-cache-result --colors=never
npm run test:orders
npm run test:ssr
```

`phpunit.xml` mặc định dùng SQLite bộ nhớ. Để chạy đủ ca khóa đồng thời, dùng cấu hình MySQL riêng với `APP_ENV=testing`, schema hậu tố `_test`, `DB_URL` rỗng và storage/cache tách khỏi web; chạy `php vendor/bin/phpunit --do-not-cache-result --colors=never --fail-on-skipped`. Cùng một schema không chạy hai bộ kiểm thử đồng thời. Ghi commit, người thử, thời gian và log đầy đủ; không đưa mật khẩu hoặc token vào log Git.

| Mục còn chờ | Người phụ trách và điều kiện nghiệm thu thật |
| --- | --- |
| Dữ liệu từng cơ sở | Chủ tiệm duyệt tên/địa chỉ/liên hệ, ảnh được phép dùng, menu/giá/tồn, thành phần/dị ứng, chuẩn bị, giờ cắt đơn, lịch/công suất và vùng/phí giao. |
| Nhân sự và tiền | Chủ tiệm chốt quản lý, người xử lý đơn/đối soát/hỗ trợ/sự cố; quyền từng cơ sở; ngân hàng, quy tắc trả trước, hủy/đổi/hoàn và ca thu tiền thật. |
| Hạ tầng ngoài máy | Người vận hành cấp staging kín, DNS/tên miền, HTTPS được thiết bị tin cậy, quyền tài nguyên tĩnh, SMTP/worker/scheduler/cảnh báo và kế hoạch sao lưu–phục hồi trên dịch vụ thật. |
| Thiết bị và môi trường đích | Kỹ thuật thử điện thoại thật, mạng thật và cấu hình được duyệt; chạy lại các ca phù hợp tại đúng bản phát hành. Viewport giả lập không thay nghiệm thu thiết bị. |
| Công bố | Chủ tiệm phê duyệt mở nhận đơn riêng sau các mục trên; nghiệm thu local, tạo commit hoặc CI đạt không tự mở bán. SEO nhiều cơ sở vẫn cần URL ổn định theo cơ sở và nghiệm thu riêng như tài liệu kiến trúc. |

Phần kỹ thuật đã đạt trong phạm vi local được ghi nhận; vận hành với cơ sở/dịch vụ và dữ liệu thật vẫn chờ các đầu vào cụ thể ở bảng trên. Không có triển khai public hoặc phát sinh dịch vụ bên ngoài trong ca này.
