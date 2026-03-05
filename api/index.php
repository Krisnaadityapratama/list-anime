<?php
session_start();

// ================== CONFIG SUPABASE ==================
$supabase_url = 'https://pmgosrafgvepqjcgxxke.supabase.co';  // Dari dashboard
$supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InBtZ29zcmFmZ3ZlcHFqY2d4eGtlIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzI2NTE5NTAsImV4cCI6MjA4ODIyNzk1MH0.QaXyPtPZn2PtZ-Fx19Tj2wxcNsmYJvMfe6Hk8wsKMbw';  // Anon public key

// ================== CONFIG LOGIN ==================
// Sama seperti sebelumnya
$admin_username = 'krisna';
$admin_password_hash = '$2a$12$iWUOsKCXWu4QPe1G1l2GeOlMPlodVHgAw8NhVZ11E4bpyVxQ91XE6';

// Fungsi cek login (sama)
function is_logged_in() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Proses login (sama)
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

// Logout (sama)
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?msg=" . urlencode('✅ Logout berhasil.'));
    exit;
}

// ================== FUNGSI HELPER SUPABASE ==================
function supabase_request($method, $endpoint, $data = null, $headers = []) {
    global $supabase_url, $supabase_key;
    $ch = curl_init($supabase_url . '/rest/v1/' . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $default_headers = [
        "apikey: $supabase_key",
        "Authorization: Bearer $supabase_key",
        "Content-Type: application/json",
        "Prefer: return=minimal"  // Untuk insert/update, biar nggak return full data
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($default_headers, $headers));

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close($ch);

    if ($code >= 400) {
        return ['error' => "Gagal ($code): " . $response];
    }

    return json_decode($response, true) ?? true;
}

function upload_cover($file) {
    global $supabase_url, $supabase_key;
    if (!$file || $file['error'] !== 0) {
        error_log("File error: " . $file['error']);  // Cek php error log
        return null;
    }

    $filename = time() . '_' . basename($file['name']);
    $ch = curl_init($supabase_url . '/storage/v1/object/anime-covers/' . $filename);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file['tmp_name']));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $supabase_key",
        "Authorization: Bearer $supabase_key",
        "Content-Type: " . ($file['type'] ?: 'image/jpeg')  // fallback kalau type kosong
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);  // tambah ini untuk debug
    // curl_close($ch);

    // Debug: tampilkan di halaman kalau gagal (untuk test)
    if ($code != 200) {
        echo '<div style="background:red; color:white; padding:10px;">';
        echo "Upload gagal! Code: $code<br>";
        echo "Response: " . htmlspecialchars($response) . "<br>";
        echo "Curl error: " . htmlspecialchars($curl_error) . "<br>";
        echo "File name: $filename | Size: " . round($file['size']/1024) . " KB | Type: " . $file['type'];
        echo '</div>';
    }

    if ($code == 200) {
        return $supabase_url . '/storage/v1/object/public/anime-covers/' . $filename;
    }
    return null;
}

// ================== FUNGSI CRUD ==================
function get_animes() {
    $data = supabase_request('GET', 'animes?select=*&is_deleted=eq.false&order=title.asc');
    return is_array($data) ? $data : [];
}

// Load data
$animes = get_animes();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Proteksi aksi tulis hanya untuk yang login
if (is_logged_in()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        $id = trim($_POST['id'] ?? '');  // Untuk edit
        $title = trim($_POST['title'] ?? '');
        if (empty($title)) {
            $error = "Judul wajib diisi.";
        } else {
            $data = [
                'title' => $title,
                'link' => trim($_POST['link'] ?? ''),
                'last_ep' => trim($_POST['last_ep'] ?? ''),
                'total_ep' => trim($_POST['total_ep'] ?? ''),
                'status' => trim($_POST['status'] ?? ''),
                'rating' => trim($_POST['rating'] ?? ''),
                'genre' => trim($_POST['genre'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'updated_at' => 'now()'
            ];

            // Upload gambar kalau ada
            if (isset($_FILES['cover']) && $_FILES['cover']['error'] === 0) {
                $cover_url = upload_cover($_FILES['cover']);
                if ($cover_url) {
                    $data['cover_url'] = $cover_url;
                } else {
                    $error = "Gagal upload gambar.";
                }
            }

            if (!isset($error)) {
                if ($id) {
                    // Update
                    $result = supabase_request('PATCH', "animes?id=eq.$id", $data);
                } else {
                    // Insert
                    $data['created_at'] = 'now()';
                    $result = supabase_request('POST', 'animes', $data);
                }

                if (!isset($result['error'])) {
                    header("Location: ?success=1&msg=" . urlencode('✅ Anime/donghua disimpan!'));
                    exit;
                } else {
                    $error = $result['error'];
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
        $id = trim($_POST['delete_id'] ?? '');
        if ($id) {
            $result = supabase_request('PATCH', "animes?id=eq.$id", ['is_deleted' => true]);
            if (!isset($result['error'])) {
                header("Location: ?success=1&msg=" . urlencode('✅ Anime/donghua dihapus!'));
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }

    if ($action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="anime_list_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Judul', 'Link', 'Ep Terakhir', 'Total Ep', 'Status', 'Rating', 'Genre', 'Catatan', 'Cover URL', 'Update']);

        foreach ($animes as $a) {
            $ep = $a['last_ep'] && $a['total_ep'] ? $a['last_ep'].'/'.$a['total_ep'] : $a['last_ep'];
            fputcsv($output, [
                $a['id'],
                $a['title'],
                $a['link'],
                $ep,
                $a['status'],
                $a['rating'],
                $a['genre'],
                $a['notes'],
                $a['cover_url'],
                date('Y-m-d H:i:s', strtotime($a['updated_at'] ?? $a['created_at']))
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
    <!-- SweetAlert2 CSS & JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Favicon & Icons (sama) -->
    <link rel="icon" type="image/png" href="https://raw.githubusercontent.com/krisnaaa/animelist/main/icon.png">
    <link rel="apple-touch-icon" href="https://raw.githubusercontent.com/krisnaaa/animelist/main/icon.png">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='35' cy='40' r='15' fill='%23667eea'/><circle cx='35' cy='40' r='8' fill='white'/><circle cx='35' cy='40' r='5' fill='black'/><circle cx='65' cy='40' r='15' fill='%23667eea'/><circle cx='65' cy='40' r='8' fill='white'/><circle cx='65' cy='40' r='5' fill='black'/><path d='M 40 65 Q 50 75 60 65' stroke='%23667eea' stroke-width='3' fill='none' stroke-linecap='round'/></svg>">
    <meta name="theme-color" content="#0f0f23">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Sama seperti sebelumnya */
        :root { --bg: #0f0f23; --text: #eee; --card: #1a1a2e; }
        [data-bs-theme="light"] { --bg: #f8f9fa; --text: #212529; --card: #ffffff; }
        body { background: var(--bg); color: var(--text); }
        .table { --bs-table-bg: var(--card); --bs-table-color: var(--text); }
        .modal-content { background: var(--card); color: var(--text); }
        .cover-img { max-width: 100px; height: auto; }

        .swal2-dark-popup {
        background: #1a1a2e !important;
        color: #eee !important;
        }
        .swal2-dark-popup .swal2-title {
            color: #fff !important;
        }
        .swal2-dark-popup .swal2-html-container {
            color: #ddd !important;
        }
        .swal2-dark-popup .swal2-icon {
            color: #ffc107 !important; /* kuning untuk icon question */
        }

    </style>
</head>
<body class="p-4">
<div class="container">

    <!-- Info login & form login kecil (sama) -->
            <?php if (!is_logged_in()): ?>
                <div class="alert alert-info mb-4 border-0 shadow-sm" style="background: #1e293b; color: #94a3b8; border-radius: 12px;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <strong>Mode Read-Only</strong>
                            <div class="small opacity-75 mt-1">Anda bisa melihat daftar anime favorit saya.</div>
                        </div>
                        <a href="login.php" class="btn btn-outline-primary btn-sm px-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Login Admin
                        </a>
                    </div>
                </div>
            <?php else: ?>
            <div class="alert alert-success mb-4 shadow-lg border-0 fade show" role="alert" 
                style="background: linear-gradient(135deg, #2c7a2c, #1e5128); color: white; border-radius: 12px; transition: all 0.3s ease;">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-shield-check-fill fs-4"></i>
                        <div>
                            <strong>Sedang login sebagai <?= htmlspecialchars($admin_username) ?></strong>
                            <small class="d-block opacity-75">Akses penuh diaktifkan</small>
                        </div>
                    </div>
                    <a href="#" class="btn btn-outline-danger btn-sm" onclick="confirmLogout()">Logout</a>
                </div>
            </div>

            <!-- Optional: animasi fade-in via CSS tambahan -->
            <style>
                .alert-success.fade.show {
                    animation: fadeIn 0.6s ease-out;
                }
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(-10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            </style>
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
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert" id="successAlert">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <!-- Auto-dismiss setelah 5 detik -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const alert = document.getElementById('successAlert');
                if (alert) {
                    setTimeout(() => {
                        // Trigger Bootstrap dismiss animation
                        const closeButton = alert.querySelector('.btn-close');
                        if (closeButton) {
                            closeButton.click(); // Simulasi klik tombol close
                        } else {
                            alert.classList.remove('show');
                            alert.classList.add('fade');
                        }
                    }, 5000); // 5000 ms = 5 detik (bisa ubah jadi 3000 untuk 3 detik)
                }
            });
        </script>
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
                    <th>Cover</th>  <!-- Kolom baru -->
                    <th>Judul</th>
                    <th>Link</th>
                    <th>Episode</th>
                    <th>Status</th>
                    <th>Rating</th>
                    <th>Sinopsis</th>
                    <th>Genre</th>
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
                $update_time = $a['updated_at'] ?? $a['created_at'];
            ?>
                <tr>
                    <td>
                        <?php if ($a['cover_url']): ?>
                            <img src="<?= htmlspecialchars($a['cover_url']) ?>" alt="Cover" class="cover-img">
                        <?php else: ?>-<?php endif; ?>
                    </td>
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
                    <td>
                        <?php
                        $notes = $a['notes'] ?: '-';
                        if (strlen($notes) > 100) {
                            $short = htmlspecialchars(substr($notes, 0, 100)) . '...';
                            $full = htmlspecialchars($notes);
                            echo "<span class='short-notes'>$short <a href='#' onclick='toggleNotes(this)' data-full=\"$full\">see more</a></span>";
                            echo "<span class='full-notes' style='display:none;'>$full <a href='#' onclick='toggleNotes(this)' data-short=\"$short\">see less</a></span>";
                        } else {
                            echo htmlspecialchars($notes);
                        }
                        ?>
                    </td>
                    <td><?= htmlspecialchars($a['genre'] ?: '-') ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($update_time)) ?></td>
                    <?php if (is_logged_in()): ?>
                        <td>
                            <button class="btn btn-warning btn-sm me-1" onclick='editAnime(<?= json_encode($a, JSON_UNESCAPED_UNICODE) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus <?= addslashes($a['title']) ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= htmlspecialchars($a['id']) ?>">
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

<!-- Modal hanya untuk admin -->
<?php if (is_logged_in()): ?>
<div class="modal fade" id="animeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Tambah Anime/Donghua Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="animeForm" enctype="multipart/form-data">  <!-- Tambah enctype untuk file upload -->
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="id">  <!-- Untuk edit -->
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
                            <label class="form-label">Cover Image</label>
                            <input type="file" name="cover" id="cover" class="form-control" accept="image/*">
                            <small class="form-text">Upload poster anime (opsional, max 1MB disarankan).</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Catatan</label>
                            <textarea name="notes" id="notes" class="form-control" rows="4"></textarea>  <!-- Rows lebih besar untuk notes panjang -->
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
// Dark mode toggle (sama)
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

// Search (sama)
document.getElementById('search')?.addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    document.querySelectorAll('#animeTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
});

// Toggle notes function (sama)
function toggleNotes(link) {
    const td = link.closest('td');
    const shortSpan = td.querySelector('.short-notes');
    const fullSpan = td.querySelector('.full-notes');
    if (shortSpan && shortSpan.style.display !== 'none') {
        shortSpan.style.display = 'none';
        fullSpan.style.display = 'inline';
    } else if (fullSpan) {
        fullSpan.style.display = 'none';
        shortSpan.style.display = 'inline';
    }
}

// Edit function (update dengan ID)
function editAnime(anime) {
    document.getElementById('modalTitle').textContent = 'Edit Anime';
    document.getElementById('id').value        = anime.id        || '';
    document.getElementById('title').value     = anime.title     || '';
    document.getElementById('link').value      = anime.link      || '';
    document.getElementById('last_ep').value   = anime.last_ep   || '';
    document.getElementById('total_ep').value  = anime.total_ep  || '';
    document.getElementById('rating').value    = anime.rating    || '';
    document.getElementById('status').value    = anime.status    || 'Plan to Watch';
    document.getElementById('genre').value     = anime.genre     || '';
    document.getElementById('notes').value     = anime.notes     || '';
    // Untuk gambar, nggak bisa prefill file, tapi user bisa upload baru kalau mau ganti
    new bootstrap.Modal(document.getElementById('animeModal')).show();
}

function resetModal() {
    document.getElementById('modalTitle').textContent = 'Tambah Anime/Donghua Baru';
    document.getElementById('animeForm').reset();
    document.getElementById('id').value = '';  // Reset ID
}

function confirmLogout() {
    Swal.fire({
        title: 'Yakin ingin logout?',
        text: "Anda akan keluar dari sesi admin.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Ya, Logout',
        cancelButtonText: 'Batal',
        reverseButtons: true,          // tombol Ya di kanan
        backdrop: true,
        allowOutsideClick: false,      // tidak bisa klik luar popup
        customClass: {
            popup: 'swal2-dark-popup', // custom class untuk dark mode
            title: 'text-white',
            content: 'text-white',
            confirmButton: 'btn btn-danger px-4 py-2',
            cancelButton: 'btn btn-secondary px-4 py-2'
        },
        buttonsStyling: false          // biar tombol pakai style Bootstrap
    }).then((result) => {
        if (result.isConfirmed) {
            // Redirect ke logout
            window.location.href = '?logout=1';
        }
    });
}

</script>

<!-- Modal Login Admin -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="loginModalLabel">Login Admin</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" name="username" id="username" class="form-control bg-secondary text-white border-0" placeholder="krisna" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control bg-secondary text-white border-0" placeholder="••••••••" required>
                    </div>
                    <?php if ($login_error): ?>
                        <div class="alert alert-danger small mb-3"><?= $login_error ?></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100 py-2">Login</button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>