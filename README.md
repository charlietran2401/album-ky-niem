# Album kỷ niệm

Trang kệ album cong 3D: mỗi cuốn album là một năm, bấm vào bìa để xem sự kiện và ảnh của năm đó.
Giao diện là HTML / CSS / JS thuần; dữ liệu do admin nhập qua trang quản trị (PHP + SQLite).

## Yêu cầu

- PHP 8.1 trở lên, có sẵn `pdo_sqlite` và `fileinfo` (XAMPP, Laragon và hầu hết hosting PHP đều có).

## Chạy thử trên máy

Mở terminal trong thư mục này rồi chạy:

```
php -d upload_max_filesize=50M -d post_max_size=100M -S localhost:8000
```

- Trang album: http://localhost:8000/
- Trang quản trị: http://localhost:8000/admin/

Lần đầu vào trang quản trị, bạn sẽ được yêu cầu **tạo tài khoản admin** (tên đăng nhập + mật khẩu từ 8 ký tự). Sau đó chỉ tài khoản này đăng nhập được.

Nếu mở thẳng `index.html` mà không chạy PHP, trang sẽ hiện dữ liệu mẫu trong `js/sample-data.js`.

## Cách nhập dữ liệu

1. Vào **Quản trị → Thêm album**: nhập năm, tên album, màu gáy.
2. Trong album, **thêm sự kiện** (ngày, tên, mô tả).
3. **Tải ảnh lên**, chọn sự kiện để gắn ảnh vào, hoặc để "ảnh chung của album".
4. Ảnh đầu tiên tự làm ảnh bìa; đổi bằng nút **Đặt làm bìa**.
5. **Cài đặt**: đổi tên trang, dòng phụ và mật khẩu.

## Chạy online từ máy này (Cloudflare Tunnel)

Bấm đúp `chay-online.bat`: chạy trang ở `127.0.0.1:8100` (qua `router.php`) và mở tunnel `clb5tot`,
tunnel trỏ `luongtran.online` và `www.luongtran.online` vào cổng đó (xem `~/.cloudflared/config.yml`).
Máy phải bật và hai cửa sổ phải mở thì trang mới truy cập được.

`router.php` thay cho `.htaccess` (máy chủ `php -S` không đọc `.htaccess`): chặn `data/`, file ẩn,
mã PHP ngoài trang chính và mọi file không phải ảnh trong `uploads/`. Luôn chạy kèm `router.php`.

## Đưa lên hosting

Chép toàn bộ thư mục lên hosting PHP. Cần cho PHP quyền ghi vào `data/` và `uploads/`.

- `data/album.sqlite` chứa toàn bộ dữ liệu và tài khoản; `data/.htaccess` chặn tải file này qua web.
- `uploads/.htaccess` chặn chạy mã trong thư mục ảnh.
- Hai file `.htaccess` chỉ có tác dụng trên Apache. Với Nginx, bạn cần tự thêm luật chặn truy cập `/data/` và chặn thực thi PHP trong `/uploads/`.
- `.user.ini` nâng giới hạn tải lên (50 MB mỗi ảnh, 100 MB mỗi lần tải). Một số hosting đặt giới hạn riêng trong bảng điều khiển.
- Nên dùng HTTPS để mật khẩu admin không đi qua mạng ở dạng thô.

Sao lưu: giữ lại `data/album.sqlite` và thư mục `uploads/`.

## Cấu trúc

```
index.html            Trang kệ album
css/style.css         Giao diện
js/app.js             Kệ cong 3D, thanh thời gian, trang chi tiết, xem ảnh
js/sample-data.js     Dữ liệu mẫu khi không có PHP
api/lib.php           Kết nối SQLite, tạo bảng
api/albums.php        API công khai trả JSON
admin/index.php       Đăng nhập và quản lý album, sự kiện, ảnh
admin/admin.css       Giao diện trang quản trị
data/                 Cơ sở dữ liệu SQLite (tự tạo khi chạy lần đầu)
uploads/              Ảnh tải lên
```

## Điều khiển trên trang

- Kéo, lăn chuột, bấm mũi tên hoặc phím ← → để duyệt album; bấm thanh thời gian để nhảy tới năm.
- Bấm bìa album (hoặc Enter) để mở; Esc để đóng.
- Trong album, bấm ảnh để xem lớn; ← → chuyển ảnh.
