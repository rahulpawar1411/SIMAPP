<?php
$src = __DIR__ . '/ReeferON_Template_Fields_and_IoT_Sensor_Questions.txt';
$outPath = __DIR__ . '/ReeferON_Template_Fields_and_IoT_Sensor_Questions.docx';
$text = file_get_contents($src);

function xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$paras = '';
foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
    $bold = $line !== '' && (
        str_starts_with($line, '====') ||
        str_starts_with($line, '####') ||
        str_starts_with($line, '----') ||
        str_starts_with($line, 'PART ') ||
        str_starts_with($line, 'A1.') ||
        str_starts_with($line, 'A2.') ||
        str_starts_with($line, 'A3.') ||
        str_starts_with($line, 'A4.') ||
        str_starts_with($line, 'B1.') ||
        str_starts_with($line, 'B2.') ||
        str_starts_with($line, 'B3.') ||
        str_starts_with($line, 'Q')
    );
    $sz = $bold ? 20 : 18;
    $rPr = '<w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:sz w:val="' . $sz . '"/><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/></w:rPr>';
    $paras .= '<w:p><w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($line === '' ? ' ' : $line) . '</w:t></w:r></w:p>';
}

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $paras
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
$zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->close();
echo "Wrote $outPath\n";
