<?php
declare(strict_types=1);

function xlsx_escape_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[^\P{C}\t\n]/u', '', $value) ?? $value;

    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_column_name(int $index): string
{
    $name = '';

    while ($index >= 0) {
        $name = chr(($index % 26) + 65) . $name;
        $index = intdiv($index, 26) - 1;
    }

    return $name;
}

function xlsx_sheet_name(string $name): string
{
    $name = preg_replace('/[\\\\\\/*?:\\[\\]]/', ' ', $name) ?? $name;
    $name = trim($name);

    if ($name === '') {
        $name = 'Hoja 1';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 31, 'UTF-8');
    }

    return substr($name, 0, 31);
}

function xlsx_column_width_xml(array $headers, array $rows): string
{
    $columnCount = count($headers);
    $maxLengths = [];

    for ($i = 0; $i < $columnCount; $i++) {
        $maxLengths[$i] = strlen((string)$headers[$i]);
    }

    foreach ($rows as $row) {
        for ($i = 0; $i < $columnCount; $i++) {
            $value = isset($row[$i]) ? (string)$row[$i] : '';
            $maxLengths[$i] = max($maxLengths[$i], strlen($value));
        }
    }

    $colsXml = '<cols>';
    for ($i = 0; $i < $columnCount; $i++) {
        $width = min(max($maxLengths[$i] + 4, 12), 40);
        $colIndex = $i + 1;
        $colsXml .= '<col min="' . $colIndex . '" max="' . $colIndex . '" width="' . $width . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    return $colsXml;
}

function xlsx_cell_xml(string $cellReference, string $value, int $styleId = 0): string
{
    return '<c r="' . $cellReference . '" t="inlineStr" s="' . $styleId . '"><is><t>'
        . xlsx_escape_text($value)
        . '</t></is></c>';
}

function xlsx_sheet_xml(string $sheetName, array $headers, array $rows): string
{
    $allRows = array_merge([$headers], $rows);
    $rowCount = count($allRows);
    $columnCount = max(1, count($headers));
    $lastColumn = xlsx_column_name($columnCount - 1);
    $dimension = 'A1:' . $lastColumn . $rowCount;
    $sheetDataXml = '';

    foreach ($allRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $sheetDataXml .= '<row r="' . $excelRow . '">';

        for ($colIndex = 0; $colIndex < $columnCount; $colIndex++) {
            $cellReference = xlsx_column_name($colIndex) . $excelRow;
            $cellValue = isset($row[$colIndex]) ? (string)$row[$colIndex] : '';
            $sheetDataXml .= xlsx_cell_xml($cellReference, $cellValue, $rowIndex === 0 ? 1 : 0);
        }

        $sheetDataXml .= '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . xlsx_column_width_xml($headers, $rows)
        . '<sheetData>' . $sheetDataXml . '</sheetData>'
        . '<autoFilter ref="' . $dimension . '"/>'
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';
}

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E4FD0"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function xlsx_dos_datetime(int $timestamp): array
{
    $date = getdate($timestamp);
    $year = max(1980, (int)$date['year']);
    $dosTime = ((int)$date['hours'] << 11) | ((int)$date['minutes'] << 5) | (int)floor((int)$date['seconds'] / 2);
    $dosDate = (($year - 1980) << 9) | ((int)$date['mon'] << 5) | (int)$date['mday'];

    return [$dosTime, $dosDate];
}

function xlsx_zip_archive(array $files): string
{
    $data = '';
    $centralDirectory = '';
    $offset = 0;
    [$dosTime, $dosDate] = xlsx_dos_datetime(time());

    foreach ($files as $fileName => $content) {
        $fileName = str_replace('\\', '/', $fileName);
        $content = (string)$content;
        $crc = (int)sprintf('%u', crc32($content));
        $size = strlen($content);
        $nameLength = strlen($fileName);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0
        );

        $data .= $localHeader . $fileName . $content;

        $centralDirectory .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $fileName;

        $offset += strlen($localHeader) + $nameLength + $size;
    }

    $centralDirectorySize = strlen($centralDirectory);
    $entryCount = count($files);

    $endOfCentralDirectory = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $entryCount,
        $entryCount,
        $centralDirectorySize,
        $offset,
        0
    );

    return $data . $centralDirectory . $endOfCentralDirectory;
}

function build_xlsx_binary(string $sheetName, array $headers, array $rows): string
{
    $sheetName = xlsx_sheet_name($sheetName);
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>KOSTICK</dc:creator>'
            . '<cp:lastModifiedBy>KOSTICK</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
            . '</cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>KOSTICK</Application>'
            . '<DocSecurity>0</DocSecurity>'
            . '<ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>' . xlsx_escape_text($sheetName) . '</vt:lpstr></vt:vector></TitlesOfParts>'
            . '<Company></Company>'
            . '<LinksUpToDate>false</LinksUpToDate>'
            . '<SharedDoc>false</SharedDoc>'
            . '<HyperlinksChanged>false</HyperlinksChanged>'
            . '<AppVersion>1.0</AppVersion>'
            . '</Properties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr/>'
            . '<bookViews><workbookView xWindow="240" yWindow="15" windowWidth="16095" windowHeight="9660"/></bookViews>'
            . '<sheets><sheet name="' . xlsx_escape_text($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => xlsx_sheet_xml($sheetName, $headers, $rows),
    ];

    return xlsx_zip_archive($files);
}

function output_xlsx_download(string $fileName, string $sheetName, array $headers, array $rows): void
{
    $binary = build_xlsx_binary($sheetName, $headers, $rows);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($binary));

    echo $binary;
    exit;
}
?>
