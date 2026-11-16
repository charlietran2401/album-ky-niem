// Dữ liệu mẫu. Chỉ dùng khi trang KHÔNG gọi được api/albums.php
// (ví dụ mở thẳng index.html mà chưa chạy PHP). Khi có máy chủ PHP, file này bị bỏ qua.
(function () {
  function ph(color, text) {
    var svg = "<svg xmlns='http://www.w3.org/2000/svg' width='800' height='600'>" +
      "<rect width='800' height='600' fill='" + color + "'/>" +
      "<circle cx='610' cy='170' r='90' fill='#F3EDDD' fill-opacity='.85'/>" +
      "<path d='M0 600L230 300l150 170 110-120 310 250z' fill='#141414' fill-opacity='.28'/>" +
      "<text x='40' y='560' font-family='sans-serif' font-size='34' font-weight='600' fill='#F3EDDD'>" + text + "</text></svg>";
    return 'data:image/svg+xml,' + encodeURIComponent(svg);
  }
  function photos(color, year, n, from) {
    var out = [];
    for (var i = 0; i < n; i++) out.push({ id: year * 100 + from + i, url: ph(color, 'Ảnh mẫu ' + year + ' · ' + (from + i)), caption: 'Ảnh mẫu số ' + (from + i) + ' của năm ' + year });
    return out;
  }
  var colors = ['#1F3FB0', '#B4432B', '#2F6B4F', '#8A5A1C', '#5B3A8C', '#1E6A7A'];
  var names = ['Khởi đầu', 'Những chuyến đi', 'Trưởng thành', 'Mùa bận rộn', 'Dấu mốc mới', 'Hiện tại'];
  var albums = names.map(function (name, i) {
    var year = 2021 + i, c = colors[i];
    return {
      id: i + 1, year: year, title: name, subtitle: 'Album mẫu', color: c, cover: ph(c, String(year)),
      description: 'Đây là album mẫu để xem thử giao diện. Đăng nhập trang quản trị để thêm album, sự kiện và ảnh thật.',
      events: [
        { id: i * 10 + 1, date: year + '-03-26', title: 'Sự kiện mẫu thứ nhất', description: 'Mô tả ngắn về sự kiện: diễn ra ở đâu, có những ai, điều gì đáng nhớ.', photos: photos(c, year, 5, 1) },
        { id: i * 10 + 2, date: year + '-09-05', title: 'Sự kiện mẫu thứ hai', description: 'Mỗi sự kiện có ngày, tên, mô tả và các ảnh riêng.', photos: photos(c, year, 3, 6) }
      ],
      photos: photos(c, year, 2, 9)
    };
  });
  window.SAMPLE_DATA = { site: { name: 'Album kỷ niệm', sub: 'Dữ liệu mẫu' }, albums: albums };
})();
