# Vòng đời đơn, đổi lịch, hủy và thông báo

Tài liệu mô tả luồng cho một cơ sở của **Tiệm Bánh Nhà Mình**. Giờ dùng `Asia/Ho_Chi_Minh`, tiền dùng số nguyên VND. Trạng thái đơn và trạng thái thanh toán được xử lý riêng. Việc yêu cầu đổi/hủy, xử lý đơn cũ và gửi thư vẫn hoạt động khi cửa hàng tạm ngừng nhận đơn mới.

## Bảng trạng thái

| Trạng thái đơn | Bước tiếp theo | Điều kiện và người xử lý |
| --- | --- | --- |
| `pending` · Chờ xác nhận | `confirmed`, `cancelled` | Nhân viên xác nhận. Hủy theo mốc ở bảng quyền bên dưới. |
| `confirmed` · Đã xác nhận | `preparing`, `cancelled` | Trước khi vào bếp, máy chủ kiểm tra chính sách trả trước đối với đơn chuyển khoản. |
| `preparing` · Đang chuẩn bị | `ready` nếu nhận tại tiệm, `shipping` nếu giao, hoặc `cancelled` | Bắt đầu làm được lưu bằng `production_started_at`. Hủy từ đây cần quản lý và lý do, chưa tự tăng tồn bán. |
| `ready` · Sẵn sàng nhận | `completed`, `uncollected`, `cancelled` | Nhân viên thu COD và bàn giao. Nếu khách chưa nhận, ghi rõ kết quả liên hệ. Hủy cần quản lý. |
| `shipping` · Đang giao | `completed`, `delivery_failed`, `cancelled` | Nhân viên cập nhật kết quả giao. Giao chưa thành công phải có lý do. Hủy cần quản lý. |
| `delivery_failed` · Giao chưa thành công | `shipping`, `completed`, `cancelled` | Liên hệ khách, ghi lý do khi giao lại hoặc kết thúc. Quản lý có thể đổi lịch trước khi giao lại, theo lịch và công suất đang áp dụng. |
| `uncollected` · Khách chưa nhận | `ready`, `completed`, `cancelled` | Liên hệ khách và ghi cách xử lý. Quản lý có thể đổi lịch trước khi sắp xếp nhận lại. |
| `completed` · Hoàn tất | Không có bước chuyển tiếp | Chỉ hoàn tất khi đã thanh toán. Nhân viên phải xác nhận việc bàn giao thực tế. |
| `cancelled` · Đã hủy | Không mở lại hoặc hủy thêm lần nữa | Nếu đã trả tiền, đơn vào hàng chờ hoàn. Nếu đã làm, quản lý xử lý phân loại bánh riêng. |

| Trạng thái thanh toán | Thay đổi được phép | Điều kiện |
| --- | --- | --- |
| `unpaid` · Chưa thanh toán | `paid` | Đơn chưa hủy. Chuyển khoản cần quyền đối soát. COD chỉ được ghi đã thu khi sẵn sàng nhận, đang giao, giao chưa thành công, khách chưa nhận hoặc hoàn tất. |
| `paid` · Đã thanh toán | `refunded` | Đơn đã hủy, người thao tác có quyền đối soát và đã kiểm tra bằng chứng hoàn đủ số tiền. |
| `refunded` · Đã hoàn tiền | Không có bước chuyển tiếp | Không ghi hoàn lần nữa. |

## Ma trận quyền theo hành động

“Chủ đơn” là tài khoản đang hoạt động có `user_id` trùng đơn, truy cập qua **Đơn của tôi**. “Liên kết tra cứu” là liên kết xem đơn đã phát trước đây. “Khách đã xác minh” là khách vãng lai đã mở liên kết thao tác riêng còn hạn gửi đến email lưu trên đơn. Nhân viên có quyền đối soát giữ các quyền xử lý thông thường của nhân viên.

| Hành động | Chủ đơn đăng nhập | Chỉ có liên kết tra cứu | Khách đã xác minh | Nhân viên | Nhân viên đối soát | Quản trị viên / quản lý |
| --- | --- | --- | --- | --- | --- | --- |
| Xem trạng thái, giờ, thanh toán | Đơn của mình | Đơn của liên kết, dữ liệu giới hạn | Đơn được xác minh | Đơn trong phạm vi cơ sở | Như nhân viên | Như nhân viên |
| Xem lý do yêu cầu và phản hồi | Đơn của mình | Không xem nội dung riêng | Đơn được xác minh | Có | Có | Có |
| Yêu cầu liên kết thao tác | Không cần khi đã là chủ đơn | Nhập email, phản hồi trung tính | Có thể xin liên kết mới từ trang tra cứu | Không thay quyền khách | Không thay quyền khách | Không thay quyền khách |
| Gửi yêu cầu đổi giờ / hủy | Được khi còn mốc yêu cầu | Không được thay đổi đơn | Được khi còn mốc yêu cầu, liên kết dùng một lần | Không giả danh khách | Không giả danh khách | Không giả danh khách |
| Chấp nhận / từ chối yêu cầu khách | Không | Không | Không | Chấp nhận trước chuẩn bị và còn mốc yêu cầu; được từ chối cả yêu cầu lỗi thời hoặc quá mốc để đóng yêu cầu | Như nhân viên | Chấp nhận vẫn kiểm tra lại mốc, trạng thái và giờ đã lưu; ngoại lệ xử lý trực tiếp có lý do |
| Đổi giờ trực tiếp trước chuẩn bị | Chỉ gửi yêu cầu | Không | Chỉ gửi yêu cầu | Được khi còn mốc yêu cầu, có lý do | Như nhân viên | Được kể cả ngoại lệ sau mốc, có lý do và giờ mới hợp lệ |
| Đổi lịch đơn giao chưa thành công / khách chưa nhận | Không | Không | Không | Không | Không | Được, kiểm tra lại lịch, công suất và lead time đã lưu |
| Hủy trước chuẩn bị, còn mốc yêu cầu | Chỉ gửi yêu cầu | Không | Chỉ gửi yêu cầu | Được | Được | Được |
| Hủy sau mốc yêu cầu hoặc sau bắt đầu làm | Liên hệ tiệm | Liên hệ tiệm | Liên hệ tiệm | Chuyển quản lý | Chuyển quản lý | Được, bắt buộc lý do |
| Thu COD đúng trạng thái | Không | Không | Không | Được | Được | Được |
| Đối soát chuyển khoản / ghi nhận hoàn | Không | Không | Không | Không | Được | Được |
| Duyệt ngoại lệ trả trước | Không | Không | Không | Không | Không | Chỉ khi cấu hình tiệm cho phép, có lý do, đơn còn chờ / đã xác nhận |
| Xác minh lead time của đơn cũ | Không | Không | Không | Không | Không | Đơn chưa vào bếp, chưa có bản chụp, có căn cứ và lý do |
| Phân loại bánh sau hủy và nhập lại phần phù hợp | Không | Không | Không | Không | Không | Được đúng một lần, kiểm tra thực tế từng dòng bánh |
| Đánh dấu chưa giao / chưa nhận và xử lý lại | Không | Không | Không | Được theo bảng trạng thái, có lý do | Được | Được |
| Sửa chính sách, bật nhận đơn mới | Không | Không | Không | Không | Không | Được sau khi hoàn tất quy trình phê duyệt của tiệm |

Máy chủ kiểm tra quyền từng thao tác trên tài khoản đang hoạt động. Ẩn nút trên giao diện chỉ giúp người dùng chọn đúng bước, không thay thế kiểm tra quyền. Tài khoản nội bộ tiếp tục dùng TOTP hiện có.

## Yêu cầu của khách và liên kết thao tác

Khách được gửi yêu cầu khi đơn ở `pending` hoặc `confirmed`, chưa vào khâu làm và thời điểm hiện tại **nhỏ hơn** `scheduled_at - prep_minutes_snapshot`. Đúng mốc hoặc sau mốc thì chuyển sang liên hệ tiệm. Một đơn chỉ có một yêu cầu đang chờ. Gửi yêu cầu chưa đổi giờ, hủy, giải phóng khung hoặc thay đổi tồn.

Khách đăng nhập mở **Đơn của tôi → chi tiết đơn → Đổi giờ hoặc hủy đơn**, chọn loại yêu cầu, giờ đề nghị nếu đổi và nhập lý do. Với khách vãng lai, trang tra cứu chỉ nhận yêu cầu gửi liên kết tới email đã lưu nếu thông tin trùng khớp. Địa chỉ người nhận không lấy từ trường nhập làm đích gửi thư.

Liên kết thao tác có token ngẫu nhiên, hiệu lực 15 phút và dùng một lần để tạo yêu cầu. Bảng `order_action_links` chỉ lưu mã băm token. Nội dung email chứa liên kết được mã hóa trong bảng thông báo, không lưu token vào payload hàng đợi ở dạng rõ. Mở trang GET chưa tiêu thụ liên kết. Gửi liên kết mới thu hồi liên kết cũ chưa dùng, và thao tác gửi được giới hạn theo đơn cùng giới hạn tần suất route.

Mỗi yêu cầu có `request_key` và dấu vân tay nội dung. Gửi lại cùng khóa và cùng nội dung trả lại yêu cầu đã tạo. Dùng cùng khóa với nội dung khác bị từ chối. Liên kết hết hạn hoặc đã dùng không thể tạo yêu cầu khác; khách quay lại liên kết tra cứu để xin liên kết mới. Các liên kết tra cứu cũ vẫn giữ quyền xem đơn, không được nâng thành quyền sửa/hủy.

Yêu cầu có trạng thái `pending`, `approved` hoặc `rejected`. Lịch sử lưu người yêu cầu, người xử lý, thời điểm, giờ cũ, giờ đề nghị, lý do và phản hồi. Trang chỉ có quyền tra cứu không nhận lý do hay phản hồi riêng; trang của chủ đơn hoặc khách đã xác minh mới xem được nội dung đó. Khách thấy nhãn vai trò, nhân viên trong quản trị thấy tên người thao tác.

## Đổi giờ, lịch và xử lý đồng thời

Đơn mới lưu thời gian chuẩn bị dài nhất của các dòng bánh vào `prep_minutes_snapshot`. Thay đổi thời gian chuẩn bị trong menu sau đó không thay bản chụp của đơn đã nhận.

Đơn cũ thiếu bản chụp không tự được coi là cần 0 phút. Thông tin menu hiện tại chỉ hỗ trợ người quản lý đối chiếu. Quản lý mở đơn còn chờ hoặc đã xác nhận, nhập số phút và căn cứ trong **Đơn cũ chưa lưu thời gian chuẩn bị**. Bản chụp đã xác nhận không được sửa lại qua bước này. Trước khi có bản chụp, khách không gửi yêu cầu trực tuyến và nhân viên không đổi lịch đơn đó.

Khi xử lý đổi giờ, máy chủ khóa cấu hình cửa hàng, khóa đơn và kiểm tra trong cùng giao dịch. Giờ mới phải còn trong lịch nhận hiện tại, ngày mở cửa, ngoài ngày nghỉ, đủ lead time và còn công suất chung cho giao / nhận. Yêu cầu chỉ giữ ý định, không giữ chỗ ở giờ đề nghị. Khi duyệt, hệ thống kiểm tra lại giờ và trạng thái gốc của yêu cầu. Nếu người khác đổi đơn, bắt đầu làm hoặc khách khác lấy khung cuối, thao tác thất bại, giờ cũ và yêu cầu chờ được giữ nguyên để nhân viên trao đổi lại.

Đổi lịch trực tiếp cũng kiểm tra `previous_slot`. Ghi nhận `order_events` có giá trị cũ / mới, người thao tác và lý do. Nếu đơn chuyển khoản chưa thu được dời sớm hơn, hạn chuyển khoản có thể rút ngắn đến giờ hẹn mới; dời muộn không tự kéo dài hạn cũ.

Khi tiệm sửa ngày nghỉ, giờ nhận hoặc giới hạn công suất, các đơn đã nhận giữ giờ cũ để nhân viên chủ động giải quyết. Mọi giờ mới phải qua quy tắc đang áp dụng tại thời điểm xử lý. Quản lý không thể dùng quyền ngoại lệ để vượt công suất hay chọn ngày nghỉ. Các lần đặt, đổi lịch, hủy và sửa cấu hình dùng chung khóa cửa hàng để tránh hai giao dịch cùng lấy chỗ cuối.

## Tồn giữ, bánh đã làm và lượt mã giảm

Đặt đơn thành công trừ tồn bán và ghi `stock_movements` lý do `order`. Đây là phần giữ cho đơn. Yêu cầu đổi/hủy chưa được duyệt không thay phần giữ đó.

Hủy trước bắt đầu làm giải phóng toàn bộ phần giữ và khung, ghi `cancel`, `stock_restored_at` và `inventory_processed_at` trong cùng giao dịch. Lặp lại yêu cầu hủy không tăng tồn thêm. Nhân viên chỉ tự hủy khi còn mốc yêu cầu; quá mốc chuyển quản lý duyệt.

Mọi thao tác hủy đều cần lý do. Lý do của yêu cầu khách và phản hồi cho khách được giữ riêng với ghi chú nội bộ khi xử lý trực tiếp.

Hủy sau bắt đầu làm cần quản lý và lý do. Đơn vẫn giải phóng khung khi thành `cancelled`, nhưng tồn bán chưa tăng. Đơn xuất hiện ở **Đơn cần xử lý → Bánh sau hủy**. Quản lý kiểm tra rồi phân loại **đủ từng dòng** bằng ba số nguyên không âm, có tổng đúng bằng số lượng trong đơn.

| Nhóm | Ý nghĩa và ảnh hưởng |
| --- | --- |
| `unmade` · Chưa làm | Giải phóng phần chưa làm, tăng tồn bán và ghi `cancel_unmade`. |
| `sellable` · Đã làm, còn đủ điều kiện bán | Chỉ nhập lại sau kiểm tra phù hợp, tăng tồn bán và ghi `return_checked`. |
| `waste` · Hỏng / tiêu hao / không bán lại được | Không tăng hoặc trừ tồn bán lần nữa vì đã giữ lúc đặt. Ghi riêng `waste` với `affected_quantity`, kể cả khi tồn trước / sau bằng nhau. |

Đơn đã xuất giao có `dispatched_at` từ lần đầu vào `shipping`, hoặc được nhận diện qua sự kiện xuất giao cũ. Sau khi đã xuất, máy chủ bắt buộc `unmade = 0`; chỉ xét phần nhận lại đủ điều kiện bán sau kiểm tra hoặc phần hỏng / tiêu hao. Không giải phóng bánh đã xuất như bánh chưa làm. Với đơn cũ được migration đánh dấu đã xuất từ trạng thái giao, mốc lấy từ dữ liệu lịch sử chỉ là dấu nhận diện, không khẳng định thời điểm xuất chính xác.

Mỗi dòng được lưu trong `order_inventory_dispositions`, gồm người kiểm tra, số lượng và lý do. `inventory_processed_at` khóa kết quả, không xử lý lại đơn đã chốt. Không dùng sửa tồn sản phẩm để giả làm hoàn tồn đơn hủy. Nếu cần điều chỉnh vì kiểm kê sai, dùng nhật ký điều chỉnh thủ công với lý do và người được phân công của tiệm.

Lượt mã giảm được xử lý độc lập. Cấu hình `coupon_return_on_early_cancel` mặc định bật, chỉ trả lượt khi hủy trước bắt đầu làm. `coupon_released_at` ngăn trả hai lần. Hủy sau làm hoặc nhập lại bánh đã kiểm tra không tự trả lượt mã. Tắt cấu hình sẽ giữ lượt dùng dù phần chưa làm được giải phóng.

Migration giữ các đơn đã hủy từ trước ở trạng thái kết thúc, đánh dấu đã xử lý tồn và giữ các dấu đã hoàn trước đó. Không phát lại hành động hoàn kho hoặc lượt mã cũ. Số tồn lịch sử cần tiệm đối chiếu riêng khi kiểm kê đầu ca.

## Trả trước, COD và hoàn tiền

`bank_prepay_required` mặc định bật. Với đơn chuyển khoản, yêu cầu trả trước có hiệu lực nếu **bản chụp trên đơn hoặc cấu hình hiện tại** yêu cầu. Tắt cấu hình sau khi nhận đơn không xóa cam kết trả trước đã lưu. Bật cấu hình áp dụng điều kiện chặt hơn cho đơn chuyển khoản đang chờ. Chuyển vào `preparing` hoặc `shipping` bị máy chủ chặn nếu chưa thu đủ và chưa có ngoại lệ được duyệt.

`allow_payment_override` mặc định tắt. Khi chủ tiệm cho phép, quản lý có thể duyệt ngoại lệ theo từng đơn chuyển khoản còn `pending` / `confirmed`, bắt buộc lý do. Lịch sử giữ người duyệt, thời điểm và lý do. Ngoại lệ cho phép xử lý khi chưa thu tiền, không đổi `payment` thành `paid` và không bỏ điều kiện đã thu trước khi hoàn tất.

Tắt công tắc ngoại lệ sẽ dừng cấp mới, không xóa quyết định đã duyệt trên đơn. Tiệm cần chốt rõ quyền và cách thu hồi ngoại lệ trước khi áp dụng. Bằng chứng hoàn chỉ được gửi tới quản lý hoặc người có quyền đối soát trên trang nội bộ.

COD tiếp tục được chuẩn bị trước khi thu. Nhân viên ghi đã thu tại các trạng thái đã tới bước giao / nhận theo bảng trên. Đơn giao chưa thành công hoặc khách chưa nhận vẫn phải được theo dõi, không tự coi là hoàn tất hay tự ghi đã thu.

Đơn đã trả rồi hủy xuất hiện ở **Đơn cần xử lý → Chờ hoàn tiền**. Nhân viên có quyền đối soát hoặc quản lý thực hiện hoàn bên ngoài ứng dụng, rồi ghi đủ bằng chứng sau đây.

- Đơn chuyển khoản dùng phương thức `bank`, mã giao dịch hoặc chứng từ đối chiếu từ 6 ký tự.
- Đơn COD dùng phương thức `cash`, mã phiếu chi hoặc xác nhận nhận tiền mặt từ 3 ký tự.
- Số tiền hoàn phải bằng toàn bộ `total`, xác nhận đã đối chiếu bằng chứng, kèm lý do / kết quả kiểm tra.

Máy chủ lưu `refund_evidence` với phương thức, tham chiếu, số tiền, người xác minh và thời điểm. Không cho ghi hoàn khi chưa thu tiền, khi đơn chưa hủy hoặc đã hoàn. Đây là ghi nhận đối soát thủ công, ứng dụng không thực hiện giao dịch ngân hàng và không tự chứng minh nội dung chứng từ là thật. Hiện chưa có hoàn một phần hay hoàn COD bằng chuyển khoản; tiệm cần chốt trước khi áp dụng cách đó.

## Thông báo và xử lý thư lỗi

Thông báo được lưu theo sự kiện rồi đưa vào hàng đợi `order-mail`, gồm nhận đơn và hướng dẫn chuyển khoản, thay đổi trạng thái, đổi giờ, hủy, thanh toán / hoàn, yêu cầu khách được nhận / duyệt / từ chối và liên kết thao tác riêng. Thư ở mốc sẵn sàng, đang giao, giao chưa thành công hoặc khách chưa nhận hướng dẫn khách theo dõi hay liên hệ tiệm.

`order_notifications` giữ người nhận và nội dung tại thời điểm sự kiện, nội dung được mã hóa. Khóa `event_key` duy nhất chống tạo thêm thông báo cho cùng sự kiện. Worker khóa riêng dòng thông báo, bỏ qua dòng đã có `sent_at`, dùng `Message-ID` ổn định và lưu số lần thử / loại lỗi. Giao dịch gửi thư không cập nhật đơn, tồn, yêu cầu hoặc thanh toán. Thư cũ không được dựng lại bằng trạng thái đơn mới khi worker chạy chậm.

Chạy worker theo [hướng dẫn vận hành](van-hanh.md). Khi thư lỗi, kiểm tra SMTP và hàng đợi, sửa nguyên nhân, sau đó retry đúng mã tác vụ. Không hủy hoặc đổi lại đơn để cố phát lại email. Nếu SMTP đã nhận thư nhưng kết nối hỏng trước khi ứng dụng ghi nhận thành công, vẫn có khả năng thư lặp khi retry; cùng `Message-ID` hỗ trợ đối chiếu nhưng không bảo đảm mọi nhà cung cấp loại trùng. Nhân viên kiểm tra hộp nhận / nhật ký nhà cung cấp khi gặp trường hợp này.

Giữ khóa ứng dụng, TOTP, token, liên kết riêng, thông tin SMTP và bằng chứng hoàn trong khu vực được phép. Log proxy và ứng dụng không ghi đầy đủ URL thao tác, URL tra cứu, Referer hay nội dung yêu cầu chứa dữ liệu riêng. Tệp nghiệm thu chỉ chứa dữ liệu thử đã che thông tin.

## Hướng dẫn ca bán

1. Đầu ca, mở **Đơn cần xử lý**, xem yêu cầu khách, đơn chờ xác nhận, chuyển khoản quá hạn, chờ hoàn, chưa giao / chưa nhận và bánh sau hủy. Chốt người xử lý đơn, đối soát và quản lý trực.
2. Mở yêu cầu khách, đối chiếu giờ cũ / đề nghị và lý do. Chấp nhận nếu đơn còn đủ điều kiện. Nếu đơn đã đổi hoặc giờ hết chỗ, liên hệ khách và ghi phản hồi khi từ chối; không tự chọn một giờ khác thay khách.
3. Với chuyển khoản trả trước, người có quyền đối soát xác minh sao kê rồi ghi đã thanh toán. Nếu có ngoại lệ do chủ tiệm cho phép, quản lý duyệt và ghi lý do trước khi vào bếp.
4. Xác nhận đơn, bắt đầu làm rồi chuyển sẵn sàng nhận hoặc đang giao. Nếu chưa bàn giao được, dùng trạng thái tương ứng và ghi kết quả liên hệ. Quản lý có thể đổi lịch trường hợp này; nhân viên xử lý lại theo quyết định đã thống nhất.
5. Nếu hủy trước làm, đối chiếu phần giữ và lượt mã theo cấu hình. Nếu đã làm, chuyển quản lý duyệt hủy rồi kiểm tra từng bánh, phân loại đủ và chốt một lần. Nếu đã trả tiền, theo dõi hàng chờ hoàn riêng.
6. Sau khi hoàn trả bên ngoài, người có quyền nhập đúng phương thức, đủ số tiền và tham chiếu, xác nhận đã kiểm tra rồi ghi đã hoàn. Không dùng thao tác thu tiền hoặc hoàn để vượt trạng thái đơn.
7. Cuối ca, đối chiếu `order_events`, `stock_movements`, phần phân loại sau hủy, bằng chứng hoàn và `order_notifications`. Retry thư lỗi sau khi xử lý nguyên nhân, theo dõi đơn chưa giao / chưa nhận và yêu cầu còn chờ.

## Phạm vi một cơ sở

Đơn có `location_id`, hiện `Store::locationId()` trả cơ sở `1` và `Orders::query()` áp dụng phạm vi đó. Yêu cầu, liên kết thao tác, sự kiện và thông báo đi theo `order_id` của đơn đã được kiểm tra phạm vi. Điều này tạo một chỗ để nối phạm vi cơ sở sau này.

Tồn phiên bản, cấu hình cửa hàng, khóa xử lý và tài khoản hiện phục vụ một cơ sở. Trước khi vận hành nhiều cơ sở, cần thiết kế tồn theo cơ sở, phân công nhân sự, quyền liên cơ sở, lịch, cấu hình, khóa, báo cáo và định tuyến. Không chỉ đổi `location_id` là có hệ thống nhiều cơ sở hoàn chỉnh.

## Quyết định cần chủ tiệm xác nhận

| Quyết định | Mặc định / quy tắc đang triển khai | Cần chốt trước nhận đơn thật |
| --- | --- | --- |
| Mốc nhận yêu cầu | Trước `giờ hẹn - lead time`, chỉ khi chờ hoặc đã xác nhận | Tiệm có cần thời hạn hủy riêng, thời hạn đổi giờ khác, phí đổi/hủy hoặc một mốc cắt đơn độc lập không? |
| Phê duyệt ngoại lệ | Quản lý xử lý sau mốc; hủy sau làm không tự nhập tồn | Ghi tên quản lý trực, người thay thế, cách xin duyệt và liên hệ khách. |
| Tồn bán và phần hỏng | Phân loại theo số bánh / phiên bản, không theo nguyên liệu | Ai xác nhận chất lượng, thời gian còn bán được, cách nhận lại bánh đã xuất và cách ghi tiêu hao? |
| Trả lượt mã | Chỉ trước làm khi cấu hình bật | Chốt việc cho khách dùng lại mã sau hủy và quy tắc riêng nếu cần. |
| Chuyển khoản trả trước | Mặc định yêu cầu; ngoại lệ mặc định tắt | Xác nhận có bắt buộc trả trước và có cho quản lý duyệt ngoại lệ không. |
| Hoàn tiền | Hoàn toàn bộ, đúng phương thức bank / cash, ghi nhận thủ công | Người đối soát, nơi giữ chứng từ, thời hạn hoàn, cách khách nhận phản hồi; có cần hoàn một phần hoặc khác phương thức không. |
| Đơn chưa bàn giao | Nhân viên ghi rõ, quản lý có thể đổi lịch hoặc hủy | Quy trình giao lại, giới hạn số lần, phí phát sinh và thời gian giữ bánh. Hiện chưa tự tính phí giao lại. |
| Thư và nhắc hành động | Thư theo sự kiện, receipt chuyển khoản có hạn và hướng dẫn | Hộp SMTP thật, người theo dõi thư lỗi; có cần tự động nhắc quá hạn hoặc hủy theo hạn không. |
| Đơn cũ | Quản lý xác minh snapshot trước khi đổi lịch | Đối chiếu các đơn đang mở và tồn đầu ca; không phát lại các lần hủy lịch sử. |

Giữ công tắc nhận đơn thật đóng đến khi người phụ trách phê duyệt ngoài ca thử. Không dùng dữ liệu tiệm tự bịa để vượt preflight. Dịch vụ SMTP, chứng từ hoàn và chuyển khoản thật cần xác minh riêng theo [biên bản nghiệm thu một cơ sở](nghiem-thu-mo-ban-mot-co-so.md).

## Kiểm thử và bằng chứng của lần bàn giao này

Ngày 23/11/2026, người thử Codex. Mã ứng dụng được đóng trong commit local `8861a72eaec381779389d0d88ad390f73b70f9bb`, nối tiếp `0dc3903`. Các ca chạy trên working tree của bản sửa trước khi tạo commit; lần đối chiếu cuối xác nhận source khớp commit này. Chưa push và chưa chạy GitHub Actions cho phiên bản này.

Sandbox giao diện dùng `http://127.0.0.1:18914`, schema `tiembanh_lifecycle_20261123_test`, ảnh, storage, cookie và khóa riêng. Bộ PHPUnit dùng schema khác `tiembanh_stage7_phpunit_test` trên MySQL 8.4 loopback 33317. Hai schema đều dành cho thử nghiệm. Dữ liệu mẫu của seeder được giữ rõ là demo.

| Mục | Trạng thái |
| --- | --- |
| Bản mã | ĐẠT LOCAL trên source được đóng trong `8861a72`. Mã băm source, kết quả, người thử và môi trường ở [bằng chứng](evidence/lifecycle-2026-11-23/audit.json). |
| Migration và seed | ĐẠT trên sandbox MySQL trống, tách DB ca bán và DB PHPUnit. Chưa chạy trên DB bán thật. |
| Pint, ESLint và build Vite | ĐẠT. `git diff --check` đạt. |
| PHPUnit SQLite | 61 bài đạt, 7 bài đồng thời dành riêng cho MySQL được bỏ qua, 1.308 assertions. |
| PHPUnit MySQL | 68/68 bài đạt, 1.365 assertions, dùng `--fail-on-skipped`. Đủ 7 ca hai tiến trình, gồm gửi trùng yêu cầu và duyệt đổi giờ tranh chỗ cuối với đặt mới. |
| Quyền, liên kết hết hạn, hai lần gửi, hoàn chưa thu | ĐẠT PHPUnit. Gồm đơn của người khác, tài khoản không có quyền, token dùng một lần/hết hạn, payload lặp/khác, quá mốc và snapshot cũ. Trang 410 thử thêm bằng fixture hết hạn trên trình duyệt. |
| Hủy trước / sau làm, phân loại và lượt mã | ĐẠT PHPUnit. Hoàn phần giữ một lần, mã giảm độc lập; sau làm không tự nhập tồn; kiểm kê đúng lượng một lần, đã xuất không được ghi chưa làm, tiêu hao có sổ riêng. UI chốt tiêu hao một bánh. |
| COD, chuyển khoản và hoàn | ĐẠT PHPUnit. COD tới sẵn sàng/thu/hoàn tất, hoàn tiền mặt có phiếu; chặn chuyển khoản chưa thu vào bếp/giao, snapshot không bị nới, ngoại lệ admin bật rõ, hoàn đúng phương thức/số tiền/bằng chứng. Không chuyển tiền thật. |
| Giao diện và tra cứu | ĐẠT LOCAL ở 390×844 và 1366×900. Khách yêu cầu đổi từ 24/11 14:00 sang 25/11 11:00, nhân viên duyệt, khách thấy phản hồi, tiếp tục xin hủy rồi được duyệt. Liên kết tra cứu cũ hiển thị hủy khi tiệm vẫn tạm ngừng. |
| Đối chiếu dữ liệu | Hai yêu cầu đã chấp thuận, lịch sử cũ/mới và người thao tác, một lần giải phóng tồn trước làm, một tiêu hao sau làm; bằng chứng SQL đã bỏ dữ liệu riêng nằm trong JSON. |
| Email và retry | Cả 8 thông báo được nhận đúng một bản trong hai hộp SMTPS local. Một thư lỗi kết nối được retry riêng sau sửa fixture; toàn bộ đơn, tồn, movements và events giữ nguyên trước/sau retry. Hàng đợi và thư lỗi cuối ca đều 0. |
| Mở bán thật | CHỜ người phụ trách duyệt chính sách, dữ liệu, nhân sự và dịch vụ thật. Công tắc sandbox giữ đóng. |

Ảnh đã kiểm tra không có liên kết thao tác/tra cứu riêng hoặc khóa. Các tên và liên hệ xuất hiện trong ảnh là dữ liệu seed demo.

- [Yêu cầu chờ trên điện thoại](evidence/lifecycle-2026-11-23/mobile-request.jpg), [kết quả khách nhận](evidence/lifecycle-2026-11-23/mobile-approved.jpg).
- [Nhân viên duyệt trên desktop](evidence/lifecycle-2026-11-23/desktop-approved.jpg), [chuyển khoản chưa thu](evidence/lifecycle-2026-11-23/desktop-prepay.jpg).
- [Quản lý ghi tiêu hao](evidence/lifecycle-2026-11-23/desktop-waste.jpg), [khách xem đơn hủy](evidence/lifecycle-2026-11-23/mobile-cancelled.jpg), [liên kết hết hạn](evidence/lifecycle-2026-11-23/mobile-expired.jpg).

Hộp thử ban đầu tại loopback 18902 đóng kết nối nhàn sau 12 giây, khiến một thư cạn lượt thử. Ca chuyển sang hộp SMTPS riêng 18912, kiểm chứng CA, chỉ nhận email mẫu và dùng timeout 600 giây. Retry đúng job lỗi đã gửi thư còn thiếu, không tạo sự kiện đơn mới. Kiểm theo Message-ID và băm MIME chứng minh 7 thư được nhận ở hộp cũ, 1 thư ở hộp riêng, mỗi thông báo có một bản trong ca này. Đây là kết quả của fixture local; không phải bảo đảm gửi đúng một lần qua nhà cung cấp SMTP thật.

Dùng schema MySQL hậu tố `_test` riêng với `APP_ENV=testing` và credential qua môi trường hoặc kho bí mật. Không chạy migration hoặc test trên schema bán thật. Cách cài và chạy bộ kiểm tra theo [README](../README.md). Khi diễn tập trên staging, dùng schema và ảnh riêng, hộp thư local hoặc hộp được duyệt, nạp dữ liệu demo chỉ trong local/test và tạm ngừng nhận đơn khi xong.

```powershell
php vendor/bin/pint --test
npm.cmd run lint
npm.cmd run build
$env:APP_ENV='testing'
$env:DB_URL=''
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
php vendor/bin/phpunit --do-not-cache-result
# Cấp DB_HOST/PORT/USERNAME/PASSWORD cho schema PHPUnit riêng qua môi trường.
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='tiembanh_lifecycle_phpunit_test'
php vendor/bin/phpunit --do-not-cache-result --fail-on-skipped
```

Để lặp ca giao diện trong workspace đã chuẩn bị, dùng profile riêng dưới `work/lifecycle-20261123`, khởi động bằng `start.ps1` sau khi bảo đảm các tiến trình cũ đã dừng, rồi làm lại theo hướng dẫn ca bán. Không chạy lại `setup.ps1` lên schema có dữ liệu. Profile và nhật ký đầy đủ bị Git bỏ qua vì chứa khóa và liên kết riêng. Chạy `php work/lifecycle-20261123/control.php pause` khi kết thúc. Người nhận bàn giao bằng checkout mới dựng sandbox theo README và tài liệu vận hành.

Chưa xác minh trên HTTPS được thiết bị tiệm tin cậy, SMTP thật, sao kê/chứng từ thật hoặc dữ liệu được tiệm duyệt. Bản cập nhật thêm trạng thái và dữ liệu audit mới, nên bản quay lui phải hiểu các trạng thái đó. Sao lưu trước migration; không dùng mã cũ hoặc rollback schema làm mất lịch sử sau khi đã phát sinh đơn mới.
