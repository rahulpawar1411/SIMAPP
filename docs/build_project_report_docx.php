<?php
/**
 * Project report — Purpose, Problem, Solution, Features (paragraph style)
 */
$outPath = __DIR__ . '/ReeferON_DF_Project_Report_v3.docx';

function xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p(string $text, string $style = 'Normal', bool $bold = false, int $size = 22): string {
    $rPr = '<w:rPr>';
    if ($bold) {
        $rPr .= '<w:b/>';
    }
    $rPr .= '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    return '<w:p><w:pPr><w:pStyle w:val="' . xml($style) . '"/><w:spacing w:after="160"/></w:pPr>'
        . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p>';
}

function heading(string $text): string {
    return p($text, 'Heading1', true, 28);
}

$body = '';
$body .= p('ReeferON DF Application', 'Title', true, 36);
$body .= p('Project Report', 'Normal', true, 26);
$body .= p('Date: ' . date('d M Y'), 'Normal', false, 18);

$body .= heading('1. Purpose');
$body .= p(
    'This application is made to manage deep freezers placed at retail stores for ReeferON. '
    . 'Its purpose is to keep a clear record of which freezer is at which store, with store contact and location details. '
    . 'It also supports field checks so the team can confirm that the freezer is still at the outlet through KYC, photos, and GPS. '
    . 'Staff can open photos, fetch live device location, auto-fill map coordinates, and save that live location to the store profile. '
    . 'When a freezer moves to another store, the system keeps full history while the Unique Freezer ID stays the same. '
    . 'In short, this app gives one place for freezer identity, store link, QR scan, live location, and field verification.'
);

$body .= heading('2. Problem');
$body .= p(
    'Before this system, freezer tracking was hard and easy to get wrong. '
    . 'Freezers often move between stores, but paper or Excel records stay old or get lost. '
    . 'The same machine can receive different IDs in different sheets, so duplicate and mixed data grows over time. '
    . 'Field staff cannot quickly find the correct store profile when they are on site. '
    . 'There is also no strong proof that a visit or KYC was completed, such as photos, GPS, and date. '
    . 'Managers cannot see live numbers like total freezers, active stores, and KYC due list. '
    . 'Even if QR tags are used, they are not always linked to one stable Unique Freezer ID. '
    . 'Because of this, asset control stays weak, field work becomes slow, and audit trails remain poor.'
);

$body .= heading('3. Solution');
$body .= p(
    'We built a web application using PHP and MySQL that works on both phone and desktop. '
    . 'Every freezer gets one Unique Freezer ID, and this ID never changes. '
    . 'Blank QR tags are printed, stuck on the freezer, and then scanned in the field. '
    . 'If the tag is new, the staff creates the store and freezer profile. '
    . 'If the tag is already assigned, the app opens that freezer profile. '
    . 'Store details, photos, GPS, and contact information stay linked to the profile. '
    . 'Users can click store or map images, fetch the phone live location, see latitude and longitude auto-filled on the form and map, and save that live location for the store. '
    . 'KYC can be done from the profile, or after a scan when starting from the list. '
    . 'Photos and results are saved for each check. '
    . 'If a freezer moves, placement history is updated while the Freezer ID remains the same. '
    . 'The dashboard and system logs give managers a clear live view. '
    . 'The simple field flow is: print QR, scan, register or open profile, then complete KYC when due.'
);

$body .= heading('4. Features');
$body .= p(
    'The application supports secure login with roles for Super Admin, Technician, and Viewer. '
    . 'An operations dashboard shows freezer count, active outlets, and KYC done or due status. '
    . 'Unique Freezer IDs are generated automatically from type, brand, model, location, install date, and PIN. '
    . 'QR printing and camera scan are available so an unused tag creates a profile and an assigned tag opens the existing profile. '
    . 'Each store and freezer profile stores address, zone, city, PIN, photos, and contact details. '
    . 'Image click and map view help staff check store photos and location on screen. '
    . 'Live location capture reads GPS from the user device, auto-fills latitude and longitude, shows the pin on the map, and lets the user save that live location to the store record. '
    . 'KYC verification captures photos, GPS, pass or fail result, and the check cycle. '
    . 'Store history shows placement and KYC records in an Excel-style table. '
    . 'When a freezer is transferred, history is kept and the Unique Freezer ID does not change. '
    . 'Import and export support CSV and Excel for bulk work. '
    . 'System logs record login and key actions for admin review. '
    . 'The interface is mobile friendly so field teams can use it on a phone.'
);

$body .= p('— End of report —', 'Normal', false, 18);

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850"/></w:sectPr>'
    . '</w:body></w:document>';

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

$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write $outPath\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->close();

echo "Wrote: $outPath (" . filesize($outPath) . " bytes)\n";
