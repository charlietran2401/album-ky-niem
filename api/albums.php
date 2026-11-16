<?php
// API công khai: trả toàn bộ album, sự kiện và ảnh dạng JSON cho trang kệ.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = db();
    $albums = $pdo->query('SELECT * FROM albums ORDER BY ' . ALBUM_ORDER)->fetchAll();
    $events = $pdo->query("SELECT * FROM events ORDER BY event_date = '', event_date, id")->fetchAll();
    $photos = $pdo->query('SELECT * FROM photos ORDER BY id')->fetchAll();

    $photosByEvent = [];
    $looseByAlbum = [];
    $fileById = [];
    foreach ($photos as $p) {
        $item = ['id' => (int) $p['id'], 'url' => $p['file'], 'caption' => $p['caption']];
        $fileById[(int) $p['id']] = $p['file'];
        if ($p['event_id'] !== null) {
            $photosByEvent[(int) $p['event_id']][] = $item;
        } else {
            $looseByAlbum[(int) $p['album_id']][] = $item;
        }
    }

    $eventsByAlbum = [];
    foreach ($events as $e) {
        $eventsByAlbum[(int) $e['album_id']][] = [
            'id' => (int) $e['id'],
            'date' => $e['event_date'],
            'title' => $e['title'],
            'description' => $e['description'],
            'photos' => $photosByEvent[(int) $e['id']] ?? [],
        ];
    }

    $out = [];
    foreach ($albums as $a) {
        $id = (int) $a['id'];
        $cover = $a['cover_photo_id'] !== null ? ($fileById[(int) $a['cover_photo_id']] ?? null) : null;
        $out[] = [
            'id' => $id,
            'course' => (int) $a['course'],
            'year' => (int) $a['year'],
            'title' => $a['title'],
            'subtitle' => $a['subtitle'],
            'description' => $a['description'],
            'color' => $a['color'],
            'cover' => $cover,
            'events' => $eventsByAlbum[$id] ?? [],
            'photos' => $looseByAlbum[$id] ?? [],
        ];
    }

    echo json_encode([
        'site' => [
            'name' => setting('site_name', 'Album kỷ niệm'),
            'sub' => setting('site_sub', 'Lưu giữ theo năm'),
        ],
        'albums' => $out,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Không đọc được dữ liệu.']);
}
