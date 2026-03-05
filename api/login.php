<?php
session_start();

$admin_username = 'krisna';
$admin_password_hash = '$2a$12$iWUOsKCXWu4QPe1G1l2GeOlMPlodVHgAw8NhVZ11E4bpyVxQ91XE6';

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === $admin_username && password_verify($password, $admin_password_hash)) {
        $_SESSION['logged_in'] = true;
        header("Location: index.php?success=1&msg=" . urlencode('✅ Login berhasil!'));
        exit;
    } else {
        $login_error = 'Username atau password salah.';
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin - AsiaAnimelist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #0f0f23; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { max-width: 420px; width: 100%; }
    </style>
</head>
<body>
    <div class="login-card card bg-dark text-white shadow-lg border-0 p-4">
        <div class="card-body text-center">
            <i class="bi bi-shield-lock-fill fs-1 mb-3 text-primary"></i>
            <h4 class="mb-4">Login Admin</h4>
            
            <?php if ($login_error): ?>
                <div class="alert alert-danger"><?= $login_error ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3 text-start">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" name="username" id="username" class="form-control bg-secondary text-white border-0" placeholder="krisna" required autofocus>
                </div>
                <div class="mb-4 text-start">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control bg-secondary text-white border-0" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">Masuk</button>
            </form>

            <a href="index.php" class="d-block mt-4 text-muted small">Kembali ke daftar anime</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>