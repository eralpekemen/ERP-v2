<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions/common.php';
require_once 'config_main.php';

if ($_SESSION['personnel_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

$personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';

$card_id = (int)($_GET['card_id'] ?? 0);
if ($card_id <= 0) die("Geçersiz kart");

$last_used   = $card['last_used'] ?? null;
$created_at  = $card['created_at'] ?? null;
$issued_at   = $card['issued_at'] ?? $created_at; // issued_at varsa onu kullan, yoksa created_at

$stmt = $main_db->prepare("
    SELECT cc.*, c.name as customer_name, c.phone, ct.name as type_name, ct.color_from, ct.color_to 
    FROM customer_cards cc 
    LEFT JOIN customers c ON cc.customer_id = c.id 
    LEFT JOIN card_types ct ON cc.card_type_id = ct.id 
    WHERE cc.id = ?
");
$stmt->bind_param("i", $card_id);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();
if (!$card) die("Kart bulunamadı");

$transactions = [];
if ($main_db->query("SHOW TABLES LIKE 'card_transactions'")->num_rows > 0) {
    $result = $main_db->query("
        SELECT * FROM card_transactions 
        WHERE card_id = $card_id 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    if ($result) {
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title><?= chunk_split($card['card_number'], 4, ' ') ?> • Kart Detay</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <style>
        .card-big { 
            border-radius: 24px; 
            min-height: 280px; 
            background: linear-gradient(135deg, <?= $card['color_from'] ?? '#667eea' ?>, <?= $card['color_to'] ?? '#764ba2' ?>); 
            color: white; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .status-badge { font-size: 1.1rem; padding: 0.5rem 1rem; border-radius: 50px; }
        .info-card {border-radius: 16px; overflow: hidden;}
    </style>
</head>
<body>
<div id="app" class="app app-sidebar-minified">

    <!-- HEADER -->
    <div id="header" class="app-header">
        <div class="mobile-toggler">
            <button type="button" class="menu-toggler" data-toggle="sidebar-mobile">
                <span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <div class="brand">
            <div class="desktop-toggler">
                <button type="button" class="menu-toggler" data-toggle="sidebar-minify">
                    <span class="bar"></span><span class="bar"></span>
                </button>
            </div>
            <a href="index.php" class="brand-logo">
                <img src="assets/img/logo.png" class="invert-dark" alt height="20">
            </a>
        </div>
        <div class="menu">
            <div class="menu-item dropdown">
                <a href="#" data-bs-toggle="dropdown" class="menu-link">
                    <div class="menu-img online">
                        <img src="assets/img/user/user.jpg" alt class="ms-100 mh-100 rounded-circle">
                    </div>
                    <div class="menu-text"><?= htmlspecialchars($personnel_name) ?></div>
                </a>
                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="logout.php">Çıkış</a>
                </div>
            </div>
        </div>
    </div>

    <?php include 'sidebar.php'; ?>

    <div id="content" class="app-content">
        <div class="d-flex align-items-center mb-4">
            <div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="customers.php">Müşteriler</a></li>
                    <li class="breadcrumb-item"><a href="customer_detail.php?id=<?= $card['customer_id'] ?>"><?= htmlspecialchars($card['customer_name']) ?></a></li>
                    <li class="breadcrumb-item active">Kart Detay</li>
                </ol>
                <h1 class="page-header mb-0">Kart Detayları</h1>
            </div>
            <div class="ms-auto">
                <a href="customer_detail.php?id=<?= $card['customer_id'] ?>" class="btn btn-outline-secondary">
                    <i class="fa fa-arrow-left"></i> Geri Dön
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-8">
                <!-- BÜYÜK KART GÖRÜNÜMÜ -->
                <div class="card-big position-relative overflow-hidden mb-4">
                    <div class="position-absolute top-0 start-0 m-4">
                        <span class="badge bg-light text-dark fs-5"><?= htmlspecialchars($card['type_name'] ?? 'Classic') ?></span>
                    </div>
                    <div class="position-absolute top-0 end-0 m-4 text-end">
                        <div class="status-badge <?= $card['status'] == 'active' ? 'bg-success' : ($card['status'] == 'frozen' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                            <?= strtoupper($card['status']) ?>
                        </div>
                        <?php if ($card['is_physical']): ?>
                            <div class="badge bg-light text-dark mt-2 d-block">FİZİKSEL KART</div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5">
                        <h4 class="opacity-75 mb-1">Müşteri</h4>
                        <h3 class="fw-bold mb-4"><?= htmlspecialchars($card['customer_name']) ?></h3>
                        <div class="fs-1 fw-bold tracking-widest mb-4"><?= chunk_split($card['card_number'], 4, ' ') ?></div>
                        <div class="row text-white-50">
                            <div class="col-6"><small>Son Kullanma</small><div class="fs-5"><?= sprintf('%02d/%d', $card['expiry_month'], substr($card['expiry_year'], -2)) ?></div></div>
                            <div class="col-6"><small>CVV</small><div class="fs-5 fw-bold"><?= $card['cvv'] ?></div></div>
                        </div>
                        <?php if ($card['uid']): ?>
                            <div class="mt-4"><small>NFC UID</small><div class="fs-4 text-monospace fw-bold"><?= strtoupper($card['uid']) ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- BAKİYE VE DURUM BİLGİLERİ -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card info-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h5>Mevcut Bakiye</h5>
                                <h2>₺<?= number_format($card['balance'], 2) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card info-card bg-success text-white">
                            <div class="card-body text-center">
                                <h5>Oluşturulma Tarihi</h5>
                                <p><?= $created_at ? date('d.m.Y H:i', strtotime($created_at)) : 'Bilinmiyor' ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card info-card bg-info text-white">
                            <div class="card-body text-center">
                                <h5>Son İşlem</h5>
                                <p><?= $last_used ? date('d.m.Y H:i', strtotime($last_used)) : 'Henüz yok' ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- İŞLEM GEÇMİŞİ -->
                <div class="card">
                    <div class="card-header">
                        <h5>İşlem Geçmişi <small class="text-muted">(son 50)</small></h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($transactions)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fa fa-receipt fa-4x mb-3"></i><br>
                                Henüz işlem yapılmamış
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tarih</th>
                                            <th>İşlem</th>
                                            <th>Tutar</th>
                                            <th>Bakiye</th>
                                            <th>Not</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $t): ?>
                                        <tr>
                                            <td><?= date('d.m.Y H:i', strtotime($t['created_at'] ?? 'now')) ?></td>
                                            <td><span class="badge <?= $t['type'] == 'load' ? 'bg-success' : 'bg-danger' ?>"><?= $t['type'] == 'load' ? 'YÜKLEME' : 'HARCAMA' ?></span></td>
                                            <td class="fw-bold <?= $t['type'] == 'load' ? 'text-success' : 'text-danger' ?>">
                                                <?= $t['type'] == 'load' ? '+' : '-' ?>₺<?= number_format($t['amount'] ?? 0, 2) ?>
                                            </td>
                                            <td>₺<?= number_format($t['balance_after'] ?? 0, 2) ?></td>
                                            <td class="text-muted small"><?= htmlspecialchars($t['note'] ?? '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-4">
                    <div class="card-header bg-dark text-white">Kart İşlemleri</div>
                    <div class="card-body">
                        <?php if ($card['status'] === 'active'): ?>
                            <button class="btn btn-warning w-100 mb-2" onclick="cardAction('freeze_card')"><i class="fa fa-pause"></i> Dondur</button>
                            <button class="btn btn-danger w-100" onclick="cardAction('cancel_card')"><i class="fa fa-ban"></i> İptal Et</button>
                        <?php elseif ($card['status'] === 'frozen'): ?>
                            <button class="btn btn-success w-100" onclick="cardAction('unfreeze_card')"><i class="fa fa-play"></i> Aktifleştir</button>
                        <?php elseif ($card['status'] === 'cancelled'): ?>
                            <button class="btn btn-info w-100" onclick="cardAction('reactivate_card')"><i class="fa fa-redo"></i> Yeniden Aktif</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Kart Bilgileri</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between"><span>Kart No</span> <strong><?= chunk_split($card['card_number'], 4, ' ') ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Tür</span> <strong><?= htmlspecialchars($card['type_name'] ?? 'Bilinmiyor') ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Fiziksel</span> <strong><?= $card['is_physical'] ? 'Evet' : 'Hayır' ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>UID</span> <strong><?= $card['uid'] ? strtoupper($card['uid']) : '-' ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Oluşturulma</span> <strong><?= $created_at ? date('d.m.Y H:i', strtotime($created_at)) : 'Bilinmiyor' ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="assets/js/vendor.min.js"></script>
    <script src="assets/js/app.min.js"></script>

    <script>
        function showToast(title, msg, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-bg-${type} border-0`;
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${msg}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>`;
            document.body.appendChild(toast);
            new bootstrap.Toast(toast).show();
            setTimeout(() => toast.remove(), 3000);
        }

        function cardAction(action, id) {
            if (confirm('Bu işlemi yapmak istediğinizden emin misiniz?')) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=' + action + '&card_id=' + id
                }).then(() => location.reload());
            }
        }
    </script>
</div>
</body>
</html>
<?php ob_end_flush(); ?>