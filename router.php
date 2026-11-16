<?php
// Bộ định tuyến cho máy chủ PHP có sẵn (php -S), dùng khi chạy qua Cloudflare Tunnel.
// php -S không đọc .htaccess, nên file này thay việc chặn: data/, mã trong uploads/, file ẩn, file nội bộ.
declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');

$deny = static function (): bool {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Không tìm thấy.';
    return true;
};

// Chặn file/thư mục ẩn (.htaccess, .user.ini, .git...) và thư mục dữ liệu.
// Windows bỏ qua dấu chấm/khoảng trắng cuối tên và hiểu "::$DATA", nên chặn luôn các dạng lách đó.
if (preg_match('#/\.#', $path) || preg_match('#^/data(/|$)#i', $path) || preg_match('#[.\s](/|$)|:#', $path)) {
    return $deny();
}
// Trong uploads/ chỉ cho phép ảnh.
if (preg_match('#^/uploads/#i', $path) && !preg_match('#\.(jpe?g|png|webp|gif)$#i', $path)) {
    return $deny();
}
// Chỉ chạy đúng các trang PHP của site; mọi file .php khác (kể cả router.php) bị chặn.
if (preg_match('#\.php$#i', $path) && !in_array(strtolower($path), ['/api/albums.php', '/admin/index.php'], true)) {
    return $deny();
}
// Chỉ phục vụ các loại file của trang web (chặn README.md, .bat, .sqlite...).
if (preg_match('#\.[a-z0-9]+$#i', $path) && !preg_match('#\.(php|html|css|js|jpe?g|png|webp|gif|svg|ico|woff2?)$#i', $path)) {
    return $deny();
}
return false; // để máy chủ PHP phục vụ file như bình thường
