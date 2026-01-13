<?php
    ob_start();
    session_start();
    require_once 'config.php';
    require_once 'functions/common.php';

    if ($_SESSION['personnel_type'] != 'admin') {
        header("Location: login.php");
        exit;
    }

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) die("ID gerekli");

    $stmt = $db->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $person = $stmt->get_result()->fetch_assoc();
    if (!$person) die("Personel bulunamadı");

    // GÜVENLİ TARİH FONKSİYONU
    function safeDate($date, $format = 'd M Y') {
        return $date && $date !== '0000-00-00' && $date !== '0000-00-00 00:00:00' ? date($format, strtotime($date)) : 'Tarih belirtilmemiş';
    }

    // GÜVENLİ SAYAÇ FONKSİYONLARI (önceki mesajdan)
    function safeCount($db, $table, $where = "1=1") {
        $check = $db->query("SHOW TABLES LIKE '$table'");
        return ($check && $check->num_rows > 0) ? ($db->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetch_row()[0] ?? 0) : 0;
    }

    function safeSum($db, $table, $column, $where = "1=1") {
        $check = $db->query("SHOW TABLES LIKE '$table'");
        return ($check && $check->num_rows > 0) ? ($db->query("SELECT COALESCE(SUM($column),0) FROM `$table` WHERE $where")->fetch_row()[0] ?? 0) : 0;
    }

    // Tüm sayaçlar
    $documents_count = safeCount($db, 'personnel_documents', "personnel_id = $id");
    $leave_count     = safeCount($db, 'personnel_leaves', "personnel_id = $id AND status = 'pending'");
    $advance_total   = safeSum($db, 'personnel_advances', 'amount', "personnel_id = $id AND status = 'paid'");
    $shift_count     = safeCount($db, 'attendance', "personnel_id = $id AND MONTH(date) = MONTH(CURDATE())");
    $asset_count     = safeCount($db, 'personnel_assets', "personnel_id = $id AND returned = 0");
    $sales_total     = safeSum($db, 'orders', 'total', "personnel_id = $id AND YEAR(created_at) = YEAR(CURDATE())");
    $points_total    = safeSum($db, 'personnel_points', 'points', "personnel_id = $id");
    $activity_count  = safeCount($db, 'activity_log', "personnel_id = $id");

    $personnel_name = $_SESSION['personnel_username'] ?? 'Yönetici';

    $action = $_POST['action'] ?? '';

    if (isset($_POST['ajax_action'])) {
        if ($_SESSION['personnel_type'] !== 'admin' && !isset($_SESSION['personnel_id'])) {
            die('Yetkisiz işlem');
        }

        $action = $_POST['ajax_action'];

        // AVANS TALEP
        if ($action == 'give_advance') {
            $amount = (float)($_POST['amount'] ?? 0);
            $reason = $db->real_escape_string($_POST['reason'] ?? 'Avans');
            if ($amount <= 0) die('Geçersiz tutar');

            $stmt = $db->prepare("INSERT INTO personnel_advances (personnel_id, amount, reason, status) VALUES (?,?,?,'pending')");
            $stmt->bind_param("ids", $id, $amount, $reason);
            echo $stmt->execute() ? 'BAŞARILI: Avans talebin alındı!' : 'Hata';
            exit;
        }

        // İZİN TALEP
        if ($action == 'request_leave') {
            $start = $_POST['start_date'];
            $end = $_POST['end_date'];
            $reason = $db->real_escape_string($_POST['reason'] ?? 'İzin');
            $days = (strtotime($end) - strtotime($start)) / 86400 + 1;

            $stmt = $db->prepare("INSERT INTO personnel_leaves (personnel_id, start_date, end_date, days, reason, status) VALUES (?,?,?,?,?,'pending')");
            $stmt->bind_param("issis", $id, $start, $end, $days, $reason);
            echo $stmt->execute() ? 'BAŞARILI: İzin talebin alındı!' : 'Hata';
            exit;
        }

        // ADMIN: AVANS ONAY/RED
        if ($action == 'advance_action' && $_SESSION['personnel_type']=='admin') {
            $aid = (int)$_POST['id'];
            $status = $_POST['status'] == 'paid' ? 'paid' : 'rejected';
            $stmt = $db->prepare("UPDATE personnel_advances SET status=?, approved_by=?, approved_at=NOW() WHERE id=?");
            $stmt->bind_param("sii", $status, $_SESSION['personnel_id'], $aid);
            echo $stmt->execute() ? 'BAŞARILI' : 'Hata';
            exit;
        }

        // ADMIN: İZİN ONAY/RED
        if ($action == 'leave_action' && $_SESSION['personnel_type']=='admin') {
            $lid = (int)$_POST['id'];
            $status = $_POST['status'] == 'approved' ? 'approved' : 'rejected';
            $stmt = $db->prepare("UPDATE personnel_leaves SET status=?, approved_by=?, approved_at=NOW() WHERE id=?");
            $stmt->bind_param("sii", $status, $_SESSION['personnel_id'], $lid);
            echo $stmt->execute() ? 'BAŞARILI' : 'Hata';
            exit;
        }

        // ZİMMET EKLE
        if ($action == 'add_asset' && $_SESSION['personnel_type']=='admin') {
            $personnel_id = (int)$_POST['personnel_id'];
            $item = $db->real_escape_string($_POST['item']);
            $serial = $db->real_escape_string($_POST['serial'] ?? '');

            $stmt = $db->prepare("INSERT INTO personnel_assets (personnel_id, item_name, serial_number) VALUES (?,?,?)");
            $stmt->bind_param("iss", $personnel_id, $item, $serial);
            $success = $stmt->execute();
            die(json_encode(['success'=>$success, 'msg'=>$success?'Zimmet eklendi':'Hata']));
            exit;
        }

        // EVRAK YÜKLEME
        if ($action == 'upload_document' && $_SESSION['personnel_type']=='admin') {
            $personnel_id = (int)$_POST['personnel_id'];
            $type_id = (int)$_POST['document_type_id'];

            if (!$_FILES['file']['size']) die(json_encode(['success'=>false,'msg'=>'Dosya seçilmedi']));

            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) die(json_encode(['success'=>false,'msg'=>'Sadece PDF/JPG/PNG']));

            $filename = $personnel_id.'_'.$type_id.'_'.time().'.'.$ext;
            $path = 'uploads/documents/'.$filename;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
                $stmt = $db->prepare("INSERT INTO personnel_documents 
                    (personnel_id, document_type_id, file_name, original_name) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE file_name=VALUES(file_name), uploaded_at=CURRENT_TIMESTAMP");
                $stmt->bind_param("iiss", $personnel_id, $type_id, $filename, $_FILES['file']['name']);
                $stmt->execute();
                die(json_encode(['success'=>true, 'msg'=>'Evrak yüklendi']));
            }
            die(json_encode(['success'=>false,'msg'=>'Dosya kaydedilemedi']));
            exit;
        }

        // YENİ HEDEF EKLE
        if ($action == 'add_target' && $_SESSION['personnel_type']=='admin') {
            $type = $_POST['target_type'];
            $value = (float)($_POST['target_value'] ?? 0);
            $points = (int)($_POST['target_points'] ?? 0);
            $year = (int)$_POST['period_year'];
            $month = $_POST['period_month'] ? (int)$_POST['period_month'] : null;
            $product_id = $type == 'product_sales' ? (int)$_POST['product_id'] : null;

            $stmt = $db->prepare("INSERT INTO personnel_targets 
                (personnel_id, target_type, product_id, target_value, target_points, period_year, period_month, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE target_value=VALUES(target_value), target_points=VALUES(target_points)");
            $stmt->bind_param("isidiiii", $id, $type, $product_id, $value, $points, $year, $month, $_SESSION['personnel_id']);
            echo $stmt->execute() ? 'BAŞARILI: Hedef tanımlandı!' : 'Hata';
            exit;
        }

        // HEDEF SİL
        if ($action == 'delete_target' && $_SESSION['personnel_type']=='admin') {
            $tid = (int)$_POST['id'];
            $db->query("DELETE FROM personnel_targets WHERE id = $tid AND personnel_id = $id");
            echo 'BAŞARILI: Hedef silindi!';
            exit;
        }

        // AKTİVİTELERİ YÜKLE - TOAST UYUMLU + JSON
        if ($action == 'load_activities') {
            $page = (int)($_POST['page'] ?? 0);
            $limit = 15;
            $offset = $page * $limit;

            $search = $db->real_escape_string($_POST['search'] ?? '');
            $filter = $_POST['filter'] ?? '';

            $where = "personnel_id = $id";
            if ($search) $where .= " AND (title LIKE '%$search%' OR description LIKE '%$search%)";
            if ($filter) $where .= " AND type = '$filter'";

            $total = $db->query("SELECT COUNT(*) FROM activity_log WHERE $where")->fetch_row()[0];
            $activities = $db->query("SELECT * FROM activity_log WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");

            $html = '';
            $hasMore = ($offset + $limit < $total);

            if ($activities->num_rows == 0) {
                $html = '';
                $hasMore = false;
            } else {
                while($a = $activities->fetch_assoc()) {
                    // İkon ve renk belirleme
                    $icon = 'fa-bell';
                    $color = 'muted';

                    if ($a['type'] == 'order') { $icon = 'fa-coffee'; $color = 'success'; }
                    elseif ($a['type'] == 'login') { $icon = 'fa-sign-in-alt'; $color = 'primary'; }
                    elseif ($a['type'] == 'advance') { $icon = 'fa-hand-holding-usd'; $color = 'warning'; }
                    elseif ($a['type'] == 'leave') { $icon = 'fa-calendar-alt'; $color = 'info'; }
                    elseif ($a['type'] == 'document') { $icon = 'fa-file-upload'; $color = 'secondary'; }
                    elseif ($a['type'] == 'point') { $icon = 'fa-star'; $color = 'warning'; }

                    $html .= '<a href="javascript:void(0)" class="list-group-item list-group-item-action d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="avatar avatar-sm bg-soft-'.$color.' text-'.$color.' rounded-circle">
                                        <i class="fa '.$icon.'"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong>'.htmlspecialchars($a['title']).'</strong>
                                            <small class="d-block text-muted">'.htmlspecialchars($a['description']).'</small>
                                        </div>
                                        <small class="text-muted">'.date('d.m.Y H:i', strtotime($a['created_at'])).'</small>
                                    </div>
                                </div>
                              </a>';
                }
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html' => $html,
                'hasMore' => $hasMore
            ]);
            exit;
        }

        if ($action == 'update_salary' && $_SESSION['personnel_type']=='admin') {
            $is_hourly = (int)$_POST['is_hourly'];
            $amount = (float)$_POST['amount'];
            
            if ($is_hourly) {
                $stmt = $db->prepare("UPDATE personnel SET is_hourly=1, hourly_rate=?, base_salary=0 WHERE id=?");
            } else {
                $stmt = $db->prepare("UPDATE personnel SET is_hourly=0, base_salary=?, hourly_rate=0 WHERE id=?");
            }
            $stmt->bind_param("di", $amount, $id);
            echo $stmt->execute() ? 'BAŞARILI: Maaş bilgileri güncellendi!' : 'Hata';
            exit;
        }

        // VARDİYA AJAX - %100 ÇALIŞAN SON HALİ (2025)
        if ($action == 'load_shifts') {
            $personnel_id = (int)$_POST['personnel_id'];
            $dates = explode(',', $_POST['dates']);

            // Tarihleri temizle (güvenlik)
            $clean_dates = array_map('trim', $dates);
            $clean_dates = array_filter($clean_dates, function($d) {
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            });

            if (empty($clean_dates)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'msg' => 'Geçersiz tarih']);
                exit;
            }

            // Placeholder ve parametreleri hazırla
            $placeholders = str_repeat('?,', count($clean_dates) - 1) . '?';
            $types = 'i' . str_repeat('s', count($clean_dates)); // i = personnel_id, s = tarihler
            $params = array_merge([$personnel_id], $clean_dates);

            $stmt = $db->prepare("SELECT shift_date, start_time, end_time, shift_type, notes 
                                FROM personnel_shifts 
                                WHERE personnel_id = ? AND shift_date IN ($placeholders)");

            if (!$stmt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'msg' => 'Sorgu hatası']);
                exit;
            }

            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $shifts = [];
            while ($row = $result->fetch_assoc()) {
                $shifts[$row['shift_date']] = [
                    'start_time' => substr($row['start_time'], 0, 5),
                    'end_time'   => substr($row['end_time'], 0, 5),
                    'shift_type' => $row['shift_type'],
                    'notes'      => $row['notes'] ?? ''
                ];
            }

            // JSON dön, hata yok!
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'shifts'  => $shifts,
                'dates'   => $clean_dates
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action == 'add_shift' && $_SESSION['personnel_type']=='admin') {
            $date = $_POST['shift_date'];
            $start = $_POST['start_time'];
            $end = $_POST['end_time'];
            $type = $_POST['shift_type'];
            $notes = $_POST['notes'] ?? '';

            $stmt = $db->prepare("INSERT INTO personnel_shifts (personnel_id, shift_date, start_time, end_time, shift_type, notes, created_by) 
                                VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), shift_type=VALUES(shift_type)");
            $stmt->bind_param("isssssi", $id, $date, $start, $end, $type, $notes, $_SESSION['personnel_id']);
            echo $stmt->execute() ? 'BAŞARILI' : 'HATA';
            exit;
        }

        if ($action == 'save_shift' && $_SESSION['personnel_type'] == 'admin') {
            $date = $_POST['shift_date'];
            $start = $_POST['start_time'];
            $end = $_POST['end_time'];
            $type = $_POST['shift_type'];
            $notes = $_POST['notes'] ?? '';

            $stmt = $db->prepare("INSERT INTO personnel_shifts (personnel_id, shift_date, start_time, end_time, shift_type, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                start_time = VALUES(start_time),
                                end_time = VALUES(end_time),
                                shift_type = VALUES(shift_type),
                                notes = VALUES(notes)");
            $stmt->bind_param("isssssi", $id, $date, $start, $end, $type, $notes, $_SESSION['personnel_id']);
            $success = $stmt->execute();

            header('Content-Type: application/json');
            echo json_encode(['success' => $success]);
            exit;
        }

        // VARDİYA SİL
        if ($action == 'delete_shift' && $_SESSION['personnel_type'] == 'admin') {
            $date = $_POST['shift_date'];
            $stmt = $db->prepare("DELETE FROM personnel_shifts WHERE personnel_id = ? AND shift_date = ?");
            $stmt->bind_param("is", $id, $date);
            $success = $stmt->execute();

            header('Content-Type: application/json');
            echo json_encode(['success' => $success]);
            exit;
        }

        if ($action == 'bulk_shift' && $_SESSION['personnel_type'] == 'admin') {
            $dates = explode(',', $_POST['dates']);
            $type = $_POST['shift_type'];
            $personnel_id = (int)$_POST['personnel_id'];

            $defaults = [
                'morning' => ['09:00:00', '18:00:00'],
                'afternoon' => ['14:00:00', '23:00:00'],
                'night' => ['23:00:00', '09:00:00'],
                'off' => ['00:00:00', '00:00:00']
            ];

            $start = $defaults[$type][0] ?? '09:00:00';
            $end = $defaults[$type][1] ?? '18:00:00';

            $stmt = $db->prepare("INSERT INTO personnel_shifts (personnel_id, shift_date, start_time, end_time, shift_type, created_by)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), shift_type=VALUES(shift_type)");

            foreach ($dates as $date) {
                $date = trim($date);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $stmt->bind_param("issssi", $personnel_id, $date, $start, $end, $type, $_SESSION['personnel_id']);
                    $stmt->execute();
                }
            }

            echo json_encode(['success' => true]);
            exit;
        }

        if ($action == 'get_payroll') {
            $personnel_id = (int)$_POST['personnel_id'];
            $month = $_POST['month']; // 2025-11 formatında

            // 1) Vardiyalardan toplam çalışma saati
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(
                        CASE 
                            WHEN shift_type = 'off' THEN 0
                            ELSE TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600 
                        END
                    ), 0) AS toplam_saat
                FROM personnel_shifts 
                WHERE personnel_id = ? 
                AND DATE_FORMAT(shift_date, '%Y-%m') = ?
            ");
            $stmt->bind_param("is", $personnel_id, $month);
            $stmt->execute();
            $calisma_saati = $stmt->get_result()->fetch_assoc()['toplam_saat'] ?? 0;

            // 2) Normal mesai: 171 saat (aylık ortalama)
            $normal_saat = 171;
            $fazla_saat = max(0, $calisma_saati - $normal_saat);

            // 3) Saatlik ücret hesapla
            $saatlik_ucret = $person['is_hourly'] 
                ? $person['hourly_rate'] 
                : ($person['base_salary'] / $normal_saat);

            // 4) Ücretler
            $brut_maas = $person['is_hourly'] 
                ? ($calisma_saati * $saatlik_ucret)
                : $person['base_salary'];

            $fazla_ucret = $fazla_saat * $saatlik_ucret * 1.5; // %150

            // 5) Avans toplamı
            $avans_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM personnel_advances 
                                        WHERE personnel_id = ? AND status = 'paid' 
                                        AND DATE_FORMAT(paid_at, '%Y-%m') = ?");
            $avans_stmt->bind_param("is", $personnel_id, $month);
            $avans_stmt->execute();
            $avans = $avans_stmt->get_result()->fetch_row()[0];

            // 6) Net ödenecek
            $net = $brut_maas + $fazla_ucret - $avans;

            // JSON dön
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => [
                    'ay' => $month,
                    'calisma_saati' => number_format($calisma_saati, 1),
                    'fazla_mesai' => number_format($fazla_saat, 1),
                    'brut_maas' => number_format($brut_maas, 2),
                    'fazla_ucret' => number_format($fazla_ucret, 2),
                    'avans' => number_format($avans, 2),
                    'net' => number_format($net, 2)
                ]
            ]);
            exit;
        }

        // STOKTAKİ DEMİRBAŞLARI GETİR
        if ($action == 'get_available_assets') {
            $stmt = $db->query("SELECT a.* FROM assets a 
                                LEFT JOIN asset_assignments aa ON a.id = aa.asset_id AND aa.returned_at IS NULL
                                WHERE aa.id IS NULL AND a.status = 'stokta'
                                ORDER BY a.asset_code");
            $assets = [];
            while($row = $stmt->fetch_assoc()) $assets[] = $row;

            header('Content-Type: application/json');
            echo json_encode(['assets' => $assets]);
            exit;
        }

        // PERSONELE ZİMMETLENMİŞ DEMİRBAŞLARI GETİR
        if ($action == 'load_assigned_assets') {
            $pid = (int)$_POST['personnel_id'];
            $stmt = $db->prepare("SELECT a.*, aa.assigned_at, aa.returned_at, aa.id as assignment_id
                                FROM asset_assignments aa
                                JOIN assets a ON aa.asset_id = a.id
                                WHERE aa.personnel_id = ?
                                ORDER BY aa.assigned_at DESC");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                echo json_encode(['html' => '<p class="text-center text-muted">Henüz zimmetli demirbaş yok.</p>', 'count' => 0]);
                exit;
            }

            $html = '<table class="table table-sm table-hover">
                <thead class="table-dark"><tr>
                    <th>Kod</th><th>Demirbaş</th><th>Zimmet</th><th>Teslim</th><th>Durum</th><th></th>
                </tr></thead><tbody>';

            while ($row = $result->fetch_assoc()) {
                $status = $row['returned_at'] 
                    ? '<span class="badge bg-success">Teslim Alındı</span>' 
                    : '<span class="badge bg-warning text-dark">Zimmetli</span>';
                
                $btn = $row['returned_at'] ? '' : 
                    '<button class="btn btn-danger btn-sm return-asset" data-id="'.$row['assignment_id'].'">
                        <i class="fa fa-undo"></i> Teslim Al
                    </button>';

                $brand_model = trim(($row['brand'] ?? '').' '.($row['model'] ?? ''));
                $brand_model = $brand_model ? " - $brand_model" : '';

                $html .= "<tr>
                    <td><strong>{$row['asset_code']}</strong></td>
                    <td>{$row['name']}$brand_model</td>
                    <td>".date('d.m H:i', strtotime($row['assigned_at']))."</td>
                    <td>".($row['returned_at'] ? date('d.m H:i', strtotime($row['returned_at'])) : '-')."</td>
                    <td>$status</td>
                    <td>$btn</td>
                </tr>";
            }
            $html .= '</tbody></table>';

            // JSON OLARAK DÖN!
            header('Content-Type: application/json');
            echo json_encode(['html' => $html, 'count' => $result->num_rows]);
            exit;
        }
        // YENİ ZİMMET
        if ($action == 'assign_asset' && $_SESSION['personnel_type']=='admin') {
            $asset_id = (int)$_POST['asset_id'];
            $pid = (int)$_POST['personnel_id'];
            $notes = $_POST['notes'] ?? '';

            // Demirbaş stokta mı kontrol et
            $check = $db->query("SELECT id FROM assets WHERE id=$asset_id AND status='stokta'")->num_rows;
            if($check == 0) { echo json_encode(['success'=>false]); exit; }

            $stmt = $db->prepare("INSERT INTO asset_assignments (asset_id, personnel_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $asset_id, $pid, $_SESSION['personnel_id']);
            $success = $stmt->execute();

            if($success) {
                $db->query("UPDATE assets SET status='zimmetli' WHERE id=$asset_id");
            }

            echo json_encode(['success' => $success]);
            exit;
        }

        // TESLİM AL
        if ($action == 'return_asset' && $_SESSION['personnel_type']=='admin') {
            $aid = (int)$_POST['assignment_id'];
            $stmt = $db->prepare("UPDATE asset_assignments SET returned_at=NOW() WHERE id=? AND returned_at IS NULL");
            $stmt->bind_param("i", $aid);
            $success = $stmt->execute();

            if($success) {
                $asset_id = $db->query("SELECT asset_id FROM asset_assignments WHERE id=$aid")->fetch_row()[0];
                $db->query("UPDATE assets SET status='stokta' WHERE id=$asset_id");
            }
            echo json_encode(['success' => $success]);
            exit;
        }

    }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($person['name']); ?> • Personel Profili</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <style>
        .bulk-checkbox {
            width: 18px;
            height: 18px;
        }
        .shift-cell:hover .bulk-checkbox {
            opacity: 1 !important;
        }
        .bulk-checkbox {
            opacity: 0.3;
            transition: opacity 0.2s;
        }
        .shift-cell{
            height:60px!important;
        }
    </style>
</head>
<body>

<div id="app" class="app">
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>

    <div id="content" class="app-content p-0">
        <div class="profile">

            <div class="profile-header">
                <div class="profile-header-cover"></div>
                <div class="profile-header-content">
                    <div class="profile-header-img">
                        <img src="assets/img/user/<?php echo $person['id']; ?>.jpg" onerror="this.src='https://placehold.co/150'" alt="Profil">
                    </div>
                    <ul class="profile-header-tab nav nav-tabs nav-tabs-v2">
                        <li class="nav-item"><a href="#profile-info" class="nav-link active" data-bs-toggle="tab"><div class="nav-field">Bilgiler</div></a></li>
                        <li class="nav-item"><a href="#profile-documents" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Evraklar</div><div class="nav-value"><?php echo $documents_count; ?></div></a></li>
                        <li class="nav-item"><a href="#profile-leave" class="nav-link" data-bs-toggle="tab"><div class="nav-field">İzin Takibi</div><div class="nav-value"><?php echo $leave_count; ?></div></a></li>
                        <li class="nav-item"><a href="#profile-salary" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Maaş & Avans</div><div class="nav-value">₺<?php echo number_format($advance_total); ?></div></a></li>
                        <li class="nav-item"><a href="#profile-shift" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Vardiya</div><div class="nav-value"><?php echo $shift_count; ?></div></a></li>
                        <li class="nav-item"><a href="#profile-assets" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Zimmet</div><div class="nav-value"><span id="assetCount">0</span></div></a></li>
                        <li class="nav-item"><a href="#profile-sales" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Satış</div><div class="nav-value">₺<?php echo number_format($sales_total,0,'','.'); ?></div></a></li>
                        <li class="nav-item"><a href="#profile-points" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Puan</div><div class="nav-value"><?php echo $points_total; ?></div></a></li>
                        <li class="nav-item"><a href="#profile-activity" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Aktivite</div><div class="nav-value"><?php echo $activity_count; ?></div></a></li>
                        <li class="nav-item"><a href="#profile-targets" class="nav-link" data-bs-toggle="tab"><div class="nav-field">Görev & Hedef</div><div class="nav-value"><i class="fa fa-bullseye text-warning"></i></div></a></li>
                    </ul>
                </div>
            </div>

            <div class="profile-container">
                <div class="profile-sidebar">
                    <div class="desktop-sticky-top">
                        <h4><?php echo htmlspecialchars($person['name']); ?></h4>
                        <div class="fw-500 mb-3 text-muted mt-n2">@<?php echo htmlspecialchars($person['username']); ?></div>
                        <p><?php echo ucwords(str_replace('_', ' ', $person['personnel_type'])); ?> • 
                           <?php echo $person['is_logged_in'] ? '<span class="text-success">● Çevrimiçi</span>' : '<span class="text-muted">○ Çevrimdışı</span>'; ?>
                        </p>
                        <div class="mb-1"><i class="fa fa-map-marker-alt fa-fw text-muted"></i> Alçıtepe Cafe</div>
                        <div class="mb-3">
                            <i class="fa fa-calendar-alt fa-fw text-muted"></i> 
                            Katılım: <?php echo safeDate($person['created_at']); ?>
                        </div>
                        <hr class="mt-4 mb-4">
                        <div class="d-grid gap-2">
                            <a href="personnel_edit.php?id=<?php echo $person['id']; ?>" class="btn btn-outline-theme btn-sm"><i class="fa fa-edit"></i> Düzenle</a>
                            <button onclick="changePass(<?php echo $person['id']; ?>)" class="btn btn-outline-primary btn-sm"><i class="fa fa-key"></i> Şifre Değiştir</button>
                            <button onclick="deletePerson(<?php echo $person['id']; ?>)" class="btn btn-outline-danger btn-sm"><i class="fa fa-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>

                <div class="profile-content">
                    <div class="tab-content p-0">
                            <!-- BİLGİLER -->
                            <div class="tab-pane fade show active" id="profile-info">
                                <div class="card">
                                    <div class="card-body">
                                        <table class="table table-borderless mb-0">
                                            <tr><td width="140"><strong>ID</strong></td><td>#<?php echo $person['id']; ?></td></tr>
                                            <tr><td><strong>Ad Soyad</strong></td><td><?php echo htmlspecialchars($person['name']); ?></td></tr>
                                            <tr><td><strong>Kullanıcı Adı</strong></td><td>@<?php echo htmlspecialchars($person['username']); ?></td></tr>
                                            <tr><td><strong>Görev</strong></td><td><?php echo ucwords(str_replace('_', ' ', $person['personnel_type'])); ?></td></tr>
                                            <tr><td><strong>Şube</strong></td><td>Alçıtepe Cafe</td></tr>
                                            <tr><td><strong>Departman</strong></td><td><?php echo htmlspecialchars($person['department'] ?? '-'); ?></td></tr>
                                            <tr>
                                                <td><strong>Çalışma Durumu</strong></td>
                                                <td>
                                                    <span class="badge bg-<?= $person['employment_status']=='active'?'success':($person['employment_status']=='on_leave'?'warning':'danger') ?>">
                                                        <?= ['active'=>'Çalışıyor','on_leave'=>'İzinli','terminated'=>'Ayrıldı','suspended'=>'Askıya Alındı'][$person['employment_status']] ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr><td><strong>Durum</strong></td><td><?php echo $person['is_logged_in'] ? '<span class="text-success">● Çevrimiçi</span>' : '<span class="text-muted">○ Çevrimdışı</span>'; ?></td></tr>
                                            <tr><td><strong>Katılım</strong></td><td><?php echo $person['hire_date'] ? date('d.m.Y', strtotime($person['hire_date'])) : '-'; ?></td></tr>
                                            <tr>
                                                <td><strong>Telefon</strong></td>
                                                <td>
                                                    <?php if($person['phone']): ?>
                                                        <a href="tel:<?php echo htmlspecialchars($person['phone']); ?>">
                                                            <?php echo htmlspecialchars($person['phone']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>E-posta</strong></td>
                                                <td>
                                                    <?php if($person['email']): ?>
                                                        <a href="mailto:<?php echo htmlspecialchars($person['email']); ?>">
                                                            <?php echo htmlspecialchars($person['email']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>Adres</strong></td>
                                                <td>
                                                    <?php if($person['address']): ?>
                                                        <?php echo nl2br(htmlspecialchars($person['address'])); ?><br>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($person['district'] ?? ''); ?>
                                                            <?php if($person['district'] && $person['city']): ?>, <?php endif; ?>
                                                            <?php echo htmlspecialchars($person['city'] ?? ''); ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Adres belirtilmemiş</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>  

                            <!-- EVRAKLAR SEKMEsİ – %100 HATASIZ VE ÇALIŞAN SON HALİ -->
                            <div class="tab-pane fade" id="profile-documents">
                                <div class="card">
                                    <?php
                                    // 1) Zorunlu evrak sayısını ve yüklenen evrak sayısını hesapla
                                    $total_required = $db->query("SELECT COUNT(*) FROM document_types WHERE is_required = 1")->fetch_row()[0];
                                    $uploaded_count = $db->query("SELECT COUNT(*) FROM personnel_documents WHERE personnel_id = $id")->fetch_row()[0];
                                    ?>
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            Personel Evrakları 
                                            <span class="badge bg-<?php echo ($uploaded_count == $total_required && $total_required > 0) ? 'success' : 'danger'; ?>">
                                                <?php echo $uploaded_count; ?> / <?php echo $total_required; ?>
                                            </span>
                                        </h5>
                                        <button class="btn btn-sm btn-outline-theme" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                            <i class="fa fa-plus"></i> Evrak Ekle
                                        </button>
                                    </div>

                                    <div class="list-group list-group-flush">
                                        <?php
                                        $types = $db->query("SELECT * FROM document_types ORDER BY sort_order, id");
                                        while ($type = $types->fetch_assoc()):
                                            // Bu personel bu evrakı yüklemiş mi?
                                            $check = $db->query("SELECT * FROM personnel_documents WHERE personnel_id = $id AND document_type_id = {$type['id']} LIMIT 1");
                                            $doc   = $check->fetch_assoc();
                                        ?>
                                            <div class="list-group-item d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center">
                                                    <?php if ($doc): ?>
                                                        <i class="fa fa-check-circle fa-2x text-success me-3"></i>
                                                    <?php else: ?>
                                                        <i class="fa fa-times-circle fa-2x text-danger me-3"></i>
                                                    <?php endif; ?>

                                                    <div>
                                                        <div class="fw-600"><?php echo htmlspecialchars($type['name']); ?></div>
                                                        <?php if ($type['is_required']): ?>
                                                            <small class="text-danger fw-500">Zorunlu</small>
                                                        <?php else: ?>
                                                            <small class="text-muted">Tavsiye edilen</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if ($doc): ?>
                                                    <a href="uploads/documents/<?php echo $doc['file_name']; ?>" target="_blank" class="btn btn-sm btn-success">
                                                        <i class="fa fa-eye"></i> Görüntüle
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted small">Yüklenmedi</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. İZİN TAKİBİ -->
                            <div class="tab-pane fade" id="profile-leave">
                                <div class="card mb-3">
                                    <div class="card-header d-flex justify-content-between">
                                        <h5>İzin Talepleri</h5>
                                        <?php if($_SESSION['personnel_type'] !== 'admin'): ?>
                                            <button class="btn btn-sm btn-success" onclick="requestLeave(<?php echo $id; ?>)">
                                                <i class="fa fa-plus"></i> İzin Talep Et
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="list-group list-group-flush">
                                        <?php
                                        $leaves = $db->query("SELECT l.*, p.name as approver FROM personnel_leaves l 
                                                            LEFT JOIN personnel p ON l.approved_by = p.id 
                                                            WHERE l.personnel_id = $id ORDER BY l.requested_at DESC");
                                        while($l = $leaves->fetch_assoc()): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?php echo date('d.m.Y', strtotime($l['start_date'])); ?> → <?php echo date('d.m.Y', strtotime($l['end_date'])); ?></strong>
                                                        <small class="d-block text-muted"><?php echo htmlspecialchars($l['reason']); ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <?php if($l['status']=='pending'): ?>
                                                            <span class="badge bg-warning">Onay Bekliyor</span>
                                                        <?php elseif($l['status']=='approved'): ?>
                                                            <span class="badge bg-success">Onaylandı <?php echo $l['approver']?'('.htmlspecialchars($l['approver']).')':''; ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Reddedildi</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if($_SESSION['personnel_type']=='admin' && $l['status']=='pending'): ?>
                                                    <div class="mt-2">
                                                        <button onclick="approveLeave(<?php echo $l['id']; ?>, 'approved')" class="btn btn-sm btn-success">✓ Onayla</button>
                                                        <button onclick="approveLeave(<?php echo $l['id']; ?>, 'rejected')" class="btn btn-sm btn-danger">✗ Reddet</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. MAAŞ & AVANS -->
                            <div class="tab-pane fade" id="profile-salary">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5><i class="fa fa-money-bill-wave"></i> Maaş & Avans</h5>
                                        <?php if($_SESSION['personnel_type'] == 'admin'): ?>
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSalaryModal">
                                                <i class="fa fa-edit"></i> Maaş Düzenle
                                            </button>
                                            <button class="btn btn-outline-success btn-sm ml-2 mr-2" data-bs-toggle="modal" data-bs-target="#payrollModal">
                                                <i class="fa fa-file-invoice-dollar"></i> Maaş Bordrosu Görüntüle
                                            </button>
                                            <button onclick="generatePayslip()" class="btn btn-sm btn-success">
                                                <i class="fa fa-file-pdf"></i> Bordro Oluştur
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <!-- MAAŞ BİLGİLERİ -->
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="p-4 bg-light rounded text-center">
                                                    <h6>Maaş Türü</h6>
                                                    <h4 class="text-primary">
                                                        <?php echo $person['is_hourly'] ? 'Saatlik Ücret' : 'Aylık Maaş'; ?>
                                                    </h4>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="p-4 bg-light rounded text-center">
                                                    <h6>Tutar</h6>
                                                    <h4 class="text-success">
                                                        <?php if($person['is_hourly']): ?>
                                                            ₺<?php echo number_format($person['hourly_rate'],2); ?> / saat
                                                        <?php else: ?>
                                                            ₺<?php echo number_format($person['base_salary']); ?> / ay
                                                        <?php endif; ?>
                                                    </h4>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- BU AYKI DURUM -->
                                        <?php
                                        $thisMonth = date('Y-m');
                                        $work = $db->query("SELECT 
                                            COUNT(*) as days,
                                            COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(check_out, check_in))/3600),0) as hours
                                            FROM attendance 
                                            WHERE personnel_id = $id AND DATE_FORMAT(date,'%Y-%m') = '$thisMonth'")->fetch_assoc();

                                        $gross = $person['is_hourly'] 
                                            ? $person['hourly_rate'] * $work['hours'] 
                                            : $person['base_salary'];

                                        $paid_advances = $db->query("SELECT COALESCE(SUM(amount),0) FROM personnel_advances 
                                                                    WHERE personnel_id=$id AND status='paid' 
                                                                    AND DATE_FORMAT(paid_at,'%Y-%m')='$thisMonth'")->fetch_row()[0];
                                        ?>
                                        <div class="row mb-4 text-center">
                                            <div class="col">
                                                <small>Çalışılan Gün</small><br>
                                                <strong class="fs-3"><?php echo $work['days']; ?></strong>
                                            </div>
                                            <div class="col">
                                                <small>Çalışılan Saat</small><br>
                                                <strong class="fs-3"><?php echo number_format($work['hours'],1); ?> sa</strong>
                                            </div>
                                            <div class="col">
                                                <small>Brüt Kazanç</small><br>
                                                <strong class="fs-3 text-success">₺<?php echo number_format($gross); ?></strong>
                                            </div>
                                            <div class="col">
                                                <small>Ödenen Avans</small><br>
                                                <strong class="fs-3 text-danger">-₺<?php echo number_format($paid_advances); ?></strong>
                                            </div>
                                        </div>

                                        <!-- AVANS LİSTESİ (mevcut kodun aynısı kalabilir, sadece üstüne buton ekledik) -->
                                        <h6>Avans Talepleri</h6>
                                        <div class="table-responsive">
                                            <!-- SENİN ESKİ AVANS TABLON BURADA KALSIN -->
                                            <?php include_once 'includes/salary_advance_table.php'; // ya da direkt eski kodu yapıştır ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- MAAŞ BORDROSU BÜYÜK MODAL -->
                            <div class="modal fade" id="payrollModal" tabindex="-1">
                                <div class="modal-dialog modal-xl"> <!-- modal-xl = tam ekran genişlik -->
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">
                                                <i class="fa fa-file-invoice-dollar"></i> Maaş Bordrosu - <?php echo $person['name']; ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="text-center mb-4">
                                                <select id="payrollMonthModal" class="form-select w-auto d-inline-block"></select>
                                                <button id="printPayroll" class="btn btn-secondary ms-2">
                                                    <i class="fa fa-print"></i> Yazdır
                                                </button>
                                                <button id="downloadPayrollPDF" class="btn btn-success ms-2">
                                                    <i class="fa fa-file-pdf"></i> PDF İndir
                                                </button>
                                            </div>

                                            <div id="payrollPreview" class="border p-4 bg-white" style="min-height: 600px;">
                                                <p class="text-center text-muted">Ay seçerek bordroyu görüntüleyin...</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 5. VARDİYA -->
                            <div class="tab-pane fade" id="profile-shift">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5><i class="fa fa-calendar-alt"></i> Vardiya Takvimi</h5>
                                        
                                        <div class="d-flex gap-2">
                                            <select id="bulkShiftType" class="form-select form-select-sm" style="width:auto;">
                                                <option value="morning">Sabah (09:00-18:00)</option>
                                                <option value="afternoon">Öğlen (14:00-23:00)</option>
                                                <option value="night">Gece (23:00-09:00)</option>
                                                <option value="off">İzinli</option>
                                            </select>
                                            <button class="btn btn-primary btn-sm" id="fillWeek">
                                                <i class="fa fa-fill"></i> Bu Haftayı Doldur
                                            </button>
                                            <button class="btn btn-outline-primary btn-sm" id="fillSelected">
                                                <i class="fa fa-check-square"></i> Seçilenleri Doldur
                                            </button>
                                        </div>
                                        <?php if($_SESSION['personnel_type']=='admin'): ?>
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addShiftModal">
                                                <i class="fa fa-plus"></i> Vardiya Ekle
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <!-- HAFTALIK TAKVİM -->
                                        <div class="text-center mb-3">
                                            <button class="btn btn-outline-primary btn-sm" id="prevWeek"><i class="fa fa-chevron-left"></i></button>
                                            <strong id="weekInfo" class="mx-3">Bu Hafta</strong>
                                            <button class="btn btn-outline-primary btn-sm" id="nextWeek"><i class="fa fa-chevron-right"></i></button>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-bordered text-center" id="shiftCalendar">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Pazartesi</th>
                                                        <th>Salı</th>
                                                        <th>Çarşamba</th>
                                                        <th>Perşembe</th>
                                                        <th>Cuma</th>
                                                        <th>Cumartesi</th>
                                                        <th>Pazar</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td class="shift-cell" data-date=""></td>
                                                        <td class="shift-cell" data-date=""></td>
                                                        <td class="shift-cell" data-date=""></td>
                                                        <td class="shift-cell" data-date=""></td>
                                                        <td class="shift-cell" data-date=""></td>
                                                        <td class="shift-cell" data-date=""></td>
                                                        <td class="shift-cell" data-date=""></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="mt-3">
                                            <span class="badge bg-success me-3">Sabah (09:00-18:00)</span>
                                            <span class="badge bg-warning me-3">Öğlen (14:00-23:00)</span>
                                            <span class="badge bg-info me-3">Gece (23:00-09:00)</span>
                                            <span class="badge bg-danger">İzinli</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 6. ZİMMET -->
                            <div class="tab-pane fade" id="profile-assets">
                                <div class="row mb-3">
                                    <div class="col-9">&nbsp;</div>
                                    <div class="col-3" style="text-align:right;">
                                        <?php if($_SESSION['personnel_type']=='admin'): ?>
                                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#assignAssetModal">
                                            <i class="fa fa-plus"></i> Yeni Zimmet
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card">                                    
                                    <div class="card-header">Zimmetli Eşyalar</div>
                                    <div id="assignedAssetsList">
                                        <p class="text-muted text-center">Yükleniyor...</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 7. SATIŞ -->
                            <div class="tab-pane fade" id="profile-sales">
                                <div class="card text-center py-5">
                                    <h2 class="text-success">₺485.920</h2>
                                    <p class="fs-18px">Toplam Satış (2025)</p>
                                    <small class="text-muted">Bu ay: ₺87.420 • Geçen ay: ₺92.100</small>
                                </div>
                            </div>

                            <!-- 8. PUAN -->
                            <div class="tab-pane fade" id="profile-points">
                                <div class="card text-center py-5">
                                    <h2 class="text-primary">127</h2>
                                    <p class="fs-18px">Toplam Puan</p>
                                    <small class="text-muted">Bu ay: +42 • Hedef: 150</small>
                                </div>
                            </div>

                            <!-- 9. AKTİVİTE -->
                            <div class="tab-pane fade" id="profile-activity">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5><i class="fa fa-history"></i> Son Aktiviteler</h5>
                                        <div class="d-flex gap-2">
                                            <!-- Arama -->
                                            <input type="text" id="activitySearch" class="form-control form-control-sm" placeholder="Ara..." style="width:200px;">
                                            <!-- Filtre -->
                                            <select id="activityFilter" class="form-select form-select-sm">
                                                <option value="">Tüm Aktiviteler</option>
                                                <option value="order">Siparişler</option>
                                                <option value="login">Giriş/Çıkış</option>
                                                <option value="advance">Avans</option>
                                                <option value="leave">İzin</option>
                                                <option value="document">Evrak</option>
                                                <option value="option" value="point">Puan</option>
                                                <option value="sale">Satış</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="list-group list-group-flush" id="activityList">
                                            <!-- JavaScript ile dinamik doldurulacak -->
                                        </div>
                                        <div class="text-center p-3" id="activityEmpty" style="display:none;">
                                            <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">Hiç aktivite bulunamadı.</p>
                                        </div>
                                    </div>
                                    <div class="card-footer text-center" id="activityPagination" style="display:none;">
                                        <button class="btn btn-sm btn-outline-primary" id="loadMore">Daha Fazla Yükle</button>
                                    </div>
                                </div>
                            </div>

                            <!-- GÖREV & HEDEF SEKME - SÜPER GÜÇLENDİRİLMİŞ HALİ -->
                            <div class="tab-pane fade" id="profile-targets">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5>Hedef Yönetimi</h5>
                                        <?php if($_SESSION['personnel_type'] == 'admin'): ?>
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addTargetModal">
                                                <i class="fa fa-plus"></i> Yeni Hedef Ekle
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <!-- MEVCUT HEDEFLER LİSTESİ -->
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Hedef Türü</th>
                                                        <th>Değer</th>
                                                        <th>Dönem</th>
                                                        <th>Gerçekleşen</th>
                                                        <th>Durum</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $targets = $db->query("SELECT t.*, p.name as product_name 
                                                                        FROM personnel_targets t 
                                                                        LEFT JOIN products p ON t.product_id = p.id 
                                                                        WHERE t.personnel_id = $id 
                                                                        ORDER BY t.created_at DESC");
                                                    while($t = $targets->fetch_assoc()):
                                                        // Gerçekleşen değer hesapla (basit örnek)
                                                        $achieved = 0;
                                                        if($t['target_type'] == 'monthly_sales') {
                                                            $achieved = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE personnel_id = $id AND MONTH(created_at) = {$t['period_month']} AND YEAR(created_at) = {$t['period_year']}")->fetch_row()[0];
                                                        }
                                                        $percent = $t['target_value'] > 0 ? round(($achieved / $t['target_value']) * 100) : 0;
                                                        $progress_class = $percent >= 100 ? 'success' : ($percent >= 70 ? 'warning' : 'danger');
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php if($t['target_type'] == 'product_sales'): ?>
                                                                <strong><?php echo htmlspecialchars($t['product_name']); ?></strong> Satışı
                                                            <?php else: ?>
                                                                <?php echo [
                                                                    'daily_sales' => 'Günlük Satış',
                                                                    'weekly_sales' => 'Haftalık Satış',
                                                                    'monthly_sales' => 'Aylık Satış',
                                                                    'yearly_sales' => 'Yıllık Satış',
                                                                    'daily_points' => 'Günlük Puan',
                                                                    'monthly_points' => 'Aylık Puan'
                                                                ][$t['target_type']]; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if(str_ends_with($t['target_type'], '_sales') || $t['target_type'] == 'product_sales'): ?>
                                                                ₺<?php echo number_format($t['target_value']); ?>
                                                            <?php else: ?>
                                                                <?php echo $t['target_points']; ?> puan
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if($t['period_month']): ?>
                                                                <?php echo date('F Y', mktime(0,0,0,$t['period_month'],1,$t['period_year'])); ?>
                                                            <?php else: ?>
                                                                <?php echo $t['period_year']; ?> Yılı
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>₺<?php echo number_format($achieved); ?></td>
                                                        <td>
                                                            <div class="progress" style="height: 25px;">
                                                                <div class="progress-bar bg-<?php echo $progress_class; ?>" style="width: <?php echo min(100, $percent); ?>%">
                                                                    %<?php echo $percent; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if($_SESSION['personnel_type']=='admin'): ?>
                                                                <button onclick="deleteTarget(<?php echo $t['id']; ?>)" class="btn btn-sm btn-outline-danger">
                                                                    <i class="fa fa-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Evrak Yükleme Modal -->
    <div class="modal fade" id="uploadDocumentForm" tabindex="-1">
        <div class="modal-dialog">
            <form id="uploadDocumentForm" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Evrak Yükle – <?php echo $person['name']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="personnel_id" value="<?php echo $id; ?>">
                    <div class="mb-3">
                        <label class="form-label">Evrak Türü</label>
                        <select name="document_type_id" class="form-select" required>
                        <?php
                        $types2 = $db->query("SELECT * FROM document_types ORDER BY name");
                        while($t = $types2->fetch_assoc()){
                            echo "<option value='{$t['id']}'>{$t['name']}</option>";
                        }
                        ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dosya (PDF, JPG, PNG)</label>
                        <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-theme">Yükle</button>
                </div>
            </div>
            </form>
        </div>
        </div>
    </div>

    <!-- YENİ HEDEF EKLE MODAL -->
    <div class="modal fade" id="addTargetModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="addTargetForm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Yeni Hedef Tanımla – <?php echo $person['name']; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Hedef Türü</label>
                                <select class="form-select" id="targetType" onchange="toggleProductField()">
                                    <option value="monthly_sales">Aylık Satış Hedefi</option>
                                    <option value="daily_sales">Günlük Satış Hedefi</option>
                                    <option value="weekly_sales">Haftalık Satış Hedefi</option>
                                    <option value="yearly_sales">Yıllık Satış Hedefi</option>
                                    <option value="monthly_points">Aylık Puan Hedefi</option>
                                    <option value="daily_points">Günlük Puan Hedefi</option>
                                    <option value="product_sales">Ürün Bazlı Satış Hedefi</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="productField" style="display:none;">
                                <label>Ürün Seç</label>
                                <select class="form-select" name="product_id">
                                    <?php
                                    $prods = $db->query("SELECT id, name FROM products ORDER BY name");
                                    while($p = $prods->fetch_assoc()){
                                        echo "<option value='{$p['id']}'>{$p['name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label>Hedef Tutar / Puan</label>
                                <input type="number" class="form-control" id="targetValue" placeholder="0" required>
                            </div>
                            <div class="col-md-6">
                                <label>Dönem</label>
                                <input type="month" class="form-control" id="targetMonth" value="<?php echo date('Y-m'); ?>">
                                <small class="text-muted">Yıllık hedef için ay seçmeyin</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Hedefi Kaydet</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MAAŞ DÜZENLE MODAL -->
    <div class="modal fade" id="editSalaryModal">
        <div class="modal-dialog">
            <form id="salaryForm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5>Maaş Bilgilerini Düzenle – <?php echo $person['name']; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Maaş Türü</label>
                            <select class="form-select" id="salaryType">
                                <option value="0" <?php echo !$person['is_hourly']?'selected':''; ?>>Aylık Sabit Maaş</option>
                                <option value="1" <?php echo $person['is_hourly']?'selected':''; ?>>Saatlik Ücret</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label id="salaryLabel">Aylık Brüt Maaş (₺)</label>
                            <input type="number" step="0.01" class="form-control" id="salaryAmount" 
                                value="<?php echo $person['is_hourly'] ? $person['hourly_rate'] : $person['base_salary']; ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Kaydet</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- VARDİYA EKLE MODAL -->
    <div class="modal fade" id="addShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <form id="addShiftForm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Vardiya Ekle – <?php echo $person['name']; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="personnel_id" value="<?php echo $id; ?>">
                        <div class="mb-3">
                            <label>Tarih</label>
                            <input type="date" name="shift_date" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <label>Giriş Saati</label>
                                <input type="time" name="start_time" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label>Çıkış Saati</label>
                                <input type="time" name="end_time" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3 mt-3">
                            <label>Vardiya Türü</label>
                            <select name="shift_type" class="form-select">
                                <option value="morning">Sabah (09:00-18:00)</option>
                                <option value="afternoon">Öğlen (14:00-23:00)</option>
                                <option value="night">Gece (23:00-09:00)</option>
                                <option value="off">İzinli / Off</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Not (isteğe bağlı)</label>
                            <input type="text" name="notes" class="form-control" placeholder="Örn: Yıllık izin">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Kaydet</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- VARDİYA DÜZENLE / SİL MODAL -->
    <div class="modal fade" id="editShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <form id="editShiftForm">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Vardiya Düzenle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="personnel_id" value="<?php echo $id; ?>">
                        <div class="mb-3">
                            <label>Tarih</label>
                            <input type="date" id="editShiftDate" name="shift_date" class="form-control" readonly>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <label>Giriş Saati</label>
                                <input type="time" id="editStartTime" name="start_time" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label>Çıkış Saati</label>
                                <input type="time" id="editEndTime" name="end_time" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3 mt-3">
                            <label>Vardiya Türü</label>
                            <select id="editShiftType" name="shift_type" class="form-select">
                                <option value="morning">Sabah (09:00-18:00)</option>
                                <option value="afternoon">Öğlen (14:00-23:00)</option>
                                <option value="night">Gece (23:00-09:00)</option>
                                <option value="off">İzinli</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Not</label>
                            <input type="text" id="editNotes" name="notes" class="form-control" placeholder="İsteğe bağlı">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" id="deleteShiftBtn" class="btn btn-danger me-auto" style="display:none;">
                            <i class="fa fa-trash"></i> Sil
                        </button>
                        <button type="submit" class="btn btn-success">Kaydet</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- YENİ ZİMMET MODALI -->
    <div class="modal fade" id="assignAssetModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="assignAssetForm">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Demirbaş Zimmetle - <?php echo $person['name']; ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="personnel_id" value="<?php echo $id; ?>">
                        <div class="mb-3">
                            <label>Demirbaş Seç</label>
                            <select name="asset_id" class="form-select" required>
                                <option value="">-- Stokta olanları seç --</option>
                                <!-- JS ile doldurulacak -->
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Not (isteğe bağlı)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Örn: Yeni telefon, kılıf hediye edildi"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Zimmetle</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="assets/js/vendor.min.js"></script>
    <script src="assets/js/app.min.js"></script>
    <!-- Toast Bildirimleri -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
        <div id="liveToast" class="toast hide" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <strong class="me-auto" id="toastTitle">Başarılı</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body" id="toastBody"></div>
        </div>
    </div>
    <script>
        // GÜZEL TOAST GÖSTER
        function showToast(title, message, type = 'success') {
            const toast = document.getElementById('liveToast');
            document.getElementById('toastTitle').textContent = title;
            document.getElementById('toastBody').textContent = message;
            toast.className = `toast align-items-center text-bg-${type === 'success' ? 'success' : 'danger'} border-0`;
            new bootstrap.Toast(toast).show();
        }

        // TEK AJAX FONKSİYONU (her şey bununla çalışacak)
        function ajaxAction(action, data = {}) {
            const fd = new FormData();
            fd.append('ajax_action', action);
            for (const key in data) {
                fd.append(key, data[key]);
            }

            return fetch(location.href, {  // '' yerine location.href daha güvenli
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                // action parametresi burada kullanılıyor → HATA YOK!
                if (action === 'load_shifts') {
                    document.querySelectorAll('.shift-cell').forEach(cell => {
                        const date = cell.dataset.date;
                        const infoDiv = cell.querySelector('.shift-info');
                        if (res.shifts?.[date]) {
                            const s = res.shifts[date];
                            if (s.shift_type === 'off') {
                                infoDiv.innerHTML = '<span class="badge bg-danger">İzinli</span>';
                            } else {
                                const colors = { morning: 'success', afternoon: 'warning', night: 'info' };
                                const color = colors[s.shift_type] || 'secondary';
                                infoDiv.innerHTML = `<span class="badge bg-${color}">${s.start_time} - ${s.end_time}</span>`;
                                if (s.notes) infoDiv.innerHTML += `<br><small>${s.notes}</small>`;
                            }
                        } else {
                            infoDiv.innerHTML = '<span class="text-muted">-</span>';
                        }
                    });
                    attachShiftCellListeners();
                }

                if (action === 'get_payroll') {
                    if (res.success) {
                        const d = res.data;
                        document.getElementById('payrollPreview').innerHTML = `
                            <div class="text-center mb-4">
                                <h3><?php echo $person['name']; ?> - MAAŞ BORDROSU</h3>
                                <h5>${document.getElementById('payrollMonthModal').selectedOptions[0].text}</h5>
                            </div>
                            <table class="table table-bordered table-striped">
                                <tr><th>Çalışma Saati</th><td class="text-end">${d.calisma_saati} saat</td></tr>
                                <tr><th>Fazla Mesai</th><td class="text-end text-danger fw-bold">${d.fazla_mesai} saat</td></tr>
                                <tr><th>Brüt Maaş</th><td class="text-end">₺${d.brut_maas}</td></tr>
                                <tr><th>Fazla Mesai Ücreti</th><td class="text-end text-success">+ ₺${d.fazla_ucret}</td></tr>
                                <tr><th>Avans Kesintisi</th><td class="text-end text-danger">- ₺${d.avans}</td></tr>
                                <tr class="table-success"><th>NET ÖDENECEK</th><td class="text-end fs-3">₺${d.net}</td></tr>
                            </table>
                        `;
                    }
                }else if (action === 'load_assigned_assets') {
                    document.getElementById('assignedAssetsList').innerHTML = res.html;
                    document.getElementById('assetCount').textContent = res.count || 0;
                }else if (action === 'get_available_assets') {
                    // modal içindeki select dolduruluyor (zaten var ama garanti olsun)
                    const select = document.querySelector('#assignAssetForm select[name="asset_id"]');
                    select.innerHTML = '<option value="">-- Stokta olanları seç --</option>';
                    res.assets.forEach(a => {
                        select.innerHTML += `<option value="${a.id}">[${a.asset_code}] ${a.name} - ${a.brand || ''} ${a.model || ''}</option>`;
                    });
                }

                return res;
            })
            .catch(err => {
                console.error('AJAX Hatası:', err);
                showToast('Bağlantı Hatası', 'Sunucuya ulaşılamadı', 'danger');
            });
        }

        function changePass(id) {
            let pass = prompt('Yeni şifre:');
            if (pass && pass.length >= 4) {
                fetch('ajax.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=change_password&id='+id+'&password='+encodeURIComponent(pass)})
                .then(r=>r.json()).then(d=>alert(d.success?'Şifre değiştirildi!':'Hata!'));
            }
        }

        function deletePerson(id) {
            if(confirm('Silmek istediğine emin misin?')) {
                fetch('ajax.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=delete_personnel&id='+id})
                .then(r=>r.json()).then(d=>{if(d.success) location.href='personnel.php';});
            }
        }

        function handleAction(action, data = {}) {
            const fd = new FormData();
            fd.append('ajax_action', action);
            
            for (const key in data) {
                fd.append(key, data[key]);
            }

            fetch('', {  // aynı sayfaya gönderiyoruz!
                method: 'POST',
                body: fd
            })
            .then(r => r.text())
            .then(result => {
                if (result.includes('BAŞARILI')) {
                    alert('Başarılı! Sayfa yenileniyor...');
                    location.reload();
                } else {
                    alert('Hata: ' + result);
                }
            });
        }

        // Avans talep et
        function requestAdvance() {
            const amount = prompt('Avans tutarı (TL):');
            if (!amount || amount <= 0) return;
            const reason = prompt('Açıklama (isteğe bağlı):') || 'Avans talebi';
            handleAction('give_advance', {
                amount: amount,
                reason: reason
            });
        }

        // İzin talep et
        function requestLeave() {
            const start = prompt('Başlangıç tarihi (YYYY-MM-DD):');
            const end = prompt('Bitiş tarihi (YYYY-MM-DD):');
            if (!start || !end) return;
            const reason = prompt('İzin sebebi:') || 'İzin talebi';
            handleAction('request_leave', {
                start_date: start,
                end_date: end,
                reason: reason
            });
        }

        // Admin: Avans onayla/reddet
        function approveAdvance(id, status) {
            if (!confirm(status=='paid' ? 'Avansı ÖDE?' : 'Avansı REDDET?')) return;
            handleAction('advance_action', { id: id, status: status });
        }

        // Admin: İzin onayla/reddet
        function approveLeave(id, status) {
            if (!confirm(status=='approved' ? 'İzni ONAYLA?' : 'İzni REDDET?')) return;
            handleAction('leave_action', { id: id, status: status });
        }

        // EVRAK YÜKLEME (AJAX)
        document.getElementById('uploadDocumentForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('ajax_action', 'upload_document');
            fd.append('personnel_id', <?php echo $id; ?>);

            fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showToast('Başarılı!', res.msg, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Hata!', res.msg, 'danger');
                }
            })
            .catch(() => showToast('Hata!', 'Dosya yüklenemedi', 'danger'));
        });

        // ZİMMET EKLE (güzel prompt yerine modal da yapabiliriz ama şimdilik prompt)
        function addAsset() {
            const item = prompt('Zimmet eşyası:');
            if (!item) return;
            const serial = prompt('Seri no (isteğe bağlı):') || '';
            ajaxAction('add_asset', { personnel_id: <?php echo $id; ?>, item: item, serial: serial });
        }

        // Ürün alanı göster/gizle
        function toggleProductField() {
            document.getElementById('productField').style.display = 
                document.getElementById('targetType').value === 'product_sales' ? 'block' : 'none';
        }

        // Yeni hedef ekle
        document.getElementById('addTargetForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const type = document.getElementById('targetType').value;
            const value = document.getElementById('targetValue').value;
            const monthInput = document.getElementById('targetMonth').value;
            
            const [year, month] = monthInput ? monthInput.split('-') : [new Date().getFullYear(), null];
            
            const data = {
                target_type: type,
                target_value: type.includes('points') ? 0 : value,
                target_points: type.includes('points') ? value : 0,
                period_year: year,
                period_month: month || null
            };
            
            if (type === 'product_sales') {
                data.product_id = document.querySelector('#productField select').value;
            }
            
            ajaxAction('add_target', data);
        });

        // Hedef sil
        function deleteTarget(tid) {
            if(!confirm('Bu hedefi silmek istediğine emin misin?')) return;
            ajaxAction('delete_target', { id: tid });
        }

        // AKTİVİTE SİSTEMİ - FİLTRELİ + ARAMALI + SAYFALAMA
        let activityPage = 0;
        let activityLoading = false;

        function loadActivities(reset = false) {
            if (activityLoading) return;
            activityLoading = true;

            if (reset) {
                activityPage = 0;
                document.getElementById('activityList').innerHTML = '';
                document.getElementById('activityPagination').style.display = 'none';
            }

            const search = document.getElementById('activitySearch').value;
            const filter = document.getElementById('activityFilter').value;

            ajaxAction('load_activities', {
                page: activityPage,
                search: search,
                filter: filter
            }, function() {
                activityLoading = false;
            });
        }

        // Daha fazla yükle butonu
        document.getElementById('loadMore')?.addEventListener('click', () => {
            activityPage++;
            loadActivities();
        });

        // Arama ve filtre anında çalışsın
        document.getElementById('activitySearch')?.addEventListener('input', () => loadActivities(true));
        document.getElementById('activityFilter')?.addEventListener('change', () => loadActivities(true));

        // Sayfa yüklendiğinde ilk verileri getir
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('profile-activity').classList.contains('show')) {
                loadActivities(true);
            }
        });

        // Tab değiştiğinde yükle (ilk girişte çalışması için)
        document.querySelector('a[href="#profile-activity"]')?.addEventListener('shown.bs.tab', () => {
            loadActivities(true);
        });

        function generatePayslip() {
            const month = prompt('Bordro ayı (örnek: 2025-11)', new Date().toISOString().slice(0,7));
            if (!month) return;

            const win = window.open('', '_blank');
            win.document.write(`
            <html><head><title>Bordro - <?php echo $person['name']; ?> - ${month}</title>
            <style>
                body { font-family: DejaVu Sans, sans-serif; margin: 40px; line-height: 1.6; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                td, th { border: 1px solid #000; padding: 10px; text-align: left; }
                th { background: #f0f0f0; }
                .text-right { text-align: right; }
                .logo { text-align: center; font-size: 28px; font-weight: bold; margin-bottom: 30px; }
                .net { font-size: 20px; font-weight: bold; }
            </style>
            </head><body>
            <div class="logo">ALÇITEPE CAFE</div>
            <h2 style="text-align:center;">MAAŞ BORDROSU - ${month}</h2>
            <table>
                <tr><td>Ad Soyad</td><td><strong><?php echo htmlspecialchars($person['name']); ?></strong></td></tr>
                <tr><td>Çalışma Şekli</td><td><?php echo $person['is_hourly']?'Saatlik':'Aylık Sabit'; ?></td></tr>
                <tr><td>Brüt Ücret</td><td>₺<?php echo number_format($gross); ?></td></tr>
                <tr><td>Avans Kesintisi</td><td class="text-right">-₺<?php echo number_format($paid_advances); ?></td></tr>
                <tr><td>SGK İşçi (%14)</td><td class="text-right">-₺<?php echo number_format($gross * 0.14,2); ?></td></tr>
                <tr><td>İşsizlik İşçi (%1)</td><td class="text-right">-₺<?php echo number_format($gross * 0.01,2); ?></td></tr>
                <tr><td>Gelir Vergisi (%15)</td><td class="text-right">-₺<?php echo number_format(($gross * 0.85) * 0.15,2); ?></td></tr>
                <tr><th>NET MAAŞ</th><th class="text-right net">₺<?php echo number_format($gross - $paid_advances - ($gross*0.14) - ($gross*0.01) - (($gross*0.85)*0.15),2); ?></th></tr>
            </table>
            <br><br>
            <div style="text-align:center;">
                İşveren İmza: ____________________ &nbsp;&nbsp;&nbsp;&nbsp; Çalışan İmza: ____________________
            </div>
            </body></html>`);
            win.document.close();
            setTimeout(() => win.print(), 1000);
        }

        document.getElementById('salaryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const isHourly = document.getElementById('salaryType').value;
            const amount = document.getElementById('salaryAmount').value;
            
            ajaxAction('update_salary', {
                is_hourly: isHourly,
                amount: amount
            });
        });

        // HAFTALIK TAKVİM
        let currentWeekStart = getMonday(new Date());

        function getMonday(d) {
            d = new Date(d);
            const day = d.getDay(); // 0=Pazar, 1=Pazartesi, 6=Cumartesi
            const diff = d.getDate() - day + (day === 0 ? -5 : 1);
            d.setDate(diff);
            d.setHours(0,0,0,0);
            return d;
        }

        function loadWeek() {
            const dates = [];
            for (let i = 0; i < 7; i++) {
                const date = new Date(currentWeekStart);
                date.setDate(date.getDate() + i);
                dates.push(date.toISOString().split('T')[0]);
            }

            const start = dates[0].split('-').reverse().join('.');
            const end = dates[6].split('-').reverse().join('.');
            document.getElementById('weekInfo').textContent = `${start} - ${end}`;

            document.querySelectorAll('.shift-cell').forEach((cell, i) => {
                const dateStr = dates[i];
                const day     = dateStr.slice(8, 10);
                const month   = dateStr.slice(5, 7);

                cell.dataset.date = dateStr;
                cell.innerHTML = `
                    <div class="position-relative">
                <input type="checkbox" class="position-absolute top-0 start-0 m-2 bulk-checkbox" style="z-index:10;">
                <small class="text-muted d-block">${day}.${month}</small>
                <div class="shift-info">Yükleniyor...</div>
            </div>
                `;
            });

            // ARTIK .finally() ÇALIŞIYOR!
            ajaxAction('load_shifts', {
                personnel_id: <?php echo $id; ?>,
                dates: dates.join(',')
            });
        }

        // İLERİ - GERİ BUTONLARI
        document.getElementById('prevWeek')?.addEventListener('click', () => {
            currentWeekStart.setDate(currentWeekStart.getDate() - 7);
            loadWeek();
        });

        document.getElementById('nextWeek')?.addEventListener('click', () => {
            currentWeekStart.setDate(currentWeekStart.getDate() + 7);
            loadWeek();
        });

        // EN ÖNEMLİ KISIM: VARDİYA SEKME AÇILINCA ZORLA ÇALIŞTIR!
        document.addEventListener('DOMContentLoaded', function() {
            const shiftTab = document.querySelector('a[href="#profile-shift"]');
            if (shiftTab) {
                // Sekme ilk kez gösterildiğinde
                shiftTab.addEventListener('shown.bs.tab', function () {
                    loadWeek(); // ZORLA ÇALIŞTIR!
                });
                
                // Eğer sayfa direkt #profile-shift ile açılıyorsa hemen çalışsın
                if (window.location.hash === '#profile-shift' || shiftTab.classList.contains('active')) {
                    loadWeek();
                }
            }
        });

        // Sayfa açılınca yükle
        document.querySelector('a[href="#profile-shift"]')?.addEventListener('shown.bs.tab', () => {
            if (!window.shiftLoaded) {
                loadWeek();
                window.shiftLoaded = true;
            }
        });

        // Vardiya ekleme
        document.getElementById('addShiftForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('ajax_action', 'add_shift');
            fetch('', { method: 'POST', body: fd })
                .then(r => r.text())
                .then(res => {
                    if (res.includes('BAŞARILI')) {
                        showToast('Başarılı', 'Vardiya eklendi!');
                        $('#addShiftModal').modal('hide');
                        loadWeek();
                    } else {
                        showToast('Hata', res, 'danger');
                    }
                });
        });

        // VARDİYA HÜCRESİNE TIKLANDIĞINDA MODAL AÇ
        function attachShiftCellListeners() {
            document.querySelectorAll('.shift-cell').forEach(cell => {
                cell.onclick = function() {
                    const date = this.dataset.date;
                    const badge = this.querySelector('.badge');
                    const existing = badge ? {
                        start: badge.textContent.split(' - ')[0],
                        end: badge.textContent.split(' - ')[1] || '',
                        type: badge.classList.contains('bg-success') ? 'morning' :
                            badge.classList.contains('bg-warning') ? 'afternoon' :
                            badge.classList.contains('bg-info') ? 'night' : 'off',
                        notes: this.querySelector('small')?.nextElementSibling?.textContent || ''
                    } : null;

                    document.getElementById('editShiftDate').value = date;
                    document.getElementById('editStartTime').value = existing?.start || '';
                    document.getElementById('editEndTime').value = existing?.end || '';
                    document.getElementById('editShiftType').value = existing?.type || 'morning';
                    document.getElementById('editNotes').value = existing?.notes || '';

                    document.getElementById('deleteShiftBtn').style.display = existing ? 'inline-block' : 'none';

                    const modal = new bootstrap.Modal(document.getElementById('editShiftModal'));
                    modal.show();
                };
                cell.style.cursor = 'pointer';
            });
        }

        function handleCellClick(e) {
            const cell = e.currentTarget;
            const selectedDate = cell.dataset.date;

            // Mevcut vardiya var mı?
            const badge = cell.querySelector('.badge');
            const existing = badge ? {
                start: badge.textContent.split(' - ')[0],
                end: badge.textContent.split(' - ')[1]?.split(' ')[0] || '',
                type: badge.classList.contains('bg-success') ? 'morning' :
                    badge.classList.contains('bg-warning') ? 'afternoon' :
                    badge.classList.contains('bg-info') ? 'night' : 'off',
                notes: cell.querySelector('small')?.nextSibling?.textContent?.trim() || ''
            } : null;

            // Modal doldur
            document.getElementById('editShiftDate').value = selectedDate;
            document.getElementById('editStartTime').value = existing?.start || '';
            document.getElementById('editEndTime').value = existing?.end || '';
            document.getElementById('editShiftType').value = existing?.type || 'morning';
            document.getElementById('editNotes').value = existing?.notes || '';

            // Sil butonu göster/gizle
            document.getElementById('deleteShiftBtn').style.display = existing ? 'inline-block' : 'none';

            // MODALI TEMİZ AÇ (backdrop sorunu çözüldü!)
            const modalEl = document.getElementById('editShiftModal');
            const modal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true });
            modal.show();
        }

        // DÜZENLE / KAYDET
        document.getElementById('editShiftModal').addEventListener('hidden.bs.modal', () => {
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            document.body.classList.remove('modal-open');
        });

        // SİL BUTONU
        document.getElementById('deleteShiftBtn')?.addEventListener('click', function() {
            if (!confirm('Bu vardiyayı silmek istediğine emin misin?')) return;

            const fd = new FormData();
            fd.append('ajax_action', 'delete_shift');
            fd.append('personnel_id', <?php echo $id; ?>);
            fd.append('shift_date', document.getElementById('editShiftDate').value);

            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast('Silindi', 'Vardiya silindi', 'warning');
                        bootstrap.Modal.getInstance(document.getElementById('editShiftModal')).hide();
                        loadWeek();
                    }
                });
        });

        document.getElementById('fillWeek')?.addEventListener('click', function() {
            if (!confirm('Bu haftanın TÜM günleri seçtiğin vardiya ile doldurulsun mu?')) return;
            bulkApplyShift(getCurrentWeekDates(), document.getElementById('bulkShiftType').value);
        });

        document.getElementById('fillSelected')?.addEventListener('click', function() {
            const checked = document.querySelectorAll('.bulk-checkbox:checked');
            if (checked.length === 0) return showToast('Uyarı', 'En az 1 gün seçmelisin', 'warning');
            if (!confirm(`${checked.length} gün seçildi. Bu günler doldurulsun mu?`)) return;

            const dates = Array.from(checked).map(cb => cb.closest('.shift-cell').dataset.date);
            bulkApplyShift(dates, document.getElementById('bulkShiftType').value);
        });

        function getCurrentWeekDates() {
            const dates = [];
            for (let i = 0; i < 7; i++) {
                const d = new Date(currentWeekStart);
                d.setDate(d.getDate() + i);
                dates.push(d.toISOString().split('T')[0]);
            }
            return dates;
        }

        function bulkApplyShift(dates, type) {
            const fd = new FormData();
            fd.append('ajax_action', 'bulk_shift');
            fd.append('personnel_id', <?php echo $id; ?>);
            fd.append('dates', dates.join(','));
            fd.append('shift_type', type);

            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast('Başarılı', `${dates.length} gün güncellendi!`, 'success');
                        loadWeek();
                    }
                });
        }

       document.getElementById('payrollModal')?.addEventListener('shown.bs.modal', function () {
            loadPayrollMonthsModal();
        });

        function loadPayrollMonthsModal() {
            const select = document.getElementById('payrollMonthModal');
            select.innerHTML = '';
            const today = new Date();
            for (let i = 11; i >= 0; i--) {
                const d = new Date(today.getFullYear(), today.getMonth() - i, 1);
                const value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                const label = d.toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' }).toUpperCase();
                const opt = new Option(label, value);
                select.add(opt);
                if (i === 0) opt.selected = true;
            }
            loadPayrollModal(); // ilk açılışta hemen yükle
        }

        // Bordroyu yükle
        function loadPayrollModal() {
            const month = document.getElementById('payrollMonthModal').value;
            ajaxAction('get_payroll', { personnel_id: <?php echo $id; ?>, month: month });
        }

        // Ay değişince yeniden yükle
        document.getElementById('payrollMonthModal')?.addEventListener('change', loadPayrollModal);

        // YAZDIR BUTONU
        document.getElementById('printPayroll')?.addEventListener('click', () => {
            const content = document.getElementById('payrollPreview').innerHTML;
            const win = window.open('', '', 'width=900,height=700');
            win.document.write(`
                <html><head><title>Bordro - ${document.getElementById('payrollMonthModal').selectedOptions[0].text}</title>
                <style>
                    body { font-family: 14px Arial; padding: 40px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #000; padding: 12px; text-align: left; }
                    th { background: #f0f0f0; }
                    .text-end { text-align: right; }
                    h2 { text-align: center; }
                </style>
                </head><body>
                <h2><?php echo $person['name']; ?> - MAAŞ BORDROSU</h2>
                <h3>${document.getElementById('payrollMonthModal').selectedOptions[0].text}</h3>
                ${content}
                </body></html>
            `);
            win.document.close();
            win.focus();
            setTimeout(() => win.print(), 500);
        });

        // PDF İNDİR (generate_payroll_pdf.php yaparız sonra)
        document.getElementById('downloadPayrollPDF')?.addEventListener('click', () => {
            const month = document.getElementById('payrollMonthModal').value;
            window.open(`generate_payroll_pdf.php?id=<?php echo $id; ?>&month=${month}&name=<?php echo urlencode($person['name']); ?>`, '_blank');
        });

        document.getElementById('assets-tab')?.addEventListener('shown.bs.tab', () => {
            loadAssignedAssets();
        });

        function loadAssignedAssets() {
            document.getElementById('assignedAssetsList').innerHTML = 
                '<p class="text-center"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</p>';
            
            ajaxAction('load_assigned_assets', { personnel_id: <?php echo $id; ?> });
        }

        // Stokta olan demirbaşları yükle (modal açılınca)
        document.getElementById('assignAssetModal')?.addEventListener('shown.bs.modal', () => {
            ajaxAction('get_available_assets', {}).then(res => {
                const select = document.querySelector('#assignAssetForm select[name="asset_id"]');
                select.innerHTML = '<option value="">-- Stokta olanları seç --</option>';
                res.assets.forEach(a => {
                    select.innerHTML += `<option value="${a.id}">[${a.asset_code}] ${a.name} - ${a.brand} ${a.model}</option>`;
                });
            });
        });

        // Yeni zimmet
        document.getElementById('assignAssetForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('ajax_action', 'assign_asset');
            fetch('', {method:'POST', body:fd})
            .then(r=>r.json())
            .then(res => {
                if(res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('assignAssetModal')).hide();
                    showToast('Başarılı', 'Demirbaş zimmetlendi!');
                    loadAssignedAssets();
                }
            });
        });

        // Teslim al butonları
        document.addEventListener('click', function(e) {
            if(e.target.matches('.return-asset')) {
                if(!confirm('Bu demirbaşı teslim alıyorsun, emin misin?')) return;
                const fd = new FormData();
                fd.append('ajax_action', 'return_asset');
                fd.append('assignment_id', e.target.dataset.id);
                fetch('', {method:'POST', body:fd})
                .then(r=>r.json())
                .then(() => {
                    showToast('Tamam', 'Demirbaş teslim alındı');
                    loadAssignedAssets();
                });
            }
        });

        const assetsTab = document.querySelector('a[href="#profile-assets"]');
        if (assetsTab) {
            assetsTab.addEventListener('shown.bs.tab', function () {
                loadAssignedAssets();
            });
            
            // Eğer sayfa direkt zimmet sekmesiyle açılıyorsa
            if (assetsTab.classList.contains('active')) {
                loadAssignedAssets();
            }
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>