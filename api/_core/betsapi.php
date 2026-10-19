<?php
// ====================================================================
// BetsAPI (betsapi.com) Official Sports Betting & Live Odds Engine
// Documentation: https://betsapi.com/docs/
// ====================================================================

require_once __DIR__ . '/bootstrap.php';

if (!defined('BETSAPI_DEFAULT_URL')) {
    define('BETSAPI_DEFAULT_URL', 'https://api.b365api.com');
}
if (!defined('BETSAPI_BACKUP_URL')) {
    define('BETSAPI_BACKUP_URL', 'https://api.betsapi.com');
}

/**
 * Supported Sport IDs mapping according to BetsAPI specifications.
 */
function betsapi_supported_sports(): array
{
    return [
        3 => ['id' => 3, 'name' => 'Cricket', 'slug' => 'cricket', 'icon' => 'cricket'],
        1 => ['id' => 1, 'name' => 'Soccer / Football', 'slug' => 'soccer', 'icon' => 'football'],
        13 => ['id' => 13, 'name' => 'Tennis', 'slug' => 'tennis', 'icon' => 'tennis'],
        18 => ['id' => 18, 'name' => 'Basketball', 'slug' => 'basketball', 'icon' => 'basketball'],
        92 => ['id' => 92, 'name' => 'Table Tennis', 'slug' => 'table-tennis', 'icon' => 'pingpong'],
        151 => ['id' => 151, 'name' => 'eSports', 'slug' => 'esports', 'icon' => 'gamepad'],
    ];
}

/**
 * Retrieve active BetsAPI configuration.
 */
function betsapi_config(): array
{
    $settings = function_exists('site_settings') ? site_settings() : [];

    return [
        'api_url' => trim((string)($settings['betsapi_url'] ?? BETSAPI_DEFAULT_URL)),
        'backup_url' => trim((string)($settings['betsapi_backup_url'] ?? BETSAPI_BACKUP_URL)),
        'token' => trim((string)($settings['betsapi_token'] ?? '')),
        'enabled' => !empty($settings['betsapi_enabled']),
        'min_bet' => (float)($settings['betsapi_min_bet'] ?? 10.00),
        'max_bet' => (float)($settings['betsapi_max_bet'] ?? 50000.00),
        'enabled_sports' => (array)($settings['betsapi_sports'] ?? [3, 1, 13, 18, 92]),
        'cache_inplay_sec' => (int)($settings['betsapi_cache_inplay'] ?? 30),
        'cache_upcoming_sec' => (int)($settings['betsapi_cache_upcoming'] ?? 300),
        'cache_odds_sec' => (int)($settings['betsapi_cache_odds'] ?? 60),
    ];
}

/**
 * Ensure database tables exist for sports bets and event cache.
 */
function betsapi_ensure_tables(?mysqli $conn = null): void
{
    $conn = $conn ?: db();
    if (!$conn) return;

    static $checked = false;
    if ($checked) return;
    $checked = true;

    // Cache table for BetsAPI rate limit preservation (3600 req/hr limit)
    $conn->query("CREATE TABLE IF NOT EXISTS sports_events_cache (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cache_key VARCHAR(160) NOT NULL UNIQUE,
        sport_id INT NOT NULL DEFAULT 0,
        event_type VARCHAR(32) NOT NULL DEFAULT 'inplay',
        response_json MEDIUMTEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key_expires (cache_key, expires_at),
        INDEX idx_sport_type (sport_id, event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Sports bets ticket ledger
    $conn->query("CREATE TABLE IF NOT EXISTS sports_bets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        bet_id VARCHAR(64) NOT NULL UNIQUE,
        user_id BIGINT NOT NULL,
        sport_id INT NOT NULL,
        sport_name VARCHAR(64) NOT NULL DEFAULT '',
        event_id VARCHAR(64) NOT NULL,
        league_name VARCHAR(255) NOT NULL DEFAULT '',
        home_team VARCHAR(255) NOT NULL DEFAULT '',
        away_team VARCHAR(255) NOT NULL DEFAULT '',
        market_key VARCHAR(64) NOT NULL DEFAULT 'match_winner',
        market_name VARCHAR(128) NOT NULL DEFAULT 'Match Winner',
        selection VARCHAR(128) NOT NULL,
        selection_key VARCHAR(64) NOT NULL DEFAULT '',
        odds DECIMAL(8,3) NOT NULL,
        stake DECIMAL(15,2) NOT NULL,
        potential_payout DECIMAL(15,2) NOT NULL,
        actual_payout DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        status ENUM('pending', 'won', 'lost', 'void', 'cancelled') NOT NULL DEFAULT 'pending',
        settled_at DATETIME NULL,
        settle_notes VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_event (event_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Make cached HTTP request to BetsAPI.
 *
 * @param string $endpoint API endpoint (e.g. '/v3/events/inplay')
 * @param array $params Query parameters
 * @param int $cacheSeconds Time to cache in seconds
 * @return array Parsed response array
 */
function betsapi_request(string $endpoint, array $params = [], int $cacheSeconds = 0): array
{
    $conn = db();
    if ($conn) {
        betsapi_ensure_tables($conn);
    }

    $cfg = betsapi_config();
    $token = $cfg['token'];

    if ($token === '') {
        return [
            'success' => false,
            'error' => 'BetsAPI token not set. Please configure in Admin -> Sports Bets Settings.',
            'results' => [],
        ];
    }

    $cacheKey = md5($endpoint . '?' . http_build_query($params));

    // 1. Check database cache if caching is requested
    if ($cacheSeconds > 0 && $conn) {
        $stmt = $conn->prepare('SELECT response_json FROM sports_events_cache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $cacheKey);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $decoded = json_decode($row['response_json'], true);
                if (is_array($decoded)) {
                    $decoded['cached'] = true;
                    return $decoded;
                }
            }
        }
    }

    // 2. Perform live HTTP request
    $params['token'] = $token;
    $url = rtrim($cfg['api_url'], '/') . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: YourWins-Sportsbook/1.0',
        ],
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Failover to backup URL if connection error
    if ($curlErr && !empty($cfg['backup_url']) && $cfg['backup_url'] !== $cfg['api_url']) {
        $urlBackup = rtrim($cfg['backup_url'], '/') . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
        $ch2 = curl_init($urlBackup);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 12,
        ]);
        $rawResponse = curl_exec($ch2);
        $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch2);
        curl_close($ch2);
    }

    if ($curlErr) {
        return [
            'success' => false,
            'error' => 'Connection error: ' . $curlErr,
            'results' => [],
        ];
    }

    $json = json_decode((string)$rawResponse, true);
    if (!is_array($json)) {
        return [
            'success' => false,
            'error' => 'Invalid JSON from BetsAPI (HTTP ' . $httpCode . ')',
            'raw' => $rawResponse,
            'results' => [],
        ];
    }

    // Check BetsAPI error format: {"success": 0, "error": "...", "error_detail": "..."}
    if (isset($json['success']) && (int)$json['success'] === 0) {
        return [
            'success' => false,
            'error' => $json['error'] ?? 'API error',
            'error_detail' => $json['error_detail'] ?? '',
            'results' => [],
        ];
    }

    // 3. Save successful response to cache
    if ($cacheSeconds > 0 && $conn && !empty($json['results'])) {
        $sportId = (int)($params['sport_id'] ?? 0);
        $eventType = strpos($endpoint, 'inplay') !== false ? 'inplay' : 'upcoming';
        $expiresAt = date('Y-m-d H:i:s', time() + $cacheSeconds);
        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ins = $conn->prepare('INSERT INTO sports_events_cache (cache_key, sport_id, event_type, response_json, expires_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), expires_at = VALUES(expires_at)');
        if ($ins) {
            $ins->bind_param('sisss', $cacheKey, $sportId, $eventType, $encoded, $expiresAt);
            $ins->execute();
        }
    }

    return [
        'success' => true,
        'results' => $json['results'] ?? [],
        'pager' => $json['pager'] ?? null,
    ];
}

/**
 * Fetch live In-Play sports events from BetsAPI.
 *
 * @param int $sportId 3=Cricket, 1=Soccer, 13=Tennis, 18=Basketball
 * @return array
 */
function betsapi_get_inplay_events(int $sportId = 3): array
{
    $cfg = betsapi_config();
    $cacheSec = $cfg['cache_inplay_sec'];
    $res = betsapi_request('/v3/events/inplay', ['sport_id' => $sportId], $cacheSec);

    if (empty($res['success'])) {
        // Return friendly demo fallback events if token is not yet configured
        return betsapi_mock_inplay_events($sportId, $res['error'] ?? '');
    }

    $formatted = [];
    foreach ($res['results'] as $ev) {
        $formatted[] = betsapi_normalize_event($ev, $sportId, true);
    }

    return [
        'success' => true,
        'sport_id' => $sportId,
        'type' => 'inplay',
        'events' => $formatted,
        'total' => count($formatted),
    ];
}

/**
 * Fetch Upcoming sports events from BetsAPI.
 *
 * @param int $sportId Sport ID
 * @param string $day Date YYYYMMDD (empty = today)
 * @return array
 */
function betsapi_get_upcoming_events(int $sportId = 3, string $day = ''): array
{
    $cfg = betsapi_config();
    $cacheSec = $cfg['cache_upcoming_sec'];
    $params = ['sport_id' => $sportId];
    if ($day !== '') {
        $params['day'] = $day;
    }

    $res = betsapi_request('/v3/events/upcoming', $params, $cacheSec);

    if (empty($res['success'])) {
        return betsapi_mock_upcoming_events($sportId, $res['error'] ?? '');
    }

    $formatted = [];
    foreach ($res['results'] as $ev) {
        $formatted[] = betsapi_normalize_event($ev, $sportId, false);
    }

    return [
        'success' => true,
        'sport_id' => $sportId,
        'type' => 'upcoming',
        'events' => $formatted,
        'total' => count($formatted),
    ];
}

/**
 * Fetch detailed match odds for an event (1X2, Match Winner, Totals).
 *
 * @param string $eventId BetsAPI Event ID
 * @return array
 */
function betsapi_get_event_odds(string $eventId): array
{
    $cfg = betsapi_config();
    $cacheSec = $cfg['cache_odds_sec'];

    $res = betsapi_request('/v2/event/odds/summary', ['event_id' => $eventId], $cacheSec);
    if (empty($res['success']) || empty($res['results'])) {
        $res = betsapi_request('/v2/event/odds', ['event_id' => $eventId], $cacheSec);
    }

    if (empty($res['success']) || empty($res['results'])) {
        // Generate realistic default odds if API odds are unavailable or locked
        return betsapi_generate_default_odds($eventId);
    }

    $oddsData = $res['results'];
    return [
        'success' => true,
        'event_id' => $eventId,
        'markets' => betsapi_parse_odds_markets($oddsData),
    ];
}

/**
 * Normalize BetsAPI raw event structure to clean standard format.
 */
function betsapi_normalize_event(array $ev, int $sportId, bool $isInPlay): array
{
    $home = $ev['home']['name'] ?? 'Home Team';
    $away = $ev['away']['name'] ?? 'Away Team';
    $league = $ev['league']['name'] ?? 'Championship';
    $eventId = (string)($ev['id'] ?? $ev['event_id'] ?? '');

    // Format score / state
    $ss = $ev['ss'] ?? ''; // e.g. "1-0" in soccer, "185/4" in cricket
    $timer = $ev['timer'] ?? ''; // e.g. "72:15"
    $time = (int)($ev['time'] ?? time());

    // Generate balanced baseline odds
    $homeOdds = 1.85;
    $drawOdds = 3.20;
    $awayOdds = 2.10;

    // Use event scores or odds if attached
    if (!empty($ev['odds']['1x2'])) {
        $homeOdds = (float)($ev['odds']['1x2']['home'] ?? 1.85);
        $drawOdds = (float)($ev['odds']['1x2']['draw'] ?? 3.20);
        $awayOdds = (float)($ev['odds']['1x2']['away'] ?? 2.10);
    }

    return [
        'id' => $eventId,
        'sport_id' => $sportId,
        'is_inplay' => $isInPlay,
        'league' => $league,
        'home_team' => $home,
        'away_team' => $away,
        'score' => $ss,
        'time_str' => is_array($timer) ? ($timer['tm'] ?? '') : (string)$timer,
        'start_time' => date('Y-m-d H:i:s', $time),
        'timestamp' => $time,
        'odds' => [
            'home' => round($homeOdds, 2),
            'draw' => $sportId === 1 ? round($drawOdds, 2) : null,
            'away' => round($awayOdds, 2),
        ],
    ];
}

/**
 * Parse BetsAPI odds format into unified betting markets.
 */
function betsapi_parse_odds_markets(array $oddsData): array
{
    $markets = [];

    // 1X2 / Match Winner
    if (isset($oddsData['Bet365']['sp']['main']['sp']['to_win_match']) || isset($oddsData['Bet365']['sp']['1_x_2'])) {
        $raw = $oddsData['Bet365']['sp']['1_x_2'] ?? $oddsData['Bet365']['sp']['main']['sp']['to_win_match'] ?? [];
        $markets[] = [
            'key' => 'match_winner',
            'name' => 'Match Winner',
            'outcomes' => [
                ['key' => '1', 'name' => 'Home Win', 'odds' => (float)($raw['odds'][0]['odds'] ?? 1.85)],
                ['key' => 'X', 'name' => 'Draw', 'odds' => (float)($raw['odds'][1]['odds'] ?? 3.20)],
                ['key' => '2', 'name' => 'Away Win', 'odds' => (float)($raw['odds'][2]['odds'] ?? 2.10)],
            ]
        ];
    } else {
        // Default standard market
        $markets[] = [
            'key' => 'match_winner',
            'name' => 'Match Winner',
            'outcomes' => [
                ['key' => '1', 'name' => 'Home', 'odds' => 1.85],
                ['key' => '2', 'name' => 'Away', 'odds' => 2.05],
            ]
        ];
    }

    // Totals / Over Under
    $markets[] = [
        'key' => 'totals',
        'name' => 'Totals (Over / Under)',
        'outcomes' => [
            ['key' => 'over', 'name' => 'Over', 'odds' => 1.90],
            ['key' => 'under', 'name' => 'Under', 'odds' => 1.90],
        ]
    ];

    return $markets;
}

/**
 * Fallback realistic odds generator.
 */
function betsapi_generate_default_odds(string $eventId): array
{
    $seed = crc32($eventId);
    $h = 1.50 + (($seed % 100) / 100);
    $a = 1.60 + ((abs($seed * 3) % 120) / 100);
    $d = 3.10 + (($seed % 50) / 100);

    return [
        'success' => true,
        'event_id' => $eventId,
        'markets' => [
            [
                'key' => 'match_winner',
                'name' => 'Match Winner',
                'outcomes' => [
                    ['key' => '1', 'name' => 'Home Win', 'odds' => round($h, 2)],
                    ['key' => 'X', 'name' => 'Draw', 'odds' => round($d, 2)],
                    ['key' => '2', 'name' => 'Away Win', 'odds' => round($a, 2)],
                ]
            ],
            [
                'key' => 'totals',
                'name' => 'Match Total Over/Under',
                'outcomes' => [
                    ['key' => 'over', 'name' => 'Over', 'odds' => 1.88],
                    ['key' => 'under', 'name' => 'Under', 'odds' => 1.92],
                ]
            ]
        ]
    ];
}

/**
 * Place a sports bet using logged-in user balance with database transaction lock.
 *
 * @param array $user Current user array
 * @param array $betData Bet parameters
 * @return array Bet result
 */
function betsapi_place_bet(array $user, array $betData): array
{
    $conn = db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed.'];
    }

    betsapi_ensure_tables($conn);

    $cfg = betsapi_config();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Please login to place bets.'];
    }

    $stake = round((float)($betData['stake'] ?? 0), 2);
    $odds = round((float)($betData['odds'] ?? 0), 3);
    $eventId = trim((string)($betData['event_id'] ?? ''));
    $sportId = (int)($betData['sport_id'] ?? 3);
    $sportName = trim((string)($betData['sport_name'] ?? 'Cricket'));
    $leagueName = trim((string)($betData['league_name'] ?? 'Championship'));
    $homeTeam = trim((string)($betData['home_team'] ?? 'Home'));
    $awayTeam = trim((string)($betData['away_team'] ?? 'Away'));
    $marketKey = trim((string)($betData['market_key'] ?? 'match_winner'));
    $marketName = trim((string)($betData['market_name'] ?? 'Match Winner'));
    $selection = trim((string)($betData['selection'] ?? ''));
    $selectionKey = trim((string)($betData['selection_key'] ?? '1'));

    if ($stake < $cfg['min_bet']) {
        return ['success' => false, 'error' => 'Minimum bet is ₹' . number_format($cfg['min_bet'], 2)];
    }
    if ($stake > $cfg['max_bet']) {
        return ['success' => false, 'error' => 'Maximum bet is ₹' . number_format($cfg['max_bet'], 2)];
    }
    if ($odds < 1.01) {
        return ['success' => false, 'error' => 'Invalid odds format. Minimum odds is 1.01'];
    }
    if ($eventId === '' || $selection === '') {
        return ['success' => false, 'error' => 'Invalid event or market selection.'];
    }

    $potentialPayout = round($stake * $odds, 2);
    $betId = 'SB_' . date('YmdHis') . '_' . $userId . '_' . rand(1000, 9999);

    // Atomic transaction with row lock
    $conn->begin_transaction();
    try {
        $stmtUser = $conn->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
        $stmtUser->bind_param('i', $userId);
        $stmtUser->execute();
        $uRow = $stmtUser->get_result()->fetch_assoc();
        $curBal = (float)($uRow['balance'] ?? 0);

        if ($curBal < $stake) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Insufficient balance. Available: ₹' . number_format($curBal, 2)];
        }

        $newBal = round($curBal - $stake, 2);

        // Deduct balance and increment bet volume
        $up = $conn->prepare('UPDATE users SET balance = ?, total_bet = COALESCE(total_bet, 0) + ? WHERE id = ?');
        $up->bind_param('ddi', $newBal, $stake, $userId);
        $up->execute();

        // Insert bet ticket
        $insBet = $conn->prepare('INSERT INTO sports_bets (
            bet_id, user_id, sport_id, sport_name, event_id, league_name, home_team, away_team,
            market_key, market_name, selection, selection_key, odds, stake, potential_payout, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
        $insBet->bind_param(
            'siisssssssssddd',
            $betId, $userId, $sportId, $sportName, $eventId, $leagueName, $homeTeam, $awayTeam,
            $marketKey, $marketName, $selection, $selectionKey, $odds, $stake, $potentialPayout
        );
        $insBet->execute();

        // Financial ledger log
        try {
            $recNo = 'REC_' . $betId;
            $vendor = 'BetsAPI';
            $type = 'SportsBet';
            $remark = "Bet on {$homeTeam} vs {$awayTeam} ({$selection} @ {$odds})";
            $finStmt = $conn->prepare('INSERT INTO financial_records (user_id, record_no, order_no, vendor_code, type, sub_type, amount, back_amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            if ($finStmt) {
                $finStmt->bind_param('isssssdds', $userId, $recNo, $betId, $vendor, $type, $marketKey, $stake, $newBal, $remark);
                $finStmt->execute();
            }
        } catch (Throwable $ignore) {}

        $conn->commit();

        return [
            'success' => true,
            'message' => 'Bet placed successfully!',
            'bet_id' => $betId,
            'stake' => $stake,
            'odds' => $odds,
            'potential_payout' => $potentialPayout,
            'new_balance' => $newBal,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return [
            'success' => false,
            'error' => 'Failed to place bet: ' . $e->getMessage(),
        ];
    }
}

/**
 * Retrieve user's sports bets history.
 */
function betsapi_get_user_bets(int $userId, int $page = 1, int $pageSize = 20, string $status = ''): array
{
    $conn = db();
    if (!$conn) return ['success' => false, 'bets' => []];

    betsapi_ensure_tables($conn);

    $offset = max(0, ($page - 1) * $pageSize);
    $where = 'WHERE user_id = ' . (int)$userId;
    if ($status !== '' && in_array($status, ['pending', 'won', 'lost', 'void'], true)) {
        $where .= " AND status = '" . $conn->real_escape_string($status) . "'";
    }

    $total = 0;
    $countRes = $conn->query("SELECT COUNT(*) c FROM sports_bets {$where}");
    if ($countRes) {
        $total = (int)($countRes->fetch_assoc()['c'] ?? 0);
    }

    $bets = [];
    $res = $conn->query("SELECT * FROM sports_bets {$where} ORDER BY id DESC LIMIT {$offset}, {$pageSize}");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $bets[] = $row;
        }
    }

    return [
        'success' => true,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'bets' => $bets,
    ];
}

/**
 * Automatically settle sports bets by querying BetsAPI event view or results.
 *
 * @param string|null $specificEventId Settle specific match, or null for all pending
 * @return array Settlement summary
 */
function betsapi_settle_bets(?string $specificEventId = null): array
{
    $conn = db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed.'];
    }

    betsapi_ensure_tables($conn);

    $sql = "SELECT DISTINCT event_id, sport_id FROM sports_bets WHERE status = 'pending'";
    if ($specificEventId !== null) {
        $sql .= " AND event_id = '" . $conn->real_escape_string($specificEventId) . "'";
    }

    $eventRes = $conn->query($sql);
    if (!$eventRes || $eventRes->num_rows === 0) {
        return ['success' => true, 'settled' => 0, 'message' => 'No pending sports bets found.'];
    }

    $settledCount = 0;
    $wonCount = 0;
    $lostCount = 0;

    while ($evRow = $eventRes->fetch_assoc()) {
        $eventId = $evRow['event_id'];
        $sportId = (int)$evRow['sport_id'];

        // Query BetsAPI for event details / view
        $viewRes = betsapi_request('/v1/event/view', ['event_id' => $eventId]);
        if (empty($viewRes['success']) || empty($viewRes['results'])) {
            continue;
        }

        $eventView = $viewRes['results'][0] ?? $viewRes['results'];
        $timeStatus = (int)($eventView['time_status'] ?? 0); // 3 = Ended / Finished in BetsAPI
        $scores = $eventView['ss'] ?? ''; // e.g. "2-1" or "185/4-182/9"

        // Time status 3 means match has officially ended
        if ($timeStatus !== 3) {
            continue;
        }

        // Parse winner
        $winner = betsapi_determine_winner($scores, $sportId);

        // Fetch all pending bets for this event
        $betsStmt = $conn->prepare("SELECT * FROM sports_bets WHERE event_id = ? AND status = 'pending'");
        $betsStmt->bind_param('s', $eventId);
        $betsStmt->execute();
        $pendingBets = $betsStmt->get_result();

        while ($b = $pendingBets->fetch_assoc()) {
            $betId = $b['bet_id'];
            $bUserId = (int)$b['user_id'];
            $selectionKey = $b['selection_key'];
            $payout = (float)$b['potential_payout'];
            $stake = (float)$b['stake'];

            $isWon = ($selectionKey === $winner);
            $conn->begin_transaction();
            try {
                if ($isWon) {
                    // Credit winnings
                    $upBal = $conn->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
                    $upBal->bind_param('di', $payout, $bUserId);
                    $upBal->execute();

                    $upBet = $conn->prepare('UPDATE sports_bets SET status = "won", actual_payout = ?, settled_at = NOW(), settle_notes = ? WHERE bet_id = ?');
                    $notes = "Won with score: " . $scores;
                    $upBet->bind_param('dss', $payout, $notes, $betId);
                    $upBet->execute();

                    // Financial log
                    $recNo = 'WIN_' . $betId;
                    $remark = "Sports Win: {$b['home_team']} vs {$b['away_team']} (Score: {$scores})";
                    $finStmt = $conn->prepare('INSERT INTO financial_records (user_id, record_no, order_no, vendor_code, type, sub_type, amount, back_amount, remark, created_at) VALUES (?, ?, ?, "BetsAPI", "SportsWin", ?, ?, 0, ?, NOW())');
                    if ($finStmt) {
                        $finStmt->bind_param('isssds', $bUserId, $recNo, $betId, $b['market_key'], $payout, $remark);
                        $finStmt->execute();
                    }

                    $wonCount++;
                } else {
                    // Mark as lost
                    $upBet = $conn->prepare('UPDATE sports_bets SET status = "lost", actual_payout = 0, settled_at = NOW(), settle_notes = ? WHERE bet_id = ?');
                    $notes = "Lost with score: " . $scores;
                    $upBet->bind_param('ss', $notes, $betId);
                    $upBet->execute();

                    $lostCount++;
                }

                $conn->commit();
                $settledCount++;
            } catch (Throwable $e) {
                $conn->rollback();
            }
        }
    }

    return [
        'success' => true,
        'settled' => $settledCount,
        'won' => $wonCount,
        'lost' => $lostCount,
    ];
}

/**
 * Determine match outcome from scores string.
 */
function betsapi_determine_winner(string $score, int $sportId): string
{
    // Soccer score: e.g. "2-1"
    if (strpos($score, '-') !== false) {
        $parts = explode('-', $score);
        $h = (int)trim($parts[0]);
        $a = (int)trim($parts[1]);
        if ($h > $a) return '1';
        if ($a > $h) return '2';
        return 'X';
    }

    return '1';
}

/**
 * Mock demo in-play events for instant plug-and-play testing before token configuration.
 */
function betsapi_mock_inplay_events(int $sportId, string $apiNotice = ''): array
{
    $events = [];

    if ($sportId === 3) { // Cricket
        $events = [
            [
                'id' => 'bets_cricket_101',
                'sport_id' => 3,
                'is_inplay' => true,
                'league' => 'Indian Premier League (IPL)',
                'home_team' => 'Mumbai Indians',
                'away_team' => 'Chennai Super Kings',
                'score' => '178/4 (17.2 ov)',
                'time_str' => 'Live',
                'start_time' => date('Y-m-d H:i:s', time() - 3600),
                'timestamp' => time() - 3600,
                'odds' => ['home' => 1.72, 'draw' => null, 'away' => 2.15],
            ],
            [
                'id' => 'bets_cricket_102',
                'sport_id' => 3,
                'is_inplay' => true,
                'league' => 'ICC T20 World Cup',
                'home_team' => 'India',
                'away_team' => 'Australia',
                'score' => '195/3 (18.4 ov)',
                'time_str' => 'Live',
                'start_time' => date('Y-m-d H:i:s', time() - 4200),
                'timestamp' => time() - 4200,
                'odds' => ['home' => 1.55, 'draw' => null, 'away' => 2.45],
            ],
            [
                'id' => 'bets_cricket_103',
                'sport_id' => 3,
                'is_inplay' => true,
                'league' => 'Big Bash League',
                'home_team' => 'Sydney Sixers',
                'away_team' => 'Perth Scorchers',
                'score' => '142/6 (15.0 ov)',
                'time_str' => 'Live',
                'start_time' => date('Y-m-d H:i:s', time() - 2500),
                'timestamp' => time() - 2500,
                'odds' => ['home' => 2.05, 'draw' => null, 'away' => 1.80],
            ],
        ];
    } elseif ($sportId === 1) { // Soccer
        $events = [
            [
                'id' => 'bets_soccer_201',
                'sport_id' => 1,
                'is_inplay' => true,
                'league' => 'English Premier League',
                'home_team' => 'Arsenal',
                'away_team' => 'Manchester City',
                'score' => '1-1',
                'time_str' => '68:45',
                'start_time' => date('Y-m-d H:i:s', time() - 4500),
                'timestamp' => time() - 4500,
                'odds' => ['home' => 2.30, 'draw' => 3.10, 'away' => 2.90],
            ],
            [
                'id' => 'bets_soccer_202',
                'sport_id' => 1,
                'is_inplay' => true,
                'league' => 'UEFA Champions League',
                'home_team' => 'Real Madrid',
                'away_team' => 'Bayern Munich',
                'score' => '2-1',
                'time_str' => '54:12',
                'start_time' => date('Y-m-d H:i:s', time() - 3600),
                'timestamp' => time() - 3600,
                'odds' => ['home' => 1.65, 'draw' => 3.80, 'away' => 4.50],
            ],
        ];
    } else { // Tennis / Basketball
        $events = [
            [
                'id' => 'bets_other_301',
                'sport_id' => $sportId,
                'is_inplay' => true,
                'league' => 'ATP Masters 1000',
                'home_team' => 'Carlos Alcaraz',
                'away_team' => 'Jannik Sinner',
                'score' => '6-4, 3-4',
                'time_str' => 'Set 2',
                'start_time' => date('Y-m-d H:i:s', time() - 3000),
                'timestamp' => time() - 3000,
                'odds' => ['home' => 1.88, 'draw' => null, 'away' => 1.92],
            ]
        ];
    }

    return [
        'success' => true,
        'sport_id' => $sportId,
        'type' => 'inplay',
        'events' => $events,
        'total' => count($events),
        'notice' => $apiNotice ?: 'Connect BetsAPI token in Admin -> Sports Bets to stream official real-time fixtures.',
    ];
}

/**
 * Mock demo upcoming events.
 */
function betsapi_mock_upcoming_events(int $sportId, string $apiNotice = ''): array
{
    $events = [];

    if ($sportId === 3) {
        $events = [
            [
                'id' => 'bets_cricket_u1',
                'sport_id' => 3,
                'is_inplay' => false,
                'league' => 'Indian Premier League (IPL)',
                'home_team' => 'Royal Challengers Bengaluru',
                'away_team' => 'Kolkata Knight Riders',
                'score' => '',
                'time_str' => 'Tonight 19:30',
                'start_time' => date('Y-m-d H:i:s', time() + 7200),
                'timestamp' => time() + 7200,
                'odds' => ['home' => 1.95, 'draw' => null, 'away' => 1.85],
            ],
            [
                'id' => 'bets_cricket_u2',
                'sport_id' => 3,
                'is_inplay' => false,
                'league' => 'ICC T20 International',
                'home_team' => 'England',
                'away_team' => 'South Africa',
                'score' => '',
                'time_str' => 'Tomorrow 15:00',
                'start_time' => date('Y-m-d H:i:s', time() + 86400),
                'timestamp' => time() + 86400,
                'odds' => ['home' => 1.80, 'draw' => null, 'away' => 2.05],
            ],
        ];
    } else {
        $events = [
            [
                'id' => 'bets_soccer_u1',
                'sport_id' => 1,
                'is_inplay' => false,
                'league' => 'La Liga',
                'home_team' => 'Barcelona',
                'away_team' => 'Atletico Madrid',
                'score' => '',
                'time_str' => 'Tonight 22:00',
                'start_time' => date('Y-m-d H:i:s', time() + 14400),
                'timestamp' => time() + 14400,
                'odds' => ['home' => 1.75, 'draw' => 3.50, 'away' => 4.20],
            ]
        ];
    }

    return [
        'success' => true,
        'sport_id' => $sportId,
        'type' => 'upcoming',
        'events' => $events,
        'total' => count($events),
        'notice' => $apiNotice ?: 'Connect BetsAPI token in Admin -> Sports Bets to stream official real-time fixtures.',
    ];
}
