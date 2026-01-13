<?php
session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

// Hata değişkeni varsayılan olarak null
$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log('login.php: POST request received');
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrf_token)) {
        $error = "Geçersiz CSRF token!";
        error_log('login.php: Invalid CSRF token');
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = "Kullanıcı adı ve şifre zorunludur!";
            error_log('login.php: Username or password empty');
        } else {
            global $db;
            $stmt = $db->prepare("SELECT id, branch_id, personnel_type, password FROM personnel WHERE username = ?");
            if (!$stmt) {
                $error = "Veritabanı hatası: Sorgu hazırlanamadı!";
                error_log('login.php: Query preparation failed: ' . $db->error);
            } else {
                $stmt->bind_param("s", $username);
                if (!$stmt->execute()) {
                    $error = "Veritabanı hatası: Sorgu çalıştırılamadı!";
                    error_log('login.php: Query execution failed: ' . $stmt->error);
                } else {
                    $result = $stmt->get_result();
                    if ($user = $result->fetch_assoc()) {
                        // Şifre kontrolü (sadece hashlenmiş şifre)
                        if (password_verify($password, $user['password'])) {
                            $_SESSION['personnel_id'] = $user['id'];
                            $_SESSION['branch_id'] = $user['branch_id'];
                            $_SESSION['personnel_type'] = $user['personnel_type'];
                            $db->query("UPDATE personnel SET is_logged_in = 1 WHERE id = " . $user['id']);
                            error_log("login.php: Login successful, personnel_id: {$user['id']}, branch_id: {$user['branch_id']}, personnel_type: {$user['personnel_type']}");

                            // Kasiyer için vardiya kontrolü
                            if ($user['personnel_type'] == 'cashier') {
                                $shift = get_active_shift($user['branch_id'], $user['id']);
                                if ($shift) {
                                    $_SESSION['shift_id'] = $shift['id'];
                                    error_log("login.php: Active shift found, shift_id: {$shift['id']}, redirecting to pos.php");
                                    header("Location: pos.php");
                                } else {
                                    error_log("login.php: No active shift, redirecting to dashboard.php");
                                    header("Location: dashboard.php");
                                }
                            } else if ($user['personnel_type'] == 'admin') {
                                header("Location: admin_dashboard.php");
                            } else if ($user['personnel_type'] == 'kitchen') {
                                header("Location: kitchen_monitor.php");
                            } else if ($user['personnel_type'] == 'shift_supervisor') {
                                header("Location: shift_supervisor_dashboard.php");
                            } else {
                                error_log("login.php: Non-cashier, redirecting to admin_dashboard.php");
                                header("Location: admin_dashboard.php");
                            }
                            exit;
                        } else {
                            $error = "Geçersiz şifre!";
                            error_log('login.php: Invalid password for username: ' . $username);
                        }
                    } else {
                        $error = "Geçersiz kullanıcı adı!";
                        error_log('login.php: Invalid username: ' . $username);
                    }
                }
            }
        }
    }
    error_log('login.php: Error after POST: ' . ($error ?? 'null'));
}

$csrf_token = generate_csrf_token();
display_header('Giriş Yap');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş - POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }
        .login-content {
            padding: 30px;
            border-radius: 8px;
            width: 100%;
            max-width: 400px;
        }

        .background-image {
            background-image: url('assets/img/markalar.png'); /* https://placehold.co/1920x1080?text=Login+Background  */
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toast-container {
            z-index: 1055;
        }
        @media (max-width: 767px) {
            .background-image {
                display: none;
            }
            .login-content {
                margin: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid login-container">
        <div class="row w-100 m-0">
            <div class="col-md-8 p-0 background-image">
                <!-- Arka plan resmi burada -->
            </div>
            <div class="col-md-4 d-flex align-items-center justify-content-center">
                <div class="login-content">
                    <h2 class="mb-3 text-center">Giriş Yap</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Kullanıcı Adı</label>
                            <input type="text" class="form-control form-control-lg fs-15px" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Şifre</label>
                            <input type="password" class="form-control form-control-lg fs-15px" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-theme btn-lg d-block w-100 fw-500 mb-3">Giriş Yap</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Toast bileşeni -->
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast fade mb-3 hide" data-autohide="true" data-delay="3000" id="error-toast">
                <div class="toast-header">
                    <i class="far fa-bell text-muted me-2"></i>
                    <strong class="me-auto">Hata</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body bg-danger text-white" id="error-toast-body"></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {

            // Hata varsa, string ve boş değilse toast'ı göster
            <?php if (isset($error) && is_string($error) && !empty($error)): ?>
                console.log('login.php: Error detected: <?php echo addslashes($error); ?>');
                showToast('<?php echo htmlspecialchars($error); ?>', 'danger');
            <?php endif; ?>

            // Toast fonksiyonu
            function showToast(message, type) {
                const toastEl = $('#error-toast');
                const toastBody = $('#error-toast-body');
                toastBody.text(message);
                toastBody.removeClass('bg-success bg-danger').addClass(`bg-${type}`);
                const toast = new bootstrap.Toast(toastEl, {
                    autohide: true,
                    delay: 3000
                });
                toast.show();
            }
        });
    </script>

    <?php 
    display_footer(); 
    ?>
</body>
</html>