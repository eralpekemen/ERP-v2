    <?php
       error_reporting(E_ALL);
        ini_set('display_errors', E_ALL); // Ekrana yazdırma, sadece log'a
        ini_set('log_errors', 1);
        ob_start();
        session_start();
        require_once 'config.php';
        require_once 'functions/common.php';

        if ($_SESSION['personnel_type'] != 'admin') {
            header("Location: login.php");
            exit;
        }
        $personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';
        $branch_id = get_current_branch();
        $csrf_token = generate_csrf_token();
        if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
            header('Content-Type: application/json; charset=utf-8');
            ob_clean();

            $branch_id = get_current_branch();
            $action = $_POST['action'] ?? '';

            // ÜRÜNLERİ LİSTELE
            if ($action === 'list') {
                $sql = "SELECT 
                            p.id, 
                            p.name,
                            p.status,
                            COALESCE(p.barcode, '') as barcode,
                            p.unit_price as price,
                            COALESCE(p.stock_quantity, 0) as stock,
                            COALESCE(pc.name, 'Kategorisiz') as category
                        FROM products p 
                        LEFT JOIN product_categories pc ON p.category_id = pc.id 
                        WHERE p.branch_id = ?
                        ORDER BY p.name";

                $stmt = $db->prepare($sql);
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $products = $result->fetch_all(MYSQLI_ASSOC);

                echo json_encode(['success' => true, 'products' => $products]);
                exit;
            }

            // KATEGORİLERİ GETİR
            if ($action === 'categories') {
                $stmt = $db->prepare("SELECT id, name FROM product_categories WHERE branch_id = ? ORDER BY name");
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $categories = $result->fetch_all(MYSQLI_ASSOC);

                echo json_encode(['success' => true, 'categories' => $categories]);
                exit;
            }

            if ($action === 'send_order') {
                $items = json_decode($_POST['items'] ?? '[]', true);
                if (empty($items)) {
                    echo json_encode(['success' => false, 'message' => 'Sepet boş']);
                    exit;
                }

                // Burada üst şirkete mail, webhook, API vs. gönderebilirsin
                // Şimdilik log + basit mail örneği:
                $message = "YENİ SİPARİŞ - Şube: " . $branch_id . "\n\n";
                foreach ($items as $item) {
                    $message .= "- {$item['name']} (Kategori: {$item['category']}) × {$item['quantity']}\n";
                }

                // Gerçek sistemde buraya API çağrısı veya mail gelecek
                error_log("SİPARİŞ ALINDI:\n" . $message);

                // Örnek: Mail gönderme (kapatılabilir)
                // mail("satis@ustfirma.com", "Yeni Sipariş - Şube $branch_id", $message);

                echo json_encode(['success' => true, 'message' => 'Sipariş gönderildi!']);
                exit;
            }

            if ($action === 'add_product') {
                $name = trim($_POST['name'] ?? '');
                $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
                $unit_price = floatval($_POST['unit_price'] ?? 0);
                $stock_quantity = floatval($_POST['stock_quantity'] ?? 0);
                $barcode = trim($_POST['barcode'] ?? '');

                // Barkod boşsa otomatik üret
                if (empty($barcode)) {
                    $barcode = generateEAN13();
                }

                // 13 hane kontrol
                if (strlen($barcode) !== 13 || !ctype_digit($barcode)) {
                    echo json_encode(['success' => false, 'msg' => 'Barkod 13 haneli olmalı!']);
                    exit;
                }

                $stmt = $db->prepare("INSERT INTO products (name, category_id, branch_id, unit_price, stock_quantity, barcode, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("siidds", $name, $category_id, $branch_id, $unit_price, $stock_quantity, $barcode);

                if ($stmt->execute()) {
                    echo json_encode([
                        'success' => true,
                        'msg' => 'Ürün başarıyla eklendi!',
                        'barcode' => $barcode
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'msg' => 'Veritabanı hatası: ' . $stmt->error
                    ]);
                }
                exit;
            }

            if ($action === 'delete_product') {
                $id = (int)$_POST['id'];

                $stmt = $db->prepare("UPDATE products SET status = 'inactive' WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $id, $branch_id);
                $success = $stmt->execute();

                echo json_encode([
                    'success' => $success,
                    'message' => $success ? 'Ürün silindi' : 'Silme hatası'
                ]);
                exit;
            }

            if ($action === 'add_extra') {
                $product_id = (int)$_POST['product_id'];
                $ingredient_id = (int)$_POST['ingredient_id'];
                $price = floatval($_POST['price'] ?? 0); // 0 fiyat kabul edilsin!

                // Malzeme adını ingredients tablosundan çek
                $stmt = $db->prepare("SELECT name FROM ingredients WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $ingredient_id, $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if (!$row = $result->fetch_assoc()) {
                    echo json_encode(['success' => false, 'message' => 'Malzeme bulunamadı veya şubenize ait değil']);
                    exit;
                }
                $name = $row['name'];

                // Aynı ekstra zaten var mı kontrol et (tekrar eklenmesin)
                $check = $db->prepare("SELECT id FROM product_extras WHERE product_id = ? AND name = ? AND branch_id = ?");
                $check->bind_param("isi", $product_id, $name, $branch_id);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu ekstra zaten ekli!']);
                    exit;
                }

                // Ekstra ekle
                $stmt = $db->prepare("INSERT INTO product_extras (product_id, name, price, branch_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isdi", $product_id, $name, $price, $branch_id);
                $success = $stmt->execute();

                echo json_encode([
                    'success' => $success,
                    'message' => $success ? 'Ekstra malzeme eklendi!' : 'Ekleme hatası'
                ]);
                exit;
            }

            // MALZEMELERİ GETİR (ekstra eklerken lazım)
            if ($action === 'get_ingredients') {
                $check = $db->query("SHOW COLUMNS FROM ingredients LIKE 'status'");
                $hasStatus = $check->num_rows > 0;

                $sql = "SELECT id, name, stock_quantity FROM ingredients WHERE branch_id = ?";
                if ($hasStatus) {
                    $sql .= " AND status = 'active'";
                }
                $sql .= " ORDER BY name";

                $stmt = $db->prepare($sql);
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $ingredients = $result->fetch_all(MYSQLI_ASSOC);

                echo json_encode(['success' => true, 'ingredients' => $ingredients]);
                exit;
            }

            // Zorunlu seçimler
            if ($action === 'get_features') {
                $id = (int)$_POST['product_id'];
                $stmt = $db->prepare("SELECT name, additional_price, is_mandatory, stock_quantity FROM product_features WHERE product_id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $id, $branch_id);
                $stmt->execute();
                $res = $stmt->get_result();
                echo json_encode(['success' => true, 'features' => $res->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // GRUP EKLE
            if ($action === 'add_feature_group') {
                $product_id = (int)$_POST['product_id'];
                $name = trim($_POST['group_name']);
                $mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

                $stmt = $db->prepare("INSERT INTO product_feature_groups (product_id, name, is_mandatory, branch_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isii", $product_id, $name, $mandatory, $branch_id);
                $success = $stmt->execute();

                echo json_encode(['success' => $success, 'group_id' => $db->insert_id]);
                exit;
            }

            // SEÇENEK EKLE (GRUBA BAĞLI)
            if ($action === 'add_feature_option') {
                $product_id = (int)$_POST['product_id'];
                $group_id = (int)$_POST['group_id'];
                $name = trim($_POST['name']);
                $price = floatval($_POST['price'] ?? 0);
                $stock = (int)$_POST['stock_quantity'] ?? 0;

                $stmt = $db->prepare("INSERT INTO product_features (product_id, group_id, name, additional_price, stock_quantity, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisdii", $product_id, $group_id, $name, $price, $stock, $branch_id);
                $success = $stmt->execute();

                echo json_encode(['success' => $success]);
                exit;
            }

            // GRUPLARI + SEÇENEKLERİ GETİR
            if ($action === 'get_feature_groups_with_options') {
                $product_id = (int)$_POST['product_id'];
                
                // Gruplar
                $stmt = $db->prepare("SELECT id, name, is_mandatory FROM product_feature_groups WHERE product_id = ? AND branch_id = ? ORDER BY name");
                $stmt->bind_param("ii", $product_id, $branch_id);
                $stmt->execute();
                $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // Her grup için seçenekler
                foreach ($groups as &$group) {
                    $stmt = $db->prepare("SELECT id, name, additional_price, stock_quantity FROM product_features WHERE product_id = ? AND group_id = ? AND branch_id = ?");
                    $stmt->bind_param("iii", $product_id, $group['id'], $branch_id);
                    $stmt->execute();
                    $group['options'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                }

                echo json_encode(['success' => true, 'groups' => $groups]);
                exit;
            }

            if ($action === 'get_recipe') {
                $product_id = (int)$_POST['product_id'];
                
                $sql = "SELECT 
                            ri.quantity,
                            ri.ingredient_id,
                            p.name AS ingredient_name,
                            p.unit_price AS ingredient_cost,
                            'adet' AS unit
                        FROM recipe_items ri
                        LEFT JOIN products p ON ri.ingredient_id = p.id
                        WHERE ri.product_id = ? AND ri.branch_id = ?";
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param("ii", $product_id, $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                echo json_encode([
                    'success' => true,
                    'recipe' => $result->fetch_all(MYSQLI_ASSOC)
                ]);
                exit;
            }

            // Ekstralar
            if ($action === 'get_extras') {
                $product_id = (int)$_POST['product_id'];
                $stmt = $db->prepare("SELECT id, name, price, quantity, unit, is_active FROM product_extras WHERE product_id = ? AND branch_id = ? ORDER BY name");
                $stmt->bind_param("ii", $product_id, $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                echo json_encode(['success' => true, 'extras' => $result->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // Boyutlar
            if ($action === 'get_sizes') {
                $id = (int)$_POST['product_id'];
                $stmt = $db->prepare("SELECT name, additional_price, stock_quantity FROM product_sizes WHERE product_id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $id, $branch_id);
                $stmt->execute();
                $res = $stmt->get_result();
                echo json_encode(['success' => true, 'sizes' => $res->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // Kampanyalar (şu an ürün bazlı değil, genel)
            if ($action === 'get_promotions') {
                $stmt = $db->prepare("SELECT code, discount_percent FROM promotions WHERE branch_id = ? AND NOW() BETWEEN valid_from AND valid_until");
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $res = $stmt->get_result();
                echo json_encode(['success' => true, 'promotions' => $res->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // Zorunlu seçim kaydet
            if ($action === 'save_feature') {
                $product_id = (int)$_POST['product_id'];
                $name = trim($_POST['name']);
                $price = (float)$_POST['additional_price'];
                $mandatory = (int)$_POST['is_mandatory'];

                $stmt = $db->prepare("INSERT INTO product_features (product_id, name, additional_price, is_mandatory, branch_id) VALUES (?, ?, ?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE name=VALUES(name), additional_price=VALUES(additional_price)");
                $stmt->bind_param("isdii", $product_id, $name, $price, $mandatory, $branch_id);
                $success = $stmt->execute();

                echo json_encode(['success' => $success]);
                exit;
            }

            // Ekstra kaydet
            if ($action === 'save_extra') {
                $product_id = (int)$_POST['product_id'];
                $extra_id = !empty($_POST['extra_id']) ? (int)$_POST['extra_id'] : 0;
                $ingredient_id = (int)$_POST['ingredient_id'];
                $price = floatval($_POST['price'] ?? 0);
                $quantity = floatval($_POST['quantity'] ?? 1);
                $unit = trim($_POST['unit'] ?? 'adet');
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                // Malzeme adı
                $stmt = $db->prepare("SELECT name FROM ingredients WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $ingredient_id, $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if (!$row = $result->fetch_assoc()) {
                    echo json_encode(['success' => false, 'message' => 'Malzeme bulunamadı']);
                    exit;
                }
                $name = $row['name'];

                if ($extra_id > 0) {
                    // Güncelle
                    $stmt = $db->prepare("UPDATE product_extras SET name = ?, price = ?, quantity = ?, unit = ?, is_active = ? WHERE id = ? AND product_id = ? AND branch_id = ?");
                    $stmt->bind_param("sdsdisii", $name, $price, $quantity, $unit, $is_active, $extra_id, $product_id, $branch_id);
                } else {
                    // Yeni ekle
                    $stmt = $db->prepare("INSERT INTO product_extras (product_id, name, price, quantity, unit, is_active, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isdsdii", $product_id, $name, $price, $quantity, $unit, $is_active, $branch_id);
                }
                $success = $stmt->execute();

                echo json_encode(['success' => $success]);
                exit;
            }

            // BOYUT KAYDET
            if ($action === 'save_size') {
                $product_id = (int)$_POST['product_id'];
                $name = trim($_POST['name']);
                $price = (float)$_POST['additional_price'];
                $stock = (int)$_POST['stock_quantity'];

                $stmt = $db->prepare("INSERT INTO product_sizes (product_id, name, additional_price, stock_quantity, branch_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("isdii", $product_id, $name, $price, $stock, $branch_id);
                $success = $stmt->execute();

                echo json_encode(['success' => $success]);
                exit;
            }

            if ($action === 'get_all_features_with_assignment') {
                $product_id = (int)$_POST['product_id'];
                
                $sql = "SELECT pf.id, pf.name, pf.additional_price, pf.stock_quantity,
                               CASE WHEN pfa.product_id IS NOT NULL THEN 1 ELSE 0 END as assigned
                        FROM product_features pf
                        LEFT JOIN product_feature_assignments pfa ON pf.id = pfa.feature_id AND pfa.product_id = ?
                        WHERE pf.branch_id = ?
                        ORDER BY pf.name";
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param("ii", $product_id, $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                echo json_encode(['success' => true, 'features' => $result->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // Atama yap / kaldır (eğer tablo yoksa oluştur)
            if ($action === 'assign_feature' || $action === 'unassign_feature') {
                $feature_id = (int)$_POST['feature_id'];
                $product_id = (int)$_POST['product_id'];
                
                if ($action === 'assign_feature') {
                    $sql = "INSERT IGNORE INTO product_feature_assignments (product_id, feature_id) VALUES (?, ?)";
                } else {
                    $sql = "DELETE FROM product_feature_assignments WHERE product_id = ? AND feature_id = ?";
                }
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param("ii", $product_id, $feature_id);
                $stmt->execute();
                
                echo json_encode(['success' => true]);
                exit;
            }

            if ($action === 'toggle_status') {
                $product_id = (int)$_POST['product_id'];
                $is_active  = $_POST['status'] == 'true' || $_POST['status'] === true ? 1 : 0;
                $new_status = $is_active ? 'active' : 'inactive';

                $stmt = $db->prepare("UPDATE products SET status = ? WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("sii", $new_status, $product_id, $branch_id);
                
                if ($stmt->execute()) {
                    echo json_encode([
                        'success' => true,
                        'new_status' => $new_status
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => $stmt->error
                    ]);
                }
                exit;
            }

            if ($action === 'get_product_detail') {
                $id = (int)$_POST['product_id'];

                // Ana ürün
                $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $id, $branch_id);
                $stmt->execute();
                $product = $stmt->get_result()->fetch_assoc();

                if (!$product) {
                    echo json_encode(['success' => false]);
                    exit;
                }

                // Ekstralar
                $stmt = $db->prepare("SELECT name, price FROM product_extras WHERE product_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $extras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // Boyutlar
                $stmt = $db->prepare("SELECT name, additional_price, stock_quantity FROM product_sizes WHERE product_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $sizes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // Reçete
                $stmt = $db->prepare("SELECT ri.quantity, p.name AS ingredient_name, p.unit_price AS cost 
                                      FROM recipe_items ri 
                                      LEFT JOIN products p ON ri.ingredient_id = p.id 
                                      WHERE ri.product_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $recipe = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // Zorunlu Seçimler (product_features)
                $stmt = $db->prepare("SELECT name, additional_price, is_mandatory FROM product_features WHERE product_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $features = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // İndirim Grupları (şimdilik genel kampanyalar, istersen ürün bazlı yaparız)
                $stmt = $db->prepare("SELECT code, discount_percent FROM promotions WHERE branch_id = ? AND NOW() BETWEEN valid_from AND valid_until");
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $promotions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                echo json_encode([
                    'success' => true,
                    'product' => $product,
                    'extras' => $extras,
                    'sizes' => $sizes,
                    'recipe' => $recipe,
                    'features' => $features,
                    'promotions' => $promotions
                ]);
                exit;
            }

            if ($action === 'upload_product_image' && isset($_FILES['image'])) {
                $product_id = (int)$_POST['product_id'];
                $file = $_FILES['image'];
                
                if ($file['error'] !== 0) {
                    echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi']);
                    exit;
                }
                
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                if (!in_array($ext, $allowed)) {
                    echo json_encode(['success' => false, 'message' => 'Sadece resim dosyası!']);
                    exit;
                }
                
                // Otomatik thumbnail + küçültme
                $newName = $product_id . '_' . time() . '.' . $ext;
                $uploadPath = 'uploads/products/' . $newName;
                
                // Klasör yoksa oluştur
                if (!is_dir('uploads/products')) mkdir('uploads/products', 0777, true);
                
                // Resmi küçült (max 1200px genişlik)
                $image = imagecreatefromstring(file_get_contents($file['tmp_name']));
                $width = imagesx($image);
                $height = imagesy($image);
                $newWidth = $width > 1200 ? 1200 : $width;
                $newHeight = $height * ($newWidth / $width);
                
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                
                switch($ext) {
                    case 'jpg': case 'jpeg': imagejpeg($resized, $uploadPath, 85); break;
                    case 'png': imagepng($resized, $uploadPath, 8); break;
                    case 'gif': imagegif($resized, $uploadPath); break;
                    case 'webp': imagewebp($resized, $uploadPath, 85); break;
                }
                
                // Veritabanına kaydet
                $stmt = $db->prepare("UPDATE products SET image_url = ? WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("sii", $newName, $product_id, $branch_id);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'image_url' => $uploadPath
                ]);
                exit;
            }

            if($action === 'save_barcode'){
                $product_id = (int)$_POST['product_id'];
                $barcode = trim($_POST['barcode']);

                if (strlen($barcode) !== 13 || !ctype_digit($barcode)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz barkod']);
                    exit;
                }

                // Aynı barkod başka üründe var mı kontrol et (isteğe bağlı)
                $check = $db->prepare("SELECT id FROM products WHERE barcode = ? AND id != ?");
                $check->execute([$barcode, $product_id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Bu barkod başka bir ürüne ait!']);
                    exit;
                }

                $stmt = $db->prepare("UPDATE products SET barcode = ? WHERE id = ?");
                $result = $stmt->execute([$barcode, $product_id]);

                echo json_encode(['success' => $result]);
                exit;
            }

            if($action === 'get_product_barcode'){
                $product_id = (int)$_POST['product_id'];
                $stmt = $db->prepare("SELECT barcode FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $barcode = trim($row['barcode'] ?? '');
                $isValid = ($barcode && $barcode !== '0' && $barcode !== '2147483647' && strlen($barcode) >= 8);
                
                echo json_encode([
                    'success' => true,
                    'barcode' => $isValid ? $barcode : null
                ]);
                exit;
            }

            // ÜRÜNE BAĞLI KAMPANYALARI GETİR
            if ($action === 'get_product_promotions') {
                $product_id = (int)$_POST['product_id'];
                $stmt = $db->prepare("SELECT p.* FROM promotions p 
                                    INNER JOIN product_promotions pp ON p.id = pp.promotion_id 
                                    WHERE pp.product_id = ? AND pp.branch_id = ?");
                $stmt->bind_param("ii", $product_id, $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                echo json_encode(['success' => true, 'promotions' => $result->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // TÜM AKTİF KAMPANYALARI GETİR (ekleme için)
            if ($action === 'get_available_promotions') {
                $stmt = $db->prepare("SELECT id, code, discount_percent FROM promotions 
                                    WHERE branch_id = ? AND NOW() BETWEEN valid_from AND valid_until");
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                echo json_encode(['success' => true, 'promotions' => $result->fetch_all(MYSQLI_ASSOC)]);
                exit;
            }

            // ÜRÜNE KAMPANYA EKLE / KALDIR
            if ($action === 'toggle_product_promotion') {
                $product_id = (int)$_POST['product_id'];
                $promotion_id = (int)$_POST['promotion_id'];
                $add = $_POST['add'] === 'true';

                if ($add) {
                    $stmt = $db->prepare("INSERT IGNORE INTO product_promotions (product_id, promotion_id, branch_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("iii", $product_id, $promotion_id, $branch_id);
                } else {
                    $stmt = $db->prepare("DELETE FROM product_promotions WHERE product_id = ? AND promotion_id = ? AND branch_id = ?");
                    $stmt->bind_param("iii", $product_id, $promotion_id, $branch_id);
                }
                $success = $stmt->execute();
                echo json_encode(['success' => $success]);
                exit;
            }

            if ($action === 'get_all_items') {
                $items = [];

                // Ürünler
                $stmt = $db->prepare("SELECT 'product' as type, id, name, stock_quantity as stock, COALESCE(unit_price, 0) as price, COALESCE(unit, 'ad') as unit FROM products WHERE branch_id = ? AND status = 'active'");
                $stmt->bind_param("i", $branch_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $row['display_name'] = $row['name'] . ' (Ürün)';
                    $items[] = $row;
                }

                // Malzemeler
                $stmt = $db->prepare("SELECT 'ingredient' as type, id, name, stock_quantity as stock, COALESCE(unit_price, 0) as price, COALESCE(unit, '') as unit FROM ingredients WHERE branch_id = ?");
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

            if ($action === 'save_recipe') {
                $product_id = (int)$_POST['product_id'];
                $items = json_decode($_POST['items'] ?? '[]', true);

                // Önce eski reçeteyi sil
                $stmt = $db->prepare("DELETE FROM recipe_items WHERE product_id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $product_id, $branch_id);
                $stmt->execute();

                // Yeni reçeteyi ekle
                if (!empty($items)) {
                    $stmt = $db->prepare("INSERT INTO recipe_items (product_id, ingredient_id, quantity, branch_id) VALUES (?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $ing_id = (int)$item['ingredient_id'];
                        $qty = (float)$item['quantity'];
                        $stmt->bind_param("iidi", $product_id, $ing_id, $qty, $branch_id);
                        $stmt->execute();
                    }
                }

                echo json_encode(['success' => true]);
                exit;
            }

            if ($action === 'add_feature_group') {
                $product_id = (int)$_POST['product_id'];
                $group_name = trim($_POST['group_name']);
                $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

                if (empty($group_name)) {
                    echo json_encode(['success' => false, 'message' => 'Grup adı boş olamaz']);
                    exit;
                }

                $stmt = $db->prepare("INSERT INTO product_feature_groups (product_id, name, is_mandatory, branch_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isii", $product_id, $group_name, $is_mandatory, $branch_id);
                $success = $stmt->execute();

                echo json_encode([
                    'success' => $success,
                    'message' => $success ? 'Zorunlu seçim grubu eklendi!' : 'Ekleme hatası'
                ]);
                exit;
            }

            if ($action === 'delete_extra') {
                $extra_id = (int)$_POST['extra_id'];
                $stmt = $db->prepare("DELETE FROM product_extras WHERE id = ? AND branch_id = ?");
                $stmt->bind_param("ii", $extra_id, $branch_id);
                $success = $stmt->execute();
                echo json_encode(['success' => $success]);
                exit;
            }

            echo json_encode(['success' => false, 'message' => 'Geçersiz action']);
            exit;
        }
        if (!isset($_SESSION['personnel_id'])) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        function generateEAN13() {
            $prefix = '200';
            $random = str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
            $base12 = $prefix . $random;

            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $digit = (int)$base12[$i];
                $sum += ($i % 2 === 0) ? $digit : $digit * 3;
            }
            $check = (10 - ($sum % 10)) % 10;

            return $base12 . $check;
        }
    ?>
    <!DOCTYPE html>
    <html lang="tr">
        <head>
            <meta charset="utf-8">
            <title>SABL | Ürün Yönetimi</title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link href="assets/css/vendor.min.css" rel="stylesheet">
            <link href="assets/css/app.min.css" rel="stylesheet">
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
            <style>
                @media (max-width: 768px) {
                    #viewProductModal .modal-dialog {
                        margin: 0;
                        max-width: 100vw;
                        height: 100vh;
                    }
                    #viewProductModal .modal-content {
                        height: 100%;
                        border-radius: 0;
                    }
                    .dark-mode {
                        background-color: #121212 !important;
                        color: #e0e0e0 !important;
                    }
                    .dark-mode .card, .dark-mode .modal-content, .dark-mode .table {
                        background-color: #1e1e1e !important;
                        color: #e0e0e0 !important;
                        border-color: #333 !important;
                    }
                    .dark-mode .form-control, .dark-mode .form-select {
                        background-color: #2d2d2d;
                        border-color: #444;
                        color: #fff;
                    }
                    .dark-mode .btn-outline-light { border-color: #555; }
                    #dropZone:hover {
                        background-color: #e3f2fd !important;
                        border-color: #2196F3 !important;
                        transform: scale(1.05);
                    }
                    #imagePreview img {
                        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                        transition: all 0.3s;
                    }
                    #imagePreview img:hover {
                        transform: scale(1.05);
                    }
                }
            </style>
            <script>
                const csrfToken = "<?= $csrf_token ?>";
            </script>
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

                <!-- İÇERİK -->
                <div id="content" class="app-content">
                    <div class="row">
                        <div class="col-xl-12 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <h5 class="mb-0">Ürünler</h5>
                                        <div class="ms-auto">
                                            <button class="btn btn-info btn-sm" title="Yazdır">
                                                <i class="fa fa-print text-white"></i>
                                            </button>
                                            <button class="btn btn-danger btn-sm" title="PDF olarak dışarı aktar">
                                                <i class="fa fa-file-pdf"></i>
                                            </button>
                                            <button class="btn btn-success btn-sm" title="CSV olarak dışarı aktar">
                                                <i class="fa fa-file-csv"></i>
                                            </button>
                                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                                <i class="fa fa-plus"></i> Yeni Ürün
                                            </button>
                                            <input type="text" id="searchProducts" class="form-control form-control-sm w-200px d-inline-block ms-2" placeholder="Ürün ara...">
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0" id="productsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Ürün Adı</th>
                                                    <th>Barkod</th>
                                                    <th>Kategori</th>
                                                    <th>Stok</th>
                                                    <th>Fiyat</th>
                                                    <th>Durum</th>
                                                    <th>İşlem</th>
                                                </tr>
                                            </thead>
                                            <tbody id="productsBody"></tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <div id="paginationInfo" class="text-muted fs-13px">Yükleniyor...</div>
                                        <nav><ul class="pagination pagination-sm mb-0" id="pagination"></ul></nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODALLAR -->
                <!-- YENİ ÜRÜN MODAL -->
                <div class="modal fade" id="addProductModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Yeni Ürün Ekle</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form id="addProductForm">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Ürün Adı</label>
                                        <input type="text" name="name" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Kategori</label>
                                        <select name="category_id" class="form-select" id="addCategorySelect"></select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Stok Miktarı</label>
                                        <input type="number" name="stock_quantity" class="form-control" value="0" step="0.01">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birim Fiyat</label>
                                        <input type="number" name="unit_price" class="form-control" step="0.01" required>
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

                <!-- ÜRÜN DETAY MODALI - PROFESYONEL 2 KOLONLU VERSİYON -->
                <div class="modal fade" id="viewProductModal" tabindex="-1">
                    <div class="modal-dialog modal-fullscreen-lg-down modal-dialog-centered modal-dialog-scrollable" style="max-width: 95vw;">
                        <div class="modal-content h-100">
                            <div class="modal-header bg-primary text-white">
                                <h4 class="modal-title"><i class="fa fa-eye"></i> Ürün Detayı</h4>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-0">
                                <div class="row g-0 h-100">
                                    <!-- SOL TARAFTA - DAR, ÜRÜN BİLGİLERİ -->
                                    <div class="col-lg-4 bg-light border-end" style="min-height: 100vh;">
                                        <div class="p-4">
                                            <div class="text-center mt-4">
                                                <div id="imagePreview" class="mt-3">
                                                    <img id="currentProductImage" src="" class="img-fluid rounded  cursor: zoom-in;" style="max-height:300px;" alt="Mevcut görsel" onclick="this.requestFullscreen()">
                                                </div>
                                                <div id="dropZone" class="d-inline-block border border-2 border-dashed rounded-pill px-4 py-2 bg-light mt-2" style="cursor:pointer; transition:all 0.3s;">
                                                    <i class="fas fa-camera text-primary me-2"></i>
                                                    <small class="text-muted">Resim Ekle / Değiştir</small>
                                                    <input type="file" id="productImageInput" accept="image/*" style="display:none;">
                                                </div>
                                                <div class="mt-2">
                                                    <button id="saveImageBtn" class="btn btn-success btn-sm d-none">
                                                        <i class="fas fa-save"></i> Kaydet
                                                    </button>
                                                    <button id="cancelImageBtn" class="btn btn-secondary btn-sm ms-2 d-none" onclick="cancelImageUpload()">
                                                        <i class="fas fa-times"></i> İptal
                                                    </button>
                                                </div>
                                                <h3 id="viewProductName" class="fw-bold text-primary mt-3"></h3>
                                                <p class="text-muted" id="viewProductCategory"></p>
                                            </div>
                                            <div class="p-1">
                                                <button type="button" class="btn btn-outline-primary" onclick="printProduct()">
                                                    <i class="fa fa-print"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-success" onclick="exportCSV()">
                                                    <i class="fa fa-file-csv"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" onclick="exportPDF()">
                                                    <i class="fa fa-file-pdf"></i>
                                                </button>
                                            </div> 
                                            <div class="list-group list-group-flush">
                                                <div id="saleStatusRow"></div>
                                                <div class="list-group-item d-flex justify-content-between">
                                                    <span><strong>Satış Fiyatı</strong></span>
                                                    <span id="viewProductPrice" class="fs-4 text-success fw-bold"></span>
                                                </div>
                                                <div class="list-group-item d-flex justify-content-between">
                                                    <span><strong>Stok</strong></span>
                                                    <span id="viewProductStockBadge" class="badge bg-success fs-6"></span>
                                                </div>
                                                <div class="list-group-item d-flex justify-content-between">
                                                    <span><strong>Maliyet</strong></span>
                                                    <span id="viewProductCost" class="text-danger"></span>
                                                </div>
                                                <div class="list-group-item d-flex justify-content-between">
                                                    <span><strong>Kar Marjı</strong></span>
                                                    <div id="profitInfo"></div>
                                                </div>
                                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                                    <strong>Barkod:</strong>
                                                    <div>
                                                        <span id="productBarcodeDisplay">Yükleniyor...</span>
                                                        <span id="barcodeActions"></span>
                                                    </div>
                                                </div>
                                                <div class="list-group-item">
                                                    <strong>Açıklama:</strong><br>
                                                    <small id="viewProductDesc" class="text-muted">-</small>
                                                </div>
                                            </div>

                                            <div class="mt-4 text-center">
                                                <button class="btn btn-warning btn-lg w-100 mb-2" onclick="editProduct(window.currentViewId)">
                                                    <i class="fa fa-pen"></i> Düzenle
                                                </button>   
                                            </div>
                                        </div>
                                    </div>

                                    <!-- SAĞ TARAFTA - GENİŞ, ÖZELLİKLER -->
                                    <div class="col-lg-8">    
                                        <div class="p-4">
                                            <ul class="nav nav-tabs mb-4" id="productTabs" role="tablist">
                                                <li class="nav-item">
                                                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#featuresTab">Zorunlu Seçimler</button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#extras">Ekstra Malzemeler</button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sizes">Boyutlar</button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#discounts">İndirim Grupları</button>
                                                </li>
                                                <li class="nav-item">
                                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#recipe">Reçete</button>
                                                </li>
                                            </ul>

                                            <div class="tab-content">
                                                <!-- ZORUNLU SEÇİMLER -->
                                                <div class="tab-pane fa active" id="featuresTab" role="tabpanel">
                                                    <div class="d-flex justify-content-between mb-3">
                                                        <h5>Zorunlu Seçim Grupları</h5>
                                                        <button class="btn btn-warning btn-sm" onclick="addNewGroup()">
                                                            <i class="fa fa-plus"></i> Yeni Grup Ekle
                                                        </button>
                                                    </div>
                                                    <div id="featureGroupsContainer"></div>
                                                </div>

                                                <!-- EKSTRA MALZEMELER -->
                                                <div class="tab-pane fade" id="extras" role="tabpanel">
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h6>Ekstra Malzemeler</h6>
                                                        <button type="button" class="btn btn-sm btn-success" onclick="openAddExtraModal()">
                                                            <i class="fa fa-plus"></i> Ekle
                                                        </button>
                                                    </div>
                                                    <div id="extraFeatures">Yükleniyor...</div>
                                                </div>

                                                <!-- İNDİRİM GRUPLARI -->
                                                <div class="tab-pane fade" id="discounts" role="tabpanel">
                                                    <div class="p-4">
                                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                                            <h5>Bu Ürüne Atanan İndirim Grupları</h5>
                                                            <button class="btn btn-sm btn-success" onclick="openAddPromotionModal()">
                                                                <i class="fa fa-plus"></i> Kampanya Ekle
                                                            </button>
                                                        </div>
                                                        <div id="assignedPromotions">
                                                            <div class="text-center py-5 text-muted">
                                                                <i class="fa fa-tag fa-3x mb-3"></i>
                                                                <p>Bu ürüne henüz indirim grubu atanmamış.</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- BOYUTLAR SEKME (yeni ekliyoruz) -->
                                                <div class="tab-pane fade" id="sizes">
                                                    <div class="p-4">
                                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                                            <h5 class="mb-0">Boyut Seçenekleri</h5>
                                                            <button class="btn btn-success btn-sm" onclick="addSizeRow()">
                                                                <i class="fa fa-plus"></i> Yeni Boyut Ekle
                                                            </button>
                                                        </div>
                                                        <div id="sizeFeatures">
                                                            <p class="text-muted text-center">Boyut tanımlanmamış.</p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- REÇETE -->
                                                <div class="tab-pane fade" id="recipe">
                                                    <div class="p-4">
                                                        <div class="d-flex justify-content-between align-items-center mb-4">
                                                            <h5 class="mb-0"><i class="fa fa-utensils"></i> Ürün Reçetesi</h5>
                                                            <button class="btn btn-outline-primary btn-sm" onclick="editRecipe(window.currentViewId)">
                                                                <i class="fa fa-pen"></i> Düzenle
                                                            </button>
                                                        </div>
                                                        <div id="productRecipe">
                                                            <div class="text-center py-5 text-muted">
                                                                <i class="fa fa-utensils fa-3x mb-3"></i>
                                                                <p>Bu ürün için reçete tanımlanmamış.</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BARKOD EKLE MODAL -->
                <div class="modal fade" id="barcodeModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title"><i class="fas fa-barcode"></i> Barkod Ekle / Oluştur</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Barkod (13 hane)</label>
                                    <input type="text" class="form-control form-control-lg text-center" id="newBarcodeInput" maxlength="13" placeholder="Manuel girin veya otomatik oluşturun">
                                    <div class="form-text">Sadece rakam, 13 hane olmalı</div>
                                </div>

                                <div class="text-center">
                                    <button type="button" id="generateBarcodeBtn" class="btn btn-success btn-lg">
                                        <i class="fas fa-magic"></i> Otomatik Barkod Oluştur
                                    </button>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="button" id="saveBarcodeBtn" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Barkodu Kaydet
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DÜZENLE MODALI (ESKİSİNİ SİL, YERİNE BUNU KOY) -->
                <div class="modal fade" id="editProductModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title"><i class="fa fa-pen"></i> Ürün Düzenle</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form id="editProductForm">
                                <div class="modal-body">
                                    <input type="hidden" name="id" id="editProductId">
                                    <div class="mb-3">
                                        <label class="form-label">Ürün Adı</label>
                                        <input type="text" name="name" id="editProductName" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Kategori</label>
                                        <select name="category_id" id="editCategorySelect" class="form-select"></select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Stok Miktarı</label>
                                        <input type="number" name="stock_quantity" id="editProductStock" class="form-control" step="0.01">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birim Fiyat</label>
                                        <input type="number" name="unit_price" id="editProductPrice" class="form-control" step="0.01" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Vergi Oranı (%)</label>
                                        <input type="number" name="tax_rate" id="editProductTax" class="form-control" value="20" min="0" max="100" step="1">
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

                <!-- SİL MODAL -->
                <div class="modal fade" id="deleteProductModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Sil?</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p><span id="deleteProductName"></span> silinsin mi?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="button" class="btn btn-danger" id="confirmDelete">Sil</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KAMPANYA EKLE MODAL -->
                <div class="modal fade" id="addPromotionModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h6 class="modal-title">Kampanya / İndirim Grubu Ekle</h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <select id="promotionSelect" class="form-select"></select>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="button" class="btn btn-success" onclick="assignPromotion()">Ekle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MİKTAR MODALI -->
                <div class="modal fade" id="orderQuantityModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5>Miktarları Girin</h5>
                            </div>
                            <div class="modal-body">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr><th>Öğe</th><th>Miktar</th></tr>
                                    </thead>
                                    <tbody id="orderQuantityBody"></tbody>
                                </table>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="backToOrderSelect()">Geri</button>
                                <button type="button" class="btn btn-success" onclick="sendFinalOrder()">Siparişi Gönder</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- REÇETE DÜZENLEME MODALI -->
                <div class="modal fade" id="recipeEditModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5>Reçete Düzenle - <span id="recipeProductName"></span></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <button class="btn btn-primary mb-3" onclick="addRecipeRow()">
                                    <i class="fa fa-plus"></i> Malzeme Ekle
                                </button>
                                <div id="recipeItems"></div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-success" onclick="saveRecipe()">Tümünü Kaydet</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EKSTRA MALZEME EKLE MODAL -->
                <div class="modal fade" id="extraModal"  tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">Ekstra Malzeme</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form id="extraForm">
                                <input type="hidden" name="extra_id" value="">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label>Malzeme</label>
                                        <select name="ingredient_id" class="form-select" required></select>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label>Ek Fiyat (₺)</label>
                                            <input type="number" name="price" class="form-control" step="0.01" value="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label>Miktar</label>
                                            <input type="number" name="quantity" class="form-control" step="0.001" value="1">
                                        </div>
                                        <div class="col-md-4">
                                            <label>Birim</label>
                                            <select name="unit" class="form-select">
                                                <option value="adet">Adet</option>
                                                <option value="g">Gram</option>
                                                <option value="kg">Kg</option>
                                                <option value="ml">Ml</option>
                                                <option value="lt">Litre</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-check mt-3">
                                        <input type="checkbox" name="is_active" class="form-check-input" checked>
                                        <label class="form-check-label">Aktif (müşteriye göster)</label>
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

                <!-- ZORUNLU SEÇİM GRUBU EKLE MODAL -->
                <div class="modal fade" id="addFeatureGroupModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark">
                                <h6 class="modal-title">Zorunlu Seçim Grubu Ekle</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label>Grup Adı</label>
                                    <input type="text" id="featureGroupName" class="form-control" placeholder="Örn: Pişirme Şekli">
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="featureMandatory" class="form-check-input">
                                    <label class="form-check-label">Zorunlu seçim</label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                <button type="button" class="btn btn-warning" onclick="saveFeatureGroup()">Ekle</button>
                            </div>
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
                let currentPage = 1;
                const perPage = 10;
                let allProducts = [];
                let categories = [];

                // TOAST
                function showToast(message, type = 'danger') {
                    const toast = $(`
                        <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
                            <div class="d-flex">
                                <div class="toast-body">${message}</div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                            </div>
                        </div>
                    `);
                    $('.toast-container').remove();
                    $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3"></div>');
                    $('.toast-container').append(toast);
                    new bootstrap.Toast(toast).show();
                }

                function loadProducts() {
                    console.log("Ürünler yükleniyor...");
                    $('#productsBody').html('<tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div><br>Yükleniyor...</td></tr>');

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'list'
                    }, function(data) {
                        console.log("Sunucudan gelen veri:", data);
                        if (data.success && data.products && data.products.length > 0) {
                            allProducts = data.products;
                            loadCategories();
                            renderProducts();
                            $('#paginationInfo').html(`Toplam: <strong>${allProducts.length}</strong> ürün`);
                        } else {
                            $('#productsBody').html('<tr><td colspan="7" class="text-center text-warning">Hiç ürün yok veya veri hatası</td></tr>');
                        }
                    }, 'json')
                    .fail(function(jqXHR) {
                        console.error("HATA! Sunucudan gelen:", jqXHR.responseText);
                        $('#productsBody').html('<tr><td colspan="7" class="text-center text-danger"><strong>HATA!</strong><br>Konsoldaki responseText\'i bana gönder</td></tr>');
                    });
                }

                function loadCategories() {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'categories'
                    }, function(res) {
                        if (res.success && res.categories) {
                            const selects = ['addCategorySelect', 'editCategorySelect'];
                            selects.forEach(id => {
                                const select = document.getElementById(id);
                                if (select) {
                                    select.innerHTML = '<option value="">Kategori Seç (İsteğe Bağlı)</option>';
                                    res.categories.forEach(c => {
                                        select.innerHTML += `<option value="${c.id}">${c.name}</option>`;
                                    });
                                }
                            });
                        } else {
                            console.error('Kategoriler yüklenemedi:', res);
                        }
                    }, 'json').fail(function() {
                        console.error('AJAX hatası: menu_management.php yanıt vermiyor');
                    });
                }

                // RENDER
                function renderProducts() {
                    const search = document.getElementById('searchProducts').value.toLowerCase();
                    const filtered = allProducts.filter(p =>
                        p.name.toLowerCase().includes(search) ||
                        (p.barcode && p.barcode.toString().includes(search))
                    );
                    const total = filtered.length;
                    const pages = Math.ceil(total / perPage);
                    const start = (currentPage - 1) * perPage;
                    const end = Math.min(start + perPage, total);
                    const pageData = filtered.slice(start, end);

                    let tbody = '';
                    if (pageData.length === 0) {
                        tbody = '<tr><td colspan="7" class="text-center text-muted">Ürün bulunamadı</td></tr>';
                    } else {
                        pageData.forEach((p, i) => {
                            if(p.status === 'active'){
                                statu = '<span class="badge bg-success"> Satışta</span>';
                            }else{
                                statu = '<span class="badge bg-danger"> Satış Dışı</span>';
                            }
                            tbody += `<tr>
                                <td>${start + i + 1}</td>
                                <td><strong>${p.name}</strong></td>
                                <td data-barcode="${p.barcode || ''}">${p.barcode ? '<strong class="text-success">' + p.barcode + '</strong>' : '<span class="text-danger">Barkod Yok</span>'}</td>
                                <td>${p.category || '-'}</td>
                                <td>${parseFloat(p.stock).toFixed(2)}</td>
                                <td>${parseFloat(p.price).toFixed(2)} ₺</td>
                                <td>${statu}</td>
                                <td>
                                    <button class="btn btn-info btn-sm" onclick="viewProduct(${p.id})"><i class="fa fa-eye"></i></button>
                                    <button class="btn btn-warning btn-sm" onclick="editProduct(${p.id})"><i class="fa fa-pen"></i></button>
                                    <span class="btn btn-success btn-sm" title="Sipariş özelliği yakında aktif olacak"><i class="fa fa-truck"></i></span>
                                    <button class="btn btn-danger btn-sm" onclick="deleteProduct(${p.id}, '${p.name.replace(/'/g, "\\'")}')"><i class="fa fa-trash"></i></button>
                                </td>
                            </tr>`;
                        });
                    }
                    document.getElementById('productsBody').innerHTML = tbody;

                    let pag = '';
                    for (let i = 1; i <= pages; i++) {
                        pag += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                            <a class="page-link" href="#" onclick="currentPage=${i}; renderProducts(); return false;">${i}</a>
                        </li>`;
                    }
                    document.getElementById('pagination').innerHTML = pag || '<li class="page-item active"><span class="page-link">1</span></li>';
                }

                // SAYFA YÜKLENDİ
                window.addEventListener('load', () => {
                    console.log('Yükleme başladı');
                    loadProducts();
                });

                $(document).ready(function() {
                    loadCategories();
                });
                
                document.getElementById('addProductForm').onsubmit = function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('ajax', 1);
                    formData.append('action', 'add_product');

                    $.ajax({
                        url: 'menu_management.php',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(res) {
                            if (res.success) {
                                showToast('Yeni ürün başarıyla eklendi!', 'success');
                                loadProducts(); // listeyi yenile
                                document.getElementById('addProductForm').reset();
                                bootstrap.Modal.getInstance(document.getElementById('addProductModal')).hide();
                            } else {
                                showToast(res.message || 'Ekleme hatası!', 'danger');
                            }
                        },
                        error: function() {
                            showToast('Sunucu hatası!', 'danger');
                        }
                    });
                };
                let orderCart = [];
                let currentProductId = null;

                // === DÜZENLE MODALINI DOLDUR ===
                function editProduct(id) {
                    const p = allProducts.find(x => x.id == id);
                    if (!p) return showToast('Ürün bulunamadı!', 'danger');

                    document.getElementById('editProductId').value = p.id;
                    document.getElementById('editProductName').value = p.name;
                    document.getElementById('editProductStock').value = p.stock;
                    document.getElementById('editProductPrice').value = p.price;

                    // Kategoriyi seçili yap
                    const select = document.getElementById('editCategorySelect');
                    select.innerHTML = '<option value="">Kategori Seç</option>';
                    categories.forEach(c => {
                        select.innerHTML += `<option value="${c.id}" ${c.id == p.category_id ? 'selected' : ''}>${c.name}</option>`;
                    });

                    new bootstrap.Modal(document.getElementById('editProductModal')).show();
                }

                // === SİL MODALINI AÇ ===
                function deleteProduct(id, name) {
                    document.getElementById('deleteProductName').textContent = name;
                    
                    // Onay butonuna tıklanınca silme işlemi yapılacak
                    document.getElementById('confirmDelete').onclick = function() {
                        $.post('menu_management.php', {
                            ajax: 1,
                            action: 'delete_product',
                            id: id
                        }, function(res) {
                            if (res.success) {
                                showToast('Ürün başarıyla silindi!', 'success');
                                loadProducts(); // listeyi yenile
                                bootstrap.Modal.getInstance(document.getElementById('deleteProductModal')).hide();
                            } else {
                                showToast(res.message || 'Silme hatası!', 'danger');
                            }
                        }, 'json').fail(function() {
                            showToast('Sunucu hatası!', 'danger');
                        });
                    };

                    new bootstrap.Modal(document.getElementById('deleteProductModal')).show();
                }

                // DETAY GÖR MODALI
                function viewProduct(id) {
                    currentProductId = id;
                    $('#viewProductModal').data('product-id', id);

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_product_detail',
                        product_id: id,
                        csrf_token: csrfToken
                    }, function(res) {
                        if (!res.success || !res.product) {
                            showToast('Ürün bulunamadı!', 'danger');
                            return;
                        }

                        const p = res.product;
                        const extras   = res.extras   || [];
                        const sizes    = res.sizes    || [];
                        const recipe   = res.recipe   || [];
                        const features = res.features || [];    
                        const promos   = res.promotions || [];
                        const barcode   = res.barcode || [];

                        // STOK - FOTO - AÇIKLAMA
                        $('#viewProductName').text(p.name);
                        $('#viewProductPrice').text(parseFloat(p.unit_price).toFixed(2) + ' ₺');
                        $('#viewProductStock').text(parseFloat(p.stock_quantity || 0).toFixed(2));
                        const barkod = (p.barcode || '').toString().trim();
                        const barkodVar = barkod && barkod !== '0' && barkod !== '2147483647' && barkod.length >= 8;

                        if (barkodVar) {
                            $('#productBarcodeDisplay').html(`<strong class="text-success">${barkod}</strong>`);
                            $('#barcodeActions').html(`
                                <button type="button" class="btn btn-sm btn-info ms-2" id="printLabelBtn">
                                    <i class="fas fa-print"></i> Etiket Yazdır
                                </button>
                            `);
                        } else {
                            $('#productBarcodeDisplay').html('<span class="text-danger">Barkod Yok</span>');
                            $('#barcodeActions').html(`
                                <button type="button" class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#barcodeModal">
                                    <i class="fas fa-plus"></i> Barkod Ekle
                                </button>
                            `);
                        }
                        $('#viewProductDesc').text(p.description || 'Açıklama yok');
                        $('#currentProductImage').attr('src', p.image_url ? 'uploads/products/' + p.image_url : 'https://placehold.co/400x300?text=' + p.name);

                        // KAR MARJI (artık burada doğru hesaplanıyor)
                        let totalCost = 0;
                        recipe.forEach(r => totalCost += parseFloat(r.cost || 0) * parseFloat(r.quantity || 0));
                        const profitPercent = p.unit_price > totalCost ? ((p.unit_price - totalCost) / p.unit_price) * 100 : 0;
                        const badge = profitPercent >= 50 ? 'bg-success' : profitPercent >= 30 ? 'bg-warning' : 'bg-danger';
                        $('#profitInfo').html(`<div class="text-center mt-4"><strong>Kar Marjı: </strong><span class="badge ${badge} fs-5">${profitPercent.toFixed(1)}%</span></div>`);

                        // TÜM SEKMELER
                        loadExtrasToTab(extras);
                        loadSizesToTab(sizes);
                        loadRecipeToTab(recipe, p.unit_price);
                        loadFeaturesToTab(features);
                        loadPromotionsToTab(promos);
                        loadAssignedPromotions(id);

                        $('#viewProductModal').modal('show');
                    }, 'json');
                }

                function loadExtrasToTab(extras) {
                    const container = $('#extraFeatures');
                    
                    if (!extras || extras.length === 0) {
                        container.html('<p class="text-center text-muted">Henüz ekstra malzeme eklenmemiş.</p>');
                        return;
                    }

                    let html = '<div class="row">';
                    extras.forEach(e => {
                        const price = e.price > 0 ? `+${parseFloat(e.price).toFixed(2)} ₺` : '';
                        const status = e.is_active ? '<span class="badge bg-success ms-2">Aktif</span>' : '<span class="badge bg-secondary ms-2">Pasif</span>';
                        const quantityText = e.quantity && e.quantity > 1 ? `${e.quantity} ${e.unit}` : (e.unit || '');
                        
                        // GÜVENLİ JSON – HATA VERMEZ!
                        const extraData = JSON.stringify({
                            id: e.id,
                            name: e.name,
                            price: e.price || 0,
                            quantity: e.quantity || 1,
                            unit: e.unit || 'adet',
                            is_active: e.is_active || 0
                        });

                        html += `
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 bg-light d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${e.name}</strong><br>
                                    ${quantityText ? `<small class="text-muted">${quantityText}</small>` : ''}
                                    ${price ? `<span class="text-success ms-2">${price}</span>` : ''}
                                    <div class="mt-1">${status}</div>
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-warning me-1" onclick='openExtraModal(${extraData})'>
                                        <i class="fa fa-pen"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteExtra(${e.id})">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>`;
                    });
                    html += '</div>';
                    container.html(html);
                }

                function addExtraFromIngredient(ingredientId) {
                    const name = prompt("Ekstra adı (varsayılan malzeme adı):") || '';
                    const price = prompt("Ek fiyat (₺, 0 = ücretsiz):", "0") || 0;
                    const quantity = prompt("Miktar (örn: 30g, 50ml, 1 adet):", "1") || 1;
                    const unit = prompt("Birim (g, ml, adet vs.):", "adet") || 'adet';

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'save_extra',
                        product_id: currentProductId,
                        ingredient_id: ingredientId,
                        price: price,
                        quantity: quantity,
                        unit: unit,
                        is_active: 1
                    }, function(res) {
                        if (res.success) {
                            showToast('Ekstra malzeme eklendi!', 'success');
                            loadExtras(); // Yenile
                        } else {
                            showToast('Ekleme hatası!', 'danger');
                        }
                    });
                }

                // BOYUTLAR
                function loadSizesToTab(sizes) {
                    const el = document.getElementById('sizeFeatures');
                    if (!el) return;
                    if (!sizes.length) {
                        el.innerHTML = '<p class="text-muted text-center">Boyut tanımlanmamış</p>';
                        return;
                    }
                    let html = '<div class="row">';
                    sizes.forEach(s => {
                        const price = s.additional_price > 0 ? ` +${parseFloat(s.additional_price).toFixed(2)} ₺` : '';
                        html += `<div class="col-md-4 mb-3"><div class="border rounded p-3 text-center bg-white"><strong>${s.name}</strong><br><small class="text-success">${price}</small><br><small class="text-muted">Stok: ${s.stock_quantity || 0}</small></div></div>`;
                    });
                    html += '</div>';
                    el.innerHTML = html;
                }

                function loadRecipeToTab(recipe, productPrice) {
                    const container = document.getElementById('productRecipe');
                    
                    if (!recipe || recipe.length === 0) {
                        container.innerHTML = '<div class="text-center py-5 text-muted"><i class="fa fa-utensils fa-3x mb-3"></i><p>Reçete tanımlanmamış</p></div>';
                        return;
                    }

                    let totalCost = 0;
                    let html = '<div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Malzeme</th><th>Miktar</th><th>Birim Fiyat</th><th>Maliyet</th></tr></thead><tbody>';

                    recipe.forEach(item => {
                        const qty = parseFloat(item.quantity || 0);
                        const cost = parseFloat(item.cost || 0);
                        const lineCost = qty * cost;
                        totalCost += lineCost;
                        html += `
                            <tr>
                                <td><strong>${item.ingredient_name || 'Bilinmeyen'}</strong></td>
                                <td>${qty.toFixed(3)}</td>
                                <td>${cost.toFixed(2)} ₺</td>
                                <td class="text-end text-danger fw-bold">${lineCost.toFixed(2)} ₺</td>
                            </tr>`;
                    });

                    const salePrice = parseFloat(productPrice || 0);
                    const profit = salePrice - totalCost;
                    const profitPercent = totalCost > 0 ? ((profit / totalCost) * 100).toFixed(1) : 0;

                    html += `
                        </tbody>
                        <tfoot class="table-success fw-bold">
                            <tr><td colspan="3" class="text-end">Toplam Maliyet:</td><td class="text-end">${totalCost.toFixed(2)} ₺</td></tr>
                            <tr><td colspan="3" class="text-end">Satış Fiyatı:</td><td class="text-end text-success">${salePrice.toFixed(2)} ₺</td></tr>
                            <tr><td colspan="3" class="text-end">Kar Marjı:</td><td class="text-end ${profit >= 0 ? 'text-success' : 'text-danger'}">${profit.toFixed(2)} ₺ (%${profitPercent})</td></tr>
                        </tfoot>
                        </table></div>`;

                    container.innerHTML = html;
                }

                function loadProductFeatures(productId) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_all_features_with_assignment',
                        product_id: productId
                    }, function(res) {
                        const container = document.getElementById('requiredFeatures');
                        
                        if (!res.success || res.features.length === 0) {
                            container.innerHTML = '<p class="text-muted text-center">Henüz zorunlu seçim grubu tanımlanmamış.</p>';
                            return;
                        }

                        let html = '<div class="row">';
                        res.features.forEach(f => {
                            const checked = f.assigned ? 'checked' : '';
                            html += `
                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 bg-white">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" ${checked} 
                                                   onchange="toggleFeatureAssignment(${f.id}, ${productId}, this.checked)">
                                            <label class="form-check-label fw-bold">${f.name}</label>
                                        </div>
                                        ${f.additional_price > 0 ? `<small class="text-success">+${f.additional_price} ₺</small>` : ''}
                                        <small class="text-muted d-block">Stok: ${f.stock_quantity}</small>
                                    </div>
                                </div>`;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }, 'json');
                }

                // Atama yap / kaldır
                function toggleFeatureAssignment(featureId, productId, assign) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: assign ? 'assign_feature' : 'unassign_feature',
                        feature_id: featureId,
                        product_id: productId
                    }, function(res) {
                        showToast(assign ? 'Seçim eklendi!' : 'Seçim kaldırıldı!', 'success');
                    });
                }

                // Ekstra Malzemeler
                function loadProductExtras(productId) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_extras',
                        product_id: productId
                    }, function(res) {
                        const container = document.getElementById('extraFeatures');
                        if (!res.success || res.extras.length === 0) {
                            container.innerHTML = '<p class="text-muted text-center">Ekstra malzeme yok</p>';
                            return;
                        }

                        let html = '<h6>Ekstra Malzemeler</h6><div class="row">';
                        res.extras.forEach(e => {
                            const price = e.price > 0 ? ` +${e.price} ₺` : ' (Ücretsiz)';
                            html += `
                                <div class="col-md-6 mb-2">
                                    <div class="border rounded p-3 bg-light">
                                        <strong>${e.name}</strong><span class="text-success">${price}</span>
                                    </div>
                                </div>`;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }, 'json');
                }

                // Boyutlar
                function loadProductSizes(productId) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_sizes',
                        product_id: productId
                    }, function(res) {
                        const container = document.getElementById('extras'); // aynı sekme içinde gösterelim
                        let html = container.innerHTML;
                        if (res.success && res.sizes.length > 0) {
                            html += '<hr><h6>Boyut Seçenekleri</h6><div class="row">';
                            res.sizes.forEach(s => {
                                const price = s.additional_price > 0 ? ` +${s.additional_price} ₺` : '';
                                html += `
                                    <div class="col-md-4 mb-2">
                                        <div class="border rounded p-3 text-center">
                                            <strong>${s.name}</strong>${price}<br>
                                            <small>Stok: ${s.stock_quantity}</small>
                                        </div>
                                    </div>`;
                            });
                            html += '</div>';
                        }
                        container.innerHTML = html;
                    }, 'json');
                }

                // İndirimler
                function loadProductPromotions(productId) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_promotions'
                    }, function(res) {
                        const container = document.getElementById('discountGroups');
                        if (!res.success || res.promotions.length === 0) {
                            container.innerHTML = '<p class="text-muted text-center">Aktif kampanya yok</p>';
                            return;
                        }

                        let html = '<h6>Aktif Kampanyalar</h6><ul class="list-group">';
                        res.promotions.forEach(p => {
                            html += `<li class="list-group-item d-flex justify-content-between">
                                <strong>${p.code}</strong>
                                <span class="badge bg-success">-%${p.discount_percent}</span>
                            </li>`;
                        });
                        html += '</ul>';
                        container.innerHTML = html;
                    }, 'json');
                }

                function loadProductRecipe(productId) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_recipe',
                        product_id: productId
                    }, function(res) {
                        const container = document.getElementById('productRecipe');
                        
                        if (!res.success || res.recipe.length === 0) {
                            container.innerHTML = `
                                <div class="text-center py-5 text-muted">
                                    <i class="fa fa-utensils fa-3x mb-3"></i>
                                    <p>Bu ürün için reçete tanımlanmamış.</p>
                                </div>`;
                            return;
                        }

                        let totalCost = 0;
                        let html = `
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Malzeme</th>
                                            <th>Miktar</th>
                                            <th>Maliyet</th>
                                        </tr>
                                    </thead>
                                    <tbody>`;

                        res.recipe.forEach(item => {
                            const cost = (item.ingredient_cost || 0) * item.quantity;
                            totalCost += cost;
                            html += `
                                <tr>
                                    <td><strong>${item.ingredient_name || 'Bilinmeyen Malzeme'}</strong></td>
                                    <td>${parseFloat(item.quantity).toFixed(3)} ${item.unit || ''}</td>
                                    <td class="text-end text-danger fw-bold">${cost.toFixed(2)} ₺</td>
                                </tr>`;
                        });

                        const productPrice = allProducts.find(p => p.id == productId)?.price || 0;
                        const profit = productPrice - totalCost;
                        const profitPercent = totalCost > 0 ? (profit / totalCost * 100).toFixed(1) : 0;

                        html += `
                                    </tbody>
                                    <tfoot class="table-success">
                                        <tr>
                                            <th colspan="2" class="text-end">Toplam Maliyet:</th>
                                            <th class="text-end">${totalCost.toFixed(2)} ₺</th>
                                        </tr>
                                        <tr>
                                            <th colspan="2" class="text-end">Satış Fiyatı:</th>
                                            <th class="text-end text-success">${parseFloat(productPrice).toFixed(2)} ₺</th>
                                        </tr>
                                        <tr>
                                            <th colspan="2" class="text-end">Kar (Brüt):</th>
                                            <th class="text-end ${profit >= 0 ? 'text-success' : 'text-danger'}">
                                                ${profit.toFixed(2)} ₺ (${profitPercent}%)
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>`;

                        container.innerHTML = html;
                    }, 'json');
                }

                // Gönder
                function sendOrderToSupplier() {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'send_order',
                        items: JSON.stringify(orderCart)
                    }, function(res) {
                        if (res.success) {
                            showToast('Sipariş üst şirkete başarıyla gönderildi!', 'success');
                            orderCart = [];
                            updateFloatingCart();
                            bootstrap.Modal.getInstance(document.getElementById('orderCartModal')).hide();
                        } else {
                            showToast(res.message || 'Hata oluştu!', 'danger');
                        }
                    }, 'json');
                }

                function editRecipe(productId) {
                    showToast('Reçete düzenleme yakında aktif olacak!', 'info');
                    // İleride buraya reçete düzenleme modalı gelecek
                }

                function addFeatureRow() {
                    const container = document.getElementById('requiredFeatures');
                    const html = `
                        <div class="row g-3 mb-3 border rounded p-3 bg-light feature-row">
                            <div class="col-md-5">
                                <input type="text" class="form-control" placeholder="Seçim adı (örn: Az Şekerli)" required>
                            </div>
                            <div class="col-md-3">
                                <input type="number" step="0.01" class="form-control" placeholder="Ek fiyat (₺)" value="0.00">
                            </div>
                            <div class="col-md-2">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" checked>
                                    <label class="form-check-label">Zorunlu</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-success btn-sm me-2" onclick="saveFeature(this)">Kaydet</button>
                                <button class="btn btn-danger btn-sm" onclick="this.closest('.feature-row').remove()">Sil</button>
                            </div>
                        </div>`;
                    container.innerHTML = html + container.innerHTML;
                }

                // Zorunlu seçim kaydet
                function saveFeature(btn) {
                    const row = btn.closest('.feature-row');
                    const name = row.querySelector('input[type=text]').value.trim();
                    const price = row.querySelector('input[type=number]').value;
                    const mandatory = row.querySelector('input[type=checkbox]').checked ? 1 : 0;

                    if (!name) return showToast('Seçim adı giriniz!', 'danger');

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'save_feature',
                        product_id: currentProductId,
                        name: name,
                        additional_price: price,
                        is_mandatory: mandatory
                    }, function(res) {
                        if (res.success) {
                            showToast('Zorunlu seçim kaydedildi!', 'success');
                            loadProductFeatures(currentProductId);
                        } else {
                            showToast(res.message || 'Hata!', 'danger');
                        }
                    }, 'json');
                }

                // EKSTRA MALZEME EKLE SATIRI
                function addExtraRow() {
                    const container = document.getElementById('extraFeatures');
                    const html = `
                        <div class="row g-3 mb-3 border rounded p-3 bg-light mb-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" placeholder="Ekstra malzeme adı" required>
                            </div>
                            <div class="col-md-4">
                                <input type="number" step="0.01" class="form-control" placeholder="Ek fiyat" value="0.00">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-success btn-sm me-2" onclick="saveExtra(this)">Kaydet</button>
                                <button class="btn btn-danger btn-sm" onclick="this.closest('.row').remove()">Sil</button>
                            </div>
                        </div>`;
                    container.innerHTML = html + container.innerHTML;
                }

                // BOYUT EKLE SATIRI
                function addSizeRow() {
                    const container = document.getElementById('sizeFeatures');
                    const html = `
                        <div class="row g-3 mb-3 border rounded p-3 bg-light">
                            <div class="col-md-5">
                                <input type="text" class="form-control" placeholder="Boyut adı (Küçük, Orta, Büyük)" required>
                            </div>
                            <div class="col-md-3">
                                <input type="number" step="0.01" class="form-control" placeholder="Ek fiyat" value="0.00">
                            </div>
                            <div class="col-md-2">
                                <input type="number" class="form-control" placeholder="Stok" value="0">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-success btn-sm me-2" onclick="saveSize(this)">Kaydet</button>
                                <button class="btn btn-danger btn-sm" onclick="this.closest('.row').remove()">Sil</button>
                            </div>
                        </div>`;
                    container.innerHTML = html + container.innerHTML;
                }

                // EKSTRA KAYDET
                function saveExtra() {
                    const ingredient_id = $('#ingredientSelect').val();
                    const price = $('#extraPrice').val();

                    if (!ingredient_id) {
                        showToast('Lütfen malzeme seç!', 'danger');
                        return;
                    }

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'add_extra',
                        csrf_token: csrfToken,
                        product_id: currentProductId,
                        ingredient_id: ingredient_id,
                        price: price
                    }, function(res) {
                        if (res.success) {
                            showToast('Ekstra malzeme eklendi!', 'success');
                            $('#addExtraModal').modal('hide');
                            loadProductDetail(currentProductId); // tekrar yükle ki güncel olsun
                        } else {
                            showToast('Hata oluştu!', 'danger');
                        }
                    }, 'json');
                }

                // BOYUT KAYDET
                function saveSize(btn) {
                    const row = btn.closest('.row');
                    const name = row.querySelectorAll('input')[0].value.trim();
                    const price = row.querySelectorAll('input')[1].value;
                    const stock = row.querySelectorAll('input')[2].value || 0;

                    if (!name) return showToast('Boyut adı gir!', 'danger');

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'save_size',
                        product_id: currentProductId,
                        name: name,
                        additional_price: price,
                        stock_quantity: stock
                    }, function(res) {
                        if (res.success) {
                            showToast('Boyut eklendi!', 'success');
                            loadProductSizes(currentProductId);
                        }
                    }, 'json');
                }

                function toggleProductStatus(productId, isChecked) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'toggle_status',
                        product_id: productId,
                        status: isChecked
                    }, function(res) {
                        if (res.success) {
                            // allProducts dizisini güncelle
                            const product = allProducts.find(p => p.id == productId);
                            if (product) {
                                product.status = isChecked ? 'active' : 'inactive';
                                product.isActive = isChecked;
                            }

                            // Modal içindeki yazıyı güncelle
                            const span = document.querySelector('#saleStatusRow label span');
                            if (span) {
                                span.textContent = isChecked ? 'SATIŞTA' : 'SATIŞ DIŞI';
                                span.className = isChecked ? 'text-success' : 'text-danger';
                            }

                            showToast(isChecked ? 'Ürün satışa açıldı!' : 'Ürün satış dışı yapıldı!', 'success');
                        } else {
                            document.querySelector('#saleStatusSwitch_' + productId).checked = !isChecked;
                            showToast('Hata oluştu!', 'danger');
                        }
                    }, 'json');
                }

                // 2. KARANLIK MOD
                function toggleDarkMode() {
                    const body = document.body;
                    body.classList.toggle('dark-mode');
                    const isDark = body.classList.contains('dark-mode');
                    
                    // Buton ikonunu değiştir
                    const btn = document.getElementById('darkModeBtn');
                    if (btn) {
                        btn.innerHTML = isDark ? '<i class="fa fa-sun"></i>' : '<i class="fa fa-moon"></i>';
                    }
                    
                    // Tercihi kaydet
                    localStorage.setItem('darkMode', isDark);
                }

                // Sayfa yüklendiğinde karanlık mod kontrolü
                document.addEventListener('DOMContentLoaded', function() {
                    if (localStorage.getItem('darkMode') === 'true') {
                        document.body.classList.add('dark-mode');
                        const btn = document.getElementById('darkModeBtn');
                        if (btn) btn.innerHTML = '<i class="fa fa-sun"></i>';
                    }
                });

                // 3. BARKOD OKUYUCU DESTEĞİ (herhangi bir inputa odaklanmadan çalışır)
                let barcodeBuffer = '';
                document.addEventListener('keypress', function(e) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                    
                    if (e.key === 'Enter') {
                        if (barcodeBuffer.length > 5) {
                            searchProductByBarcode(barcodeBuffer);
                        }
                        barcodeBuffer = '';
                    } else {
                        barcodeBuffer += e.key;
                    }
                });

                function searchProductByBarcode(code) {
                    const product = allProducts.find(p => p.barcode == code);
                    if (product) {
                        viewProduct(product.id);
                        showToast(`${product.name} bulundu ve açıldı!`, 'success');
                    } else {
                        showToast('Barkod bulunamadı: ' + code, 'warning');
                    }
                }

                // 4. REÇETE DÜZENLEME (+ Malzeme Ekle)
                function editRecipe(productId) {
                    currentProductId = productId;
                    loadIngredientsForRecipe(); // aşağıda tanımlayacağız
                    new bootstrap.Modal(document.getElementById('recipeEditModal')).show();
                }

                // ZORUNLU SEÇİMLER
                function loadFeaturesToTab(features) {
                    const el = document.getElementById('requiredFeatures');
                    if (!el) return;
                    if (!features.length) {
                        el.innerHTML = '<p class="text-muted text-center">Zorunlu seçim tanımlanmamış</p>';
                        return;
                    }
                    let html = '<div class="row">';
                    features.forEach(f => {
                        const mandatory = f.is_mandatory ? '<span class="badge bg-danger ms-2">Zorunlu</span>' : '';
                        const price = f.additional_price > 0 ? `<span class="text-success ms-2">+${parseFloat(f.additional_price).toFixed(2)} ₺</span>` : '';
                        html += `<div class="col-md-6 mb-3"><div class="border rounded p-3 bg-light"><strong>${f.name}</strong>${mandatory}${price}</div></div>`;
                    });
                    html += '</div>';
                    el.innerHTML = html;
                }

                // İNDİRİM GRUPLARI
                function loadPromotionsToTab(promotions) {
                    const el = document.getElementById('discountGroups');
                    if (!el) return;
                    if (!promotions.length) {
                        el.innerHTML = '<p class="text-muted text-center">Aktif kampanya yok</p>';
                        return;
                    }
                    let html = '<ul class="list-group">';
                    promotions.forEach(p => {
                        html += `<li class="list-group-item d-flex justify-content-between"><strong>${p.code || p.name}</strong><span class="badge bg-success">-%${p.discount_percent}</span></li>`;
                    });
                    html += '</ul>';
                    el.innerHTML = html;
                }

                // EAN13 barkod üretme (barkod yoksa otomatik atayacak)
                function generateEAN13() {
                    $code = '200' . str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
                    $sum = 0;
                    for ($i = 0; $i < 12; $i++) {
                        $sum += ($i % 2 == 0) ? $code[$i] : $code[$i] * 3;
                    }
                    $check = (10 - ($sum % 10)) % 10;
                    return $code . $check;
                }
                function printProduct() {
                    window.print();
                }

                function exportCSV() {
                    const data = `Ürün Adı,Fiyat,Stok,Barkod,Kar Marjı\n${currentProduct.name},${currentProduct.unit_price},${currentProduct.stock_quantity},${currentProduct.barcode || 'Yok'},${profitPercent.toFixed(1)}%`;
                    const blob = new Blob([data], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement("a");
                    link.href = URL.createObjectURL(blob);
                    link.download = currentProduct.name + ".csv";
                    link.click();
                }

                function exportPDF() {
                    alert("PDF özelliği aktif edilecek – FPDF ile yapılacak (sonra ekleriz kanka)");
                    // İstersen FPDF ile yaparız, şimdilik uyarı
                }
                function openAddExtraModal(extra = null) {
                    currentProductId = $('#viewProductModal').data('product-id');
                    
                    const modal = $('#extraModal');
                    const form = document.getElementById('extraForm');
                    form.reset();
                    
                    // Temizle
                    form.querySelector('[name="extra_id"]').value = '';
                    form.querySelector('[name="price"]').value = '0';
                    form.querySelector('[name="quantity"]').value = '1';
                    form.querySelector('[name="unit"]').value = 'adet';
                    form.querySelector('[name="is_active"]').checked = true;

                    if (extra) {
                        form.querySelector('[name="extra_id"]').value = extra.id;
                        form.querySelector('[name="price"]').value = extra.price || 0;
                        form.querySelector('[name="quantity"]').value = extra.quantity || 1;
                        form.querySelector('[name="unit"]').value = extra.unit || 'adet';
                        form.querySelector('[name="is_active"]').checked = extra.is_active == 1;
                    }

                    // MALZEMELERİ YÜKLE – EKSİK OLAN BUYDU!
                    const select = form.querySelector('[name="ingredient_id"]');
                    select.innerHTML = '<option value="">Yükleniyor...</option>';

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_ingredients'
                    }, function(res) {
                        select.innerHTML = '<option value="">Malzeme Seç</option>';
                        if (res.success && res.ingredients) {
                            res.ingredients.forEach(ing => {
                                const opt = document.createElement('option');
                                opt.value = ing.id;
                                opt.textContent = `${ing.name} (Stok: ${ing.stock_quantity})`;
                                if (extra && extra.ingredient_id == ing.id) opt.selected = true;
                                select.appendChild(opt);
                            });
                        } else {
                            select.innerHTML = '<option value="">Malzeme yok</option>';
                        }
                    }).fail(function() {
                        select.innerHTML = '<option value="">Hata!</option>';
                    });

                    modal.modal('show');
                }

                function loadIngredientsForSelect() {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_ingredients',
                        csrf_token: csrfToken
                    }, function(res) {
                        if (res.success) {
                            const select = $('#ingredientSelect');
                            select.empty();
                            select.append('<option value="">— Malzeme Seç —</option>');
                            res.ingredients.forEach(ing => {
                                select.append(`<option value="${ing.id}">${ing.name}</option>`);
                            });
                        }
                    }, 'json');
                }

                // === GÖRSEL YÜKLEME - SONSUZ DÖNGÜYE GİRMEYEN VERSİYON ===
                let selectedFile = null;
                let isImageUploading = false;

                const $dropZone = $('#dropZone');
                const $imageInput = $('#productImageInput');
                const $preview = $('#currentProductImage');
                const $saveBtn = $('#saveImageBtn');

                // Tıklama ile dosya seç
                $('#dropZone').off('click').on('click', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    document.getElementById('productImageInput').click(); // native DOM, jQuery değil!
                });

                // Drag & Drop
                $dropZone.off('dragover dragenter dragleave drop').on('dragover dragenter', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).addClass('border-primary bg-primary-subtle');
                }).on('dragleave dragend', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('border-primary bg-primary-subtle');
                }).on('drop', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('border-primary bg-primary-subtle');
                    const files = e.originalEvent.dataTransfer?.files;
                    if (files?.length) handleImage(files[0]);
                });

                // Input change
                $imageInput.off('change').on('change', function(e) {
                    if (this.files?.length) handleImage(this.files[0]);
                });

                function handleImage(file) {
                    if (isImageUploading) return;
                    if (!file.type.match('image.*')) {
                        showToast('Lütfen sadece resim dosyası seçin!', 'danger');
                        return;
                    }

                    selectedFile = file;
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $preview.attr('src', e.target.result).show();
                        $saveBtn.show();
                        showToast('Resim seçildi, kaydetmek için butona tıkla', 'success');
                    };
                    reader.readAsDataURL(file);
                }

                // Kaydet butonu
                $saveBtn.off('click').on('click', function() {
                    if (!selectedFile || isImageUploading) return;

                    isImageUploading = true;
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Kaydediliyor...');

                    const formData = new FormData();
                    formData.append('ajax', 1);
                    formData.append('action', 'upload_product_image');
                    formData.append('product_id', currentProductId);
                    formData.append('image', selectedFile);

                    $.ajax({
                        url: 'menu_management.php',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        timeout: 30000,
                        success: function(res) {
                            if (res.success) {
                                const newUrl = res.image_url + '?v=' + Date.now();
                                $('#viewProductImage, #currentProductImage').attr('src', newUrl);
                                showToast('Görsel başarıyla kaydedildi!', 'success');
                                $saveBtn.hide();
                                selectedFile = null;
                            } else {
                                showToast(res.message || 'Kaydetme hatası!', 'danger');
                            }
                        },
                        error: function() {
                            showToast('Sunucuyla bağlantı hatası!', 'danger');
                        },
                        complete: function() {
                            isImageUploading = false;
                            $saveBtn.prop('disabled', false).html('<i class="fas fa-save"></i> Görseli Kaydet');
                        }
                    });
                });

                // BARKOD SİSTEMİ - BABANIN EMRİNDEYİZ!

                $(document).on('click', '.product-item', function() {
                    currentProductId = $(this).data('id');
                    const productBarcode = $(this).data('barcode'); // ÖNEMLİ: aşağıda anlatacağım

                    // BARKOD KISMI - İŞTE BURASI ÇÖZÜM!
                    const barkod = (productBarcode || '').toString().trim();
                    const barkodVar = barkod && barkod !== '0' && barkod !== '2147483647' && barkod.length >= 8;

                    if (barkodVar) {
                        $('#productBarcodeDisplay').html(`<strong class="text-success">${barkod}</strong>`);
                        $('#barcodeActions').html(`
                            <button type="button" class="btn btn-sm btn-info ms-2" id="printLabelBtn">
                                <i class="fas fa-print"></i> Etiket Yazdır
                            </button>
                        `);
                    } else {
                        $('#productBarcodeDisplay').html('<span class="text-danger">Barkod Yok</span>');
                        $('#barcodeActions').html(`
                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#barcodeModal">
                                <i class="fas fa-plus"></i> Barkod Ekle
                            </button>
                        `);
                    }

                    // currentProductId ayarla (barkod kaydetme için)
                    currentProductId = productId;
                });

                $('#generateBarcodeBtn').on('click', function() {
                    const random13 = Math.floor(1000000000000 + Math.random() * 9000000000000);
                    const barcode = generateEAN13(random13.toString().substring(0,12));
                    $('#newBarcodeInput').val(barcode);
                });

                $('#saveBarcodeBtn').on('click', function() {
                    const barcode = $('#newBarcodeInput').val().trim();

                    if (!barcode || barcode.length !== 13 || !/^\d+$/.test(barcode)) {
                        showToast('Hata!', 'Lütfen geçerli 13 haneli barkod girin', 'danger');
                        return;
                    }

                    const $btn = $(this);
                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Kaydediliyor...');

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'save_barcode',
                        product_id: currentProductId,
                        barcode: barcode
                    }, function(res) {
                        if (res.success) {
                            $('#productBarcodeDisplay').html(`
                                <strong class="text-success">${barcode}</strong>
                                <button type="button" class="btn btn-sm btn-info ms-2" id="printLabelBtn">
                                    <i class="fas fa-print"></i> Etiket Yazdır
                                </button>
                            `);
                            $('#barcodeModal').modal('hide');
                            showToast('Başarılı!', 'Barkod kaydedildi', 'success');
                        } else {
                            showToast('Hata!', res.message || 'Kaydedilemedi', 'danger');
                        }
                    }, 'json')
                    .fail(function() {
                        showToast('Sunucu Hatası!', 'Bağlantı kurulamadı', 'danger');
                    })
                    .then(function() {
                        // HER ZAMAN ÇALIŞIR → buton normale döner
                        $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Barkodu Kaydet');
                        setTimeout(refreshBarcodeDisplay, 500);
                    });
                });

                // Sayfa yüklendikten sonra barkod durumunu AJAX ile güncelle (en garanti yöntem!)
                function refreshBarcodeDisplay() {
                    if (!currentProductId) return;

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_product_barcode',
                        product_id: currentProductId
                    }, function(res) {
                        if (res.success && res.barcode) {
                            const barcode = res.barcode;
                            $('#productBarcodeDisplay').html(`
                                <strong class="text-success">${barcode}</strong>
                                <button type="button" class="btn btn-sm btn-info ms-2" id="printLabelBtn">
                                    <i class="fas fa-print"></i> Etiket Yazdır
                                </button>
                            `);
                        }
                    });
                }

                function loadAssignedPromotions(productId) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_product_promotions',
                        product_id: productId
                    }, function(res) {
                        const container = $('#assignedPromotions');
                        if (!res.success || res.promotions.length === 0) {
                            container.html('<p class="text-center text-muted">Bu ürüne indirim grubu atanmamış.</p>');
                            return;
                        }
                        let html = '<div class="row">';
                        res.promotions.forEach(p => {
                            html += `
                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 bg-light d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>${p.code}</strong><br>
                                            <span class="badge bg-success">-%${p.discount_percent}</span>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger" onclick="togglePromotion(${productId}, ${p.id}, false)">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    </div>
                                </div>`;
                        });
                        html += '</div>';
                        container.html(html);
                    }, 'json');
                }

                function openAddPromotionModal() {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_available_promotions'
                    }, function(res) {
                        const select = $('#promotionSelect');
                        select.empty();
                        if (res.promotions.length === 0) {
                            select.append('<option>Kampanya yok</option>');
                            return;
                        }
                        res.promotions.forEach(p => {
                            select.append(`<option value="${p.id}">${p.code} (-%${p.discount_percent})</option>`);
                        });
                        $('#addPromotionModal').modal('show');
                    }, 'json');
                }

                function assignPromotion() {
                    const promotionId = $('#promotionSelect').val();
                    if (!promotionId) return;
                    togglePromotion(currentProductId, promotionId, true);
                    $('#addPromotionModal').modal('hide');
                }

                function togglePromotion(productId, promotionId, add) {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'toggle_product_promotion',
                        product_id: productId,
                        promotion_id: promotionId,
                        add: add
                    }, function(res) {
                        if (res.success) {
                            showToast(add ? 'Kampanya eklendi!' : 'Kampanya kaldırıldı!', 'success');
                            loadAssignedPromotions(productId);
                        }
                    }, 'json');
                }

                let selectedOrderItems = [];
                let finalOrderItems = [];

                // Tüm ürün + malzeme getir
                async function loadOrderItems() {
                    const res = await fetch('menu_management.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'ajax=1&action=get_all_items'
                    });
                    const data = await res.json();
                    if (!data.success) return;

                    const grid = document.getElementById('orderItemsGrid');
                    let html = '';
                    data.items.forEach(item => {
                        const badge = item.stock < 10 ? 'danger' : item.stock < 30 ? 'warning' : 'success';
                        html += `
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 order-card" onclick="toggleOrderSelect(${item.id}, '${item.type}')" id="order_${item.type}_${item.id}">
                                <div class="card-body text-center">
                                    <h6 class="card-title mb-2">${item.display_name}</h6>
                                    <p class="small text-muted">Stok: <span class="badge bg-${badge}">${parseFloat(item.stock).toFixed(3)}</span></p>
                                    <small>${item.unit || 'ad'}</small>
                                </div>
                            </div>
                        </div>`;
                    });
                    grid.innerHTML = html;
                }

                function toggleOrderSelect(id, type) {
                    const key = `${type}_${id}`;
                    const card = document.getElementById(`order_${type}_${id}`);
                    if (selectedOrderItems.includes(key)) {
                        selectedOrderItems = selectedOrderItems.filter(x => x !== key);
                        card.classList.remove('border-primary', 'border-4');
                    } else {
                        selectedOrderItems.push(key);
                        card.classList.add('border-primary', 'border-4');
                    }
                    document.getElementById('selectedOrderCount').textContent = selectedOrderItems.length;
                    document.getElementById('goToQuantityBtn').disabled = selectedOrderItems.length === 0;
                }

                document.addEventListener('click', function(e) {
                    if (e.target && e.target.id === 'goToQuantityBtn') {
                        finalOrderItems = [];
                        selectedOrderItems.forEach(key => {
                            const [type, id] = key.split('_');
                            const card = document.getElementById(`order_${type}_${id}`);
                            const name = card.querySelector('h6').textContent.trim();
                            finalOrderItems.push({id: parseInt(id), type, name, quantity: 1});
                        });
                        renderOrderQuantity();
                        bootstrap.Modal.getInstance(document.getElementById('newOrderModal')).hide();
                        new bootstrap.Modal(document.getElementById('orderQuantityModal')).show();
                    }
                });

                function renderOrderQuantity() {
                    let html = '';
                    finalOrderItems.forEach((item, i) => {
                        html += `<tr>
                            <td>${item.name}</td>
                            <td><input type="number" step="0.001" class="form-control form-control-sm" value="${item.quantity}" onchange="finalOrderItems[${i}].quantity = this.value"></td>
                        </tr>`;
                    });
                    document.getElementById('orderQuantityBody').innerHTML = html;
                }
    
                function backToOrderSelect() {
                    const qtyModal = bootstrap.Modal.getInstance(document.getElementById('orderQuantityModal'));
                    if (qtyModal) qtyModal.hide();
                    const mainModal = new bootstrap.Modal(document.getElementById('newOrderModal'));
                    mainModal.show();
                }                

                // Arama
                document.getElementById('orderSearchBox')?.addEventListener('input', function() {
                    const q = this.value.toLowerCase();
                    document.querySelectorAll('.order-card').forEach(card => {
                        const text = card.querySelector('h6').textContent.toLowerCase();
                        card.closest('.col-md-4').style.display = text.includes(q) ? '' : 'none';
                    });
                });                

                // Sayfa yüklendiğinde butonu göster
                window.addEventListener('load', () => {
                    const cartBtn = document.getElementById('floatingOrderCart');
                    if (cartBtn) cartBtn.style.display = 'block';
                    loadProducts();
                });

                function cancelImageUpload() {
                    selectedFile = null;
                    $('#currentProductImage').attr('src', '');
                    $('#imagePreview').hide();
                    $('#dropZone').show();
                    $('#saveImageBtn').hide();
                }
               
                function loadIngredientsForRecipe() {
                    // Önce malzemeleri al
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_ingredients'
                    }, function(ingRes) {
                        if (!ingRes.success || !ingRes.ingredients || ingRes.ingredients.length === 0) {
                            $('#recipeItems').html('<p class="text-danger text-center">Malzemeler yüklenemedi veya hiç malzeme yok!</p>');
                            return;
                        }

                        // Sonra mevcut reçeteyi al
                        $.post('menu_management.php', {
                            ajax: 1,
                            action: 'get_recipe',
                            product_id: currentProductId
                        }, function(recipeRes) {
                            const existing = {};
                            if (recipeRes.success && recipeRes.recipe) {
                                recipeRes.recipe.forEach(item => {
                                    existing[item.ingredient_id] = parseFloat(item.quantity) || 0;
                                });
                            }

                            const container = $('#recipeItems');
                            container.empty();

                            ingRes.ingredients.forEach(ing => {
                                const qty = existing[ing.id] || 0;

                                container.append(`
                                    <div class="row g-3 mb-3 align-items-center ingredient-row border rounded p-2 bg-light">
                                        <div class="col-md-6">
                                            <strong>${ing.name}</strong>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="number" step="0.001" min="0" class="form-control recipe-qty" 
                                                data-id="${ing.id}" value="${qty}" placeholder="0.000">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="this.closest('.ingredient-row').remove()">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                `);
                            });

                            // Kaydet butonu
                            container.append(`
                                <div class="text-center mt-4">
                                    <button class="btn btn-success btn-lg px-5" onclick="saveRecipe()">
                                        <i class="fa fa-save"></i> Reçeteyi Kaydet
                                    </button>
                                </div>
                            `);

                        }, 'json').fail(function() {
                            $('#recipeItems').html('<p class="text-danger text-center">Reçete yüklenemedi!</p>');
                        });

                    }, 'json').fail(function() {
                        $('#recipeItems').html('<p class="text-danger text-center">Malzemeler yüklenemedi!</p>');
                    });
                }

                // REÇETE KAYDET
                function saveRecipe() {
                    const items = [];
                    $('.recipe-qty, input[data-id]').each(function() {
                        const id = $(this).data('id');
                        const qty = parseFloat($(this).val()) || 0;
                        if (qty > 0) {
                            items.push({ ingredient_id: id, quantity: qty });
                        }
                    });

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'save_recipe',
                        product_id: currentProductId,
                        items: JSON.stringify(items)
                    }, function(res) {
                        if (res.success) {
                            showToast('Reçete kaydedildi!', 'success');
                            $('#recipeEditModal').modal('hide');
                            loadProductRecipe(currentProductId);
                        } else {
                            showToast('Reçete kaydedilemedi!', 'danger');
                        }
                    }, 'json');
                }

                function openAddFeatureGroupModal() {
                    currentProductId = $('#viewProductModal').data('product-id');
                    $('#featureGroupName').val('');
                    $('#featureMandatory').prop('checked', false);
                    $('#addFeatureGroupModal').modal('show');
                }

                function saveFeatureGroup() {
                    const name = $('#featureGroupName').val().trim();
                    const mandatory = $('#featureMandatory').is(':checked');

                    if (!name) {
                        showToast('Grup adı gerekli!', 'danger');
                        return;
                    }

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'add_feature_group',
                        product_id: currentProductId,
                        group_name: name,
                        is_mandatory: mandatory
                    }, function(res) {
                        if (res.success) {
                            showToast('Zorunlu seçim grubu eklendi!', 'success');
                            $('#addFeatureGroupModal').modal('hide');
                            // Zorunlu seçimler sekmesini yenile
                            loadProductFeatures(currentProductId);
                        } else {
                            showToast(res.message || 'Ekleme hatası!', 'danger');
                        }
                    }, 'json');
                }

                function addNewGroup() {
                    const name = prompt("Grup Adı (örn: Pişme Derecesi, İçecek Boyu):");
                    if (!name) return;
                    const mandatory = confirm("Bu grup zorunlu olsun mu?");
                    
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'add_feature_group',
                        product_id: currentProductId,
                        group_name: name,
                        is_mandatory: mandatory ? 1 : 0
                    }, function(res) {
                        if (res.success) {
                            showToast('Grup eklendi! Şimdi seçenekleri ekleyin', 'success');
                            loadFeatureGroups(); // yenile
                        }
                    });
                }

                function addOption(groupId) {
                    const name = prompt("Seçenek adı:");
                    const price = prompt("Ek fiyat (0 = ücretsiz):", "0") || 0;
                    const stock = prompt("Stok (0 = sınırsız):", "0") || 0;

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'add_feature_option',
                        product_id: currentProductId,
                        group_id: groupId,
                        name: name,
                        price: price,
                        stock_quantity: stock
                    }, function(res) {
                        if (res.success) showToast('Seçenek eklendi!', 'success');
                        loadFeatureGroups();
                    });
                }

                function loadFeatureGroups() {
                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'get_feature_groups_with_options',
                        product_id: currentProductId
                    }, function(res) {
                        let html = '';
                        if (res.groups.length === 0) {
                            html = '<p class="text-center text-muted">Henüz grup eklenmemiş</p>';
                        } else {
                            res.groups.forEach(g => {
                                const badge = g.is_mandatory ? '<span class="badge bg-danger">Zorunlu</span>' : '';
                                html += `
                                <div class="card mb-3">
                                    <div class="card-header d-flex justify-content-between">
                                        <strong>${g.name}</strong> ${badge}
                                        <button class="btn btn-sm btn-success" onclick="addOption(${g.id})">+ Seçenek Ekle</button>
                                    </div>
                                    <div class="list-group list-group-flush">
                                `;
                                g.options.forEach(opt => {
                                    const price = opt.additional_price > 0 ? `+${opt.additional_price} ₺` : '';
                                    html += `<div class="list-group-item">${opt.name} ${price} <small class="text-muted">(Stok: ${opt.stock_quantity})</small></div>`;
                                });
                                html += `</div></div>`;
                            });
                        }
                        $('#featureGroupsContainer').html(html);
                    });
                }

                function openExtraModal(extra = null) {
                    currentProductId = $('#viewProductModal').data('product-id');
                    const modal = $('#extraModal');
                    const form = document.getElementById('extraForm');
                    form.reset();
                    form.querySelector('[name="extra_id"]').value = '';

                    if (extra) {
                        form.querySelector('[name="extra_id"]').value = extra.id;
                        form.querySelector('[name="price"]').value = extra.price || 0;
                        form.querySelector('[name="quantity"]').value = extra.quantity || 1;
                        form.querySelector('[name="unit"]').value = extra.unit || 'adet';
                        form.querySelector('[name="is_active"]').checked = extra.is_active == 1;
                    }

                    // Malzemeleri yükle
                    $.post('menu_management.php', { ajax:1, action:'get_ingredients' }, function(res) {
                        const select = form.querySelector('[name="ingredient_id"]');
                        select.innerHTML = '<option value="">Malzeme Seç</option>';
                        if (res.success) {
                            res.ingredients.forEach(i => {
                                const opt = document.createElement('option');
                                opt.value = i.id;
                                opt.textContent = i.name;
                                if (extra && extra.ingredient_id == i.id) opt.selected = true;
                                select.appendChild(opt);
                            });
                        }
                    });

                    modal.modal('show');
                }

                $('#extraForm').on('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    formData.append('action', 'save_extra');
                    formData.append('product_id', currentProductId);

                    $.ajax({
                        url: 'menu_management.php',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(res) {
                            if (res.success) {
                                showToast('Ekstra malzeme kaydedildi!', 'success');
                                
                                // MODAL KAPANMASIN!
                                // Sadece formu temizle ve select'i tekrar doldur
                                const form = document.getElementById('extraForm');
                                form.reset();
                                form.querySelector('[name="extra_id"]').value = '';
                                form.querySelector('[name="price"]').value = '0';
                                form.querySelector('[name="quantity"]').value = '1';
                                form.querySelector('[name="unit"]').value = 'adet';
                                form.querySelector('[name="is_active"]').checked = true;

                                // Malzemeleri tekrar yükle (select güncel kalsın)
                                const select = form.querySelector('[name="ingredient_id"]');
                                select.innerHTML = '<option value="">Yükleniyor...</option>';
                                $.post('menu_management.php', { ajax:1, action:'get_ingredients' }, function(res2) {
                                    select.innerHTML = '<option value="">Malzeme Seç</option>';
                                    if (res2.success) {
                                        res2.ingredients.forEach(i => {
                                            const opt = document.createElement('option');
                                            opt.value = i.id;
                                            opt.textContent = i.name + ' (Stok: ' + i.stock_quantity + ')';
                                            select.appendChild(opt);
                                        });
                                    }
                                });

                                // EKSTRA LİSTESİNİ YENİLE → TAB GÜNCEL OLSUN!
                                loadExtrasToTab(res.extras || []); // Eğer backend yeni listeyi dönerse daha iyi ama şimdilik yeniden çekelim
                                $.post('menu_management.php', {
                                    ajax: 1,
                                    action: 'get_extras',
                                    product_id: currentProductId
                                }, function(newRes) {
                                    if (newRes.success) {
                                        loadExtrasToTab(newRes.extras);
                                    }
                                });

                            } else {
                                showToast('Hata: ' + (res.message || 'Kaydedilemedi'), 'danger');
                            }
                        },
                        error: function() {
                            showToast('Sunucu hatası!', 'danger');
                        }
                    });
                });

                function deleteExtra(id) {
                    if (!confirm('Bu ekstra malzemeyi silmek istediğine emin misin?')) return;

                    $.post('menu_management.php', {
                        ajax: 1,
                        action: 'delete_extra',
                        extra_id: id
                    }, function(res) {
                        if (res.success) {
                            showToast('Ekstra silindi!', 'success');
                            loadExtras();
                        }
                    });
                }

            </script>
        </body>
    </html>
    <?php ob_end_flush(); ?>