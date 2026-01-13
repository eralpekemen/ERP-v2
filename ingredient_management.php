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
        header('Content-Type: application/json; charset=utf-8');
        ob_clean();  // ← BURAYI DEĞİŞTİR! (ob_clean değil, ob_end_clean!)
        
        $action = $_POST['action'] ?? '';
        $branch_id = get_current_branch(); // TANIMLANDI!

        // Malzemeleri Listele
        if ($action === 'ingredients') {
            $stmt = $db->prepare("SELECT id, name, unit, unit_cost, stock_quantity FROM ingredients WHERE branch_id = ? ORDER BY name ASC");
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $ingredients = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'ingredients' => $ingredients]);
            exit;
        }

        // Malzeme Ekle
        if ($action === 'add_ingredient') {
            $name           = trim($_POST['name']);
            $unit           = $_POST['unit'];
            $unit_cost      = floatval($_POST['unit_cost']);
            $stock_quantity = floatval($_POST['stock_quantity'] ?? 0);
            $category_id    = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;

            $stmt = $db->prepare("INSERT INTO ingredients (name, category_id, unit, unit_cost, stock_quantity, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisddi", $name, $category_id, $unit, $unit_cost, $stock_quantity, $branch_id);
            $success = $stmt->execute();

            echo json_encode(['success' => $success]);
            exit;
        }

        // Malzeme Düzenle
        if ($action === 'edit_ingredient') {
            $id             = (int)$_POST['id'];
            $name           = trim($_POST['name']);
            $unit           = $_POST['unit'];
            $unit_cost      = floatval($_POST['unit_cost']);
            $stock_quantity = floatval($_POST['stock_quantity']);

            $stmt = $db->prepare("UPDATE ingredients SET name = ?, unit = ?, unit_cost = ?, stock_quantity = ? WHERE id = ? AND branch_id = ?");
            $stmt->bind_param("ssddii", $name, $unit, $unit_cost, $stock_quantity, $id, $branch_id);
            $success = $stmt->execute();

            echo json_encode(['success' => $success]);
            exit;
        }

        // Malzeme Sil
        if ($action === 'delete_ingredient') {
            $id = (int)$_POST['id'];

            $stmt = $db->prepare("DELETE FROM ingredients WHERE id = ? AND branch_id = ?");
            $stmt->bind_param("ii", $id, $branch_id);
            $success = $stmt->execute();

            echo json_encode(['success' => $success]);
            exit;
        }

        // KATEGORİLERİ GETİR
        if ($action === 'get_ingredient_categories') {
            $stmt = $db->prepare("SELECT id, name FROM ingredient_categories WHERE branch_id = ? ORDER BY name");
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            echo json_encode(['success' => true, 'categories' => $result->fetch_all(MYSQLI_ASSOC)]);
            exit;
        }

        // YENİ KATEGORİ EKLE
        if ($action === 'add_ingredient_category') {
            $name = trim($_POST['name']);
            $stmt = $db->prepare("INSERT IGNORE INTO ingredient_categories (name, branch_id) VALUES (?, ?)");
            $stmt->bind_param("si", $name, $branch_id);
            $success = $stmt->execute();
            echo json_encode(['success' => $success, 'id' => $db->insert_id]);
            exit;
        }
        exit;
    }
    
    $personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';
$branch_id = get_current_branch();
$csrf_token = generate_csrf_token();
        $csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <title>SABL | Malzeme Yönetimi</title>
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

                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;"></div>

                <!-- İÇERİK -->
                <div id="content" class="app-content">
                    <div class="row">
                        <div class="col-xl-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div class="d-flex justify-content-between align-items-center w-100">
                                        <h5 class="mb-0">Malzemeler</h5>
                                        <div>
                                            <input type="text" id="searchIngredients" class="form-control form-control-sm d-inline-block w-250px me-2" placeholder="Malzeme ara..." onkeyup="renderIngredients()">
                                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addIngredientModal">
                                                <i class="fa fa-plus"></i> Yeni Malzeme
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Malzeme</th>
                                                    <th>Birim</th>
                                                    <th>Birim Maliyet</th>
                                                    <th>Stok</th>
                                                    <th>İşlem</th>
                                                </tr>
                                            </thead>
                                            <tbody id="ingredientsBody">
                                                <tr><td colspan="5" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODALLAR -->
                <!-- EKLE -->
                <div class="modal fade" id="addIngredientModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Yeni Malzeme Ekle</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form id="addIngredientForm">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Malzeme Adı</label>
                                        <input type="text" name="name" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Malzeme Kategorisi</label>
                                        <div class="input-group">
                                            <select id="ingredientCategorySelect" class="form-select">
                                                <option value="">Kategori Seç (İsteğe Bağlı)</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" onclick="addNewIngredientCategory()">
                                                <i class="fa fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birim</label>
                                        <select name="unit" class="form-select">
                                            <option value="kg">Kilogram (kg)</option>
                                            <option value="g">Gram (g)</option>
                                            <option value="lt">Litre (lt)</option>
                                            <option value="ml">Mililitre (ml)</option>
                                            <option value="adet">Adet</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birim Maliyet (₺)</label>
                                        <input type="number" name="unit_cost" class="form-control" step="0.001" min="0.000" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Stok Miktarı</label>
                                        <input type="number" name="stock_quantity" class="form-control" step="0.01" value="0">
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

                <!-- DÜZENLE -->
                <div class="modal fade" id="editIngredientModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Malzeme Düzenle</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form id="editIngredientForm">
                                <input type="hidden" name="id">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Malzeme Adı</label>
                                        <input type="text" name="name" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birim</label>
                                        <select name="unit" class="form-select">
                                            <option value="kg">Kilogram (kg)</option>
                                            <option value="g">Gram (g)</option>
                                            <option value="lt">Litre (lt)</option>
                                            <option value="ml">Mililitre (ml)</option>
                                            <option value="adet">Adet</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birim Maliyet (₺)</label>
                                        <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Stok Miktarı</label>
                                        <input type="number" name="stock_quantity" class="form-control" step="0.01">
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

            <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
            <script src="assets/js/vendor.min.js?v=<?= time() ?>"></script>
            <script src="assets/js/app.min.js?v=<?= time() ?>"></script>

        <script>
            const CSRF = '<?php echo $csrf_token; ?>';
            let allIngredients = [];

            function showToast(message, type = 'success') {
                const toast = $(`
                    <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">${message}</div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                `);
                $('.toast-container').append(toast);
                new bootstrap.Toast(toast, { delay: 3000 }).show();
            }

            async function fetchData(action, postData = {}) {
                console.log('İstek gönderiliyor:', action);
                
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', action); // DÜZELTİLDİ!
                for (const key in postData) {
                    formData.append(key, postData[key]);
                }

                try {
                    const response = await fetch('ingredient_management.php', {
                        method: 'POST',
                        body: formData
                    });

                    const text = await response.text();
                    console.log('Ham veri:', text.substring(0, 200));

                    if (!response.ok) throw new Error('HTTP ' + response.status);

                    const data = JSON.parse(text);
                    console.log('Parse edildi:', data);
                    return data;
                } catch (err) {
                    console.error('HATA:', err);
                    showToast('Sunucu hatası!', 'danger');
                    return null;
                }
            }

            async function loadIngredients() {
                console.log('Malzemeler yükleniyor...');
                const data = await fetchData('ingredients'); // DÜZELTİLDİ!
                
                if (data && data.success) {
                    allIngredients = data.ingredients;
                    renderIngredients();
                } else {
                    document.getElementById('ingredientsBody').innerHTML = 
                        '<tr><td colspan="5" class="text-danger text-center">Malzemeler yüklenemedi!</td></tr>';
                }
            }

            function renderIngredients() {
                const search = document.getElementById('searchIngredients')?.value.toLowerCase() || '';
                const filtered = allIngredients.filter(i => i.name.toLowerCase().includes(search));
                
                let html = '';
                if (filtered.length === 0) {
                    html = '<tr><td colspan="5" class="text-center text-muted">Malzeme bulunamadı</td></tr>';
                } else {
                    filtered.forEach(i => {
                        const stok = parseFloat(i.stock_quantity);
                        const stokBadge = stok > 10 ? 'bg-success' : stok > 0 ? 'bg-warning' : 'bg-danger';
                        const stokText = stok > 10 ? stok.toFixed(2) : stok > 0 ? `${stok.toFixed(2)} (Azaldı!)` : 'BİTTİ!';
                        html += `<tr data-id="${i.id}">
                            <td><strong>${i.name.toUpperCase()}</strong></td>
                            <td><span class="badge bg-info">${i.unit}</span></td>
                            <td><strong class="text-success">${parseFloat(i.unit_cost).toFixed(2)} ₺</strong></td>
                            <td><span class="badge ${stokBadge} fs-6">${stokText}</span></td>
                            <td>
                                <button class="btn btn-warning btn-sm" onclick="editIngredient(${i.id})">
                                    <i class="fa fa-pen"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteIngredient(${i.id}, '${i.name}')">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>`;
                    });
                }
                document.getElementById('ingredientsBody').innerHTML = html;
            }

            // EKLE
            window.editIngredient = function(id) {
                const ing = allIngredients.find(x => x.id == id);
                document.querySelector('#editIngredientForm [name="id"]').value = id;
                document.querySelector('#editIngredientForm [name="name"]').value = ing.name;
                document.querySelector('#editIngredientForm [name="unit"]').value = ing.unit;
                document.querySelector('#editIngredientForm [name="unit_cost"]').value = ing.unit_cost;
                document.querySelector('#editIngredientForm [name="stock_quantity"]').value = ing.stock_quantity;
                new bootstrap.Modal(document.getElementById('editIngredientModal')).show();
            };

            document.getElementById('editIngredientForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const data = await fetchData({ action: 'edit_ingredient', ...Object.fromEntries(formData) });
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editIngredientModal')).hide();
                    loadIngredients();
                } else {
                    showToast(data.message || 'Düzenleme hatası!', 'danger');
                }
            });

            $('#addIngredientForm').on('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const postObj = Object.fromEntries(formData.entries());
                
                const res = await fetchData('add_ingredient', postObj);
                
                if (res && res.success) {
                    showToast('Malzeme eklendi!', 'success');
                    this.reset();
                    $('#addIngredientModal').modal('hide');
                    loadIngredients();
                }
            });

            // SİL
            window.deleteIngredient = async function(id, name) {
                if (!confirm(`${name.toUpperCase()} malzeme kalıcı olarak silinecek!\n\nDevam etmek istediğine emin misin?`)) return;
                
                const data = await fetchData({ action: 'delete_ingredient', id });
                if (data.success) {
                    showToast('Malzeme silindi!', 'success');
                    loadIngredients();
                } else {
                    showToast(data.message || 'Silme hatası!', 'danger');
                }
            };

            function loadIngredientCategories() {
                $.post('ingredient_management.php', {
                    ajax: 1,
                    action: 'get_ingredient_categories'
                }, function(res) {
                    const select = $('#ingredientCategorySelect');
                    select.empty().append('<option value="">Kategori Seç (İsteğe Bağlı)</option>');
                    if (res.success) {
                        res.categories.forEach(cat => {
                            select.append(`<option value="${cat.id}">${cat.name}</option>`);
                        });
                    }
                }, 'json');
            }

            function addNewIngredientCategory() {
                const name = prompt("Yeni kategori adı:");
                if (!name) return;
                
                $.post('ingredient_management.php', {
                    ajax: 1,
                    action: 'add_ingredient_category',
                    name: name
                }, function(res) {
                    if (res.success && res.id) {
                        $('#ingredientCategorySelect').append(`<option value="${res.id}" selected>${name}</option>`);
                        showToast('Kategori eklendi!', 'success');
                    }
                }, 'json');
            }
            $(document).ready(function() {
                loadIngredientCategories();
                loadIngredients();
            });
            window.onload = loadIngredients;
        </script>
    </body>
</html>
<?php ob_end_flush(); ?>