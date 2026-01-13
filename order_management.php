<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions/common.php';

if ($_SESSION['personnel_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

// AJAX
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    ob_end_clean();

    $branch_id = get_current_branch();

    // Tüm ürünleri getir
    if ($_POST['action'] === 'get_all_items') {
        $items = [];

        // Ürünler
        $stmt = $db->prepare("SELECT 'product' as type, id, name, stock_quantity as stock, COALESCE(unit, 'ad') as unit FROM products WHERE branch_id = ?");
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['display_name'] = $row['name'] . ' (Ürün)';
            $items[] = $row;
        }

        // Malzemeler
        $stmt = $db->prepare("SELECT 'ingredient' as type, id, name, stock_quantity as stock, COALESCE(unit, '') as unit FROM ingredients WHERE branch_id = ?");
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['display_name'] = $row['name'] . ' (Malzeme)';
            $items[] = $row;
        }

        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    // Sipariş gönder
    if ($_POST['action'] === 'place_order' && !empty($_POST['items'])) {
        $items = json_decode($_POST['items'], true);
        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Sepet boş']);
            exit;
        }

        $db->begin_transaction();
        try {
            $total_qty = array_sum(array_column($items, 'quantity'));
            $stmt = $db->prepare("INSERT INTO branch_orders (branch_id, total_items, status) VALUES (?, ?, 'pending')");
            $stmt->bind_param("ii", $branch_id, $total_qty);
            $stmt->execute();
            $order_id = $db->insert_id;

            $stmt2 = $db->prepare("INSERT INTO branch_order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
            foreach ($items as $it) {
                $stmt2->bind_param("iii", $order_id, $it['id'], $it['quantity']);
                $stmt2->execute();
            }

            // Stok hareketi (order)
            $user_id = $_SESSION['personnel_id'] ?? 1;
            $stmt3 = $db->prepare("INSERT INTO stock_movements 
                (branch_id, item_type, item_id, type, quantity, old_stock, new_stock, reason, user_id, created_at) 
                SELECT ?, ?, ?, 'order', ?, stock_quantity, stock_quantity, 'Ana merkeze sipariş', ?, NOW() 
                FROM " . ($it['type'] === 'product' ? 'products' : 'ingredients') . " WHERE id = ?");

            foreach ($items as $it) {
                $stmt3->bind_param("isiiii", $branch_id, $it['type'], $it['id'], $it['quantity'], $user_id, $it['id']);
                $stmt3->execute();
            }

            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Geçmiş siparişler
    if ($_POST['action'] === 'list_orders') {
        $stmt = $db->prepare("SELECT id, created_at, total_items, status FROM branch_orders WHERE branch_id = ? ORDER BY id DESC LIMIT 100");
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        echo json_encode(['success' => true, 'orders' => $result->fetch_all(MYSQLI_ASSOC)]);
        exit;
    }

    // Sipariş detayı
    if ($_POST['action'] === 'order_detail' && !empty($_POST['order_id'])) {
        $order_id = (int)$_POST['order_id'];
        $stmt = $db->prepare("SELECT p.name, boi.quantity, COALESCE(p.unit,'ad') as unit 
                              FROM branch_order_items boi 
                              JOIN products p ON boi.product_id = p.id 
                              WHERE boi.order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        echo json_encode(['success' => true, 'items' => $result->fetch_all(MYSQLI_ASSOC)]);
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
    <title>SABL | Ana Merkeze Sipariş</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        .product-card { cursor: pointer; transition: all 0.2s; }
        .product-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .product-card.selected { border: 2px solid #0d6efd; background: #f8f9fa; }
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
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5>Ana Merkeze Sipariş</h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#selectModal">
                    <i class="fa fa-plus"></i> Yeni Sipariş
                </button>
            </div>
            <div class="card-body">
                <h6 class="text-muted mb-3">Geçmiş Siparişler</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>#</th><th>Tarih</th><th>Ürün Sayısı</th><th>Durum</th><th></th></tr>
                        </thead>
                        <tbody id="ordersBody">
                            <tr><td colspan="5" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- 1. AŞAMA: ÜRÜN SEÇME MODALI -->
    <div class="modal fade" id="selectModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="w-100">
                        <input type="text" class="form-control" id="searchBox" placeholder="Ürün veya malzeme ara..." autocomplete="off">
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height:70vh; overflow-y:auto">
                    <div class="row" id="productsGrid"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button class="btn btn-primary" id="nextBtn" disabled onclick="nextStep()">
                        <span id="selectedCount">0</span> ürün seçildi → Miktar Belirle
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. AŞAMA: MİKTAR GİRME MODALI -->
    <div class="modal fade" id="quantityModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>2. Sipariş Miktarlarını Girin</h5>
                </div>
                <div class="modal-body">
                    <table class="table">
                        <thead><tr><th>Ürün</th><th>Birim</th><th>Miktar</th></tr></thead>
                        <tbody id="quantityBody"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="backToSelect()">Geri</button>
                    <button class="btn btn-success" id="sendOrderBtn">Siparişi Gönder</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. DETAY MODALI -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5>Sipariş Detayı</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <table class="table"><tbody id="detailBody"></tbody></table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
    const CSRF = '<?php echo $csrf_token; ?>';
    let selectedProducts = []; // sadece id'ler
    let orderItems = [];       // {id, name, unit, quantity}

    async function api(data) {
        const f = new FormData();
        f.append('ajax', '1');
        f.append('csrf_token', CSRF);
        for (let k in data) f.append(k, data[k]);
        const r = await fetch('order_management.php', {method:'POST', body:f});
        return await r.json();
    }

    async function loadAllItems() {
        const data = await api({action:'get_all_items'});
        if (!data.success) return;

        const grid = document.getElementById('productsGrid');
        let html = '';

        data.items.forEach(item => {
            const stockBadge = item.stock < 10 ? 'danger' : item.stock < 30 ? 'warning' : 'success';
            html += `
            <div class="col-md-4 col-lg-3 mb-3">
                <div class="card product-card h-100" onclick="toggleSelect(${item.id}, '${item.type}')" id="card_${item.type}_${item.id}">
                    <div class="card-body text-center">
                        <h6 class="card-title mb-2">${item.display_name}</h6>
                        <p class="small text-muted mb-1">Stok: <span class="badge bg-${stockBadge}">${parseFloat(item.stock).toFixed(3)}</span></p>
                        <small class="text-muted">${item.unit || 'ad'}</small>
                    </div>
                </div>
            </div>`;
        });
        grid.innerHTML = html;
    }

    function toggleSelect(id, type) {
        const cardId = `card_${type}_${id}`;
        const card = document.getElementById(cardId);
        const key = `${type}_${id}`;

        if (selectedProducts.includes(key)) {
            selectedProducts = selectedProducts.filter(x => x !== key);
            card.classList.remove('selected');
        } else {
            selectedProducts.push(key);
            card.classList.add('selected');
        }
        document.getElementById('selectedCount').textContent = selectedProducts.length;
        document.getElementById('nextBtn').disabled = selectedProducts.length === 0;
    }

    function nextStep() {
        if (selectedProducts.length === 0) return;

        orderItems = [];
        selectedProducts.forEach(key => {
            const [type, id] = key.split('_');
            const card = document.getElementById(`card_${type}_${id}`);
            const name = card.querySelector('h6').textContent.trim();
            const unit = card.querySelector('small').textContent.trim();
            orderItems.push({id: parseInt(id), type, name, unit, quantity: 1});
        });

        renderQuantityTable();
        bootstrap.Modal.getInstance(document.getElementById('selectModal')).hide();
        new bootstrap.Modal(document.getElementById('quantityModal')).show();
    }

    function renderQuantityTable() {
        let h = '';
        orderItems.forEach(it => {
            h += `<tr>
                <td>${it.name}</td>
                <td>${it.unit}</td>
                <td><input type="number" class="form-control w-100px" value="${it.quantity}" min="1" onchange="orderItems.find(x=>x.id==${it.id}).quantity=this.value"></td>
            </tr>`;
        });
        document.getElementById('quantityBody').innerHTML = h;
    }

    function backToSelect() {
        bootstrap.Modal.getInstance(document.getElementById('quantityModal')).hide();
        new bootstrap.Modal(document.getElementById('selectModal')).show();
    }

    document.getElementById('sendOrderBtn').onclick = async () => {
        if (!confirm('Sipariş gönderilsin mi?')) return;
        const res = await api({action:'place_order', items:JSON.stringify(orderItems)});
        if (res.success) {
            alert('Sipariş başarıyla gönderildi!');
            selectedProducts = [];
            orderItems = [];
            bootstrap.Modal.getInstance(document.getElementById('quantityModal')).hide();
            loadOrders();
        } else {
            alert('Hata!');
        }
    };

    async function loadOrders() {
        const d = await api({action:'list_orders'});
        let h = '';
        d.orders.forEach((o,i) => {
            const badge = o.status==='pending' ? 'warning' : 'success';
            const status = o.status==='pending' ? 'Bekliyor' : 'Onaylandı';
            h += `<tr>
                <td>${i+1}</td>
                <td>${o.created_at}</td>
                <td>${o.total_items}</td>
                <td><span class="badge bg-${badge}">${status}</span></td>
                <td><button class="btn btn-sm btn-info" onclick="showDetail(${o.id})">Detay</button></td>
            </tr>`;
        });
        document.getElementById('ordersBody').innerHTML = h || '<tr><td colspan="5" class="text-center text-muted">Henüz sipariş yok</td></tr>';
    }

    async function showDetail(id) {
        const d = await api({action:'order_detail', order_id:id});
        let h = '';
        d.items.forEach(it => h += `<tr><td>${it.name}</td><td>${it.quantity}</td><td>${it.unit}</td></tr>`);
        document.getElementById('detailBody').innerHTML = h;
        new bootstrap.Modal(document.getElementById('detailModal')).show();
    }

    window.onload = () => {
        loadAllItems();
        loadOrders();
    };
    document.getElementById('searchBox')?.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('.product-card').forEach(card => {
            const title = card.querySelector('h6').textContent.toLowerCase();
            card.closest('.col-md-4').style.display = title.includes(query) ? 'block' : 'none';
        });
    });
</script>
</body>
</html>
<?php ob_end_flush(); ?>