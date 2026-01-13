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

    // Arama (barkod veya isim)
    if ($action === 'search') {
        $q = '%' . ($_POST['q'] ?? '') . '%';
        $results = [];

        // Ürünler
        $stmt = $db->prepare("SELECT 'product' as type, id, name as text, stock_quantity as stock, COALESCE(unit, 'ad') as unit FROM products WHERE branch_id = ? AND (name LIKE ? OR barcode LIKE ?) LIMIT 10");
        $stmt->bind_param("iss", $branch_id, $q, $q);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $results[] = $row;

        // Malzemeler
        $stmt = $db->prepare("SELECT 'ingredient' as type, id, name as text, stock_quantity as stock, COALESCE(unit, '') as unit FROM ingredients WHERE branch_id = ? AND name LIKE ? LIMIT 10");
        $stmt->bind_param("is", $branch_id, $q);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $results[] = $row;

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    // Kaydet (tüm sayılanlar)
    if ($action === 'save_count' && !empty($_POST['items'])) {
        $items = json_decode($_POST['items'], true);
        $success = true;

        $db->begin_transaction();
        try {
            $user_id = $_SESSION['personnel_id'];
            foreach ($items as $item) {
                $count = floatval($item['count']);
                $system = floatval($item['system']);

                if ($count == $system) continue;

                // Stok güncelle
                if ($item['type'] === 'product') {
                    $stmt = $db->prepare("UPDATE products SET stock_quantity = ? WHERE id = ? AND branch_id = ?");
                } else {
                    $stmt = $db->prepare("UPDATE ingredients SET stock_quantity = ? WHERE id = ? AND branch_id = ?");
                }
                $stmt->bind_param("dii", $count, $item['id'], $branch_id);
                $stmt->execute();

                // Hareket kaydı at
                $type = $count > $system ? 'in' : 'out';
                $quantity = $count - $system;
                $stmt = $db->prepare("INSERT INTO stock_movements (branch_id, item_type, item_id, type, quantity, old_stock, new_stock, reason, user_id) VALUES (?, ?, ?, 'count', ?, ?, ?, 'Stok Sayımı', ?)");
                $stmt->bind_param("isiiddii", $branch_id, $item['type'], $item['id'], $quantity, $system, $count, $user_id);
                $stmt->execute();
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            $success = false;
        }

        echo json_encode(['success' => $success]);
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
    <title>SABL | Stok Sayımı (Barkod + Arama)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
                <h5>Stok Sayımı (Barkod ile Hızlı Ekle)</h5>
                <button class="btn btn-success" id="saveBtn" disabled><i class="fa fa-save"></i> Tümünü Kaydet (<span id="count">0</span>)</button>
            </div>
            <div class="card-body">
                <!-- ARAMA KUTUSU -->
                <div class="mb-4">
                    <select id="searchItem" class="form-select w-100" placeholder="Barkod okutun veya ürün/malzeme arayın..."></select>
                </div>

                <!-- SAYIM TABLOSU -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Tip</th>
                                <th>Ürün / Malzeme</th>
                                <th>Birim</th>
                                <th>Sistem Stok</th>
                                <th>Sayım Miktarı</th>
                                <th>Fark</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody id="countTable">
                            <tr><td colspan="8" class="text-center text-muted py-5">Barkod okutun veya arama yapın...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const CSRF = '<?php echo $csrf_token; ?>';
let countedItems = []; // {type, id, name, unit, system, count}

$('#searchItem').select2({
    placeholder: 'Barkod okutun veya ürün/malzeme arayın...',
    allowClear: true,
    ajax: {
        url: 'stock_count.php',
        type: 'POST',
        dataType: 'json',
        delay: 250,
        data: params => ({
            ajax: 1,
            action: 'search',
            q: params.term || ''
        }),
        processResults: data => ({
            results: data.results.map(r => ({
                id: r.type + '_' + r.id,
                text: r.text,
                type: r.type,
                unit: r.unit || '',
                stock: r.stock
            }))
        }),
        cache: true
    },
    templateResult: data => {
        if (!data.id) return data.text;
        const typeLabel = data.type === 'product' ? 'Ürün' : 'Malzeme';
        const unitText = data.unit ? ` ${data.unit}` : '';
        return $(`<span>${data.text} <small class="text-muted">(${typeLabel} - Stok: ${parseFloat(data.stock).toFixed(3)}${unitText})</small></span>`);
    },
    templateSelection: data => data.text || data.id
}).on('select2:select', function (e) {
    const data = e.params.data;
    const [type, id] = data.id.split('_');
    const name = data.text;
    const unit = data.unit || (type === 'product' ? '' : 'ad'); // ürünlerde birim yoksa boş

    if (countedItems.find(x => x.type === type && x.id == id)) {
        alert('Bu ürün zaten listede!');
        $(this).val(null).trigger('change');
        return;
    }

    countedItems.push({
        type,
        id: parseInt(id),
        name,
        unit,
        system: parseFloat(data.stock),
        count: parseFloat(data.stock)
    });

    renderTable();
    $(this).val(null).trigger('change');
    $('#searchItem').focus();
});

function renderTable() {
    const tbody = document.getElementById('countTable');
    if (countedItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5">Barkod okutun veya arama yapın...</td></tr>';
        document.getElementById('saveBtn').disabled = true;
        return;
    }

    let html = '';
    countedItems.forEach((item, index )=> {
        const diff = item.count - item.system;
        html += `<tr>
            <td>${index + 1}</td>
            <td><span class="badge bg-${item.type === 'product' ? 'primary' : 'info'}">${item.type === 'product' ? 'Ürün' : 'Malzeme'}</span></td>
            <td>${item.name}</td>
            <td>${item.unit ? item.unit : '-'}</td>
            <td class="fw-600">${item.system.toFixed(3)}</td>
            <td><input type="number" step="0.001" class="form-control form-control-sm w-100px" value="${item.count.toFixed(3)}" onchange="updateCount(${index}, this.value)"></td>
            <td class="fw-600 ${diff > 0 ? 'text-success' : diff < 0 ? 'text-danger' : 'text-muted'}">${diff > 0 ? '+' : ''}${diff.toFixed(3)}</td>
            <td><button class="btn btn-danger btn-sm" onclick="removeItem(${index})"><i class="fa fa-trash"></i></button></td>
        </tr>`;
    });
    tbody.innerHTML = html;
    document.getElementById('count').textContent = countedItems.length;
    document.getElementById('saveBtn').disabled = false;
}

function updateCount(index, value) {
    countedItems[index].count = parseFloat(value) || 0;
    renderTable();
}

function removeItem(index) {
    countedItems.splice(index, 1);
    renderTable();
}

document.getElementById('saveBtn').addEventListener('click', async () => {
    if (!confirm(`${countedItems.length} ürün kaydedilsin mi?`)) return;

    const res = await fetch('stock_count.php', {
        method: 'POST',
        body: new URLSearchParams({
            ajax: 1,
            action: 'save_count',
            items: JSON.stringify(countedItems)
        })
    });
    const data = await res.json();

    if (data.success) {
        alert('Stok sayımı başarıyla kaydedildi!');
        countedItems = [];
        renderTable();
    } else {
        alert('Hata oluştu!');
    }
});

// Barkod okuyucu için odak
document.getElementById('searchItem').focus();
</script>
</body>
</html>
<?php ob_end_flush(); ?>