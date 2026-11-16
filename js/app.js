// Kệ album cong 3D. Một vòng requestAnimationFrame + Web Animations API, không dùng thư viện.
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const EASE = 'cubic-bezier(.2,.8,.2,1)';
  const P = 1400; // khớp với perspective trong CSS
  const DEFAULT_COLOR = '#1F3FB0';

  const root = document.documentElement;
  const shelf = $('shelf'), stage = $('stage'), ring = $('ring'), dot = $('dot');
  const prevBtn = $('prev'), nextBtn = $('next');
  const detail = $('detail'), lightbox = $('lightbox'), cursor = $('cursor'), cursorLbl = $('cursorLbl');

  let albums = [], N = 0, tapes = [];
  let H = 400, ws = 68, d = 210, gap = 14, U = 520, W = innerWidth, cy = 300, XS = [0];
  let s = 0, T = 0, active = -1;
  let tiltX = 0, tiltY = 0, mx = -1e4, my = -1e4, cx = -100, cyC = -100, hoverEl = null;
  let dragStart = null, dragMoved = false, justDragged = false, T0 = 0, wheelTimer = 0;
  let detailOpen = false, lbOpen = false, lbList = [], lbIndex = 0, lastFocus = null;
  let y0 = 2000, y1 = 2010, dotYear = null;
  let pages = [], pageI = 0, endFlip = null;

  const mod = (a, n) => ((a % n) + n) % n;
  const smooth = (t) => t * t * (3 - 2 * t);
  const cssUrl = (u) => 'url("' + String(u).replace(/"/g, '%22').replace(/\n/g, '') + '")';
  const count = (n, word) => n + ' ' + word;

  function fmtDate(iso, short) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    if (!m) return '';
    return short ? m[3] + '/' + m[2] : m[3] + '/' + m[2] + '/' + m[1];
  }
  function allPhotos(a) {
    return a.events.reduce((acc, e) => acc.concat(e.photos), []).concat(a.photos);
  }

  // ---------- Hình học của cung ----------
  function measure() {
    H = document.querySelector('.probe').getBoundingClientRect().height || 400;
    ws = H * 142 / 840; d = H * 474 / 900; gap = H * 0.035;
    U = H * (innerWidth < 768 ? 1.05 : 1.3);
    W = innerWidth;
    root.style.setProperty('--Hpx', H + 'px');
    root.style.setProperty('--ws', ws + 'px');
    root.style.setProperty('--d', d + 'px');
    const r = shelf.getBoundingClientRect();
    cy = r.top + r.height / 2;
    XS = [0];
    let acc = 0;
    for (let u = 0; u <= 3 * U; u += 2) { acc += 2 / (1 + Math.pow(u / U, 1.6)); XS.push(acc); }
    ring.style.left = (W / 2 - d / 2 - 6) + 'px';
    ring.style.top = (cy + 0.36 * H) + 'px';
  }
  function rel(v, at) { return v - at; } // kệ không lặp: mỗi album chỉ hiện một lần
  function B(r) { return Math.abs(r) < 1 ? (r + Math.sin(Math.PI * r) / Math.PI) / 2 : Math.sign(r) * 0.5; }
  function F(r) { return r * (ws + gap) + (d - ws + 1.4 * gap) * B(r); }
  function scl(u) { return 1 / (1 + Math.pow(Math.abs(u) / U, 1.6)); }
  function xsc(u) { return Math.sign(u) * XS[Math.min(XS.length - 1, Math.floor(Math.abs(u) / 2))]; }

  // ---------- Dựng kệ ----------
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  // Màu nhạt: chữ trên gáy chuyển sang tối, chữ trên bìa dùng bản đậm của màu đó để vẫn đọc được.
  function inks(hex) {
    const rgb = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const lum = rgb.map((c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); });
    const L = 0.2126 * lum[0] + 0.7152 * lum[1] + 0.0722 * lum[2];
    if (L < 0.3) return { spine: '#F3EDDD', cover: hex };
    const k = L > 0.6 ? 0.3 : 0.5; // còn lại bao nhiêu phần màu gốc khi trộn với mực đen
    const dark = '#' + rgb.map((c) => Math.round(c * k + 20 * (1 - k)).toString(16).padStart(2, '0')).join('');
    return { spine: '#1A1A1A', cover: dark };
  }
  function buildTape(a, v) {
    const tape = el('div', 'tape');
    const ink = inks(a.color);
    tape.style.setProperty('--c', a.color);
    tape.style.setProperty('--ci', ink.spine);
    tape.style.setProperty('--cc', ink.cover);
    tape.dataset.v = v;

    const sp = el('div', 'face sp');
    const thumb = el('div', 'sp-thumb', a.course ? 'K' + a.course : '');
    if (!a.course && a.cover) thumb.style.backgroundImage = cssUrl(a.cover);
    sp.append(thumb, el('div', 'sp-band'), el('div', 'sp-title', a.title), el('div', 'sp-year', a.year));

    const cv = el('div', 'face cv');
    const top = el('div', 'cv-top');
    top.append(el('span', '', 'Album ảnh'), el('span', '', 'Số ' + String(a.no).padStart(2, '0')));
    const photo = el('div', 'cv-photo' + (a.cover ? '' : ' none'));
    if (a.cover) photo.style.backgroundImage = cssUrl(a.cover);
    const foot = el('div', 'cv-foot');
    foot.append(el('b', '', count(a.nEvents, 'sự kiện') + ' · ' + count(a.nPhotos, 'ảnh')));
    cv.append(top, el('div', 'cv-year', a.year), el('div', 'cv-title', a.title), photo, foot);

    tape.append(sp, cv);
    stage.append(tape);
    return { el: tape, sp, cv, v, lift: 0, hover: false, shown: true };
  }
  function build() {
    albums.forEach((a, i) => tapes.push(buildTape(a, i)));
    s = T = N - 1; // mở ở album mới nhất
  }

  function buildTimeline() {
    const years = albums.map((a) => a.year);
    // Thanh chạy từ năm album cũ nhất đến năm album mới nhất, tự giãn khi thêm album.
    y0 = Math.min(...years);
    y1 = Math.max(y0 + 1, Math.max(...years));
    const span = y1 - y0, major = span <= 12 ? 1 : span <= 60 ? 5 : span <= 150 ? 10 : 25;
    const ticks = $('ticks');
    let nMajor = 0;
    for (let y = y0; y <= y1; y++) {
      const isMajor = (y - y0) % major === 0 || y === y1;
      if (!isMajor && span > 80) continue;
      const t = el('div', 'tick' + (isMajor ? ' major' : ''));
      t.style.left = ((y - y0) / span * 100) + '%';
      if (isMajor) {
        if (y === y0) t.classList.add('first');
        if (y === y1) t.classList.add('last');
        if (nMajor++ % 2 === 1 && y !== y1) t.classList.add('alt');
        t.append(el('span', '', y));
      }
      ticks.append(t);
    }
  }

  // ---------- Chú thích (trượt trong khung che) ----------
  function swapCaption(a, dir, instant) {
    const vals = {
      title: a.title,
      sub: 'Album ' + a.year + (a.subtitle ? ' / ' + a.subtitle : ''),
      meta: a.year + ' — ' + count(a.nEvents, 'sự kiện') + ' · ' + count(a.nPhotos, 'ảnh')
    };
    document.querySelectorAll('#caption .mask').forEach((mask) => {
      const olds = Array.from(mask.children);
      const span = el('span', '', vals[mask.dataset.k]);
      mask.append(span);
      if (instant || RM) { olds.forEach((o) => o.remove()); return; }
      const opt = { duration: 650, easing: EASE, fill: 'both' };
      span.animate([{ transform: 'translateY(' + dir * 105 + '%)' }, { transform: 'translateY(0)' }], opt);
      olds.forEach((o) => {
        const from = getComputedStyle(o).transform;
        o.animate([{ transform: from === 'none' ? 'translateY(0)' : from }, { transform: 'translateY(' + -dir * 105 + '%)' }], opt).onfinish = () => o.remove();
      });
    });
  }

  // ---------- Vòng lặp ----------
  let last = performance.now();
  function frame(now) {
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    const ease = (k) => (RM ? 1 : 1 - Math.exp(-k * dt));

    T = Math.max(0, Math.min(N - 1, T)); // dừng ở hai đầu kệ
    prevBtn.disabled = T <= 0; nextBtn.disabled = T >= N - 1;
    s += (T - s) * ease(dragMoved ? 18 : 5.2);
    if (Math.abs(T - s) < 1e-4) s = T;

    const idx = mod(Math.round(s), N);
    if (idx !== active) { swapCaption(albums[idx], Math.sign(T - s) || 1, active < 0); active = idx; }

    const settled = Math.abs(T - s) < 0.3;
    const onCover = !detailOpen && settled && Math.abs(mx - W / 2) < 0.55 * d && Math.abs(my - cy) < 0.55 * H;
    const tyT = onCover ? (mx - W / 2) / (0.55 * d) * 8 : (mx > -1e3 ? (mx - W / 2) / W * 4 : 0);
    const txT = onCover ? -(my - cy) / (H / 2) * 5 : 0;
    tiltY += (tyT - tiltY) * ease(7);
    tiltX += (txT - tiltX) * ease(7);

    for (const t of tapes) {
      const r = rel(t.v, s);
      const o = smooth(Math.max(0, 1 - Math.abs(r)));
      const u = F(r);
      if (Math.abs(u) > U * 1.95) {
        if (t.shown) { t.el.style.display = 'none'; t.shown = false; }
        continue;
      }
      if (!t.shown) { t.el.style.display = ''; t.shown = true; }
      t.lift += ((t.hover && Math.abs(r) > 0.5 ? 1 : 0) - t.lift) * ease(12);
      const sc = scl(u), k = 1 / sc;
      const z = P * (1 - k) + o * H * 0.1;
      const x = xsc(u) * k;
      const y = (H * 0.5 * (1 - sc) * 0.42 + o * H * 0.012 - t.lift * H * 0.05) * k;
      const ry = Math.sign(u) * Math.min(1, Math.abs(u) / U) * 26 * (1 - o) - 90 * o + tiltY * o;
      t.el.style.transform = 'translate3d(' + x.toFixed(2) + 'px,' + y.toFixed(2) + 'px,' + z.toFixed(2) + 'px) rotateX(' + (tiltX * o).toFixed(3) + 'deg) rotateY(' + ry.toFixed(3) + 'deg)';
      t.el.style.zIndex = Math.round(3000 + z);
      t.sp.style.setProperty('--shade', Math.min(0.6, o * 0.55 + (1 - sc) * 0.32).toFixed(3));
      t.cv.style.setProperty('--shade', ((1 - o) * 0.82).toFixed(3));
    }

    // Chấm trên thanh thời gian
    const fl = Math.floor(s), f = s - fl, i0 = mod(fl, N), i1 = mod(fl + 1, N);
    const ya = albums[i0].year, yb = albums[i1].year;
    const target = i1 > i0 ? ya + (yb - ya) * f : (f < 0.5 ? ya : yb);
    const jump = (y1 - y0) * 6 / 35;
    dotYear = dotYear === null || Math.abs(target - dotYear) > jump ? target : dotYear + (target - dotYear) * ease(14);
    dot.style.left = ((dotYear - y0) / (y1 - y0) * 100) + '%';

    ring.style.transform = 'scale(' + (detailOpen ? 0 : smooth(Math.max(0, 1 - Math.abs(T - s) * 3))).toFixed(3) + ')';

    // Con trỏ
    cx += (mx - cx) * ease(16); cyC += (my - cyC) * ease(16);
    cursor.style.transform = 'translate(' + cx.toFixed(1) + 'px,' + cyC.toFixed(1) + 'px)';
    let state = '';
    if (hoverEl && hoverEl.closest) {
      if (hoverEl.closest('.tile')) { state = 'open'; cursorLbl.textContent = 'XEM'; }
      else if (hoverEl.closest('a,button,.timeline')) state = 'btn';
      else if (onCover) { state = 'open'; cursorLbl.textContent = 'MỞ'; }
      else if (hoverEl.closest('.tape')) state = 'spine';
    }
    cursor.className = 'cursor' + (state ? ' ' + state : '') + (lbOpen ? ' light' : '');

    requestAnimationFrame(frame);
  }

  // ---------- Trang chi tiết ----------
  function activeCoverRect() {
    let best = null, bd = 1e9;
    for (const t of tapes) { const a = Math.abs(rel(t.v, s)); if (a < bd) { bd = a; best = t; } }
    return best.cv.getBoundingClientRect();
  }
  function insetFrom(r) {
    return 'inset(' + Math.max(0, r.top) + 'px ' + Math.max(0, innerWidth - r.right) + 'px ' + Math.max(0, innerHeight - r.bottom) + 'px ' + Math.max(0, r.left) + 'px round 3px)';
  }
  // ---------- Bài viết của sự kiện ----------
  // Định dạng gọn: **đậm**, *nghiêng*, ## tiêu đề, - danh sách, > trích dẫn, [ảnh:ID] trên một dòng.
  // Dựng bằng DOM + textContent nên nội dung không chèn được mã HTML.
  function inline(parent, text) {
    const re = /\*\*(.+?)\*\*|\*(.+?)\*/g;
    let last = 0, m;
    while ((m = re.exec(text))) {
      parent.append(text.slice(last, m.index), el(m[1] != null ? 'strong' : 'em', '', m[1] != null ? m[1] : m[2]));
      last = re.lastIndex;
    }
    parent.append(text.slice(last));
  }
  function article(src, photos, startIndex, used) {
    const box = el('div', 'ev-body');
    let cur = null, kind = '';
    src.split(/\r?\n/).forEach((raw) => {
      const line = raw.trim();
      let m;
      if (!line) { kind = ''; return; }
      if ((m = /^\[ảnh:\s*(\d+)\]$/i.exec(line))) {
        kind = '';
        const i = photos.findIndex((p) => String(p.id) === m[1]);
        if (i < 0) return; // ảnh đã bị xoá hoặc thuộc sự kiện khác
        used.add(i);
        const p = photos[i], fig = el('figure', 'ev-fig'), b = el('button', 'tile');
        b.type = 'button';
        b.dataset.i = startIndex + i;
        b.setAttribute('aria-label', p.caption || 'Xem ảnh lớn');
        const img = new Image();
        img.loading = 'lazy'; img.alt = p.caption || ''; img.draggable = false; img.src = p.url;
        b.append(img);
        fig.append(b);
        if (p.caption) fig.append(el('figcaption', '', p.caption));
        box.append(fig);
        return;
      }
      if ((m = /^#{1,3}\s+(.*)$/.exec(line))) { kind = ''; const h = el('h4'); inline(h, m[1]); box.append(h); return; }
      let k = 'p', text = line;
      if ((m = /^[-•]\s+(.*)$/.exec(line))) { k = 'ul'; text = m[1]; }
      else if ((m = /^>\s?(.*)$/.exec(line))) { k = 'blockquote'; text = m[1]; }
      if (kind !== k) { cur = box.appendChild(el(k)); kind = k; }
      else if (k !== 'ul') cur.append(el('br'));
      if (k === 'ul') { const li = el('li'); inline(li, text); cur.append(li); } else inline(cur, text);
    });
    return box;
  }
  function eventBlock(dateText, title, desc, photos, startIndex, id) {
    const sec = el('section', 'ev');
    if (id) sec.id = id;
    if (dateText) sec.append(el('span', 'lbl ev-date', dateText));
    sec.append(el('h3', 'ev-title', title));
    const used = new Set();
    if (desc) sec.append(article(desc, photos, startIndex, used));
    if (photos.length > used.size) {
      const grid = el('div', 'tiles');
      photos.forEach((p, i) => {
        if (used.has(i)) return; // đã hiện trong bài viết
        const b = el('button', 'tile');
        b.type = 'button';
        b.dataset.i = startIndex + i;
        b.setAttribute('aria-label', p.caption || 'Xem ảnh ' + (startIndex + i + 1));
        const img = new Image();
        img.loading = 'lazy'; img.alt = ''; img.draggable = false; img.src = p.url;
        b.append(img);
        grid.append(b);
      });
      // Chia cột theo số ảnh để lưới luôn kín: 1 ảnh to, 2-4 ảnh 2 cột, từ 5 ảnh 3 cột; ảnh đầu giãn ra lấp chỗ lẻ.
      const n = grid.children.length, cols = n === 1 ? 1 : n <= 4 ? 2 : 3;
      const odd = n === 3 ? 2 : n >= 5 ? [0, 3, 2][n % 3] : 0;
      grid.style.setProperty('--cols', cols);
      if (odd) grid.firstChild.style.gridColumn = 'span ' + odd;
      sec.append(grid);
    } else if (!photos.length) {
      sec.append(el('p', 'ev-none', 'Chưa có ảnh cho sự kiện này.'));
    }
    return sec;
  }
  function fillDetail(a) {
    $('dSub').textContent = 'Album ' + a.year + (a.subtitle ? ' / ' + a.subtitle : '');
    $('dTitle').textContent = a.title;
    $('dMeta').textContent = a.year + ' / ' + count(a.nEvents, 'sự kiện') + ' / ' + count(a.nPhotos, 'ảnh');
    $('dDesc').textContent = a.description || '';
    $('dDesc').parentElement.hidden = !a.description;

    const nav = $('dNav'), body = $('dBody');
    if (endFlip) endFlip();
    nav.replaceChildren(); body.replaceChildren(); detail.scrollTop = 0;
    lbList = allPhotos(a);
    let index = 0;
    a.events.forEach((e) => {
      const id = 'ev-' + e.id;
      body.append(eventBlock(fmtDate(e.date), e.title, e.description, e.photos, index, id));
      index += e.photos.length;
      const b = el('button');
      b.type = 'button';
      b.append(el('span', '', fmtDate(e.date, true) || '—'), document.createTextNode(e.title));
      const at = nav.children.length;
      b.addEventListener('click', () => turnTo(at));
      nav.append(b);
    });
    if (a.photos.length) body.append(eventBlock('', a.events.length ? 'Ảnh khác trong năm' : 'Ảnh trong năm', '', a.photos, index));
    if (!a.events.length && !a.photos.length) { const s = el('section', 'ev'); s.append(el('p', 'ev-none', 'Album này chưa có sự kiện hay ảnh.')); body.append(s); }
    nav.hidden = !a.events.length;
    pages = Array.from(body.children);
    pages.forEach((p, i) => { p.classList.add('page'); p.append(el('span', 'lbl page-no', 'Trang ' + (i + 1) + ' / ' + pages.length)); });
    pageI = 0;
    showPage();
  }

  // ---------- Lật trang ----------
  function showPage() {
    pages.forEach((p, i) => p.classList.toggle('on', i === pageI));
    Array.from($('dNav').children).forEach((b, i) => b.classList.toggle('on', i === pageI));
    $('pgPrev').disabled = pageI <= 0;
    $('pgNext').disabled = pageI >= pages.length - 1;
    $('pgNum').textContent = pages.length ? 'Trang ' + (pageI + 1) + ' / ' + pages.length : '';
  }
  function turnTo(i, instant) {
    i = Math.max(0, Math.min(pages.length - 1, i));
    if (!pages.length || i === pageI) return;
    if (endFlip) endFlip();
    const from = pages[pageI], to = pages[i], fwd = i > pageI;
    pageI = i;
    to.scrollTop = 0;
    showPage();
    // Lật trang chạy cả khi máy bật "giảm chuyển động": đây là hiệu ứng chính chủ trang yêu cầu.
    if (instant) return;
    // Tiến: góc dưới-phải trang cũ cuộn lên lộ trang mới bên dưới. Lùi: trang mới cuộn ngược về phủ lên trang cũ.
    const sheet = fwd ? from : to;
    from.classList.add('turning');
    sheet.style.zIndex = 3;
    const book = sheet.parentNode;
    let flap = book.querySelector(':scope > .peel');
    if (!flap) { flap = el('div', 'peel'); flap.append(el('div', 'peel-in')); book.append(flap); }
    const back = flap.firstChild;
    const W = sheet.offsetWidth, H = sheet.offsetHeight, D = Math.hypot(W, H);
    let raf = 0;
    const t0 = performance.now();
    const step = (now) => {
      const p = Math.min(1, (now - t0) / 900);
      const e = p < 0.5 ? 2 * p * p : 1 - Math.pow(2 - 2 * p, 2) / 2;
      const P = peelAt(W, H, D * (fwd ? e : 1 - e) * 1.01);
      sheet.style.clipPath = P.page;
      back.style.clipPath = P.flap;
      back.style.background = P.bg;
      if (p < 1) raf = requestAnimationFrame(step); else endFlip();
    };
    flap.hidden = false;
    endFlip = () => { cancelAnimationFrame(raf); flap.hidden = true; sheet.style.clipPath = ''; from.classList.remove('turning'); sheet.style.zIndex = ''; endFlip = null; };
    raf = requestAnimationFrame(step);
  }
  // Gấp góc dưới-phải của trang W×H theo nếp gấp cách góc t px (vuông góc với đường chéo).
  // Trả về phần trang còn lại, mặt sau tờ giấy bị gấp (phản chiếu qua nếp) và dải sáng của nó.
  function peelAt(W, H, t) {
    const D = Math.hypot(W, H), ux = -W / D, uy = -H / D;
    const dist = (X) => (X[0] - W) * ux + (X[1] - H) * uy;
    const half = (keep) => {
      const R = [[0, 0], [W, 0], [W, H], [0, H]], out = [];
      R.forEach((P, i) => {
        const Q = R[(i + 1) % 4], dp = dist(P) - t, dq = dist(Q) - t;
        const inP = keep ? dp >= 0 : dp < 0, inQ = keep ? dq >= 0 : dq < 0;
        if (inP) out.push(P);
        if (inP !== inQ) { const k = dp / (dp - dq); out.push([P[0] + (Q[0] - P[0]) * k, P[1] + (Q[1] - P[1]) * k]); }
      });
      return out;
    };
    const poly = (pts) => 'polygon(' + (pts.length ? pts : [[0, 0], [0, 0], [0, 0]]).map((X) => X[0].toFixed(1) + 'px ' + X[1].toFixed(1) + 'px').join(',') + ')';
    const folded = half(false).map((X) => { const k = 2 * (t - dist(X)); return [X[0] + k * ux, X[1] + k * uy]; });
    // Với góc gradient theo hướng u, vị trí dọc gradient trùng đúng khoảng cách tới góc trang.
    const deg = Math.atan2(ux, -uy) * 180 / Math.PI;
    const bg = 'linear-gradient(' + deg.toFixed(2) + 'deg,#BDB8AE ' + t.toFixed(1) + 'px,#FFFFFF ' + (t * 1.4).toFixed(1) + 'px,#DEDAD2 ' + (2 * t).toFixed(1) + 'px)';
    return { page: poly(half(true)), flap: poly(folded), bg };
  }
  function openDetail() {
    if (detailOpen || !N) return;
    T = Math.round(T);
    const a = albums[mod(T, N)];
    fillDetail(a);
    lastFocus = document.activeElement;
    detailOpen = true;
    detail.hidden = false;
    const dur = (ms) => (RM ? 1 : ms);
    detail.animate([{ clipPath: insetFrom(activeCoverRect()) }, { clipPath: 'inset(0px 0px 0px 0px round 0px)' }],
      { duration: dur(1050), easing: 'cubic-bezier(.76,0,.18,1)', fill: 'both' });
    detail.querySelectorAll('.dleft .mask > *').forEach((line, i) => {
      line.animate([{ transform: 'translateY(110%)' }, { transform: 'translateY(0)' }],
        { duration: dur(900), delay: RM ? 0 : 620 + i * 75, easing: EASE, fill: 'both' });
    });
    const reveal = [$('dNav'), $('dBody'), detail.querySelector('.book-nav')];
    reveal.forEach((node, i) => {
      node.animate([{ clipPath: 'inset(0 0 100% 0)', transform: 'translateY(24px)' }, { clipPath: 'inset(0 0 0% 0)', transform: 'translateY(0)' }],
        { duration: dur(900), delay: RM ? 0 : 760 + Math.min(i, 6) * 90, easing: EASE, fill: 'backwards' });
    });
    $('close').focus({ preventScroll: true });
  }
  function closeDetail() {
    if (!detailOpen) return;
    detailOpen = false;
    const anim = detail.animate([{ clipPath: 'inset(0px 0px 0px 0px round 0px)' }, { clipPath: insetFrom(activeCoverRect()) }],
      { duration: RM ? 1 : 850, easing: 'cubic-bezier(.76,0,.18,1)', fill: 'both' });
    anim.onfinish = () => {
      if (detailOpen) return;
      detail.hidden = true;
      detail.getAnimations({ subtree: true }).forEach((x) => x.cancel());
      if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
    };
  }

  // ---------- Xem ảnh lớn ----------
  function showPhoto(i) {
    lbIndex = mod(i, lbList.length);
    const p = lbList[lbIndex];
    $('lbImg').src = p.url;
    $('lbImg').alt = p.caption || '';
    $('lbCap').textContent = p.caption || '';
    $('lbCount').textContent = (lbIndex + 1) + ' / ' + lbList.length;
  }
  function openLightbox(i, fromRect) {
    if (!lbList.length) return;
    showPhoto(i);
    lbOpen = true;
    lightbox.hidden = false;
    const many = lbList.length > 1;
    $('lbPrev').style.visibility = $('lbNext').style.visibility = many ? '' : 'hidden';
    if (!RM && fromRect) {
      lightbox.animate([{ clipPath: insetFrom(fromRect) }, { clipPath: 'inset(0px 0px 0px 0px round 0px)' }],
        { duration: 600, easing: 'cubic-bezier(.76,0,.18,1)' });
    }
    $('lbClose').focus({ preventScroll: true });
  }
  function closeLightbox() {
    if (!lbOpen) return;
    lbOpen = false;
    lightbox.hidden = true;
    const tile = detail.querySelector('.tile[data-i="' + lbIndex + '"]');
    if (tile) tile.focus({ preventScroll: true });
  }

  // ---------- Điều khiển ----------
  function step(n) { T = Math.round(T) + n; }
  function bind() {
    addEventListener('resize', measure);
    addEventListener('pointermove', (e) => {
      mx = e.clientX; my = e.clientY; hoverEl = e.target;
      if (dragStart !== null) {
        const dx = e.clientX - dragStart;
        if (!dragMoved && Math.abs(dx) > 5) dragMoved = true;
        if (dragMoved) T = T0 - dx / (ws + gap);
      }
    });
    const endDrag = () => {
      if (dragStart === null) return;
      dragStart = null;
      if (dragMoved) { T = Math.round(T); dragMoved = false; justDragged = true; setTimeout(() => { justDragged = false; }, 0); }
    };
    addEventListener('pointerup', endDrag);
    addEventListener('pointercancel', endDrag);
    shelf.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 || detailOpen) return;
      dragStart = e.clientX; dragMoved = false; T0 = T;
    });
    stage.addEventListener('pointerover', (e) => { const t = e.target.closest('.tape'); if (t) tapes[+t.dataset.v].hover = true; });
    stage.addEventListener('pointerout', (e) => { const t = e.target.closest('.tape'); if (t) tapes[+t.dataset.v].hover = false; });
    stage.addEventListener('click', (e) => {
      const t = e.target.closest('.tape');
      if (!t || justDragged || detailOpen) return;
      const v = +t.dataset.v;
      if (Math.abs(rel(v, s)) < 0.5) openDetail();
      else { const base = Math.round(T); T = base + rel(v, base); }
    });
    addEventListener('wheel', (e) => {
      if (detailOpen || !N) return;
      const delta = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
      T += delta / (ws + gap) * 0.5;
      clearTimeout(wheelTimer);
      wheelTimer = setTimeout(() => { T = Math.round(T); }, 160);
    }, { passive: true });

    $('prev').addEventListener('click', () => step(-1));
    $('next').addEventListener('click', () => step(1));
    $('close').addEventListener('click', closeDetail);
    $('timeline').addEventListener('click', (e) => {
      const r = e.currentTarget.getBoundingClientRect();
      const year = y0 + (e.clientX - r.left) / r.width * (y1 - y0);
      let j = 0;
      albums.forEach((a, i) => { if (Math.abs(a.year - year) < Math.abs(albums[j].year - year)) j = i; });
      T = j;
    });

    $('dBody').addEventListener('click', (e) => {
      const tile = e.target.closest('.tile');
      if (tile) openLightbox(+tile.dataset.i, tile.getBoundingClientRect());
    });
    $('pgPrev').addEventListener('click', () => turnTo(pageI - 1));
    $('pgNext').addEventListener('click', () => turnTo(pageI + 1));
    // Vuốt ngang trên điện thoại để lật trang.
    let swipeX = null;
    $('dBody').addEventListener('pointerdown', (e) => { swipeX = e.pointerType === 'mouse' ? null : e.clientX; });
    $('dBody').addEventListener('pointerup', (e) => {
      if (swipeX === null) return;
      const dx = e.clientX - swipeX;
      swipeX = null;
      if (Math.abs(dx) > 60) turnTo(pageI + (dx < 0 ? 1 : -1));
    });
    $('lbClose').addEventListener('click', closeLightbox);
    $('lbPrev').addEventListener('click', () => showPhoto(lbIndex - 1));
    $('lbNext').addEventListener('click', () => showPhoto(lbIndex + 1));
    lightbox.addEventListener('click', (e) => { if (e.target === lightbox || e.target.tagName === 'FIGURE') closeLightbox(); });

    addEventListener('keydown', (e) => {
      if (lbOpen) {
        if (e.key === 'Escape') closeLightbox();
        else if (e.key === 'ArrowLeft') showPhoto(lbIndex - 1);
        else if (e.key === 'ArrowRight') showPhoto(lbIndex + 1);
        else if (e.key === 'Tab') { // giữ tiêu điểm trong khung xem ảnh
          const f = [$('lbClose'), $('lbPrev'), $('lbNext')].filter((b) => b.style.visibility !== 'hidden');
          const i = f.indexOf(document.activeElement);
          e.preventDefault();
          f[mod(i + (e.shiftKey ? -1 : 1), f.length)].focus();
        }
        return;
      }
      if (detailOpen) {
        if (e.key === 'Escape') closeDetail();
        else if (e.key === 'ArrowLeft') turnTo(pageI - 1);
        else if (e.key === 'ArrowRight') turnTo(pageI + 1);
        return;
      }
      if (e.key === 'ArrowLeft') step(-1);
      else if (e.key === 'ArrowRight') step(1);
      else if (e.key === 'Enter' && !(document.activeElement && document.activeElement.closest('a,button'))) openDetail();
    });
  }

  // ---------- Khởi động ----------
  function normalise(data) {
    const list = Array.isArray(data && data.albums) ? data.albums : [];
    return list.map((a, i) => {
      const events = Array.isArray(a.events) ? a.events.map((e) => Object.assign({}, e, { photos: Array.isArray(e.photos) ? e.photos : [] })) : [];
      const photos = Array.isArray(a.photos) ? a.photos : [];
      const out = {
        id: a.id, no: 0, course: Number(a.course) || 0, year: Number(a.year) || 0, title: String(a.title || 'Album'), subtitle: String(a.subtitle || ''),
        description: String(a.description || ''), cover: a.cover || null, events, photos,
        color: /^#[0-9a-f]{6}$/i.test(a.color || '') ? a.color : DEFAULT_COLOR
      };
      out.nEvents = events.length;
      out.nPhotos = allPhotos(out).length;
      return out;
    }).sort((p, q) => (p.course || 1e9) - (q.course || 1e9) || p.year - q.year).map((a, i) => Object.assign(a, { no: i + 1 }));
  }
  function start(data) {
    const site = (data && data.site) || {};
    const name = site.name || 'Album kỷ niệm', sub = site.sub || '';
    document.title = name;
    $('brandName').textContent = $('dBrandName').textContent = name;
    $('brandSub').textContent = $('dBrandSub').textContent = sub;
    document.querySelectorAll('.brand i, .dbrand i').forEach((i) => { i.hidden = !sub; });

    albums = normalise(data);
    N = albums.length;
    $('footLeft').textContent = '© ' + new Date().getFullYear() + ' ' + name;
    measure();
    if (!N) {
      $('empty').hidden = false;
      ['caption', 'prev', 'next', 'timeline', 'ring'].forEach((id) => { $(id).style.display = 'none'; });
      document.querySelector('.floor').style.display = 'none';
      return;
    }
    $('footRight').textContent = count(N, 'album') + ' · ' + Math.min(...albums.map((a) => a.year)) + (N > 1 ? '–' + Math.max(...albums.map((a) => a.year)) : '');
    build();
    buildTimeline();
    bind();
    // Liên kết #ev-ID (từ trang quản trị): mở đúng album và cuộn tới sự kiện.
    const hit = /^#ev-(\d+)$/.exec(location.hash);
    const j = hit ? albums.findIndex((a) => a.events.some((e) => String(e.id) === hit[1])) : -1;
    if (j >= 0) {
      s = T = j;
      setTimeout(() => { openDetail(); turnTo(pages.findIndex((p) => p.id === 'ev-' + hit[1]), true); }, 400);
    }
    requestAnimationFrame((t) => { last = t; frame(t); });
  }

  fetch('api/albums.php', { cache: 'no-store' })
    .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then((data) => { if (!data || !Array.isArray(data.albums)) throw new Error('Dữ liệu sai định dạng'); return data; })
    .catch(() => window.SAMPLE_DATA) // không có máy chủ PHP: dùng dữ liệu mẫu
    .then(start);
})();
