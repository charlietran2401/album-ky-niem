<?php
// Hàm dùng chung: kết nối SQLite, tạo bảng, tiện ích.
declare(strict_types=1);

const ROOT_DIR   = __DIR__ . '/..';
const DATA_DIR   = ROOT_DIR . '/data';
const UPLOAD_DIR = ROOT_DIR . '/uploads';
const MAX_UPLOAD_BYTES = 50 * 1024 * 1024; // 50 MB mỗi ảnh
const ALBUM_COLORS = ['#1F3FB0', '#B4432B', '#2F6B4F', '#8A5A1C', '#5B3A8C', '#1E6A7A', '#9A2F55', '#3B3B3B'];
// Bảng màu cho gáy album: mỗi màu 9 sắc độ, từ nhạt đến đậm.
const ALBUM_PALETTE = [
    'Màu gốc'    => ALBUM_COLORS,
    'Đỏ'         => ['#FEE2E2', '#FECACA', '#FCA5A5', '#F87171', '#EF4444', '#DC2626', '#B91C1C', '#991B1B', '#7F1D1D'],
    'Cam'        => ['#FFEDD5', '#FED7AA', '#FDBA74', '#FB923C', '#F97316', '#EA580C', '#C2410C', '#9A3412', '#7C2D12'],
    'Vàng'       => ['#FEF3C7', '#FDE68A', '#FCD34D', '#FBBF24', '#F59E0B', '#D97706', '#B45309', '#92400E', '#78350F'],
    'Xanh lá'    => ['#DCFCE7', '#BBF7D0', '#86EFAC', '#4ADE80', '#22C55E', '#16A34A', '#15803D', '#166534', '#14532D'],
    'Xanh ngọc'  => ['#CCFBF1', '#99F6E4', '#5EEAD4', '#2DD4BF', '#14B8A6', '#0D9488', '#0F766E', '#115E59', '#134E4A'],
    'Xanh trời'  => ['#E0F2FE', '#BAE6FD', '#7DD3FC', '#38BDF8', '#0EA5E9', '#0284C7', '#0369A1', '#075985', '#0C4A6E'],
    'Xanh dương' => ['#DBEAFE', '#BFDBFE', '#93C5FD', '#60A5FA', '#3B82F6', '#2563EB', '#1D4ED8', '#1E40AF', '#1E3A8A'],
    'Chàm'       => ['#E0E7FF', '#C7D2FE', '#A5B4FC', '#818CF8', '#6366F1', '#4F46E5', '#4338CA', '#3730A3', '#312E81'],
    'Tím'        => ['#F3E8FF', '#E9D5FF', '#D8B4FE', '#C084FC', '#A855F7', '#9333EA', '#7E22CE', '#6B21A8', '#581C87'],
    'Hồng'       => ['#FCE7F3', '#FBCFE8', '#F9A8D4', '#F472B6', '#EC4899', '#DB2777', '#BE185D', '#9D174D', '#831843'],
    'Nâu'        => ['#EFE3D3', '#E0C9A8', '#CBA57A', '#B28150', '#9A6A3A', '#80542C', '#674222', '#4F321A', '#3A2413'],
    'Xám'        => ['#F5F5F4', '#E7E5E4', '#D6D3D1', '#A8A29E', '#78716C', '#57534E', '#44403C', '#292524', '#1C1917'],
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/album.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS albums (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            title TEXT NOT NULL,
            subtitle TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '#1F3FB0',
            cover_photo_id INTEGER
        );
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            album_id INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
            event_date TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            album_id INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
            event_id INTEGER REFERENCES events(id) ON DELETE SET NULL,
            file TEXT NOT NULL,
            caption TEXT NOT NULL DEFAULT ''
        );
    ");
    // Cột khóa (thêm sau): điền sẵn từ "K23" trong tên album cũ.
    $cols = array_column($pdo->query('PRAGMA table_info(albums)')->fetchAll(), 'name');
    if (!in_array('course', $cols, true)) {
        $pdo->exec('ALTER TABLE albums ADD COLUMN course INTEGER NOT NULL DEFAULT 0');
        $up = $pdo->prepare('UPDATE albums SET course = ? WHERE id = ?');
        foreach ($pdo->query('SELECT id, title FROM albums')->fetchAll() as $a) {
            if (preg_match('/\bK\s?(\d{1,3})\b/i', $a['title'], $m)) {
                $up->execute([(int) $m[1], $a['id']]);
            }
        }
    }
    return $pdo;
}

// Thứ tự kệ: theo khóa, trong khóa theo năm; album chưa có khóa (0) nằm cuối.
const ALBUM_ORDER = 'course = 0, course, year, id';

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(string $key, string $default = ''): string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
        ->execute([$key, $value]);
}

/** Xoá file ảnh trên đĩa, chỉ khi đường dẫn nằm trong uploads/. */
function delete_photo_file(string $relative): void
{
    $base = realpath(UPLOAD_DIR);
    $path = realpath(ROOT_DIR . '/' . $relative);
    if ($base && $path && strncmp($path, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0 && is_file($path)) {
        unlink($path);
    }
}
