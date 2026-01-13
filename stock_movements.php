<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions/common.php';

if ($_SESSION['personnel_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

// AJAX İŞLEMLERİ
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    ob_end_clean();

    $branch_id = get_current_branch();
    $action = $_POST['action'] ?? '';

    if ($action === 'stock_movements') {
        $date = $_POST['date'] ?? '';
        $type = $_POST['type'] ?? '';

        $sql = "SELECT 
                    sm.id,
                    sm.created_at,
                    sm.type,
                    CASE sm.type
                        WHEN 'in' THEN 'Giriş'
                        WHEN 'out' THEN 'Çıkış'
                        WHEN 'count' THEN 'Sayım'
                        WHEN 'adjustment' THEN 'Düzeltme'
                        WHEN 'order' THEN 'Sipariş'
                        ELSE 'Diğer'
                    END as type_tr,
                    COALESCE(p.name, i.name) as item_name,
                    COALESCE(p.unit, i.unit, 'ad') as unit,
                    sm.quantity,
                    sm.old_stock,
                    sm.new_stock,
                    sm.reason,
                    u.username as user_name
                FROM stock_movements sm
                LEFT JOIN products p ON sm.item_type = 'product' AND sm.item_id = p.id
                LEFT JOIN ingredients i ON sm.item_type = 'ingredient' AND sm.item_id = i.id
                LEFT JOIN personnel u ON sm.user_id = u.id
                WHERE sm.branch_id = ?";

        $params = [$branch_id];
        $types = "i";

        if ($date) {
            $sql .= " AND DATE(sm.created_at) = ?";
            $params[] = $date;
            $types .= "s";
        }
        if ($type) {
            $sql .= " AND sm.type = ?";
            $params[] = $type;
            $types .= "s";
        }

        $sql .= " ORDER BY sm.created_at DESC LIMIT 1000";

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $movements = [];
        while ($row = $res->fetch_assoc()) {
            $movements[] = $row;
        }

        echo json_encode(['success' => true, 'movements' => $movements]);
        exit;
    }

    echo json_encode(['success' => false]);
    exit;
}

$personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';
$branch_id = get_current_branch();
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>SABL | Stok Hareketleri</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
    <?php include('sidebar.php'); ?>

    <div id="content" class="app-content">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Stok Hareketleri</h5>
                <div class="d-flex gap-2">
                    <input type="date" id="filterDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    <select id="filterType" class="form-control form-control-sm">
                        <option value="">Tümü</option>
                        <option value="in">Giriş</option>
                        <option value="out">Çıkış</option>
                        <option value="count">Sayım</option>
                        <option value="adjustment">Düzeltme</option>
                        <option value="order">Sipariş</option>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="loadMovements()">Filtrele</button>
                    <button class="btn btn-success btn-sm" onclick="loadMovements()">Bugün</button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Tarih/Saat</th>
                                <th>Tip</th>
                                <th>Ürün/Malzeme</th>
                                <th>Birim</th>
                                <th>Miktar</th>
                                <th>Eski → Yeni</th>
                                <th>Açıklama</th>
                                <th>Kullanıcı</th>
                            </tr>
                        </thead>
                        <tbody id="movementsBody">
                            <tr><td colspan="8" class="text-center py-4"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
const CSRF = '<?php echo $csrf_token; ?>';

async function fetchData(data) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('csrf_token', CSRF);
    for (let key in data) formData.append(key, data[key]);

    const res = await fetch('stock_movements.php', { method: 'POST', body: formData });
    const text = await res.text();
    console.log('RAW:', text.trim());
    return JSON.parse(text);
}

async function loadMovements() {
    const date = document.getElementById('filterDate').value;
    const type = document.getElementById('filterType').value;

    const data = await fetchData({ 
        action: 'stock_movements',
        date: date,
        type: type
    });

    const tbody = document.getElementById('movementsBody');
    if (!data.success || data.movements.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5">Kayıt bulunamadı</td></tr>';
        return;
    }

    let html = '';
    data.movements.forEach(m => {
        const badgeClass = {
            'in': 'success',
            'out': 'danger',
            'count': 'primary',
            'adjustment': 'warning',
            'order': 'info'
        }[m.type] || 'secondary';

        const qtyClass = m.quantity > 0 ? 'text-success' : 'text-danger';

        html += `<tr>
            <td class="small">${m.created_at.replace(' ', '<br>')}</td>
            <td><span class="badge bg-${badgeClass} p-2" style="text-transform: uppercase; font-weight: bold;">${m.type_tr}</span></td>
            <td class="fw-600">${m.item_name}</td>
            <td class="text-muted">${m.unit}</td>
            <td class="${qtyClass} fw-600">${m.quantity > 0 ? '+' : ''}${parseFloat(m.quantity).toFixed(3)}</td>
            <td><span class="text-muted">${parseFloat(m.old_stock).toFixed(3)}</span> → <span class="text-primary fw-600">${parseFloat(m.new_stock).toFixed(3)}</span></td>
            <td class=" text-muted">${m.reason || '-'}</td>
            <td class="">${m.user_name || '-'}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

window.onload = () => {
    loadMovements();
    document.getElementById('filterDate').value = new Date().toISOString().slice(0, 10);
};
</script>
</body>
</html>
<?php ob_end_flush(); ?>