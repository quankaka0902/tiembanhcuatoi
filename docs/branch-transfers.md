# Chuyển cơ sở xử lý một đơn

Chính sách mặc định `v1-unpaid-before-production`, áp dụng một đơn/một cơ sở. Người dùng đã giao quyền chọn quy tắc hợp lý; phiên bản này ưu tiên không chuyển nghĩa vụ tiền đã thu hoặc bánh đã làm. Đây là chính sách phần mềm đề xuất cho vận hành, chưa phải xác nhận thông tin hoặc phê duyệt mở bán thật của tiệm.

## Điều kiện và quyền

| Trường hợp/hành động | Quy tắc phía máy chủ |
| --- | --- |
| Lập báo giá, gửi đề nghị | Quản lý có quyền cấu hình ở cả nguồn và đích; kiểm lại membership trong transaction. |
| Đơn được chuyển | `pending` hoặc `confirmed`, `unpaid`, chưa bắt đầu sản xuất/xuất giao, chưa hoàn tồn, không có yêu cầu đổi/hủy đang chờ. |
| Đã điều phối | Chỉ công việc chưa phân chuyến/người giao, chưa có lượt giao được chuyển. Công việc đã giao/nhận không chuyển. |
| Khách đăng nhập | Chỉ chủ đơn còn hoạt động được phê duyệt báo giá của chính mình. |
| Khách vãng lai | Liên kết thao tác riêng 256 bit, chỉ lưu hash; gửi qua thông báo riêng, hết hạn sau 15 phút. Liên kết tra cứu cũ chỉ đọc và được giữ nguyên. |
| Người thực hiện | Quản lý có quyền cả hai cơ sở, khách đã đồng ý, đề nghị còn trong hạn 30 phút, đơn và báo giá vẫn khớp. |
| Chuyển khoản chưa thu | Thêm quyền đối soát ở cả hai cơ sở, cùng ngân hàng/số tài khoản nhận, xác nhận chưa nhận tiền và mã đối chiếu. Không đổi ngân hàng nhận qua chuyển đơn. |
| Đã thu/đã hoàn tiền | Chặn. Dùng luồng đối soát, hủy/hoàn hiện có; không tự bù trừ hoặc chuyển tiền. |
| Đã bắt đầu làm/đã xuất | Chặn. Dùng quy trình hủy, kiểm kê phần chưa làm/bán lại/tiêu hao hiện có. Không tự tăng tồn bánh đã làm. |
| Đơn hoặc giá đổi sau đề nghị | Không chuyển. Nguồn giữ nguyên; quản lý đóng đề nghị và tạo báo giá mới để khách duyệt lại. |
| Hủy/từ chối/hết hạn đề nghị | Không hủy đơn và không thay kho, khung hoặc lượt mã. |

Muốn bật cần `allow_branch_transfers=true`, đúng `transfer_policy_version` và `transfer_policy_approval_reference` ở **cả hai cơ sở**. Mặc định đóng. Tham chiếu `LOCAL-SYNTHETIC-ONLY-*` trong sandbox là nhãn ca thử, tuyệt đối không dùng làm bằng chứng phê duyệt thật. Quyền của nhân viên, bếp, đối soát hoặc người giao riêng lẻ không đủ để chuyển.

## Trạng thái, tiền và tài nguyên

`pending → approved → completed`; `pending → rejected`; `pending/approved → cancelled`; đề nghị quá hạn không thực hiện được, được đóng `expired` khi lập đề nghị tiếp theo. Khách phê duyệt chưa đặt giữ nguồn lực đích.

Báo giá gồm cơ sở, giờ, giá từng dòng, phí lời chúc/phụ kiện, mã giảm, phí giao, tổng và chênh lệch. Đơn chưa thu nên số phải thanh toán sau chuyển là **toàn bộ tổng mới**; khách phải đồng ý trước. Không tạo phiếu thu/hoàn tiền bằng chuyển cơ sở. Giữ điều kiện trả trước nghiêm hơn giữa bản chụp đơn và cơ sở đích; xóa ngoại lệ cho chưa thu tiền tại nguồn, quản lý đích chỉ được cấp ngoại lệ mới nếu chính sách đích cho phép.

Transaction chốt theo thứ tự: người/membership theo ID cơ sở → khóa yêu cầu → cấu hình hai cơ sở theo ID, thương hiệu → đơn → đề nghị → mã giảm → khung theo cơ sở/giờ → SKU theo cơ sở/ID → công việc. Giữ SKU/khung đích trước khi giải phóng nguồn, commit cùng đơn/dòng hàng/công việc/audit/thông báo. Thua tranh khung hoặc tồn sẽ rollback toàn bộ. `transfer_hold` và `transfer_release` ghi đúng cơ sở, không trộn với hoàn kho do hủy.

Mã toàn chuỗi đã giữ lượt bởi chính đơn được giữ nguyên lượt kể cả khi chạm giới hạn. Mã nguồn không còn hợp lệ ở đích bị bỏ và khách thấy giảm giá thay đổi trước khi duyệt; trả lượt cũ đúng một lần khi chuyển thành công. Không tự áp mã mới. Chuyển xong trạng thái đơn về chờ xác nhận tại đích; giữ ID, mã và liên kết tra cứu.

## Lịch sử và quyền riêng tư

`order_transfers` lưu bản chụp trước/sau mã hóa, người đề xuất/phê duyệt/thực hiện, lý do, thời hạn, dấu vân tay và khóa chống lặp. `order_transfer_commands` chống dùng cùng khóa cho lệnh khác. Giữ lịch sử đơn, `stock_movements`, lịch sử giao nhận và thông báo theo sự kiện. Email có thể thử lại mà không chuyển đơn lần nữa.

Địa chỉ giao mới được mã hóa trong `orders.delivery_snapshot`; cột `address` cũ còn phục vụ tương thích, vì vậy DB/backup vẫn cần kiểm soát truy cập. Bản chụp địa chỉ cũ thiếu dữ liệu dùng địa chỉ đã có và mã vùng có nguồn đối chiếu; không suy diễn pin hoặc mã hành chính. Không đọc được dữ liệu mã hóa thì dừng để kiểm tra. Projection tra cứu vãng lai không trả địa chỉ chi tiết; nhân sự đối soát không nhận địa chỉ/ghi chú giao. Log mail local mặc định ở `storage/app/private/mail.log`, không trộn vào log ứng dụng chung. Không đưa log mail hoặc URL thao tác vào Git.

## Quy trình trong ca bán

1. Đối chiếu khách, đơn, quyền nguồn/đích, chưa thu/chưa làm/chưa điều phối. Với ngân hàng, người đối soát xác nhận ngoài phần mềm chưa có khoản thu đến muộn.
2. Chọn cơ sở đích, ngày/giờ đúng lịch, mã vùng đúng địa chỉ; xem báo giá và gửi đề nghị. Không hứa đã giữ chỗ ở đích.
3. Khách mở liên kết riêng, xem cơ sở/giờ/tổng, chấp thuận hoặc từ chối. Nếu hết hạn, tạo đề nghị mới sau khi đóng đề nghị cũ.
4. Quản lý kiểm tra lại và thực hiện. Nếu báo giá/tồn/khung đổi, giữ nguồn và xin xác nhận báo giá mới. Nếu thành công, giao diện chuyển sang phạm vi đích; xác nhận lại đơn tại đích trước khi làm.
5. Theo dõi thư thất bại và đối soát thu tiền tại đích. Không thao tác chuyển lặp để khắc phục lỗi email.

## Chạy lại kiểm thử

Trên môi trường `APP_ENV=testing`, `DB_URL` rỗng, schema MySQL riêng có hậu tố `_test`:

```sh
php artisan migrate --force
php vendor/bin/phpunit --filter 'OrderTransfersTest|ConcurrentOrderTransferTest|DispatchTest|ConcurrentDispatchTest|BranchSelectionTest'
```

MySQL thực mới xác minh khóa/tranh tài nguyên; SQLite chỉ xác minh nghiệp vụ tuần tự. Migration mở rộng không xóa cột cũ. `down()` các bảng mới chỉ cho phép test dùng SQLite memory/MySQL `_test`; quay lui vận hành cần giữ bảng, khóa nhận đơn và dùng bản ứng dụng tương thích schema sau đối chiếu, không chạy `migrate:rollback` tùy tiện.
