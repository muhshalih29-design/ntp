<?php
session_start();

require_once __DIR__ . '/bootstrap.php';

// Simple auth (ganti username/password sesuai kebutuhan).
$authUsers = [
    'admin' => 'admin123'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $loginUser = trim((string) ($_POST['username'] ?? ''));
    $loginPass = (string) ($_POST['password'] ?? '');
    if ($loginUser !== '' && isset($authUsers[$loginUser]) && hash_equals($authUsers[$loginUser], $loginPass)) {
        $_SESSION['auth_user'] = $loginUser;
        $redirectPage = $_POST['redirect'] ?? 'dashboard';
        header('Location: ?page=' . urlencode($redirectPage));
        exit;
    }
    $loginError = 'Username atau password salah.';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: ?page=login');
    exit;
}

$conn = ntp_db_connect();

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

function normalizeNumericValue(string $raw): ?float {
    $value = trim(str_replace(["\xC2\xA0", "\t", "\r", "\n", " "], '', $raw));
    if ($value === '') {
        return null;
    }

    // In source CSVs, "-" commonly means no contribution (treated as 0).
    if (in_array($value, ['-', '–', '—'], true)) {
        return 0.0;
    }

    if (preg_match('/^\((.*)\)$/', $value, $matches)) {
        $value = '-' . $matches[1];
    }

    $commaPos = strrpos($value, ',');
    $dotPos = strrpos($value, '.');

    if ($commaPos !== false && $dotPos !== false) {
        if ($commaPos > $dotPos) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($commaPos !== false) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : null;
}

function detectDelimiter(string $filePath): string {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ';';
    }

    $sample = fgets($handle);
    fclose($handle);

    if ($sample === false) {
        return ';';
    }

    $delimiters = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
    arsort($delimiters);
    $detected = array_key_first($delimiters);

    return $detected ?: ';';
}

$currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowedPages = ['dashboard', 'update-data', 'generate-brs', 'generate-brs-text', 'login'];
if (!in_array($currentPage, $allowedPages, true)) {
    $currentPage = 'dashboard';
}
$protectedPages = ['update-data', 'generate-brs', 'generate-brs-text'];
if (in_array($currentPage, $protectedPages, true) && empty($_SESSION['auth_user'])) {
    $currentPage = 'login';
}

$accountMenuHtml = '';
if (!empty($_SESSION['auth_user'])) {
    $accountInitials = strtoupper(substr((string) $_SESSION['auth_user'], 0, 2));
    $accountName = htmlspecialchars((string) $_SESSION['auth_user']);
    $accountMenuHtml = '<div class="account-menu">'
        . '<button type="button" class="account-trigger" id="accountTrigger">'
        . '<span class="account-avatar">' . $accountInitials . '</span>'
        . '<span class="account-name">' . $accountName . '</span>'
        . '<span class="account-caret">&#9662;</span>'
        . '</button>'
        . '<div class="account-dropdown" id="accountDropdown">'
        . '<a href="?action=logout">Logout</a>'
        . '</div>'
        . '</div>';
}
$importStatus = '';
$importMessage = '';
$reasonStatus = '';
$reasonMessage = '';
$pendingReasonSave = null;
$pendingReasonSaveAll = null;
$subsectorReasonLabels = [
    'tp' => 'Tanaman Pangan',
    'th' => 'Tanaman Hortikultura',
    'tpr' => 'Tanaman Perkebunan Rakyat',
    'trk' => 'Peternakan',
    'ikt' => 'Ikan Tangkap',
    'ikb' => 'Ikan Budidaya'
];
$subsectorReasonValues = [
    'tp' => '',
    'th' => '',
    'tpr' => '',
    'trk' => '',
    'ikt' => '',
    'ikb' => ''
];
$subsectorReasonImpact = [
    'tp' => ['komoditi' => '-', 'andil' => null, 'it_change' => null],
    'th' => ['komoditi' => '-', 'andil' => null, 'it_change' => null],
    'tpr' => ['komoditi' => '-', 'andil' => null, 'it_change' => null],
    'trk' => ['komoditi' => '-', 'andil' => null, 'it_change' => null],
    'ikt' => ['komoditi' => '-', 'andil' => null, 'it_change' => null],
    'ikb' => ['komoditi' => '-', 'andil' => null, 'it_change' => null]
];
$uploadedPreviewRows = [];
$uploadedPreviewTruncated = false;
$undoMessages = [];
$undoStatus = '';
$deleteMessages = [];
$deleteStatus = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'undo_last_upload') {
    $currentPage = 'update-data';
    $undoStatus = 'success';
    $targets = ['NTP_Subsektor', 'Andil_NTP'];
    foreach ($targets as $tableName) {
        $batchRes = $conn->query("SELECT batch_id FROM upload_log WHERE table_name='{$tableName}' ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($batchRes && $batchRes->num_rows > 0) {
            $batchRow = $batchRes->fetch_assoc();
            $batchId = $conn->real_escape_string($batchRow['batch_id']);
            $del = $conn->query("DELETE FROM {$tableName} WHERE upload_batch_id='{$batchId}'");
            $deleted = $del ? $conn->affected_rows : 0;
            $undoMessages[] = "{$tableName}: {$deleted} baris dibatalkan.";
            $conn->query("DELETE FROM upload_log WHERE table_name='{$tableName}' AND batch_id='{$batchId}'");
        } else {
            $undoMessages[] = "{$tableName}: tidak ada batch upload yang bisa dibatalkan.";
        }
    }
    if (empty($undoMessages)) {
        $undoStatus = 'error';
        $undoMessages[] = 'Tidak ada batch upload yang bisa dibatalkan.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_by_date') {
    $currentPage = 'update-data';
    $deleteStatus = 'success';
    $dateValue = trim((string) ($_POST['delete_date'] ?? ''));
    if ($dateValue === '') {
        $deleteStatus = 'error';
        $deleteMessages[] = 'Tanggal upload wajib diisi.';
    } else {
        $targets = ['NTP_Subsektor', 'Andil_NTP'];
        foreach ($targets as $tableName) {
            $stmt = $conn->prepare("DELETE FROM {$tableName} WHERE DATE(uploaded_at)=?");
            if ($stmt) {
                $stmt->bind_param('s', $dateValue);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $deleteMessages[] = "{$tableName}: {$affected} baris dihapus untuk tanggal {$dateValue}.";
                $stmt->close();
            } else {
                $deleteStatus = 'error';
                $deleteMessages[] = "{$tableName}: Gagal menyiapkan penghapusan.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    $currentPage = 'update-data';

    $importStatus = 'success';
    $importMessages = [];
    $hasError = false;

    $processUpload = function($fileInputName, $tableName) use ($conn, &$uploadedPreviewRows, &$uploadedPreviewTruncated, &$hasError, &$importMessages) {
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        $uploadedFile = $_FILES[$fileInputName];
        $fileName = $uploadedFile['name'];
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            $hasError = true;
            $importMessages[] = "Format file $tableName harus .csv";
            return;
        }

        $tmpPath = $uploadedFile['tmp_name'];
        $delimiter = detectDelimiter($tmpPath);
        $rawContent = file_get_contents($tmpPath);

        if ($rawContent === false) {
            $hasError = true;
            $importMessages[] = "File CSV $tableName tidak bisa dibaca.";
            return;
        }

        // Normalize mixed CSV encodings (UTF-8/Windows-1252/ISO-8859-1) to UTF-8
        // so MySQL utf8mb4 columns do not fail with "Incorrect string value".
        if (function_exists('mb_convert_encoding')) {
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $rawContent);
            if ($converted !== false) {
                $rawContent = $converted;
            }
        }

        $rawContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);
        $lines = preg_split("/\r\n|\n|\r/", $rawContent);
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $duplicates = 0;
        $previewLimit = 500;
        $skippedReason = '';

        $batchId = bin2hex(random_bytes(8));
        $insertCols = ($tableName === 'Andil_NTP') 
            ? "subsektor, prov, jnsbrg, komoditi, kel, rincian, kode_bulan, tahun, andil, upload_batch_id, uploaded_at" 
            : "prov, tahun, bulan, subsektor, rincian, nilai, upload_batch_id, uploaded_at";
        $updateCols = ($tableName === 'Andil_NTP')
            ? "andil = VALUES(andil)"
            : "nilai = VALUES(nilai)";
        $placeholders = ($tableName === 'Andil_NTP')
            ? "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?"
            : "?, ?, ?, ?, ?, ?, ?, ?";

        $stmt = $conn->prepare("
            INSERT INTO $tableName ($insertCols)
            VALUES ($placeholders)
            ON DUPLICATE KEY UPDATE $updateCols
        ");

        if (!$stmt) {
            $hasError = true;
            $importMessages[] = "Gagal menyiapkan query insert $tableName: " . $conn->error;
            return;
        }

        try {
            $conn->begin_transaction();

            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') continue;

                $row = str_getcsv($line, $delimiter);
                if (!is_array($row) || count($row) === 0) {
                    $skipped++;
                    continue;
                }

                $row = array_map(function($cell) { return trim((string) $cell); }, $row);
                if (isset($row[0])) $row[0] = ltrim($row[0], "\xEF\xBB\xBF");

                $nonEmpty = array_filter($row, function($cell) { return $cell !== ''; });
                if (count($nonEmpty) === 0) continue;

                if ($tableName === 'Andil_NTP') {
                    if (count($row) < 9) {
                        if (!$skippedReason) $skippedReason = "Andil_NTP: Kolom kurang dari 9 (ditemukan " . count($row) . "). Baris: " . json_encode($row);
                        $skipped++;
                        continue;
                    }
                    $subsektor = $row[0];
                    $prov = $row[1];
                    $jnsbrg = $row[2];
                    $komoditi = $row[3];
                    $kel = $row[4];
                    $rincian = $row[5];
                    $kode_bulan = $row[6];
                    $tahun = (int) $row[7];
                    $andil = normalizeNumericValue($row[8]);

                    if ($subsektor === '' || $prov === '' || $tahun <= 0 || $kode_bulan === '' || $andil === null) {
                        if (!$skippedReason) $skippedReason = "Andil_NTP: Validasi gagal (subsektor='$subsektor', prov='$prov', tahun=$tahun, kode_bulan='$kode_bulan', andil=".var_export($andil,true)."). Baris: " . json_encode($row);
                        $skipped++;
                        continue;
                    }
                    $uploadedAt = date('Y-m-d H:i:s');
                    $stmt->bind_param('sssssssidss', $subsektor, $prov, $jnsbrg, $komoditi, $kel, $rincian, $kode_bulan, $tahun, $andil, $batchId, $uploadedAt);
                } else {
                    if (count($row) < 6) {
                        if (!$skippedReason) $skippedReason = "NTP: Kolom kurang dari 6 (ditemukan " . count($row) . "). Baris: " . json_encode($row);
                        $skipped++;
                        continue;
                    }

                    $prov = $row[0];
                    $tahun = (int) $row[1];
                    $bulan = $row[2];
                    $subsektor = $row[3];
                    $rincian = $row[4];
                    $nilai = normalizeNumericValue($row[5]);

                    if ($prov === '' || $tahun <= 0 || $bulan === '' || $subsektor === '' || $rincian === '' || $nilai === null) {
                        if (!$skippedReason) $skippedReason = "NTP: Validasi gagal (prov='$prov', tahun=$tahun, bulan='$bulan', nilai=".var_export($nilai,true)."). Baris: " . json_encode($row);
                        $skipped++;
                        continue;
                    }

                    $uploadedAt = date('Y-m-d H:i:s');
                    $stmt->bind_param('sisssdss', $prov, $tahun, $bulan, $subsektor, $rincian, $nilai, $batchId, $uploadedAt);
                }

                if (!$stmt->execute()) {
                    throw new RuntimeException($stmt->error);
                }

                if ($stmt->affected_rows === 1) {
                    $inserted++;
                    if ($tableName === 'NTP_Subsektor') {
                        if (count($uploadedPreviewRows) < $previewLimit) {
                            $uploadedPreviewRows[] = [
                                'prov' => $prov, 'tahun' => $tahun, 'bulan' => $bulan,
                                'subsektor' => $subsektor, 'rincian' => $rincian, 'nilai' => $nilai
                            ];
                        } else {
                            $uploadedPreviewTruncated = true;
                        }
                    }
                } elseif ($stmt->affected_rows === 2) {
                    $updated++;
                } else {
                    $duplicates++;
                }
            }

            $conn->commit();
            if ($skipped > 0 && $skippedReason !== '') {
                $importMessages[] = "INFO DEBUG ($tableName): " . $skippedReason;
            }
            $importMessages[] = "$tableName: $inserted baris berhasil ditambahkan, $updated baris diperbarui, $duplicates duplikat dilewati, $skipped baris tidak valid dilewati.";
            $logStmt = $conn->prepare("INSERT INTO upload_log (table_name, batch_id, inserted, updated, duplicates, skipped) VALUES (?, ?, ?, ?, ?, ?)");
            if ($logStmt) {
                $logStmt->bind_param('ssiiii', $tableName, $batchId, $inserted, $updated, $duplicates, $skipped);
                $logStmt->execute();
                $logStmt->close();
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $hasError = true;
            $importMessages[] = "Import $tableName gagal: " . $e->getMessage();
        }

        $stmt->close();
    };

    $processUpload('csv_file_ntp', 'NTP_Subsektor');
    $processUpload('csv_file_andil', 'Andil_NTP');

    if (empty($importMessages)) {
        $importStatus = 'error';
        $importMessages[] = 'Silakan pilih file CSV untuk diupload.';
    } else {
        $importStatus = $hasError ? 'error' : 'success';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_subsector_reason') {
    $currentPage = 'generate-brs';
    $pendingReasonSave = [
        'key' => strtolower(trim((string) ($_POST['reason_key'] ?? ''))),
        'text' => trim((string) ($_POST['reason_text'] ?? '')),
        'tahun' => (int) ($_POST['brs_tahun'] ?? 0),
        'bulan' => (int) ($_POST['brs_bulan'] ?? 0)
    ];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_subsector_reasons_all') {
    $currentPage = 'generate-brs';
    $pendingReasonSaveAll = [
        'tahun' => (int) ($_POST['brs_tahun'] ?? 0),
        'bulan' => (int) ($_POST['brs_bulan'] ?? 0),
        'reasons' => []
    ];
    foreach ($subsectorReasonLabels as $reasonKey => $_label) {
        $pendingReasonSaveAll['reasons'][$reasonKey] = trim((string) ($_POST['reason_' . $reasonKey] ?? ''));
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonPayload = $rawInput ? json_decode($rawInput, true) : null;
    if (is_array($jsonPayload) && ($jsonPayload['action'] ?? '') === 'save_brs_xml') {
        header('Content-Type: application/json; charset=UTF-8');
        $sanitizeXmlText = static function ($value) {
            $text = (string)$value;
            return preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
        };
        $noBrs = trim((string)($jsonPayload['no_brs'] ?? ''));
        $blocks = $jsonPayload['blocks'] ?? [];
        $monthLabel = trim((string)($jsonPayload['month'] ?? ''));
        $yearLabel = trim((string)($jsonPayload['year'] ?? ''));
        $noBrs = $sanitizeXmlText($noBrs);
        if (is_array($blocks)) {
            $blocks = array_map($sanitizeXmlText, $blocks);
        }

        if ($noBrs === '' || !is_array($blocks) || empty($blocks)) {
            echo json_encode(['ok' => false, 'message' => 'Nomor BRS dan naskah harus diisi.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $xmlPath = __DIR__ . '/Indesign.xml';
        if (!is_file($xmlPath)) {
            echo json_encode(['ok' => false, 'message' => 'Template Indesign.xml tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rawXml = file_get_contents($xmlPath);
        if ($rawXml === false) {
            echo json_encode(['ok' => false, 'message' => 'Gagal membaca Indesign.xml.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $xmlEscape = static function ($value) {
            return str_replace(
                ['&', '<', '>', '"', "'"],
                ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
                (string)$value
            );
        };
        $lineBreak = "\xE2\x80\xA8";
        $judulText = 'Perkembangan Nilai Tukar Petani' . $lineBreak . 'Provinsi Lampung';
        if ($monthLabel !== '' || $yearLabel !== '') {
            $judulText .= $lineBreak . trim($monthLabel . ' ' . $yearLabel);
        }
        $replacements = [
            'NomorBRSatas' => $noBrs,
            'Headline1' => (string)($blocks[0] ?? ''),
            'Headline2' => (string)($blocks[1] ?? ''),
            'JudulBRS' => $judulText
        ];
        foreach ($replacements as $tag => $value) {
            $escaped = $xmlEscape($value);
            $pattern = '/(<'.preg_quote($tag, '/').'>)(.*?)(<\/'.preg_quote($tag, '/').'>)/s';
            $rawXml = preg_replace($pattern, '$1' . $escaped . '$3', $rawXml, 1);
        }

        $subNodes = $doc->getElementsByTagName('Sub_Headline');
        if ($subNodes->length > 0) {
            $parent = $subNodes->item(0)->parentNode;
            if ($parent) {
                for ($i = $subNodes->length - 1; $i >= 0; $i--) {
                    $node = $subNodes->item($i);
                    if ($node && $node->parentNode === $parent) {
                        $parent->removeChild($node);
                    }
                }
                for ($i = 2; $i < count($blocks); $i++) {
                    $node = $doc->createElement('Sub_Headline');
                    $node->appendChild($doc->createTextNode((string)$blocks[$i]));
                    $parent->appendChild($node);
                }
            }
        }

        $safeMonth = strtolower(preg_replace('/\s+/', '-', $monthLabel !== '' ? $monthLabel : 'bulan'));
        $safeYear = $yearLabel !== '' ? $yearLabel : 'tahun';
        $fileName = 'indesign-' . $safeMonth . '-' . $safeYear . '.xml';
        $exportDir = __DIR__ . '/exports';
        if (!is_dir($exportDir)) {
            @mkdir($exportDir, 0777, true);
        }
        $outPath = rtrim($exportDir, '/') . '/' . $fileName;
        if (!is_writable(dirname($outPath))) {
            echo json_encode(['ok' => false, 'message' => 'Folder output tidak dapat ditulis. Periksa permission folder exports.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (file_put_contents($outPath, $rawXml) === false) {
            $lastError = error_get_last();
            $detail = $lastError && isset($lastError['message']) ? ' ' . trim($lastError['message']) : '';
            echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan file XML.' . $detail], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => true, 'file' => $fileName], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$dashboardRows = [];
$dashboardAndilRows = [];
$brsMonths = [];
$brsYears = [];
$brsLatestMonth = '';
$brsLatestYear = '';
$brsSelectedMonth = '';
$brsSelectedYear = '';
$brsSelectedMonthIndex = 0;
$brsTable1TopRows = [];
$brsTable1DetailRows = [];
$brsTable2Rows = [];
$brsTable2Columns = [];
$brsTable3Rows = [];
$brsTable4Rows = [];
$brsTable1Title = 'Tabel BRS 1';
$brsTable2Title = 'Tabel BRS 2';
$brsTable3Title = 'Tabel BRS 3';
$brsTable4Title = 'Tabel BRS 4';
$brsNarrativeText = '';
$brsDynamicNarrative = '';
$brsDynamicSubsectorNarrative = '';
$brsDynamicSubsectorChangeNarrative = '';
$brsDynamicSubsectorYoyNarrative = '';
$brsDynamicSubsectorMoMDetailNarrative = '';
$brsDynamicItNarrative = '';
$brsDynamicIbNarrative = '';
$brsDynamicNtpItIbNarrative = '';
$brsDynamicNtppNarrative = '';
$brsDynamicNtphNarrative = '';
$brsDynamicNtprNarrative = '';
$brsDynamicNtptNarrative = '';
$brsDynamicNtnNarrative = '';
$brsDynamicNtpiNarrative = '';
$brsDynamicIkrtNarrative = '';
$brsDynamicIkrtDominantNarrative = '';
$brsDynamicNtupHeadline = '';
$brsDynamicNtupBriefNarrative = '';
$brsDynamicNtpDetailNarrative = '';
$brsDynamicNtupNarrative = '';
if ($currentPage === 'dashboard') {
    $result = $conn->query("SELECT prov, tahun, bulan, subsektor, rincian, nilai FROM NTP_Subsektor WHERE prov = '18'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dashboardRows[] = $row;
        }
    }
    $dashboardKrtRows = [];
    $krtResult = $conn->query("SELECT prov, tahun, bulan, rincian, nilai FROM NTP_Subsektor WHERE LOWER(TRIM(subsektor))='gabungan' AND (LOWER(TRIM(rincian)) LIKE '%konsumsi rumah tangga%' OR LOWER(TRIM(rincian)) LIKE '%krt%')");
    if ($krtResult) {
        while ($row = $krtResult->fetch_assoc()) {
            $dashboardKrtRows[] = $row;
        }
    }
    $andilResult = $conn->query("SELECT prov, tahun, kode_bulan, subsektor, komoditi, andil FROM Andil_NTP WHERE prov = '18'");
    if ($andilResult) {
        while ($row = $andilResult->fetch_assoc()) {
            $dashboardAndilRows[] = $row;
        }
    }
} elseif ($currentPage === 'generate-brs' || $currentPage === 'generate-brs-text') {
    $monthOrderPhp = [
        '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6,
        '7' => 7, '8' => 8, '9' => 9, '10' => 10, '11' => 11, '12' => 12,
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12
    ];

    $periodResult = $conn->query("SELECT DISTINCT tahun, bulan FROM NTP_Subsektor");
    if ($periodResult) {
        $yearsMap = [];
        $monthsMap = [];
        $latestScore = -1;
        while ($period = $periodResult->fetch_assoc()) {
            $tahun = (int) $period['tahun'];
            $bulan = trim((string) $period['bulan']);
            $index = 99;
            if ($tahun > 0) {
                $yearsMap[(string) $tahun] = true;
            }
            if ($bulan !== '') {
                $index = $monthOrderPhp[strtolower($bulan)] ?? 99;
                $monthsMap[strtolower($bulan)] = ['name' => $bulan, 'index' => $index];
            }
            if ($tahun > 0 && $index > 0 && $index < 99) {
                $score = ($tahun * 12) + $index;
                if ($score > $latestScore) {
                    $latestScore = $score;
                    $brsLatestYear = (string) $tahun;
                    $brsLatestMonth = $bulan;
                }
            }
        }
        $brsYears = array_map('intval', array_keys($yearsMap));
        sort($brsYears);
        $brsMonths = array_values($monthsMap);
        usort($brsMonths, function($a, $b) {
            return $a['index'] <=> $b['index'];
        });
    }

    $monthNameByIndexPhp = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    $brsSelectedMonth = $brsLatestMonth;
    $brsSelectedYear = $brsLatestYear;

    if (isset($_GET['brs_tahun']) && trim((string) $_GET['brs_tahun']) !== '') {
        $requestedYear = (int) $_GET['brs_tahun'];
        if ($requestedYear > 0) {
            $brsSelectedYear = (string) $requestedYear;
        }
    }

    if (isset($_GET['brs_bulan']) && trim((string) $_GET['brs_bulan']) !== '') {
        $requestedMonthRaw = trim((string) $_GET['brs_bulan']);
        $requestedMonthIndex = $monthOrderPhp[strtolower($requestedMonthRaw)] ?? 0;
        if ($requestedMonthIndex > 0 && $requestedMonthIndex <= 12) {
            $brsSelectedMonth = $monthNameByIndexPhp[$requestedMonthIndex] ?? $requestedMonthRaw;
        }
    }
    if ($pendingReasonSaveAll !== null) {
        if (($pendingReasonSaveAll['tahun'] ?? 0) > 0) {
            $brsSelectedYear = (string) ((int) $pendingReasonSaveAll['tahun']);
        }
        if (($pendingReasonSaveAll['bulan'] ?? 0) >= 1 && ($pendingReasonSaveAll['bulan'] ?? 0) <= 12) {
            $brsSelectedMonth = $monthNameByIndexPhp[(int) $pendingReasonSaveAll['bulan']] ?? $brsSelectedMonth;
        }
    }
    if ($pendingReasonSave !== null) {
        if (($pendingReasonSave['tahun'] ?? 0) > 0) {
            $brsSelectedYear = (string) ((int) $pendingReasonSave['tahun']);
        }
        if (($pendingReasonSave['bulan'] ?? 0) >= 1 && ($pendingReasonSave['bulan'] ?? 0) <= 12) {
            $brsSelectedMonth = $monthNameByIndexPhp[(int) $pendingReasonSave['bulan']] ?? $brsSelectedMonth;
        }
    }

    $selectedMonthIndex = $monthOrderPhp[strtolower((string) $brsSelectedMonth)] ?? 0;
    $selectedYearInt = (int) $brsSelectedYear;
    if ($selectedYearInt <= 0 || $selectedMonthIndex <= 0 || $selectedMonthIndex > 12) {
        $selectedYearInt = (int) $brsLatestYear;
        $selectedMonthIndex = $monthOrderPhp[strtolower((string) $brsLatestMonth)] ?? 0;
        $brsSelectedYear = $selectedYearInt > 0 ? (string) $selectedYearInt : '';
        $brsSelectedMonth = $monthNameByIndexPhp[$selectedMonthIndex] ?? $brsLatestMonth;
    }
    $brsSelectedMonthIndex = $selectedMonthIndex;

    $prevMonthIndex = $selectedMonthIndex === 1 ? 12 : max(1, $selectedMonthIndex - 1);
    $prevYearInt = $selectedMonthIndex === 1 ? ($selectedYearInt - 1) : $selectedYearInt;
    $currentMonthName = $monthNameByIndexPhp[$selectedMonthIndex] ?? (string) $selectedMonthIndex;
    $prevMonthName = $monthNameByIndexPhp[$prevMonthIndex] ?? (string) $prevMonthIndex;
    $monthPairLabel = $prevYearInt === $selectedYearInt
        ? ($prevMonthName . ' dan ' . $currentMonthName . ' ' . $selectedYearInt)
        : ($prevMonthName . ' ' . $prevYearInt . ' dan ' . $currentMonthName . ' ' . $selectedYearInt);
    $yoyLabel = $currentMonthName . ' ' . ($selectedYearInt - 1) . ' dan ' . $currentMonthName . ' ' . $selectedYearInt;

    $brsTable1Title = 'Nilai Tukar Petani Provinsi Lampung Per Subsektor Serta Persentase Perubahannya (2018=100), ' . $monthPairLabel;
    $brsTable2Title = 'Persentase Perubahan Indeks Konsumsi Rumah Tangga (2018=100), ' . $currentMonthName . ' ' . $selectedYearInt;
    $brsTable3Title = 'Nilai Tukar Petani per Subsektor dan Gabungan se-Provinsi Lampung (2018=100), ' . $yoyLabel;
    $brsTable4Title = 'Nilai Tukar Usaha Rumah Tangga Pertanian per Subsektor dan Persentase Perubahannya Provinsi Lampung (2018=100), ' . $monthPairLabel;

    if ($conn->getDialect() === 'pgsql') {
        $conn->query("
            CREATE TABLE IF NOT EXISTS BRS_Alasan_Subsektor (
                period_tahun INT NOT NULL,
                period_bulan SMALLINT NOT NULL,
                subsektor_key VARCHAR(10) NOT NULL,
                alasan VARCHAR(200) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (period_tahun, period_bulan, subsektor_key)
            )
        ");
    } else {
        $conn->query("
            CREATE TABLE IF NOT EXISTS BRS_Alasan_Subsektor (
                period_tahun INT NOT NULL,
                period_bulan TINYINT NOT NULL,
                subsektor_key VARCHAR(10) NOT NULL,
                alasan VARCHAR(200) NOT NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (period_tahun, period_bulan, subsektor_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if ($pendingReasonSave !== null) {
        $reasonKey = $pendingReasonSave['key'] ?? '';
        $reasonText = mb_substr(trim((string) ($pendingReasonSave['text'] ?? '')), 0, 200);
        $reasonYear = (int) ($pendingReasonSave['tahun'] ?? 0);
        $reasonMonth = (int) ($pendingReasonSave['bulan'] ?? 0);
        if (isset($subsectorReasonLabels[$reasonKey]) && $reasonYear > 0 && $reasonMonth >= 1 && $reasonMonth <= 12) {
            $stmtReason = $conn->prepare("
                INSERT INTO BRS_Alasan_Subsektor (period_tahun, period_bulan, subsektor_key, alasan)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE alasan = VALUES(alasan)
            ");
            if ($stmtReason) {
                $stmtReason->bind_param('iiss', $reasonYear, $reasonMonth, $reasonKey, $reasonText);
                if ($stmtReason->execute()) {
                    $reasonStatus = 'success';
                    $reasonMessage = 'Alasan untuk ' . $subsectorReasonLabels[$reasonKey] . ' berhasil disimpan.';
                } else {
                    $reasonStatus = 'error';
                    $reasonMessage = 'Gagal menyimpan alasan: ' . $stmtReason->error;
                }
                $stmtReason->close();
            } else {
                $reasonStatus = 'error';
                $reasonMessage = 'Gagal menyiapkan penyimpanan alasan.';
            }
        } else {
            $reasonStatus = 'error';
            $reasonMessage = 'Data alasan tidak valid.';
        }
    }
    if ($pendingReasonSaveAll !== null) {
        $reasonYear = (int) ($pendingReasonSaveAll['tahun'] ?? 0);
        $reasonMonth = (int) ($pendingReasonSaveAll['bulan'] ?? 0);
        $reasonsInput = $pendingReasonSaveAll['reasons'] ?? [];
        $allFilled = true;
        $sanitizedReasons = [];
        foreach ($subsectorReasonLabels as $reasonKey => $_label) {
            $val = mb_substr(trim((string) ($reasonsInput[$reasonKey] ?? '')), 0, 200);
            $sanitizedReasons[$reasonKey] = $val;
            if ($val === '') {
                $allFilled = false;
            }
        }
        if ($reasonYear > 0 && $reasonMonth >= 1 && $reasonMonth <= 12 && $allFilled) {
            try {
                $conn->begin_transaction();
                $stmtReasonAll = $conn->prepare("
                    INSERT INTO BRS_Alasan_Subsektor (period_tahun, period_bulan, subsektor_key, alasan)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE alasan = VALUES(alasan)
                ");
                if (!$stmtReasonAll) {
                    throw new RuntimeException('Gagal menyiapkan query simpan semua alasan.');
                }
                foreach ($sanitizedReasons as $reasonKey => $reasonText) {
                    $stmtReasonAll->bind_param('iiss', $reasonYear, $reasonMonth, $reasonKey, $reasonText);
                    if (!$stmtReasonAll->execute()) {
                        throw new RuntimeException($stmtReasonAll->error);
                    }
                }
                $stmtReasonAll->close();
                $conn->commit();
                $reasonStatus = 'success';
                $reasonMessage = 'Semua alasan berhasil disimpan.';
            } catch (Throwable $e) {
                $conn->rollback();
                $reasonStatus = 'error';
                $reasonMessage = 'Gagal menyimpan semua alasan: ' . $e->getMessage();
            }
        } else {
            $reasonStatus = 'error';
            $reasonMessage = 'Semua form alasan wajib diisi (maksimal 200 karakter).';
        }
        foreach ($sanitizedReasons as $k => $v) {
            if (array_key_exists($k, $subsectorReasonValues)) {
                $subsectorReasonValues[$k] = $v;
            }
        }
    }

    $stmtLoadReason = $conn->prepare("
        SELECT subsektor_key, alasan
        FROM BRS_Alasan_Subsektor
        WHERE period_tahun = ? AND period_bulan = ?
    ");
    if ($stmtLoadReason) {
        $stmtLoadReason->bind_param('ii', $selectedYearInt, $selectedMonthIndex);
        if ($stmtLoadReason->execute()) {
            $resReason = $stmtLoadReason->get_result();
            while ($rowReason = $resReason->fetch_assoc()) {
                $k = strtolower(trim((string) ($rowReason['subsektor_key'] ?? '')));
                if (array_key_exists($k, $subsectorReasonValues)) {
                    $subsectorReasonValues[$k] = (string) ($rowReason['alasan'] ?? '');
                }
            }
        }
        $stmtLoadReason->close();
    }

    $normalizeMetricKey = function(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^[:alnum:]]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    };

    $periodMetricValues = [];
    $periodProvMetricValues = [];
    $periodSubsectorMetricValues = [];
    $metricResult = $conn->query("
        SELECT prov, tahun, bulan, rincian, nilai
        FROM NTP_Subsektor
        WHERE prov = '18' AND LOWER(TRIM(subsektor)) = 'gabungan'
    ");
    $metricResultProv18AllSubsektor = $conn->query("
        SELECT prov, tahun, bulan, subsektor, rincian, nilai
        FROM NTP_Subsektor
        WHERE prov = '18'
    ");
    $metricResultAllProv = $conn->query("
        SELECT prov, tahun, bulan, rincian, nilai
        FROM NTP_Subsektor
        WHERE LOWER(TRIM(subsektor)) = 'gabungan'
    ");
    if ($metricResult) {
        while ($metric = $metricResult->fetch_assoc()) {
            $tahun = (int) $metric['tahun'];
            $bulanRaw = trim((string) $metric['bulan']);
            $bulanIndex = $monthOrderPhp[strtolower($bulanRaw)] ?? 0;
            if ($tahun <= 0 || $bulanIndex <= 0 || $bulanIndex > 12) {
                continue;
            }
            $metricKey = $normalizeMetricKey((string) $metric['rincian']);
            if ($metricKey === '') {
                continue;
            }
            $periodKey = $tahun . '-' . $bulanIndex;
            if (!isset($periodMetricValues[$periodKey])) {
                $periodMetricValues[$periodKey] = [];
            }
            if (!isset($periodMetricValues[$periodKey][$metricKey])) {
                $periodMetricValues[$periodKey][$metricKey] = [];
            }
            $periodMetricValues[$periodKey][$metricKey][] = (float) $metric['nilai'];
        }
    }
    if ($metricResultAllProv) {
        while ($metric = $metricResultAllProv->fetch_assoc()) {
            $prov = trim((string) $metric['prov']);
            $tahun = (int) $metric['tahun'];
            $bulanRaw = trim((string) $metric['bulan']);
            $bulanIndex = $monthOrderPhp[strtolower($bulanRaw)] ?? 0;
            if ($prov === '' || $tahun <= 0 || $bulanIndex <= 0 || $bulanIndex > 12) {
                continue;
            }
            $metricKey = $normalizeMetricKey((string) $metric['rincian']);
            if ($metricKey === '') {
                continue;
            }
            $periodKey = $tahun . '-' . $bulanIndex;
            if (!isset($periodProvMetricValues[$periodKey])) {
                $periodProvMetricValues[$periodKey] = [];
            }
            if (!isset($periodProvMetricValues[$periodKey][$prov])) {
                $periodProvMetricValues[$periodKey][$prov] = [];
            }
            if (!isset($periodProvMetricValues[$periodKey][$prov][$metricKey])) {
                $periodProvMetricValues[$periodKey][$prov][$metricKey] = [];
            }
            $periodProvMetricValues[$periodKey][$prov][$metricKey][] = (float) $metric['nilai'];
        }
    }
    if ($metricResultProv18AllSubsektor) {
        while ($metric = $metricResultProv18AllSubsektor->fetch_assoc()) {
            $prov = trim((string) $metric['prov']);
            $subsektor = $normalizeMetricKey((string) $metric['subsektor']);
            $tahun = (int) $metric['tahun'];
            $bulanRaw = trim((string) $metric['bulan']);
            $bulanIndex = $monthOrderPhp[strtolower($bulanRaw)] ?? 0;
            if ($prov !== '18' || $subsektor === '' || $tahun <= 0 || $bulanIndex <= 0 || $bulanIndex > 12) {
                continue;
            }
            $metricKey = $normalizeMetricKey((string) $metric['rincian']);
            if ($metricKey === '') {
                continue;
            }
            $periodKey = $tahun . '-' . $bulanIndex;
            if (!isset($periodSubsectorMetricValues[$periodKey])) {
                $periodSubsectorMetricValues[$periodKey] = [];
            }
            if (!isset($periodSubsectorMetricValues[$periodKey][$subsektor])) {
                $periodSubsectorMetricValues[$periodKey][$subsektor] = [];
            }
            if (!isset($periodSubsectorMetricValues[$periodKey][$subsektor][$metricKey])) {
                $periodSubsectorMetricValues[$periodKey][$subsektor][$metricKey] = [];
            }
            $periodSubsectorMetricValues[$periodKey][$subsektor][$metricKey][] = (float) $metric['nilai'];
        }
    }

    $findMetricValue = function(int $tahun, int $bulanIndex, array $aliases) use ($periodMetricValues, $normalizeMetricKey): ?float {
        $periodKey = $tahun . '-' . $bulanIndex;
        if (!isset($periodMetricValues[$periodKey])) {
            return null;
        }
        $bucket = $periodMetricValues[$periodKey];
        foreach ($aliases as $aliasRaw) {
            $alias = $normalizeMetricKey($aliasRaw);
            $matched = [];
            foreach ($bucket as $metricKey => $values) {
                if ($metricKey === $alias || strpos($metricKey, $alias) !== false) {
                    $matched = array_merge($matched, $values);
                }
            }
            if (!empty($matched)) {
                return array_sum($matched) / count($matched);
            }
        }
        return null;
    };

    $buildMetricRow = function(string $label, array $aliases) use ($findMetricValue, $selectedYearInt, $selectedMonthIndex, $prevYearInt, $prevMonthIndex): array {
        $currentValue = $findMetricValue($selectedYearInt, $selectedMonthIndex, $aliases);
        $prevValue = $findMetricValue($prevYearInt, $prevMonthIndex, $aliases);
        $changeValue = ($currentValue !== null && $prevValue !== null && $prevValue != 0.0)
            ? (($currentValue - $prevValue) / $prevValue) * 100.0
            : null;
        return [
            'label' => $label,
            'current' => $currentValue,
            'previous' => $prevValue,
            'change' => $changeValue
        ];
    };

    $brsTable1TopRows = [
        $buildMetricRow('a. Nilai Tukar Petani (NTP)', ['nilai tukar petani']),
        $buildMetricRow('b. Indeks Harga yang Diterima oleh Petani (It)', ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']),
        $buildMetricRow('c. Indeks Harga yang Dibayar oleh Petani (Ib)', ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']),
        $buildMetricRow('- Indeks Konsumsi Rumah Tangga (KRT)', ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']),
        $buildMetricRow('- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)'])
    ];

    $toPercentChange = function(?float $current, ?float $previous): ?float {
        if ($current === null || $previous === null || $previous == 0.0) {
            return null;
        }
        return (($current - $previous) / $previous) * 100.0;
    };
    $formatIdNumber = function(?float $value): string {
        if ($value === null) return '-';
        $trunc = floor((float) $value * 100) / 100;
        return number_format($trunc, 2, ',', '.');
    };
    $formatPctAbs = function(?float $value): string {
        if ($value === null) return 'tidak tersedia';
        $abs = abs((float) $value);
        $trunc = floor($abs * 100) / 100;
        return number_format($trunc, 2, ',', '.');
    };
    $describeChange = function(?float $pct) use ($formatPctAbs): string {
        if ($pct === null) return 'tidak tersedia';
        if ($pct > 0) return 'naik sebesar ' . $formatPctAbs($pct) . ' persen';
        if ($pct < 0) return 'turun sebesar ' . $formatPctAbs($pct) . ' persen';
        return 'tetap (0,00 persen)';
    };
    $classifyChange = function(?float $pct): string {
        if ($pct === null) {
            return 'na';
        }
        $rounded = round($pct, 2);
        if ($rounded > 0) {
            return 'naik';
        }
        if ($rounded < 0) {
            return 'turun';
        }
        return 'tetap';
    };
    $pctText = function(?float $pct) use ($formatPctAbs): string {
        return $formatPctAbs($pct);
    };

    $ntpCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntpPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $itCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $ibCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);

    $ntpPct = $toPercentChange($ntpCurrent, $ntpPrev);
    $itPct = $toPercentChange($itCurrent, $itPrev);
    $ibPct = $toPercentChange($ibCurrent, $ibPrev);

    if ($ntpCurrent !== null && $ntpPrev !== null && $ntpPct !== null) {
        $trendWord = $ntpPct > 0 ? 'naik' : ($ntpPct < 0 ? 'turun' : 'tetap');
        $brsDynamicNarrative = 'Nilai Tukar Petani (NTP) Provinsi Lampung selama ' . $currentMonthName . ' ' . $selectedYearInt .
            ' sebesar ' . $formatIdNumber($ntpCurrent) . ' atau ' . $trendWord . ' ' . $formatPctAbs($ntpPct) .
            ' persen dibanding ' . $prevMonthName . ' ' . $prevYearInt . '.';
        if ($itPct !== null && $ibPct !== null) {
            $itRounded = round($itPct, 2);
            $ibRounded = round($ibPct, 2);
            $itDir = $itRounded > 0 ? 'naik' : ($itRounded < 0 ? 'turun' : 'tetap');
            $ibDir = $ibRounded > 0 ? 'naik' : ($ibRounded < 0 ? 'turun' : 'tetap');
            $itText = $itDir === 'tetap' ? 'tetap' : ($itDir . ' sebesar ' . number_format(abs((float)$itRounded), 2, ',', '.') . ' persen');
            $ibText = $ibDir === 'tetap' ? 'tetap' : ($ibDir . ' sebesar ' . number_format(abs((float)$ibRounded), 2, ',', '.') . ' persen');

            $explain = '';
            if ($itDir === 'naik' && $ibDir === 'tetap') {
                $explain = 'Pendapatan petani meningkat sementara pengeluaran tidak berubah.';
            } elseif ($itDir === 'tetap' && $ibDir === 'turun') {
                $explain = 'Biaya yang dibayar petani turun.';
            } elseif ($itDir === 'naik' && $ibDir === 'naik') {
                if ($itRounded > $ibRounded) {
                    $explain = 'Pendapatan naik lebih cepat daripada pengeluaran.';
                } elseif ($itRounded < $ibRounded) {
                    $explain = 'Pengeluaran meningkat lebih cepat daripada pendapatan.';
                } else {
                    $explain = 'Kenaikan pendapatan seimbang dengan kenaikan biaya.';
                }
            } elseif ($itDir === 'turun' && $ibDir === 'tetap') {
                $explain = 'Harga hasil pertanian turun.';
            } elseif ($itDir === 'tetap' && $ibDir === 'naik') {
                $explain = 'Biaya yang dibayar petani meningkat.';
            } elseif ($itDir === 'turun' && $ibDir === 'naik') {
                $explain = 'Pengeluaran meningkat lebih cepat daripada pendapatan.';
            } elseif ($itDir === 'turun' && $ibDir === 'turun') {
                if ($itRounded === $ibRounded) {
                    $explain = 'Penurunan pendapatan seimbang dengan penurunan biaya.';
                } elseif ($itRounded > $ibRounded) {
                    $explain = 'Penurunan pendapatan lebih kecil daripada penurunan biaya.';
                } else {
                    $explain = 'Penurunan pendapatan lebih besar daripada penurunan biaya.';
                }
            } elseif ($itDir === 'tetap' && $ibDir === 'tetap') {
                $explain = 'Pendapatan dan biaya relatif tidak berubah.';
            }
            if ($itDir !== 'tetap' && $ibDir !== 'tetap') {
                $ntpWord = $ntpPct > 0 ? 'Peningkatan' : ($ntpPct < 0 ? 'Penurunan' : 'Perubahan');
                $itClause = 'It mengalami ' . ($itDir === 'naik' ? 'kenaikan' : 'penurunan') .
                    ' sebesar ' . $formatPctAbs($itRounded) . ' persen';
                $ibClause = ($ibDir === 'naik' ? 'kenaikan' : 'penurunan') .
                    ' Ib sebesar ' . $formatPctAbs($ibRounded) . ' persen';
                $compare = '';
                if ($itRounded < $ibRounded) {
                    $compare = ', lebih rendah dari ' . $ibClause;
                } elseif ($itRounded > $ibRounded) {
                    $compare = ', lebih tinggi dari ' . $ibClause;
                } else {
                    $compare = ', sebanding dengan ' . $ibClause;
                }
                $brsDynamicNtpItIbNarrative = 'NTP Provinsi Lampung ' . $currentMonthName . ' ' . $selectedYearInt .
                    ' sebesar ' . $formatIdNumber($ntpCurrent) . ' atau ' . $trendWord . ' ' . $formatPctAbs($ntpPct) .
                    ' persen dibanding NTP bulan sebelumnya. ' . $ntpWord . ' NTP dikarenakan ' . $itClause . $compare . '.';
            }
        }

        if ($itPct !== null && $ibPct !== null) {
            $ntpChangeWord = $ntpPct > 0 ? 'naik' : ($ntpPct < 0 ? 'turun' : 'tetap');
            $itWord = $itPct > 0 ? 'kenaikan' : ($itPct < 0 ? 'penurunan' : 'perubahan');
            $ibWord = $ibPct > 0 ? 'kenaikan' : ($ibPct < 0 ? 'penurunan' : 'perubahan');
            $detail = 'NTP Provinsi Lampung ' . $currentMonthName . ' ' . $selectedYearInt . ' sebesar ' . $formatIdNumber($ntpCurrent) .
                ' atau ' . $ntpWord . ' sebesar ' . $formatPctAbs($ntpPct) .
                ' persen dibanding NTP ' . $prevMonthName . ' ' . $prevYearInt . ' yaitu dari ' . $formatIdNumber($ntpPrev) .
                ' menjadi ' . $formatIdNumber($ntpCurrent) . '. ' .
                ($ntpPct > 0 ? 'Peningkatan' : ($ntpPct < 0 ? 'Penurunan' : 'Perubahan')) .
                ' NTP pada ' . $currentMonthName . ' ' . $selectedYearInt . ' disebabkan oleh ' .
                ($itPct < 0 ? 'turunnya' : ($itPct > 0 ? 'naiknya' : 'perubahan')) . ' It sebesar ' .
                $formatPctAbs($itPct) . ' persen';

            if (round($itPct, 2) !== 0.0 && round($ibPct, 2) !== 0.0) {
                if (abs($itPct) < abs($ibPct)) {
                    $detail .= ', lebih rendah dibandingkan ' . $ibWord . ' Ib sebesar ' . $formatPctAbs($ibPct) . ' persen';
                } elseif (abs($itPct) > abs($ibPct)) {
                    $detail .= ', lebih tinggi dibandingkan ' . $ibWord . ' Ib sebesar ' . $formatPctAbs($ibPct) . ' persen';
                } else {
                    $detail .= ', sebanding dengan ' . $ibWord . ' Ib sebesar ' . $formatPctAbs($ibPct) . ' persen';
                }
            } else {
                $detail .= ' dan ' . $ibWord . ' Ib sebesar ' . $formatPctAbs($ibPct) . ' persen';
            }
            $detail .= '.';

            $ikrtPhrase = $ikrtResolvedPct !== null
                ? (($ikrtResolvedPct < 0 ? 'turunnya' : ($ikrtResolvedPct > 0 ? 'naiknya' : 'stabilnya')) . ' IKRT sebesar ' . $formatPctAbs($ikrtResolvedPct) . ' persen')
                : 'perubahan IKRT belum tersedia';
            $ibppbmPhrase = $ibppbmResolvedPct !== null
                ? ('Indeks Biaya Produksi serta Penambahan Barang Modal (IBPPBM) yang ' . ($ibppbmResolvedPct < 0 ? 'turun' : ($ibppbmResolvedPct > 0 ? 'naik' : 'tetap')) . ' ' . $formatPctAbs($ibppbmResolvedPct) . ' persen')
                : 'Indeks Biaya Produksi serta Penambahan Barang Modal (IBPPBM) belum tersedia';
            $detail .= ' Penurunan Ib disebabkan oleh ' . $ikrtPhrase . ' dan ' . $ibppbmPhrase . '.';
            $brsDynamicNtpDetailNarrative = $detail;
        }
    } elseif ($ntpCurrent !== null) {
        $brsDynamicNarrative = 'Nilai Tukar Petani (NTP) Provinsi Lampung selama ' . $currentMonthName . ' ' . $selectedYearInt .
            ' sebesar ' . $formatIdNumber($ntpCurrent) . '.';
    } else {
        $brsDynamicNarrative = 'Data NTP Provinsi Lampung untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia.';
    }

    $findSubsectorMetricValue = function(string $subsektorRaw, int $tahun, int $bulanIndex, array $aliases) use ($periodSubsectorMetricValues, $normalizeMetricKey): ?float {
        $periodKey = $tahun . '-' . $bulanIndex;
        $subsektorKey = $normalizeMetricKey($subsektorRaw);
        if (!isset($periodSubsectorMetricValues[$periodKey][$subsektorKey])) {
            return null;
        }
        $bucket = $periodSubsectorMetricValues[$periodKey][$subsektorKey];
        foreach ($aliases as $aliasRaw) {
            $alias = $normalizeMetricKey($aliasRaw);
            $matched = [];
            foreach ($bucket as $metricKey => $values) {
                if ($metricKey === $alias || strpos($metricKey, $alias) !== false) {
                    $matched = array_merge($matched, $values);
                }
            }
            if (!empty($matched)) {
                return array_sum($matched) / count($matched);
            }
        }
        return null;
    };

    $buildSubsectorMetricRow = function(string $label, string $subsektor, array $aliases) use ($findSubsectorMetricValue, $selectedYearInt, $selectedMonthIndex, $prevYearInt, $prevMonthIndex): array {
        $currentValue = $findSubsectorMetricValue($subsektor, $selectedYearInt, $selectedMonthIndex, $aliases);
        $prevValue = $findSubsectorMetricValue($subsektor, $prevYearInt, $prevMonthIndex, $aliases);
        $changeValue = ($currentValue !== null && $prevValue !== null && $prevValue != 0.0)
            ? (($currentValue - $prevValue) / $prevValue) * 100.0
            : null;
        return [
            'label' => $label,
            'current' => $currentValue,
            'previous' => $prevValue,
            'change' => $changeValue
        ];
    };

    $findSubsectorMetricValueByAliases = function(array $subsektorAliases, int $tahun, int $bulanIndex, array $rincianAliases) use ($findSubsectorMetricValue): ?float {
        foreach ($subsektorAliases as $subsektorAlias) {
            $value = $findSubsectorMetricValue($subsektorAlias, $tahun, $bulanIndex, $rincianAliases);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    };
    $formatValueDotDecimal = function(?float $value): string {
        if ($value === null) return '-';
        $trunc = floor((float) $value * 100) / 100;
        return number_format($trunc, 2, ',', '');
    };
    $subsectorCurrentNtp = [
        'tanamanPangan' => $findSubsectorMetricValueByAliases(['tanaman pangan'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']),
        'hortikultura' => $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']),
        'perkebunan' => $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']),
        'peternakan' => $findSubsectorMetricValueByAliases(['peternakan'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']),
        'tangkap' => $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']),
        'budidaya' => $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani'])
    ];
    $brsDynamicSubsectorNarrative = 'NTP Provinsi Lampung ' . $currentMonthName . ' ' . $selectedYearInt . ' untuk masing-masing subsektor tercatat '
        . 'Subsektor Tanaman Pangan (NTP-P) ' . $formatValueDotDecimal($subsectorCurrentNtp['tanamanPangan']) . '; '
        . 'Tanaman Hortikultura (NTP-H) ' . $formatValueDotDecimal($subsectorCurrentNtp['hortikultura']) . '; '
        . 'Tanaman Perkebunan Rakyat (NTP-Pr) ' . $formatValueDotDecimal($subsectorCurrentNtp['perkebunan']) . '; '
        . 'Peternakan (NTP-Pt) ' . $formatValueDotDecimal($subsectorCurrentNtp['peternakan']) . '; '
        . 'Perikanan Tangkap ' . $formatValueDotDecimal($subsectorCurrentNtp['tangkap']) . '; '
        . 'dan Perikanan Budidaya ' . $formatValueDotDecimal($subsectorCurrentNtp['budidaya']) . '.';

    $reasonImpactMap = [
        'tp' => ['andilCode' => 'TP', 'aliases' => ['tanaman pangan']],
        'th' => ['andilCode' => 'TH', 'aliases' => ['hortikultura', 'tanaman hortikultura']],
        'tpr' => ['andilCode' => 'TPR', 'aliases' => ['tanaman perkebunan rakyat']],
        'trk' => ['andilCode' => 'TRK', 'aliases' => ['peternakan']],
        'ikt' => ['andilCode' => 'IKT', 'aliases' => ['ikan tangkap', 'perikanan tangkap']],
        'ikb' => ['andilCode' => 'IKB', 'aliases' => ['ikan budidaya', 'perikanan budidaya']]
    ];
    $andilRowsForReason = [];
    $stmtAndilReason = $conn->prepare("
        SELECT subsektor, komoditi, andil
        FROM Andil_NTP
        WHERE prov = '18' AND tahun = ? AND kode_bulan = ?
    ");
    if ($stmtAndilReason) {
        $kodeBulanReason = (string) $selectedMonthIndex;
        $stmtAndilReason->bind_param('is', $selectedYearInt, $kodeBulanReason);
        if ($stmtAndilReason->execute()) {
            $resAndilReason = $stmtAndilReason->get_result();
            while ($rowAndilReason = $resAndilReason->fetch_assoc()) {
                $andilRowsForReason[] = [
                    'code' => strtoupper(trim((string) ($rowAndilReason['subsektor'] ?? ''))),
                    'komoditi' => trim((string) ($rowAndilReason['komoditi'] ?? '')),
                    'andil' => isset($rowAndilReason['andil']) ? (float) $rowAndilReason['andil'] : null
                ];
            }
        }
        $stmtAndilReason->close();
    }
    foreach ($reasonImpactMap as $reasonKey => $cfg) {
        $itCurr = $findSubsectorMetricValueByAliases($cfg['aliases'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
        $itPrev = $findSubsectorMetricValueByAliases($cfg['aliases'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
        $itChange = $toPercentChange($itCurr, $itPrev);

        $selectedAndil = null;
        foreach ($andilRowsForReason as $candidate) {
            if (($candidate['code'] ?? '') !== $cfg['andilCode'] || !isset($candidate['andil'])) {
                continue;
            }
            if ($selectedAndil === null) {
                $selectedAndil = $candidate;
                continue;
            }
            if ($itChange !== null && $itChange < 0) {
                if ((float) $candidate['andil'] < (float) $selectedAndil['andil']) {
                    $selectedAndil = $candidate;
                }
            } else {
                if ((float) $candidate['andil'] > (float) $selectedAndil['andil']) {
                    $selectedAndil = $candidate;
                }
            }
        }

        $subsectorReasonImpact[$reasonKey] = [
            'komoditi' => $selectedAndil !== null && ($selectedAndil['komoditi'] ?? '') !== '' ? $selectedAndil['komoditi'] : '-',
            'andil' => $selectedAndil['andil'] ?? null,
            'it_change' => $itChange
        ];
    }
    $formatPctAbs = function(?float $value): string {
        if ($value === null) return 'tidak tersedia';
        $abs = abs((float) $value);
        $trunc = floor($abs * 100) / 100;
        return number_format($trunc, 2, ',', '.');
    };
    $formatPctSigned = function(?float $value): string {
        if ($value === null) return '-';
        $sign = $value < 0 ? -1 : 1;
        $abs = abs((float) $value);
        $trunc = floor($abs * 100) / 100;
        return number_format($trunc * $sign, 2, ',', '.');
    };

    $ntppCurrent = $findSubsectorMetricValueByAliases(['tanaman pangan'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntppPrev = $findSubsectorMetricValueByAliases(['tanaman pangan'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $ntppPct = $toPercentChange($ntppCurrent, $ntppPrev);
    $itTpCurrent = $findSubsectorMetricValueByAliases(['tanaman pangan'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itTpPrev = $findSubsectorMetricValueByAliases(['tanaman pangan'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itTpPct = $toPercentChange($itTpCurrent, $itTpPrev);
    $ibTpCurrent = $findSubsectorMetricValueByAliases(['tanaman pangan'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibTpPrev = $findSubsectorMetricValueByAliases(['tanaman pangan'], $prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibTpPct = $toPercentChange($ibTpCurrent, $ibTpPrev);
    $ikrtTpCurrent = $findSubsectorMetricValueByAliases(['tanaman pangan'], $selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtTpPrev = $findSubsectorMetricValueByAliases(['tanaman pangan'], $prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtTpPct = $toPercentChange($ikrtTpCurrent, $ikrtTpPrev);
    $ibppbmTpCurrent = $findSubsectorMetricValueByAliases(['tanaman pangan'], $selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmTpPrev = $findSubsectorMetricValueByAliases(['tanaman pangan'], $prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmTpPct = $toPercentChange($ibppbmTpCurrent, $ibppbmTpPrev);

    $wordTrend = function(?float $pct, string $type = 'noun'): string {
        if ($pct === null || (float)$pct == 0.0) {
            return $type === 'verb' ? 'stabil' : 'stabilitas';
        }
        if ($pct > 0) {
            return $type === 'verb' ? 'naik' : 'peningkatan';
        }
        return $type === 'verb' ? 'turun' : 'penurunan';
    };
    $normalizeReasonText = function(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return 'ALASAN BELUM TERSEDIA.';
        }
        $firstChar = mb_substr($text, 0, 1, 'UTF-8');
        $restText = mb_substr($text, 1, null, 'UTF-8');
        $text = mb_strtoupper($firstChar, 'UTF-8') . $restText;
        $text = rtrim($text);
        $text = rtrim($text, ".!? \t\n\r\0\x0B");
        return $text . '.';
    };

    $andilTpCandidates = array_values(array_filter($andilRowsForReason, function($row) {
        return (($row['code'] ?? '') === 'TP') && isset($row['andil']);
    }));
    if ($itTpPct !== null && !empty($andilTpCandidates)) {
        if ($itTpPct < 0) {
            usort($andilTpCandidates, function($a, $b) { return ((float)$a['andil']) <=> ((float)$b['andil']); });
        } else {
            usort($andilTpCandidates, function($a, $b) { return ((float)$b['andil']) <=> ((float)$a['andil']); });
        }
    }
    $topKomoditiTp = [];
    foreach ($andilTpCandidates as $cand) {
        $k = trim((string)($cand['komoditi'] ?? ''));
        if ($k === '') continue;
        $topKomoditiTp[] = mb_strtolower($k, 'UTF-8');
        if (count($topKomoditiTp) >= 2) break;
    }
    $komoditiPhrase = 'komoditas utama pada subsektor ini';
    if (count($topKomoditiTp) === 1) {
        $komoditiPhrase = 'komoditas ' . $topKomoditiTp[0];
    } elseif (count($topKomoditiTp) >= 2) {
        $komoditiPhrase = 'komoditas ' . $topKomoditiTp[0] . ' dan ' . $topKomoditiTp[1];
    }

    if ($ntppPct !== null && $itTpPct !== null && $ibTpPct !== null) {
        $ntppReasonText = trim((string) ($subsectorReasonValues['tp'] ?? ''));
        $ntppReasonText = $normalizeReasonText($ntppReasonText);
        $brsDynamicNtppNarrative = 'Pada ' . $currentMonthName . ' ' . $selectedYearInt . ' terjadi ' . $wordTrend($ntppPct) .
            ' NTPP Provinsi Lampung sebesar ' . $formatPctAbs($ntppPct) . ' persen. Hal ini terjadi karena It ' .
            $wordTrend($itTpPct, 'verb') . ' sebesar ' . $formatPctAbs($itTpPct) . ' persen dan Ib yang ' .
            $wordTrend($ibTpPct, 'verb') . ' sebesar ' . $formatPctAbs($ibTpPct) . ' persen. Bila dilihat dari perubahan It-nya, faktor yang menjadi pemicu adalah ' .
            $komoditiPhrase . '. ' . $ntppReasonText . ' Sementara itu, perubahan Ib disebabkan oleh IKRT pada subsektor ini yang ' .
            $wordTrend($ikrtTpPct, 'verb') . ' sebesar ' . $formatPctAbs($ikrtTpPct) . ' persen dan IBPPBM yang ' .
            $wordTrend($ibppbmTpPct, 'verb') . ' sebesar ' . $formatPctAbs($ibppbmTpPct) . ' persen.';
    } else {
        $brsDynamicNtppNarrative = 'Data narasi NTPP (tanaman pangan) untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia lengkap.';
    }

    $ntphCurrent = $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntphPrev = $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $ntphPct = $toPercentChange($ntphCurrent, $ntphPrev);
    $itThCurrent = $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itThPrev = $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itThPct = $toPercentChange($itThCurrent, $itThPrev);
    $ibThCurrent = $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibThPrev = $findSubsectorMetricValueByAliases(['hortikultura', 'tanaman hortikultura'], $prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibThPct = $toPercentChange($ibThCurrent, $ibThPrev);

    $andilThCandidates = array_values(array_filter($andilRowsForReason, function($row) {
        return (($row['code'] ?? '') === 'TH') && isset($row['andil']);
    }));
    if ($itThPct !== null && !empty($andilThCandidates)) {
        if ($itThPct < 0) {
            usort($andilThCandidates, function($a, $b) { return ((float)$a['andil']) <=> ((float)$b['andil']); });
        } else {
            usort($andilThCandidates, function($a, $b) { return ((float)$b['andil']) <=> ((float)$a['andil']); });
        }
    }
    $topKomoditiTh = [];
    foreach ($andilThCandidates as $cand) {
        $k = trim((string)($cand['komoditi'] ?? ''));
        if ($k === '') continue;
        $topKomoditiTh[] = mb_strtolower($k, 'UTF-8');
        if (count($topKomoditiTh) >= 2) break;
    }
    $komoditiThPhrase = 'komoditas utama pada subsektor ini';
    if (count($topKomoditiTh) === 1) {
        $komoditiThPhrase = 'komoditas ' . $topKomoditiTh[0];
    } elseif (count($topKomoditiTh) >= 2) {
        $komoditiThPhrase = 'komoditas ' . $topKomoditiTh[0] . ' dan ' . $topKomoditiTh[1];
    }

    if ($ntphPct !== null && $itThPct !== null && $ibThPct !== null) {
        $ntphReasonText = trim((string) ($subsectorReasonValues['th'] ?? ''));
        $ntphReasonText = $normalizeReasonText($ntphReasonText);
        $brsDynamicNtphNarrative = 'Subsektor tanaman hortikultura juga mengalami ' . $wordTrend($ntphPct) .
            ' NTP sebesar ' . $formatPctAbs($ntphPct) . ' persen pada ' . $currentMonthName . ' ' . $selectedYearInt .
            '. Hal ini terjadi karena It mengalami ' . ($itThPct > 0 ? 'kenaikan' : ($itThPct < 0 ? 'penurunan' : 'stabilitas')) .
            ' sebesar ' . $formatPctAbs($itThPct) . ' persen dan Ib yang ' . $wordTrend($ibThPct, 'verb') .
            ' sebesar ' . $formatPctAbs($ibThPct) . ' persen. Faktor yang menjadi pemicu perubahan It adalah ' .
            $komoditiThPhrase . '. ' . $ntphReasonText;
    } else {
        $brsDynamicNtphNarrative = 'Data narasi NTPH (hortikultura) untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia lengkap.';
    }

    $ntprCurrent = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntprPrev = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $ntprPct = $toPercentChange($ntprCurrent, $ntprPrev);
    $itTprCurrent = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itTprPrev = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itTprPct = $toPercentChange($itTprCurrent, $itTprPrev);
    $ibTprCurrent = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibTprPrev = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibTprPct = $toPercentChange($ibTprCurrent, $ibTprPrev);
    $ikrtTprCurrent = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtTprPrev = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtTprPct = $toPercentChange($ikrtTprCurrent, $ikrtTprPrev);
    $ibppbmTprCurrent = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmTprPrev = $findSubsectorMetricValueByAliases(['tanaman perkebunan rakyat'], $prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmTprPct = $toPercentChange($ibppbmTprCurrent, $ibppbmTprPrev);

    $andilTprCandidates = array_values(array_filter($andilRowsForReason, function($row) {
        return (($row['code'] ?? '') === 'TPR') && isset($row['andil']);
    }));
    if ($itTprPct !== null && !empty($andilTprCandidates)) {
        if ($itTprPct < 0) {
            usort($andilTprCandidates, function($a, $b) { return ((float)$a['andil']) <=> ((float)$b['andil']); });
        } else {
            usort($andilTprCandidates, function($a, $b) { return ((float)$b['andil']) <=> ((float)$a['andil']); });
        }
    }
    $topKomoditiTpr = [];
    foreach ($andilTprCandidates as $cand) {
        $k = trim((string)($cand['komoditi'] ?? ''));
        if ($k === '') continue;
        $topKomoditiTpr[] = mb_strtolower($k, 'UTF-8');
        if (count($topKomoditiTpr) >= 1) break;
    }
    $komoditiTprPhrase = count($topKomoditiTpr) >= 1 ? ('komoditas ' . $topKomoditiTpr[0]) : 'komoditas utama pada subsektor ini';

    if ($ntprPct !== null && $itTprPct !== null && $ibTprPct !== null) {
        $ntprReasonText = trim((string) ($subsectorReasonValues['tpr'] ?? ''));
        $ntprReasonText = $normalizeReasonText($ntprReasonText);
        $brsDynamicNtprNarrative = 'NTPR Provinsi Lampung mengalami ' . ($ntprPct > 0 ? 'peningkatan' : ($ntprPct < 0 ? 'penurunan' : 'stabilitas')) .
            ' di bulan ini sebesar ' . $formatPctAbs($ntprPct) . ' persen yang disebabkan It ' .
            $wordTrend($itTprPct, 'verb') . ' sebesar ' . $formatPctAbs($itTprPct) . ' persen dan Ib yang ' .
            $wordTrend($ibTprPct, 'verb') . ' sebesar ' . $formatPctAbs($ibTprPct) . ' persen. Komoditas penyumbang perubahan It adalah ' .
            $komoditiTprPhrase . '. ' . $ntprReasonText . ' Sementara itu, Ib pada subsektor ini mengalami ' .
            ($ibTprPct > 0 ? 'kenaikan' : ($ibTprPct < 0 ? 'penurunan' : 'stabilitas')) . ' yang disebabkan IKRT ' .
            $wordTrend($ikrtTprPct, 'verb') . ' sebesar ' . $formatPctAbs($ikrtTprPct) .
            ' persen dan IBPPBM yang ' . $wordTrend($ibppbmTprPct, 'verb') . ' sebesar ' . $formatPctAbs($ibppbmTprPct) . ' persen.';
    } else {
        $brsDynamicNtprNarrative = 'Data narasi NTPR (tanaman perkebunan rakyat) untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia lengkap.';
    }

    $ntptCurrent = $findSubsectorMetricValueByAliases(['peternakan'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntptPrev = $findSubsectorMetricValueByAliases(['peternakan'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $ntptPct = $toPercentChange($ntptCurrent, $ntptPrev);
    $itTrkCurrent = $findSubsectorMetricValueByAliases(['peternakan'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itTrkPrev = $findSubsectorMetricValueByAliases(['peternakan'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itTrkPct = $toPercentChange($itTrkCurrent, $itTrkPrev);
    $ibTrkCurrent = $findSubsectorMetricValueByAliases(['peternakan'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibTrkPrev = $findSubsectorMetricValueByAliases(['peternakan'], $prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibTrkPct = $toPercentChange($ibTrkCurrent, $ibTrkPrev);
    $ikrtTrkCurrent = $findSubsectorMetricValueByAliases(['peternakan'], $selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtTrkPrev = $findSubsectorMetricValueByAliases(['peternakan'], $prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtTrkPct = $toPercentChange($ikrtTrkCurrent, $ikrtTrkPrev);
    $ibppbmTrkCurrent = $findSubsectorMetricValueByAliases(['peternakan'], $selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmTrkPrev = $findSubsectorMetricValueByAliases(['peternakan'], $prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmTrkPct = $toPercentChange($ibppbmTrkCurrent, $ibppbmTrkPrev);

    $andilTrkCandidates = array_values(array_filter($andilRowsForReason, function($row) {
        return (($row['code'] ?? '') === 'TRK') && isset($row['andil']);
    }));
    if ($itTrkPct !== null && !empty($andilTrkCandidates)) {
        if ($itTrkPct < 0) {
            usort($andilTrkCandidates, function($a, $b) { return ((float)$a['andil']) <=> ((float)$b['andil']); });
        } else {
            usort($andilTrkCandidates, function($a, $b) { return ((float)$b['andil']) <=> ((float)$a['andil']); });
        }
    }
    $topKomoditiTrk = [];
    foreach ($andilTrkCandidates as $cand) {
        $k = trim((string)($cand['komoditi'] ?? ''));
        if ($k === '') continue;
        $topKomoditiTrk[] = mb_strtolower($k, 'UTF-8');
        if (count($topKomoditiTrk) >= 1) break;
    }
    $komoditiTrkPhrase = count($topKomoditiTrk) >= 1 ? ('komoditas ' . $topKomoditiTrk[0]) : 'komoditas utama pada subsektor ini';

    if ($ntptPct !== null && $itTrkPct !== null && $ibTrkPct !== null) {
        $ntptReasonText = trim((string) ($subsectorReasonValues['trk'] ?? ''));
        $ntptReasonText = $normalizeReasonText($ntptReasonText);
        $brsDynamicNtptNarrative = 'NTPT Provinsi Lampung mengalami ' . ($ntptPct > 0 ? 'peningkatan' : ($ntptPct < 0 ? 'penurunan' : 'stabilitas')) .
            ' indeks sebesar ' . $formatPctAbs($ntptPct) . ' persen pada ' . $currentMonthName . ' ' . $selectedYearInt .
            '. Hal ini terjadi karena It ' . ($itTrkPct > 0 ? 'naik' : ($itTrkPct < 0 ? 'turun' : 'stabil')) .
            ' sebesar ' . $formatPctAbs($itTrkPct) . ' persen yang dipengaruhi perubahan harga ' . $komoditiTrkPhrase . '. ' .
            $ntptReasonText . ' Sedangkan Ib pada subsektor ini mengalami ' .
            ($ibTrkPct > 0 ? 'kenaikan' : ($ibTrkPct < 0 ? 'penurunan' : 'stabilitas')) . ' sebesar ' . $formatPctAbs($ibTrkPct) .
            ' persen yang disebabkan IKRT yang ' . $wordTrend($ikrtTrkPct, 'verb') . ' sebesar ' . $formatPctAbs($ikrtTrkPct) .
            ' persen dan IBPPBM yang ' . $wordTrend($ibppbmTrkPct, 'verb') . ' sebesar ' . $formatPctAbs($ibppbmTrkPct) . ' persen.';
    } else {
        $brsDynamicNtptNarrative = 'Data narasi NTPT (peternakan) untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia lengkap.';
    }

    $ntnCurrent = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntnPrev = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $ntnPct = $toPercentChange($ntnCurrent, $ntnPrev);
    $itIktCurrent = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itIktPrev = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itIktPct = $toPercentChange($itIktCurrent, $itIktPrev);
    $ibIktCurrent = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibIktPrev = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibIktPct = $toPercentChange($ibIktCurrent, $ibIktPrev);
    $ikrtIktCurrent = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtIktPrev = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtIktPct = $toPercentChange($ikrtIktCurrent, $ikrtIktPrev);
    $ibppbmIktCurrent = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmIktPrev = $findSubsectorMetricValueByAliases(['ikan tangkap', 'perikanan tangkap'], $prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmIktPct = $toPercentChange($ibppbmIktCurrent, $ibppbmIktPrev);

    $andilIktCandidates = array_values(array_filter($andilRowsForReason, function($row) {
        return (($row['code'] ?? '') === 'IKT') && isset($row['andil']);
    }));
    if ($itIktPct !== null && !empty($andilIktCandidates)) {
        if ($itIktPct < 0) {
            usort($andilIktCandidates, function($a, $b) { return ((float)$a['andil']) <=> ((float)$b['andil']); });
        } else {
            usort($andilIktCandidates, function($a, $b) { return ((float)$b['andil']) <=> ((float)$a['andil']); });
        }
    }
    $topKomoditiIkt = [];
    foreach ($andilIktCandidates as $cand) {
        $k = trim((string)($cand['komoditi'] ?? ''));
        if ($k === '') continue;
        $topKomoditiIkt[] = mb_strtolower($k, 'UTF-8');
        if (count($topKomoditiIkt) >= 1) break;
    }
    $komoditiIktPhrase = count($topKomoditiIkt) >= 1 ? ('komoditas ' . $topKomoditiIkt[0]) : 'komoditas utama pada subsektor ini';

    if ($ntnPct !== null && $itIktPct !== null && $ibIktPct !== null) {
        $ntnReasonText = trim((string) ($subsectorReasonValues['ikt'] ?? ''));
        $ntnReasonText = $normalizeReasonText($ntnReasonText);
        $brsDynamicNtnNarrative = 'Pada subsektor perikanan tangkap, indeks NTN bergerak ' .
            ($ntnPct > 0 ? 'naik' : ($ntnPct < 0 ? 'turun' : 'stabil')) . ' sebesar ' . $formatPctAbs($ntnPct) .
            ' persen pada ' . $currentMonthName . ' ' . $selectedYearInt . '. Hal ini terjadi karena It ' .
            ($itIktPct > 0 ? 'naik' : ($itIktPct < 0 ? 'turun' : 'stabil')) . ' sebesar ' . $formatPctAbs($itIktPct) .
            ' persen yang dipengaruhi perubahan harga ' . $komoditiIktPhrase . '. ' . $ntnReasonText .
            ' Perubahan Ib pada subsektor ini sebesar ' . $formatPctAbs($ibIktPct) . ' persen yang disebabkan IKRT yang ' .
            $wordTrend($ikrtIktPct, 'verb') . ' sebesar ' . $formatPctAbs($ikrtIktPct) .
            ' persen dan IBPPBM yang ' . $wordTrend($ibppbmIktPct, 'verb') . ' sebesar ' . $formatPctAbs($ibppbmIktPct) . ' persen.';
    } else {
        $brsDynamicNtnNarrative = 'Data narasi NTN (perikanan tangkap) untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia lengkap.';
    }

    $ntpiCurrent = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntpiPrev = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
    $ntpiPct = $toPercentChange($ntpiCurrent, $ntpiPrev);
    $ibIkbCurrent = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibIkbPrev = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $prevYearInt, $prevMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
    $ibIkbPct = $toPercentChange($ibIkbCurrent, $ibIkbPrev);
    $ikrtIkbCurrent = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtIkbPrev = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtIkbPct = $toPercentChange($ikrtIkbCurrent, $ikrtIkbPrev);
    $ibppbmIkbCurrent = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmIkbPrev = $findSubsectorMetricValueByAliases(['ikan budidaya', 'perikanan budidaya'], $prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmIkbPct = $toPercentChange($ibppbmIkbCurrent, $ibppbmIkbPrev);

    $andilIkbCandidates = array_values(array_filter($andilRowsForReason, function($row) {
        return (($row['code'] ?? '') === 'IKB') && isset($row['andil']);
    }));
    usort($andilIkbCandidates, function($a, $b) { return ((float)$b['andil']) <=> ((float)$a['andil']); });
    $topKomoditiIkb = [];
    foreach ($andilIkbCandidates as $cand) {
        $k = trim((string)($cand['komoditi'] ?? ''));
        if ($k === '') continue;
        $topKomoditiIkb[] = mb_strtolower($k, 'UTF-8');
        if (count($topKomoditiIkb) >= 1) break;
    }
    $komoditiIkbPhrase = count($topKomoditiIkb) >= 1 ? ('komoditas ' . $topKomoditiIkb[0]) : 'komoditas utama pada subsektor ini';

    if ($ntpiPct !== null && $ibIkbPct !== null) {
        $ntpiReasonText = trim((string) ($subsectorReasonValues['ikb'] ?? ''));
        $ntpiReasonText = $normalizeReasonText($ntpiReasonText);
        $brsDynamicNtpiNarrative = 'NTPi mengalami ' . ($ntpiPct > 0 ? 'peningkatan' : ($ntpiPct < 0 ? 'penurunan' : 'stabilitas')) .
            ' indeks sebesar ' . $formatPctAbs($ntpiPct) . ' persen pada ' . $currentMonthName . ' ' . $selectedYearInt .
            '. Faktor yang menjadi pemicu perubahan NTPi adalah ' . $komoditiIkbPhrase . '. ' . $ntpiReasonText .
            ' Perubahan Ib pada subsektor ini sebesar ' . $formatPctAbs($ibIkbPct) . ' persen disebabkan IKRT yang ' .
            $wordTrend($ikrtIkbPct, 'verb') . ' sebesar ' . $formatPctAbs($ikrtIkbPct) .
            ' persen dan IBPPBM yang ' . $wordTrend($ibppbmIkbPct, 'verb') . ' sebesar ' . $formatPctAbs($ibppbmIkbPct) . ' persen.';
    } else {
        $brsDynamicNtpiNarrative = 'Data narasi NTPi (ikan budidaya) untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' belum tersedia lengkap.';
    }

    $yoyPrevYear = $selectedYearInt - 1;
    $ntpYoyCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
    $ntpYoyPrev = $findMetricValue($yoyPrevYear, $selectedMonthIndex, ['nilai tukar petani']);
    $ntpYoyPct = $toPercentChange($ntpYoyCurrent, $ntpYoyPrev);

    $yoySubsectors = [
        ['label' => 'tanaman pangan', 'aliases' => ['tanaman pangan']],
        ['label' => 'tanaman hortikultura', 'aliases' => ['tanaman hortikultura', 'hortikultura']],
        ['label' => 'tanaman perkebunan rakyat', 'aliases' => ['tanaman perkebunan rakyat']],
        ['label' => 'peternakan', 'aliases' => ['peternakan']],
        ['label' => 'perikanan tangkap', 'aliases' => ['ikan tangkap', 'perikanan tangkap']],
        ['label' => 'perikanan budidaya', 'aliases' => ['ikan budidaya', 'perikanan budidaya']]
    ];
    $yoyDeclines = [];
    $yoyIncreases = [];
    $yoyLargest = null;
    foreach ($yoySubsectors as $sub) {
        $curr = $findSubsectorMetricValueByAliases($sub['aliases'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
        $prev = $findSubsectorMetricValueByAliases($sub['aliases'], $yoyPrevYear, $selectedMonthIndex, ['nilai tukar petani']);
        $pct = $toPercentChange($curr, $prev);
        if ($pct === null || (float)$pct == 0.0) {
            continue;
        }
        $entry = 'subsektor ' . $sub['label'];
        if ($pct < 0) {
            $yoyDeclines[] = $entry;
        } else {
            $yoyIncreases[] = $entry;
        }
        if ($yoyLargest === null || abs($pct) > abs((float)$yoyLargest['pct'])) {
            $yoyLargest = [
                'label' => $sub['label'],
                'pct' => $pct,
                'prev' => $prev,
                'curr' => $curr
            ];
        }
    }
    $joinList = function(array $items): string {
        if (count($items) === 0) return '';
        if (count($items) === 1) return $items[0];
        if (count($items) === 2) return $items[0] . ' dan ' . $items[1];
        $last = array_pop($items);
        return implode(', ', $items) . ', dan ' . $last;
    };
    if ($ntpYoyPct !== null && $ntpYoyCurrent !== null && $ntpYoyPrev !== null) {
        $trendWord = $ntpYoyPct < 0 ? 'lebih rendah' : ($ntpYoyPct > 0 ? 'lebih tinggi' : 'tetap');
        $trendNoun = $ntpYoyPct < 0 ? 'penurunan' : ($ntpYoyPct > 0 ? 'kenaikan' : 'perubahan');
        $brsDynamicSubsectorYoyNarrative = 'Secara umum, NTP ' . $currentMonthName . ' ' . $selectedYearInt . ' ' . $trendWord .
            ' dibandingkan NTP ' . $currentMonthName . ' ' . $yoyPrevYear . ' dengan ' . $trendNoun . ' sebesar ' .
            number_format(abs((float)$ntpYoyPct), 2, ',', '.') . ' persen dari ' . $formatIdNumber($ntpYoyPrev) . ' menjadi ' .
            $formatIdNumber($ntpYoyCurrent) . '.';

        if ($yoyLargest !== null) {
            $dir = $yoyLargest['pct'] < 0 ? 'turun' : 'naik';
            $brsDynamicSubsectorYoyNarrative .= ' Perubahan terbesar terjadi pada subsektor ' . $yoyLargest['label'] . ' yang ' .
                $dir . ' ' . number_format(abs((float)$yoyLargest['pct']), 2, ',', '.') . ' persen dari ' .
                $formatIdNumber($yoyLargest['prev']) . ' menjadi ' . $formatIdNumber($yoyLargest['curr']) . '.';
            $yoyDeclines = array_values(array_filter($yoyDeclines, function($v) use ($yoyLargest) {
                return $v !== 'subsektor ' . $yoyLargest['label'];
            }));
            $yoyIncreases = array_values(array_filter($yoyIncreases, function($v) use ($yoyLargest) {
                return $v !== 'subsektor ' . $yoyLargest['label'];
            }));
        }
        if (!empty($yoyDeclines)) {
            $brsDynamicSubsectorYoyNarrative .= ' Selain itu subsektor lain yang mengalami penurunan adalah ' . $joinList($yoyDeclines) . '.';
        }
        if (!empty($yoyIncreases)) {
            $brsDynamicSubsectorYoyNarrative .= ' Sedangkan subsektor yang mengalami peningkatan adalah ' . $joinList($yoyIncreases) . '.';
        }
    } else {
        $brsDynamicSubsectorYoyNarrative = 'Data perbandingan NTP ' . $currentMonthName . ' ' . $selectedYearInt . ' dan ' . $currentMonthName . ' ' . $yoyPrevYear . ' belum tersedia.';
    }
    $yoyChangeRows = [];
    foreach ($yoySubsectors as $sub) {
        $curr = $findSubsectorMetricValueByAliases($sub['aliases'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
        $prev = $findSubsectorMetricValueByAliases($sub['aliases'], $yoyPrevYear, $selectedMonthIndex, ['nilai tukar petani']);
        $pct = $toPercentChange($curr, $prev);
        if ($pct === null || (float)$pct == 0.0) {
            continue;
        }
        $yoyChangeRows[] = [
            'label' => $sub['label'],
            'pct' => $pct,
            'text' => 'subsektor ' . $sub['label'] . ' sebesar ' . number_format(abs((float)$pct), 2, ',', '.') . ' persen'
        ];
    }
    $yoyDown = array_values(array_filter($yoyChangeRows, function($r) { return $r['pct'] < 0; }));
    $yoyUp = array_values(array_filter($yoyChangeRows, function($r) { return $r['pct'] > 0; }));
    $joinListText = function(array $items): string {
        $texts = array_map(function($r) { return $r['text']; }, $items);
        if (count($texts) === 0) return '';
        if (count($texts) === 1) return $texts[0];
        if (count($texts) === 2) return $texts[0] . '; dan ' . $texts[1];
        $last = array_pop($texts);
        return implode('; ', $texts) . '; dan ' . $last;
    };
    if ($ntpYoyPct !== null) {
        $isDown = $ntpYoyPct < 0;
        $first = $isDown ? $yoyDown : $yoyUp;
        $second = $isDown ? $yoyUp : $yoyDown;
        $sentence = '';
        if (!empty($first)) {
            $sentence .= ($isDown ? 'Penurunan' : 'Kenaikan') . ' NTP ' . $currentMonthName . ' ' . $selectedYearInt .
                ' dipengaruhi oleh ' . $joinListText($first) . '.';
        }
        if (!empty($second)) {
            $sentence .= ' Sementara itu, subsektor yang ' . ($isDown ? 'mengalami peningkatan' : 'mengalami penurunan') .
                ' yaitu ' . $joinListText($second) . '.';
        }
        if ($sentence === '') {
            $sentence = 'Perubahan NTP ' . $currentMonthName . ' ' . $selectedYearInt . ' belum dapat dijelaskan karena data subsektor tidak lengkap.';
        }
        $brsDynamicSubsectorChangeNarrative = $sentence;
    } else {
        $brsDynamicSubsectorChangeNarrative = 'Data perbandingan NTP ' . $currentMonthName . ' ' . $selectedYearInt . ' dan ' . $currentMonthName . ' ' . $yoyPrevYear . ' belum tersedia.';
    }

    $momSubsectors = [
        ['label' => 'tanaman pangan', 'aliases' => ['tanaman pangan']],
        ['label' => 'tanaman hortikultura', 'aliases' => ['tanaman hortikultura', 'hortikultura']],
        ['label' => 'tanaman perkebunan rakyat', 'aliases' => ['tanaman perkebunan rakyat']],
        ['label' => 'peternakan', 'aliases' => ['peternakan']],
        ['label' => 'perikanan tangkap', 'aliases' => ['ikan tangkap', 'perikanan tangkap']],
        ['label' => 'perikanan budidaya', 'aliases' => ['ikan budidaya', 'perikanan budidaya']]
    ];
    $momChanges = [];
    foreach ($momSubsectors as $sub) {
        $curr = $findSubsectorMetricValueByAliases($sub['aliases'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);
        $prev = $findSubsectorMetricValueByAliases($sub['aliases'], $prevYearInt, $prevMonthIndex, ['nilai tukar petani']);
        $pct = $toPercentChange($curr, $prev);
        if ($pct === null || (float)$pct == 0.0) continue;
        $momChanges[] = [
            'label' => $sub['label'],
            'pct' => $pct,
            'text' => 'subsektor ' . $sub['label'] . ' sebesar ' . number_format(abs((float)$pct), 2, ',', '.') . ' persen'
        ];
    }
    $momUp = array_values(array_filter($momChanges, function($r) { return $r['pct'] > 0; }));
    $momDown = array_values(array_filter($momChanges, function($r) { return $r['pct'] < 0; }));
    $joinMom = function(array $items): string {
        $texts = array_map(function($r) { return $r['text']; }, $items);
        if (count($texts) === 0) return '';
        if (count($texts) === 1) return $texts[0];
        if (count($texts) === 2) return $texts[0] . ', dan ' . $texts[1];
        $last = array_pop($texts);
        return implode(', ', $texts) . ', dan ' . $last;
    };
    if ($ntpPct !== null) {
        $isUp = $ntpPct > 0;
        $firstList = $isUp ? $momUp : $momDown;
        $secondList = $isUp ? $momDown : $momUp;
        $momSentence = 'NTP Provinsi Lampung per subsektor pertanian mengalami kenaikan dan penurunan. ';
        if (!empty($firstList)) {
            $momSentence .= 'NTP yang mengalami ' . ($isUp ? 'kenaikan' : 'penurunan') . ' yaitu ' . $joinMom($firstList) . '.';
        }
        if (!empty($secondList)) {
            $momSentence .= ' Sedangkan NTP yang mengalami ' . ($isUp ? 'penurunan' : 'kenaikan') . ' yaitu ' . $joinMom($secondList) . '.';
        }
        $brsDynamicSubsectorMoMDetailNarrative = trim($momSentence);
    }

    $joinNarrativeList = function(array $items): string {
        $count = count($items);
        if ($count === 0) return '';
        if ($count === 1) return $items[0];
        if ($count === 2) return $items[0] . ' dan ' . $items[1];
        $last = array_pop($items);
        return implode(', ', $items) . ', dan ' . $last;
    };
    $itSubsectorConfig = [
        ['label' => 'subsektor tanaman pangan', 'aliases' => ['tanaman pangan']],
        ['label' => 'subsektor tanaman hortikultura', 'aliases' => ['hortikultura', 'tanaman hortikultura']],
        ['label' => 'subsektor tanaman perkebunan rakyat', 'aliases' => ['tanaman perkebunan rakyat']],
        ['label' => 'subsektor peternakan', 'aliases' => ['peternakan']],
        ['label' => 'subsektor perikanan tangkap', 'aliases' => ['ikan tangkap', 'perikanan tangkap']],
        ['label' => 'subsektor perikanan budidaya', 'aliases' => ['ikan budidaya', 'perikanan budidaya']]
    ];
    $itSubsectorDown = [];
    $itSubsectorUp = [];
    foreach ($itSubsectorConfig as $item) {
        $itCurrSub = $findSubsectorMetricValueByAliases($item['aliases'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
        $itPrevSub = $findSubsectorMetricValueByAliases($item['aliases'], $prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
        $itSubPct = $toPercentChange($itCurrSub, $itPrevSub);
        if ($itSubPct === null || (float) $itSubPct == 0.0) {
            continue;
        }
        $entry = $item['label'] . ' sebesar ' . $formatPctAbs($itSubPct) . ' persen';
        if ($itSubPct < 0) {
            $itSubsectorDown[] = $entry;
        } else {
            $itSubsectorUp[] = $entry;
        }
    }
    if ($itCurrent !== null && $itPrev !== null && $itPct !== null) {
        $itTrendVerb = $itPct > 0 ? 'naik' : ($itPct < 0 ? 'turun' : 'stabil');
        $itDirectionNoun = $itPct > 0 ? 'Kenaikan' : ($itPct < 0 ? 'Penurunan' : 'Pergerakan');
        $itSentence1 = 'Pada ' . $currentMonthName . ' ' . $selectedYearInt . ', It Provinsi Lampung ' . $itTrendVerb .
            ' sebesar ' . $formatPctAbs($itPct) . ' persen dibanding It ' . $prevMonthName . ' ' . $prevYearInt .
            ' yaitu dari ' . $formatIdNumber($itPrev) . ' menjadi ' . $formatIdNumber($itCurrent) . '.';

        if ($itPct < 0) {
            $parts = [];
            if (!empty($itSubsectorDown)) {
                $parts[] = $itDirectionNoun . ' It pada ' . $currentMonthName . ' dipengaruhi oleh ' . $joinNarrativeList($itSubsectorDown) . '.';
            }
            if (!empty($itSubsectorUp)) {
                $parts[] = 'Sementara itu, It yang mengalami peningkatan yaitu ' . $joinNarrativeList($itSubsectorUp) . '.';
            }
            $itSentence2 = implode(' ', $parts);
        } elseif ($itPct > 0) {
            $parts = [];
            if (!empty($itSubsectorUp)) {
                $parts[] = $itDirectionNoun . ' It pada ' . $currentMonthName . ' dipengaruhi oleh ' . $joinNarrativeList($itSubsectorUp) . '.';
            }
            if (!empty($itSubsectorDown)) {
                $parts[] = 'Sementara itu, It yang mengalami penurunan yaitu ' . $joinNarrativeList($itSubsectorDown) . '.';
            }
            $itSentence2 = implode(' ', $parts);
        } else {
            $itSentence2 = 'Pergerakan It antar subsektor relatif berimbang.';
        }

        $brsDynamicItNarrative = trim($itSentence1 . ' ' . $itSentence2);
    } else {
        $brsDynamicItNarrative = 'Data It Provinsi Lampung untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' tidak tersedia.';
    }

    $ikrtForIbCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtForIbPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtForIbPct = $toPercentChange($ikrtForIbCurrent, $ikrtForIbPrev);
    $ibppbmForIbCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmForIbPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $ibppbmForIbPct = $toPercentChange($ibppbmForIbCurrent, $ibppbmForIbPrev);
    $ikrtFallbackPct = null;
    $bppbmFallbackPct = null;
    foreach ($brsTable1TopRows as $row) {
        if ($ikrtFallbackPct === null && stripos($row['label'], 'KRT') !== false) {
            $ikrtFallbackPct = $row['change'];
        }
        if ($bppbmFallbackPct === null && stripos($row['label'], 'BPPBM') !== false) {
            $bppbmFallbackPct = $row['change'];
        }
    }
    $ikrtResolvedPct = $ikrtForIbPct ?? $ikrtFallbackPct;
    $ibppbmResolvedPct = $ibppbmForIbPct ?? $bppbmFallbackPct;

    if ($ibCurrent !== null && $ibPrev !== null && $ibPct !== null) {
        $ibTrendVerb = $ibPct > 0 ? 'naik' : ($ibPct < 0 ? 'turun' : 'stabil');
        $ikrtCauseWord = $ikrtResolvedPct !== null ? ($ikrtResolvedPct > 0 ? 'naiknya' : ($ikrtResolvedPct < 0 ? 'turunnya' : 'stabilnya')) : 'perubahan';
        $ibppbmCauseWord = $ibppbmResolvedPct !== null ? ($ibppbmResolvedPct > 0 ? 'naiknya' : ($ibppbmResolvedPct < 0 ? 'turunnya' : 'stabilnya')) : 'perubahan';
        $brsDynamicIbNarrative = 'Angka Ib dapat menggambarkan fluktuasi harga barang dan jasa yang dikonsumsi oleh masyarakat perdesaan, khususnya petani yang merupakan bagian terbesar dari masyarakat perdesaan, serta fluktuasi harga barang dan jasa yang diperlukan untuk memproduksi hasil pertanian. Pada ' .
            $currentMonthName . ' ' . $selectedYearInt . ', Ib Provinsi Lampung ' . $ibTrendVerb . ' ' .
            $formatPctAbs($ibPct) . ' persen bila dibanding Ib ' . $prevMonthName . ' ' . $prevYearInt .
            ' yaitu dari ' . $formatIdNumber($ibPrev) . ' menjadi ' . $formatIdNumber($ibCurrent) .
            '. Hal ini disebabkan oleh ' . $ikrtCauseWord . ' IKRT sebesar ' . $formatPctAbs($ikrtResolvedPct) .
            ' persen dan ' . $ibppbmCauseWord . ' IBPPBM sebesar ' . $formatPctAbs($ibppbmResolvedPct) . ' persen.';
    } else {
        $brsDynamicIbNarrative = 'Data Ib Provinsi Lampung untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' tidak tersedia.';
    }

    $brsTable1DetailConfig = [
        ['type' => 'section', 'label' => '1. Tanaman Pangan'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Petani Tanaman Pangan (NTPP)', 'subsektor' => 'tanaman pangan', 'aliases' => ['nilai tukar petani']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Petani (It)', 'subsektor' => 'tanaman pangan', 'aliases' => ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']],
        ['type' => 'metric', 'label' => '- Padi', 'subsektor' => 'tanaman pangan', 'aliases' => ['padi']],
        ['type' => 'metric', 'label' => '- Palawija', 'subsektor' => 'tanaman pangan', 'aliases' => ['palawija']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar oleh Petani (Ib)', 'subsektor' => 'tanaman pangan', 'aliases' => ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'tanaman pangan', 'aliases' => ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'tanaman pangan', 'aliases' => ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']],

        ['type' => 'section', 'label' => '2. Hortikultura'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Petani Hortikultura (NTPH)', 'subsektor' => 'hortikultura', 'aliases' => ['nilai tukar petani']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Petani (It)', 'subsektor' => 'hortikultura', 'aliases' => ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']],
        ['type' => 'metric', 'label' => '- Sayur-sayuran', 'subsektor' => 'hortikultura', 'aliases' => ['sayur sayuran']],
        ['type' => 'metric', 'label' => '- Buah-buahan', 'subsektor' => 'hortikultura', 'aliases' => ['buah buahan']],
        ['type' => 'metric', 'label' => '- Tanaman Obat', 'subsektor' => 'hortikultura', 'aliases' => ['tanaman obat']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar oleh Petani (Ib)', 'subsektor' => 'hortikultura', 'aliases' => ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'hortikultura', 'aliases' => ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'hortikultura', 'aliases' => ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']],

        ['type' => 'section', 'label' => '3. Tanaman Perkebunan Rakyat'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Petani Perkebunan Rakyat (NTPR)', 'subsektor' => 'tanaman perkebunan rakyat', 'aliases' => ['nilai tukar petani']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Petani (It)', 'subsektor' => 'tanaman perkebunan rakyat', 'aliases' => ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']],
        ['type' => 'metric', 'label' => '- Tanaman Perkebunan Rakyat', 'subsektor' => 'tanaman perkebunan rakyat', 'aliases' => ['tanaman perkebunan rakyat']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar oleh Petani (Ib)', 'subsektor' => 'tanaman perkebunan rakyat', 'aliases' => ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'tanaman perkebunan rakyat', 'aliases' => ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'tanaman perkebunan rakyat', 'aliases' => ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']],

        ['type' => 'section', 'label' => '4. Peternakan'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Petani Peternakan (NTPT)', 'subsektor' => 'peternakan', 'aliases' => ['nilai tukar petani']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Petani (It)', 'subsektor' => 'peternakan', 'aliases' => ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']],
        ['type' => 'metric', 'label' => '- Ternak Besar', 'subsektor' => 'peternakan', 'aliases' => ['ternak besar']],
        ['type' => 'metric', 'label' => '- Ternak Kecil', 'subsektor' => 'peternakan', 'aliases' => ['ternak kecil']],
        ['type' => 'metric', 'label' => '- Unggas', 'subsektor' => 'peternakan', 'aliases' => ['unggas']],
        ['type' => 'metric', 'label' => '- Hasil Ternak', 'subsektor' => 'peternakan', 'aliases' => ['hasil ternak']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar oleh Petani (Ib)', 'subsektor' => 'peternakan', 'aliases' => ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'peternakan', 'aliases' => ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'peternakan', 'aliases' => ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']],

        ['type' => 'section', 'label' => '5. Perikanan'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Nelayan dan Pembudidaya Ikan (NTNP)', 'subsektor' => 'perikanan', 'aliases' => ['nilai tukar petani', 'nilai tukar nelayan dan pembudidaya ikan']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Nelayan dan Pembudidaya Ikan (It)', 'subsektor' => 'perikanan', 'aliases' => ['indeks harga yang diterima oleh nelayan dan pembudidaya ikan', 'indeks harga yang diterima petani']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar oleh Nelayan dan Pembudidaya Ikan (Ib)', 'subsektor' => 'perikanan', 'aliases' => ['indeks harga yang dibayar oleh nelayan dan pembudidaya ikan', 'indeks harga yang dibayar petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'perikanan', 'aliases' => ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'perikanan', 'aliases' => ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']],

        ['type' => 'section', 'label' => '5.1. Perikanan Tangkap'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Nelayan (NTN)', 'subsektor' => 'ikan tangkap', 'aliases' => ['nilai tukar petani']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Nelayan (It)', 'subsektor' => 'ikan tangkap', 'aliases' => ['indeks harga yang diterima petani']],
        ['type' => 'metric', 'label' => '- Penangkapan Perairan Umum', 'subsektor' => 'ikan tangkap', 'aliases' => ['penangkapan di perairan umum']],
        ['type' => 'metric', 'label' => '- Penangkapan Laut', 'subsektor' => 'ikan tangkap', 'aliases' => ['penangkapan di laut']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar Nelayan (Ib)', 'subsektor' => 'ikan tangkap', 'aliases' => ['indeks harga yang dibayar petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'ikan tangkap', 'aliases' => ['konsumsi rumah tangga']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'ikan tangkap', 'aliases' => ['bppbm']],

        ['type' => 'section', 'label' => '5.2. Perikanan Budidaya'],
        ['type' => 'metric', 'label' => 'a. Nilai Tukar Pembudidaya Ikan (NTPI)', 'subsektor' => 'ikan budidaya', 'aliases' => ['nilai tukar petani']],
        ['type' => 'metric', 'label' => 'b. Indeks Harga yang Diterima oleh Pembudidaya Ikan (It)', 'subsektor' => 'ikan budidaya', 'aliases' => ['indeks harga yang diterima petani']],
        ['type' => 'metric', 'label' => '- Budidaya Air Tawar', 'subsektor' => 'ikan budidaya', 'aliases' => ['budidaya air tawar']],
        ['type' => 'metric', 'label' => '- Budidaya Laut', 'subsektor' => 'ikan budidaya', 'aliases' => ['budidaya laut']],
        ['type' => 'metric', 'label' => '- Budidaya Air Payau', 'subsektor' => 'ikan budidaya', 'aliases' => ['budidaya air payau']],
        ['type' => 'metric', 'label' => 'c. Indeks Harga yang Dibayar oleh Pembudidaya Ikan (Ib)', 'subsektor' => 'ikan budidaya', 'aliases' => ['indeks harga yang dibayar petani']],
        ['type' => 'metric', 'label' => '- Indeks Konsumsi Rumah Tangga (KRT)', 'subsektor' => 'ikan budidaya', 'aliases' => ['konsumsi rumah tangga']],
        ['type' => 'metric', 'label' => '- Indeks Biaya Produksi dan Penambahan Barang Modal (BPPBM)', 'subsektor' => 'ikan budidaya', 'aliases' => ['bppbm']]
    ];

    foreach ($brsTable1DetailConfig as $rowConfig) {
        if ($rowConfig['type'] === 'section') {
            $brsTable1DetailRows[] = [
                'type' => 'section',
                'label' => $rowConfig['label'],
                'current' => null,
                'previous' => null,
                'change' => null
            ];
            continue;
        }
        $metricRow = $buildSubsectorMetricRow($rowConfig['label'], $rowConfig['subsektor'], $rowConfig['aliases']);
        $metricRow['type'] = 'metric';
        $brsTable1DetailRows[] = $metricRow;
    }

    $findProvMetricValue = function(string $provCode, int $tahun, int $bulanIndex, array $aliases) use ($periodProvMetricValues, $normalizeMetricKey): ?float {
        $periodKey = $tahun . '-' . $bulanIndex;
        if (!isset($periodProvMetricValues[$periodKey][$provCode])) {
            return null;
        }
        $bucket = $periodProvMetricValues[$periodKey][$provCode];
        foreach ($aliases as $aliasRaw) {
            $alias = $normalizeMetricKey($aliasRaw);
            $matched = [];
            foreach ($bucket as $metricKey => $values) {
                if ($metricKey === $alias || strpos($metricKey, $alias) !== false) {
                    $matched = array_merge($matched, $values);
                }
            }
            if (!empty($matched)) {
                return array_sum($matched) / count($matched);
            }
        }
        return null;
    };
    $findProvMetricValueExact = function(string $provCode, int $tahun, int $bulanIndex, array $aliases) use ($periodProvMetricValues, $normalizeMetricKey): ?float {
        $periodKey = $tahun . '-' . $bulanIndex;
        if (!isset($periodProvMetricValues[$periodKey][$provCode])) {
            return null;
        }
        $bucket = $periodProvMetricValues[$periodKey][$provCode];
        foreach ($aliases as $aliasRaw) {
            $alias = $normalizeMetricKey($aliasRaw);
            if (isset($bucket[$alias]) && !empty($bucket[$alias])) {
                return array_sum($bucket[$alias]) / count($bucket[$alias]);
            }
        }
        return null;
    };
    $findProvMetricChangePct = function(string $provCode, int $tahun, int $bulanIndex, int $prevTahun, int $prevBulanIndex, array $aliases) use ($findProvMetricValue): ?float {
        $currentValue = $findProvMetricValue($provCode, $tahun, $bulanIndex, $aliases);
        $previousValue = $findProvMetricValue($provCode, $prevTahun, $prevBulanIndex, $aliases);
        if ($currentValue === null || $previousValue === null || (float) $previousValue == 0.0) {
            return null;
        }
        return (($currentValue - $previousValue) / $previousValue) * 100.0;
    };
    $findProvMetricChangePctExact = function(string $provCode, int $tahun, int $bulanIndex, int $prevTahun, int $prevBulanIndex, array $aliases) use ($findProvMetricValueExact): ?float {
        $currentValue = $findProvMetricValueExact($provCode, $tahun, $bulanIndex, $aliases);
        $previousValue = $findProvMetricValueExact($provCode, $prevTahun, $prevBulanIndex, $aliases);
        if ($currentValue === null || $previousValue === null || (float) $previousValue == 0.0) {
            return null;
        }
        return (($currentValue - $previousValue) / $previousValue) * 100.0;
    };

    $ikrtCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['konsumsi rumah tangga', 'indeks konsumsi rumah tangga (krt)']);
    $ikrtPct = $toPercentChange($ikrtCurrent, $ikrtPrev);
    $ikrtGroupCandidates = [
        ['header' => 'Makanan, Minuman, dan Tembakau', 'aliases' => ['Makanan, Minuman, dan Tembakau', 'Makanan Minuman dan Tembakau']],
        ['header' => 'Pakaian dan Alas Kaki', 'aliases' => ['Pakaian dan Alas Kaki']],
        ['header' => 'Perumahan, Air, Listrik, dan Bahan Bakar Rumah Tangga', 'aliases' => ['Perumahan, Air, Listrik, dan Bahan Bakar Rumah Tangga']],
        ['header' => 'Perlengkapan, Peralatan, dan Pemeliharaan Rutin Rumah Tangga', 'aliases' => ['Perlengkapan, Peralatan, dan Pemeliharaan Rutin Rumah Tangga']],
        ['header' => 'Kesehatan', 'aliases' => ['Kesehatan']],
        ['header' => 'Transportasi', 'aliases' => ['Transportasi']],
        ['header' => 'Informasi, Komunikasi, dan Jasa Keuangan', 'aliases' => ['Informasi, Komunikasi, dan Jasa Keuangan']],
        ['header' => 'Rekreasi, Olahraga, dan Budaya', 'aliases' => ['Rekreasi, Olahraga, dan Budaya']],
        ['header' => 'Pendidikan', 'aliases' => ['Pendidikan']],
        ['header' => 'Penyediaan Makanan dan Minuman/Restoran', 'aliases' => ['Penyediaan Makanan dan Minuman/Restoran']],
        ['header' => 'Perawatan Pribadi dan Jasa Lainnya', 'aliases' => ['Perawatan Pribadi dan Jasa Lainnya']]
    ];
    $ikrtGroupUp = [];
    $ikrtGroupDown = [];
    foreach ($ikrtGroupCandidates as $group) {
        $groupPct = $findProvMetricChangePct('18', $selectedYearInt, $selectedMonthIndex, $prevYearInt, $prevMonthIndex, $group['aliases']);
        if ($groupPct === null) {
            continue;
        }
        if ((float) $groupPct > 0) {
            $ikrtGroupUp[] = ['header' => $group['header'], 'pct' => $groupPct];
        } elseif ((float) $groupPct < 0) {
            $ikrtGroupDown[] = ['header' => $group['header'], 'pct' => $groupPct];
        }
    }
    usort($ikrtGroupUp, function($a, $b) { return ((float)$b['pct']) <=> ((float)$a['pct']); });
    usort($ikrtGroupDown, function($a, $b) { return ((float)$a['pct']) <=> ((float)$b['pct']); });
    $formatGroupList = function(array $groups): string {
        $parts = [];
        foreach ($groups as $g) {
            $parts[] = 'kelompok ' . $g['header'] . ' sebesar ' . number_format(abs((float) $g['pct']), 2, ',', '.') . ' persen';
        }
        if (count($parts) === 0) {
            return '';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);
        return implode('; ', $parts) . '; dan ' . $last;
    };
    $ikrtStaticNationalText = '';
    if ($ikrtNationalPct !== null && $ikrtTopProv !== null && $ikrtTopPct !== null) {
        $ikrtNationalText = 'Secara nasional, IKRT mengalami ' . ($ikrtNationalPct >= 0 ? 'peningkatan' : 'penurunan') .
            ' indeks sebesar ' . number_format(abs((float) $ikrtNationalPct), 2, ',', '.') . ' persen pada ' . $currentMonthName . ' ' . $selectedYearInt . '.';
        $ikrtTopText = 'Dilihat dari keterbandingannya, IKRT di seluruh Indonesia mengalami ' . ($ikrtTopPct >= 0 ? 'peningkatan' : 'penurunan') .
            ' tertinggi yang terjadi di Provinsi ' . $ikrtTopProv . ' sebesar ' . number_format(abs((float) $ikrtTopPct), 2, ',', '.') . ' persen.';
        $ikrtStaticNationalText = $ikrtNationalText . ' ' . $ikrtTopText;
        if ($ikrtLampungPct !== null && $ikrtLampungRank !== null) {
            $ikrtLampungText = 'Sementara itu, Provinsi Lampung juga mengalami ' . ($ikrtLampungPct >= 0 ? 'kenaikan' : 'penurunan') .
                ' IKRT sebesar ' . number_format(abs((float) $ikrtLampungPct), 2, ',', '.') . ' persen menempati peringkat ke-' . $ikrtLampungRank . ' dibandingkan seluruh provinsi di Indonesia.';
            $ikrtStaticNationalText .= ' ' . $ikrtLampungText;
        }
    } else {
        $fallbackNationalPct = 0.53;
        $fallbackTopProv = 'Sulawesi Utara';
        $fallbackTopPct = 2.34;
        $fallbackLampungPct = 0.85;
        $fallbackLampungRank = 12;

        $nationalPct = $ikrtNationalPct ?? $fallbackNationalPct;
        $topProv = $ikrtTopProv ?? $fallbackTopProv;
        $topPct = $ikrtTopPct ?? $fallbackTopPct;
        $lampungPct = $ikrtLampungPct ?? $fallbackLampungPct;
        $lampungRank = $ikrtLampungRank ?? $fallbackLampungRank;

        $ikrtStaticNationalText = 'Secara nasional, IKRT mengalami peningkatan indeks sebesar ' .
            number_format(abs((float) $nationalPct), 2, ',', '.') . ' persen pada ' . $currentMonthName . ' ' . $selectedYearInt . '. ' .
            'Dilihat dari keterbandingannya, IKRT di seluruh Indonesia mengalami peningkatan tertinggi yang terjadi di Provinsi ' .
            $topProv . ' sebesar ' . number_format(abs((float) $topPct), 2, ',', '.') . ' persen. ' .
            'Sementara itu, Provinsi Lampung juga mengalami kenaikan IKRT sebesar ' .
            number_format(abs((float) $lampungPct), 2, ',', '.') . ' persen menempati peringkat ke-' . $lampungRank . ' secara nasional.';
    }

    if ($ikrtPct === null) {
        $brsDynamicIkrtNarrative = 'Data Indeks Konsumsi Rumah Tangga (IKRT) Provinsi Lampung untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' tidak tersedia. ' . $ikrtStaticNationalText;
    } else {
        $ikrtWord = $ikrtPct > 0 ? 'peningkatan' : ($ikrtPct < 0 ? 'penurunan' : 'pergerakan');
        $ikrtPrimaryList = $ikrtPct >= 0 ? $ikrtGroupUp : $ikrtGroupDown;
        $ikrtSecondaryList = $ikrtPct >= 0 ? $ikrtGroupDown : $ikrtGroupUp;
        $ikrtPrimaryVerb = $ikrtPct >= 0 ? 'naiknya' : 'turunnya';
        $ikrtSecondaryDesc = $ikrtPct >= 0 ? 'penurunan' : 'kenaikan';

        if (!empty($ikrtPrimaryList)) {
            $dominant = $ikrtPrimaryList[0];
            $brsDynamicIkrtDominantNarrative = 'Pada ' . $currentMonthName . ' ' . $selectedYearInt . ' terjadi ' . $ikrtWord .
                ' Indeks Konsumsi Rumah Tangga (IKRT) Provinsi Lampung sebesar ' . number_format(abs((float) $ikrtPct), 2, ',', '.') .
                ' persen dominan disebabkan oleh ' . $ikrtPrimaryVerb . ' indeks kelompok ' . $dominant['header'] . ' sebesar ' .
                number_format(abs((float) $dominant['pct']), 2, ',', '.') . ' persen.';
        }

        if (empty($ikrtPrimaryList)) {
            $brsDynamicIkrtNarrative = 'Konsumsi rumah tangga petani merupakan salah satu komponen nilai yang dibayar oleh petani. Pada ' .
                $currentMonthName . ' ' . $selectedYearInt . ', IKRT di Provinsi Lampung mengalami ' . $ikrtWord .
                ' sebesar ' . number_format(abs((float) $ikrtPct), 2, ',', '.') . ' persen. ' . $ikrtStaticNationalText;
        } else {
            $primaryText = $formatGroupList($ikrtPrimaryList);
            $sentence = 'Konsumsi rumah tangga petani merupakan salah satu komponen nilai yang dibayar oleh petani. Pada ' .
                $currentMonthName . ' ' . $selectedYearInt . ', IKRT di Provinsi Lampung mengalami ' . $ikrtWord .
                ' sebesar ' . number_format(abs((float) $ikrtPct), 2, ',', '.') . ' persen yang disebabkan oleh ' .
                $ikrtPrimaryVerb . ' indeks ' . $primaryText . '.';
            if (!empty($ikrtSecondaryList)) {
                $secondaryText = $formatGroupList($ikrtSecondaryList);
                $sentence .= ' Sementara itu, kelompok yang mengalami ' . $ikrtSecondaryDesc . ' indeks adalah ' . $secondaryText . '.';
            }
            $brsDynamicIkrtNarrative = $sentence . ' ' . $ikrtStaticNationalText;
        }
    }

    $ntupCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['nilai tukar usaha pertanian']);
    $ntupPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['nilai tukar usaha pertanian']);
    $ntupPct = $toPercentChange($ntupCurrent, $ntupPrev);
    $itForNtupCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itForNtupPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
    $itForNtupPct = $toPercentChange($itForNtupCurrent, $itForNtupPrev);
    $bppbmCurrent = $findMetricValue($selectedYearInt, $selectedMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $bppbmPrev = $findMetricValue($prevYearInt, $prevMonthIndex, ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']);
    $bppbmPct = $toPercentChange($bppbmCurrent, $bppbmPrev);

    $ntupSubsectors = [
        ['label' => 'tanaman pangan', 'aliases' => ['tanaman pangan']],
        ['label' => 'hortikultura', 'aliases' => ['hortikultura', 'tanaman hortikultura']],
        ['label' => 'tanaman perkebunan rakyat', 'aliases' => ['tanaman perkebunan rakyat']],
        ['label' => 'peternakan', 'aliases' => ['peternakan']],
        ['label' => 'perikanan tangkap', 'aliases' => ['ikan tangkap', 'perikanan tangkap']],
        ['label' => 'perikanan budidaya', 'aliases' => ['ikan budidaya', 'perikanan budidaya']]
    ];
    $ntupDown = [];
    $ntupUp = [];
    foreach ($ntupSubsectors as $sub) {
        $curr = $findSubsectorMetricValueByAliases($sub['aliases'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar usaha pertanian']);
        $prev = $findSubsectorMetricValueByAliases($sub['aliases'], $prevYearInt, $prevMonthIndex, ['nilai tukar usaha pertanian']);
        $pct = $toPercentChange($curr, $prev);
        if ($pct === null || (float)$pct == 0.0) continue;
        $entry = 'subsektor ' . $sub['label'] . ' sebesar ' . number_format(abs((float)$pct), 2, ',', '.') . ' persen';
        if ($pct < 0) {
            $ntupDown[] = $entry;
        } else {
            $ntupUp[] = $entry;
        }
    }
    $joinText = function(array $items): string {
        if (count($items) === 0) return '';
        if (count($items) === 1) return $items[0];
        if (count($items) === 2) return $items[0] . '; dan ' . $items[1];
        $last = array_pop($items);
        return implode('; ', $items) . '; dan ' . $last;
    };

    $ntupIntro = 'Nilai Tukar Usaha Rumah Tangga Pertanian (NTUP) merupakan perbandingan antara Indeks Harga yang Diterima oleh Petani (It) dengan Indeks Harga yang dibayar oleh Petani (Ib) dimana komponen Ib hanya meliputi Biaya Produksi dan Penambahan Barang Modal (BPPBM). Secara konseptual, NTUP mengukur seberapa cepat It dibandingkan dengan indeks BPPBM.';

    if ($ntupCurrent === null || $ntupPct === null) {
        $brsDynamicNtupNarrative = $ntupIntro . ' Data NTUP Provinsi Lampung untuk ' . $currentMonthName . ' ' . $selectedYearInt . ' tidak tersedia.';
    } else {
        $brsDynamicNtupBriefNarrative = 'Nilai Tukar Usaha Rumah Tangga Pertanian (NTUP) Provinsi Lampung ' . $currentMonthName . ' ' . $selectedYearInt .
            ' sebesar ' . $formatIdNumber($ntupCurrent) . ' atau ' . ($ntupPct > 0 ? 'naik' : ($ntupPct < 0 ? 'turun' : 'tetap')) . ' ' .
            number_format(abs((float)$ntupPct), 2, ',', '.') . ' persen dibanding NTUP ' . $prevMonthName . ' ' . $prevYearInt .
            ' sebesar ' . $formatIdNumber($ntupPrev) . '.';
        $brsDynamicNtupHeadline = 'Nilai Tukar Usaha Petani (NTUP) selama ' . $currentMonthName . ' ' . $selectedYearInt .
            ' ' . ($ntupPct > 0 ? 'mengalami kenaikan' : ($ntupPct < 0 ? 'mengalami penurunan' : 'tidak mengalami perubahan')) .
            ' sebesar ' . number_format(abs((float)$ntupPct), 2, ',', '.') . ' persen dibandingkan ' . $prevMonthName . ' ' . $prevYearInt .
            ', dari ' . $formatIdNumber($ntupPrev) . ' menjadi ' . $formatIdNumber($ntupCurrent) . '.';
        $trendWord = $ntupPct > 0 ? 'naik' : ($ntupPct < 0 ? 'turun' : 'stabil');
        $brsDynamicNtupNarrative = $ntupIntro . ' Nilai Tukar Usaha Petani (NTUP) selama ' . $currentMonthName . ' ' . $selectedYearInt .
            ' ' . ($ntupPct > 0 ? 'mengalami kenaikan' : ($ntupPct < 0 ? 'mengalami penurunan' : 'tidak mengalami perubahan')) .
            ' sebesar ' . number_format(abs((float)$ntupPct), 2, ',', '.') . ' persen dibandingkan ' . $prevMonthName . ' ' . $prevYearInt .
            ', dari ' . $formatIdNumber($ntupPrev) . ' menjadi ' . $formatIdNumber($ntupCurrent) . '.';
        if ($itForNtupPct !== null && $bppbmPct !== null) {
            $brsDynamicNtupNarrative .= ' Hal ini terjadi karena It ' . $trendWord . ' sebesar ' . number_format(abs((float)$itForNtupPct), 2, ',', '.') .
                ' persen, dan BPPBM yang ' . $trendWord . ' sebesar ' . number_format(abs((float)$bppbmPct), 2, ',', '.') . ' persen.';
        }
        if ($ntupPct < 0 && !empty($ntupDown)) {
            $brsDynamicNtupNarrative .= ' Seperti yang terlihat pada tabel 4, beberapa subsektor mengalami penurunan NTUP seperti ' . $joinText($ntupDown) . '.';
            if (!empty($ntupUp)) {
                $brsDynamicNtupNarrative .= ' Sementara itu, subsektor yang mengalami peningkatan NTUP yaitu ' . $joinText($ntupUp) . '.';
            }
        } elseif ($ntupPct > 0 && !empty($ntupUp)) {
            $brsDynamicNtupNarrative .= ' Seperti yang terlihat pada tabel 4, beberapa subsektor mengalami peningkatan NTUP seperti ' . $joinText($ntupUp) . '.';
            if (!empty($ntupDown)) {
                $brsDynamicNtupNarrative .= ' Sementara itu, subsektor yang mengalami penurunan NTUP yaitu ' . $joinText($ntupDown) . '.';
            }
        }
    }

    $brsProvOrder = [
        '11' => 'NAD', '12' => 'SUMUT', '13' => 'SUMBAR', '14' => 'RIAU', '15' => 'JAMBI',
        '16' => 'SUMSEL', '17' => 'BENGKULU', '18' => 'LAMPUNG', '19' => 'BABEL', '21' => 'KEPRI',
        '31' => 'DKI', '32' => 'JABAR', '33' => 'JATENG', '34' => 'YOGYAKARTA', '35' => 'JATIM',
        '36' => 'BANTEN', '51' => 'BALI', '52' => 'NTB', '53' => 'NTT', '61' => 'KALBAR',
        '62' => 'KALTENG', '63' => 'KALSEL', '64' => 'KALTIM', '65' => 'KALTARA', '71' => 'SULUT',
        '72' => 'SULTENG', '73' => 'SULSEL', '74' => 'SULTRA', '75' => 'GORONTALO', '76' => 'SULBAR',
        '81' => 'MALUKU', '82' => 'MALUKU UTARA', '91' => 'PAPUA BARAT', '92' => 'PAPUA BARAT DAYA',
        '94' => 'PAPUA', '95' => 'PAPUA TENGAH', '96' => 'PAPUA SELATAN'
    ];

    $brsTable2Columns = [
        ['header' => 'Makanan, Minuman, dan Tembakau', 'aliases' => ['Makanan, Minuman, dan Tembakau']],
        ['header' => 'Pakaian dan Alas Kaki', 'aliases' => ['Pakaian dan Alas Kaki']],
        ['header' => 'Perumahan, Air, Listrik, dan Bahan Bakar Rumah Tangga', 'aliases' => ['Perumahan, Air, Listrik, dan Bahan Bakar Rumah Tangga']],
        ['header' => 'Perlengkapan, Peralatan, dan Pemeliharaan Rutin Rumah Tangga', 'aliases' => ['Perlengkapan, Peralatan, dan Pemeliharaan Rutin Rumah Tangga']],
        ['header' => 'Kesehatan', 'aliases' => ['Kesehatan']],
        ['header' => 'Transportasi', 'aliases' => ['Transportasi']],
        ['header' => 'Informasi, Komunikasi, dan Jasa Keuangan', 'aliases' => ['Informasi, Komunikasi, dan Jasa Keuangan']],
        ['header' => 'Rekreasi, Olahraga, dan Budaya', 'aliases' => ['Rekreasi, Olahraga, dan Budaya']],
        ['header' => 'Pendidikan', 'aliases' => ['Pendidikan']],
        ['header' => 'Penyediaan Makanan dan Minuman/Restoran', 'aliases' => ['Penyediaan Makanan dan Minuman/Restoran']],
        ['header' => 'Perawatan Pribadi dan Jasa Lainnya', 'aliases' => ['Perawatan Pribadi dan Jasa Lainnya']],
        ['header' => 'Konsumsi Rumah Tangga', 'aliases' => ['Konsumsi Rumah Tangga', 'Indeks Konsumsi Rumah Tangga (KRT)', 'KRT']]
    ];

    $krtColumnIndex = null;
    foreach ($brsTable2Columns as $idx => $col) {
        if (stripos($col['header'], 'Konsumsi Rumah Tangga') !== false) {
            $krtColumnIndex = $idx;
            break;
        }
    }

    foreach ($brsProvOrder as $provCode => $provLabel) {
        $values = [];
        foreach ($brsTable2Columns as $column) {
            $values[] = $findProvMetricChangePctExact($provCode, $selectedYearInt, $selectedMonthIndex, $prevYearInt, $prevMonthIndex, $column['aliases']);
        }
        $brsTable2Rows[] = [
            'code' => $provCode,
            'label' => $provLabel,
            'values' => $values
        ];
    }

    $ikrtNationalPct = null;
    $ikrtTopProv = null;
    $ikrtTopPct = null;
    $ikrtLampungPct = null;
    $ikrtLampungRank = null;
    if ($krtColumnIndex !== null) {
        $krtRows = [];
        foreach ($brsTable2Rows as $row) {
            $val = $row['values'][$krtColumnIndex] ?? null;
            if ($val === null) {
                continue;
            }
            $krtRows[] = [
                'code' => $row['code'],
                'label' => $row['label'],
                'value' => (float) $val
            ];
        }
        if (!empty($krtRows)) {
            $sum = 0.0;
            foreach ($krtRows as $r) {
                $sum += (float) $r['value'];
                if ($r['code'] === '18') {
                    $ikrtLampungPct = (float) $r['value'];
                }
                if ($ikrtTopPct === null || (float) $r['value'] > (float) $ikrtTopPct) {
                    $ikrtTopPct = (float) $r['value'];
                    $ikrtTopProv = $r['label'];
                }
            }
            $ikrtNationalPct = $sum / count($krtRows);
            usort($krtRows, function($a, $b) {
                return ((float) $b['value']) <=> ((float) $a['value']);
            });
            foreach ($krtRows as $i => $r) {
                if ($r['code'] === '18') {
                    $ikrtLampungRank = $i + 1;
                    break;
                }
            }
        }
    }

    if ($ikrtNationalPct === null && $krtColumnIndex !== null) {
        $krtCurrMap = [];
        $krtPrevMap = [];
        $stmtKrtCurr = $conn->prepare("SELECT prov, nilai FROM NTP_Subsektor WHERE LOWER(TRIM(subsektor))='gabungan' AND tahun=? AND bulan=? AND (LOWER(TRIM(rincian)) LIKE '%konsumsi rumah tangga%' OR LOWER(TRIM(rincian)) LIKE '%krt%')");
        if ($stmtKrtCurr) {
            $stmtKrtCurr->bind_param('is', $selectedYearInt, $selectedMonthIndex);
            if ($stmtKrtCurr->execute()) {
                $res = $stmtKrtCurr->get_result();
                while ($row = $res->fetch_assoc()) {
                    $krtCurrMap[trim((string) $row['prov'])] = (float) $row['nilai'];
                }
            }
            $stmtKrtCurr->close();
        }
        $stmtKrtPrev = $conn->prepare("SELECT prov, nilai FROM NTP_Subsektor WHERE LOWER(TRIM(subsektor))='gabungan' AND tahun=? AND bulan=? AND (LOWER(TRIM(rincian)) LIKE '%konsumsi rumah tangga%' OR LOWER(TRIM(rincian)) LIKE '%krt%')");
        if ($stmtKrtPrev) {
            $stmtKrtPrev->bind_param('is', $prevYearInt, $prevMonthIndex);
            if ($stmtKrtPrev->execute()) {
                $res = $stmtKrtPrev->get_result();
                while ($row = $res->fetch_assoc()) {
                    $krtPrevMap[trim((string) $row['prov'])] = (float) $row['nilai'];
                }
            }
            $stmtKrtPrev->close();
        }
        $krtRows = [];
        foreach ($brsProvOrder as $provCode => $provLabel) {
            if (!isset($krtCurrMap[$provCode]) || !isset($krtPrevMap[$provCode]) || (float) $krtPrevMap[$provCode] == 0.0) {
                continue;
            }
            $pct = (($krtCurrMap[$provCode] - $krtPrevMap[$provCode]) / $krtPrevMap[$provCode]) * 100.0;
            $krtRows[] = ['code' => $provCode, 'label' => $provLabel, 'value' => $pct];
            if ($provCode === '18') {
                $ikrtLampungPct = $pct;
            }
            if ($ikrtTopPct === null || $pct > $ikrtTopPct) {
                $ikrtTopPct = $pct;
                $ikrtTopProv = $provLabel;
            }
        }
        if (!empty($krtRows)) {
            $sum = 0.0;
            foreach ($krtRows as $r) {
                $sum += (float) $r['value'];
            }
            $ikrtNationalPct = $sum / count($krtRows);
            usort($krtRows, function($a, $b) {
                return ((float) $b['value']) <=> ((float) $a['value']);
            });
            foreach ($krtRows as $i => $r) {
                if ($r['code'] === '18') {
                    $ikrtLampungRank = $i + 1;
                    break;
                }
            }
        }
    }

    $yoyTableYear = max(0, $selectedYearInt - 1);
    $brsTable3Config = [
        ['type' => 'metric', 'label' => '1. Tanaman Pangan', 'subsektorAliases' => ['tanaman pangan']],
        ['type' => 'metric', 'label' => '2. Tanaman Hortikultura', 'subsektorAliases' => ['hortikultura', 'tanaman hortikultura']],
        ['type' => 'metric', 'label' => '3. Tanaman Perkebunan  Rakyat', 'subsektorAliases' => ['tanaman perkebunan rakyat']],
        ['type' => 'metric', 'label' => '4. Peternakan', 'subsektorAliases' => ['peternakan']],
        ['type' => 'section', 'label' => '5. Perikanan'],
        ['type' => 'metric', 'label' => '&nbsp;&nbsp;a. Tangkap', 'subsektorAliases' => ['ikan tangkap', 'perikanan tangkap']],
        ['type' => 'metric', 'label' => '&nbsp;&nbsp;b. Budidaya', 'subsektorAliases' => ['ikan budidaya', 'perikanan budidaya']],
        ['type' => 'metric', 'label' => 'Provinsi Lampung', 'subsektorAliases' => ['gabungan']]
    ];

    foreach ($brsTable3Config as $rowConfig) {
        if ($rowConfig['type'] === 'section') {
            $brsTable3Rows[] = [
                'type' => 'section',
                'label' => $rowConfig['label'],
                'prevIt' => null, 'prevIb' => null, 'prevNtp' => null,
                'currentIt' => null, 'currentIb' => null, 'currentNtp' => null,
                'changeNtpPct' => null
            ];
            continue;
        }

        $prevIt = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $yoyTableYear, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
        $prevIb = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $yoyTableYear, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
        $prevNtp = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $yoyTableYear, $selectedMonthIndex, ['nilai tukar petani']);

        $currentIt = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']);
        $currentIb = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $selectedYearInt, $selectedMonthIndex, ['indeks harga yang dibayar petani', 'indeks harga yang dibayar oleh petani']);
        $currentNtp = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $selectedYearInt, $selectedMonthIndex, ['nilai tukar petani']);

        $changeNtpPct = ($currentNtp !== null && $prevNtp !== null && $prevNtp != 0)
            ? (($currentNtp - $prevNtp) / $prevNtp) * 100
            : null;

        $brsTable3Rows[] = [
            'type' => 'metric',
            'label' => $rowConfig['label'],
            'prevIt' => $prevIt, 'prevIb' => $prevIb, 'prevNtp' => $prevNtp,
            'currentIt' => $currentIt, 'currentIb' => $currentIb, 'currentNtp' => $currentNtp,
            'changeNtpPct' => $changeNtpPct
        ];
    }

    $brsTable4Config = [
        ['type' => 'metric', 'label' => '1. Tanaman Pangan', 'subsektorAliases' => ['tanaman pangan']],
        ['type' => 'metric', 'label' => '2. Hortikultura', 'subsektorAliases' => ['hortikultura', 'tanaman hortikultura']],
        ['type' => 'metric', 'label' => '3. Tanaman Perkebunan Rakyat', 'subsektorAliases' => ['tanaman perkebunan rakyat']],
        ['type' => 'metric', 'label' => '4. Peternakan', 'subsektorAliases' => ['peternakan']],
        ['type' => 'section', 'label' => '5. Perikanan'],
        ['type' => 'metric', 'label' => '&nbsp;&nbsp;a. Tangkap', 'subsektorAliases' => ['ikan tangkap', 'perikanan tangkap']],
        ['type' => 'metric', 'label' => '&nbsp;&nbsp;b. Budidaya', 'subsektorAliases' => ['ikan budidaya', 'perikanan budidaya']],
        ['type' => 'metric', 'label' => '6. Provinsi Lampung', 'subsektorAliases' => ['gabungan']],
        ['type' => 'metric', 'label' => 'a. Indeks harga yang diterima petani', 'subsektorAliases' => ['gabungan'], 'aliases' => ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani']],
        ['type' => 'metric', 'label' => 'b. Indeks BPPBM', 'subsektorAliases' => ['gabungan'], 'aliases' => ['bppbm', 'indeks biaya produksi dan penambahan barang modal (bppbm)']]
    ];

    foreach ($brsTable4Config as $rowConfig) {
        if ($rowConfig['type'] === 'section') {
            $brsTable4Rows[] = [
                'type' => 'section',
                'label' => $rowConfig['label'],
                'currentNtup' => null,
                'prevNtup' => null,
                'changeNtupPct' => null
            ];
            continue;
        }

        $metricAliases = $rowConfig['aliases'] ?? ['nilai tukar usaha pertanian'];
        $prevNtup = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $prevYearInt, $prevMonthIndex, $metricAliases);
        $currentNtup = $findSubsectorMetricValueByAliases($rowConfig['subsektorAliases'], $selectedYearInt, $selectedMonthIndex, $metricAliases);
        $changeNtupPct = ($currentNtup !== null && $prevNtup !== null && $prevNtup != 0)
            ? (($currentNtup - $prevNtup) / $prevNtup) * 100
            : null;

        $brsTable4Rows[] = [
            'type' => 'metric',
            'label' => $rowConfig['label'],
            'currentNtup' => $currentNtup,
            'prevNtup' => $prevNtup,
            'changeNtupPct' => $changeNtupPct
        ];
    }
    if (in_array($currentPage, ['generate-brs', 'generate-brs-text'], true)) {
        $brsNarrativePath = __DIR__ . '/brs_februari_2026_pages2.txt';
        if (is_file($brsNarrativePath)) {
            $rawNarrative = file_get_contents($brsNarrativePath);
            if ($rawNarrative !== false) {
                $brsNarrativeText = str_replace(["\xC2\x84", "\x84"], "•", $rawNarrative);
                $cutPos = mb_strpos($brsNarrativeText, '=== Halaman 2 ===');
                if ($cutPos !== false) {
                    $brsNarrativeText = trim(mb_substr($brsNarrativeText, 0, $cutPos));
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NTP Lampung</title>
    <link rel="icon" type="image/png" href="img/—Pngtree—green rice tree_6297044.png">
    <link rel="shortcut icon" type="image/png" href="img/—Pngtree—green rice tree_6297044.png">
    <link rel="apple-touch-icon" href="img/—Pngtree—green rice tree_6297044.png">

    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/templatemo-crypto-style.css">
    <?php if ($currentPage === 'dashboard') { ?>
        <link rel="stylesheet" href="css/templatemo-crypto-dashboard.css">
    <?php } else { ?>
        <link rel="stylesheet" href="css/templatemo-crypto-pages.css">
    <?php } ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Theme override: neon dark dashboard inspired */
        html[data-theme="dark"] {
            --bg-primary: #0b0f10;
            --bg-secondary: #111619;
            --text-primary: #e6f5ea;
            --text-secondary: #9fb2a5;
            --text-muted: #6f8176;
            --border: rgba(255, 255, 255, 0.08);
            --accent: #b7f35b;
            --accent-strong: #88ff6a;
            --accent-gradient: linear-gradient(135deg, #b7f35b 0%, #6dff85 100%);
        }
        html[data-theme="dark"] body {
            background: radial-gradient(circle at 10% 0%, rgba(0, 255, 140, 0.15), transparent 35%),
                        radial-gradient(circle at 90% 10%, rgba(0, 200, 120, 0.12), transparent 35%),
                        #0b0f10;
        }
        html[data-theme="dark"] .dashboard {
            background: transparent;
        }
        html[data-theme="dark"] .sidebar {
            background: linear-gradient(180deg, #0d1113 0%, #0a0e10 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.06);
        }
        html[data-theme="dark"] .nav-item {
            color: #a8b7ad;
        }
        html[data-theme="dark"] .nav-item:hover {
            background: rgba(183, 243, 91, 0.12);
            color: #eaffd4;
        }
        html[data-theme="dark"] .nav-item.active {
            background: #b7f35b;
            color: #0b0f10;
            box-shadow: 0 8px 20px rgba(183, 243, 91, 0.28);
        }
        html[data-theme="dark"] .card,
        html[data-theme="dark"] .stat-card {
            background: #141a1d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 16px 28px rgba(0, 0, 0, 0.35);
            border-radius: 18px;
        }
        html[data-theme="dark"] .card-header .card-title,
        html[data-theme="dark"] .header h1,
        html[data-theme="dark"] h1, 
        html[data-theme="dark"] h2 {
            color: #e6f5ea;
        }
        html[data-theme="dark"] .btn.primary {
            background: var(--accent-gradient);
            color: #0b0f10;
            box-shadow: 0 12px 22px rgba(183, 243, 91, 0.25);
        }
        html[data-theme="dark"] .chart-download-btn,
        html[data-theme="dark"] .chart-toggle-btn {
            background: #0f1416;
            border-color: rgba(255,255,255,0.08);
        }
        html[data-theme="dark"] .chart-toggle-btn.active {
            background: rgba(183, 243, 91, 0.2);
            border-color: rgba(183, 243, 91, 0.6);
            color: #eaffd4;
        }
        html[data-theme="dark"] .logo-icon {
            background: #162018;
            box-shadow: 0 8px 20px rgba(183, 243, 91, 0.18);
        }
        html[data-theme="dark"] .active-period {
            background: rgba(183, 243, 91, 0.12);
            border-color: rgba(183, 243, 91, 0.35);
            color: #dfffc1;
        }
        html[data-theme="dark"] .summary-change.positive,
        html[data-theme="dark"] .chart-change-badge.positive {
            color: #b7f35b;
        }
        html[data-theme="dark"] .summary-change.negative,
        html[data-theme="dark"] .chart-change-badge.negative {
            color: #ff8b8b;
        }
        html[data-theme="dark"] .ntp-card .summary-value,
        html[data-theme="dark"] .ntup-card .summary-value,
        html[data-theme="dark"] .it-ib-card .summary-value {
            color: #eaffd4;
        }
        html[data-theme="dark"] .account-trigger {
            background: rgba(255, 255, 255, 0.06);
        }
        .global-filter-card {
            margin-bottom: 24px;
        }

        .global-filter-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .global-filter-controls label {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
        }

        .global-filter-controls select {
            min-width: 170px;
            padding: 0 16px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-family: inherit;
        }

        .global-filter-controls button,
        .brs-filter-controls button {
            padding: 0 16px;
            height: 40px;
            border: none;
            border-radius: 10px;
            background: var(--accent-gradient);
            color: #1c1c1e;
            font-weight: 700;
            cursor: pointer;
        }

        .active-period {
            display: inline-flex;
            align-items: center;
            margin-top: 10px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(217, 168, 109, 0.14);
            color: var(--text-primary);
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
        }

        .ntp-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
            margin-bottom: 14px;
            align-items: center;
        }

        .ntp-controls label {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
        }

        .ntp-controls select {
            min-width: 180px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-family: inherit;
        }

        .chart-wrap {
            height: 260px;
        }

        .chart-wrap canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .chart-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            width: 100%;
        }

        .chart-title-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            min-width: 0;
        }

        .chart-change-badges {
            display: inline-flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 6px;
        }

        .chart-change-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(184, 115, 51, 0.14);
            color: var(--text-primary);
            font-size: 11px;
            font-weight: 700;
            padding: 5px 10px;
            line-height: 1;
            white-space: nowrap;
        }

        .chart-change-badge.positive {
            color: #6b8e6b;
            border-color: rgba(107, 142, 107, 0.45);
            background: rgba(107, 142, 107, 0.16);
        }

        .chart-change-badge.negative {
            color: #c27878;
            border-color: rgba(194, 120, 120, 0.45);
            background: rgba(194, 120, 120, 0.14);
        }

        .chart-change-badge.neutral {
            color: var(--text-muted);
        }

        .chart-download-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .chart-download-btn {
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            line-height: 1.1;
        }

        .chart-download-btn:hover {
            border-color: rgba(184, 115, 51, 0.55);
        }
        .chart-toggle-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .chart-toggle-btn {
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            line-height: 1.1;
            transition: all 0.2s ease;
        }
        .chart-toggle-btn:hover {
            border-color: rgba(184, 115, 51, 0.55);
        }
        .chart-toggle-btn.active {
            background: rgba(184, 115, 51, 0.2);
            border-color: rgba(184, 115, 51, 0.6);
            color: #ffe8cf;
        }

        .metric-panel {
            display: grid;
            grid-template-rows: repeat(3, minmax(0, 1fr));
            gap: 16px;
            height: 480px;
        }

        .metric-panel .stat-card {
            height: 100%;
            margin: 0;
            padding: 22px;
        }
        .it-ib-card .it-ib-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            margin-top: 10px;
        }
        .it-ib-card .it-ib-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
        }
        .it-ib-card .it-ib-value {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--text-primary);
        }
        .it-ib-card .it-ib-value,
        .it-ib-card .summary-change {
            min-width: 90px;
        }
        .it-ib-card .summary-change {
            text-align: right;
        }

        .metric-panel .stat-content {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .ntp-movers-card {
            min-height: 420px;
        }

        .ntp-movers-card .card-header {
            align-items: flex-start;
            flex-direction: column;
            gap: 10px;
        }
        .ikrt-rank-card {
            min-height: 360px;
        }
        .ikrt-rank-grid {
            display: grid;
            gap: 16px;
            margin-top: 8px;
        }
        .ikrt-rank-title {
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: rgba(226, 232, 240, 0.7);
            margin-bottom: 8px;
        }
        .ikrt-rank-list {
            display: grid;
            gap: 8px;
        }
        .ikrt-rank-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            background: linear-gradient(120deg, rgba(148, 163, 184, 0.12), rgba(30, 41, 59, 0.2));
            font-size: 0.85rem;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
        }
        .ikrt-rank-item .rank-num {
            font-weight: 800;
            color: rgba(248, 250, 252, 0.9);
            width: 30px;
            height: 30px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(184, 115, 51, 0.35);
        }
        .ikrt-rank-item .rank-value {
            font-weight: 800;
            color: #ffe8cf;
        }
        .ikrt-rank-lampung {
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(184, 115, 51, 0.45);
            background: linear-gradient(135deg, rgba(184, 115, 51, 0.2), rgba(107, 142, 107, 0.12));
            font-weight: 800;
            color: #fff7ed;
        }

        .ntp-movers-card .movers-list {
            max-height: none;
            overflow: visible;
        }

        .movers-empty {
            color: var(--text-muted);
            font-size: 13px;
            padding: 8px 0;
        }

        .ntp-card {
            background: linear-gradient(135deg, #f2d8bf 0%, #e8bf97 100%);
            border: 1px solid rgba(184, 115, 51, 0.28);
        }

        .ntp-card .summary-title,
        .ntp-card .summary-value,
        .ntp-card .summary-sub {
            color: #2b2016;
        }

        .ntp-card .summary-period {
            background: rgba(255, 255, 255, 0.58);
            border: 1px solid rgba(43, 32, 22, 0.18);
            color: #2b2016;
        }

        .metric-panel .summary-value {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.02;
            margin-bottom: 0;
        }

        .metric-value-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-top: 6px;
            margin-bottom: 0;
            width: 100%;
            flex: 1;
        }

        .metric-icon {
            font-size: 34px;
            line-height: 1;
        }

        .metric-value-row .summary-value {
            margin-bottom: 0;
            min-width: 0;
        }

        #ntpMetricValue {
            text-align: left;
        }

        #ntupMetricValue {
            text-align: left;
        }

        .table-card {
            margin-top: 24px;
        }
        .andil-table-card {
            grid-column: span 2;
            margin-top: 12px;
        }
        .andil-table-wrap {
            overflow: auto;
        }
        .andil-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .andil-table thead th {
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-primary);
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .andil-table th,
        .andil-table td {
            padding: 9px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 12px;
            color: var(--text-secondary);
            text-align: left;
        }
        .andil-table tbody tr:nth-child(even) td {
            background: rgba(255, 255, 255, 0.02);
        }
        .andil-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.05);
        }
        .andil-table th:last-child,
        .andil-table td:last-child {
            text-align: right;
            white-space: nowrap;
        }
        .andil-table .it-col,
        .andil-table .it-change-col {
            text-align: right;
            white-space: nowrap;
        }
        .andil-change-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .andil-change-badge.positive {
            color: #36d399;
            border-color: rgba(54, 211, 153, 0.45);
            background: rgba(54, 211, 153, 0.12);
        }
        .andil-change-badge.negative {
            color: #f87171;
            border-color: rgba(248, 113, 113, 0.45);
            background: rgba(248, 113, 113, 0.12);
        }
        .andil-change-badge.neutral {
            color: var(--text-muted);
            border-color: var(--border);
            background: rgba(255, 255, 255, 0.05);
        }

        .dataTables_wrapper {
            color: var(--text-secondary);
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 8px;
        }

        table.dataTable {
            border-collapse: collapse !important;
        }

        table.dataTable thead th,
        table.dataTable thead td,
        table.dataTable tbody th,
        table.dataTable tbody td {
            border-bottom: 1px solid var(--border) !important;
            color: var(--text-primary);
            background: transparent;
        }

        .summary-title {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }

        .summary-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }

        .summary-period {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            background: rgba(184, 115, 51, 0.18);
            border: 1px solid rgba(184, 115, 51, 0.35);
            border-radius: 999px;
            padding: 6px 12px;
            letter-spacing: 0.2px;
            white-space: normal;
            line-height: 1.3;
        }

        .summary-value {
            font-size: 26px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .summary-sub {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .summary-change {
            margin-top: 0;
            font-weight: 600;
            text-align: right;
            line-height: 1.2;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: flex-end;
            gap: 3px;
            flex: 0 0 auto;
        }

        .summary-change .change-value {
            display: block;
            font-size: 18px;
            font-weight: 700;
        }

        .summary-change .change-compare {
            display: block;
            font-size: 9px;
            opacity: 0.62;
            font-weight: 500;
        }

        .summary-change .change-note {
            display: block;
            font-size: 9px;
            opacity: 0.62;
            font-weight: 500;
        }

        .summary-change.positive {
            color: #6b8e6b;
        }

        .summary-change.negative {
            color: #c27878;
        }

        .summary-change.neutral {
            color: var(--text-muted);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .page-header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .upload-page {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 24px;
        }
        .upload-page.has-preview {
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
        }
        @media (max-width: 1100px) {
            .upload-page,
            .upload-page.has-preview {
                grid-template-columns: 1fr;
            }
        }

        .brs-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            width: 100%;
            max-width: 1080px;
            margin: 0 auto;
        }

        .brs-card {
            height: 420px;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .brs-card .card-title {
            font-size: 16px;
            line-height: 1.35;
        }

        .brs-card .card-description {
            font-size: 11px;
            line-height: 1.35;
        }

        .brs-card-full {
            grid-column: 1 / -1;
        }

        .brs-filter-card {
            margin-bottom: 20px;
            width: 100%;
            max-width: 1080px;
            margin-left: auto;
            margin-right: auto;
        }

        .brs-filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .brs-filter-controls label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .brs-filter-controls select {
            min-width: 170px;
            padding: 0 16px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-family: inherit;
        }

        .brs-table-wrap {
            overflow: auto;
            flex: 1;
            min-height: 0;
        }

        .brs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .brs-table th,
        .brs-table td {
            padding: 6px 8px;
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            text-align: left;
            font-size: 11px;
            line-height: 1.25;
            white-space: nowrap;
        }
        #brsTable1 th:not(:first-child),
        #brsTable1 td:not(:first-child),
        #brsTable2 th:not(:first-child),
        #brsTable2 td:not(:first-child),
        #brsTable3 th:not(:first-child),
        #brsTable3 td:not(:first-child),
        #brsTable4 th:not(:first-child),
        #brsTable4 td:not(:first-child) {
            text-align: right;
        }
        #brsTable2 th {
            white-space: normal;
            word-break: break-word;
        }

        .brs-placeholder {
            color: var(--text-muted);
            font-size: 12px;
        }

        .brs-highlight td {
            background: #fff17f;
            font-weight: 700;
            color: #3f3a00;
        }

        .brs-generate-wrap {
            margin-top: 26px;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .brs-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(8, 10, 14, 0.6);
            z-index: 1300;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }
        .brs-modal-backdrop.open {
            opacity: 1;
            pointer-events: auto;
        }
        .brs-modal {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            width: 420px;
            max-width: calc(100vw - 48px);
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 24px 50px rgba(0, 0, 0, 0.35);
            z-index: 1301;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .brs-modal.open {
            opacity: 1;
            pointer-events: auto;
            transform: translate(-50%, -50%) scale(1);
        }
        .brs-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }
        .brs-modal-header h3 {
            font-size: 15px;
            margin: 0;
            color: var(--text-primary);
        }
        .brs-modal-close {
            background: transparent;
            border: none;
            font-size: 18px;
            color: var(--text-muted);
            cursor: pointer;
        }
        .brs-modal-body {
            padding: 16px;
            display: grid;
            gap: 12px;
        }
        .brs-modal-body label {
            font-size: 12px;
            color: var(--text-primary);
            font-weight: 600;
            margin-bottom: 6px;
            display: block;
        }
        .brs-modal-body input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 10px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 13px;
        }
        .brs-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 12px 16px 16px;
        }
        .reason-roster-card {
            margin-top: 18px;
        }
        .reason-roster-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .reason-item {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            background: var(--bg-secondary);
        }
        .reason-item label {
            display: block;
            font-size: 12px;
            color: var(--text-primary);
            margin-bottom: 6px;
            font-weight: 600;
        }
        .reason-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }
        .reason-head label {
            margin-bottom: 0;
            padding-top: 4px;
        }
        .reason-item textarea {
            width: 100%;
            min-height: 78px;
            max-height: 120px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 12px;
            line-height: 1.35;
        }
        .reason-item .btn {
            margin-top: 8px;
        }
        .reason-submit-wrap {
            margin-top: 12px;
            display: flex;
            justify-content: center;
        }
        .reason-submit-wrap .reason-submit-btn {
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            background: var(--accent-gradient);
            color: #1c1c1e;
            font-weight: 700;
            font-size: 13px;
            line-height: 1;
            cursor: pointer;
            display: inline-block;
            width: auto;
        }
        .reason-impact {
            margin: 0;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
            max-width: 72%;
        }
        .reason-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid transparent;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            font-size: 11px;
            line-height: 1.2;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.16);
        }
        .reason-badge .reason-badge-value {
            font-weight: 700;
            margin-left: 4px;
        }
        .reason-badge.badge-commodity {
            background: linear-gradient(135deg, rgba(80, 120, 210, 0.24), rgba(95, 145, 240, 0.12));
            border-color: rgba(120, 160, 245, 0.45);
            color: #dbe9ff;
        }
        .reason-badge.badge-andil {
            background: linear-gradient(135deg, rgba(198, 140, 70, 0.28), rgba(217, 168, 109, 0.14));
            border-color: rgba(217, 168, 109, 0.5);
            color: #ffe5c7;
        }
        .reason-badge.badge-it-positive {
            background: linear-gradient(135deg, rgba(44, 170, 109, 0.28), rgba(54, 211, 153, 0.14));
            border-color: rgba(54, 211, 153, 0.5);
            color: #d8ffef;
        }
        .reason-badge.badge-it-negative {
            background: linear-gradient(135deg, rgba(181, 72, 72, 0.3), rgba(248, 113, 113, 0.16));
            border-color: rgba(248, 113, 113, 0.52);
            color: #ffe1e1;
        }
        .reason-badge.badge-it-neutral {
            background: linear-gradient(135deg, rgba(148, 163, 184, 0.24), rgba(100, 116, 139, 0.12));
            border-color: rgba(148, 163, 184, 0.45);
            color: #e6edf8;
        }
        .ai-chat-card {
            max-width: 980px;
            margin: 0 auto;
        }
        .ai-chat-toggle {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 1200;
            background: transparent;
            color: inherit;
            border: none;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ai-chat-toggle img {
            width: 96px;
            height: 96px;
            display: block;
            transform: none;
            transition: transform 0.15s ease;
        }
        .ai-chat-toggle:active img {
            transform: scale(0.92);
        }
        .ai-chat-panel {
            position: fixed;
            right: 140px;
            bottom: 24px;
            width: 360px;
            max-width: calc(100vw - 48px);
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            z-index: 1201;
            opacity: 0;
            transform: translateY(16px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .ai-chat-panel.open {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .ui-toast {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            max-width: 420px;
            width: min(420px, calc(100vw - 48px));
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            background: rgba(12, 18, 28, 0.55);
            color: #f8fafc;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1300;
            font-size: 0.92rem;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        .ui-toast.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
            pointer-events: auto;
        }
        .ui-toast.success {
            border-color: rgba(34, 197, 94, 0.55);
            background: rgba(16, 185, 129, 0.22);
            color: #eafff4;
        }
        .ui-toast.error {
            border-color: rgba(248, 113, 113, 0.6);
            background: rgba(239, 68, 68, 0.2);
            color: #fff1f2;
        }
        .ai-chat-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }
        .ai-chat-panel-title {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .ai-chat-panel-title strong {
            font-size: 14px;
            color: var(--text-primary);
        }
        .ai-chat-panel-title span {
            font-size: 11px;
            color: var(--text-muted);
        }
        .ai-chat-panel-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 18px;
            cursor: pointer;
            padding: 4px;
        }
        .ai-chat-panel-body {
            padding: 12px 14px 14px;
        }
        .ai-chat-panel .ai-chat-box {
            min-height: 240px;
            max-height: 320px;
        }
        .ai-chat-box {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--bg-secondary);
            min-height: 420px;
            max-height: 62vh;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .ai-msg {
            max-width: 82%;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.45;
            white-space: pre-wrap;
        }
        .ai-msg.user {
            align-self: flex-end;
            background: rgba(217, 168, 109, 0.18);
            border: 1px solid rgba(217, 168, 109, 0.35);
            color: var(--text-primary);
        }
        .ai-msg.bot {
            align-self: flex-start;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            color: var(--text-primary);
        }
        .ai-chat-controls {
            margin-top: 10px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: end;
        }
        .ai-chat-controls textarea {
            width: 100%;
            min-height: 64px;
            max-height: 160px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 13px;
            line-height: 1.4;
        }
        .ai-chat-actions {
            display: flex;
            gap: 8px;
        }
        .ai-chat-actions .btn {
            padding: 8px 14px;
            font-size: 12px;
        }
        html[data-theme="light"] .reason-badge {
            box-shadow: none;
            background: #f6f7fb;
            border-color: #d6d9e2;
            color: #1f2937;
        }
        html[data-theme="light"] .reason-badge.badge-commodity {
            background: #eaf2ff;
            border-color: #8fb3ff;
            color: #1f3a8a;
        }
        html[data-theme="light"] .reason-badge.badge-andil {
            background: #fff5e8;
            border-color: #f3bf7b;
            color: #8a4b00;
        }
        html[data-theme="light"] .reason-badge.badge-it-positive {
            background: #e9f9ef;
            border-color: #76d9a2;
            color: #146c43;
        }
        html[data-theme="light"] .reason-badge.badge-it-negative {
            background: #ffecec;
            border-color: #f3a3a3;
            color: #9f1239;
        }
        html[data-theme="light"] .reason-badge.badge-it-neutral {
            background: #edf2f7;
            border-color: #b8c1cf;
            color: #334155;
        }

        .brs-text-page {
            max-width: 1080px;
            margin: 0 auto;
        }
        .brs-text-page.brs-inline-naskah {
            margin-top: 24px;
        }

        .brs-text-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .brs-text-header-main {
            min-width: 0;
        }

        .brs-text-content {
            white-space: pre-wrap;
            font-size: 12px;
            line-height: 1.5;
            color: var(--text-secondary);
            max-height: 72vh;
            overflow: auto;
            margin: 0;
        }

        .brs-dynamic-summary {
            margin: 0 0 14px 0;
            font-size: 13px;
            line-height: 1.6;
            color: var(--text-primary);
            font-weight: 400;
            border-left: 3px solid transparent;
            padding-left: 10px;
        }
        .brs-dynamic-summary.dynamic-ntp {
            color: #dbeafe;
            border-left-color: #60a5fa;
        }
        .brs-dynamic-summary.dynamic-it {
            color: #dcfce7;
            border-left-color: #34d399;
        }
        .brs-dynamic-summary.dynamic-ib {
            color: #ffedd5;
            border-left-color: #fb923c;
        }
        .brs-dynamic-summary.dynamic-ntpp {
            color: #e0f7fa;
            border-left-color: #06b6d4;
        }
        .brs-dynamic-summary.dynamic-ntph {
            color: #ecfccb;
            border-left-color: #84cc16;
        }
        .brs-dynamic-summary.dynamic-ntpr {
            color: #fce7f3;
            border-left-color: #ec4899;
        }
        .brs-dynamic-summary.dynamic-ntpt {
            color: #e0e7ff;
            border-left-color: #6366f1;
        }
        .brs-dynamic-summary.dynamic-ntn {
            color: #dcfce7;
            border-left-color: #22c55e;
        }
        .brs-dynamic-summary.dynamic-ntpi {
            color: #ede9fe;
            border-left-color: #8b5cf6;
        }
        .brs-dynamic-summary.dynamic-subsector {
            color: #f3e8ff;
            border-left-color: #c084fc;
        }
        .brs-dynamic-summary.dynamic-subsector-change {
            color: #fee2e2;
            border-left-color: #f87171;
        }
        .brs-dynamic-summary.dynamic-subsector-yoy {
            color: #e9d5ff;
            border-left-color: #a78bfa;
        }
        .brs-dynamic-summary.dynamic-ikrt {
            color: #fef9c3;
            border-left-color: #facc15;
        }
        .brs-dynamic-summary.dynamic-ntup {
            color: #e0f2fe;
            border-left-color: #22d3ee;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntp {
            color: #1e3a8a;
            border-left-color: #3b82f6;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-it {
            color: #166534;
            border-left-color: #22c55e;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ib {
            color: #9a3412;
            border-left-color: #f97316;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntpp {
            color: #155e75;
            border-left-color: #06b6d4;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntph {
            color: #3f6212;
            border-left-color: #65a30d;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntpr {
            color: #9d174d;
            border-left-color: #db2777;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntpt {
            color: #3730a3;
            border-left-color: #4f46e5;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntn {
            color: #166534;
            border-left-color: #16a34a;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntpi {
            color: #5b21b6;
            border-left-color: #7c3aed;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-subsector {
            color: #6b21a8;
            border-left-color: #a855f7;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-subsector-change {
            color: #991b1b;
            border-left-color: #ef4444;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-subsector-yoy {
            color: #5b21b6;
            border-left-color: #8b5cf6;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ikrt {
            color: #854d0e;
            border-left-color: #eab308;
        }
        html[data-theme="light"] .brs-dynamic-summary.dynamic-ntup {
            color: #0e7490;
            border-left-color: #06b6d4;
        }

        @media (max-width: 1100px) {
            .brs-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .brs-text-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .reason-roster-grid {
                grid-template-columns: 1fr;
            }
            .reason-head {
                flex-direction: column;
                align-items: stretch;
            }
            .reason-impact {
                justify-content: flex-start;
                max-width: 100%;
            }
        }

        .upload-card {
            width: 100%;
            max-width: none;
            position: relative;
            overflow: hidden;
        }

        .upload-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 0%, rgba(249, 219, 155, 0.16), transparent 55%),
                        radial-gradient(circle at 100% 0%, rgba(105, 185, 255, 0.12), transparent 50%);
            pointer-events: none;
        }

        body[data-page="dashboard"] .card,
        body[data-page="dashboard"] .stat-card {
            position: relative;
            overflow: hidden;
        }

        body[data-page="dashboard"] .card::before,
        body[data-page="dashboard"] .stat-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 0%, rgba(249, 219, 155, 0.16), transparent 55%),
                        radial-gradient(circle at 100% 0%, rgba(105, 185, 255, 0.12), transparent 50%);
            pointer-events: none;
        }
        body[data-page="dashboard"] .card > *,
        body[data-page="dashboard"] .stat-card > * {
            position: relative;
        }

        .login-wrap {
            min-height: calc(100vh - 220px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0 40px;
        }
        .login-card {
            max-width: 520px;
            width: 100%;
            padding: 26px 26px 22px;
        }
        .login-card .card-header {
            text-align: center;
            margin-bottom: 18px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .login-card .card-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.3px;
            margin: 0;
        }
        .login-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0 auto;
            max-width: 320px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        .login-actions {
            display: flex;
            justify-content: center;
            margin-top: 12px;
        }
        .account-menu {
            position: relative;
        }
        .account-trigger {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 6px 12px 6px 6px;
            color: var(--text-primary);
            cursor: pointer;
            font-family: inherit;
        }
        .account-avatar {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            background: rgba(184, 115, 51, 0.35);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff7ed;
            font-size: 12px;
        }
        .account-name {
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .account-caret {
            font-size: 12px;
            color: var(--text-muted);
        }
        .account-dropdown {
            position: absolute;
            right: 0;
            top: 110%;
            min-width: 180px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 18px 40px rgba(0,0,0,0.25);
            padding: 8px;
            opacity: 0;
            transform: translateY(6px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 20;
        }
        .account-dropdown.open {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .account-dropdown a {
            display: block;
            padding: 8px 10px;
            border-radius: 8px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        .account-dropdown a:hover {
            background: rgba(184, 115, 51, 0.18);
        }

        .upload-card .card-header {
            position: relative;
        }

        .upload-card form {
            position: relative;
        }

        .upload-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .upload-form-grid .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .upload-form-grid {
            row-gap: 16px;
        }
        @media (max-width: 860px) {
            .upload-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .file-input {
            border: 1px dashed var(--border);
            border-radius: 12px;
            padding: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 13px;
        }

        .file-input::file-selector-button {
            margin-right: 12px;
            border: none;
            border-radius: 10px;
            padding: 8px 12px;
            background: var(--accent-gradient);
            color: #1c1c1e;
            font-weight: 700;
            cursor: pointer;
        }

        .file-help {
            font-size: 11px;
            color: var(--text-muted);
        }

        .upload-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 14px;
        }
        .template-download {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            text-decoration: none;
            margin-left: auto;
        }
        .template-download svg {
            width: 14px;
            height: 14px;
        }
        .form-label {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn.subtle {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--border);
            color: var(--text-primary);
        }
        html[data-theme="light"] .btn.subtle {
            background: #f1f5f9;
        }

        .upload-preview-card {
            margin-top: 20px;
        }

        .upload-note {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 16px;
            line-height: 1.5;
        }

        .upload-card .btn-group {
            margin-top: 4px;
        }

        .import-message {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
        }

        .import-message.success {
            background: rgba(38, 161, 123, 0.18);
            border: 1px solid rgba(38, 161, 123, 0.35);
            color: #86efac;
        }

        .import-message.error {
            background: rgba(194, 120, 120, 0.18);
            border: 1px solid rgba(194, 120, 120, 0.35);
            color: #fca5a5;
        }

        .import-message.warning {
            background: rgba(239, 68, 68, 0.18);
            border: 1px solid rgba(239, 68, 68, 0.45);
            color: #fecaca;
        }

        .logo-icon {
            background: #f2e2c8;
            box-shadow: 0 6px 16px rgba(233, 210, 165, 0.6);
            padding: 6px;
            border-radius: 12px;
        }
        .logo-icon img {
            width: 34px;
            height: 34px;
            display: block;
        }
    </style>
</head>
<body data-page="<?= htmlspecialchars($currentPage); ?>">
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
        <div class="hamburger">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard">
        <aside class="sidebar" id="sidebar">
            <div class="logo">
                <div class="logo-icon" aria-hidden="true">
                    <img src="img/—Pngtree—green rice tree_6297044.png" alt="Logo NTP Lampung">
                </div>
                <span class="logo-text">NTP Lampung</span>
            </div>

            <nav class="nav-section">
                <div class="nav-label">Main Menu</div>
                <a href="?page=dashboard" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                    Dashboard NTP
                </a>
                <a href="?page=update-data" class="nav-item <?= $currentPage === 'update-data' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Update Data
                </a>
                <a href="?page=generate-brs" class="nav-item <?= in_array($currentPage, ['generate-brs', 'generate-brs-text'], true) ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="16" rx="2"/>
                        <line x1="7" y1="8" x2="17" y2="8"/>
                        <line x1="7" y1="12" x2="17" y2="12"/>
                        <line x1="7" y1="16" x2="13" y2="16"/>
                    </svg>
                    Generate BRS
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="theme-toggle">
                    <div class="theme-toggle-label">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"/>
                            <line x1="12" y1="1" x2="12" y2="3"/>
                            <line x1="12" y1="21" x2="12" y2="23"/>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                            <line x1="1" y1="12" x2="3" y2="12"/>
                            <line x1="21" y1="12" x2="23" y2="12"/>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                        </svg>
                        Light Mode
                    </div>
                    <div class="theme-switch" id="themeSwitch"></div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($currentPage === 'dashboard') { ?>
                <div class="header">
                    <div class="header-left">
                        <h1>Dashboard NTP Subsektor</h1>
                        <p class="header-subtitle">Visualisasi interaktif Nilai Tukar Petani Provinsi Lampung (otomatis untuk prov 18).</p>
                    </div>
                    <div class="header-right">
                        <?= $accountMenuHtml !== '' ? $accountMenuHtml : '<div class="user-menu"><div class="user-avatar">NTP</div><div class="user-info"><span class="user-name">BPS Lampung</span><span class="user-role">Dashboard Data</span></div></div>'; ?>
                    </div>
                </div>

                <div class="card global-filter-card">
                    <div class="card-header">
                        <h2 class="card-title">Filter Periode Global</h2>
                    </div>
                    <div class="global-filter-controls">
                        <label for="globalBulanFilter">Bulan</label>
                        <select id="globalBulanFilter"></select>

                        <label for="globalTahunFilter">Tahun</label>
                        <select id="globalTahunFilter"></select>

                        <label for="globalSubsektorFilter">Subsektor</label>
                        <select id="globalSubsektorFilter"></select>

                        <button type="button" id="applyGlobalFilter">Terapkan Filter</button>
                    </div>
                    <div class="active-period" id="activePeriodLabel">Periode aktif: Semua Bulan Semua Tahun | Subsektor: Semua Subsektor</div>
                </div>

                <div class="content-wrapper">
                    <div class="content-left">
                        <div class="card chart-card" style="grid-column: span 2;">
                            <div class="card-header">
                                <div class="chart-header-row">
                                    <div class="chart-title-wrap">
                                        <h2 class="card-title" id="ntpChartTitle">Rincian Nilai Tukar Petani</h2>
                                        <div class="chart-change-badges">
                                            <span class="chart-change-badge neutral" id="ntpMtmBadge">MoM -</span>
                                            <span class="chart-change-badge neutral" id="ntpYoyBadge">YoY -</span>
                                        </div>
                                    </div>
                                    <div class="chart-download-actions">
                                        <button type="button" class="chart-download-btn" id="downloadNtpPng">PNG</button>
                                        <button type="button" class="chart-download-btn" id="downloadNtpJpg">JPG</button>
                                    </div>
                                </div>
                            </div>
                            <div class="chart-wrap">
                                <canvas id="ntpChart"></canvas>
                            </div>
                        </div>

                        <div class="card chart-card" style="grid-column: span 2;">
                            <div class="card-header">
                                <div class="chart-header-row">
                                    <div class="chart-title-wrap">
                                        <h2 class="card-title" id="ntupChartTitle">Nilai Tukar Usaha Pertanian</h2>
                                        <div class="chart-change-badges">
                                            <span class="chart-change-badge neutral" id="ntupMtmBadge">MoM -</span>
                                            <span class="chart-change-badge neutral" id="ntupYoyBadge">YoY -</span>
                                        </div>
                                    </div>
                                    <div class="chart-download-actions">
                                        <button type="button" class="chart-download-btn" id="downloadNtupPng">PNG</button>
                                        <button type="button" class="chart-download-btn" id="downloadNtupJpg">JPG</button>
                                    </div>
                                </div>
                            </div>
                            <div class="chart-wrap">
                                <canvas id="ntupChart"></canvas>
                            </div>
                        </div>

                        <div class="card chart-card" style="grid-column: span 2;">
                            <div class="card-header">
                                <div class="chart-header-row">
                                    <div class="chart-title-wrap">
                                        <h2 class="card-title" id="itIbChartTitle">Indeks Harga It vs Ib (Provinsi Lampung)</h2>
                                        <div class="chart-toggle-group" id="itIbToggleGroup">
                                            <button type="button" class="chart-toggle-btn active" data-series="both">It + Ib</button>
                                            <button type="button" class="chart-toggle-btn" data-series="it">It</button>
                                            <button type="button" class="chart-toggle-btn" data-series="ib">Ib</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="chart-wrap">
                                <canvas id="itIbChart"></canvas>
                            </div>
                        </div>

                        <div class="card andil-table-card">
                            <div class="card-header">
                                <h2 class="card-title">Komoditi Andil Utama per Subsektor</h2>
                            </div>
                            <div class="andil-table-wrap">
                                <table class="andil-table">
                                    <thead>
                                        <tr>
                                            <th>Subsektor</th>
                                            <th>Komoditi</th>
                                            <th class="it-col">It</th>
                                            <th class="it-change-col">Perubahan It</th>
                                            <th>Andil</th>
                                        </tr>
                                    </thead>
                                    <tbody id="andilSubsektorTableBody">
                                        <tr><td colspan="5" class="movers-empty">Data belum tersedia.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="content-right">
                        <div class="metric-panel">
                            <div class="stat-card ntp-card">
                                <div class="stat-content">
                                    <div class="summary-title-row">
                                        <div class="summary-period" id="ntpMetricPeriod">-</div>
                                    </div>
                                    <div class="metric-value-row">
                                        <div class="summary-value" id="ntpMetricValue">0.00</div>
                                        <div class="summary-change neutral" id="ntpMetricChange">-</div>
                                    </div>
                                </div>
                            </div>

                            <div class="stat-card ntup-card">
                                <div class="stat-content">
                                    <div class="summary-title-row">
                                        <div class="summary-period" id="ntupMetricPeriod">-</div>
                                    </div>
                                    <div class="metric-value-row">
                                        <div class="summary-value" id="ntupMetricValue">0.00</div>
                                        <div class="summary-change neutral" id="ntupMetricChange">-</div>
                                    </div>
                                </div>
                            </div>

                            <div class="stat-card it-ib-card">
                                <div class="stat-content">
                                    <div class="summary-title-row">
                                        <div class="summary-period" id="itIbMetricPeriod">It vs Ib -</div>
                                    </div>
                                    <div class="it-ib-row">
                                        <div class="it-ib-label">It</div>
                                        <div class="it-ib-value" id="itMetricValue">0.00</div>
                                        <div class="summary-change neutral" id="itMetricChange">-</div>
                                    </div>
                                    <div class="it-ib-row">
                                        <div class="it-ib-label">Ib</div>
                                        <div class="it-ib-value" id="ibMetricValue">0.00</div>
                                        <div class="summary-change neutral" id="ibMetricChange">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card movers-card ntp-movers-card">
                            <div class="card-header">
                                <h2 class="card-title">Perubahan NTP Menurut Subsektor</h2>
                                <div class="summary-period" id="moversPeriod">-</div>
                            </div>
                            <div class="movers-list" id="moversList"></div>
                        </div>

                        <div class="card movers-card ikrt-rank-card">
                            <div class="card-header">
                                <h2 class="card-title">Ranking IKRT (Konsumsi Rumah Tangga)</h2>
                                <div class="summary-period" id="ikrtRankPeriod">-</div>
                            </div>
                            <div class="ikrt-rank-grid">
                                <div class="ikrt-rank-block">
                                    <div class="ikrt-rank-title">Top 3 Provinsi</div>
                                    <div class="ikrt-rank-list" id="ikrtTopList"></div>
                                </div>
                                <div class="ikrt-rank-block">
                                    <div class="ikrt-rank-title">Bottom 3 Provinsi</div>
                                    <div class="ikrt-rank-list" id="ikrtBottomList"></div>
                                </div>
                                <div class="ikrt-rank-block">
                                    <div class="ikrt-rank-title">Ranking Lampung</div>
                                    <div class="ikrt-rank-lampung" id="ikrtLampungRank">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } elseif ($currentPage === 'update-data') { ?>
                <div class="page-header">
                    <div>
                        <h1>Update Data NTP</h1>
                        <p>Upload file CSV untuk menambahkan data ke tabel NTP_Subsektor.</p>
                    </div>
                    <div class="page-header-right">
                        <?php if ($accountMenuHtml !== '') { echo $accountMenuHtml; } ?>
                        <button type="button" class="btn subtle" id="openDeleteByDate">Hapus Data</button>
                    </div>
                </div>

                <div class="upload-page <?= !empty($uploadedPreviewRows) ? 'has-preview' : ''; ?>">
                    <div class="card upload-card">
                        <div class="card-header">
                            <h2 class="card-title">Upload CSV</h2>
                        </div>

                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="import_csv">
                                    <div class="upload-form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="csv_file_ntp">
                                        Pilih File CSV (NTP_Subsektor)
                                        <a class="template-download" href="templates/ntp_subsektor_template.csv" download title="Unduh template NTP_Subsektor">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 3v12"></path>
                                                <path d="M7 10l5 5 5-5"></path>
                                                <path d="M5 21h14"></path>
                                            </svg>
                                        </a>
                                    </label>
                                    <input class="file-input" type="file" id="csv_file_ntp" name="csv_file_ntp" accept=".csv">
                                    <span class="file-help">Format berisi kolom prov, tahun, bulan, subsektor, rincian, nilai.</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="csv_file_andil">
                                        Pilih File CSV (Andil_NTP)
                                        <a class="template-download" href="templates/andil_ntp_template.csv" download title="Unduh template Andil_NTP">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 3v12"></path>
                                                <path d="M7 10l5 5 5-5"></path>
                                                <path d="M5 21h14"></path>
                                            </svg>
                                        </a>
                                    </label>
                                    <input class="file-input" type="file" id="csv_file_andil" name="csv_file_andil" accept=".csv">
                                    <span class="file-help">Format berisi kolom subsektor, prov, komoditi, rincian, tahun, bulan, andil.</span>
                                </div>
                                    </div>
                                    <div class="upload-actions">
                                        <button type="submit" class="btn primary">Upload dan Import</button>
                                    </div>
                                </form>

                        <?php if (!empty($importMessages)) { ?>
                            <div class="import-message <?= $importStatus === 'success' ? 'success' : 'error'; ?>">
                                <?php foreach ($importMessages as $msg) { ?>
                                    <div><?= htmlspecialchars($msg); ?></div>
                                <?php } ?>
                            </div>
                        <?php } elseif (!empty($importMessage)) { ?>
                            <div class="import-message <?= $importStatus === 'success' ? 'success' : 'error'; ?>">
                                <?= htmlspecialchars($importMessage); ?>
                            </div>
                        <?php } elseif (!empty($undoMessages)) { ?>
                            <div class="import-message <?= $undoStatus === 'success' ? 'success' : 'error'; ?>">
                                <?php foreach ($undoMessages as $msg) { ?>
                                    <div><?= htmlspecialchars($msg); ?></div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>

                    <?php if (!empty($deleteMessages)) { ?>
                        <div class="import-message warning">
                            <?php foreach ($deleteMessages as $msg) { ?>
                                <div><?= htmlspecialchars($msg); ?></div>
                            <?php } ?>
                        </div>
                    <?php } ?>

                    <?php if (!empty($uploadedPreviewRows)) { ?>
                        <div class="card upload-preview-card">
                            <div class="card-header">
                                <h2 class="card-title">Data Yang Baru Diupload</h2>
                                <p class="card-description">Menampilkan baris yang berhasil diinsert pada upload terakhir.</p>
                            </div>
                            <div class="brs-table-wrap">
                                <table class="brs-table">
                                    <thead>
                                        <tr>
                                            <th>Prov</th>
                                            <th>Tahun</th>
                                            <th>Bulan</th>
                                            <th>Subsektor</th>
                                            <th>Rincian</th>
                                            <th>Nilai</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($uploadedPreviewRows as $row) { ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['prov']); ?></td>
                                                <td><?= (int) $row['tahun']; ?></td>
                                                <td><?= htmlspecialchars($row['bulan']); ?></td>
                                                <td><?= htmlspecialchars($row['subsektor']); ?></td>
                                                <td><?= htmlspecialchars($row['rincian']); ?></td>
                                                <td><?= number_format((float) $row['nilai'], 2, ',', '.'); ?></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($uploadedPreviewTruncated) { ?>
                                <div class="upload-note">Preview dibatasi 500 baris pertama.</div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>

                <div id="deleteByDateBackdrop" class="brs-modal-backdrop" aria-hidden="true"></div>
                <div id="deleteByDateModal" class="brs-modal" role="dialog" aria-modal="true" aria-hidden="true">
                    <div class="brs-modal-header">
                        <h3>Hapus Data</h3>
                        <button type="button" class="brs-modal-close" id="deleteByDateCloseBtn">&times;</button>
                    </div>
                    <div class="brs-modal-body">
                        <div>
                            <label for="delete_date_modal">Pilih Tanggal Upload</label>
                            <input type="date" id="delete_date_modal">
                        </div>
                    </div>
                    <div class="brs-modal-actions">
                        <button type="button" class="btn" id="deleteByDateCancelBtn">Batal</button>
                        <button type="button" class="btn primary" id="deleteByDateSubmitBtn">Hapus</button>
                    </div>
                </div>
<?php } elseif ($currentPage === 'generate-brs') { ?>
                <div class="page-header">
                    <div>
                        <h1>Generate BRS</h1>
                        <p>Halaman ini menyiapkan tabel output BRS, draft naskah untuk BRS dan mengekspor BRS dalam format .xml</p>
                    </div>
                    <div class="page-header-right">
                        <?php if ($accountMenuHtml !== '') { echo $accountMenuHtml; } ?>
                    </div>
                </div>

                <div class="card brs-filter-card">
                    <div class="card-header">
                        <h2 class="card-title">Filter Periode BRS</h2>
                        <p class="card-description">Pilih bulan dan tahun sebelum generate tabel BRS.</p>
                    </div>
                    <div class="brs-filter-controls">
                        <label for="brsBulanFilter">Bulan</label>
                        <select id="brsBulanFilter" name="brs_bulan">
                            <?php foreach ($brsMonths as $month) { ?>
                                <option value="<?= (int) $month['index']; ?>" <?= $brsSelectedMonthIndex === (int) $month['index'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($monthNameByIndexPhp[(int) $month['index']] ?? $month['name']); ?>
                                </option>
                            <?php } ?>
                        </select>

                        <label for="brsTahunFilter">Tahun</label>
                        <select id="brsTahunFilter" name="brs_tahun">
                            <?php foreach ($brsYears as $year) { ?>
                                <option value="<?= (int) $year; ?>" <?= $brsSelectedYear === (string) $year ? 'selected' : ''; ?>><?= (int) $year; ?></option>
                            <?php } ?>
                        </select>
                        <button type="button" id="applyBrsFilter">Terapkan</button>
                    </div>
                </div>

                <div class="brs-grid">
                    <div class="card brs-card brs-card-full">
                        <div class="card-header">
                            <h2 class="card-title"><?= str_replace('(2018=100),', '(2018=100),<br>', htmlspecialchars($brsTable1Title)); ?></h2>
                            <p class="card-description">Nilai Tukar Petani dan komponen pembentuknya.</p>
                        </div>
                        <div class="brs-table-wrap">
                            <table class="brs-table" id="brsTable1">
                                <thead>
                                    <tr>
                                        <th>Tabel 1</th>
                                        <th class="brs-prev-col">Bulan sebelum</th>
                                        <th class="brs-current-col">Bulan ini</th>
                                        <th>Perubahan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($brsTable1TopRows as $metricRow) { ?>
                                        <tr>
                                            <td><?= htmlspecialchars($metricRow['label']); ?></td>
                                            <td><?= $formatIdNumber($metricRow['previous']); ?></td>
                                            <td><?= $formatIdNumber($metricRow['current']); ?></td>
                                            <td><?= $formatPctSigned($metricRow['change']); ?></td>
                                        </tr>
                                    <?php } ?>
                                    <?php foreach ($brsTable1DetailRows as $metricRow) { ?>
                                        <tr>
                                            <td><?= htmlspecialchars($metricRow['label']); ?></td>
                                            <?php if (($metricRow['type'] ?? 'metric') === 'section') { ?>
                                                <td></td><td></td><td></td>
                                            <?php } else { ?>
                                                <td><?= $formatIdNumber($metricRow['previous']); ?></td>
                                                <td><?= $formatIdNumber($metricRow['current']); ?></td>
                                                <td><?= $formatPctSigned($metricRow['change']); ?></td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card brs-card brs-card-full">
                        <div class="card-header">
                            <h2 class="card-title"><?= htmlspecialchars($brsTable2Title); ?></h2>
                            <p class="card-description">Andil inflasi per kelompok pengeluaran menurut provinsi.</p>
                        </div>
                        <div class="brs-table-wrap">
                            <table class="brs-table" id="brsTable2">
                                <thead>
                                    <tr>
                                        <th>Provinsi</th>
                                        <?php foreach ($brsTable2Columns as $column) { ?>
                                            <th><?= htmlspecialchars($column['header']); ?></th>
                                        <?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($brsTable2Rows as $row) { ?>
                                        <tr class="<?= $row['code'] === '18' ? 'brs-highlight' : ''; ?>">
                                            <td><?= htmlspecialchars($row['label']); ?></td>
                                            <?php foreach ($row['values'] as $val) { ?>
                                                <td><?= $formatPctSigned($val); ?></td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card brs-card">
                        <div class="card-header">
                            <h2 class="card-title"><?= htmlspecialchars($brsTable3Title); ?></h2>
                            <p class="card-description">Perbandingan subsektor NTP antar periode.</p>
                        </div>
                        <div class="brs-table-wrap">
                            <table class="brs-table" id="brsTable3">
                                <thead>
                                    <tr>
                                        <th rowspan="2">Subsektor</th>
                                        <th class="brs-yoy-prev-col" colspan="3">Bulan sebelum</th>
                                        <th class="brs-yoy-current-col" colspan="3">Bulan ini</th>
                                        <th rowspan="2">Perubahan NTP (%)</th>
                                    </tr>
                                    <tr>
                                        <th>It</th>
                                        <th>Ib</th>
                                        <th>NTP</th>
                                        <th>It</th>
                                        <th>Ib</th>
                                        <th>NTP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($brsTable3Rows as $row) { ?>
                                        <tr>
                                            <td><?= $row['label']; ?></td>
                                            <?php if (($row['type'] ?? 'metric') === 'section') { ?>
                                                <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                                            <?php } else { ?>
                                                <td><?= $formatIdNumber($row['prevIt']); ?></td>
                                                <td><?= $formatIdNumber($row['prevIb']); ?></td>
                                                <td><?= $formatIdNumber($row['prevNtp']); ?></td>
                                                <td><?= $formatIdNumber($row['currentIt']); ?></td>
                                                <td><?= $formatIdNumber($row['currentIb']); ?></td>
                                                <td><?= $formatIdNumber($row['currentNtp']); ?></td>
                                                <td><?= $formatPctSigned($row['changeNtpPct']); ?></td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card brs-card">
                        <div class="card-header">
                            <h2 class="card-title"><?= htmlspecialchars($brsTable4Title); ?></h2>
                            <p class="card-description">Perubahan indeks pendukung NTP.</p>
                        </div>
                        <div class="brs-table-wrap">
                            <table class="brs-table" id="brsTable4">
                                <thead>
                                    <tr>
                                        <th>Tabel 4</th>
                                        <th class="brs-prev-col">Bulan sebelum</th>
                                        <th class="brs-current-col">Bulan ini</th>
                                        <th>Perubahan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($brsTable4Rows as $row) { ?>
                                        <tr>
                                            <td><?= $row['label']; ?></td>
                                            <?php if (($row['type'] ?? 'metric') === 'section') { ?>
                                                <td></td><td></td><td></td>
                                            <?php } else { ?>
                                                <td><?= $formatIdNumber($row['prevNtup']); ?></td>
                                                <td><?= $formatIdNumber($row['currentNtup']); ?></td>
                                                <td><?= $formatPctSigned($row['changeNtupPct']); ?></td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card reason-roster-card">
                    <div class="card-header">
                        <h2 class="card-title">Roster Alasan Perubahan Subsektor</h2>
                        <p class="card-description">Isi alasan manual (maks. 200 karakter) untuk periode terpilih.</p>
                    </div>
                    <div class="card-body">
                        <?php if ($reasonMessage !== '') { ?>
                            <div class="import-message <?= $reasonStatus === 'success' ? 'success' : 'error'; ?>">
                                <?= htmlspecialchars($reasonMessage); ?>
                            </div>
                        <?php } ?>
                        <form method="post">
                            <input type="hidden" name="action" value="save_subsector_reasons_all">
                            <input type="hidden" name="brs_bulan" value="<?= (int) $brsSelectedMonthIndex; ?>">
                            <input type="hidden" name="brs_tahun" value="<?= (int) $brsSelectedYear; ?>">
                            <div class="reason-roster-grid">
                                <?php foreach ($subsectorReasonLabels as $reasonKey => $reasonLabel) { ?>
                                    <div class="reason-item">
                                        <div class="reason-head">
                                            <label for="reason_<?= htmlspecialchars($reasonKey); ?>"><?= htmlspecialchars($reasonLabel); ?></label>
                                            <div class="reason-impact">
                                                <span class="reason-badge badge-commodity">Komoditas utama:<span class="reason-badge-value"><?= htmlspecialchars($subsectorReasonImpact[$reasonKey]['komoditi'] ?? '-'); ?></span></span>
                                                <span class="reason-badge badge-andil">Andil:<span class="reason-badge-value"><?= isset($subsectorReasonImpact[$reasonKey]['andil']) ? $formatIdNumber($subsectorReasonImpact[$reasonKey]['andil']) : '-'; ?></span></span>
                                                <?php
                                                    $itVal = $subsectorReasonImpact[$reasonKey]['it_change'] ?? null;
                                                    $itClass = 'badge-it-neutral';
                                                    if ($itVal !== null) {
                                                        if ((float) $itVal > 0) {
                                                            $itClass = 'badge-it-positive';
                                                        } elseif ((float) $itVal < 0) {
                                                            $itClass = 'badge-it-negative';
                                                        }
                                                    }
                                                ?>
                                                <span class="reason-badge <?= $itClass; ?>">Perubahan It:<span class="reason-badge-value"><?php if ($itVal !== null) { ?><?= ((float) $itVal > 0 ? '+' : '') . $formatPctAbs($itVal); ?>%<?php } else { ?>-<?php } ?></span></span>
                                            </div>
                                        </div>
                                        <textarea id="reason_<?= htmlspecialchars($reasonKey); ?>" name="reason_<?= htmlspecialchars($reasonKey); ?>" maxlength="200" required placeholder="Tulis alasan perubahan subsektor (maks. 200 karakter)"><?= htmlspecialchars($subsectorReasonValues[$reasonKey] ?? ''); ?></textarea>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="reason-submit-wrap">
                                <button type="submit" class="reason-submit-btn">Submit Semua Alasan</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card brs-text-page brs-inline-naskah">
                    <div class="card-header">
                        <h2 class="card-title">Naskah BRS</h2>
                    </div>
                    <div class="card-body">
                        <p class="brs-dynamic-summary dynamic-ntp"><?= htmlspecialchars($brsDynamicNarrative); ?></p>
                        <?php if ($brsDynamicNtupHeadline !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntup"><?= htmlspecialchars($brsDynamicNtupHeadline); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicNtpItIbNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntp"><?= htmlspecialchars($brsDynamicNtpItIbNarrative); ?></p>
                        <?php } ?>
                        <p class="brs-dynamic-summary dynamic-subsector"><?= htmlspecialchars($brsDynamicSubsectorNarrative); ?></p>
                        <?php if ($brsDynamicIkrtDominantNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ikrt"><?= htmlspecialchars($brsDynamicIkrtDominantNarrative); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicNtupBriefNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntup"><?= htmlspecialchars($brsDynamicNtupBriefNarrative); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicNtpDetailNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntp"><?= htmlspecialchars($brsDynamicNtpDetailNarrative); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicSubsectorMoMDetailNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-subsector"><?= htmlspecialchars($brsDynamicSubsectorMoMDetailNarrative); ?></p>
                        <?php } ?>
                        <p class="brs-dynamic-summary dynamic-it"><?= htmlspecialchars($brsDynamicItNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ib"><?= htmlspecialchars($brsDynamicIbNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpp"><?= htmlspecialchars($brsDynamicNtppNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntph"><?= htmlspecialchars($brsDynamicNtphNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpr"><?= htmlspecialchars($brsDynamicNtprNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpt"><?= htmlspecialchars($brsDynamicNtptNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntn"><?= htmlspecialchars($brsDynamicNtnNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpi"><?= htmlspecialchars($brsDynamicNtpiNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-subsector-yoy"><?= htmlspecialchars($brsDynamicSubsectorYoyNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-subsector-change"><?= htmlspecialchars($brsDynamicSubsectorChangeNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ikrt"><?= htmlspecialchars($brsDynamicIkrtNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntup"><?= htmlspecialchars($brsDynamicNtupNarrative); ?></p>
                        <?php if ($brsNarrativeText !== '') { ?>
                            <pre class="brs-text-content"><?= htmlspecialchars($brsNarrativeText); ?></pre>
                        <?php } ?>
                    </div>
                </div>

                <div class="brs-generate-wrap">
                    <button type="button" class="btn primary" id="generateBrsBtn">Generate BRS (.xml)</button>
                    <button type="button" class="btn" id="downloadAllXlsBtn">Unduh Semua Tabel (.xls)</button>
                </div>

                <div id="brsModalBackdrop" class="brs-modal-backdrop" aria-hidden="true"></div>
                <div id="brsModal" class="brs-modal" role="dialog" aria-modal="true" aria-hidden="true">
                    <div class="brs-modal-header">
                        <h3>Generate BRS</h3>
                        <button type="button" class="brs-modal-close" id="brsModalCloseBtn">&times;</button>
                    </div>
                    <div class="brs-modal-body">
                        <div>
                            <label for="brsNumberInput">Tuliskan Nomor BRS Bulan ini</label>
                            <input type="text" id="brsNumberInput" placeholder="Contoh: No. 61/11/18/Th.XXV">
                        </div>
                        <div>
                            <label for="brsDateInput">Pilih Tanggal Rilis</label>
                            <input type="date" id="brsDateInput">
                        </div>
                    </div>
                    <div class="brs-modal-actions">
                        <button type="button" class="btn" id="brsModalCancelBtn">Batal</button>
                        <button type="button" class="btn primary" id="brsModalSubmitBtn">Simpan</button>
                    </div>
                </div>
            <?php } elseif ($currentPage === 'generate-brs-text') { ?>
                <div class="page-header brs-text-header">
                    <div class="brs-text-header-main">
                        <h1>Generate BRS</h1>
                        <p>Konten narasi BRS dari dokumen PDF (mulai halaman 2).</p>
                    </div>
                    <div class="page-header-right">
                        <?php if ($accountMenuHtml !== '') { echo $accountMenuHtml; } ?>
                        <button type="button" class="btn primary" id="exportXmlBtn">Export XML</button>
                    </div>
                </div>

                <div class="card brs-text-page">
                    <div class="card-header">
                        <h2 class="card-title">Naskah BRS</h2>
                    </div>
                    <div class="card-body">
                        <p class="brs-dynamic-summary dynamic-ntp"><?= htmlspecialchars($brsDynamicNarrative); ?></p>
                        <?php if ($brsDynamicNtupHeadline !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntup"><?= htmlspecialchars($brsDynamicNtupHeadline); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicNtpItIbNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntp"><?= htmlspecialchars($brsDynamicNtpItIbNarrative); ?></p>
                        <?php } ?>
                        <p class="brs-dynamic-summary dynamic-subsector"><?= htmlspecialchars($brsDynamicSubsectorNarrative); ?></p>
                        <?php if ($brsDynamicIkrtDominantNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ikrt"><?= htmlspecialchars($brsDynamicIkrtDominantNarrative); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicNtupBriefNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntup"><?= htmlspecialchars($brsDynamicNtupBriefNarrative); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicNtpDetailNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-ntp"><?= htmlspecialchars($brsDynamicNtpDetailNarrative); ?></p>
                        <?php } ?>
                        <?php if ($brsDynamicSubsectorMoMDetailNarrative !== '') { ?>
                            <p class="brs-dynamic-summary dynamic-subsector"><?= htmlspecialchars($brsDynamicSubsectorMoMDetailNarrative); ?></p>
                        <?php } ?>
                        <p class="brs-dynamic-summary dynamic-it"><?= htmlspecialchars($brsDynamicItNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ib"><?= htmlspecialchars($brsDynamicIbNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpp"><?= htmlspecialchars($brsDynamicNtppNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntph"><?= htmlspecialchars($brsDynamicNtphNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpr"><?= htmlspecialchars($brsDynamicNtprNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpt"><?= htmlspecialchars($brsDynamicNtptNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntn"><?= htmlspecialchars($brsDynamicNtnNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntpi"><?= htmlspecialchars($brsDynamicNtpiNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-subsector-yoy"><?= htmlspecialchars($brsDynamicSubsectorYoyNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-subsector-change"><?= htmlspecialchars($brsDynamicSubsectorChangeNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ikrt"><?= htmlspecialchars($brsDynamicIkrtNarrative); ?></p>
                        <p class="brs-dynamic-summary dynamic-ntup"><?= htmlspecialchars($brsDynamicNtupNarrative); ?></p>
                        <?php if ($brsNarrativeText !== '') { ?>
                            <pre class="brs-text-content"><?= htmlspecialchars($brsNarrativeText); ?></pre>
                        <?php } ?>
                    </div>
                </div>
            <?php } elseif ($currentPage === 'login') { ?>
                <div class="login-wrap">
                    <div class="card upload-card login-card">
                        <div class="card-header">
                            <h2 class="card-title">Masuk</h2>
                            <p class="login-subtitle">Silakan login untuk mengakses Update Data dan Generate BRS.</p>
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="login">
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? 'dashboard'); ?>">
                            <div class="upload-form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="login_user">Username</label>
                                    <input class="form-input" id="login_user" name="username" type="text" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="login_pass">Password</label>
                                    <input class="form-input" id="login_pass" name="password" type="password" required>
                                </div>
                            </div>
                            <?php if (!empty($loginError)) { ?>
                                <div class="import-message error"><?= htmlspecialchars($loginError); ?></div>
                            <?php } ?>
                            <div class="login-actions">
                                <button type="submit" class="btn primary">Login</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php } ?>

            <footer class="copyright">
                Copyright &copy; <?= date('Y'); ?> NTP Lampung. Created by m.shalih.
            </footer>
        </main>
    </div>

    <button type="button" id="aiChatToggle" class="ai-chat-toggle" aria-label="Buka chat AI">
        <img src="img/robot.png" alt="Robot AI">
    </button>
    <div id="aiChatPanel" class="ai-chat-panel" aria-hidden="true">
        <div class="ai-chat-panel-header">
            <div class="ai-chat-panel-title">
                <strong>Asisten Data NTP Lampung</strong>
                <span>Analisis data NTP dan Andil</span>
            </div>
            <button type="button" class="ai-chat-panel-close" id="aiChatCloseBtn">&times;</button>
        </div>
        <div class="ai-chat-panel-body">
            <div id="aiChatBox" class="ai-chat-box">
                <div class="ai-msg bot">Halo. Saya siap membantu eksplorasi data NTP Lampung. Contoh: "NTP terbaru provinsi Lampung", "subsektor tertinggi bulan terakhir", atau "komoditas andil terbesar Februari 2026".</div>
            </div>
            <div class="ai-chat-controls">
                <textarea id="aiChatInput" placeholder="Ketik pertanyaan Anda..."></textarea>
                <div class="ai-chat-actions">
                    <button type="button" class="btn primary" id="sendAiChatBtn">Kirim</button>
                    <button type="button" class="btn" id="clearAiChatBtn">Reset</button>
                </div>
            </div>
        </div>
    </div>
    <div id="uiToast" class="ui-toast" role="status" aria-live="polite"></div>
    <script>
        (function() {
            const toastEl = document.getElementById('uiToast');
            let toastTimer = null;
            window.showToast = function(message, type) {
                if (!toastEl) return;
                toastEl.classList.remove('success', 'error', 'show');
                if (type) toastEl.classList.add(type);
                toastEl.textContent = message || '';
                toastEl.classList.add('show');
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(() => toastEl.classList.remove('show'), 3200);
            };
        })();

        (function() {
            const trigger = document.getElementById('accountTrigger');
            const dropdown = document.getElementById('accountDropdown');
            if (!trigger || !dropdown) return;

            function closeDropdown() {
                dropdown.classList.remove('open');
            }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('open');
            });

            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target)) {
                    closeDropdown();
                }
            });
        })();

        window.addEventListener('error', function(e) {
            const msg = e && e.message ? e.message : 'JavaScript error';
            if (window.showToast) {
                window.showToast('JS error: ' + msg, 'error');
            } else {
                alert('JS error: ' + msg);
            }
        });
    </script>

    <script src="js/templatemo-crypto-script.js"></script>

    <?php if ($currentPage === 'dashboard') { ?>
    <script>
        $(document).ready(function() {
            const dashboardRows = <?= json_encode($dashboardRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const dashboardAndilRows = <?= json_encode($dashboardAndilRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const monthOrder = {
                1: 1,
                2: 2,
                3: 3,
                4: 4,
                5: 5,
                6: 6,
                7: 7,
                8: 8,
                9: 9,
                10: 10,
                11: 11,
                12: 12,
                januari: 1,
                februari: 2,
                maret: 3,
                april: 4,
                mei: 5,
                juni: 6,
                juli: 7,
                agustus: 8,
                september: 9,
                oktober: 10,
                november: 11,
                desember: 12
            };
            const monthNameByIndex = {
                1: 'Januari',
                2: 'Februari',
                3: 'Maret',
                4: 'April',
                5: 'Mei',
                6: 'Juni',
                7: 'Juli',
                8: 'Agustus',
                9: 'September',
                10: 'Oktober',
                11: 'November',
                12: 'Desember'
            };
            const monthShortByIndex = {
                1: 'Jan',
                2: 'Feb',
                3: 'Mar',
                4: 'Apr',
                5: 'Mei',
                6: 'Jun',
                7: 'Jul',
                8: 'Agu',
                9: 'Sep',
                10: 'Okt',
                11: 'Nov',
                12: 'Des'
            };
            const provLabelMap = {
                '11': 'NAD', '12': 'SUMUT', '13': 'SUMBAR', '14': 'RIAU', '15': 'JAMBI', '16': 'SUMSEL',
                '17': 'BENGKULU', '18': 'LAMPUNG', '19': 'BABEL', '21': 'KEPRI', '31': 'DKI', '32': 'JABAR',
                '33': 'JATENG', '34': 'YOGYAKARTA', '35': 'JATIM', '36': 'BANTEN', '51': 'BALI', '52': 'NTB',
                '53': 'NTT', '61': 'KALBAR', '62': 'KALTENG', '63': 'KALSEL', '64': 'KALTIM', '65': 'KALTARA',
                '71': 'SULUT', '72': 'SULTENG', '73': 'SULSEL', '74': 'SULTRA', '75': 'GORONTALO', '76': 'SULBAR',
                '81': 'MALUKU', '82': 'MALUKU UTARA', '91': 'PAPUA BARAT', '92': 'PAPUA BARAT DAYA',
                '94': 'PAPUA', '95': 'PAPUA TENGAH', '96': 'PAPUA SELATAN'
            };
            const decimalFormatter = new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            function getMonthIndex(value) {
                const raw = String(value || '').trim();
                if (!raw) return null;
                if (/^\d+$/.test(raw)) {
                    const numeric = parseInt(raw, 10);
                    return numeric >= 1 && numeric <= 12 ? numeric : null;
                }
                return monthOrder[raw.toLowerCase()] || null;
            }
            function capitalizeWords(value) {
                return String(value || '').trim().replace(/\b\w/g, function(char) {
                    return char.toUpperCase();
                });
            }
            const valueLabelPlugin = {
                id: 'valueLabelPlugin',
                afterDatasetsDraw: function(chart) {
                    const ctx = chart.ctx;
                    const pluginOptions = (chart.options.plugins && chart.options.plugins.valueLabelPlugin) || {};
                    if (pluginOptions.enabled === false) return;
                    const allowed = Array.isArray(pluginOptions.datasets) ? pluginOptions.datasets : null;

                    ctx.save();
                    ctx.font = '600 10px Instrument Sans, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    chart.data.datasets.forEach(function(dataset, datasetIndex) {
                        if (!dataset) return;
                        if (allowed && !allowed.includes(datasetIndex)) return;
                        const meta = chart.getDatasetMeta(datasetIndex);
                        if (!meta || meta.hidden) return;
                        const labelText = String(dataset.label || '').toLowerCase();
                        const offset = (labelText.includes('ib') || labelText.includes('it') || labelText.includes('ntp') || labelText.includes('ntup')) ? -8 : 12;
                        ctx.fillStyle = dataset.borderColor || (chart.options.scales.x.ticks.color || '#9ca3af');
                        meta.data.forEach(function(point, index) {
                            const rawValue = dataset.data[index];
                            if (rawValue === null || rawValue === undefined || Number.isNaN(rawValue)) return;
                            ctx.fillText(formatDecimal(rawValue), point.x, point.y + offset);
                        });
                    });

                    ctx.restore();
                }
            };
            Chart.register(valueLabelPlugin);
            const targetProv = '18';
            const krtAllRowsRaw = <?= json_encode($dashboardKrtRows ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const allRows = dashboardRows.map(function(row) {
                return {
                    prov: String(row.prov || '').trim(),
                    tahun: parseInt(row.tahun, 10),
                    bulan: String(row.bulan || '').trim(),
                    bulanIndex: getMonthIndex(row.bulan) || 99,
                    subsektor: String(row.subsektor || '').trim(),
                    rincian: String(row.rincian || '').trim(),
                    nilai: parseFloat(String(row.nilai).replace(',', '.'))
                };
            }).filter(function(item) {
                return !isNaN(item.tahun) && !isNaN(item.nilai) && String(item.prov) === targetProv;
            });
            const krtAllRows = krtAllRowsRaw.map(function(row) {
                return {
                    prov: String(row.prov || '').trim(),
                    tahun: parseInt(row.tahun, 10),
                    bulan: String(row.bulan || '').trim(),
                    bulanIndex: getMonthIndex(row.bulan) || 99,
                    rincian: String(row.rincian || '').trim(),
                    nilai: parseFloat(String(row.nilai).replace(',', '.'))
                };
            }).filter(function(item) {
                return !isNaN(item.tahun) && !isNaN(item.nilai);
            });
            const andilRows = dashboardAndilRows.map(function(row) {
                return {
                    prov: String(row.prov || '').trim(),
                    tahun: parseInt(row.tahun, 10),
                    bulanIndex: getMonthIndex(row.kode_bulan) || 99,
                    kodeSubsektor: String(row.subsektor || '').trim().toUpperCase(),
                    komoditi: String(row.komoditi || '').trim(),
                    andil: parseFloat(String(row.andil).replace(',', '.'))
                };
            }).filter(function(item) {
                return item.prov === targetProv &&
                    item.tahun > 0 &&
                    item.bulanIndex >= 1 &&
                    item.bulanIndex <= 12 &&
                    Number.isFinite(item.andil);
            });

            const globalBulanFilter = $('#globalBulanFilter');
            const globalTahunFilter = $('#globalTahunFilter');
            const globalSubsektorFilter = $('#globalSubsektorFilter');
            const applyGlobalFilter = $('#applyGlobalFilter');
            const activePeriodLabel = $('#activePeriodLabel');
            let currentGlobalBulan = '';
            let currentGlobalTahun = '';
            let currentGlobalSubsektor = '';
            const ntpKey = 'nilai tukar petani';
            const ntupKey = 'nilai tukar usaha pertanian';
            const itKey = 'indeks harga yang diterima petani';
            const ibKey = 'indeks harga yang dibayar petani';
            const uniqueMonths = [...new Map(
                allRows
                    .filter(function(item) { return item.bulanIndex >= 1 && item.bulanIndex <= 12; })
                    .map(function(item) { return [item.bulanIndex, { index: item.bulanIndex }]; })
            ).values()].sort(function(a, b) {
                return a.index - b.index;
            });
            const uniqueYears = [...new Set(allRows.map(item => item.tahun))].sort(function(a, b) {
                return a - b;
            });
            const uniqueSubsektor = [...new Set(allRows.map(function(item) {
                return item.subsektor;
            }))].sort(function(a, b) {
                return String(a).localeCompare(String(b), 'id', { sensitivity: 'base' });
            });
            const latestRow = allRows.length ? allRows.reduce(function(prev, curr) {
                const prevScore = prev.tahun * 12 + prev.bulanIndex;
                const currScore = curr.tahun * 12 + curr.bulanIndex;
                return currScore > prevScore ? curr : prev;
            }) : null;

            globalBulanFilter.append(new Option('Semua Bulan', ''));
            uniqueMonths.forEach(function(month) {
                globalBulanFilter.append(new Option(monthNameByIndex[month.index] || String(month.index), String(month.index)));
            });

            globalTahunFilter.append(new Option('Semua Tahun', ''));
            uniqueYears.forEach(function(year) {
                globalTahunFilter.append(new Option(String(year), String(year)));
            });

            globalSubsektorFilter.append(new Option('Semua Subsektor', ''));
            uniqueSubsektor.forEach(function(subsektor) {
                globalSubsektorFilter.append(new Option(capitalizeWords(subsektor), subsektor));
            });

            if (latestRow) {
                globalBulanFilter.val(String(latestRow.bulanIndex));
                globalTahunFilter.val(String(latestRow.tahun));
            }
            const defaultSubsektor = uniqueSubsektor.find(function(item) {
                return normalizeText(item) === 'gabungan';
            });
            if (defaultSubsektor) {
                globalSubsektorFilter.val(defaultSubsektor);
            }

            function getGlobalRows() {
                return allRows.filter(function(item) {
                    const selectedMonthIndex = currentGlobalBulan ? parseInt(currentGlobalBulan, 10) : null;
                    const isBulanMatch = !selectedMonthIndex || item.bulanIndex === selectedMonthIndex;
                    const isTahunMatch = !currentGlobalTahun || String(item.tahun) === currentGlobalTahun;
                    return isBulanMatch && isTahunMatch;
                });
            }

            function updateActivePeriodLabel() {
                const monthNumber = Number(currentGlobalBulan);
                const periodBulan = monthNameByIndex[monthNumber] || 'Semua Bulan';
                const periodTahun = currentGlobalTahun || 'Semua Tahun';
                const subsektorLabel = capitalizeWords(currentGlobalSubsektor || 'Semua Subsektor');
                activePeriodLabel.text('Periode aktif: ' + periodBulan + ' ' + periodTahun + ' | Subsektor: ' + subsektorLabel);
            }

            function normalizeText(value) {
                return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
            }

            function getSelectedSubsektorLabel() {
                return capitalizeWords(currentGlobalSubsektor || 'Semua Subsektor');
            }

            function isSubsektorMatch(item) {
                if (!currentGlobalSubsektor) return true;
                return normalizeText(item.subsektor) === normalizeText(currentGlobalSubsektor);
            }

            function formatDecimal(value) {
                const num = Number(value) || 0;
                const trunc = Math.trunc(num * 100) / 100;
                return decimalFormatter.format(trunc);
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function getMetricRows(sourceRows, rincianKey) {
                return sourceRows.filter(function(item) {
                    return isSubsektorMatch(item) && normalizeText(item.rincian) === rincianKey;
                });
            }

            function getAxisRangeForSubsektor(rincianKey) {
                const rows = getMetricRows(allRows, rincianKey).filter(function(item) {
                    return item.bulanIndex >= 1 && item.bulanIndex <= 12 && Number.isFinite(item.nilai);
                });
                if (!rows.length) {
                    return { min: 50, max: 200 };
                }

                let minValue = rows[0].nilai;
                let maxValue = rows[0].nilai;
                rows.forEach(function(item) {
                    if (item.nilai < minValue) minValue = item.nilai;
                    if (item.nilai > maxValue) maxValue = item.nilai;
                });

                let axisMin = minValue - 5;
                let axisMax = maxValue + 5;
                if (axisMin === axisMax) {
                    axisMin -= 5;
                    axisMax += 5;
                }
                return { min: axisMin, max: axisMax };
            }
            function getAxisRangeForMultiple(rincianKeys) {
                let values = [];
                rincianKeys.forEach(function(key) {
                    const rows = getMetricRows(allRows, key).filter(function(item) {
                        return item.bulanIndex >= 1 && item.bulanIndex <= 12 && Number.isFinite(item.nilai);
                    });
                    rows.forEach(function(item) { values.push(item.nilai); });
                });
                if (!values.length) {
                    return { min: 50, max: 200 };
                }
                let minValue = Math.min.apply(null, values);
                let maxValue = Math.max.apply(null, values);
                let axisMin = minValue - 5;
                let axisMax = maxValue + 5;
                if (axisMin === axisMax) {
                    axisMin -= 5;
                    axisMax += 5;
                }
                return { min: axisMin, max: axisMax };
            }

            function averageForPeriod(sourceRows, rincianKey, tahun, bulanIndex) {
                const rows = getMetricRows(sourceRows, rincianKey).filter(function(item) {
                    return item.tahun === tahun && item.bulanIndex === bulanIndex;
                });
                if (!rows.length) return null;
                return rows.reduce((sum, item) => sum + item.nilai, 0) / rows.length;
            }

            function resolveCurrentPeriod(sourceRows, rincianKey) {
                const selectedMonthIndex = currentGlobalBulan ? parseInt(currentGlobalBulan, 10) : null;
                const selectedYear = currentGlobalTahun ? parseInt(currentGlobalTahun, 10) : null;

                if (selectedYear && selectedMonthIndex) {
                    return { tahun: selectedYear, bulanIndex: selectedMonthIndex };
                }

                const metricRows = getMetricRows(sourceRows, rincianKey);
                if (!metricRows.length) return null;

                let latest = metricRows[0];
                metricRows.forEach(function(item) {
                    const latestScore = latest.tahun * 12 + latest.bulanIndex;
                    const itemScore = item.tahun * 12 + item.bulanIndex;
                    if (itemScore > latestScore) {
                        latest = item;
                    }
                });

                return { tahun: latest.tahun, bulanIndex: latest.bulanIndex };
            }

            function resolveChartEndPeriod() {
                const selectedMonthIndex = currentGlobalBulan ? parseInt(currentGlobalBulan, 10) : null;
                const selectedYear = currentGlobalTahun ? parseInt(currentGlobalTahun, 10) : null;

                if (selectedMonthIndex && selectedYear) {
                    return { tahun: selectedYear, bulanIndex: selectedMonthIndex };
                }

                const subsektorRows = allRows.filter(function(item) {
                    return isSubsektorMatch(item) && item.bulanIndex >= 1 && item.bulanIndex <= 12;
                });
                if (!subsektorRows.length) return null;

                let latest = subsektorRows[0];
                subsektorRows.forEach(function(item) {
                    const prevScore = latest.tahun * 12 + latest.bulanIndex;
                    const currScore = item.tahun * 12 + item.bulanIndex;
                    if (currScore > prevScore) latest = item;
                });
                return { tahun: latest.tahun, bulanIndex: latest.bulanIndex };
            }

            function buildSeries13Months(rincianKey, endPeriod) {
                if (!endPeriod) return [];

                const periods = [];
                for (let i = 12; i >= 0; i--) {
                    const baseMonth = endPeriod.bulanIndex - i;
                    const shiftYear = Math.floor((baseMonth - 1) / 12);
                    const month = ((baseMonth - 1) % 12 + 12) % 12 + 1;
                    const year = endPeriod.tahun + shiftYear;
                    periods.push({ tahun: year, bulanIndex: month });
                }

                return periods.map(function(period) {
                    const rows = allRows.filter(function(item) {
                        return isSubsektorMatch(item) &&
                            normalizeText(item.rincian) === rincianKey &&
                            item.tahun === period.tahun &&
                            item.bulanIndex === period.bulanIndex;
                    });

                    const value = rows.length
                        ? (rows.reduce(function(sum, item) { return sum + item.nilai; }, 0) / rows.length)
                        : null;

                    return {
                        label: (monthShortByIndex[period.bulanIndex] || String(period.bulanIndex)) + ' ' + String(period.tahun).slice(-2),
                        value: value
                    };
                });
            }

            function computeMoMYoY(rincianKey, endPeriod) {
                if (!endPeriod) {
                    return { mom: null, yoy: null };
                }
                const current = averageForPeriod(allRows, rincianKey, endPeriod.tahun, endPeriod.bulanIndex);
                if (current === null) {
                    return { mom: null, yoy: null };
                }

                const prevMonth = endPeriod.bulanIndex === 1 ? 12 : endPeriod.bulanIndex - 1;
                const prevMonthYear = endPeriod.bulanIndex === 1 ? endPeriod.tahun - 1 : endPeriod.tahun;
                const prevMonthValue = averageForPeriod(allRows, rincianKey, prevMonthYear, prevMonth);

                const prevYearValue = averageForPeriod(allRows, rincianKey, endPeriod.tahun - 1, endPeriod.bulanIndex);

                const mom = (!prevMonthValue || prevMonthValue === 0) ? null : ((current - prevMonthValue) / prevMonthValue) * 100;
                const yoy = (!prevYearValue || prevYearValue === 0) ? null : ((current - prevYearValue) / prevYearValue) * 100;
                return { mom: mom, yoy: yoy };
            }

            function renderChartChangeBadge(selector, prefix, value) {
                const el = $(selector);
                el.removeClass('positive negative neutral');
                if (value === null || !isFinite(value)) {
                    el.addClass('neutral').text(prefix + ' -');
                    return;
                }
                const sign = value > 0 ? '+' : '';
                if (value > 0) {
                    el.addClass('positive');
                } else if (value < 0) {
                    el.addClass('negative');
                } else {
                    el.addClass('neutral');
                }
                el.text(prefix + ' ' + sign + formatDecimal(value) + '%');
            }

            function updateSummary(filteredRows) {
                function computeMetric(rincianKey) {
                    const period = resolveCurrentPeriod(filteredRows, rincianKey);
                    if (!period) {
                        return { currentValue: 0, changePct: null, periodLabel: '-', compareLabel: null };
                    }

                    const currentValue = averageForPeriod(allRows, rincianKey, period.tahun, period.bulanIndex);
                    if (currentValue === null) {
                        return { currentValue: 0, changePct: null, periodLabel: '-', compareLabel: null };
                    }

                    const prevMonth = period.bulanIndex === 1 ? 12 : period.bulanIndex - 1;
                    const prevYear = period.bulanIndex === 1 ? period.tahun - 1 : period.tahun;
                    const prevValue = averageForPeriod(allRows, rincianKey, prevYear, prevMonth);
                    const monthName = monthNameByIndex[period.bulanIndex] || String(period.bulanIndex);
                    const periodLabel = monthName + ' ' + period.tahun;
                    const prevMonthName = monthNameByIndex[prevMonth] || String(prevMonth);
                    const compareLabel = prevMonthName + ' ' + prevYear;

                    if (prevValue === null || prevValue === 0) {
                        return { currentValue: currentValue, changePct: null, periodLabel: periodLabel, compareLabel: compareLabel };
                    }

                    const changePct = ((currentValue - prevValue) / prevValue) * 100;
                    return { currentValue: currentValue, changePct: changePct, periodLabel: periodLabel, compareLabel: compareLabel };
                }

                function renderChange(selector, changePct, compareLabel) {
                    const el = $(selector);
                    el.removeClass('positive negative neutral');

                    if (changePct === null || !isFinite(changePct)) {
                        const noteText = compareLabel ? ('Data pembanding tidak tersedia (dibanding ' + compareLabel + ')') : 'Data pembanding tidak tersedia';
                        el.addClass('neutral').html('<span class="change-note">' + escapeHtml(noteText) + '</span>');
                        return;
                    }

                    const sign = changePct > 0 ? '+' : '';
                    if (changePct > 0) {
                        el.addClass('positive');
                    } else if (changePct < 0) {
                        el.addClass('negative');
                    } else {
                        el.addClass('neutral');
                    }

                    el.html(
                        '<span class="change-value">' + sign + formatDecimal(changePct) + '%</span>' +
                        '<span class="change-compare">dibanding ' + escapeHtml(compareLabel) + '</span>'
                    );
                }
                function renderChangeCompact(selector, changePct) {
                    const el = $(selector);
                    el.removeClass('positive negative neutral');
                    if (changePct === null || !isFinite(changePct)) {
                        el.addClass('neutral').text('-');
                        return;
                    }
                    const sign = changePct > 0 ? '+' : '';
                    if (changePct > 0) {
                        el.addClass('positive');
                    } else if (changePct < 0) {
                        el.addClass('negative');
                    } else {
                        el.addClass('neutral');
                    }
                    el.text(sign + formatDecimal(changePct) + '%');
                }

                const ntpMetric = computeMetric(ntpKey);
                const ntupMetric = computeMetric(ntupKey);
                const itMetric = computeMetric(itKey);
                const ibMetric = computeMetric(ibKey);

                $('#ntpMetricValue').text(formatDecimal(ntpMetric.currentValue));
                $('#ntupMetricValue').text(formatDecimal(ntupMetric.currentValue));
                $('#ntpMetricPeriod').text('Nilai NTP - ' + (ntpMetric.periodLabel || '-'));
                $('#ntupMetricPeriod').text('Nilai NTUP - ' + (ntupMetric.periodLabel || '-'));
                renderChange('#ntpMetricChange', ntpMetric.changePct, ntpMetric.compareLabel);
                renderChange('#ntupMetricChange', ntupMetric.changePct, ntupMetric.compareLabel);

                $('#itMetricValue').text(formatDecimal(itMetric.currentValue));
                $('#ibMetricValue').text(formatDecimal(ibMetric.currentValue));
                $('#itIbMetricPeriod').text('It vs Ib - ' + (itMetric.periodLabel || '-'));
                renderChangeCompact('#itMetricChange', itMetric.changePct);
                renderChangeCompact('#ibMetricChange', ibMetric.changePct);
            }

            function updateMovers() {
                const moversList = $('#moversList');
                const moversPeriod = $('#moversPeriod');
                moversList.empty();

                const selectedMonthIndex = currentGlobalBulan ? parseInt(currentGlobalBulan, 10) : null;
                const selectedYear = currentGlobalTahun ? parseInt(currentGlobalTahun, 10) : null;

                let period = null;
                if (selectedMonthIndex && selectedYear) {
                    period = { tahun: selectedYear, bulanIndex: selectedMonthIndex };
                } else {
                    const ntpRows = allRows.filter(function(item) {
                        return normalizeText(item.rincian) === ntpKey && item.bulanIndex >= 1 && item.bulanIndex <= 12;
                    });
                    if (ntpRows.length) {
                        let latest = ntpRows[0];
                        ntpRows.forEach(function(item) {
                            const latestScore = latest.tahun * 12 + latest.bulanIndex;
                            const itemScore = item.tahun * 12 + item.bulanIndex;
                            if (itemScore > latestScore) {
                                latest = item;
                            }
                        });
                        period = { tahun: latest.tahun, bulanIndex: latest.bulanIndex };
                    }
                }

                if (!period) {
                    moversPeriod.text('-');
                    moversList.append('<div class=\"movers-empty\">Data tidak tersedia untuk periode ini.</div>');
                    return;
                }

                const periodMonthName = monthNameByIndex[period.bulanIndex] || String(period.bulanIndex);
                moversPeriod.text(periodMonthName + ' ' + period.tahun);

                const prevMonth = period.bulanIndex === 1 ? 12 : period.bulanIndex - 1;
                const prevYear = period.bulanIndex === 1 ? period.tahun - 1 : period.tahun;

                const currentRows = allRows.filter(function(item) {
                    return item.tahun === period.tahun &&
                        item.bulanIndex === period.bulanIndex &&
                        normalizeText(item.rincian) === ntpKey &&
                        item.bulanIndex >= 1 && item.bulanIndex <= 12 &&
                        normalizeText(item.subsektor || '') !== 'gabungan';
                });
                const prevRows = allRows.filter(function(item) {
                    return item.tahun === prevYear &&
                        item.bulanIndex === prevMonth &&
                        normalizeText(item.rincian) === ntpKey &&
                        item.bulanIndex >= 1 && item.bulanIndex <= 12 &&
                        normalizeText(item.subsektor || '') !== 'gabungan';
                });

                const currentGabungan = allRows.filter(function(item) {
                    return item.tahun === period.tahun &&
                        item.bulanIndex === period.bulanIndex &&
                        normalizeText(item.rincian) === ntpKey &&
                        normalizeText(item.subsektor || '') === 'gabungan';
                });
                const prevGabungan = allRows.filter(function(item) {
                    return item.tahun === prevYear &&
                        item.bulanIndex === prevMonth &&
                        normalizeText(item.rincian) === ntpKey &&
                        normalizeText(item.subsektor || '') === 'gabungan';
                });
                const currentGabunganAvg = currentGabungan.length
                    ? currentGabungan.reduce(function(sum, item) { return sum + item.nilai; }, 0) / currentGabungan.length
                    : null;
                const prevGabunganAvg = prevGabungan.length
                    ? prevGabungan.reduce(function(sum, item) { return sum + item.nilai; }, 0) / prevGabungan.length
                    : null;
                const overallNtpChange = (currentGabunganAvg !== null && prevGabunganAvg !== null && prevGabunganAvg !== 0)
                    ? ((currentGabunganAvg - prevGabunganAvg) / prevGabunganAvg) * 100
                    : null;

                const bySubsektor = {};
                currentRows.forEach(function(item) {
                    const key = normalizeText(item.subsektor || '');
                    if (!key) return;
                    if (!bySubsektor[key]) {
                        bySubsektor[key] = {
                            label: capitalizeWords(item.subsektor),
                            total: 0,
                            count: 0
                        };
                    }
                    bySubsektor[key].total += item.nilai;
                    bySubsektor[key].count += 1;
                });
                prevRows.forEach(function(item) {
                    const key = normalizeText(item.subsektor || '');
                    if (!key) return;
                    if (!bySubsektor[key]) {
                        bySubsektor[key] = {
                            label: capitalizeWords(item.subsektor),
                            total: 0,
                            count: 0,
                            prevTotal: 0,
                            prevCount: 0
                        };
                    }
                    if (!('prevTotal' in bySubsektor[key])) {
                        bySubsektor[key].prevTotal = 0;
                        bySubsektor[key].prevCount = 0;
                    }
                    bySubsektor[key].prevTotal += item.nilai;
                    bySubsektor[key].prevCount += 1;
                });

                const movers = Object.keys(bySubsektor).map(function(key) {
                    const data = bySubsektor[key];
                    const currentAvg = data.count ? (data.total / data.count) : null;
                    const prevAvg = data.prevCount ? (data.prevTotal / data.prevCount) : null;
                    const changePct = (currentAvg !== null && prevAvg !== null && prevAvg !== 0)
                        ? ((currentAvg - prevAvg) / prevAvg) * 100
                        : null;
                    return {
                        subsektor: data.label,
                        currentAvg: currentAvg,
                        changePct: changePct
                    };
                }).filter(function(item) {
                    return item.changePct !== null && isFinite(item.changePct);
                }).sort(function(a, b) {
                    if (overallNtpChange !== null && overallNtpChange < 0) {
                        return a.changePct - b.changePct;
                    }
                    return b.changePct - a.changePct;
                });

                if (!movers.length) {
                    moversList.append('<div class=\"movers-empty\">Data pembanding bulan sebelumnya tidak tersedia untuk perhitungan MoM.</div>');
                    return;
                }

                movers.slice(0, 8).forEach(function(item, index) {
                    const changeClass = item.changePct >= 0 ? 'positive' : 'negative';
                    const sign = item.changePct > 0 ? '+' : '';
                    const rowHtml = `
                        <div class=\"mover-item\">
                            <div class=\"mover-rank\">${index + 1}</div>
                            <div class=\"mover-coin\">
                                <div class=\"coin-info\">
                                    <span class=\"coin-name\">${escapeHtml(item.subsektor)}</span>
                                </div>
                            </div>
                            <div class=\"mover-data\">
                                <span class=\"mover-price\">${formatDecimal(item.currentAvg)}</span>
                                <span class=\"mover-change ${changeClass}\">${sign}${formatDecimal(item.changePct)}%</span>
                            </div>
                        </div>
                    `;
                    moversList.append(rowHtml);
                });
            }

            function updateIkrtRanking() {
                const topList = $('#ikrtTopList');
                const bottomList = $('#ikrtBottomList');
                const lampungRankEl = $('#ikrtLampungRank');
                const periodLabel = $('#ikrtRankPeriod');
                topList.empty();
                bottomList.empty();

                const selectedMonthIndex = currentGlobalBulan ? parseInt(currentGlobalBulan, 10) : null;
                const selectedYear = currentGlobalTahun ? parseInt(currentGlobalTahun, 10) : null;
                if (!selectedMonthIndex || !selectedYear) {
                    periodLabel.text('-');
                    topList.append('<div class="movers-empty">Pilih bulan dan tahun.</div>');
                    bottomList.append('<div class="movers-empty">Pilih bulan dan tahun.</div>');
                    lampungRankEl.text('-');
                    return;
                }

                const periodMonthName = monthNameByIndex[selectedMonthIndex] || String(selectedMonthIndex);
                periodLabel.text(periodMonthName + ' ' + selectedYear);

                const prevMonth = selectedMonthIndex === 1 ? 12 : selectedMonthIndex - 1;
                const prevYear = selectedMonthIndex === 1 ? selectedYear - 1 : selectedYear;

                const currentRows = krtAllRows.filter(function(item) {
                    return item.tahun === selectedYear && item.bulanIndex === selectedMonthIndex;
                });
                const prevRows = krtAllRows.filter(function(item) {
                    return item.tahun === prevYear && item.bulanIndex === prevMonth;
                });

                const currentMap = {};
                currentRows.forEach(function(item) { currentMap[item.prov] = item.nilai; });
                const prevMap = {};
                prevRows.forEach(function(item) { prevMap[item.prov] = item.nilai; });

                const changes = [];
                Object.keys(currentMap).forEach(function(prov) {
                    if (prevMap[prov] === undefined || prevMap[prov] === 0) return;
                    const pct = ((currentMap[prov] - prevMap[prov]) / prevMap[prov]) * 100;
                    changes.push({ prov: prov, value: pct });
                });

                if (!changes.length) {
                    topList.append('<div class="movers-empty">Data pembanding tidak tersedia.</div>');
                    bottomList.append('<div class="movers-empty">Data pembanding tidak tersedia.</div>');
                    lampungRankEl.text('-');
                    return;
                }

                const sortedDesc = changes.slice().sort(function(a, b) { return b.value - a.value; });
                const sortedAsc = changes.slice().sort(function(a, b) { return a.value - b.value; });

                sortedDesc.slice(0, 3).forEach(function(item, index) {
                    const label = provLabelMap[item.prov] || item.prov;
                    const sign = item.value > 0 ? '+' : '';
                    topList.append(`<div class="ikrt-rank-item"><span class="rank-num">${index + 1}</span><span>${label}</span><span class="rank-value">${sign}${formatDecimal(item.value)}%</span></div>`);
                });
                const bottomListData = sortedAsc.slice(0, 3);
                const totalRankCount = sortedDesc.length;
                bottomListData.forEach(function(item, index) {
                    const label = provLabelMap[item.prov] || item.prov;
                    const sign = item.value > 0 ? '+' : '';
                    const rankNumber = totalRankCount - index;
                    bottomList.append(`<div class="ikrt-rank-item"><span class="rank-num">${rankNumber}</span><span>${label}</span><span class="rank-value">${sign}${formatDecimal(item.value)}%</span></div>`);
                });

                const lampungIndex = sortedDesc.findIndex(function(item) { return item.prov === '18'; });
                if (lampungIndex >= 0) {
                    const lampungItem = sortedDesc[lampungIndex];
                    const sign = lampungItem.value > 0 ? '+' : '';
                    lampungRankEl.text('Peringkat ke-' + (lampungIndex + 1) + ' (' + sign + formatDecimal(lampungItem.value) + '%)');
                } else {
                    lampungRankEl.text('-');
                }
            }

            function updateAndilSubsektorTable() {
                const tbody = $('#andilSubsektorTableBody');
                tbody.empty();

                const period = resolveCurrentPeriod(allRows, ntpKey);
                if (!period) {
                    tbody.append('<tr><td colspan="5" class="movers-empty">Data periode tidak tersedia.</td></tr>');
                    return;
                }

                const prevMonth = period.bulanIndex === 1 ? 12 : period.bulanIndex - 1;
                const prevYear = period.bulanIndex === 1 ? period.tahun - 1 : period.tahun;
                const itAliases = ['indeks harga yang diterima petani', 'indeks harga yang diterima oleh petani'];

                const mapConfig = [
                    { code: 'TP', label: 'Tanaman Pangan', aliases: ['tanaman pangan'] },
                    { code: 'TH', label: 'Tanaman Hortikultura', aliases: ['hortikultura', 'tanaman hortikultura'] },
                    { code: 'TPR', label: 'Tanaman Perkebunan Rakyat', aliases: ['tanaman perkebunan rakyat'] },
                    { code: 'TRK', label: 'Peternakan', aliases: ['peternakan'] },
                    { code: 'IKT', label: 'Ikan Tangkap', aliases: ['ikan tangkap', 'perikanan tangkap'] },
                    { code: 'IKB', label: 'Ikan Budidaya', aliases: ['ikan budidaya', 'perikanan budidaya'] }
                ];

                function avgMetricByAliases(year, monthIndex, subsektorAliases, rincianAliases) {
                    const rows = allRows.filter(function(item) {
                        if (item.tahun !== year || item.bulanIndex !== monthIndex) return false;
                        const subsektorMatch = subsektorAliases.some(function(alias) {
                            return normalizeText(item.subsektor) === normalizeText(alias);
                        });
                        if (!subsektorMatch) return false;
                        return rincianAliases.some(function(alias) {
                            return normalizeText(item.rincian) === normalizeText(alias);
                        });
                    });
                    if (!rows.length) return null;
                    return rows.reduce(function(sum, item) { return sum + item.nilai; }, 0) / rows.length;
                }

                let hasAny = false;
                mapConfig.forEach(function(cfg) {
                    const itCurrent = avgMetricByAliases(period.tahun, period.bulanIndex, cfg.aliases, itAliases);
                    const itPrev = avgMetricByAliases(prevYear, prevMonth, cfg.aliases, itAliases);
                    const itChange = (itCurrent !== null && itPrev !== null && itPrev !== 0)
                        ? ((itCurrent - itPrev) / itPrev) * 100
                        : null;

                    const andilCandidates = andilRows.filter(function(item) {
                        return item.tahun === period.tahun &&
                            item.bulanIndex === period.bulanIndex &&
                            item.kodeSubsektor === cfg.code;
                    });
                    if (!andilCandidates.length) {
                        return;
                    }

                    let selected = andilCandidates[0];
                    if (itChange !== null && itChange < 0) {
                        andilCandidates.forEach(function(item) {
                            if (item.andil < selected.andil) selected = item;
                        });
                    } else {
                        andilCandidates.forEach(function(item) {
                            if (item.andil > selected.andil) selected = item;
                        });
                    }

                    hasAny = true;
                    const itCurrentText = itCurrent !== null ? formatDecimal(itCurrent) : '-';
                    const itChangeText = (itChange !== null && isFinite(itChange))
                        ? ((itChange > 0 ? '+' : '') + formatDecimal(itChange) + '%')
                        : '-';
                    const itChangeClass = (itChange === null || !isFinite(itChange))
                        ? 'neutral'
                        : (itChange > 0 ? 'positive' : (itChange < 0 ? 'negative' : 'neutral'));
                    const rowHtml = '<tr>' +
                        '<td>' + escapeHtml(cfg.label) + '</td>' +
                        '<td>' + escapeHtml(selected.komoditi || '-') + '</td>' +
                        '<td class="it-col">' + itCurrentText + '</td>' +
                        '<td class="it-change-col"><span class="andil-change-badge ' + itChangeClass + '">' + itChangeText + '</span></td>' +
                        '<td>' + formatDecimal(selected.andil) + '</td>' +
                        '</tr>';
                    tbody.append(rowHtml);
                });

                if (!hasAny) {
                    tbody.append('<tr><td colspan="5" class="movers-empty">Data andil tidak tersedia untuk periode ini.</td></tr>');
                }
            }

            const ntpChartCtx = document.getElementById('ntpChart').getContext('2d');
            const ntupChartCtx = document.getElementById('ntupChart').getContext('2d');
            const itIbChartCtx = document.getElementById('itIbChart').getContext('2d');

            const ntpChart = new Chart(ntpChartCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Nilai Tukar Petani',
                        data: [],
                        borderColor: '#b87333',
                        backgroundColor: 'rgba(184, 115, 51, 0.18)',
                        borderWidth: 2.5,
                        pointRadius: 3.5,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#b87333',
                        pointBorderWidth: 0,
                        tension: 0.3,
                        fill: true,
                        spanGaps: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Nilai: ' + formatDecimal(context.parsed.y);
                                }
                            }
                        }
                        ,
                        valueLabelPlugin: { enabled: true }
                    },
                    scales: {
                        x: {
                            ticks: { color: '#9ca3af' },
                            grid: { color: 'rgba(255,255,255,0.06)' }
                        },
                        y: {
                            display: false,
                            ticks: { display: false },
                            grid: { display: false }
                        }
                    }
                }
            });

            const ntupChart = new Chart(ntupChartCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Nilai Tukar Usaha Pertanian',
                        data: [],
                        borderColor: '#6b8e6b',
                        backgroundColor: 'rgba(107, 142, 107, 0.18)',
                        borderWidth: 2.5,
                        pointRadius: 3.5,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#6b8e6b',
                        pointBorderWidth: 0,
                        tension: 0.3,
                        fill: true,
                        spanGaps: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Nilai: ' + formatDecimal(context.parsed.y);
                                }
                            }
                        }
                        ,
                        valueLabelPlugin: { enabled: true }
                    },
                    scales: {
                        x: {
                            ticks: { color: '#9ca3af' },
                            grid: { color: 'rgba(255,255,255,0.06)' }
                        },
                        y: {
                            display: false,
                            ticks: { display: false },
                            grid: { display: false }
                        }
                    }
                }
            });

            const itIbChart = new Chart(itIbChartCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'It',
                            data: [],
                            borderColor: '#b87333',
                            backgroundColor: 'rgba(184, 115, 51, 0.18)',
                            borderWidth: 2.2,
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#b87333',
                            pointBorderWidth: 0,
                            tension: 0.3,
                            fill: true,
                            spanGaps: true
                        },
                        {
                            label: 'Ib',
                            data: [],
                            borderColor: '#6b8e6b',
                            backgroundColor: 'rgba(107, 142, 107, 0.18)',
                            borderWidth: 2.2,
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#6b8e6b',
                            pointBorderWidth: 0,
                            tension: 0.3,
                            fill: true,
                            spanGaps: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatDecimal(context.parsed.y);
                                }
                            }
                        },
                        valueLabelPlugin: { enabled: true, datasets: [0, 1] }
                    },
                    scales: {
                        x: {
                            ticks: { color: '#9ca3af' },
                            grid: { color: 'rgba(255,255,255,0.06)' }
                        },
                        y: {
                            display: false,
                            ticks: { display: false },
                            grid: { display: false }
                        }
                    }
                }
            });

            function updateCharts() {
                const endPeriod = resolveChartEndPeriod();
                const ntpSeries = buildSeries13Months(ntpKey, endPeriod);
                const ntupSeries = buildSeries13Months(ntupKey, endPeriod);
                const itSeries = buildSeries13Months(itKey, endPeriod);
                const ibSeries = buildSeries13Months(ibKey, endPeriod);
                const subtitle = [];
                if (currentGlobalBulan) subtitle.push(monthNameByIndex[parseInt(currentGlobalBulan, 10)] || currentGlobalBulan);
                if (currentGlobalTahun) subtitle.push(currentGlobalTahun);
                const subsektorLabel = getSelectedSubsektorLabel();
                $('#ntpChartTitle').text('Rincian Nilai Tukar Petani (' + subsektorLabel + ')');
                $('#ntupChartTitle').text('Nilai Tukar Usaha Pertanian (' + subsektorLabel + ')');
                $('#itIbChartTitle').text('Indeks Harga It vs Ib (Provinsi Lampung)');

                ntpChart.data.labels = ntpSeries.map(item => item.label);
                ntpChart.data.datasets[0].data = ntpSeries.map(item => item.value);
                ntpChart.data.datasets[0].label = 'NTP ' + subsektorLabel + ' (13 Bulan Terakhir)' + (subtitle.length ? ' [' + subtitle.join(' - ') + ']' : '');
                const ntpAxisRange = getAxisRangeForSubsektor(ntpKey);
                ntpChart.options.scales.y.min = ntpAxisRange.min;
                ntpChart.options.scales.y.max = ntpAxisRange.max;
                ntpChart.update();

                ntupChart.data.labels = ntupSeries.map(item => item.label);
                ntupChart.data.datasets[0].data = ntupSeries.map(item => item.value);
                ntupChart.data.datasets[0].label = 'NTUP ' + subsektorLabel + ' (13 Bulan Terakhir)' + (subtitle.length ? ' [' + subtitle.join(' - ') + ']' : '');
                const ntupAxisRange = getAxisRangeForSubsektor(ntupKey);
                ntupChart.options.scales.y.min = ntupAxisRange.min;
                ntupChart.options.scales.y.max = ntupAxisRange.max;
                ntupChart.update();

                itIbChart.data.labels = itSeries.map(item => item.label);
                itIbChart.data.datasets[0].data = itSeries.map(item => item.value);
                itIbChart.data.datasets[1].data = ibSeries.map(item => item.value);
                const itIbRange = getAxisRangeForMultiple([itKey, ibKey]);
                itIbChart.options.scales.y.min = itIbRange.min;
                itIbChart.options.scales.y.max = itIbRange.max;
                itIbChart.update();

                const ntpChange = computeMoMYoY(ntpKey, endPeriod);
                const ntupChange = computeMoMYoY(ntupKey, endPeriod);
                renderChartChangeBadge('#ntpMtmBadge', 'MoM', ntpChange.mom);
                renderChartChangeBadge('#ntpYoyBadge', 'YoY', ntpChange.yoy);
                renderChartChangeBadge('#ntupMtmBadge', 'MoM', ntupChange.mom);
                renderChartChangeBadge('#ntupYoyBadge', 'YoY', ntupChange.yoy);
            }

            function applyGlobalPeriodFilter() {
                currentGlobalBulan = globalBulanFilter.val();
                currentGlobalTahun = globalTahunFilter.val();
                currentGlobalSubsektor = globalSubsektorFilter.val();

                updateActivePeriodLabel();
                const globalRows = getGlobalRows();
                updateSummary(globalRows);
                updateCharts();
                updateMovers();
                updateIkrtRanking();
                updateAndilSubsektorTable();
            }

            function setItIbVisibility(mode) {
                if (!itIbChart) return;
                if (mode === 'it') {
                    itIbChart.data.datasets[0].hidden = false;
                    itIbChart.data.datasets[1].hidden = true;
                    itIbChart.options.plugins.valueLabelPlugin = { enabled: true, datasets: [0] };
                } else if (mode === 'ib') {
                    itIbChart.data.datasets[0].hidden = true;
                    itIbChart.data.datasets[1].hidden = false;
                    itIbChart.options.plugins.valueLabelPlugin = { enabled: true, datasets: [1] };
                } else {
                    itIbChart.data.datasets[0].hidden = false;
                    itIbChart.data.datasets[1].hidden = false;
                    itIbChart.options.plugins.valueLabelPlugin = { enabled: true, datasets: [0, 1] };
                }
                itIbChart.update();
            }

            $('#itIbToggleGroup .chart-toggle-btn').on('click', function() {
                const mode = $(this).data('series');
                $('#itIbToggleGroup .chart-toggle-btn').removeClass('active');
                $(this).addClass('active');
                setItIbVisibility(mode);
            });

            function downloadChartImage(chart, fileBaseName, format) {
                if (!chart || !chart.canvas) return;
                const sourceCanvas = chart.canvas;
                const exportCanvas = document.createElement('canvas');
                exportCanvas.width = sourceCanvas.width;
                exportCanvas.height = sourceCanvas.height;
                const exportCtx = exportCanvas.getContext('2d');
                exportCtx.fillStyle = '#ffffff';
                exportCtx.fillRect(0, 0, exportCanvas.width, exportCanvas.height);
                exportCtx.drawImage(sourceCanvas, 0, 0);

                const imageType = format === 'jpg' ? 'image/jpeg' : 'image/png';
                const imageData = exportCanvas.toDataURL(imageType, 0.92);
                const link = document.createElement('a');
                link.href = imageData;
                link.download = fileBaseName + '.' + (format === 'jpg' ? 'jpg' : 'png');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            $('#downloadNtpPng').on('click', function() { downloadChartImage(ntpChart, 'grafik-ntp', 'png'); });
            $('#downloadNtpJpg').on('click', function() { downloadChartImage(ntpChart, 'grafik-ntp', 'jpg'); });
            $('#downloadNtupPng').on('click', function() { downloadChartImage(ntupChart, 'grafik-ntup', 'png'); });
            $('#downloadNtupJpg').on('click', function() { downloadChartImage(ntupChart, 'grafik-ntup', 'jpg'); });

            applyGlobalPeriodFilter();

            applyGlobalFilter.on('click', function() {
                applyGlobalPeriodFilter();
            });

            const observer = new MutationObserver(function() {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const isDark = currentTheme === 'dark';
                const axisColor = isDark ? '#9ca3af' : '#4b5563';
                const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.07)';

                ntpChart.options.scales.x.ticks.color = axisColor;
                ntpChart.options.scales.x.grid.color = gridColor;
                ntpChart.update();

                ntupChart.options.scales.x.ticks.color = axisColor;
                ntupChart.options.scales.x.grid.color = gridColor;
                ntupChart.update();
            });

            observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        });
    </script>
    <?php } elseif ($currentPage === 'generate-brs') { ?>
    <script>
        $(document).ready(function() {
            const monthNameToIndex = {
                'januari': 1, 'februari': 2, 'maret': 3, 'april': 4, 'mei': 5, 'juni': 6,
                'juli': 7, 'agustus': 8, 'september': 9, 'oktober': 10, 'november': 11, 'desember': 12
            };
            const monthIndexToName = {
                1: 'Januari', 2: 'Februari', 3: 'Maret', 4: 'April', 5: 'Mei', 6: 'Juni',
                7: 'Juli', 8: 'Agustus', 9: 'September', 10: 'Oktober', 11: 'November', 12: 'Desember'
            };

            const bulanFilter = $('#brsBulanFilter');
            const tahunFilter = $('#brsTahunFilter');
            const applyFilterBtn = $('#applyBrsFilter');
            const downloadBtn = $('#downloadAllXlsBtn');
            const generateBtn = $('#generateBrsBtn');
            const brsModal = $('#brsModal');
            const brsBackdrop = $('#brsModalBackdrop');
            const brsClose = $('#brsModalCloseBtn');
            const brsCancel = $('#brsModalCancelBtn');
            const brsSubmit = $('#brsModalSubmitBtn');
            const brsNumberInput = $('#brsNumberInput');
            const brsDateInput = $('#brsDateInput');
            if (generateBtn.length && window.showToast) {
                window.showToast('JS siap: tombol Generate BRS aktif.', 'success');
            }

            function resolveMonthIndex(raw) {
                const value = String(raw || '').trim();
                if (!value) return null;
                if (/^\d+$/.test(value)) {
                    const num = parseInt(value, 10);
                    return num >= 1 && num <= 12 ? num : null;
                }
                return monthNameToIndex[value.toLowerCase()] || null;
            }

            function updateBrsHeaders() {
                const bulanValue = bulanFilter.val();
                const tahunValue = tahunFilter.val();
                const monthIndex = resolveMonthIndex(bulanValue);

                let currentLabel = '';
                let prevLabel = '';
                let yoyPrevLabel = '';

                if (monthIndex && tahunValue) {
                    const prevMonth = monthIndex === 1 ? 12 : monthIndex - 1;
                    const prevYear = monthIndex === 1 ? (parseInt(tahunValue, 10) - 1) : parseInt(tahunValue, 10);
                    currentLabel = (monthIndexToName[monthIndex] || bulanValue) + ' ' + tahunValue;
                    prevLabel = monthIndexToName[prevMonth] + ' ' + prevYear;
                    yoyPrevLabel = (monthIndexToName[monthIndex] || bulanValue) + ' ' + (parseInt(tahunValue, 10) - 1);
                }

                $('.brs-current-col').text(currentLabel);
                $('.brs-prev-col').text(prevLabel);
                $('.brs-yoy-current-col').text(currentLabel);
                $('.brs-yoy-prev-col').text(yoyPrevLabel);
            }

            function applyBrsFilterToPage() {
                const params = new URLSearchParams(window.location.search);
                params.set('page', 'generate-brs');

                const bulanValue = (bulanFilter.val() || '').toString().trim();
                const tahunValue = (tahunFilter.val() || '').toString().trim();

                if (bulanValue) {
                    params.set('brs_bulan', bulanValue);
                } else {
                    params.delete('brs_bulan');
                }

                if (tahunValue) {
                    params.set('brs_tahun', tahunValue);
                } else {
                    params.delete('brs_tahun');
                }

                window.location.search = params.toString();
            }

            updateBrsHeaders();
            bulanFilter.on('change', function() {
                updateBrsHeaders();
            });
            tahunFilter.on('change', function() {
                updateBrsHeaders();
            });
            applyFilterBtn.on('click', function() {
                applyBrsFilterToPage();
            });

            downloadBtn.on('click', function() {
                const bulan = (bulanFilter.val() || 'semua-bulan').toString().replace(/\s+/g, '-').toLowerCase();
                const tahun = (tahunFilter.val() || 'semua-tahun').toString();
                const filename = 'brs-' + bulan + '-' + tahun + '.xls';

                const tableIds = ['brsTable1', 'brsTable2', 'brsTable3', 'brsTable4'];
                let html = '<html><head><meta charset=\"UTF-8\"></head><body>';
                tableIds.forEach(function(id, idx) {
                    const table = document.getElementById(id);
                    if (!table) return;
                    html += '<h3>Tabel BRS ' + (idx + 1) + '</h3>';
                    html += table.outerHTML;
                    html += '<br/><br/>';
                });
                html += '</body></html>';

                const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });

            generateBtn.on('click', function(event) {
                event.preventDefault();
                brsModal.addClass('open').attr('aria-hidden', 'false');
                brsBackdrop.addClass('open').attr('aria-hidden', 'false');
                brsNumberInput.focus();
            });
            function closeBrsModal() {
                brsModal.removeClass('open').attr('aria-hidden', 'true');
                brsBackdrop.removeClass('open').attr('aria-hidden', 'true');
            }
            brsClose.on('click', closeBrsModal);
            brsCancel.on('click', closeBrsModal);
            brsBackdrop.on('click', closeBrsModal);
            brsSubmit.off('click').on('click', function() {
                try {
                    if (window.showToast) {
                        window.showToast('Klik simpan diterima.', 'success');
                    }
                    const noBrsRaw = (brsNumberInput.val() || '').toString().trim();
                if (!noBrsRaw) {
                    if (window.showToast) {
                        window.showToast('Nomor BRS wajib diisi.', 'error');
                    } else {
                        alert('Nomor BRS wajib diisi.');
                    }
                    brsNumberInput.focus();
                    return;
                }
                const dateRaw = (brsDateInput.val() || '').toString().trim();
                let noBrsText = noBrsRaw;
                if (dateRaw) {
                    const d = new Date(dateRaw + 'T00:00:00');
                    const pad = function(n) { return String(n).padStart(2, '0'); };
                    const monthName = monthIndexToName[d.getMonth() + 1];
                    if (monthName) {
                        const dateLabel = d.getDate() + ' ' + monthName + ' ' + d.getFullYear();
                        if (noBrsText.indexOf(dateLabel) === -1) {
                            noBrsText = noBrsText.replace(/\s+$/, '') + ', ' + dateLabel;
                        }
                    }
                }

                const blocks = [];
                $('.brs-text-page .brs-dynamic-summary').each(function() {
                    const text = $(this).text().trim();
                    if (text) blocks.push(text);
                });

                const bulanValue = (bulanFilter.val() || '').toString().trim();
                const tahunValue = (tahunFilter.val() || '').toString().trim();
                const monthIndex = resolveMonthIndex(bulanValue);
                const monthLabel = monthIndex ? monthIndexToName[monthIndex] : bulanValue;

                if (window.showToast) {
                    window.showToast('Menyimpan XML...', 'success');
                }
                brsSubmit.prop('disabled', true).addClass('disabled');
                fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_brs_xml',
                        no_brs: noBrsText,
                        month: monthLabel,
                        year: tahunValue,
                        blocks: blocks
                    })
                }).then(res => res.text())
                  .then(text => {
                      let data = null;
                      try {
                          data = JSON.parse(text);
                      } catch (e) {
                          data = null;
                      }
                      if (data && data.ok) {
                          if (window.showToast) {
                              window.showToast('XML disimpan: ' + data.file, 'success');
                          } else {
                              alert('XML disimpan: ' + data.file);
                          }
                          closeBrsModal();
                      } else {
                          const msg = data && data.message ? data.message : (text ? 'Gagal menyimpan XML. Respon: ' + text : 'Gagal menyimpan XML.');
                          if (window.showToast) {
                              window.showToast(msg, 'error');
                          } else {
                              alert(msg);
                          }
                      }
                  }).catch(() => {
                      if (window.showToast) {
                          window.showToast('Gagal menyimpan XML.', 'error');
                      } else {
                          alert('Gagal menyimpan XML.');
                      }
                  }).finally(() => {
                      brsSubmit.prop('disabled', false).removeClass('disabled');
                  });
                } catch (err) {
                    if (window.showToast) {
                        window.showToast('Error: ' + (err && err.message ? err.message : 'Tidak diketahui'), 'error');
                    } else {
                        alert('Error: ' + (err && err.message ? err.message : 'Tidak diketahui'));
                    }
                }
            });
        });
    </script>
    <?php } elseif ($currentPage === 'generate-brs-text') { ?>
    <script>
        $(document).ready(function() {
            const exportBtn = $('#exportXmlBtn');
            exportBtn.on('click', function() {
                const content = ($('.brs-text-content').text() || '').trim();
                const xmlEscape = function(value) {
                    return String(value || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/\"/g, '&quot;')
                        .replace(/'/g, '&apos;');
                };

                const now = new Date();
                const pad = function(n) { return String(n).padStart(2, '0'); };
                const dateLabel = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
                const xml = [
                    '<?xml version="1.0" encoding="UTF-8"?>',
                    '<brs>',
                    '  <source>perkembangan-nilai-tukar-petani-provinsi-lampung-februari-2026.pdf</source>',
                    '  <generated_at>' + xmlEscape(now.toISOString()) + '</generated_at>',
                    '  <content>' + xmlEscape(content) + '</content>',
                    '</brs>'
                ].join('\n');

                const blob = new Blob([xml], { type: 'application/xml;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'naskah-brs-' + dateLabel + '.xml';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
        });
    </script>
    <?php } ?>
    <?php if ($currentPage === 'update-data') { ?>
    <script>
        $(document).ready(function() {
            const openBtn = $('#openDeleteByDate');
            const modal = $('#deleteByDateModal');
            const backdrop = $('#deleteByDateBackdrop');
            const closeBtn = $('#deleteByDateCloseBtn');
            const cancelBtn = $('#deleteByDateCancelBtn');
            const submitBtn = $('#deleteByDateSubmitBtn');
            const dateInput = $('#delete_date_modal');

            function openModal() {
                modal.addClass('open').attr('aria-hidden', 'false');
                backdrop.addClass('open').attr('aria-hidden', 'false');
                dateInput.focus();
            }
            function closeModal() {
                modal.removeClass('open').attr('aria-hidden', 'true');
                backdrop.removeClass('open').attr('aria-hidden', 'true');
            }

            openBtn.on('click', function() { openModal(); });
            closeBtn.on('click', closeModal);
            cancelBtn.on('click', closeModal);
            backdrop.on('click', closeModal);

            submitBtn.on('click', function() {
                const dateVal = (dateInput.val() || '').toString().trim();
                if (!dateVal) {
                    if (window.showToast) {
                        window.showToast('Tanggal upload wajib diisi.', 'error');
                    } else {
                        alert('Tanggal upload wajib diisi.');
                    }
                    return;
                }
                if (!confirm('Yakin ingin menghapus data pada tanggal ini?')) return;
                const form = $('<form method="post"></form>');
                form.append('<input type="hidden" name="action" value="delete_by_date">');
                form.append('<input type="hidden" name="delete_date" value="' + dateVal + '">');
                $('body').append(form);
                form.submit();
            });
        });
    </script>
    <?php } ?>
    <script>
        $(document).ready(function() {
            const chatPanel = $('#aiChatPanel');
            const toggleBtn = $('#aiChatToggle');
            const closeBtn = $('#aiChatCloseBtn');
            const chatBox = $('#aiChatBox');
            const input = $('#aiChatInput');
            const sendBtn = $('#sendAiChatBtn');
            const clearBtn = $('#clearAiChatBtn');
            const isExplorePage = false;
            let history = [];

            function openChat() {
                chatPanel.addClass('open').attr('aria-hidden', 'false');
                input.focus();
            }
            function closeChat() {
                chatPanel.removeClass('open').attr('aria-hidden', 'true');
            }

            function appendMsg(role, text) {
                const cls = role === 'user' ? 'user' : 'bot';
                chatBox.append('<div class="ai-msg ' + cls + '">' + $('<div>').text(text).html() + '</div>');
                chatBox.scrollTop(chatBox[0].scrollHeight);
            }

            async function sendMessage() {
                const msg = (input.val() || '').trim();
                if (!msg) return;
                appendMsg('user', msg);
                history.push({ role: 'user', content: msg });
                input.val('');
                sendBtn.prop('disabled', true).text('Memproses...');

                try {
                    const response = await fetch('api_explore_ai.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            message: msg,
                            history: history.slice(-12)
                        })
                    });
                    const data = await response.json();
                    const reply = (data && data.reply) ? data.reply : 'Maaf, respons AI tidak tersedia.';
                    appendMsg('bot', reply);
                    history.push({ role: 'assistant', content: reply });
                } catch (err) {
                    appendMsg('bot', 'Gagal menghubungi layanan AI eksplorasi data.');
                } finally {
                    sendBtn.prop('disabled', false).text('Kirim');
                    input.focus();
                }
            }

            toggleBtn.on('click', function() {
                if (chatPanel.hasClass('open')) {
                    closeChat();
                } else {
                    openChat();
                }
            });
            closeBtn.on('click', closeChat);

            sendBtn.on('click', sendMessage);
            input.on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            clearBtn.on('click', function() {
                history = [];
                chatBox.html('<div class="ai-msg bot">Riwayat chat direset. Silakan tanyakan analisis data yang Anda butuhkan.</div>');
            });
            if (isExplorePage) {
                openChat();
            }
        });
    </script>
</body>
</html>
