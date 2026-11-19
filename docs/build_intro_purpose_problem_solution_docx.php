<?php
/**
 * Word brief: Introduction · Purpose · Problem · Solution · Features · Store Profile
 * + 6 application sections (data each area holds). No repeated bullets across sections.
 */
$outPath = __DIR__ . '/ReeferON_DF_Briefing_with_6_App_Sections.docx';

function xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p(string $text, bool $bold = false, int $size = 20, string $color = '0F172A', string $align = 'left', int $spaceAfter = 80, int $spaceBefore = 0): string {
    $rPr = '<w:rPr>';
    if ($bold) {
        $rPr .= '<w:b/>';
    }
    $rPr .= '<w:color w:val="' . $color . '"/>'
        . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    $jc = $align !== 'left' ? '<w:jc w:val="' . $align . '"/>' : '';
    return '<w:p><w:pPr>' . $jc
        . '<w:spacing w:before="' . $spaceBefore . '" w:after="' . $spaceAfter . '" w:line="276" w:lineRule="auto"/>'
        . '</w:pPr><w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p>';
}

function mixedP(array $runs, int $size = 20, int $spaceAfter = 80, string $align = 'left', int $spaceBefore = 0): string {
    $jc = $align !== 'left' ? '<w:jc w:val="' . $align . '"/>' : '';
    $xml = '<w:p><w:pPr>' . $jc
        . '<w:spacing w:before="' . $spaceBefore . '" w:after="' . $spaceAfter . '" w:line="276" w:lineRule="auto"/>'
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

function bullet(string $text, int $size = 19): string {
    return '<w:p><w:pPr>'
        . '<w:pStyle w:val="ListParagraph"/>'
        . '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>'
        . '<w:spacing w:before="20" w:after="40" w:line="270" w:lineRule="auto"/>'
        . '<w:ind w:left="360"/>'
        . '</w:pPr><w:r><w:rPr><w:color w:val="334155"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
        . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p>';
}

function heading(string $text): string {
    return p($text, true, 24, '1E3A8A', 'left', 60, 160);
}

$body = '';

$body .= p('ReeferON DF', true, 36, '1E3A8A', 'center', 20);
$body .= p('Deep Freezer Asset & Store Profile Management', false, 20, '475569', 'center', 40);
$body .= p('Introduction · Purpose · Problem · Solution · Features · Store Profile · 6 App Sections', false, 16, '64748B', 'center', 160);

/* ---------- 1. Introduction ---------- */
$body .= heading('1. Introduction');
$body .= mixedP([
    ['t' => 'ReeferON DF is a mobile-friendly web application for managing deep freezers in the field. '
        . 'Each freezer is linked to a unique QR tag and a complete store + freezer profile — address, photos, GPS, asset status, and history — so teams can find, verify, and update assets without paper files or scattered spreadsheets.', 'c' => '334155'],
], 20, 100);

/* ---------- 2. Purpose ---------- */
$body .= heading('2. Purpose');
$body .= mixedP([
    ['t' => 'The purpose of ReeferON DF is to give one live system where field technicians and admins can:', 'c' => '334155'],
], 20, 40);
$body .= bullet('Register and track freezers by zone and city with a unique ID / QR.');
$body .= bullet('Capture proof on site (store front, freezer sides, SIM/IoT photos, and GPS).');
$body .= bullet('Run asset (Pass / Fail / Pending) and follow up due or failed units.');
$body .= bullet('Control who can edit what — with approvals, role access, and an Undo window for mistakes.');
$body .= bullet('Export lists and print QR stickers when operations or audits need them.');

/* ---------- 3. Problem ---------- */
$body .= heading('3. Problem');
$body .= mixedP([
    ['t' => 'Before a system like this, freezer data often sits in Excel sheets, WhatsApp photos, and notebooks. That creates real gaps:', 'c' => '334155'],
], 20, 40);
$body .= bullet('No single source of truth — the same freezer may appear with different IDs or missing store details.');
$body .= bullet('Hard to prove installation or visit history (photos and GPS are not tied to one profile).');
$body .= bullet('asset due dates and failed checks get lost; follow-up depends on memory, not a work list.');
$body .= bullet('Anyone with a shared sheet can change data; there is weak role control and no safe undo.');
$body .= bullet('Zone teams cannot be limited to their area; managers struggle to approve field edits or print blank tags in bulk.');

/* ---------- 4. Solution ---------- */
$body .= heading('4. Solution');
$body .= mixedP([
    ['t' => 'ReeferON DF solves this with one digital workflow: Blank QR → Scan & register → Store/freezer profile with photos & GPS → asset on site → Admin oversight (approvals, activity, export).', 'c' => '334155'],
], 20, 40);
$body .= bullet('Unique tag codes (e.g. RO-ZONE-XXXXXX) so every unit has one identity from print to install.');
$body .= bullet('Role-based login: Super Admin (full), Technician (assigned zone/city), Viewer (read-only).');
$body .= bullet('Password-gated sensitive actions and toast Undo for recent user / profile / approval changes.');
$body .= bullet('Approvals for technician edit access; asset Activity and map views for pending / done / failed work.');
$body .= bullet('Import / Export (CSV & Excel) and System Logs for audit and support.');

/* ---------- 5. Features (no repeat of purpose/solution bullets) ---------- */
$body .= heading('5. Key Features');
$body .= mixedP([
    ['t' => 'Product capabilities (listed once — not repeating Purpose / Solution points above):', 'c' => '334155'],
], 20, 40);
$body .= bullet('Blank QR inventory — generate by zone, filter unused/assigned, print Logo + QR only.');
$body .= bullet('Live Unique Freezer ID preview while filling type / brand / model / location / PIN.');
$body .= bullet('Camera capture with date stamp, gallery options where needed, and location share after GPS save.');
$body .= bullet('Profiles list with asset tabs (All / OK / Pending / Issue), search, filters, and mobile card layout.');
$body .= bullet('Field Home for technicians — totals, asset alerts, and open edit unlocks in one place.');
$body .= bullet('Manage Users — create accounts, assign zone/city, On/Off, delete with admin password confirm.');
$body .= bullet('Secure stack — session + JWT cookie, bcrypt passwords, CSRF on forms and APIs.');

/* ---------- 6. Store Profile help ---------- */
$body .= heading('6. How the Store Profile Helps');
$body .= mixedP([
    ['t' => 'The Store Profile is the “home” record attached to each freezer. It is what makes field visits, asset, and audits useful — not just a freezer serial number.', 'c' => '334155'],
], 20, 40);
$body .= bullet('Know the outlet: store name, customer / distributor, contact person, phone, and address in one screen.');
$body .= bullet('Compliance fields in one place: Aadhaar (front/back images), FSSAI, GST — ready for checks without hunting files.');
$body .= bullet('Exact place on the map: GPS latitude / longitude, accuracy, and a shareable location link for the team.');
$body .= bullet('Photo proof of the store front linked to the same profile as the freezer and asset visit.');
$body .= bullet('Outlet ID and zone/city scoping so admins filter assets and technicians only see their assigned area.');
$body .= bullet('When a freezer moves to a new store, the move can go through approval — the profile keeps a clear trail instead of silent overwrites.');
$body .= bullet('Exports and QR print pull from the same profile data — stickers and Excel match what is live in the app.');

$body .= p(
    'In short: the Store Profile turns each freezer into a complete outlet record — who owns it, where it sits, what was photographed, and when asset was done — so operations, audits, and field teams work from the same truth.',
    false,
    20,
    '1E3A8A',
    'left',
    100,
    80
);

/* ---------- 7. Six application sections (data focus) ---------- */
$body .= heading('7. Six Application Sections — What Data Each Holds');
$body .= mixedP([
    ['t' => 'These are six different areas of the live app. Each owns its own data job (no repeat of Purpose / Features lists above).', 'c' => '334155'],
], 20, 50);

$body .= mixedP([
    ['t' => 'Section A — Dashboard / Field Home', 'b' => true, 'c' => '0F766E'],
], 20, 20, 'left', 60);
$body .= bullet('Admin Dashboard: totals for freezers & outlets, asset done / pending / failed counts, zone and city filters.');
$body .= bullet('Technician Home: my installs, today’s work count, asset due alerts, and open edit-unlock notices.');

$body .= mixedP([
    ['t' => 'Section B — Freezer & Store Profiles (Assets)', 'b' => true, 'c' => '0F766E'],
], 20, 20, 'left', 60);
$body .= bullet('Searchable list of assets with asset tabs (All / OK / Pending / Issue) and gap filters (GPS / photos).');
$body .= bullet('Profile detail: Unique Freezer ID, type/brand/model, install date, store link, photos, activity history, print/export.');

$body .= mixedP([
    ['t' => 'Section C — QR Management (Blank Tags)', 'b' => true, 'c' => '0F766E'],
], 20, 20, 'left', 60);
$body .= bullet('Tag inventory data: tag code, zone, batch, unused vs assigned, linked freezer when used.');
$body .= bullet('Print data: Logo + QR only (no freezer text until the tag is registered on site).');

$body .= mixedP([
    ['t' => 'Section D — asset Activity', 'b' => true, 'c' => '0F766E'],
], 20, 20, 'left', 60);
$body .= bullet('Pending due list + completed Pass/Fail rows with date range, same/new store type, technician, and result.');
$body .= bullet('Row detail + Excel/CSV export of the filtered asset table for audits.');

$body .= mixedP([
    ['t' => 'Section E — Approvals & Manage Users', 'b' => true, 'c' => '0F766E'],
], 20, 20, 'left', 60);
$body .= bullet('Approvals data: pending edit / store-move requests, reviewer notes, approve/reject decisions, bulk actions.');
$body .= bullet('Users data: name, email, role, phone, assigned zone/city, active/inactive — edit / On-Off / delete with admin password.');

$body .= mixedP([
    ['t' => 'Section F — Import / Export & System Logs', 'b' => true, 'c' => '0F766E'],
], 20, 20, 'left', 60);
$body .= bullet('Import/Export: bulk store–freezer rows via CSV/Excel templates; full asset dumps from Profiles.');
$body .= bullet('System Logs: daily event files (login, audit, failed actions, errors) with level filter, search, and pagination.');

$body .= p(
    'Together these six sections cover live operations (Home/Profiles/QR), verification (asset), control (Approvals/Users), and records (Export/Logs) — each with its own data, not the same points repeated.',
    false,
    20,
    '1E3A8A',
    'left',
    120,
    80
);

$body .= p('ReeferON DF  ·  Internal briefing document  ·  English', false, 14, '94A3B8', 'center', 0);

$numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:abstractNum w:abstractNumId="0">'
    . '<w:multiLevelType w:val="hybridMultilevel"/>'
    . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/>'
    . '<w:lvlText w:val="•"/><w:lvlJc w:val="left"/>'
    . '<w:pPr><w:ind w:left="360" w:hanging="180"/></w:pPr>'
    . '</w:lvl></w:abstractNum>'
    . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
    . '</w:numbering>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
    . '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
    . '</Relationships>';

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>'
    . $body
    . '<w:sectPr>'
    . '<w:pgSz w:w="12240" w:h="15840"/>'
    . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="0" w:footer="0"/>'
    . '</w:sectPr>'
    . '</w:body></w:document>';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive not available\n");
    exit(1);
}

@unlink($outPath);
$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create {$outPath}\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/_rels/document.xml.rels', $docRels);
$zip->addFromString('word/numbering.xml', $numbering);
$zip->addFromString('word/document.xml', $document);
$zip->close();
echo "Created: {$outPath}\n";
exit(0);
