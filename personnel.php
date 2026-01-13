<?php
ob_start();
session_start();
require_once 'config.php';           // $db = new mysqli() var
require_once 'functions/common.php';

if ($_SESSION['personnel_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

// ====================== AJAX İŞLEMLERİ (MySQLi - $db) ======================
if (isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1) PERSONEL LİSTELE
    if ($action === 'list') {
        header('Content-Type: text/html; charset=utf-8');
        $result = $db->query("SELECT * FROM personnel ORDER BY id DESC");

        if ($result->num_rows == 0) {
            echo '<div class="col-12 text-center py-5"><h5 class="text-muted">Henüz personel eklenmemiş.</h5></div>';
            exit;
        }

        while ($p = $result->fetch_assoc()) {
            $type = $p['personnel_type'];
            $statusClass = $p['is_logged_in'] ? 'bg-success' : 'bg-secondary';
            $statusText   = $p['is_logged_in'] ? 'Çevrimiçi' : 'Çevrimdışı';

            echo '
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card personnel-card type-'.$type.' h-100">
                    <div class="card-body text-center">
                        <img src="assets/img/user/'.$p['id'].'.jpg" 
                             onerror="this.src=\'https://placehold.co/150\'" 
                             class="avatar-circle rounded-circle mb-3">
                        <h5 class="mb-1">'.htmlspecialchars($p['name']).'</h5>
                        <p class="text-muted mb-2">@'.htmlspecialchars($p['username']).'</p>
                        <span class="badge '.$statusClass.' mb-3">'.$statusText.'</span>
                        <div class="text-muted small mb-3">
                            <i class="fas fa-user-tag"></i> '.ucwords(str_replace('_', ' ', $type)).'
                        </div>
                        <div>
                            <a href="personnel_detail.php?id='.$p['id'].'" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> Detayları Gör
                            </a>
                        </div>
                    </div>
                </div>
            </div>';
        }
        exit;
    }

    // 2) YENİ PERSONEL EKLE
    if ($action === 'add') {
        header('Content-Type: application/json; charset=utf-8');

        $name     = trim($_POST['name']);
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $type     = $_POST['personnel_type'];
        $branch_id = get_current_branch() ?? 1;

        // Kullanıcı adı kontrol
        $check = $db->prepare("SELECT id FROM personnel WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Bu kullanıcı adı zaten kullanılıyor!']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO personnel (name, username, password, personnel_type, branch_id, role_id) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssss", $name, $username, $password, $type, $branch_id);
        $result = $stmt->execute();

        if ($result && !empty($_FILES['photo']['name'])) {
            $id = $db->insert_id;
            $target = "assets/img/user/$id.jpg";
            move_uploaded_file($_FILES['photo']['tmp_name'], $target);
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

// ====================== SAYFA ======================
$personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';
$branch_id = get_current_branch();
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>SABL | Personeller</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        .personnel-card {transition:all .3s ease;border-radius:12px;overflow:hidden;}
        .personnel-card:hover {transform:translateY(-5px);box-shadow:0 10px 25px rgba(0,0,0,.12)!important;}
        .avatar-circle {width:80px;height:80px;object-fit:cover;border:4px solid #fff;box-shadow:0 4px 15px rgba(0,0,0,.2);}
        .type-cashier {border-left:5px solid #28a745;}
        .type-kitchen {border-left:5px solid #fd7e14;}
        .type-admin {border-left:5px solid #dc3545;}
        .type-supervisor {border-left:5px solid #0d6efd;}
    </style>
</head>
<body>
<div id="app" class="app">
    <div id="header" class="app-header">
                    <div class="mobile-toggler">
                        <button type="button" class="menu-toggler" data-toggle="sidebar-mobile">
                            <span class="bar"></span>
                            <span class="bar"></span>
                        </button>
                    </div>
                    <div class="brand">
                        <div class="desktop-toggler">
                            <button type="button" class="menu-toggler" data-toggle="sidebar-minify">
                                <span class="bar"></span>
                                <span class="bar"></span>
                            </button>
                        </div>
                        <a href="index.php" class="brand-logo">
                            <img src="assets/img/logo.png" class="invert-dark" alt height="20">
                        </a>
                    </div>
                    <div class="menu">
                        <form class="menu-search" method="POST" name="header_search_form">
                            <div class="menu-search-icon"><i class="fa fa-search"></i></div>
                            <div class="menu-search-input">
                                <input type="text" class="form-control" placeholder="Arama...">
                            </div>
                        </form>
                        <div class="menu-item dropdown">
                            <a href="#" data-bs-toggle="dropdown" data-display="static" class="menu-link">
                                <div class="menu-icon"><i class="fa fa-bell nav-icon"></i></div>
                                <div class="menu-label">3</div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end dropdown-notification">
                                <h6 class="dropdown-header text-body-emphasis mb-1">Bildirimler</h6>
                                <a href="#" class="dropdown-notification-item">
                                    <div class="dropdown-notification-icon">
                                        <i class="fa fa-receipt fa-lg fa-fw text-success"></i>
                                    </div>
                                    <div class="dropdown-notification-info">
                                        <div class="title">Your store has a new order for 2 items totaling $1,299.00</div>
                                        <div class="time">just now</div>
                                    </div>
                                    <div class="dropdown-notification-arrow">
                                        <i class="fa fa-chevron-right"></i>
                                    </div>
                                </a>
                                <a href="#" class="dropdown-notification-item">
                                    <div class="dropdown-notification-icon">
                                        <i class="far fa-user-circle fa-lg fa-fw text-muted"></i>
                                    </div>
                                    <div class="dropdown-notification-info">
                                        <div class="title">3 new customer account is created</div>
                                        <div class="time">2 minutes ago</div>
                                    </div>
                                    <div class="dropdown-notification-arrow">
                                        <i class="fa fa-chevron-right"></i>
                                    </div>
                                </a>
                                <a href="#" class="dropdown-notification-item">
                                    <div class="dropdown-notification-icon">
                                        <img src="assets/img/icon/android.svg" alt width="26">
                                    </div>
                                    <div class="dropdown-notification-info">
                                        <div class="title">Your android application has been approved</div>
                                        <div class="time">5 minutes ago</div>
                                    </div>
                                    <div class="dropdown-notification-arrow">
                                        <i class="fa fa-chevron-right"></i>
                                    </div>
                                </a>
                                <a href="#" class="dropdown-notification-item">
                                    <div class="dropdown-notification-icon">
                                        <img src="assets/img/icon/paypal.svg" alt width="26">
                                    </div>
                                    <div class="dropdown-notification-info">
                                        <div class="title">Paypal payment method has been enabled for your store</div>
                                        <div class="time">10 minutes ago</div>
                                    </div>
                                    <div class="dropdown-notification-arrow">
                                        <i class="fa fa-chevron-right"></i>
                                    </div>
                                </a>
                                <div class="p-2 text-center mb-n1">
                                    <a href="#" class="text-body-emphasis text-opacity-50 text-decoration-none">See all</a>
                                </div>
                            </div>
                        </div>
                        <div class="menu-item dropdown">
                            <a href="#" data-bs-toggle="dropdown" data-display="static" class="menu-link">
                                <div class="menu-img online">
                                    <img src="assets/img/user/user.jpg" alt class="ms-100 mh-100 rounded-circle">
                                </div>
                                <div class="menu-text"><?php echo htmlspecialchars($personnel_name); ?></div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end me-lg-3">
                                <a class="dropdown-item d-flex align-items-center" href="profile.php">Profil<i class="fa fa-user-circle fa-fw ms-auto text-body text-opacity-50"></i></a>
                                <a class="dropdown-item d-flex align-items-center" href="inbox.php">Mesajlar <i class="fa fa-envelope fa-fw ms-auto text-body text-opacity-50"></i></a>
                                <a class="dropdown-item d-flex align-items-center" href="calendar.php">Takvim <i class="fa fa-calendar-alt fa-fw ms-auto text-body text-opacity-50"></i></a>
                                <a class="dropdown-item d-flex align-items-center" href="settings.php">Ayarlar <i class="fa fa-wrench fa-fw ms-auto text-body text-opacity-50"></i></a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item d-flex align-items-center" href="logout.php">Çıkış <i class="fa fa-toggle-off fa-fw ms-auto text-body text-opacity-50"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
    <?php include 'sidebar.php'; ?>

    <div id="content" class="app-content">
        <div class="d-flex align-items-center mb-3">
            <div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Ana Sayfa</a></li>
                    <li class="breadcrumb-item active">Personeller</li>
                </ol>
                <h1 class="page-header mb-0"><i class="fas fa-users text-primary"></i> Personel Yönetimi</h1>
            </div>
            <div class="ms-auto">
                <button class="btn btn-success btn-lg shadow-sm" data-bs-toggle="modal" data-bs-target="#addPersonnelModal">
                    <i class="fas fa-user-plus"></i> Yeni Personel
                </button>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Personel ara...">
                </div>
            </div>
        </div>

        <div class="row" id="personnelContainer">
            <div class="col-12 text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL -->
<div class="modal fade" id="addPersonnelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="personnelForm" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Yeni Personel Ekle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label>Ad Soyad *</label><input type="text" name="name" class="form-control" required></div>
                        <div class="col-md-6"><label>Kullanıcı Adı *</label><input type="text" name="username" class="form-control" required></div>
                        <div class="col-md-6"><label>Şifre *</label><input type="password" name="password" class="form-control" required></div>
                        <div class="col-md-6"><label>Görev *</label>
                            <select name="personnel_type" class="form-select" required>
                                <option value="cashier">Kasiyer</option>
                                <option value="kitchen">Mutfak</option>
                                <option value="admin">Yönetici</option>
                                <option value="shift_supervisor">Vardiya Sorumlusu</option>
                            </select>
                        </div>
                        <div class="col-12 text-center">
                            <img id="photoPreview" src="https://placehold.co/150" class="avatar-circle rounded-circle mb-3">
                            <br>
                            <label class="btn btn-outline-primary">
                                <i class="fas fa-camera"></i> Fotoğraf Seç
                                <input type="file" name="photo" accept="image/*" id="photoInput" style="display:none">
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success">Kaydet</button>
                </div>
            </div>
        </form>
    </div>
</div>


            <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
            <script src="assets/js/vendor.min.js?v=<?= time() ?>"></script>
            <script src="assets/js/app.min.js?v=<?= time() ?>"></script>
<script>
    window.cloudflareRocketLoaderDisabled = true;
function loadPersonnel() {
    $.post('personnel.php', {action: 'list'}, function(data) {
        $('#personnelContainer').html(data);
    });
}

$(function() {
    loadPersonnel();

    $('#searchInput').on('keyup', function() {
        let val = this.value.toLowerCase();
        $('.personnel-card').each(function() {
            let text = this.textContent.toLowerCase();
            $(this).closest('.col-lg-4').toggle(text.includes(val));
        });
    });

    $('#photoInput').on('change', function(e) {
        if (e.target.files[0]) {
            $('#photoPreview').attr('src', URL.createObjectURL(e.target.files[0]));
        }
    });

    $('#personnelForm').on('submit', function(e) {
        e.preventDefault();
        let fd = new FormData(this);
        fd.append('action', 'add');

        $.ajax({
            url: 'personnel.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(res) {
                try {
                    let json = typeof res === 'object' ? res : JSON.parse(res);
                    if (json.success) {
                        bootstrap.Modal.getInstance(document.getElementById('addPersonnelModal')).hide();
                        $('#personnelForm')[0].reset();
                        $('#photoPreview').attr('src', 'https://placehold.co/150');
                        loadPersonnel();
                        app.showToast('Personel başarıyla eklendi!', 'success');
                    } else {
                        alert(json.message || 'Bir hata oluştu!');
                    }
                } catch(e) {
                    alert('Sunucu hatası! Konsolu kontrol et.');
                    console.log(res);
                }
            }
        });
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>