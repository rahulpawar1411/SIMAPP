<?php
/**
 * One-page Word overview: ReeferON DF features + field / admin working process.
 */
$outPath = __DIR__ . '/ReeferON_DF_One_Page_Overview.docx';

function xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p(string $text, bool $bold = false, int $size = 16, string $color = '0F172A', string $align = 'left', int $spaceAfter = 40): string {
    $rPr = '<w:rPr>';
    if ($bold) {
        $rPr .= '<w:b/>';
    }
    $rPr .= '<w:color w:val="' . $color . '"/>'
        . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    $jc = $align !== 'left' ? '<w:jc w:val="' . $align . '"/>' : '';
    return '<w:p><w:pPr>' . $jc
        . '<w:spacing w:before="0" w:after="' . $spaceAfter . '" w:line="240" w:lineRule="auto"/>'
        . '</w:pPr><w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p>';
}

function mixedP(array $runs, int $size = 15, int $spaceAfter = 36, string $align = 'left'): string {
    $jc = $align !== 'left' ? '<w:jc w:val="' . $align . '"/>' : '';
    $xml = '<w:p><w:pPr>' . $jc
        . '<w:spacing w:before="0" w:after="' . $spaceAfter . '" w:line="230" w:lineRule="auto"/>'
        . '</w:pPr>';
    foreach ($runs as $run) {
        $bold = !empty($run['b']);
        $color = $run['c'] ?? '0F172A';
        $rPr = '<w:rPr>';
        if ($bold) {
            $rPr .= '<w:b/>';
        }
        $rPr .= '<w:color w:val="' . $color . '"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
        $xml .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . xml((string)$run['t']) . '</w:t></w:r>';
    }
    return $xml . '</w:p>';
}

function cell(string $text, string $fill = '', bool $bold = false, int $width = 2340, string $color = '0F172A', int $size = 14): string {
    $shd = $fill !== ''
        ? '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/><w:vAlign w:val="center"/></w:tcPr>'
        : '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>';
    $rPr = '<w:rPr>';
    if ($bold) {
        $rPr .= '<w:b/>';
    }
    $rPr .= '<w:color w:val="' . $color . '"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    return '<w:tc>' . $shd . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/></w:pPr><w:r>' . $rPr
        . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p></w:tc>';
}

function table(array $rows, array $widths, string $headerFill = '1E3A8A'): string {
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="10080" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:left w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:right w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="CBD5E1"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="CBD5E1"/>'
        . '</w:tblBorders></w:tblPr>';
    foreach ($rows as $ri => $row) {
        $isHeader = $ri === 0;
        $xml .= '<w:tr>';
        foreach ($row as $ci => $text) {
            $fill = $isHeader ? $headerFill : '';
            $color = $isHeader ? 'FFFFFF' : '0F172A';
            $xml .= cell((string)$text, $fill, $isHeader || $ci === 0, $widths[$ci] ?? 2400, $color, $isHeader ? 14 : 13);
        }
        $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl>';
}

$body = '';

$body .= p('ReeferON DF — Deep Freezer Asset Management', true, 28, '1E3A8A', 'center', 20);
$body .= p('One-page overview · Features · Users · Field & Admin working process', false, 14, '64748B', 'center', 80);

$body .= mixedP([
    ['t' => 'What it is: ', 'b' => true, 'c' => '1E3A8A'],
    ['t' => 'Mobile + web app to register deep freezers with QR tags, capture store/freezer photos & GPS, run asset checks, and manage assets by zone/city.', 'c' => '334155'],
], 14, 60);

$body .= p('1) User roles — who does what', true, 18, '1E3A8A', 'left', 40);
$body .= table([
    ['Role', 'Access', 'Main work'],
    ['Super Admin', 'Full system', 'Users, Approvals, Blank QR, Import/Export, Logs, asset Activity, all zones'],
    ['Technician', 'Assigned zone/city', 'Scan QR → register / update store & freezer → photos + GPS → asset on site'],
    ['Viewer', 'View only', 'Dashboard & profiles view / print QR — no add, edit, asset, or user management'],
], [1600, 2200, 6280], '1E3A8A');

$body .= p('2) Field working process (Technician)', true, 18, '1E3A8A', 'left', 50);
$body .= mixedP([
    ['t' => '1. Login  →  2. Scan Blank QR / open asset  →  3. Fill store + freezer details  →  4. Capture photos (store / freezer / IoT-SIM)  →  5. Capture GPS  →  6. Save profile  →  7. Do asset (Pass/Fail) with photos & notes  →  8. Pending asset appears on Home / Profiles for follow-up.', 'c' => '334155'],
], 13, 40);
$body .= mixedP([
    ['t' => 'Rules: ', 'b' => true, 'c' => '0F172A'],
    ['t' => 'Technician sees only their zone/city. Edit of existing profiles may need Super Admin approval (password-gated unlock / 1 save window). asset due / failed assets show clear next action (Do asset / Re-verify).', 'c' => '475569'],
], 13, 60);

$body .= p('3) Admin powers & working process', true, 18, '1E3A8A', 'left', 40);
$body .= table([
    ['Area', 'Admin can…'],
    ['Users', 'Create / edit roles · On/Off · Delete (admin password) · Undo toast'],
    ['Approvals', 'Approve / reject technician edit-access & store-move requests · bulk actions'],
    ['Blank QR', 'Generate zone tags (RO-ZONE-XXXXXX) · filter · print selected / by zone'],
    ['Assets', 'View all zones · export CSV/Excel · print stickers · open any profile'],
    ['asset Activity', 'Pending / Complete / Failed history · filters · export · map'],
    ['System', 'Import/Export · System Logs · password-protected sensitive actions'],
], [2200, 7880], '0F766E');

$body .= p('4) Core product features (short)', true, 18, '1E3A8A', 'left', 50);
$body .= mixedP([
    ['t' => 'QR lifecycle · Store/freezer profile · Photos + GPS · asset Pass/Fail · Zone/city scope · Approvals + Undo · Blank QR print · Excel/CSV export · Mobile field UI · Role-based login (JWT + bcrypt).', 'c' => '334155'],
], 13, 50);

$body .= p('5) Simple flow map', true, 18, '1E3A8A', 'left', 40);
$body .= mixedP([
    ['t' => 'Admin generates Blank QR  →  Field scans & registers freezer  →  asset on site  →  Admin monitors Approvals / asset Activity / Assets  →  Export or print when needed.', 'c' => '334155'],
], 13, 70);

$body .= p('ReeferON DF  ·  Confidential internal overview  ·  Fits 1 page', false, 12, '94A3B8', 'center', 0);

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>'
    . $body
    . '<w:sectPr>'
    . '<w:pgSz w:w="12240" w:h="15840"/>'
    . '<w:pgMar w:top="540" w:right="540" w:bottom="540" w:left="540" w:header="0" w:footer="0"/>'
    . '</w:sectPr>'
    . '</w:body></w:document>';

if (class_exists('ZipArchive')) {
    @unlink($outPath);
    $zip = new ZipArchive();
    if ($zip->open($outPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "Could not create {$outPath}\n");
        exit(1);
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();
    echo "Created: {$outPath}\n";
    exit(0);
}

fwrite(STDERR, "ZipArchive not available\n");
exit(1);
