<?php
// Trang quản trị: đăng nhập, quản lý album, sự kiện và ảnh.
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_name('album_admin');
session_start();

$pdo = db();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function redirect(string $query = ''): never
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function flash(string $msg, bool $ok = true): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'ok' => $ok];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function clean_color(string $c): string
{
    return in_array($c, array_merge(...array_values(ALBUM_PALETTE)), true) ? $c : ALBUM_COLORS[0];
}

/** Quay lại đúng sự kiện đang sửa (mở sẵn), hoặc mục mặc định của album. */
function clean_course(string $c): int
{
    return max(0, min(999, (int) $c)); // 0 = chưa có khóa
}

function back_to(int $albumId, ?int $eventId, string $anchor): string
{
    return 'p=album&id=' . $albumId . ($eventId ? '&ev=' . $eventId . '#ev-' . $eventId : '#' . $anchor);
}

function clean_date(string $d): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
}

/** Trả id sự kiện nếu nó thuộc album, ngược lại null. */
function event_in_album(PDO $pdo, string $eventId, int $albumId): ?int
{
    if ($eventId === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM events WHERE id = ? AND album_id = ?');
    $st->execute([(int) $eventId, $albumId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

$hasUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
$loggedIn = !empty($_SESSION['uid']);
$page = (string) ($_GET['p'] ?? 'albums');

// ---------- Xử lý POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Phiên làm việc đã hết hạn. Hãy tải lại trang và thử lại.');
    }
    $action = post('action');

    if ($action === 'setup' && !$hasUser) {
        $u = post('username');
        $p = (string) ($_POST['password'] ?? '');
        if ($u === '' || strlen($p) < 8) {
            flash('Nhập tên đăng nhập và mật khẩu từ 8 ký tự trở lên.', false);
            redirect();
        }
        $pdo->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)')
            ->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $pdo->lastInsertId();
        flash('Đã tạo tài khoản admin.');
        redirect();
    }

    if ($action === 'login') {
        $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([post('username')]);
        $user = $st->fetch();
        if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $user['id'];
            redirect();
        }
        sleep(1); // làm chậm việc dò mật khẩu
        flash('Sai tên đăng nhập hoặc mật khẩu.', false);
        redirect();
    }

    if (!$loggedIn) {
        redirect();
    }

    switch ($action) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect();

        case 'album_create':
            $year = (int) post('year');
            $title = post('title');
            if ($year < 1800 || $year > 2200 || $title === '') {
                flash('Cần nhập năm hợp lệ và tên album.', false);
                redirect();
            }
            $pdo->prepare('INSERT INTO albums (year, title, subtitle, description, color, course) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$year, $title, post('subtitle'), post('description'), clean_color(post('color')), clean_course(post('course'))]);
            flash('Đã thêm album. Giờ bạn thêm sự kiện và ảnh.');
            redirect('p=album&id=' . $pdo->lastInsertId());

        case 'album_update':
            $id = (int) post('id');
            $year = (int) post('year');
            $title = post('title');
            if ($year < 1800 || $year > 2200 || $title === '') {
                flash('Cần nhập năm hợp lệ và tên album.', false);
            } else {
                $pdo->prepare('UPDATE albums SET year = ?, title = ?, subtitle = ?, description = ?, color = ?, course = ? WHERE id = ?')
                    ->execute([$year, $title, post('subtitle'), post('description'), clean_color(post('color')), clean_course(post('course')), $id]);
                flash('Đã lưu album.');
            }
            redirect('p=album&id=' . $id);

        case 'album_delete':
            $id = (int) post('id');
            $st = $pdo->prepare('SELECT file FROM photos WHERE album_id = ?');
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $file) {
                delete_photo_file($file);
            }
            $pdo->prepare('DELETE FROM albums WHERE id = ?')->execute([$id]);
            @rmdir(UPLOAD_DIR . '/' . $id);
            flash('Đã xoá album.');
            redirect();

        case 'event_create':
            $albumId = (int) post('album_id');
            if (post('title') === '') {
                flash('Cần nhập tên sự kiện.', false);
            } else {
                $pdo->prepare('INSERT INTO events (album_id, event_date, title, description) VALUES (?, ?, ?, ?)')
                    ->execute([$albumId, clean_date(post('event_date')), post('title'), post('description')]);
                flash('Đã thêm sự kiện. Giờ bạn viết bài và tải ảnh cho sự kiện.');
                redirect(back_to($albumId, (int) $pdo->lastInsertId(), 'su-kien'));
            }
            redirect('p=album&id=' . $albumId . '#su-kien');

        case 'event_update':
            $albumId = (int) post('album_id');
            if (post('title') === '') {
                flash('Cần nhập tên sự kiện.', false);
            } else {
                $pdo->prepare('UPDATE events SET event_date = ?, title = ?, description = ? WHERE id = ? AND album_id = ?')
                    ->execute([clean_date(post('event_date')), post('title'), post('description'), (int) post('id'), $albumId]);
                flash('Đã lưu sự kiện.');
            }
            redirect(back_to($albumId, (int) post('id'), 'su-kien'));

        case 'event_delete':
            $albumId = (int) post('album_id');
            $pdo->prepare('DELETE FROM events WHERE id = ? AND album_id = ?')->execute([(int) post('id'), $albumId]);
            flash('Đã xoá sự kiện. Ảnh của sự kiện vẫn còn trong album.');
            redirect('p=album&id=' . $albumId . '#su-kien');

        case 'photo_upload':
            $albumId = (int) post('album_id');
            $st = $pdo->prepare('SELECT id FROM albums WHERE id = ?');
            $st->execute([$albumId]);
            if (!$st->fetchColumn()) {
                redirect();
            }
            $eventId = event_in_album($pdo, post('event_id'), $albumId);
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $dir = UPLOAD_DIR . '/' . $albumId;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $files = $_FILES['photos'] ?? null;
            $saved = 0;
            $skipped = 0;
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $tmp = $files['tmp_name'][$i];
                $mime = $files['error'][$i] === UPLOAD_ERR_OK ? $finfo->file($tmp) : '';
                if (!isset($types[$mime]) || $files['size'][$i] > MAX_UPLOAD_BYTES || !@getimagesize($tmp)) {
                    $skipped++;
                    continue;
                }
                $name = bin2hex(random_bytes(8)) . '.' . $types[$mime];
                if (move_uploaded_file($tmp, $dir . '/' . $name)) {
                    $pdo->prepare('INSERT INTO photos (album_id, event_id, file, caption) VALUES (?, ?, ?, ?)')
                        ->execute([$albumId, $eventId, 'uploads/' . $albumId . '/' . $name, '']);
                    $saved++;
                    $lastId = (int) $pdo->lastInsertId();
                } else {
                    $skipped++;
                }
            }
            if (post('as_cover') === '1' && !empty($lastId)) {
                $pdo->prepare('UPDATE albums SET cover_photo_id = ? WHERE id = ?')->execute([$lastId, $albumId]);
                flash($saved ? 'Đã cập nhật ảnh bìa.' : 'Ảnh không hợp lệ hoặc quá 50 MB.', $saved > 0);
                redirect('p=album&id=' . $albumId . '#thong-tin');
            }
            // Ảnh đầu tiên của album tự động làm bìa.
            $pdo->prepare('UPDATE albums SET cover_photo_id = (SELECT MIN(id) FROM photos WHERE album_id = ?) WHERE id = ? AND cover_photo_id IS NULL')
                ->execute([$albumId, $albumId]);
            if ($saved === 0 && $skipped === 0) {
                flash('Chưa chọn ảnh nào (hoặc ảnh vượt giới hạn tải lên của máy chủ).', false);
            } else {
                flash("Đã tải lên $saved ảnh." . ($skipped ? " Bỏ qua $skipped file không hợp lệ hoặc quá 50 MB." : ''), $saved > 0);
            }
            redirect(back_to($albumId, $eventId, 'anh'));

        case 'photo_update':
            $albumId = (int) post('album_id');
            $pdo->prepare('UPDATE photos SET caption = ?, event_id = ? WHERE id = ? AND album_id = ?')
                ->execute([post('caption'), event_in_album($pdo, post('event_id'), $albumId), (int) post('id'), $albumId]);
            flash('Đã lưu ảnh.');
            redirect(back_to($albumId, (int) post('ev') ?: null, 'anh'));

        case 'photo_cover':
            $albumId = (int) post('album_id');
            $st = $pdo->prepare('SELECT id FROM photos WHERE id = ? AND album_id = ?');
            $st->execute([(int) post('id'), $albumId]);
            if ($st->fetchColumn()) {
                $pdo->prepare('UPDATE albums SET cover_photo_id = ? WHERE id = ?')->execute([(int) post('id'), $albumId]);
                flash('Đã đặt ảnh bìa.');
            }
            redirect(back_to($albumId, (int) post('ev') ?: null, 'anh'));

        case 'photo_delete':
            $albumId = (int) post('album_id');
            $st = $pdo->prepare('SELECT file FROM photos WHERE id = ? AND album_id = ?');
            $st->execute([(int) post('id'), $albumId]);
            $file = $st->fetchColumn();
            if ($file !== false) {
                delete_photo_file((string) $file);
                $pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([(int) post('id')]);
                $pdo->prepare('UPDATE albums SET cover_photo_id = (SELECT MIN(id) FROM photos WHERE album_id = ?) WHERE id = ? AND cover_photo_id = ?')
                    ->execute([$albumId, $albumId, (int) post('id')]);
                flash('Đã xoá ảnh.');
            }
            redirect(back_to($albumId, (int) post('ev') ?: null, 'anh'));

        case 'settings_save':
            set_setting('site_name', post('site_name') !== '' ? post('site_name') : 'Album kỷ niệm');
            set_setting('site_sub', post('site_sub'));
            flash('Đã lưu thông tin trang.');
            redirect('p=settings');

        case 'password_change':
            $st = $pdo->prepare('SELECT pass_hash FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify((string) ($_POST['old_password'] ?? ''), (string) $st->fetchColumn())) {
                flash('Mật khẩu hiện tại không đúng.', false);
            } elseif (strlen($new) < 8) {
                flash('Mật khẩu mới cần từ 8 ký tự trở lên.', false);
            } else {
                $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['uid']]);
                flash('Đã đổi mật khẩu.');
            }
            redirect('p=settings');
    }
    redirect();
}

// ---------- Hiển thị ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function color_select(string $current): string
{
    $current = $current !== '' ? $current : ALBUM_COLORS[0];
    $out = '<div class="palette" role="radiogroup" aria-label="Màu gáy album">';
    foreach (ALBUM_PALETTE as $name => $shades) {
        $out .= '<div class="palette-row"><span class="palette-name">' . h($name) . '</span><div class="swatches">';
        foreach ($shades as $i => $c) {
            $label = $name . ($name === 'Màu gốc' ? ' ' . ($i + 1) : ' — mức ' . ($i + 1) . '/9');
            $out .= '<label class="swatch" style="--c:' . h($c) . '" title="' . h($label . ' · ' . $c) . '">'
                . '<input type="radio" name="color" value="' . h($c) . '" aria-label="' . h($label) . '"'
                . ($current === $c ? ' checked' : '') . '><span></span></label>';
        }
        $out .= '</div></div>';
    }
    return $out . '</div>';
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Quản trị album</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600&family=Playfair+Display:ital,wght@0,500;0,600;1,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>
<?php if (!$loggedIn): ?>
<main class="auth">
  <div class="auth-card">
    <p class="eyebrow"><?= h(setting('site_name', 'Album kỷ niệm')) ?></p>
    <h1><?= $hasUser ? 'Đăng nhập quản trị' : 'Tạo tài khoản admin' ?></h1>
    <?php if (!$hasUser): ?><p class="hint">Đây là lần chạy đầu tiên. Tài khoản bạn tạo bây giờ là tài khoản admin duy nhất.</p><?php endif; ?>
    <?php if ($flash): ?><p class="flash <?= $flash['ok'] ? 'ok' : 'err' ?>" role="status"><?= h($flash['msg']) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $hasUser ? 'login' : 'setup' ?>">
      <label>Tên đăng nhập<input name="username" required autocomplete="username" autofocus></label>
      <label>Mật khẩu<?= $hasUser ? '' : ' (từ 8 ký tự)' ?><input type="password" name="password" required <?= $hasUser ? '' : 'minlength="8"' ?> autocomplete="<?= $hasUser ? 'current-password' : 'new-password' ?>"></label>
      <button class="btn primary block"><?= $hasUser ? 'Đăng nhập' : 'Tạo tài khoản' ?></button>
    </form>
  </div>
  <p class="auth-back"><a href="../">← Về trang album</a></p>
</main>
<?php else: ?>
<?php
  $albums = $pdo->query('SELECT a.*, c.file AS cover_file,
      (SELECT COUNT(*) FROM events e WHERE e.album_id = a.id) AS n_events,
      (SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id) AS n_photos
    FROM albums a LEFT JOIN photos c ON c.id = a.cover_photo_id ORDER BY a.' . str_replace(', ', ', a.', ALBUM_ORDER))->fetchAll();
  $curId = $page === 'album' ? (int) ($_GET['id'] ?? 0) : 0;
?>
<div class="shell">
<aside class="side">
  <a class="logo" href="index.php">
    <span class="logo-mark" aria-hidden="true"></span>
    <span><b><?= h(setting('site_name', 'Album kỷ niệm')) ?></b><small>Quản trị</small></span>
  </a>
  <nav class="side-nav" aria-label="Điều hướng chính">
    <a href="index.php" <?= $page !== 'settings' && !$curId ? 'aria-current="page"' : '' ?>>Tổng quan</a>
    <a href="index.php?p=settings" <?= $page === 'settings' ? 'aria-current="page"' : '' ?>>Cài đặt</a>
    <a href="../">Xem trang</a>
  </nav>
  <?php if ($albums): ?>
  <p class="side-label">Album</p>
  <nav class="side-albums" aria-label="Danh sách album">
    <?php foreach ($albums as $a): ?>
    <a href="index.php?p=album&amp;id=<?= (int) $a['id'] ?>" style="--c:<?= h($a['color']) ?>" <?= $curId === (int) $a['id'] ? 'aria-current="page"' : '' ?>><i></i><span><?= (int) $a['year'] ?></span> <?= h($a['title']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <form method="post" class="side-out"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="link">Đăng xuất</button></form>
</aside>

<main class="wrap">
<?php if ($flash): ?><p class="flash <?= $flash['ok'] ? 'ok' : 'err' ?>" role="status"><?= h($flash['msg']) ?></p><?php endif; ?>

<?php if ($page === 'settings'): ?>
  <header class="head">
    <p class="eyebrow">Cấu hình</p>
    <h1>Cài đặt</h1>
  </header>
  <div class="grid-2">
    <section class="card">
      <h2>Thông tin trang</h2>
      <p class="hint">Hiện ở góc trên bên trái trang album.</p>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings_save">
        <label>Tên trang<input name="site_name" maxlength="60" value="<?= h(setting('site_name', 'Album kỷ niệm')) ?>"></label>
        <label>Dòng phụ<input name="site_sub" maxlength="60" value="<?= h(setting('site_sub', 'Lưu giữ theo năm')) ?>"></label>
        <button class="btn primary">Lưu thông tin</button>
      </form>
    </section>
    <section class="card">
      <h2>Đổi mật khẩu</h2>
      <p class="hint">Mật khẩu mới cần từ 8 ký tự.</p>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="password_change">
        <label>Mật khẩu hiện tại<input type="password" name="old_password" required autocomplete="current-password"></label>
        <label>Mật khẩu mới<input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
        <button class="btn primary">Đổi mật khẩu</button>
      </form>
    </section>
  </div>

<?php elseif ($page === 'album' && ($album = (function () use ($pdo) {
    $st = $pdo->prepare('SELECT * FROM albums WHERE id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    return $st->fetch();
})())): ?>
  <?php
    $aid = (int) $album['id'];
    $st = $pdo->prepare("SELECT * FROM events WHERE album_id = ? ORDER BY event_date = '', event_date, id");
    $st->execute([$aid]);
    $events = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM photos WHERE album_id = ? ORDER BY id');
    $st->execute([$aid]);
    $photos = $st->fetchAll();
    $byEvent = [];
    foreach ($photos as $p) {
        $byEvent[(int) $p['event_id']][] = $p;
    }
    $openEv = (int) ($_GET['ev'] ?? 0);
  ?>
  <header class="head" style="--c:<?= h($album['color']) ?>">
    <a class="back" href="index.php">← Tất cả album</a>
    <p class="eyebrow">Album năm <?= (int) $album['year'] ?></p>
    <h1><?= h($album['title']) ?></h1>
    <p class="head-meta"><?= count($events) ?> sự kiện · <?= count($photos) ?> ảnh</p>
    <nav class="tabs" aria-label="Phần trong album">
      <a href="#thong-tin">Thông tin</a><a href="#su-kien">Sự kiện</a><a href="#anh">Ảnh</a><a href="#xoa">Xoá album</a>
    </nav>
  </header>

  <section class="card" id="thong-tin">
    <h2>Thông tin album</h2>
    <?php $coverFile = ''; foreach ($photos as $p) { if ((int) $p['id'] === (int) $album['cover_photo_id']) { $coverFile = $p['file']; } } ?>
    <form method="post" enctype="multipart/form-data" class="cover-up">
      <?= csrf_field() ?><input type="hidden" name="action" value="photo_upload"><input type="hidden" name="album_id" value="<?= $aid ?>"><input type="hidden" name="as_cover" value="1">
      <span class="cover-prev"<?= $coverFile ? ' style="background-image:url(&quot;../' . h($coverFile) . '&quot;)"' : '' ?>><?= $coverFile ? '' : 'Chưa có bìa' ?></span>
      <div class="cover-ctl">
        <strong>Ảnh bìa</strong>
        <p class="hint">Ảnh hiện trên bìa album ở trang chính. Ảnh mới sẽ được thêm vào mục Ảnh của album.</p>
        <label class="btn file">Chọn ảnh bìa<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" required onchange="this.form.submit()"></label>
      </div>
    </form>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="album_update"><input type="hidden" name="id" value="<?= $aid ?>">
      <div class="row">
<label class="narrow">Khóa (K)<input type="number" name="course" min="1" max="999" placeholder="23" value="<?= (int) $album['course'] ?: '' ?>"></label>
        <label class="narrow">Năm<input type="number" name="year" min="1800" max="2200" required value="<?= (int) $album['year'] ?>"></label>
        <label>Tên album<input name="title" required maxlength="60" value="<?= h($album['title']) ?>"></label>
      </div>
      <p class="hint">Kệ album xếp theo khóa (K23, K24…), trong mỗi khóa xếp theo năm. Để trống ô Khóa thì album nằm cuối kệ.</p>
      <label><span>Dòng phụ <em>(không bắt buộc)</em></span><input name="subtitle" maxlength="80" value="<?= h($album['subtitle']) ?>"></label>
      <label>Mô tả<textarea name="description" rows="3"><?= h($album['description']) ?></textarea></label>
      <div class="field">Màu gáy album<?= color_select($album['color']) ?></div>
      <button class="btn primary">Lưu album</button>
    </form>
  </section>

  <section class="card" id="su-kien">
    <h2>Sự kiện <span class="count"><?= count($events) ?></span></h2>
    <?php if (!$events): ?><p class="empty-note">Chưa có sự kiện. Thêm sự kiện đầu tiên ở dưới.</p><?php endif; ?>
    <div class="items">
    <?php foreach ($events as $e): $eid = (int) $e['id']; $evPhotos = $byEvent[$eid] ?? []; ?>
    <details class="item" id="ev-<?= $eid ?>" <?= $openEv === $eid ? 'open' : '' ?>>
      <summary>
        <span class="date"><?= $e['event_date'] !== '' ? h(date('d/m/Y', strtotime($e['event_date']))) : 'Không ngày' ?></span>
        <span class="item-title"><?= h($e['title']) ?></span>
        <span class="item-meta"><?= trim($e['description']) !== '' ? 'Có bài viết · ' : '' ?><?= count($evPhotos) ?> ảnh</span>
      </summary>
      <div class="item-body">
        <form method="post" class="stack" id="evf-<?= $eid ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="event_update"><input type="hidden" name="id" value="<?= $eid ?>"><input type="hidden" name="album_id" value="<?= $aid ?>">
          <div class="row">
            <label class="narrow">Ngày<input type="date" name="event_date" value="<?= h($e['event_date']) ?>"></label>
            <label>Tên sự kiện<input name="title" required maxlength="120" value="<?= h($e['title']) ?>"></label>
          </div>

          <div class="field">
            <span>Ảnh của sự kiện <em>(<?= count($evPhotos) ?>)</em></span>
            <?php if ($evPhotos): ?>
            <div class="ev-photos">
              <?php foreach ($evPhotos as $p): $pid = (int) $p['id']; ?>
              <figure class="ev-photo">
                <img src="../<?= h($p['file']) ?>" alt="<?= h($p['caption']) ?>" loading="lazy">
                <?php if ((int) $album['cover_photo_id'] === $pid): ?><span class="tag">Bìa</span><?php endif; ?>
                <figcaption>
                  <button type="button" class="link" data-photo="<?= $pid ?>">Chèn vào bài</button>
                  <button class="link danger" form="evdel-<?= $eid ?>" name="id" value="<?= $pid ?>" aria-label="Xoá ảnh này">Xoá</button>
                </figcaption>
              </figure>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="ev-upload">
              <label class="drop small">
                <input type="file" name="photos[]" form="evup-<?= $eid ?>" accept="image/jpeg,image/png,image/webp,image/gif" multiple required
                  onchange="this.nextElementSibling.firstElementChild.textContent = this.files.length ? 'Đã chọn ' + this.files.length + ' ảnh' : 'Chọn ảnh cho sự kiện này'">
                <span><b>Chọn ảnh cho sự kiện này</b><small>Chọn được nhiều ảnh · tối đa 50 MB mỗi ảnh</small></span>
              </label>
              <button class="btn" form="evup-<?= $eid ?>">Tải ảnh lên</button>
            </div>
          </div>

          <div class="field editor">
            <span>Bài viết <em>(hiện dưới tên sự kiện trên trang album)</em></span>
            <div class="toolbar" role="toolbar" aria-label="Định dạng bài viết">
              <button type="button" data-md="b" title="Chữ đậm"><b>B</b></button>
              <button type="button" data-md="i" title="Chữ nghiêng"><i>I</i></button>
              <button type="button" data-md="h">Tiêu đề</button>
              <button type="button" data-md="ul">• Danh sách</button>
              <button type="button" data-md="q">“ Trích dẫn</button>
            </div>
            <textarea name="description" rows="12" aria-label="Bài viết" placeholder="Kể lại sự kiện… Để trống một dòng giữa các đoạn. Bấm “Chèn vào bài” dưới ảnh để đặt ảnh vào giữa bài."><?= h($e['description']) ?></textarea>
            <details class="md-help"><summary>Cách định dạng</summary>
              <p><code>**chữ đậm**</code> · <code>*chữ nghiêng*</code> · dòng bắt đầu bằng <code>## </code> là tiêu đề, <code>- </code> là danh sách, <code>&gt; </code> là trích dẫn · <code>[ảnh:12]</code> trên một dòng riêng là ảnh số 12 của sự kiện. Ảnh đã chèn vào bài sẽ không lặp lại trong lưới ảnh bên dưới bài.</p>
            </details>
          </div>

          <div class="actions">
            <button class="btn primary">Lưu sự kiện</button>
            <a class="btn ghost" href="../#ev-<?= $eid ?>">Xem trên trang</a>
            <button class="btn danger ghost" form="del-ev-<?= $eid ?>">Xoá sự kiện</button>
          </div>
        </form>
        <form method="post" id="evup-<?= $eid ?>" enctype="multipart/form-data" data-guard="evf-<?= $eid ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="photo_upload"><input type="hidden" name="album_id" value="<?= $aid ?>"><input type="hidden" name="event_id" value="<?= $eid ?>">
        </form>
        <form method="post" id="evdel-<?= $eid ?>" data-guard="evf-<?= $eid ?>" onsubmit="return confirm('Xoá ảnh này?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="album_id" value="<?= $aid ?>"><input type="hidden" name="ev" value="<?= $eid ?>">
        </form>
        <form method="post" id="del-ev-<?= $eid ?>" onsubmit="return confirm('Xoá sự kiện này? Ảnh của sự kiện vẫn được giữ trong album.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="event_delete"><input type="hidden" name="id" value="<?= $eid ?>"><input type="hidden" name="album_id" value="<?= $aid ?>">
        </form>
      </div>
    </details>
    <?php endforeach; ?>
    </div>
    <details class="add" <?= $events ? '' : 'open' ?>>
      <summary class="btn">+ Thêm sự kiện</summary>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="event_create"><input type="hidden" name="album_id" value="<?= $aid ?>">
        <div class="row">
          <label class="narrow"><span>Ngày <em>(không bắt buộc)</em></span><input type="date" name="event_date"></label>
          <label>Tên sự kiện<input name="title" required maxlength="120"></label>
        </div>
        <p class="hint">Sau khi thêm, sự kiện sẽ mở sẵn để bạn viết bài và tải ảnh.</p>
        <button class="btn primary">Thêm sự kiện</button>
      </form>
    </details>
  </section>

  <section class="card" id="anh">
    <h2>Ảnh <span class="count"><?= count($photos) ?></span></h2>
    <form method="post" enctype="multipart/form-data" class="upload">
      <?= csrf_field() ?><input type="hidden" name="action" value="photo_upload"><input type="hidden" name="album_id" value="<?= $aid ?>">
      <label class="drop">
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required
          onchange="this.nextElementSibling.firstElementChild.textContent = this.files.length ? 'Đã chọn ' + this.files.length + ' ảnh' : 'Bấm để chọn ảnh hoặc kéo thả vào đây'">
        <span><b>Bấm để chọn ảnh hoặc kéo thả vào đây</b><small>JPG, PNG, WebP, GIF · tối đa 50 MB mỗi ảnh · chọn được nhiều ảnh</small></span>
      </label>
      <div class="row end">
        <label>Gắn vào sự kiện
          <select name="event_id">
            <option value="">Không gắn (ảnh chung của album)</option>
            <?php foreach ($events as $e): ?><option value="<?= (int) $e['id'] ?>"><?= h($e['title']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn primary">Tải ảnh lên</button>
      </div>
    </form>
    <?php if (!$photos): ?><p class="empty-note">Album chưa có ảnh nào.</p><?php endif; ?>
    <div class="photos">
      <?php foreach ($photos as $p): $isCover = (int) $album['cover_photo_id'] === (int) $p['id']; ?>
      <div class="photo<?= $isCover ? ' is-cover' : '' ?>">
        <div class="photo-img">
          <img src="../<?= h($p['file']) ?>" alt="<?= h($p['caption']) ?>" loading="lazy">
          <?php if ($isCover): ?><span class="tag">Ảnh bìa</span><?php endif; ?>
        </div>
        <form method="post" class="stack tight">
          <?= csrf_field() ?><input type="hidden" name="action" value="photo_update"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="album_id" value="<?= $aid ?>">
          <input name="caption" maxlength="200" placeholder="Chú thích" aria-label="Chú thích" value="<?= h($p['caption']) ?>">
          <select name="event_id" aria-label="Sự kiện">
            <option value="">Ảnh chung</option>
            <?php foreach ($events as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (int) $p['event_id'] === (int) $e['id'] ? 'selected' : '' ?>><?= h($e['title']) ?></option><?php endforeach; ?>
          </select>
          <button class="btn small">Lưu</button>
        </form>
        <div class="photo-actions">
          <?php if (!$isCover): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="photo_cover"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="album_id" value="<?= $aid ?>"><button class="link">Đặt làm bìa</button></form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Xoá ảnh này?')"><?= csrf_field() ?><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="album_id" value="<?= $aid ?>"><button class="link danger">Xoá</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card danger-zone" id="xoa">
    <h2>Xoá album</h2>
    <p class="hint">Xoá album sẽ xoá luôn toàn bộ sự kiện và ảnh của năm này. Không khôi phục được.</p>
    <form method="post" onsubmit="return confirm('Xoá album <?= (int) $album['year'] ?> cùng toàn bộ sự kiện và ảnh?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="album_delete"><input type="hidden" name="id" value="<?= $aid ?>">
      <button class="btn danger">Xoá album <?= (int) $album['year'] ?></button>
    </form>
  </section>

<?php else: ?>
  <header class="head">
    <p class="eyebrow">Xin chào</p>
    <h1>Tổng quan</h1>
  </header>
  <div class="stats">
    <div class="stat"><b><?= count($albums) ?></b><span>album</span></div>
    <div class="stat"><b><?= array_sum(array_column($albums, 'n_events')) ?></b><span>sự kiện</span></div>
    <div class="stat"><b><?= array_sum(array_column($albums, 'n_photos')) ?></b><span>ảnh</span></div>
  </div>

  <h2 class="section-title">Album của bạn</h2>
  <?php if (!$albums): ?><p class="empty-note">Chưa có album nào. Thêm album đầu tiên ở bên dưới.</p><?php endif; ?>
  <div class="list">
    <?php foreach ($albums as $a): ?>
    <a class="album" href="index.php?p=album&amp;id=<?= (int) $a['id'] ?>" style="--c:<?= h($a['color']) ?>">
      <span class="album-cover"<?= $a['cover_file'] ? ' style="background-image:url(&quot;../' . h($a['cover_file']) . '&quot;)"' : '' ?>><span class="year"><?= (int) $a['year'] ?></span></span>
      <span class="album-body">
        <span class="title"><?= h($a['title']) ?></span>
        <span class="meta"><?= (int) $a['n_events'] ?> sự kiện · <?= (int) $a['n_photos'] ?> ảnh</span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>

  <section class="card" id="them">
    <h2>Thêm album mới</h2>
    <p class="hint">Mỗi album là một năm. Sau khi tạo, bạn thêm sự kiện và ảnh.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="album_create">
      <div class="row">
<label class="narrow">Khóa (K)<input type="number" name="course" min="1" max="999" placeholder="23" value=""></label>
        <label class="narrow">Năm<input type="number" name="year" min="1800" max="2200" required value="<?= date('Y') ?>"></label>
        <label>Tên album<input name="title" required maxlength="60" placeholder="Ví dụ: Năm đầu đại học"></label>
      </div>
      <label><span>Dòng phụ <em>(không bắt buộc)</em></span><input name="subtitle" maxlength="80"></label>
      <label>Mô tả<textarea name="description" rows="3"></textarea></label>
      <div class="field">Màu gáy album<?= color_select('') ?></div>
      <button class="btn primary">Thêm album</button>
    </form>
  </section>
<?php endif; ?>
</main>
</div>
<?php endif; ?>
<script>
// Thanh công cụ soạn bài + nút "Chèn vào bài" dưới ảnh sự kiện.
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-md],[data-photo]');
  if (!b) return;
  const ta = b.closest('.item-body').querySelector('textarea[name=description]');
  const v = ta.value, s = ta.selectionStart, end = ta.selectionEnd, sel = v.slice(s, end);
  const put = (text, a = s, z = end) => { ta.focus(); ta.setRangeText(text, a, z, 'end'); };
  const md = b.dataset.md;
  if (b.dataset.photo) {
    const before = v.slice(0, s), after = v.slice(end);
    put((before && !before.endsWith('\n\n') ? (before.endsWith('\n') ? '\n' : '\n\n') : '') + '[ảnh:' + b.dataset.photo + ']' + (after.startsWith('\n\n') ? '' : '\n\n'));
  } else if (md === 'b' || md === 'i') {
    const m = md === 'b' ? '**' : '*';
    put(m + (sel || (md === 'b' ? 'chữ đậm' : 'chữ nghiêng')) + m);
  } else {
    // Tiền tố cho từng dòng đang chọn (hoặc dòng có con trỏ).
    const a = v.lastIndexOf('\n', s - 1) + 1, zi = v.indexOf('\n', end), z = zi < 0 ? v.length : zi;
    const pre = { h: '## ', ul: '- ', q: '> ' }[md];
    put(v.slice(a, z).split('\n').map((l) => (l.startsWith(pre) ? l : pre + l)).join('\n'), a, z);
  }
});
// Tải/xoá ảnh trong sự kiện sẽ tải lại trang: cảnh báo nếu bài viết đang sửa chưa lưu.
document.addEventListener('submit', (e) => {
  const f = document.getElementById(e.target.dataset.guard || '');
  if (!f) return;
  const dirty = Array.from(f.elements).some((x) => 'defaultValue' in x && x.type !== 'hidden' && x.value !== x.defaultValue);
  if (dirty && !confirm('Bài viết hoặc thông tin sự kiện chưa lưu sẽ bị mất. Vẫn tiếp tục?')) { e.preventDefault(); e.stopPropagation(); }
}, true);
// Liên kết giờ mở trong cùng tab: hỏi lại trước khi rời trang khi có ô đang sửa chưa lưu.
let leaving = false;
document.addEventListener('submit', (e) => { if (!e.defaultPrevented) leaving = true; });
addEventListener('beforeunload', (e) => {
  if (leaving) return;
  const dirty = Array.from(document.querySelectorAll('form textarea, form input:not([type=hidden]):not([type=file]):not([type=radio])')).some((x) => x.value !== x.defaultValue);
  if (dirty) e.preventDefault();
});
</script>
</body>
</html>
