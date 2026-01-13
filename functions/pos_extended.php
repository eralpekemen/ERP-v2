<?php
// functions/pos_extended.php
// Alçitepe Cafe - SÜPER POS FONKSİYONLARI (2025)

require_once 'pos.php'; // Senin mevcut vardiya fonksiyonları

// 1. Fiş basma (QR kodlu + kurye takip)
function print_receipt_extended($order_data, $delivery = false) {
    global $db;
    
    $items = $order_data['items'];
    $total = $order_data['total'];
    $order_id = $order_data['order_id'] ?? null;
    
    // QR Kod (paket servis için)
    $qr_url = $delivery && $order_id ? "https://sabl.com.tr/takip.php?id=$order_id" : "";
    
    // Fiş içeriği
    $receipt = "=============================\n";
    $receipt .= "     ALÇİTEPE CAFE\n";
    $receipt .= "=============================\n";
    foreach($items as $item) {
        $receipt .= sprintf("%-20s %2dx %6.2f\n", 
            substr($item['name'],0,20), $item['qty'], $item['unit_price']);
        $receipt .= sprintf("%38s\n", number_format($item['unit_price']*$item['qty'],2)." TL");
    }
    $receipt .= "-----------------------------\n";
    $receipt .= sprintf("TOPLAM: %28s TL\n", number_format($total,2));
    if ($qr_url) {
        $receipt .= "\nTakip için QR okutun:\n";
        $receipt .= "$qr_url\n";
    }
    $receipt .= "Teşekkür ederiz!\n";
    
    // TCP ile yazıcıya gönder (senin pos_fast.php mantığı)
    $printer_ip = "192.168.1.100"; // ayarlara çekilebilir
    $fp = @fsockopen("tcp://$printer_ip", 9100, $errno, $errstr, 10);
    if ($fp) {
        fwrite($fp, $receipt . chr(27) . chr(105)); // kesme komutu
        fclose($fp);
        return true;
    }
    return false;
}

// 2. Stok düşümü + reçete
function update_stock_from_receipt($items) {
    global $db;
    foreach($items as $item) {
        // Normal stok düşümü
        $db->query("UPDATE products SET stock_quantity = stock_quantity - {$item['qty']} WHERE id = {$item['id']}");
        
        // Reçete kontrolü (varsa)
        $recipes = $db->query("SELECT ingredient_id, quantity FROM recipe_items WHERE product_id = {$item['id']}");
        while($r = $recipes->fetch_assoc()) {
            $db->query("UPDATE ingredients SET stock = stock - ({(($r[quantity]) * {$item['qty']}) WHERE id = {$r['ingredient_id']}");
        }
    }
}

// 3. Puan ekleme (müşteri + personel)
function add_points($customer_id, $personnel_id, $amount) {
    global $db, $db_main;
    
    // Müşteri puanı
    if ($customer_id) {
        $points = floor($amount); // 1 TL = 1 puan
        $db_main->query("INSERT INTO customer_promotions (customer_id, points) VALUES ($customer_id, $points) 
                         ON DUPLICATE KEY UPDATE points = points + $points");
    }
    
    // Personel puanı
    if ($personnel_id) {
        $db->query("UPDATE personnel SET sales_points = sales_points + $points WHERE id = $personnel_id");
    }
}