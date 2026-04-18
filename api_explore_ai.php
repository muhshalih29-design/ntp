<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/bootstrap.php';

$conn = ntp_db_connect();
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['reply' => 'Koneksi database gagal: ' . $conn->connect_error], JSON_UNESCAPED_UNICODE);
    exit;
}
$conn->set_charset('utf8mb4');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['reply' => 'Method tidak didukung.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    echo json_encode(['reply' => 'Silakan tulis pertanyaan terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$norm = function(string $v): string {
    $v = mb_strtolower(trim($v), 'UTF-8');
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v ?? '';
};

$fmt = function($n): string {
    if ($n === null) return '-';
    return number_format((float)$n, 2, ',', '.');
};

$monthCase = "
CASE LOWER(TRIM(bulan))
  WHEN '1' THEN 1 WHEN 'januari' THEN 1
  WHEN '2' THEN 2 WHEN 'februari' THEN 2
  WHEN '3' THEN 3 WHEN 'maret' THEN 3
  WHEN '4' THEN 4 WHEN 'april' THEN 4
  WHEN '5' THEN 5 WHEN 'mei' THEN 5
  WHEN '6' THEN 6 WHEN 'juni' THEN 6
  WHEN '7' THEN 7 WHEN 'juli' THEN 7
  WHEN '8' THEN 8 WHEN 'agustus' THEN 8
  WHEN '9' THEN 9 WHEN 'september' THEN 9
  WHEN '10' THEN 10 WHEN 'oktober' THEN 10
  WHEN '11' THEN 11 WHEN 'november' THEN 11
  WHEN '12' THEN 12 WHEN 'desember' THEN 12
  ELSE NULL
END
";

$monthName = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$latestSql = "
SELECT tahun, $monthCase AS bulan_idx
FROM NTP_Subsektor
WHERE prov='18'
ORDER BY tahun DESC, bulan_idx DESC
LIMIT 1
";
$latestRes = $conn->query($latestSql);
$latest = $latestRes ? $latestRes->fetch_assoc() : null;
if (!$latest || !isset($latest['tahun'], $latest['bulan_idx'])) {
    echo json_encode(['reply' => 'Data NTP belum tersedia di database.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$tahun = (int)$latest['tahun'];
$bulanIdx = (int)$latest['bulan_idx'];
$bulanNama = $monthName[$bulanIdx] ?? (string)$bulanIdx;

$ruleBasedReply = function(string $q) use ($conn, $tahun, $bulanIdx, $bulanNama, $monthCase, $fmt, $monthName): ?string {
    $extractYear = function(string $text): ?int {
        if (preg_match('/\b(19|20)\d{2}\b/', $text, $m)) {
            return (int)$m[0];
        }
        return null;
    };
    $monthMap = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12
    ];
    $extractMonthIdx = function(string $text) use ($monthMap): ?int {
        foreach ($monthMap as $name => $idx) {
            if (strpos($text, $name) !== false) return $idx;
        }
        if (preg_match('/\bbulan\s*(\d{1,2})\b/', $text, $m)) {
            $n = (int)$m[1];
            return ($n >= 1 && $n <= 12) ? $n : null;
        }
        return null;
    };

    $targetYear = $extractYear($q);
    $targetMonth = $extractMonthIdx($q);

    if ((strpos($q, 'ntp tertinggi') !== false || strpos($q, 'tertinggi ntp') !== false || strpos($q, 'ntp terbesar') !== false || strpos($q, 'terbesar ntp') !== false) && $targetYear) {
        $stmt = $conn->prepare("\n            SELECT tahun, $monthCase AS bulan_idx, AVG(nilai) AS ntp\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n            GROUP BY tahun, bulan_idx\n            ORDER BY ntp DESC\n            LIMIT 1\n        ");
        $stmt->bind_param('i', $targetYear);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !isset($row['bulan_idx'], $row['tahun'])) {
            return "Data NTP gabungan tahun {$targetYear} belum tersedia.";
        }
        $bulanIdxRow = (int)$row['bulan_idx'];
        $bulanLabel = $monthName[$bulanIdxRow] ?? (string)$bulanIdxRow;
        return "NTP tertinggi tahun {$targetYear} terjadi pada {$bulanLabel} {$row['tahun']} dengan nilai " . $fmt($row['ntp'] ?? null) . ".";
    }
    if ((strpos($q, 'ntp terendah') !== false || strpos($q, 'terendah ntp') !== false) && $targetYear) {
        $stmt = $conn->prepare("\n            SELECT tahun, $monthCase AS bulan_idx, AVG(nilai) AS ntp\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n            GROUP BY tahun, bulan_idx\n            ORDER BY ntp ASC\n            LIMIT 1\n        ");
        $stmt->bind_param('i', $targetYear);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !isset($row['bulan_idx'], $row['tahun'])) {
            return "Data NTP gabungan tahun {$targetYear} belum tersedia.";
        }
        $bulanIdxRow = (int)$row['bulan_idx'];
        $bulanLabel = $monthName[$bulanIdxRow] ?? (string)$bulanIdxRow;
        return "NTP terendah tahun {$targetYear} terjadi pada {$bulanLabel} {$row['tahun']} dengan nilai " . $fmt($row['ntp'] ?? null) . ".";
    }
    if ($targetYear && $targetMonth && strpos($q, 'ntp') !== false) {
        $stmt = $conn->prepare("\n            SELECT AVG(nilai) AS nilai\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND $monthCase=?\n              AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n        ");
        $stmt->bind_param('ii', $targetYear, $targetMonth);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $bulanLabel = $monthName[$targetMonth] ?? (string)$targetMonth;
        return "NTP Provinsi Lampung pada {$bulanLabel} {$targetYear} adalah " . $fmt($row['nilai'] ?? null) . ".";
    }
    if ($targetYear && $targetMonth && strpos($q, 'ntup') !== false) {
        $stmt = $conn->prepare("\n            SELECT AVG(nilai) AS nilai\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND $monthCase=? AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar usaha pertanian'\n        ");
        $stmt->bind_param('ii', $targetYear, $targetMonth);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $bulanLabel = $monthName[$targetMonth] ?? (string)$targetMonth;
        return "NTUP Provinsi Lampung pada {$bulanLabel} {$targetYear} adalah " . $fmt($row['nilai'] ?? null) . ".";
    }
    if ($targetYear && $targetMonth && (strpos($q, ' it ') !== false || strpos($q, 'ib') !== false)) {
        if (strpos($q, ' it ') !== false || strpos($q, 'it') === 0 || strpos($q, ' it') !== false) {
            $stmt = $conn->prepare("\n                SELECT AVG(nilai) AS nilai\n                FROM NTP_Subsektor\n                WHERE prov='18' AND tahun=? AND $monthCase=? AND LOWER(TRIM(subsektor))='gabungan'\n                  AND LOWER(TRIM(rincian)) IN ('indeks harga yang diterima petani','indeks harga yang diterima oleh petani')\n            ");
            $stmt->bind_param('ii', $targetYear, $targetMonth);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $bulanLabel = $monthName[$targetMonth] ?? (string)$targetMonth;
            return "It Provinsi Lampung pada {$bulanLabel} {$targetYear} adalah " . $fmt($row['nilai'] ?? null) . ".";
        }
        if (strpos($q, 'ib') !== false) {
            $stmt = $conn->prepare("\n                SELECT AVG(nilai) AS nilai\n                FROM NTP_Subsektor\n                WHERE prov='18' AND tahun=? AND $monthCase=? AND LOWER(TRIM(subsektor))='gabungan'\n                  AND LOWER(TRIM(rincian)) IN ('indeks harga yang dibayar petani','indeks harga yang dibayar oleh petani')\n            ");
            $stmt->bind_param('ii', $targetYear, $targetMonth);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $bulanLabel = $monthName[$targetMonth] ?? (string)$targetMonth;
            return "Ib Provinsi Lampung pada {$bulanLabel} {$targetYear} adalah " . $fmt($row['nilai'] ?? null) . ".";
        }
    }
    if ($targetYear && $targetMonth && strpos($q, 'andil') !== false && (strpos($q, 'terbesar') !== false || strpos($q, 'utama') !== false)) {
        $stmt = $conn->prepare("\n            SELECT komoditi, andil\n            FROM Andil_NTP\n            WHERE prov='18' AND tahun=? AND kode_bulan=?\n            ORDER BY andil DESC\n            LIMIT 10\n        ");
        $bulanStr = (string)$targetMonth;
        $stmt->bind_param('is', $targetYear, $bulanStr);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = ($r['komoditi'] ?? '-') . ' (' . $fmt($r['andil'] ?? null) . ')';
        }
        $stmt->close();
        $bulanLabel = $monthName[$targetMonth] ?? (string)$targetMonth;
        if (!$rows) {
            return "Data Andil untuk {$bulanLabel} {$targetYear} belum tersedia.";
        }
        return "Komoditas andil terbesar di Lampung ({$bulanLabel} {$targetYear}):\n- " . implode("\n- ", $rows);
    }

    if ((strpos($q, 'ntp tertinggi') !== false || strpos($q, 'tertinggi ntp') !== false || strpos($q, 'ntp terbesar') !== false || strpos($q, 'terbesar ntp') !== false) && (strpos($q, 'bulan') !== false || strpos($q, 'di bulan') !== false || strpos($q, 'bulan apa') !== false)) {
        $stmt = $conn->prepare("\n            SELECT tahun, $monthCase AS bulan_idx, AVG(nilai) AS ntp\n            FROM NTP_Subsektor\n            WHERE prov='18' AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n            GROUP BY tahun, bulan_idx\n            ORDER BY ntp DESC\n            LIMIT 1\n        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !isset($row['bulan_idx'], $row['tahun'])) {
            return "Data NTP gabungan belum tersedia untuk menentukan bulan tertinggi.";
        }
        $bulanIdxRow = (int)$row['bulan_idx'];
        $bulanLabel = $monthName[$bulanIdxRow] ?? (string)$bulanIdxRow;
        return "NTP tertinggi terjadi pada {$bulanLabel} {$row['tahun']} dengan nilai " . $fmt($row['ntp'] ?? null) . ".";
    }
    if ((strpos($q, 'ringkasan') !== false || strpos($q, 'rangkuman') !== false) && preg_match('/tahun\s*(\d{4})/u', $q, $m)) {
        $targetYear = (int) $m[1];
        $stmt = $conn->prepare("\n            SELECT tahun, $monthCase AS bulan_idx,\n              AVG(CASE WHEN LOWER(TRIM(rincian))='nilai tukar petani' THEN nilai END) AS ntp,\n              AVG(CASE WHEN LOWER(TRIM(rincian))='nilai tukar usaha pertanian' THEN nilai END) AS ntup,\n              AVG(CASE WHEN LOWER(TRIM(rincian)) IN ('indeks harga yang diterima petani','indeks harga yang diterima oleh petani') THEN nilai END) AS it,\n              AVG(CASE WHEN LOWER(TRIM(rincian)) IN ('indeks harga yang dibayar petani','indeks harga yang dibayar oleh petani') THEN nilai END) AS ib\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND LOWER(TRIM(subsektor))='gabungan'\n            GROUP BY tahun, bulan_idx\n            ORDER BY tahun ASC, bulan_idx ASC\n        ");
        $stmt->bind_param('i', $targetYear);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $idx = (int) ($r['bulan_idx'] ?? 0);
            if ($idx <= 0) continue;
            $bulanLabel = $monthName[$idx] ?? (string) $idx;
            $rows[] = $bulanLabel . ' ' . $targetYear .
                ' | NTP ' . $fmt($r['ntp'] ?? null) .
                ' | NTUP ' . $fmt($r['ntup'] ?? null) .
                ' | It ' . $fmt($r['it'] ?? null) .
                ' | Ib ' . $fmt($r['ib'] ?? null);
        }
        $stmt->close();
        if (!$rows) {
            return "Ringkasan {$targetYear} belum tersedia untuk Provinsi Lampung.";
        }
        return "Ringkasan Provinsi Lampung tahun {$targetYear} (gabungan):\n- " . implode("\n- ", $rows);
    }

    if (strpos($q, 'ntup') !== false) {
        $stmt = $conn->prepare("\n            SELECT AVG(nilai) AS nilai\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND $monthCase=? AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar usaha pertanian'\n        ");
        $stmt->bind_param('ii', $tahun, $bulanIdx);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return "NTUP terbaru Provinsi Lampung pada {$bulanNama} {$tahun} adalah " . $fmt($row['nilai'] ?? null) . ".";
    }

    if (strpos($q, 'andil') !== false && (strpos($q, 'terbesar') !== false || strpos($q, 'utama') !== false)) {
        $stmt = $conn->prepare("\n            SELECT komoditi, andil\n            FROM Andil_NTP\n            WHERE prov='18' AND tahun=? AND kode_bulan=?\n            ORDER BY andil DESC\n            LIMIT 10\n        ");
        $bulanStr = (string)$bulanIdx;
        $stmt->bind_param('is', $tahun, $bulanStr);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = ($r['komoditi'] ?? '-') . ' (' . $fmt($r['andil'] ?? null) . ')';
        }
        $stmt->close();
        if (!$rows) {
            return "Data Andil untuk {$bulanNama} {$tahun} belum tersedia.";
        }
        return "Komoditas andil terbesar di Lampung ({$bulanNama} {$tahun}):\n- " . implode("\n- ", $rows);
    }

    if (strpos($q, 'subsektor') !== false && (strpos($q, 'tertinggi') !== false || strpos($q, 'terendah') !== false)) {
        $order = (strpos($q, 'terendah') !== false) ? 'ASC' : 'DESC';
        $stmt = $conn->prepare("\n            SELECT subsektor, AVG(nilai) AS nilai\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND $monthCase=?\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n              AND LOWER(TRIM(subsektor)) <> 'gabungan'\n            GROUP BY subsektor\n            ORDER BY nilai {$order}\n            LIMIT 6\n        ");
        $stmt->bind_param('ii', $tahun, $bulanIdx);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = ucfirst((string)$r['subsektor']) . ' (' . $fmt($r['nilai'] ?? null) . ')';
        }
        $stmt->close();
        if (!$rows) {
            return "Data subsektor NTP untuk {$bulanNama} {$tahun} belum tersedia.";
        }
        $label = (strpos($q, 'terendah') !== false) ? 'terendah' : 'tertinggi';
        return "Subsektor NTP {$label} ({$bulanNama} {$tahun}):\n- " . implode("\n- ", $rows);
    }

    if (strpos($q, 'ntp') !== false || strpos($q, 'terbaru') !== false || strpos($q, 'latest') !== false) {
        $stmt = $conn->prepare("\n            SELECT AVG(nilai) AS nilai\n            FROM NTP_Subsektor\n            WHERE prov='18' AND tahun=? AND $monthCase=?\n              AND LOWER(TRIM(subsektor))='gabungan'\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n        ");
        $stmt->bind_param('ii', $tahun, $bulanIdx);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return "NTP terbaru Provinsi Lampung pada {$bulanNama} {$tahun} adalah " . $fmt($row['nilai'] ?? null) . ".";
    }

    return null;
};

$loadEnv = fn(string $key): ?string => ntp_env($key);

$buildContext = function() use ($conn, $tahun, $bulanIdx, $bulanNama, $monthCase, $fmt): string {
    $countNtp = $conn->query("SELECT COUNT(*) AS c FROM NTP_Subsektor")->fetch_assoc();
    $countAndil = $conn->query("SELECT COUNT(*) AS c FROM Andil_NTP")->fetch_assoc();

    $stmtMain = $conn->prepare("\n        SELECT\n          AVG(CASE WHEN LOWER(TRIM(rincian))='nilai tukar petani' THEN nilai END) AS ntp,\n          AVG(CASE WHEN LOWER(TRIM(rincian))='nilai tukar usaha pertanian' THEN nilai END) AS ntup,\n          AVG(CASE WHEN LOWER(TRIM(rincian)) IN ('indeks harga yang diterima petani','indeks harga yang diterima oleh petani') THEN nilai END) AS it,\n          AVG(CASE WHEN LOWER(TRIM(rincian)) IN ('indeks harga yang dibayar petani','indeks harga yang dibayar oleh petani') THEN nilai END) AS ib\n        FROM NTP_Subsektor\n        WHERE prov='18' AND tahun=? AND $monthCase=? AND LOWER(TRIM(subsektor))='gabungan'\n    ");
    $stmtMain->bind_param('ii', $tahun, $bulanIdx);
    $stmtMain->execute();
    $main = $stmtMain->get_result()->fetch_assoc();
    $stmtMain->close();

    $stmtSub = $conn->prepare("\n        SELECT subsektor, AVG(nilai) AS ntp\n        FROM NTP_Subsektor\n        WHERE prov='18' AND tahun=? AND $monthCase=?\n          AND LOWER(TRIM(rincian))='nilai tukar petani'\n          AND LOWER(TRIM(subsektor))<>'gabungan'\n        GROUP BY subsektor\n        ORDER BY ntp DESC\n        LIMIT 8\n    ");
    $stmtSub->bind_param('ii', $tahun, $bulanIdx);
    $stmtSub->execute();
    $resSub = $stmtSub->get_result();
    $subRows = [];
    while ($r = $resSub->fetch_assoc()) {
        $subRows[] = ($r['subsektor'] ?? '-') . ': ' . $fmt($r['ntp'] ?? null);
    }
    $stmtSub->close();

    $stmtAndil = $conn->prepare("\n        SELECT komoditi, andil\n        FROM Andil_NTP\n        WHERE prov='18' AND tahun=? AND kode_bulan=?\n        ORDER BY andil DESC\n        LIMIT 8\n    ");
    $bulanStr = (string)$bulanIdx;
    $stmtAndil->bind_param('is', $tahun, $bulanStr);
    $stmtAndil->execute();
    $resAndil = $stmtAndil->get_result();
    $andilRows = [];
    while ($r = $resAndil->fetch_assoc()) {
        $andilRows[] = ($r['komoditi'] ?? '-') . ': ' . $fmt($r['andil'] ?? null);
    }
    $stmtAndil->close();

    $yearRange = $conn->query("SELECT MIN(tahun) AS min_tahun, MAX(tahun) AS max_tahun FROM NTP_Subsektor WHERE prov='18'")->fetch_assoc();
    $yearsText = ($yearRange && $yearRange['min_tahun'] !== null && $yearRange['max_tahun'] !== null)
        ? ("Rentang tahun tersedia: " . (int)$yearRange['min_tahun'] . " - " . (int)$yearRange['max_tahun'])
        : "Rentang tahun tersedia: -";

    $avgYearStmt = $conn->prepare("\n        SELECT tahun,\n          AVG(CASE WHEN LOWER(TRIM(rincian))='nilai tukar petani' THEN nilai END) AS ntp,\n          AVG(CASE WHEN LOWER(TRIM(rincian))='nilai tukar usaha pertanian' THEN nilai END) AS ntup,\n          AVG(CASE WHEN LOWER(TRIM(rincian)) IN ('indeks harga yang diterima petani','indeks harga yang diterima oleh petani') THEN nilai END) AS it,\n          AVG(CASE WHEN LOWER(TRIM(rincian)) IN ('indeks harga yang dibayar petani','indeks harga yang dibayar oleh petani') THEN nilai END) AS ib\n        FROM NTP_Subsektor\n        WHERE prov='18' AND LOWER(TRIM(subsektor))='gabungan'\n        GROUP BY tahun\n        ORDER BY tahun DESC\n        LIMIT 5\n    ");
    $avgYearStmt->execute();
    $avgYearRes = $avgYearStmt->get_result();
    $yearRows = [];
    while ($r = $avgYearRes->fetch_assoc()) {
        $yearRows[] = (string)$r['tahun'] . ': NTP ' . $fmt($r['ntp'] ?? null) .
            ', NTUP ' . $fmt($r['ntup'] ?? null) .
            ', It ' . $fmt($r['it'] ?? null) .
            ', Ib ' . $fmt($r['ib'] ?? null);
    }
    $avgYearStmt->close();

    $yearListRes = $conn->query("SELECT DISTINCT tahun FROM NTP_Subsektor WHERE prov='18' ORDER BY tahun DESC LIMIT 3");
    $yearList = [];
    if ($yearListRes) {
        while ($r = $yearListRes->fetch_assoc()) {
            $yearList[] = (int)$r['tahun'];
        }
    }
    $trendSummary = '-';
    $topBottomSummary = '-';
    if (count($yearList) >= 2) {
        $yearsIn = implode(',', array_map('intval', $yearList));
        $subRes = $conn->query("\n            SELECT tahun, subsektor, AVG(nilai) AS ntp\n            FROM NTP_Subsektor\n            WHERE prov='18'\n              AND LOWER(TRIM(rincian))='nilai tukar petani'\n              AND LOWER(TRIM(subsektor))<>'gabungan'\n              AND tahun IN ({$yearsIn})\n            GROUP BY tahun, subsektor\n        ");
        $subMap = [];
        if ($subRes) {
            while ($r = $subRes->fetch_assoc()) {
                $yr = (int)$r['tahun'];
                $sub = (string)$r['subsektor'];
                $val = $r['ntp'] !== null ? (float)$r['ntp'] : null;
                if (!isset($subMap[$sub])) $subMap[$sub] = [];
                $subMap[$sub][$yr] = $val;
            }
        }

        $yearsSorted = $yearList;
        sort($yearsSorted);
        $startYear = $yearsSorted[0];
        $endYear = $yearsSorted[count($yearsSorted) - 1];

        $inc = [];
        $dec = [];
        foreach ($subMap as $sub => $vals) {
            if (!isset($vals[$startYear], $vals[$endYear]) || $vals[$startYear] == 0.0) continue;
            $pct = (($vals[$endYear] - $vals[$startYear]) / $vals[$startYear]) * 100.0;
            $entry = $sub . ' (' . number_format($pct, 2, ',', '.') . '%)';
            if ($pct >= 0) {
                $inc[] = ['pct' => $pct, 'text' => $entry];
            } else {
                $dec[] = ['pct' => $pct, 'text' => $entry];
            }
        }
        usort($inc, function($a, $b) { return $b['pct'] <=> $a['pct']; });
        usort($dec, function($a, $b) { return $a['pct'] <=> $b['pct']; });
        $incTop = array_slice(array_map(function($i) { return $i['text']; }, $inc), 0, 3);
        $decTop = array_slice(array_map(function($i) { return $i['text']; }, $dec), 0, 3);
        $trendSummary = "Kenaikan tertinggi {$startYear}-{$endYear}: " . (empty($incTop) ? '-' : implode('; ', $incTop)) .
            ". Penurunan terdalam {$startYear}-{$endYear}: " . (empty($decTop) ? '-' : implode('; ', $decTop)) . '.';

        $topBottomLines = [];
        foreach ($yearsSorted as $yr) {
            $maxSub = null;
            $minSub = null;
            foreach ($subMap as $sub => $vals) {
                if (!isset($vals[$yr])) continue;
                $v = $vals[$yr];
                if ($maxSub === null || $v > $maxSub['val']) {
                    $maxSub = ['sub' => $sub, 'val' => $v];
                }
                if ($minSub === null || $v < $minSub['val']) {
                    $minSub = ['sub' => $sub, 'val' => $v];
                }
            }
            if ($maxSub && $minSub) {
                $topBottomLines[] = $yr . ': tertinggi ' . $maxSub['sub'] . ' (' . $fmt($maxSub['val']) . '), terendah ' . $minSub['sub'] . ' (' . $fmt($minSub['val']) . ')';
            }
        }
        $topBottomSummary = empty($topBottomLines) ? '-' : implode(' | ', $topBottomLines);
    }

    return "Periode terbaru: {$bulanNama} {$tahun}\n" .
        "Jumlah baris NTP_Subsektor: " . (int)($countNtp['c'] ?? 0) . "\n" .
        "Jumlah baris Andil_NTP: " . (int)($countAndil['c'] ?? 0) . "\n" .
        $yearsText . "\n" .
        "Ringkasan rata-rata tahunan (5 tahun terakhir, gabungan): " . (empty($yearRows) ? '-' : implode(' | ', $yearRows)) . "\n" .
        "Tren subsektor (3 tahun terakhir): " . $trendSummary . "\n" .
        "Top/Bottom subsektor per tahun (3 tahun terakhir): " . $topBottomSummary . "\n" .
        "NTP gabungan terbaru: " . $fmt($main['ntp'] ?? null) . "\n" .
        "NTUP gabungan terbaru: " . $fmt($main['ntup'] ?? null) . "\n" .
        "It gabungan terbaru: " . $fmt($main['it'] ?? null) . "\n" .
        "Ib gabungan terbaru: " . $fmt($main['ib'] ?? null) . "\n" .
        "Top NTP subsektor terbaru: " . (empty($subRows) ? '-' : implode('; ', $subRows)) . "\n" .
        "Top andil komoditas terbaru: " . (empty($andilRows) ? '-' : implode('; ', $andilRows));
};

$getSchemaSummary = function() use ($conn): string {
    if ($conn->getDialect() === 'pgsql') {
        $sql = "SELECT table_name, column_name, data_type
                FROM information_schema.columns
                WHERE table_schema = 'public'
                ORDER BY table_name, ordinal_position";
    } else {
        $sql = "SELECT table_name, column_name, data_type
                FROM information_schema.columns
                WHERE table_schema = 'NTP_Lampung'
                ORDER BY table_name, ordinal_position";
    }
    $res = $conn->query($sql);
    if (!$res) {
        return 'Skema database tidak tersedia.';
    }
    $tables = [];
    while ($row = $res->fetch_assoc()) {
        $t = $row['table_name'] ?? '';
        $c = $row['column_name'] ?? '';
        $d = $row['data_type'] ?? '';
        if ($t === '' || $c === '') continue;
        if (!isset($tables[$t])) $tables[$t] = [];
        $tables[$t][] = $c . ' (' . $d . ')';
    }
    $lines = [];
    foreach ($tables as $t => $cols) {
        $lines[] = $t . ': ' . implode(', ', $cols);
    }
    return $lines ? implode("\n", $lines) : 'Skema database kosong.';
};

$validateAndNormalizeSql = function(string $sql): ?string {
    $s = trim($sql);
    if ($s === '') return null;
    if (strpos($s, ';') !== false) return null;
    $lower = mb_strtolower($s, 'UTF-8');
    if (strpos($lower, 'select') !== 0) return null;
    $forbidden = ['insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate', 'replace', 'into outfile', 'load data', 'information_schema'];
    foreach ($forbidden as $kw) {
        if (strpos($lower, $kw) !== false) return null;
    }
    $allowed = ['ntp_subsektor', 'andil_ntp', 'brs_alasan_subsektor'];
    preg_match_all('/\b(from|join)\s+([`"]?)([a-z0-9_]+)\2/iu', $lower, $m);
    foreach (($m[3] ?? []) as $tbl) {
        if (!in_array($tbl, $allowed, true)) return null;
    }
    if (strpos($lower, ' limit ') === false) {
        $s .= ' LIMIT 200';
    } else {
        $s = preg_replace('/\blimit\s+(\d+)\b/i', function($mm) {
            $n = (int) $mm[1];
            if ($n > 200) return 'LIMIT 200';
            return 'LIMIT ' . $n;
        }, $s);
    }
    return $s;
};

$extractJsonAction = function(string $text): ?array {
    $trim = trim($text);
    if ($trim === '') return null;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trim, $m)) {
        $trim = trim($m[1]);
    }
    $start = strpos($trim, '{');
    $end = strrpos($trim, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $candidate = substr($trim, $start, $end - $start + 1);
        $parsed = json_decode($candidate, true);
        if (is_array($parsed)) return $parsed;
    }
    $parsed = json_decode($trim, true);
    return is_array($parsed) ? $parsed : null;
};

$callGemini = function(string $apiKey, string $model, string $message, array $history, string $context, ?string $schema, ?string $sqlResult, bool $finalAnswer): ?string {
    if (!function_exists('curl_init')) {
        return null;
    }

    $systemText = "Anda adalah asisten analisis data NTP Lampung. Jawab dalam Bahasa Indonesia, ringkas, faktual, dan gunakan angka dari konteks database yang diberikan. Jika data tidak cukup, katakan keterbatasannya dengan jelas. Jangan mengarang angka. Anda boleh meringkas data secara menyeluruh berdasarkan konteks yang disediakan.";
    if ($finalAnswer) {
        $systemText .= " Gunakan hasil query SQL jika tersedia untuk menjawab.";
    } else {
        $systemText .= " Jika butuh data rinci, keluarkan JSON dengan format {\"action\":\"sql\",\"sql\":\"...\"}. Jika sudah cukup, keluarkan JSON {\"action\":\"answer\",\"answer\":\"...\"}.";
    }
    $contents = [];
    foreach ($history as $h) {
        $role = ($h['role'] ?? '') === 'assistant' ? 'model' : 'user';
        $content = trim((string)($h['content'] ?? ''));
        if ($content !== '') {
            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $content]
                ]
            ];
        }
    }
    $contents[] = [
        'role' => 'user',
        'parts' => [
            ['text' => $systemText .
                "\n\nKonteks data:\n" . $context .
                ($schema ? "\n\nSkema database:\n" . $schema : '') .
                ($sqlResult ? "\n\nHasil query SQL:\n" . $sqlResult : '') .
                "\n\nPertanyaan pengguna:\n" . $message
            ]
        ]
    ];

    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.2
        ]
    ];

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $json = json_decode($raw, true);
    $reply = trim((string)($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    return $reply !== '' ? $reply : null;
};

$q = $norm($message);
$fallback = $ruleBasedReply($q);

$apiKey = $loadEnv('GEMINI_API_KEY');
$model = $loadEnv('GEMINI_MODEL') ?: 'gemini-3-flash-preview';

if (!$apiKey) {
    if ($fallback !== null) {
        echo json_encode(['reply' => $fallback], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $countNtp = $conn->query("SELECT COUNT(*) AS c FROM NTP_Subsektor")->fetch_assoc();
    $countAndil = $conn->query("SELECT COUNT(*) AS c FROM Andil_NTP")->fetch_assoc();
    echo json_encode([
        'reply' => "Mode AI bebas belum aktif karena GEMINI_API_KEY belum diset.\n\nRingkasan data:\n- NTP_Subsektor: " . (int)($countNtp['c'] ?? 0) . " baris\n- Andil_NTP: " . (int)($countAndil['c'] ?? 0) . " baris\n- Periode terbaru: {$bulanNama} {$tahun}\n\nTambahkan GEMINI_API_KEY di environment atau file .env untuk mengaktifkan jawaban AI lebih fleksibel."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$historyRaw = $payload['history'] ?? [];
$history = [];
if (is_array($historyRaw)) {
    foreach (array_slice($historyRaw, -12) as $h) {
        if (!is_array($h)) continue;
        $role = (string)($h['role'] ?? 'user');
        $content = trim((string)($h['content'] ?? ''));
        if ($content === '') continue;
        $history[] = [
            'role' => $role === 'assistant' ? 'assistant' : 'user',
            'content' => mb_substr($content, 0, 1200)
        ];
    }
}

$context = $buildContext();
$schema = $getSchemaSummary();
$llmFirst = $callGemini($apiKey, $model, $message, $history, $context, $schema, null, false);

if ($llmFirst !== null) {
    $json = $extractJsonAction($llmFirst);
    if (is_array($json) && ($json['action'] ?? '') === 'sql' && isset($json['sql'])) {
        $sql = $validateAndNormalizeSql((string)$json['sql']);
        if ($sql !== null) {
            $res = $conn->query($sql);
            if ($res) {
                $rows = [];
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                    if (count($rows) >= 200) break;
                }
                if (empty($rows)) {
                    echo json_encode(['reply' => "Query berhasil, tetapi tidak ada data yang cocok."], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $cols = array_keys($rows[0]);
                $mdLines = [];
                $mdLines[] = '| ' . implode(' | ', $cols) . ' |';
                $mdLines[] = '| ' . implode(' | ', array_fill(0, count($cols), '---')) . ' |';
                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($cols as $c) {
                        $v = $r[$c];
                        $vals[] = is_numeric($v) ? number_format((float)$v, 2, ',', '.') : (string)$v;
                    }
                    $mdLines[] = '| ' . implode(' | ', $vals) . ' |';
                }
                $replyText = "Hasil query (tabel):\n" . implode("\n", $mdLines);
                echo json_encode(['reply' => $replyText], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } elseif (is_array($json) && ($json['action'] ?? '') === 'answer' && isset($json['answer'])) {
        $ans = trim((string)$json['answer']);
        if ($ans !== '') {
            echo json_encode(['reply' => $ans], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        echo json_encode(['reply' => $llmFirst], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($fallback !== null) {
    echo json_encode(['reply' => $fallback], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'reply' => "AI tidak bisa dihubungi saat ini. Coba lagi beberapa saat."
], JSON_UNESCAPED_UNICODE);
