<?php
// cron_jobs.php - Her 5 dakikada bir çalıştır (veya saat 23:55)

// GEREKLİ DOSYALAR
require_once 'config.php';
require_once 'functions/notifications.php';
require_once 'functions/payments.php';
require_once 'functions/daily_close.php';
require_once 'functions/common.php'; // error_log için

function run_cron_jobs() {
    global $db;
    $current_date = date('Y-m-d');
    $log_prefix = "[CRON " . date('Y-m-d H:i:s') . "] ";

    try {
        // 1. VADE HATIRLATMALARI (Sadece 1 kez bildir)
        $query = "SELECT c.name, oa.amount_due, oa.due_date, oa.branch_id, oa.id
                  FROM open_accounts oa
                  JOIN customers c ON oa.customer_id = c.id
                  LEFT JOIN notifications n ON n.related_id = oa.id AND n.type = 'due_reminder' AND DATE(n.created_at) = ?
                  WHERE oa.status = 'open' AND oa.due_date <= ? AND n.id IS NULL";
        $stmt = $db->prepare($query);
        $stmt->bind_param("ss", $current_date, $current_date);
        $stmt->execute();
        $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($accounts as $account) {
            $msg = "Vade Uyarısı: {$account['name']} için {$account['amount_due']} TL vadesi {$account['due_date']} tarihinde doldu!";
            add_notification($msg, 'warning', $account['branch_id'], null, 'due_reminder', $account['id']);
            error_log($log_prefix . $msg);
        }

        // 2. GÜN SONU HATIRLATMALARI
        send_daily_close_reminder();

        error_log($log_prefix . "Cron tamamlandı. Vade: " . count($accounts) . " adet.");

    } catch (Exception $e) {
        error_log($log_prefix . "HATA: " . $e->getMessage());
        add_notification("Cron hatası: " . $e->getMessage(), 'error', 0); // Genel
    }
}

// ÇALIŞTIR
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === 'cron')) {
    run_cron_jobs();
} else {
    http_response_code(403);
    exit('Forbidden');
}
?>