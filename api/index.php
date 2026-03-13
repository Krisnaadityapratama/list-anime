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
function get_animes($sortBy = 'title', $direction = 'asc', $filterGenre = '') {
    $allowedSort = ['title', 'genre', 'updated_at', 'rating', 'status'];
    if (!in_array($sortBy, $allowedSort, true)) {
        $sortBy = 'title';
    }
    $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

    $endpoint = 'animes?select=*&is_deleted=eq.false';
    if ($filterGenre) {
        $encoded = rawurlencode($filterGenre);
        $endpoint .= "&genre=ilike.*{$encoded}*";
    }
    $endpoint .= "&order={$sortBy}.{$direction}";

    $data = supabase_request('GET', $endpoint);
    return is_array($data) ? $data : [];
}

// Load data (support sorting + filtering)
$current_sort = $_GET['sort'] ?? 'title';
$current_dir = $_GET['dir'] ?? 'asc';
$current_genre = trim($_GET['genre'] ?? '');
$animes = get_animes($current_sort, $current_dir, $current_genre);
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
    <script>
      window.va = window.va || function () { (window.vaq = window.vaq || []).push(arguments); };
    </script>
    <script defer src="/_vercel/insights/script.js"></script>
    <style>
        :root {
            --bg: #0b1220;
            --text: #e6edf8;
            --card: rgba(15, 22, 48, 0.92);
            --card-2: rgba(20, 30, 55, 0.82);
            --border: rgba(255, 255, 255, 0.12);
            --accent: #667eea;
            --accent-2: #22c55e;
            --muted: rgba(230, 237, 248, 0.7);
        }

        [data-bs-theme="light"] {
            --bg: #f8f9fa;
            --text: #212529;
            --card: #ffffff;
            --border: rgba(0, 0, 0, 0.08);
            --muted: rgba(33, 37, 41, 0.7);
        }

        body {
            background: radial-gradient(circle at 20% 10%, rgba(102, 126, 234, 0.35), transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(34, 197, 94, 0.22), transparent 60%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .container {
            max-width: 1040px;
        }

        .table-responsive {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1rem;
            box-shadow: 0 18px 35px rgba(0, 0, 0, 0.25);
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text);
            border-radius: 14px;
            overflow: hidden;
        }

        .table thead th {
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(0, 0, 0, 0.2);
        }

        .table tbody tr:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .cover-img {
            max-width: 110px;
            height: auto;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 10px 18px rgba(0, 0, 0, 0.3);
        }

        #searchRow {
            background: var(--card-2);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.22);
        }

        #search {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: var(--text);
        }

        #search::placeholder {
            color: var(--muted);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border: none;
        }

        .btn-warning {
            background: rgba(255, 198, 65, 0.95);
            border: 1px solid rgba(255, 198, 65, 0.6);
            color: #0f172a;
        }

        .btn-warning:hover {
            background: rgba(255, 205, 90, 0.95);
        }

        .btn-info {
            background: rgba(38, 198, 218, 0.85);
            border: none;
        }

        .btn-info:hover {
            background: rgba(38, 198, 218, 1);
        }

        .alert {
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .alert a {
            color: inherit;
            text-decoration: underline;
        }

        .modal-content {
            background: var(--card);
            color: var(--text);
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        #loginModal .form-control {
            border-radius: 10px;
        }

        .swal2-dark-popup {
            background: var(--card) !important;
            color: var(--text) !important;
        }

        .swal2-dark-popup .swal2-title {
            color: var(--text) !important;
        }

        .swal2-dark-popup .swal2-html-container {
            color: var(--muted) !important;
        }

        .swal2-dark-popup .swal2-icon {
            color: #ffc107 !important;
        }

        /* Mobile Card View */
        @media (max-width: 768px) {
            .table-responsive {
                display: none; /* Sembunyikan tabel di mobile */
            }

            .mobile-cards {
                display: block;
                margin-top: 1rem;
            }

            .anime-card {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 16px;
                margin-bottom: 1rem;
                overflow: hidden;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .anime-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3);
            }

            .card-header {
                display: flex;
                align-items: center;
                padding: 1rem;
                background: rgba(0, 0, 0, 0.1);
            }

            .card-cover {
                width: 80px;
                height: 80px;
                object-fit: cover;
                border-radius: 10px;
                margin-right: 1rem;
                border: 1px solid rgba(255, 255, 255, 0.2);
            }

            .card-title {
                font-size: 1.1rem;
                font-weight: 600;
                margin: 0;
                color: var(--text);
            }

            .card-details {
                padding: 1rem;
                display: none;
                background: rgba(255, 255, 255, 0.02);
                border-top: 1px solid var(--border);
            }

            .card-details.expanded {
                display: block;
            }

            .detail-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 0.5rem;
                font-size: 0.9rem;
            }

            .detail-label {
                color: var(--muted);
                font-weight: 500;
            }

            .detail-value {
                color: var(--text);
            }

            .card-actions {
                display: flex;
                gap: 0.5rem;
                margin-top: 1rem;
            }

            .btn-mobile {
                flex: 1;
                padding: 0.5rem;
                font-size: 0.9rem;
            }
        }

        @media (min-width: 769px) {
            .mobile-cards {
                display: none; /* Sembunyikan card di desktop */
            }
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="sticky-header">
        <!-- Info login & form login kecil (sama) -->
        <?php if (!is_logged_in()): ?>
            <div class="alert alert-info mb-4 border-0 shadow" style="background: #1e293b; color: #94a3b8; border-radius: 12px; padding: 1.25rem;">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-eye fs-4 opacity-75"></i>
                        <div>
                            <strong>Mode Read-Only</strong>
                            <div class="small opacity-75 mt-1">
                                Anda bisa melihat daftar anime favorit saya. Untuk edit/tambah/hapus, login admin.
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm px-3 py-2" 
                            data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Login Admin
                    </button>
                </div>
            </div>
        <?php else: ?>
            <!-- Bagian login sukses tetap -->
            <div class="alert alert-success mb-4 shadow border-0" style="background: linear-gradient(135deg, #065f46, #047857); color: white; border-radius: 12px; padding: 1rem 1.5rem;">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-shield-check-fill fs-4"></i>
                        <div>
                            <strong>Sedang login sebagai <?= htmlspecialchars($admin_username) ?></strong>
                            <small class="d-block opacity-75">Akses penuh diaktifkan</small>
                        </div>
                    </div>
                    <a href="?logout=1" class="btn btn-outline-light btn-sm px-3 logout-link">
                        <i class="bi bi-box-arrow-right me-1"></i> Logout
                    </a>
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
            <div class="d-flex gap-2 align-items-center">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="darkModeToggle" checked>
                    <label class="form-check-label" for="darkModeToggle">Dark Mode</label>
                </div>
                <a href="https://saweria.co/Bangkrisna" target="_blank" class="btn btn-warning btn-sm">
                    <i class="bi bi-heart-fill"></i> Donasi (Saweria)
                </a>
                <?php if (is_logged_in()): ?>
                    <a href="?action=export_csv" class="btn btn-info btn-sm"><i class="bi bi-download"></i> Export CSV</a>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#animeModal" onclick="resetModal()">
                        <i class="bi bi-plus-lg"></i> Tambah
                    </button>
                <?php endif; ?>
            </div>
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

            // logout sweet alert
            const logoutLink = document.querySelector('.logout-link');
            if (logoutLink) {
                logoutLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Yakin ingin logout?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya',
                        cancelButtonText: 'Tidak',
                        customClass: { popup: 'swal2-dark-popup' }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location = logoutLink.href;
                        }
                    });
                });
            }
        </script>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="row mb-3 g-2" id="searchRow">
        <div class="col-md-4">
            <input type="text" id="search" class="form-control" placeholder="Cari judul anime...">
        </div>
        <div class="col-md-8 d-flex flex-wrap gap-2">
            <select id="sortSelect" class="form-select w-auto">
                <option value="title" <?= $current_sort === 'title' ? 'selected' : '' ?>>Urutkan: Judul</option>
                <option value="genre" <?= $current_sort === 'genre' ? 'selected' : '' ?>>Urutkan: Genre</option>
                <option value="updated_at" <?= $current_sort === 'updated_at' ? 'selected' : '' ?>>Urutkan: Update</option>
                <option value="rating" <?= $current_sort === 'rating' ? 'selected' : '' ?>>Urutkan: Rating</option>
            </select>
            <select id="dirSelect" class="form-select w-auto">
                <option value="asc" <?= $current_dir === 'asc' ? 'selected' : '' ?>>A–Z / Naik</option>
                <option value="desc" <?= $current_dir === 'desc' ? 'selected' : '' ?>>Z–A / Turun</option>
            </select>
            <input type="text" id="filterGenre" class="form-control flex-grow-1" placeholder="Filter genre (anime, donghua..." value="<?= htmlspecialchars($current_genre) ?>">
            <button class="btn btn-outline-light" id="applySortFilter">Terapkan</button>
        </div>
    </div>
    </div> <!-- /.sticky-header -->

    <div class="table-scroll">
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
    </div> <!-- /.table-scroll -->

    <!-- Mobile Card View -->
    <div class="mobile-cards">
        <?php foreach ($animes as $a):
            $ep = $a['last_ep'] && $a['total_ep'] ? $a['last_ep'].'/'.$a['total_ep'] : ($a['last_ep'] ?: '-');
            $statusClass = match(strtolower($a['status'] ?? '')) {
                'watching' => 'bg-success', 'completed' => 'bg-primary', 'dropped' => 'bg-danger',
                'on hold' => 'bg-warning', default => 'bg-secondary'
            };
            $update_time = $a['updated_at'] ?? $a['created_at'];
        ?>
        <div class="anime-card" onclick="toggleCardDetails(this)">
            <div class="card-header">
                <?php if ($a['cover_url']): ?>
                    <img src="<?= htmlspecialchars($a['cover_url']) ?>" alt="Cover" class="card-cover">
                <?php else: ?>
                    <div class="card-cover" style="background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: var(--muted);">
                        <i class="bi bi-image fs-2"></i>
                    </div>
                <?php endif; ?>
                <h5 class="card-title"><?= htmlspecialchars($a['title']) ?></h5>
            </div>
            <div class="card-details">
                <div class="detail-row">
                    <span class="detail-label">Link:</span>
                    <span class="detail-value">
                        <?php if ($a['link']): ?>
                            <a href="<?= htmlspecialchars($a['link']) ?>" target="_blank" class="btn btn-sm btn-outline-light">
                                <i class="bi bi-play-circle"></i> Nonton
                            </a>
                        <?php else: ?>-<?php endif; ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Episode:</span>
                    <span class="detail-value"><?= htmlspecialchars($ep) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value"><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($a['status'] ?: '-') ?></span></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Rating:</span>
                    <span class="detail-value"><?= htmlspecialchars($a['rating'] ?: '-') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Genre:</span>
                    <span class="detail-value"><?= htmlspecialchars($a['genre'] ?: '-') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Update:</span>
                    <span class="detail-value"><?= date('d/m/Y H:i', strtotime($update_time)) ?></span>
                </div>
                <?php if ($a['notes']): ?>
                <div class="detail-row">
                    <span class="detail-label">Sinopsis:</span>
                    <span class="detail-value">
                        <?php
                        $notes = $a['notes'];
                        if (strlen($notes) > 100) {
                            $short = htmlspecialchars(substr($notes, 0, 100)) . '...';
                            $full = htmlspecialchars($notes);
                            echo "<span class='short-notes'>$short <a href='#' onclick='event.stopPropagation(); toggleNotes(this)' data-full=\"$full\">see more</a></span>";
                            echo "<span class='full-notes' style='display:none;'>$full <a href='#' onclick='event.stopPropagation(); toggleNotes(this)' data-short=\"$short\">see less</a></span>";
                        } else {
                            echo htmlspecialchars($notes);
                        }
                        ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (is_logged_in()): ?>
                <div class="card-actions">
                    <button class="btn btn-warning btn-mobile" onclick="event.stopPropagation(); editAnime(<?= json_encode($a, JSON_UNESCAPED_UNICODE) ?>)">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <form method="post" class="d-inline" onsubmit="event.stopPropagation(); return confirm('Yakin hapus <?= addslashes($a['title']) ?>?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="delete_id" value="<?= htmlspecialchars($a['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-mobile">
                            <i class="bi bi-trash"></i> Hapus
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
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

// Sort + filter by genre (reload page with query params)
function applySortFilter() {
    const params = new URLSearchParams(window.location.search);
    const sort = document.getElementById('sortSelect')?.value;
    const dir = document.getElementById('dirSelect')?.value;
    const genre = document.getElementById('filterGenre')?.value.trim();

    if (sort) params.set('sort', sort);
    if (dir) params.set('dir', dir);
    if (genre) {
        params.set('genre', genre);
    } else {
        params.delete('genre');
    }

    window.location.search = params.toString();
}

document.getElementById('sortSelect')?.addEventListener('change', applySortFilter);
document.getElementById('dirSelect')?.addEventListener('change', applySortFilter);
document.getElementById('applySortFilter')?.addEventListener('click', function(e) {
    e.preventDefault();
    applySortFilter();
});

document.getElementById('filterGenre')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        applySortFilter();
    }
});

// Mobile card toggle
function toggleCardDetails(card) {
    const details = card.querySelector('.card-details');
    if (details) {
        details.classList.toggle('expanded');
    }
}

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
            <div class="modal-header border-0 justify-content-center">
                <div class="text-center w-100">
                    <i class="bi bi-lock-fill fs-1 mb-2"></i>
                    <h5 class="modal-title" id="loginModalLabel">Masuk sebagai Admin</h5>
                    <div class="small text-muted">Gunakan kredensial untuk mengelola daftar anime</div>
                </div>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4">
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="username" class="form-label">Nama Pengguna</label>
                        <input type="text" name="username" id="username" class="form-control bg-secondary text-white border-0" placeholder="Masukkan username" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Kata Sandi</label>
                        <input type="password" name="password" id="password" class="form-control bg-secondary text-white border-0" placeholder="Masukkan password" required>
                    </div>
                    <?php if ($login_error): ?>
                        <div class="alert alert-danger small mb-3"><?= $login_error ?></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100 py-2">Masuk</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>