<?php
// functions/pos_fast.php
// Composer YOK – Tek dosya ile direkt yazıcıya gönderim (raw TCP veya Windows print)

function print_receipt($order_data) {
    // AYARLAR – BURAYI DEĞİŞTİR
    $printer_ip   = "192.168.1.100";     // Yazıcı IP'si (yazıcı ayarlarından bak)
    $printer_port = 9100;                // Termik yazıcıların standart portu
    // Eğer IP yerine Windows paylaşımlı yazıcı kullanıyorsan alttaki satırı aç:
    // $printer_name = "XP-58";          // Windows yazıcı adı (aşağıdaki Windows kısmını aç)

    $receipt = "";
    $receipt .= str_pad("ALÇİTEPE CAFE", 32, " ", STR_PAD_BOTH) . "\n";
    $receipt .= str_pad("Lezzet Durağınız", 32, " ", STR_PAD_BOTH) . "\n";
    $receipt .= "================================" . "\n";

    foreach ($order_data['items'] as $item) {
        $name = substr($item['name'], 0, 20);
        $name = str_pad($name, 20);
        $qty  = str_pad($item['qty'] . "x", 4, " ", STR_PAD_LEFT);
        $price = str_pad(number_format($item['price'], 2), 8, " ", STR_PAD_LEFT);
        $total = number_format($item['price'] * $item['qty'], 2);
        $receipt .= $name . $qty . $price . "\n";
        $receipt .= str_pad(" ", 28) . str_pad($total . " TL", 8, " ", STR_PAD_LEFT) . "\n";
    }

    $receipt .= "================================" . "\n";
    $receipt .= str_pad("TOPLAM:", 24) . str_pad(number_format($order_data['total'], 2) . " TL", 12, " ", STR_PAD_LEFT) . "\n";
    $receipt .= str_pad("Ödeme: " . $order_data['payment_method'], 36) . "\n";
    $receipt .= str_pad("Tarih: " . date('d.m.Y H:i'), 36) . "\n";
    $receipt .= str_pad("Kasiyer: " . ($_SESSION['personnel_name'] ?? 'Kasiyer'), 36) . "\n";
    $receipt .= "\n";
    $receipt .= str_pad("TEŞEKKÜR EDERİZ!", 32, " ", STR_PAD_BOTH) . "\n\n\n";
    $receipt .= chr(27) . chr(105); // Kesme komutu (ESC i)

    // 1. YOL: IP ile direkt TCP (çoğu termik yazıcı bu şekilde çalışır)
    if ($printer_ip != "") {
        $fp = @fsockopen("tcp://".$printer_ip, $printer_port, $errno, $errstr, 10);
        if ($fp) {
            fwrite($fp, $receipt);
            fclose($fp);
            return true;
        }
    }

    // 2. YOL: Windows paylaşımlı yazıcı (IP çalışmazsa bunu dene)
    /*
    $printer_name = "XP-58"; // Windows'taki tam yazıcı adı
    $handle = printer_open($printer_name);
    if ($handle) {
        printer_write($handle, $receipt);
        printer_close($handle);
        return true;
    }
    */

    // Hiçbiri çalışmazsa
    error_log("Fiş basılamadı! IP: $printer_ip");
    return false;
}