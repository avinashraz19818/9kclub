<?php
// =======================================================
// Automated Game Sync Engine (API & Catalog Synchronizer)
// =======================================================

require_once __DIR__ . '/bootstrap.php';

/**
 * Ensure the games table has all required columns and proper indexes.
 */
function game_sync_ensure_schema(?mysqli $conn = null): void
{
    $conn = $conn ?: db();
    if (!$conn) return;

    $conn->query("CREATE TABLE IF NOT EXISTS games (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        category_code VARCHAR(64) NOT NULL DEFAULT 'Other',
        vendor_code VARCHAR(64) NOT NULL DEFAULT '',
        game_id BIGINT NULL,
        game_code VARCHAR(128) NOT NULL,
        name VARCHAR(255) NOT NULL DEFAULT '',
        game_name VARCHAR(255) NOT NULL DEFAULT '',
        category VARCHAR(64) NOT NULL DEFAULT 'Other',
        img VARCHAR(512) NOT NULL DEFAULT '',
        sort INT NOT NULL DEFAULT 0,
        rtp DECIMAL(5,2) NOT NULL DEFAULT 96.00,
        is_maintenance TINYINT(1) NOT NULL DEFAULT 0,
        status TINYINT(1) NOT NULL DEFAULT 1,
        state TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_game_vendor (game_code, vendor_code),
        INDEX idx_category (category_code),
        INDEX idx_vendor (vendor_code),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Check for missing columns and add if needed
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM games");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[strtolower($row['Field'])] = true;
        }
    }

    $addCols = [
        'category_code' => "VARCHAR(64) NOT NULL DEFAULT 'Other'",
        'vendor_code' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'game_id' => "BIGINT NULL",
        'game_code' => "VARCHAR(128) NOT NULL DEFAULT ''",
        'name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'game_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'category' => "VARCHAR(64) NOT NULL DEFAULT 'Other'",
        'img' => "VARCHAR(512) NOT NULL DEFAULT ''",
        'sort' => "INT NOT NULL DEFAULT 0",
        'rtp' => "DECIMAL(5,2) NOT NULL DEFAULT 96.00",
        'is_maintenance' => "TINYINT(1) NOT NULL DEFAULT 0",
        'status' => "TINYINT(1) NOT NULL DEFAULT 1",
        'state' => "TINYINT(1) NOT NULL DEFAULT 1",
        'updated_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($addCols as $col => $def) {
        if (!isset($cols[$col])) {
            @$conn->query("ALTER TABLE games ADD COLUMN `{$col}` {$def}");
        }
    }

    // Ensure unique index on (game_code, vendor_code)
    $hasUnique = false;
    $idxRes = @$conn->query("SHOW INDEX FROM games WHERE Key_name = 'uq_game_vendor'");
    if ($idxRes && $idxRes->num_rows > 0) {
        $hasUnique = true;
    }
    if (!$hasUnique) {
        @$conn->query("ALTER TABLE games ADD UNIQUE KEY uq_game_vendor (game_code, vendor_code)");
    }
}

/**
 * Fetch games from PreferAPI / Nexus Gaming.
 * NOTE: Sports providers are strictly excluded from PreferAPI as sports is exclusively handled by BetsAPI.
 */
function game_sync_fetch_preferapi(): array
{
    $cfg = preferapi_config();
    if (empty($cfg['api_key']) || $cfg['api_key'] === 'YOUR_MERCHANT_API_KEY') {
        return ['success' => false, 'error' => 'PreferAPI key not configured in settings.'];
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_key'],
    ];

    $ch = curl_init('https://panel.preferapi.info/api/v1/games');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'PreferAPI connection error: ' . $err];
    }
    if ($code !== 200) {
        return ['success' => false, 'error' => 'PreferAPI HTTP ' . $code . ': ' . substr((string)$res, 0, 150)];
    }

    $data = json_decode((string)$res, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON from PreferAPI'];
    }

    $games = $data['data'] ?? $data['games'] ?? $data;
    if (!is_array($games)) {
        return ['success' => false, 'error' => 'No games list found in PreferAPI response'];
    }

    // Filter out any sports games from PreferAPI
    $casinoGames = [];
    foreach ($games as $g) {
        $c = strtolower((string)($g['category'] ?? $g['categoryName'] ?? ''));
        $v = strtoupper((string)($g['vendorCode'] ?? $g['vendor'] ?? ''));
        if (str_contains($c, 'sport') || in_array($v, ['SABA', 'CMD', '9WICKETS', 'SPORTS'], true)) {
            continue; // Skip external sports providers
        }
        $casinoGames[] = $g;
    }

    return ['success' => true, 'games' => $casinoGames, 'source' => 'preferapi'];
}

/**
 * Fetch games from GameCloud API.
 */
function game_sync_fetch_gamecloud(): array
{
    $cfg = gamecloud_config();
    if (empty($cfg['api_token'])) {
        return ['success' => false, 'error' => 'GameCloud token not configured.'];
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_token'],
    ];

    $ch = curl_init(rtrim($cfg['api_url'], '/') . '/api/v1/games');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        return ['success' => false, 'error' => 'GameCloud API returned HTTP ' . $code];
    }

    $data = json_decode((string)$res, true);
    if (!is_array($data) || empty($data['data'])) {
        return ['success' => false, 'error' => 'Invalid response from GameCloud'];
    }

    $filtered = [];
    foreach ($data['data'] as $g) {
        $c = strtolower((string)($g['category'] ?? ''));
        $v = strtoupper((string)($g['vendorCode'] ?? ''));
        if (str_contains($c, 'sport') || in_array($v, ['SABA', 'CMD', '9WICKETS', 'SPORTS'], true)) {
            continue;
        }
        $filtered[] = $g;
    }

    return ['success' => true, 'games' => $filtered, 'source' => 'gamecloud'];
}

/**
 * Load games catalog from GetHomeAllGameList.html, removing legacy sports providers.
 */
function game_sync_load_catalog(): array
{
    $file = __DIR__ . '/../Home/GetHomeAllGameList.html';
    if (!file_exists($file)) {
        return ['success' => false, 'error' => 'Catalog file GetHomeAllGameList.html not found.'];
    }

    $content = file_get_contents($file);
    $json = json_decode($content, true);
    if (!$json || !isset($json['data']['games'])) {
        return ['success' => false, 'error' => 'Failed to parse GetHomeAllGameList.html'];
    }

    $flatGames = [];
    foreach ($json['data']['games'] as $cat) {
        $catCode = $cat['categoryCode'] ?? 'Other';
        $catName = $cat['categoryNameDic']['en'] ?? $catCode;

        // Skip legacy external sports providers from catalog
        if (strtolower($catName) === 'sports' || $catCode === 'C202505280608265093') {
            continue;
        }

        foreach ($cat['gameList'] as $g) {
            $g['categoryCode'] = $catCode;
            $g['categoryName'] = $catName;
            $flatGames[] = $g;
        }
    }

    return ['success' => true, 'games' => $flatGames, 'raw_catalog' => $json, 'source' => 'catalog'];
}

/**
 * Synchronize games into the database games table.
 *
 * @param mysqli|null $conn Database connection
 * @param string $provider 'auto', 'preferapi', 'gamecloud', or 'catalog'
 * @return array Sync statistics
 */
function game_sync_execute(?mysqli $conn = null, string $provider = 'auto'): array
{
    $conn = $conn ?: db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed.'];
    }

    game_sync_ensure_schema($conn);

    $fetched = null;
    $sourceUsed = 'catalog';

    // 1. Try PreferAPI if requested or configured
    if ($provider === 'preferapi' || $provider === 'auto') {
        $pRes = game_sync_fetch_preferapi();
        if (!empty($pRes['success']) && !empty($pRes['games'])) {
            $fetched = $pRes;
            $sourceUsed = 'preferapi';
        }
    }

    // 2. Try GameCloud if requested or not yet satisfied
    if (!$fetched && ($provider === 'gamecloud' || $provider === 'auto')) {
        $gRes = game_sync_fetch_gamecloud();
        if (!empty($gRes['success']) && !empty($gRes['games'])) {
            $fetched = $gRes;
            $sourceUsed = 'gamecloud';
        }
    }

    // 3. Fallback to comprehensive built-in catalog
    if (!$fetched) {
        $cRes = game_sync_load_catalog();
        if (!empty($cRes['success'])) {
            $fetched = $cRes;
            $sourceUsed = 'catalog';
        }
    }

    if (!$fetched || empty($fetched['games'])) {
        return ['success' => false, 'error' => 'No games could be loaded from API or catalog.'];
    }

    $games = $fetched['games'];
    $inserted = 0;
    $updated = 0;
    $errors = 0;
    $categoriesCount = [];

    // Prepared statement for upsert
    $stmt = $conn->prepare("INSERT INTO games (
        category_code, vendor_code, game_id, game_code, name, game_name, category, img, sort, rtp, is_maintenance, status, state
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        game_name = VALUES(game_name),
        category_code = VALUES(category_code),
        category = VALUES(category),
        img = IF(VALUES(img) != '', VALUES(img), img),
        sort = VALUES(sort),
        updated_at = NOW()");

    foreach ($games as $g) {
        $gameCode = trim((string)($g['gameCode'] ?? $g['game_code'] ?? $g['code'] ?? ''));
        $vendorCode = trim((string)($g['vendorCode'] ?? $g['vendor_code'] ?? $g['vendor'] ?? ''));
        $name = trim((string)($g['name'] ?? $g['gameName'] ?? $g['game_name'] ?? $gameCode));
        $img = trim((string)($g['img'] ?? $g['image'] ?? $g['icon'] ?? ''));
        $gameId = !empty($g['gameId']) ? (int)$g['gameId'] : (!empty($g['id']) ? (int)$g['id'] : null);
        $sort = (int)($g['sort'] ?? 0);
        $rtp = isset($g['rtp']) ? (float)$g['rtp'] : 96.00;
        $isMaintenance = !empty($g['isGameMaintenance']) || !empty($g['is_maintenance']) ? 1 : 0;
        $status = 1;
        $state = 1;

        $catCode = trim((string)($g['categoryCode'] ?? $g['category_code'] ?? 'Other'));
        $catName = trim((string)($g['categoryName'] ?? $g['category'] ?? $catCode));

        // Skip legacy sports providers from other panels
        if (in_array(strtoupper($vendorCode), ['SABA', 'CMD', '9WICKETS'], true) || strtolower($catName) === 'sports') {
            continue;
        }

        if ($gameCode === '') {
            continue;
        }

        $catKey = $catName ?: $catCode;
        $categoriesCount[$catKey] = ($categoriesCount[$catKey] ?? 0) + 1;

        if ($stmt) {
            $stmt->bind_param(
                'ssisssssidiii',
                $catCode,
                $vendorCode,
                $gameId,
                $gameCode,
                $name,
                $name,
                $catName,
                $img,
                $sort,
                $rtp,
                $isMaintenance,
                $status,
                $state
            );
            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    $inserted++;
                } else {
                    $updated++;
                }
            } else {
                $errors++;
            }
        }
    }

    if ($stmt) {
        $stmt->close();
    }

    // Ensure internal lottery games are present
    $lotteryGames = [
        ['WinGo_30S', 'WinGo 30sec', 'ARLottery', 'Lottery', 'C202505280608510046', 10],
        ['WinGo_1M', 'WinGo 1 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 10],
        ['WinGo_3M', 'WinGo 3 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 10],
        ['WinGo_5M', 'WinGo 5 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 10],
        ['K3_1M', 'K3 1 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 9],
        ['K3_3M', 'K3 3 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 9],
        ['K3_5M', 'K3 5 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 9],
        ['D5_1M', '5D 1 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 8],
        ['D5_3M', '5D 3 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 8],
        ['D5_5M', '5D 5 Min', 'ARLottery', 'Lottery', 'C202505280608510046', 8],
        ['MotoRace_1M', 'Moto Racing', 'ARLottery', 'Lottery', 'C202505280608510046', 7],
        ['TrxWinGo_1M', 'TRX WinGo', 'ARLottery', 'Lottery', 'C202505280608510046', 6],
    ];

    foreach ($lotteryGames as $lg) {
        $gCode = $lg[0];
        $gName = $lg[1];
        $vCode = $lg[2];
        $cCode = $lg[3];
        $catCode = $lg[4];
        $gSort = $lg[5];
        @$conn->query("INSERT INTO games(game_code, name, game_name, vendor_code, category_code, category, sort, status, state, is_maintenance)
            VALUES('" . $conn->real_escape_string($gCode) . "', '" . $conn->real_escape_string($gName) . "', '" . $conn->real_escape_string($gName) . "', '{$vCode}', '{$catCode}', '{$cCode}', {$gSort}, 1, 1, 0)
            ON DUPLICATE KEY UPDATE name=VALUES(name), game_name=VALUES(game_name)");
    }

    // DEDICATED BETSAPI SPORTS REGISTRATION
    // External sports from other panels (PreferAPI/GameCloud/SABA/CMD) are deleted/replaced by BetsAPI
    @$conn->query("DELETE FROM games WHERE vendor_code IN ('SaBa', 'CMD', '9Wickets') OR (category='Sports' AND vendor_code <> 'BETSAPI')");

    $betsapiSports = [
        ['betsapi_cricket', 'Cricket Live (IPL & World Cups)', 'BETSAPI', 'Sports', 'C202505280608265093', '/img/0/gamelogo/MG/SMG_cricketStar.png', 50],
        ['betsapi_soccer', 'Soccer / Football Live', 'BETSAPI', 'Sports', 'C202505280608265093', '/img/0/vendorlogo/050447544-28413-file_20260324050447537.webp', 49],
        ['betsapi_sportsbook', 'BetsAPI Live Sportsbook', 'BETSAPI', 'Sports', 'C202505280608265093', '/img/0/vendorlogo/050714222-28424-file_20260324050714216.webp', 48],
        ['betsapi_tennis', 'Tennis Live', 'BETSAPI', 'Sports', 'C202505280608265093', '/img/0/vendorlogo/050631256-28421-file_20260324050631249.webp', 47],
    ];

    foreach ($betsapiSports as $bs) {
        $cCode = $conn->real_escape_string($bs[0]);
        $cName = $conn->real_escape_string($bs[1]);
        $cVend = $bs[2];
        $cCat = $bs[3];
        $cCatCode = $bs[4];
        $cImg = $bs[5];
        $cSort = (int)$bs[6];

        @$conn->query("INSERT INTO games(game_code, name, game_name, vendor_code, category, category_code, img, sort, status, state, is_maintenance)
            VALUES('{$cCode}', '{$cName}', '{$cName}', '{$cVend}', '{$cCat}', '{$cCatCode}', '{$cImg}', {$cSort}, 1, 1, 0)
            ON DUPLICATE KEY UPDATE name=VALUES(name), game_name=VALUES(game_name), category=VALUES(category), category_code=VALUES(category_code), vendor_code=VALUES(vendor_code)");
        $categoriesCount['Sports'] = ($categoriesCount['Sports'] ?? 0) + 1;
    }

    // Total games in database
    $totalCount = 0;
    $res = $conn->query("SELECT COUNT(*) c FROM games");
    if ($res) {
        $row = $res->fetch_assoc();
        $totalCount = (int)($row['c'] ?? 0);
    }

    // Save sync audit log
    $settings = site_settings();
    $settings['last_game_sync_time'] = date('Y-m-d H:i:s');
    $settings['last_game_sync_source'] = $sourceUsed;
    $settings['last_game_sync_total'] = $totalCount;
    if (function_exists('save_site_settings')) {
        save_site_settings($conn, $settings);
    }

    return [
        'success' => true,
        'source' => $sourceUsed,
        'total_in_db' => $totalCount,
        'synced_count' => count($games),
        'inserted' => $inserted,
        'updated' => $updated,
        'errors' => $errors,
        'categories' => $categoriesCount,
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}
