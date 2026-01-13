<?php
    ob_start();
    session_start();
    require_once 'config.php';
    require_once 'functions/common.php';

    if ($_SESSION['personnel_type'] != 'admin') {
        header("Location: login.php");
        exit;
    }

    if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
        // AJAX İSTEĞİ GELDİYSE SADECE JSON DÖNDÜR!
        header('Content-Type: application/json; charset=utf-8');
        ob_end_clean(); // BU KESİNLİKLE ÇALIŞIR

        $branch_id = get_current_branch();
        $action = $_POST['action'] ?? '';
        $product_id = (int)($_POST['product_id'] ?? 0);

        // Ürünleri Listele
        if ($action === 'list') {
            $stmt = $db->prepare("SELECT id, name FROM products WHERE branch_id = ? ORDER BY name");
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $res = $stmt->get_result();
            echo json_encode(['success' => true, 'products' => $res->fetch_all(MYSQLI_ASSOC)]);
            exit;
        }

        // Malzemeleri Listele
        if ($action === 'ingredients') {
            $stmt = $db->prepare("SELECT id, name, unit FROM ingredients WHERE branch_id = ?");
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $res = $stmt->get_result();
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            exit;
        }

        // Reçeteleri Getir
        if ($action === 'recipes' && $product_id) {
            $stmt = $db->prepare("
                SELECT ri.id, ri.quantity, i.name as ingredient_name, i.unit, i.unit_cost 
                FROM recipe_ingredients ri 
                JOIN ingredients i ON ri.ingredient_id = i.id 
                WHERE ri.product_id = ?
            ");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $res = $stmt->get_result();
            echo json_encode(['success' => true, 'recipes' => $res->fetch_all(MYSQLI_ASSOC)]);
            exit;
        }

        // Reçeteye Malzeme Ekle
        if ($action === 'add_recipe' && $product_id) {
            $ingredient_id = (int)$_POST['ingredient_id'];
            $quantity = floatval($_POST['quantity']);
            $stmt = $db->prepare("INSERT INTO recipe_ingredients (product_id, ingredient_id, quantity) VALUES (?, ?, ?)");
            $stmt->bind_param("iid", $product_id, $ingredient_id, $quantity);
            echo json_encode(['success' => $stmt->execute()]);
            exit;
        }

        // Düzenle
        if ($action === 'edit_recipe') {
            $id = (int)$_POST['id'];
            $quantity = floatval($_POST['quantity']);
            $stmt = $db->prepare("UPDATE recipe_ingredients SET quantity = ? WHERE id = ?");
            $stmt->bind_param("di", $quantity, $id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;
        }

        // Sil
        if ($action === 'delete_recipe') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("DELETE FROM recipe_ingredients WHERE id = ?");
            $stmt->bind_param("i", $id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;
        }

        // Maliyet Hesapla + Optimizasyon
        if ($action === 'cost' && $product_id) {
            // Reçete maliyeti
            $stmt = $db->prepare("SELECT SUM(ri.quantity * i.unit_cost) as total_cost FROM recipe_ingredients ri JOIN ingredients i ON ri.ingredient_id = i.id WHERE ri.product_id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $total_cost = $stmt->get_result()->fetch_assoc()['total_cost'] ?? 0;

            // Ürün fiyatı
            $stmt = $db->prepare("SELECT unit_price FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $unit_price = $stmt->get_result()->fetch_assoc()['unit_price'] ?? 0;

            $profit = $unit_price - $total_cost;
            $profit_margin = $unit_price > 0 ? round(($profit / $unit_price) * 100, 2) : 0;

            echo json_encode([
                'success' => true,
                'cost' => [
                    'total_cost' => number_format($total_cost, 2),
                    'unit_price' => number_format($unit_price, 2),
                    'profit' => number_format($profit, 2),
                    'profit_margin' => $profit_margin
                ]
            ]);
            exit;
        }


        if ($action === 'edit' && $product_id) {
            $unit_price = floatval($_POST['unit_price'] ?? 0);
            $stmt = $db->prepare("UPDATE products SET unit_price = ? WHERE id = ? AND branch_id = ?");
            $stmt->bind_param("dii", $unit_price, $product_id, $branch_id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;
        }

        // optimize_price düzeltildi → $unit_price tanımlı olsun
        if ($action === 'optimize_price' && $product_id) {
            $target = 30; // %30 kar hedefi

            // Maliyeti hesapla
            $stmt = $db->prepare("SELECT SUM(ri.quantity * i.unit_cost) as cost FROM recipe_ingredients ri JOIN ingredients i ON ri.ingredient_id = i.id WHERE ri.product_id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $cost_result = $stmt->get_result()->fetch_assoc();
            $cost = $cost_result['cost'] ?? 0;

            // Mevcut satış fiyatını al
            $stmt = $db->prepare("SELECT unit_price FROM products WHERE id = ? AND branch_id = ?");
            $stmt->bind_param("ii", $product_id, $branch_id);
            $stmt->execute();
            $price_result = $stmt->get_result()->fetch_assoc();
            $unit_price = $price_result['unit_price'] ?? 0;

            // Önerilen fiyat hesapla
            $suggested = ($cost > 0) ? ceil(($cost * 100) / (100 - $target)) : $unit_price;

            // Kar marjı hesapla (sıfıra bölme hatası önlendi)
            $current_margin = ($unit_price > 0) ? round((($unit_price - $cost) / $unit_price) * 100, 2) : 0;

            echo json_encode([
                'success' => true,
                'optimization' => [
                    'current_margin' => $current_margin,
                    'suggested_price' => (int)$suggested,
                    'needs_update' => $current_margin < $target
                ]
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Geçersiz action']);
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
        <title>SABL | Reçete Yönetimi</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="assets/css/vendor.min.css" rel="stylesheet">
        <link href="assets/css/app.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    </head>
    <body>
        <div id="app" class="app">
            <!-- HEADER -->
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
                    <a href="admin_dashboard.php" class="brand-logo">
                        <img src="assets/img/logo.png" class="invert-dark" alt height="20">
                    </a>
                </div>
                <div class="menu">
                    <form class="menu-search" method="POST">
                        <div class="menu-search-icon"><i class="fa fa-search"></i></div>
                        <div class="menu-search-input">
                            <input type="text" class="form-control" placeholder="Menüde ara...">
                        </div>
                    </form>
                    <div class="menu-item dropdown">
                        <a href="#" data-bs-toggle="dropdown" class="menu-link">
                            <div class="menu-icon"><i class="fa fa-bell nav-icon"></i></div>
                            <div class="menu-label" id="notificationCount">0</div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-notification" id="notificationsList">
                            <h6 class="dropdown-header">Bildirimler</h6>
                            <div class="text-center p-3">Yükleniyor...</div>
                        </div>
                    </div>
                    <div class="menu-item dropdown">
                        <a href="#" data-bs-toggle="dropdown" class="menu-link">
                            <div class="menu-img online">
                                <img src="assets/img/user/user.jpg" alt class="ms-100 mh-100 rounded-circle">
                            </div>
                            <div class="menu-text"><?php echo htmlspecialchars($personnel_name); ?></div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end me-lg-3">
                            <a class="dropdown-item d-flex align-items-center" href="profile.php">Profil <i class="fa fa-user-circle fa-fw ms-auto text-body text-opacity-50"></i></a>
                            <a class="dropdown-item d-flex align-items-center" href="settings.php">Ayarlar <i class="fa fa-wrench fa-fw ms-auto text-body text-opacity-50"></i></a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item d-flex align-items-center" href="logout.php">Çıkış Yap <i class="fa fa-toggle-off fa-fw ms-auto text-body text-opacity-50"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <?php include('sidebar.php'); ?>

            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;"></div>

            <div id="content" class="app-content">
                <div class="d-flex justify-content-between mb-3 text-right float-right">
                    <div id="marginStatus"></div>
                    <button class="btn btn-success btn-sm" id="optimizeBtn" style="display:none;padding;0">
                        <i class="fa fa-calculator"></i> Fiyat Optimizasyonu
                    </button>
                </div>
                <div class="row">
                    <div class="col-xl-12 mb-3">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Reçete Yönetimi</h5>
                                <div>
                                    <select id="recipeProductSelect" class="form-select form-select-sm d-inline-block w-250px"></select>
                                    <button class="btn btn-primary btn-sm ms-2" id="addRecipeBtn" disabled>+ Malzeme Ekle</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Malzeme</th>
                                                <th>Miktar</th>
                                                <th>Birim</th>
                                                <th>Maliyet</th>
                                                <th>İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody id="recipeBody">
                                            <tr><td colspan="4" class="text-center text-muted">Ürün seçin</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="addRecipeModal" tabindex="-1">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Malzeme Ekle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="addRecipeForm">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Malzeme</label>
                                    <select name="ingredient_id" class="form-select" id="addIngredientSelect" required></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Miktar</label>
                                    <input type="number" name="quantity" class="form-control" step="0.001" min="0.001" required>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="submit" class="btn btn-primary">Ekle</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- MİKTAR DÜZENLE -->
            <div class="modal fade" id="editRecipeModal" tabindex="-1">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Miktar Düzenle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="editRecipeForm">
                            <input type="hidden" name="id">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Miktar</label>
                                    <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="submit" class="btn btn-primary">Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <a href="#" data-click="scroll-top" class="btn-scroll-top fade"><i class="fa fa-arrow-up"></i></a>
        </div>

        <!-- SCRIPTS -->
            <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
            <script src="assets/js/vendor.min.js?v=<?= time() ?>"></script>
            <script src="assets/js/app.min.js?v=<?= time() ?>"></script>

        <script>
            window.cloudflareRocketLoaderDisabled = true;
            console.log('Reçete Yönetimi JS BAŞLADI');
            const CSRF = '<?php echo $csrf_token; ?>';
            let currentProductId = null;
            let ingredients = [];

            function showToast(message, type = 'success') {
                const toast = $(`<div class="toast align-items-center text-white bg-${type} border-0" role="alert">
                    <div class="d-flex"><div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div></div>`);
                $('.toast-container').append(toast);
                new bootstrap.Toast(toast[0], { delay: 3000 }).show();
            }

            async function fetchData(data) {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('csrf_token', CSRF);
                for (let key in data) formData.append(key, data[key]);

                try {
                    const res = await fetch('recipe_management.php', {
                        method: 'POST',
                        body: formData
                    });
                    const text = await res.text();
                    console.log('RAW CEVAP:', text); // BU SATIRI EKLE, NE GELDİĞİNİ GÖR!
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return JSON.parse(text);
                } catch (e) {
                    console.error('AJAX HATASI:', e);
                    showToast('Sunucu hatası!', 'danger');
                    return { success: false };
                }
            }

            // Ürünler yükle
            async function loadProducts() {
                const data = await fetchData({ action: 'list' });
                if (!data.success) {
                    alert('Ürünler yüklenemedi: ' + data.message);
                    return;
                }
                const select = document.getElementById('recipeProductSelect');
                select.innerHTML = '<option value="">-- Ürün Seç --</option>';
                data.products.forEach(p => {
                    select.innerHTML += `<option value="${p.id}">${p.name}</option>`;
                });
            }

            // Malzemeler yükle
            async function loadIngredients() {
                const data = await fetchData({ action: 'ingredients' });
                ingredients = data || [];
                const select = document.getElementById('addIngredientSelect');
                select.innerHTML = '';
                ingredients.forEach(i => {
                    select.innerHTML += `<option value="${i.id}">${i.name} (${i.unit})</option>`;
                });
            }

            // Reçete yükle
            async function loadRecipes(productId) {
                const tbody = document.getElementById('recipeBody');
                const costContainer = document.querySelector('.cost-container');
                if (costContainer) costContainer.remove();

                if (!productId) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Ürün seçin</td></tr>';
                    return;
                }

                const data = await fetchData({ action: 'recipes', product_id: productId });
                if (!data.success || !data.recipes || data.recipes.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Bu ürüne reçete yok</td></tr>';
                    return;
                }

                let html = '';
                let totalCost = 0;
                data.recipes.forEach(r => {
                    const cost = (r.quantity * (r.unit_cost || 0)).toFixed(2);
                    totalCost += parseFloat(cost);
                    html += `<tr data-id="${r.id}">
                        <td>${r.ingredient_name}</td>
                        <td><span class="fw-600">${parseFloat(r.quantity).toFixed(3)}</span></td>
                        <td>${r.unit}</td>
                        <td><span class="text-success fw-600">${cost} ₺</span></td>
                        <td>
                            <button class="btn btn-warning btn-sm" onclick="editRecipe(${r.id}, ${r.quantity})"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteRecipe(${r.id})"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>`;
                });
                tbody.innerHTML = html;

                // Maliyet hesapla
                const costData = await fetchData({ action: 'cost', product_id: productId });
                let costHtml = '';
                if (costData.success) {
                    const c = costData.cost;
                    const profitClass = c.profit >= 0 ? 'text-success' : 'text-danger';
                    const marginClass = c.profit_margin >= 30 ? 'text-success' : (c.profit_margin >= 15 ? 'text-warning' : 'text-danger');
                    costHtml = `
                        <div class="cost-container mt-3 p-3 bg-light rounded border">
                            <div class="row text-center g-3">
                                <div class="col-3">
                                    <small class="text-muted d-block">Toplam Maliyet</small>
                                    <strong class="fs-5 text-primary">${c.total_cost} ₺</strong>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted d-block">Satış Fiyatı</small>
                                    <strong class="fs-5 text-info">${c.unit_price} ₺</strong>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted d-block">Kar</small>
                                    <strong class="fs-5 ${profitClass}">${c.profit} ₺</strong>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted d-block">Kar Marjı</small>
                                    <strong class="fs-5 ${marginClass}">%${c.profit_margin}</strong>
                                </div>
                            </div>
                        </div>`;
                } else {
                    costHtml = `<div class="alert alert-warning mt-3">Maliyet hesaplanamadı.</div>`;
                }
                document.querySelector('.card-body').insertAdjacentHTML('beforeend', costHtml);
                // Maliyet hesaplandıktan sonra
                const optimizeData = await fetchData({ action: 'optimize_price', product_id: productId, target_margin: 30 });
                let statusHtml = '';
                const optimizeBtn = document.getElementById('optimizeBtn');
                optimizeBtn.style.display = 'none';

                if (optimizeData.success) {
                    const o = optimizeData.optimization;
                    const icon = o.current_margin >= 30 ? 'check' : (o.current_margin >= 15 ? 'exclamation' : 'times');
                    const color = o.current_margin >= 30 ? 'success' : (o.current_margin >= 15 ? 'warning' : 'danger');

                    statusHtml = `<span class="badge bg-${color}"><i class="fa fa-${icon}-circle"></i> %${o.current_margin}</span>`;

                    if (o.needs_update) {
                        statusHtml += ` → <strong class="text-primary">Önerilen: ${o.suggested_price} ₺</strong>`;
                        optimizeBtn.style.display = 'inline-block';
                        optimizeBtn.onclick = () => {
                            if (confirm(`Fiyat ${o.suggested_price} ₺ olsun mu?`)) {
                                updateProductPrice(productId, o.suggested_price);
                            }
                        };
                    }
                }
                document.getElementById('marginStatus').innerHTML = statusHtml;
            }

            // Ürün seçimi
            document.getElementById('recipeProductSelect').addEventListener('change', (e) => {
                currentProductId = e.target.value;
                document.getElementById('addRecipeBtn').disabled = !currentProductId;
                loadRecipes(currentProductId);
            });

            // + Malzeme Ekle
            document.getElementById('addRecipeBtn').addEventListener('click', () => {
                if (!currentProductId) return;
                new bootstrap.Modal(document.getElementById('addRecipeModal')).show();
            });

            // Ekle
            document.getElementById('addRecipeForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const data = await fetchData({
                    action: 'add_recipe',
                    product_id: currentProductId,
                    ...Object.fromEntries(formData)
                });
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addRecipeModal')).hide();
                    e.target.reset();
                    loadRecipes(currentProductId);
                } else {
                    alert(data.message);
                }
            });

            // Düzenle
            window.editRecipe = function(id, qty) {
                document.querySelector('#editRecipeForm [name="id"]').value = id;
                document.querySelector('#editRecipeForm [name="quantity"]').value = qty;
                new bootstrap.Modal(document.getElementById('editRecipeModal')).show();
            };

            document.getElementById('editRecipeForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const data = await fetchData({ action: 'edit_recipe', ...Object.fromEntries(formData) });
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editRecipeModal')).hide();
                    loadRecipes(currentProductId);
                } else {
                    alert(data.message);
                }
            });

            // Sil
            window.deleteRecipe = async function(id) {
                if (!confirm('Bu malzeme reçeteden silinsin mi?')) return;
                const data = await fetchData({ action: 'delete_recipe', id });
                if (data.success) {
                    loadRecipes(currentProductId);
                } else {
                    alert(data.message);
                }
            };

            async function updateProductPrice(productId, newPrice) {
                const data = await fetchData({
                    action: 'edit',
                    product_id: productId,
                    unit_price: newPrice
                });

                if (data.success) {
                    showToast('Fiyat başarıyla güncellendi!', 'success');
                    loadRecipes(productId);
                } else {
                    showToast('Fiyat güncellenemedi!', 'danger');
                }
            }

            // Sayfa yüklendi
            window.onload = async () => {
                await loadProducts();
                await loadIngredients();
            };
        </script>
    </body>
</html>
<?php ob_end_flush(); ?>