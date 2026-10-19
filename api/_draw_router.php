<?php
require_once __DIR__ . '/_core/bootstrap.php';

$gameCode = $_GET['gameCode'] ?? '';
$path = $_GET['path'] ?? '';

if (!$gameCode && $path) {
    $parts = explode('/', trim($path, '/'));
    // /WinGo/WinGo_30S.json OR /WinGo/WinGo_30S/GetHistoryIssuePage.json
    $gameCode = $parts[1] ?? ($parts[0] ?? 'WinGo_30S');
}
$gameCode = preg_replace('/\.json$/i', '', $gameCode ?: 'WinGo_30S');
$gameCode = preg_replace('/[^A-Za-z0-9_]/', '', $gameCode ?: 'WinGo_30S');

// Normalize 5D / D5 codes (e.g. 5D_1M -> D5_1M)
if (stripos($gameCode, '5D_') === 0) {
    $gameCode = 'D5_' . substr($gameCode, 3);
}

if (str_contains($path, 'GetHistoryIssuePage')) {
    $list = [];
    $pageNo = max(1, (int)($_GET['pageNo'] ?? $_GET['page'] ?? 1));
    $set = site_settings();
    $pageSize = max(1, min(10, (int)($set['game_history_page_size'] ?? 10)));
    if (mw_is_reference_wingo_game($gameCode)) {
        $referenceList = mw_fetch_reference_history($gameCode, 1, $pageSize);
        if ($referenceList) {
            $publishedList = [];
            foreach ($referenceList as $row) {
                $stored = le_store_reference_result($gameCode, $row);
                if (!empty($stored['_persisted'])) le_settle_pending_bets($gameCode, (string)$row['issueNumber']);
                unset($stored['_persisted']);
                $publishedList[] = array_merge($row, $stored);
            }
            api_success(['list'=>$publishedList,'pageNo'=>1,'pageSize'=>count($publishedList),'totalPage'=>50,'totalCount'=>500], 'Succeed', ['serviceTime'=>now_ms(), 'result'=>true]);
        }
    }
    le_settle_pending_bets($gameCode);
    $interval = le_game_interval($gameCode);
    $now = time();
    for ($i = 1; $i <= $pageSize; $i++) {
        $offset = (($pageNo - 1) * $pageSize) + $i;
        $issueNum = le_issue_by_offset($gameCode, $offset);
        $r = le_result_for_issue($gameCode, $issueNum, true);
        $list[] = lottery_public_result($gameCode, (string)$issueNum, $r, ($now - ($offset * $interval)) * 1000);
    }
    api_success(['list'=>$list,'pageNo'=>$pageNo,'pageSize'=>$pageSize,'totalPage'=>50,'totalCount'=>500], 'Succeed', ['serviceTime'=>now_ms(), 'result'=>true]);
}

$issue = lottery_issue($gameCode);
api_success($issue, 'Succeed', [
    'result' => true,
    'previous' => $issue['previous'] ?? null,
    'current' => $issue['current'] ?? null,
    'next' => $issue['next'] ?? null,
    'intervalMinute' => $issue['intervalMinute'] ?? 1,
    'gameCode' => $gameCode,
    'state' => 1
]);
