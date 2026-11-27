<?php
declare(strict_types=1);

/**
 * AnaBot CLI Development Dataset Seeder
 * 
 * Usage: php database/seeders/seed_sample_dataset.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__, 2) . '/.env')) {
    load_env(dirname(__DIR__, 2) . '/.env');
}

use AnaBot\Core\App;
use AnaBot\Core\Database;

$app = App::getInstance()->boot();
$db = Database::getInstance();

echo "Seeding sample dataset for Data Explorer development..." . PHP_EOL;

// 1. Check or create connector
$connector = $db->queryOne("SELECT id FROM connectors WHERE connector_code = 'google_sheets'");
$cid = $connector['id'] ?? null;
$now = date('c');

if (!$cid) {
    $db->execute("INSERT INTO connectors (connector_code, name, category, auth_type, created_at) VALUES ('google_sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);
    $cid = $db->lastInsertId();
}

// 2. Check or create user
$user = $db->queryOne("SELECT id FROM users LIMIT 1");
$uid = $user['id'] ?? 1;

// 3. Create platform connection if none
$conn = $db->queryOne("SELECT id FROM platform_connections WHERE connector_id = :cid LIMIT 1", [':cid' => $cid]);
$connId = $conn['id'] ?? null;
if (!$connId) {
    $db->execute(
        "INSERT INTO platform_connections (connector_id, user_id, name, status, last_sync_at, last_sync_status, created_at, updated_at) 
         VALUES (:cid, :uid, 'E-Commerce Google Sheet', 'connected', :now, 'success', :now, :now)",
        [':cid' => $cid, ':uid' => $uid, ':now' => $now]
    );
    $connId = $db->lastInsertId();
}

// 4. Create data source
$source = $db->queryOne("SELECT id FROM data_sources WHERE connection_id = :cid LIMIT 1", [':cid' => $connId]);
$sourceId = $source['id'] ?? null;
if (!$sourceId) {
    $db->execute(
        "INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at)
         VALUES (:cid, 'sheet_penjualan_2026', 'Data Penjualan Toko Online', :now, :now)",
        [':cid' => $connId, ':now' => $now]
    );
    $sourceId = $db->lastInsertId();
}

// 5. Create Dataset
$dataset = $db->queryOne("SELECT id FROM datasets WHERE data_source_id = :sid LIMIT 1", [':sid' => $sourceId]);
$datasetId = $dataset['id'] ?? null;
if (!$datasetId) {
    $db->execute(
        "INSERT INTO datasets (data_source_id, name, description, record_count, first_record_at, latest_record_at, created_at, updated_at)
         VALUES (:sid, 'Laporan Penjualan Multi-Kategori 2026', 'Data transaksi penjualan online terverifikasi', 75, '2026-01-01', '2026-03-16', :now, :now)",
        [':sid' => $sourceId, ':now' => $now]
    );
    $datasetId = (int) $db->lastInsertId();

    // 6. Seed Columns
    $columns = [
        ['name' => 'tanggal', 'type' => 'DATETIME', 'date' => 1, 'metric' => 0, 'dim' => 0],
        ['name' => 'produk', 'type' => 'TEXT', 'date' => 0, 'metric' => 0, 'dim' => 1],
        ['name' => 'kategori', 'type' => 'TEXT', 'date' => 0, 'metric' => 0, 'dim' => 1],
        ['name' => 'unit_terjual', 'type' => 'INTEGER', 'date' => 0, 'metric' => 1, 'dim' => 0],
        ['name' => 'total_omset', 'type' => 'NUMERIC', 'date' => 0, 'metric' => 1, 'dim' => 0],
        ['name' => 'metode_bayar', 'type' => 'TEXT', 'date' => 0, 'metric' => 0, 'dim' => 1],
    ];

    foreach ($columns as $idx => $c) {
        $db->execute(
            "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
             VALUES (:did, :cname, :type, :pdate, :m, :dim, :ord, :now)",
            [
                ':did' => $datasetId,
                ':cname' => $c['name'],
                ':type' => $c['type'],
                ':pdate' => $c['date'],
                ':m' => $c['metric'],
                ':dim' => $c['dim'],
                ':ord' => $idx + 1,
                ':now' => $now,
            ]
        );
    }

    // 7. Seed 75 Realistic Records
    $categories = ['Elektronik', 'Fashion Pria', 'Fashion Wanita', 'Kesehatan', 'Kuliner'];
    $products = [
        'Elektronik' => ['Earphone Bluetooth TWS', 'Kabel USB-C Fast Charge', 'Powerbank 10000mAh'],
        'Fashion Pria' => ['Kemeja Flanel Slimfit', 'Celana Chino Panjang', 'Sepatu Sneakers'],
        'Fashion Wanita' => ['Blouse Katun Polos', 'Tas Selempang Kulit', 'Rok Plisket'],
        'Kesehatan' => ['Vitamin C 500mg', 'Masker Medis 3 Ply', 'Madu Murni 500ml'],
        'Kuliner' => ['Kopi Arabika 250g', 'Keripik Tempe Renyah', 'Sambal Bawang Botol'],
    ];
    $paymentMethods = ['Transfer Bank', 'E-Wallet QRIS', 'COD', 'Kartu Kredit'];

    $db->transaction(function (Database $d) use ($datasetId, $categories, $products, $paymentMethods, $now) {
        for ($i = 1; $i <= 75; $i++) {
            $cat = $categories[$i % count($categories)];
            $prodList = $products[$cat];
            $prod = $prodList[$i % count($prodList)];
            $pay = $paymentMethods[$i % count($paymentMethods)];
            $qty = ($i % 6) + 1;
            $unitPrice = 45000 + (($i * 12345) % 350000);
            $omset = $qty * $unitPrice;
            $date = date('Y-m-d', strtotime("2026-01-01 +{$i} days"));

            $data = [
                'tanggal' => $date,
                'produk' => $prod,
                'kategori' => $cat,
                'unit_terjual' => $qty,
                'total_omset' => $omset,
                'metode_bayar' => $pay,
            ];

            $json = json_encode($data);
            $hash = hash('sha256', $json);

            $d->execute(
                "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                 VALUES (:did, :eid, :rdate, :hash, :json, :now, :now)",
                [
                    ':did' => $datasetId,
                    ':eid' => "INV-2026-" . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    ':rdate' => $date,
                    ':hash' => $hash,
                    ':json' => $json,
                    ':now' => $now,
                ]
            );
        }
    });

    echo "Sample dataset created with ID: {$datasetId} and 75 records." . PHP_EOL;
} else {
    echo "Sample dataset already exists with ID: {$datasetId}." . PHP_EOL;
}
