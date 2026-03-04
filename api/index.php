<?php
session_start();

// ================== CONFIG ThingSpeak ==================
$channel_id = getenv('TS_CHANNEL_ID') ?: '3285302';
$read_key   = getenv('TS_READ_KEY')   ?: '0WNQZISBM58F9K5V';
$write_key  = getenv('TS_WRITE_KEY')  ?: 'TXN2H06SCR00AZDR';

// ================== CONFIG LOGIN ==================
$admin_username = 'krisna';
$admin_password_hash = '$2a$12$iWUOsKCXWu4QPe1G1l2GeOlMPlodVHgAw8NhVZ11E4bpyVxQ91XE6';

// Fungsi cek login
function is_logged_in() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Proses login
$login_error = '';
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === $admin_username && password_verify($password, $admin_password_hash)) {
        $_SESSION['logged_in'] = true;
        header("Location: ?success=1&msg=" . urlencode('✅ Login berhasil! Selamat datang, Krisna!'));
        exit;
    } else {
        $login_error = 'Username atau password salah.';
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?msg=" . urlencode('✅ Logout berhasil.'));
    exit;
}

// ================== FUNGSI CRUD ==================
function get_feeds() {
    global $channel_id, $read_key;
    $url = "https://api.thingspeak.com/channels/$channel_id/feeds.json?api_key=$read_key&results=8000";
    $json = @file_get_contents($url);
    $data = json_decode($json, true);
    return $data['feeds'] ?? [];
}

function get_latest_animes($feeds) {
    $map = [];
    foreach ($feeds as $f) {
        $title = trim($f['field1'] ?? '');
        if (!$title) continue;
        $key = strtolower($title);
        $time = strtotime($f['created_at']);

        if (!isset($map[$key]) || $time > $map[$key]['time']) {
            $map[$key] = [
                'title'     => $f['field1'],
                'link'      => $f['field2'] ?? '',
                'last_ep'   => $f['field3'] ?? '',
                'total_ep'  => $f['field4'] ?? '',
                'status'    => $f['field5'] ?? '',
                'rating'    => $f['field6'] ?? '',
                'genre'     => $f['field7'] ?? '',
                'notes'     => $f['field8'] ?? '',
                'time'      => $time,
                'created'   => $f['created_at']
            ];
        }
    }

    $list = [];
    foreach ($map as $item) {
        if (strtoupper(trim($item['notes'] ?? '')) !== 'DELETED') {
            $list[] = $item;
        }
    }
    usort($list, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    return $list;
}

// Load data (untuk semua pengunjung)
$animes = get_latest_animes(get_feeds());
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Proteksi aksi tulis (save, delete, export) hanya untuk yang login
if (is_logged_in()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        $data = [
            'api_key' => $write_key,
            'field1'  => trim($_POST['title'] ?? ''),
            'field2'  => trim($_POST['link'] ?? ''),
            'field3'  => trim($_POST['last_ep'] ?? ''),
            'field4'  => trim($_POST['total_ep'] ?? ''),
            'field5'  => trim($_POST['status'] ?? ''),
            'field6'  => trim($_POST['rating'] ?? ''),
            'field7'  => trim($_POST['genre'] ?? ''),
            'field8'  => trim($_POST['notes'] ?? ''),
        ];

        if (!empty($data['field1'])) {
            $ch = curl_init('https://api.thingspeak.com/update.json');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code == 200) {
                header("Location: ?success=1&msg=" . urlencode('✅ anime/donghua disimpan!'));
                exit;
            } else {
                $error = "Gagal simpan (code $code). Tunggu 15 detik.";
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
        $title = trim($_POST['delete_title'] ?? '');
        $data = [
            'api_key' => $write_key,
            'field1'  => $title,
            'field8'  => 'DELETED'
        ];

        $ch = curl_init('https://api.thingspeak.com/update.json');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 200) {
            header("Location: ?success=1&msg=" . urlencode('✅ anime/donghua dihapus!'));
            exit;
        } else {
            $error = "Gagal hapus (code $code).";
        }
    }

    if ($action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="anime_list_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Judul', 'Link', 'Ep Terakhir', 'Total Ep', 'Status', 'Rating', 'Genre', 'Catatan', 'Update']);

        foreach ($animes as $a) {
            $ep = $a['last_ep'] && $a['total_ep'] ? $a['last_ep'].'/'.$a['total_ep'] : $a['last_ep'];
            fputcsv($output, [
                $a['title'],
                $a['link'],
                $ep,
                $a['status'],
                $a['rating'],
                $a['genre'],
                $a['notes'],
                date('Y-m-d H:i:s', strtotime($a['created']))
            ]);
        }
        fclose($output);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Daftar anime dan donghua favorit saya, dengan status, rating, dan catatan pribadi. Update rutin berdasarkan apa yang sedang saya tonton!">
    <title>AsiaAnimelist</title>
    <!-- Favicon & Icons -->
    <link rel="icon" type="image/png" href="https://raw.githubusercontent.com/krisnaaa/animelist/main/icon.png">
    <link rel="apple-touch-icon" href="https://raw.githubusercontent.com/krisnaaa/animelist/main/icon.png">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='35' cy='40' r='15' fill='%23667eea'/><circle cx='35' cy='40' r='8' fill='white'/><circle cx='35' cy='40' r='5' fill='black'/><circle cx='65' cy='40' r='15' fill='%23667eea'/><circle cx='65' cy='40' r='8' fill='white'/><circle cx='65' cy='40' r='5' fill='black'/><path d='M 40 65 Q 50 75 60 65' stroke='%23667eea' stroke-width='3' fill='none' stroke-linecap='round'/></svg>">
    <meta name="theme-color" content="#0f0f23">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bg: #0f0f23; --text: #eee; --card: #1a1a2e; }
        [data-bs-theme="light"] { --bg: #f8f9fa; --text: #212529; --card: #ffffff; }
        body { background: var(--bg); color: var(--text); }
        .table { --bs-table-bg: var(--card); --bs-table-color: var(--text); }
        .modal-content { background: var(--card); color: var(--text); }
    </style>
</head>
<body class="p-4">
<div class="container">

    <!-- Info login & form login kecil -->
    <?php if (!is_logged_in()): ?>
        <div class="alert alert-info mb-4">
            <strong>Mode Read-Only</strong> – Anda bisa melihat daftar anime, tapi untuk edit/tambah/hapus perlu login.
            <form method="post" class="mt-2">
                <input type="hidden" name="action" value="login">
                <div class="input-group input-group-sm">
                    <input type="text" name="username" class="form-control" placeholder="Username" required style="max-width:140px;">
                    <input type="password" name="password" class="form-control" placeholder="Password" required style="max-width:140px;">
                    <button type="submit" class="btn btn-primary btn-sm">Login</button>
                </div>
            </form>
            <?php if ($login_error): ?>
                <div class="alert alert-danger mt-2 small"><?= $login_error ?></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success mb-4 d-flex justify-content-between align-items-center">
            <div>Sedang login sebagai <strong><?= $admin_username ?></strong></div>
            <a href="?logout=1" class="btn btn-outline-danger btn-sm" onclick="return confirm('Yakin logout?')">Logout</a>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-tv"></i> AsiaAnimelist</h1>
        <?php if (is_logged_in()): ?>
            <div class="d-flex gap-2 align-items-center">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="darkModeToggle" checked>
                    <label class="form-check-label" for="darkModeToggle">Dark Mode</label>
                </div>
                <a href="?action=export_csv" class="btn btn-info btn-sm"><i class="bi bi-download"></i> Export CSV</a>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#animeModal" onclick="resetModal()">
                    <i class="bi bi-plus-lg"></i> Tambah
                </button>
            </div>
        <?php else: ?>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="darkModeToggle" checked>
                <label class="form-check-label" for="darkModeToggle">Dark Mode</label>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <input type="text" id="search" class="form-control" placeholder="Cari judul anime...">
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-striped" id="animeTable">
            <thead class="table-dark">
                <tr>
                    <th>Judul</th>
                    <th>Link</th>
                    <th>Episode</th>
                    <th>Status</th>
                    <th>Rating</th>
                    <th>Genre</th>
                    <th>Catatan</th>
                    <th>Update</th>
                    <?php if (is_logged_in()): ?>
                        <th>Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($animes as $a): 
                $ep = $a['last_ep'] && $a['total_ep'] ? $a['last_ep'].'/'.$a['total_ep'] : ($a['last_ep'] ?: '-');
                $statusClass = match(strtolower($a['status'] ?? '')) {
                    'watching' => 'bg-success', 'completed' => 'bg-primary', 'dropped' => 'bg-danger',
                    'on hold' => 'bg-warning', default => 'bg-secondary'
                };
            ?>
                <tr>
                    <td><?= htmlspecialchars($a['title']) ?></td>
                    <td>
                        <?php if ($a['link']): ?>
                            <a href="<?= htmlspecialchars($a['link']) ?>" target="_blank" class="btn btn-sm btn-outline-light">
                                <i class="bi bi-play-circle"></i> Nonton
                            </a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($ep) ?></td>
                    <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($a['status'] ?: '-') ?></span></td>
                    <td><?= htmlspecialchars($a['rating'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($a['genre'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($a['notes'] ?: '-') ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['created'])) ?></td>
                    <?php if (is_logged_in()): ?>
                        <td>
                            <button class="btn btn-warning btn-sm me-1" onclick='editAnime(<?= json_encode($a, JSON_UNESCAPED_UNICODE) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus <?= addslashes($a['title']) ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_title" value="<?= htmlspecialchars($a['title']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (empty($animes)): ?>
        <div class="text-center py-5">
            <h4>Belum ada anime/donghua di list 😢</h4>
            <?php if (is_logged_in()): ?>
                <p>Klik tombol "Tambah" untuk menambahkan anime/donghua favoritmu.</p>
            <?php else: ?>
                <p>Login untuk bisa menambahkan anime/donghua ke list ini.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal hanya untuk admin (yang login) -->
<?php if (is_logged_in()): ?>
<div class="modal fade" id="animeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Tambah Anime/Donghua Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="animeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Judul anime/donghua <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="title" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Link Menonton</label>
                            <input type="url" name="link" id="link" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ep Terakhir</label>
                            <input type="text" name="last_ep" id="last_ep" class="form-control" placeholder="12">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Ep</label>
                            <input type="text" name="total_ep" id="total_ep" class="form-control" placeholder="24">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Rating (0-10)</label>
                            <input type="number" step="0.1" min="0" max="10" name="rating" id="rating" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="Plan to Watch">Plan to Watch</option>
                                <option value="Watching">Watching</option>
                                <option value="On Hold">On Hold</option>
                                <option value="Completed">Completed</option>
                                <option value="Dropped">Dropped</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Genre (pisah koma)</label>
                            <input type="text" name="genre" id="genre" class="form-control" placeholder="Action, Romance, Fantasy">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Catatan</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Dark mode toggle (untuk semua pengunjung)
const toggle = document.getElementById('darkModeToggle');
if (toggle) {
    const html = document.documentElement;
    if (localStorage.getItem('theme') === 'light') {
        html.setAttribute('data-bs-theme', 'light');
        toggle.checked = false;
    }
    toggle.addEventListener('change', () => {
        if (toggle.checked) {
            html.setAttribute('data-bs-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        } else {
            html.setAttribute('data-bs-theme', 'light');
            localStorage.setItem('theme', 'light');
        }
    });
}

// Search (untuk semua pengunjung)
document.getElementById('search')?.addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    document.querySelectorAll('#animeTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
});

// Edit function (hanya dipakai kalau tombol edit ada)
function editAnime(anime) {
    document.getElementById('modalTitle').textContent = 'Edit Anime';
    document.getElementById('title').value     = anime.title     || '';
    document.getElementById('link').value      = anime.link      || '';
    document.getElementById('last_ep').value   = anime.last_ep   || '';
    document.getElementById('total_ep').value  = anime.total_ep  || '';
    document.getElementById('rating').value    = anime.rating    || '';
    document.getElementById('status').value    = anime.status    || 'Plan to Watch';
    document.getElementById('genre').value     = anime.genre     || '';
    document.getElementById('notes').value     = anime.notes     || '';
    new bootstrap.Modal(document.getElementById('animeModal')).show();
}

function resetModal() {
    document.getElementById('modalTitle').textContent = 'Tambah anime/donghua Baru';
    document.getElementById('animeForm').reset();
}
</script>
</body>
</html>