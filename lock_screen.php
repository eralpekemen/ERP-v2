<?php
session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

// Oturum kontrolü
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['branch_id'])) {
    header("Location: login.php");
    exit;
}

// Sadece kasiyerler için erişim
if ($_SESSION['personnel_type'] != 'cashier') {
    header("Location: dashboard.php");
    exit;
}

// Mola modunda değilse pos.php'ye yönlendir
if (!is_on_break($_SESSION['personnel_id'])) {
    header("Location: pos.php");
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$csrf_token = generate_csrf_token();

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz CSRF token!']);
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'unlock') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // Kullanıcı doğrulama
        global $db;
        $query = "SELECT id, password, personnel_type FROM personnel WHERE username = ? AND branch_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("si", $username, $branch_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if ($user && password_verify($password, $user['password']) && $user['id'] == $personnel_id && $user['personnel_type'] == 'cashier') {
            end_break($personnel_id);
            echo json_encode(['success' => true, 'message' => 'Mola sona erdi!', 'redirect' => 'pos.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Geçersiz kimlik bilgileri!']);
        }
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] == 'start_break') {
        start_break($personnel_id);
        echo json_encode(['success' => true, 'message' => 'Mola başlatıldı!']);
        exit;
    }
}

display_header('Kilit Ekranı');
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kilit Ekranı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <style>
        .lock-screen { height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8f9fa; }
        .lock-screen .card { max-width: 400px; width: 100%; }
    </style>
</head>
<body>
<div class="lock-screen">
    <div class="card">
        <div class="card-body">
            <h4 class="card-title text-center">Kilit Ekranı</h4>
            <p class="text-center">Mola modundasınız. Devam etmek için giriş yapın.</p>
            <div id="error-message" class="alert alert-danger d-none"></div>
            <div class="mb-3">
                <label for="username" class="form-label">Kullanıcı Adı</label>
                <input type="text" class="form-control" id="username" placeholder="Kullanıcı adı girin">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Şifre</label>
                <input type="password" class="form-control" id="password" placeholder="Şifre girin">
            </div>
            <button class="btn btn-primary w-100" onclick="unlockScreen()">Kilidi Aç</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
    function unlockScreen() {
        console.log('unlockScreen called');
        const username = $('#username').val();
        const password = $('#password').val();
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo $csrf_token; ?>');
        formData.append('action', 'unlock');
        formData.append('ajax', '1');
        formData.append('username', username);
        formData.append('password', password);

        $.ajax({
            url: 'lock_screen.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                console.log('unlockScreen AJAX success:', response);
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => window.location.href = response.redirect, 1000);
                } else {
                    $('#error-message').text(response.message || 'Geçersiz kimlik bilgileri!').removeClass('d-none');
                }
            },
            error: function(xhr, status, error) {
                console.error('unlockScreen AJAX error:', xhr.status, xhr.responseText);
                $('#error-message').text('Hata: ' + (xhr.responseJSON?.message || error)).removeClass('d-none');
            }
        });
    }

    function showToast(message, type) {
        console.log('showToast called:', message, type);
        const toastContainer = $('<div class="toast-container position-fixed top-0 end-0 p-3"></div>');
        toastContainer.html(`
            <div class="toast show" role="alert">
                <div class="toast-body bg-${type} text-white">${message}</div>
            </div>
        `);
        $('body').append(toastContainer);
        setTimeout(() => toastContainer.remove(), 3000);
    }
</script>

<?php display_footer(); ?>
</body>
</html>
<?php
ob_end_flush();
?>