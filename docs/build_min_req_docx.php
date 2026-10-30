<?php
/**
 * One-shot builder: ReeferON Hub minimum data requirements (.docx)
 */
$outPath = __DIR__ . '/ReeferON_Hub_Minimum_Data_Requirements.docx';

function xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p(string $text, string $style = 'Normal', bool $bold = false, int $size = 22): string {
    $rPr = '<w:rPr>';
    if ($bold) {
        $rPr .= '<w:b/>';
    }
    $rPr .= '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    return '<w:p><w:pPr><w:pStyle w:val="' . xml($style) . '"/></w:pPr>'
        . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p>';
}

function heading(string $text, int $level = 1): string {
    $size = $level === 1 ? 32 : 26;
    return p($text, 'Heading' . $level, true, $size);
}

function cell(string $text, string $fill = '', bool $bold = false, int $width = 2400): string {
    $shd = $fill !== ''
        ? '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/></w:tcPr>'
        : '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr>';
    $rPr = $bold ? '<w:rPr><w:b/><w:sz w:val="18"/></w:rPr>' : '<w:rPr><w:sz w:val="18"/></w:rPr>';
    return '<w:tc>' . $shd . '<w:p><w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r></w:p></w:tc>';
}

function row(array $cells, array $fills = [], bool $header = false): string {
    $xml = '<w:tr>';
    $widths = [2200, 1600, 4200];
    foreach ($cells as $i => $c) {
        $fill = $fills[$i] ?? ($header ? '1E3A8A' : '');
        $xml .= cell($c, $fill, $header || ($i === 0 && isset($fills[0])), $widths[$i] ?? 2400);
    }
    return $xml . '</w:tr>';
}

function table(array $rows, string $sectionFill): string {
    $headerFill = $sectionFill;
    $xml = '<w:tbl>'
        . '<w:tblPr><w:tblW w:w="8000" w:type="dxa"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:left w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:right w:val="single" w:sz="4" w:color="94A3B8"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="CBD5E1"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="CBD5E1"/>'
        . '</w:tblBorders></w:tblPr>';
    $xml .= row(['Field', 'Required?', 'Notes / Example'], [$headerFill, $headerFill, $headerFill], true);
    foreach ($rows as $r) {
        $reqFill = ($r[1] === 'YES') ? 'FEE2E2' : 'F1F5F9';
        $xml .= row([$r[0], $r[1], $r[2]], ['', $reqFill, '']);
    }
    return $xml . '</w:tbl>';
}

$storeRows = [
    ['store_name', 'YES', 'Outlet / store display name — e.g. Baskin Robbins - CG Road'],
    ['address', 'YES', 'Full street address'],
    ['city', 'YES', 'e.g. Ahmedabad (also used for auto location code + city GPS)'],
    ['pincode', 'YES', '6-digit PIN — e.g. 380009'],
    ['zone', 'Recommended', 'WEST / NORTH / SOUTH / EAST / CENTRAL'],
    ['state', 'Recommended', 'e.g. Gujarat'],
    ['location_type', 'Optional', 'Retail Store / etc.'],
    ['location_code', 'Optional', 'Blank = auto from city (AMD1). Used in Store ID + Freezer ID'],
    ['outlet_id', 'Optional', 'Blank = auto AMD1-380009-0001. Fill to reuse same store'],
    ['customer_name', 'Recommended', 'Brand / customer — e.g. Baskin Robbins India'],
    ['distributor_name', 'Optional', 'Distributor if any'],
    ['contact_person', 'Recommended', 'Operator / manager name'],
    ['phone_number', 'Recommended', '10-digit Indian mobile'],
    ['email', 'Optional', 'Contact email'],
    ['aadhar_number', 'Optional', '12-digit Aadhaar (if collected)'],
    ['fssai_number', 'Optional', 'FSSAI licence'],
    ['gst_number', 'Optional', 'GSTIN'],
    ['latitude + longitude', 'Optional', 'Both together. Blank = city-center GPS (map still shows). Exact pin later via Store Front GPS'],
    ['image_store_front', 'YES', 'REQUIRED — filename in uploads/import/ e.g. store_1_front.jpg'],
    ['image_aadhar_card', 'Optional', 'Aadhaar front filename'],
    ['image_aadhar_back', 'Optional', 'Aadhaar back filename'],
];

$freezerRows = [
    ['freezer_type', 'YES', 'FULL NAME — e.g. Deep Freezer (system → DF in Unique ID)'],
    ['company', 'YES', 'FULL NAME — e.g. Rockwell (system → RW in Unique ID)'],
    ['model_number', 'YES', 'e.g. SFR550DDU'],
    ['installation_date', 'Recommended', 'YYYY-MM-DD. Blank = today. Becomes MMYY in Unique ID'],
    ['unique_freezer_id', 'Optional', 'Blank = auto (random unit seq). Do not fill unit_sequence'],
    ['capacity_litres', 'Recommended', 'e.g. 550'],
    ['freezer_condition', 'Optional', 'WORKING CONDITION (default)'],
    ['status', 'Optional', 'Active (default)'],
    ['image_left_side', 'YES', 'REQUIRED — freezer left photo filename'],
    ['image_right_side', 'YES', 'REQUIRED — freezer right photo filename'],
    ['notes', 'Optional', 'Any remarks'],
];

$iotRows = [
    ['iot_serial', 'Optional*', 'If IoT is fitted: e.g. RF-IOT-99210. Blank = no IoT device'],
    ['iot_imei', 'Optional', 'IMEI / MAC if available'],
    ['iot_type', 'Optional', 'Default: Temperature & GPS'],
    ['iot_owner', 'Optional', 'ReeferON Own Device / Outsourced Device'],
    ['iot_model', 'Optional', 'Default: RF-T100'],
    ['image_iot_device', 'YES if IoT', 'REQUIRED when iot_serial is filled'],
];

$body = '';
$body .= p('ReeferON SIM — Hub Data Requirements', 'Title', true, 36);
$body .= p('For field / ops users: minimum fields needed for Store + Freezer + IoT import', 'Normal', false, 20);
$body .= p('Document date: ' . date('d M Y') . '  |  Template: Hub Excel / CSV v3', 'Normal', false, 18);
$body .= p('');

$body .= heading('1. How to use this list', 1);
$body .= p('• YES = must fill — import will fail without it.');
$body .= p('• Recommended = strongly needed for map, KYC, photos, Unique ID quality.');
$body .= p('• Optional = can leave blank; system uses defaults or skips.');
$body .= p('• Photos are REQUIRED (not optional): store front + freezer left + freezer right.');
$body .= p('• Put files in uploads/import/ and write exact file name in Excel (not full path).');
$body .= p('• Full names for freezer_type & company — system converts to short codes (DF, RW) for Unique ID.');
$body .= p('• Latitude AND Longitude work as a pair. Leave both blank for auto city GPS.');
$body .= p('');

$body .= heading('2. STORE — minimum & recommended', 1);
$body .= p('Header colour in Excel: BLUE', 'Normal', true, 20);
$body .= table($storeRows, '93C5FD');
$body .= p('');
$body .= p('Minimum Store set (must): store_name + address + city + pincode + image_store_front', 'Normal', true, 20);
$body .= p('');

$body .= heading('3. FREEZER — minimum & recommended', 1);
$body .= p('Header colour in Excel: YELLOW / AMBER', 'Normal', true, 20);
$body .= table($freezerRows, 'FCD34D');
$body .= p('');
$body .= p('Minimum Freezer set (must): freezer_type + company + model_number + image_left_side + image_right_side', 'Normal', true, 20);
$body .= p('Example Unique ID: DF-RW-SFR550DDU-AMD1-0326-380009-KXM472 (last part auto-random)', 'Normal', false, 18);
$body .= p('');

$body .= heading('4. IOT DEVICE — when device is fitted', 1);
$body .= p('Header colour in Excel: GREEN', 'Normal', true, 20);
$body .= table($iotRows, '86EFAC');
$body .= p('');
$body .= p('* IoT is optional per freezer. If iot_serial is filled, image_iot_device is also required.', 'Normal', true, 20);
$body .= p('');

$body .= heading('5. One-line checklist for users', 1);
$body .= p('STORE: Name, Address, City, PIN, Store Front photo (required)');
$body .= p('FREEZER: Type (full), Company (full), Model, Left photo, Right photo (required)');
$body .= p('IOT (if any): Serial + IoT photo (required together)');
$body .= p('GPS: leave blank OK — city map shows; exact pin later via Store Front GPS in app');
$body .= p('');

$body .= heading('6. Sample (minimum) row', 1);
$body .= p('store_name: Baskin Robbins - CG Road');
$body .= p('address: Shop 12, CG Road');
$body .= p('city: Ahmedabad');
$body .= p('pincode: 380009');
$body .= p('image_store_front: store_1_front.jpg');
$body .= p('freezer_type: Deep Freezer');
$body .= p('company: Rockwell');
$body .= p('model_number: SFR550DDU');
$body .= p('image_left_side: freezer_1_left.jpg');
$body .= p('image_right_side: freezer_1_right.jpg');
$body .= p('iot_serial: (blank if no device)');
$body .= p('latitude / longitude: (blank)');
$body .= p('');
$body .= p('— End of document — Share this with ops / Excel data providers.', 'Normal', false, 18);

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr>'
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
