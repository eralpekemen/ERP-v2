<?php
function get_business_info($key) {
    $business_info = [
        'name' => 'SABL Restorant',
        'address' => 'Örnek Mah. 123 Sk. No:4, İstanbul',
        'phone' => '+90 212 123 45 67',
        'tax_number' => '1234567890'
    ];
    return $business_info[$key] ?? '';
}
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function update_exchange_rates() {
    global $db;
   
    // 1 saat içinde güncellendiyse çık
    $stmt = $db->prepare("SELECT 1 FROM exchange_rates WHERE last_updated > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1");
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) return true; // Zaten güncel

    $url = "https://www.tcmb.gov.tr/kurlar/today.xml";
    $xml = @simplexml_load_file($url);
    if (!$xml) {
        error_log("TCMB API erişilemedi: $url");
        return false;
    }

    $rates = [];
    foreach ($xml->Currency as $currency) {
        $code = (string)$currency['CurrencyCode'];
        if (in_array($code, ['USD', 'EUR'])) {
            $rate = (float)$currency->ForexBuying;
            if ($rate > 0) $rates[$code] = $rate;
        }
    }

    if (empty($rates)) return false;

    $db->query("DELETE FROM exchange_rates WHERE currency IN ('USD', 'EUR')");
    $stmt = $db->prepare("INSERT INTO exchange_rates (currency, rate, last_updated) VALUES (?, ?, NOW())");
    foreach ($rates as $cur => $rate) {
        $stmt->bind_param("sd", $cur, $rate);
        $stmt->execute();
    }
    return true;
}

function get_exchange_rate($currency) {
    global $db;
    
    $stmt = $db->prepare("SELECT rate FROM exchange_rates WHERE currency = ?");
    $stmt->bind_param("s", $currency);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['rate'] ?? null;
}

function get_current_branch() {
    if (isset($_SESSION['branch_id']) && is_numeric($_SESSION['branch_id'])) {
        return intval($_SESSION['branch_id']);
    }
    // Fallback: If no branch_id in session, log error and redirect to login
    error_log("get_current_branch: No branch_id found in session");
    header("Location: login.php");
    exit;
}
function generateInvoiceContent($payment_details, $discount, $sale_id) {
    global $db;
    $branch_id = get_current_branch();
    $personnel_name = $_SESSION['personnel_name'] ?? 'Bilinmeyen Kasiyer';
    $customer_id = $_POST['customer_id'] ?? 1;

    // Satış öğelerini al
    $query = "SELECT si.product_id, si.quantity, si.unit_price, si.notes, p.name 
              FROM sale_items si 
              LEFT JOIN products p ON si.product_id = p.id 
              WHERE si.sale_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Müşteri adı
    $query = "SELECT name FROM customers WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer_name = $stmt->get_result()->fetch_assoc()['name'] ?? 'Misafir';

    // Masa bilgisi
    $query = "SELECT t.number FROM tables t JOIN sales s ON t.id = s.table_id WHERE s.id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $table_number = $stmt->get_result()->fetch_assoc()['number'] ?? 'Bilinmeyen Masa';

    // Fiş içeriği
    $invoiceContent = '
        <div class="invoice-container">
            <div class="invoice-header">
                <img src="assets/img/logo.png" alt="Logo" style="max-width: 100px;">
                <h2>' . htmlspecialchars(get_business_info('name')) . '</h2>
                <p><strong>Adres:</strong> ' . htmlspecialchars(get_business_info('address')) . '</p>
                <p><strong>Telefon:</strong> ' . htmlspecialchars(get_business_info('phone')) . '</p>
                <p><strong>Vergi No:</strong> ' . htmlspecialchars(get_business_info('tax_number')) . '</p>
                <p><strong>Sipariş No:</strong> #' . $sale_id . '</p>
                <p><strong>Tarih:</strong> ' . date('d.m.Y H:i') . '</p>
                <p><strong>Masa:</strong> ' . htmlspecialchars($table_number) . '</p>
                <p><strong>Kasiyer:</strong> ' . htmlspecialchars($personnel_name) . '</p>
                <p><strong>Müşteri:</strong> ' . htmlspecialchars($customer_name) . '</p>
            </div>
            <hr>
            <h4>Ürünler</h4>
            <table class="invoice-table">
                <tr>
                    <th>Ürün</th>
                    <th>Adet</th>
                    <th>Birim Fiyat</th>
                    <th>Notlar</th>
                    <th>Toplam</th>
                </tr>';

    $subtotal = 0;
    foreach ($items as $item) {
        $itemTotal = $item['unit_price'] * $item['quantity'];
        $subtotal += $itemTotal;
        $invoiceContent .= '
                <tr>
                    <td>' . htmlspecialchars($item['name']) . '</td>
                    <td>' . $item['quantity'] . '</td>
                    <td>' . number_format($item['unit_price'], 2) . ' TL</td>
                    <td>' . htmlspecialchars($item['notes'] ?? '-') . '</td>
                    <td>' . number_format($itemTotal, 2) . ' TL</td>
                </tr>';
    }

    $tax = $subtotal * 0.06;
    $commission = 0;
    foreach ($payment_details as $payment) {
        $amount_in_tl = $payment['currency'] !== 'TL' ? $payment['amount'] * get_exchange_rate($payment['currency']) : $payment['amount'];
        $commission += $amount_in_tl * ($payment['commission_rate'] / 100);
    }
    $total = $subtotal + $tax + $commission - $discount;

    $invoiceContent .= '
            </table>
            <hr>
            <div class="invoice-summary">
                <p><strong>Alt Toplam:</strong> ' . number_format($subtotal, 2) . ' TL</p>
                <p><strong>Vergi (%6):</strong> ' . number_format($tax, 2) . ' TL</p>
                <p><strong>Komisyon:</strong> ' . number_format($commission, 2) . ' TL</p>
                <p><strong>İndirim:</strong> ' . number_format($discount, 2) . ' TL</p>
                <p><strong>Genel Toplam:</strong> ' . number_format($total, 2) . ' TL</p>
            </div>
            <hr>
            <h4>Ödeme Detayları</h4>
            <table class="invoice-table">
                <tr>
                    <th>Ödeme Yöntemi</th>
                    <th>Tutar</th>
                    <th>Para Birimi</th>
                    <th>Komisyon</th>
                </tr>';

    foreach ($payment_details as $payment) {
        $method_query = "SELECT name FROM payment_methods WHERE id = ?";
        $method_stmt = $db->prepare($method_query);
        $method_stmt->bind_param("i", $payment['method_id']);
        $method_stmt->execute();
        $method_name = $method_stmt->get_result()->fetch_assoc()['name'] ?? 'Bilinmeyen Yöntem';
        $amount_in_tl = $payment['currency'] !== 'TL' ? $payment['amount'] * get_exchange_rate($payment['currency']) : $payment['amount'];
        $commission = $amount_in_tl * ($payment['commission_rate'] / 100);
        $invoiceContent .= '
                <tr>
                    <td>' . htmlspecialchars($method_name) . '</td>
                    <td>' . number_format($payment['amount'], 2) . '</td>
                    <td>' . htmlspecialchars($payment['currency']) . '</td>
                    <td>' . number_format($commission, 2) . ' TL</td>
                </tr>';
    }

    $invoiceContent .= '
            </table>
            <hr>
            <div class="invoice-qr" style="text-align: center; margin-top: 10px;">
                <div id="qrcode"></div>
                <p><strong>Sipariş Kodu:</strong> #' . $sale_id . '</p>
            </div>
        </div>';

    return $invoiceContent;
}
// common.php sonuna ekle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_exchange_rates_ajax') {
    header('Content-Type: application/json');
    if (update_exchange_rates()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'API erişilemedi!']);
    }
    exit;
}

function update_exchange_rates_with_error() {
    global $db;
    
    $url = 'https://www.tcmb.gov.tr/kurlar/today.xml';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $xml = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $debug = "HTTP: $httpCode\ncURL Error: $curlError\nXML Length: " . strlen($xml) . "\n\n--- XML İÇERİĞİ ---\n" . htmlspecialchars(substr($xml, 0, 2000)) . (strlen($xml) > 2000 ? "\n... (kesildi)" : '');
    
    if ($xml === false || $httpCode !== 200) {
        error_log("TCMB API Hatası: $debug");
        return ['success' => false, 'error' => "Bağlantı hatası: HTTP $httpCode", 'debug' => $debug];
    }
    
    // Encoding düzelt (TCMB ISO-8859-9 kullanır)
    if (!mb_check_encoding($xml, 'UTF-8')) {
        $xml = mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-9');
    }
    
    libxml_use_internal_errors(true);
    $data = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    $libxmlErrors = libxml_get_errors();
    libxml_clear_errors();
    
    if ($data === false) {
        $errorMsg = "XML ayrıştırma hatası: " . implode('; ', array_map(fn($e) => $e->message, $libxmlErrors));
        error_log($errorMsg);
        return ['success' => false, 'error' => 'XML ayrıştırma hatası', 'debug' => $debug . "\nLibXML: $errorMsg"];
    }
    
    $currencies = ['USD', 'EUR'];
    $found = 0;
    $foundRates = [];
    
    foreach ($data->Currency as $curr) {
        $code = (string)$curr['CurrencyCode'];
        if (in_array($code, $currencies)) {
            $rate = (float)$curr->ForexSelling;
            if ($rate > 0) {
                $query = "INSERT INTO exchange_rates (currency, rate, last_updated) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE rate = VALUES(rate), last_updated = NOW()";
                $stmt = $db->prepare($query);
                $stmt->bind_param("sd", $code, $rate);
                $stmt->execute();
                $stmt->close();
                $found++;
                $foundRates[$code] = $rate;
            }
        }
    }
    
    if ($found < 2) {
        return ['success' => false, 'error' => "$missing bulunamadı", 'debug' => $debug . "\nBulunanlar: " . json_encode($foundRates)];
    }
    
    return ['success' => true];
}
function table_exists($table) {
    global $db;
    
    // ? yerine doğrudan değer koy (güvenli çünkü sadece tablo adı)
    $safe_table = $db->real_escape_string($table);
    $query = "SHOW TABLES LIKE '$safe_table'";
    
    $result = $db->query($query);
    return $result && $result->num_rows > 0;
}
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // PHP 8.2+ uyumlu: $diff->w manuel hesapla
    $diff_days = $diff->days;
    $weeks = floor($diff_days / 7);
    $days = $diff_days % 7;

    $string = [];
    if ($weeks > 0) $string[] = $weeks . ' hafta';
    if ($days > 0) $string[] = $days . ' gün';
    if ($diff->h > 0) $string[] = $diff->h . ' saat';
    if ($diff->i > 0) $string[] = $diff->i . ' dakika';
    if ($diff->s > 0 && empty($string)) $string[] = $diff->s . ' saniye';

    if (!$full && !empty($string)) {
        $string = array_slice($string, 0, 1);
    }

    return !empty($string) ? implode(', ', $string) . ' önce' : 'şimdi';
}
function log_stock_movement($db, $type, $item_type, $item_id, $quantity, $old_stock, $new_stock, $reason = null) {
    $branch_id = get_current_branch();
    $created_by = $_SESSION['personnel_id'] ?? 0;

    $q = "INSERT INTO stock_movements 
          (type, item_type, item_id, quantity, old_stock, new_stock, reason, created_by, branch_id) 
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $s = $db->prepare($q);
    if (!$s) return false;
    $s->bind_param("ssiddssii", $type, $item_type, $item_id, $quantity, $old_stock, $new_stock, $reason, $created_by, $branch_id);
    return $s->execute();
}

function get_personnel_by_id($id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function calculate_sales_points($personnel_id) {
    global $db;
    $stmt = $db->prepare("SELECT SUM(points) AS total FROM personnel_points WHERE personnel_id = ?");
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

function get_notifications_for_personnel($personnel_id, $limit = 10) {
    global $db;
    
    // Tablo yapısını kontrol et
    $result = $db->query("SHOW COLUMNS FROM notifications LIKE 'personnel_id'");
    $has_personnel_id = $result && $result->num_rows > 0;

    if ($has_personnel_id) {
        $stmt = $db->prepare("
            SELECT message, created_at 
            FROM notifications 
            WHERE personnel_id = ? OR personnel_id IS NULL 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("ii", $personnel_id, $limit);
    } else {
        // personel_id sütunu yoksa → sadece genel bildirimler
        $stmt = $db->prepare("
            SELECT message, created_at 
            FROM notifications 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
    }

    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_personnel_tasks($personnel_id, $limit = 10) {
    global $db;
    $result = $db->query("SHOW COLUMNS FROM personnel_tasks LIKE 'personnel_id'");
    if (!$result || $result->num_rows == 0) {
        return []; // Tablo yoksa boş dön
    }
    $stmt = $db->prepare("
        SELECT title, status 
        FROM personnel_tasks 
        WHERE personnel_id = ? 
        ORDER BY assigned_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $personnel_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_leave_requests($personnel_id, $limit = 10) {
    global $db;
    $result = $db->query("SHOW COLUMNS FROM leave_requests LIKE 'personnel_id'");
    if (!$result || $result->num_rows == 0) {
        return [];
    }
    $stmt = $db->prepare("
        SELECT start_date, end_date, status 
        FROM leave_requests 
        WHERE personnel_id = ? 
        ORDER BY requested_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $personnel_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_personnel_documents($personnel_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM personnel_documents WHERE personnel_id = ? ORDER BY uploaded_at DESC");
    $stmt->bind_param("i", $personnel_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function upload_document($personnel_id, $file, $type) {
    $target_dir = "uploads/documents/";
    $target_file = $target_dir . basename($file["name"]);
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        global $db;
        $stmt = $db->prepare("INSERT INTO personnel_documents (personnel_id, file_name, file_path, document_type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $personnel_id, $file["name"], $target_file, $type);
        return $stmt->execute();
    }
    return false;
}

function add_leave_request($personnel_id, $start, $end, $reason) {
    global $db;
    $stmt = $db->prepare("INSERT INTO leave_requests (personnel_id, start_date, end_date, reason) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $personnel_id, $start, $end, $reason);
    return $stmt->execute();
}

function update_personnel_profile($id, $username, $photo = null) {
    global $db;
    $query = "UPDATE personnel SET username = ?";
    if ($photo) $query .= ", profile_photo = '$photo'";
    $query .= " WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("si", $username, $id);
    return $stmt->execute();
}

function upload_photo($file, $personnel_id) {
    $target_dir = "uploads/profiles/";
    $target_file = $target_dir . $personnel_id . '_' . basename($file["name"]);
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $target_file;
    }
    return null;
}

function get_all_branches() {
    global $db;
    $result = $db->query("SELECT * FROM branches");
    return $result->fetch_all(MYSQLI_ASSOC);
}

function get_branch_name($id) {
    global $db;
    $stmt = $db->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['name'] ?? 'Bilinmiyor';
}
// Get active shift for the branch and personnel
function get_active_shift($branch_id, $personnel_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT id, opening_balance, closing_balance, status, start_time 
        FROM shifts 
        WHERE personnel_id = ? AND branch_id = ? AND status = 'open'
        ORDER BY start_time DESC 
        LIMIT 1
    ");
    $stmt->bind_param("ii", $personnel_id, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result) {
        $result['opening_balance'] = $result['opening_balance'] ?? 0;
        $result['closing_balance'] = $result['closing_balance'] ?? 0;
    }
    return $result;
}