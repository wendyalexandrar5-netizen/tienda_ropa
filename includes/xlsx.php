<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Generador mínimo de hojas de cálculo Excel (.xlsx) en PHP puro.
 *
 * Un .xlsx es un archivo ZIP con varios XML (formato Office Open XML). Este módulo
 * arma esos XML y los empaqueta sin librerías externas ni la extensión "zip", así
 * funciona en cualquier instalación de XAMPP.
 *
 * Cada hoja se describe así:
 *   [
 *     'nombre'   => 'Pedidos',
 *     'columnas' => [['Código', 'texto', 14], ['Total', 'moneda', 14], ...],  // título, tipo, ancho
 *     'filas'    => [['FC-000001', 229800], ...],
 *     'totales'  => ['Total', null, 229800],        // opcional: fila final en negrita
 *   ]
 * Tipos: texto, entero, moneda, fecha (Y-m-d), fechahora (Y-m-d H:i:s).
 *
 * Seguridad: todos los textos se escriben como "inlineStr" (nunca como fórmula),
 * por lo que un valor como "=HYPERLINK(...)" no se ejecuta al abrir el archivo.
 */

// Índices de estilo definidos en xlsx_estilos()
const XLSX_ESTILO = [
    'texto' => 0, 'encabezado' => 1, 'moneda' => 2, 'entero' => 3, 'fechahora' => 4, 'fecha' => 5,
    'total_texto' => 6, 'total_moneda' => 7, 'total_entero' => 8,
];

/** Envía el libro al navegador como descarga y termina la ejecución. */
function enviar_xlsx(string $nombreArchivo, array $hojas): void
{
    $contenido = generar_xlsx($hojas);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombreArchivo) . '"');
    header('Content-Length: ' . strlen($contenido));
    header('Cache-Control: no-store');
    echo $contenido;
    exit;
}

/** Devuelve el contenido binario del archivo .xlsx. */
function generar_xlsx(array $hojas): string
{
    $archivos = [
        '[Content_Types].xml' => xlsx_content_types(count($hojas)),
        '_rels/.rels'         => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>',
        'docProps/core.xml'   => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>' . xlsx_xml((string) ajuste('nombre_tienda', 'FIRE CAT')) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>',
        'xl/workbook.xml'     => xlsx_workbook($hojas),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels(count($hojas)),
        'xl/styles.xml'       => xlsx_estilos(),
    ];
    foreach (array_values($hojas) as $i => $hoja) {
        $archivos['xl/worksheets/sheet' . ($i + 1) . '.xml'] = xlsx_hoja($hoja);
    }
    return zip_empaquetar($archivos);
}

function xlsx_xml(string $texto): string
{
    // Elimina caracteres de control no válidos en XML y escapa los especiales.
    $texto = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $texto) ?? '';
    return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Letra de columna de Excel: 0 → A, 25 → Z, 26 → AA. */
function xlsx_columna(int $indice): string
{
    $letra = '';
    for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $letra = chr(65 + ($n - 1) % 26) . $letra;
    }
    return $letra;
}

/** Convierte "Y-m-d[ H:i:s]" al número de serie de fecha de Excel. */
function xlsx_fecha_serial(string $valor): ?float
{
    try {
        $d = new DateTime($valor, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    return $d->getTimestamp() / 86400 + 25569;
}

function xlsx_celda(string $ref, $valor, string $tipo, bool $total = false): string
{
    if ($valor === null || $valor === '') {
        return $total ? '<c r="' . $ref . '" s="' . XLSX_ESTILO['total_texto'] . '"/>' : '';
    }
    if (in_array($tipo, ['moneda', 'entero'], true) && is_numeric($valor)) {
        $estilo = XLSX_ESTILO[($total ? 'total_' : '') . $tipo];
        return '<c r="' . $ref . '" s="' . $estilo . '"><v>' . (0 + $valor) . '</v></c>';
    }
    if (in_array($tipo, ['fecha', 'fechahora'], true) && ($serial = xlsx_fecha_serial((string) $valor)) !== null) {
        return '<c r="' . $ref . '" s="' . XLSX_ESTILO[$tipo] . '"><v>' . round($serial, 10) . '</v></c>';
    }
    $estilo = $total ? XLSX_ESTILO['total_texto'] : XLSX_ESTILO['texto'];
    return '<c r="' . $ref . '" t="inlineStr" s="' . $estilo . '"><is><t xml:space="preserve">' . xlsx_xml((string) $valor) . '</t></is></c>';
}

function xlsx_hoja(array $hoja): string
{
    $columnas = $hoja['columnas'];
    $cols = '';
    foreach ($columnas as $i => $c) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) ($c[2] ?? 15) . '" customWidth="1"/>';
    }

    $filasXml = '<row r="1">';
    foreach ($columnas as $i => $c) {
        $filasXml .= '<c r="' . xlsx_columna($i) . '1" t="inlineStr" s="' . XLSX_ESTILO['encabezado'] . '"><is><t>' . xlsx_xml($c[0]) . '</t></is></c>';
    }
    $filasXml .= '</row>';

    $n = 1;
    foreach ($hoja['filas'] as $fila) {
        $n++;
        $filasXml .= '<row r="' . $n . '">';
        foreach (array_values($fila) as $i => $valor) {
            $filasXml .= xlsx_celda(xlsx_columna($i) . $n, $valor, $columnas[$i][1] ?? 'texto');
        }
        $filasXml .= '</row>';
    }
    if (!empty($hoja['totales'])) {
        $n++;
        $filasXml .= '<row r="' . $n . '">';
        foreach (array_values($hoja['totales']) as $i => $valor) {
            $filasXml .= xlsx_celda(xlsx_columna($i) . $n, $valor, $columnas[$i][1] ?? 'texto', true);
        }
        $filasXml .= '</row>';
    }

    $ultima = xlsx_columna(max(0, count($columnas) - 1));
    $filtro = count($hoja['filas']) > 0 ? '<autoFilter ref="A1:' . $ultima . (1 + count($hoja['filas'])) . '"/>' : '';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $ultima . $n . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . $filasXml . '</sheetData>'
        . $filtro
        . '</worksheet>';
}

function xlsx_estilos(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="3">'
        . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0"/>'
        . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/>'
        . '<numFmt numFmtId="166" formatCode="dd/mm/yyyy"/>'
        . '</numFmts>'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0E0E0E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFE500"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                    // 0 texto
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'        // 1 encabezado
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'            // 2 moneda
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'              // 3 entero
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'            // 4 fecha y hora
        . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'            // 5 fecha
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'        // 6 total texto
        . '<xf numFmtId="164" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>' // 7 total moneda
        . '<xf numFmtId="3" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'   // 8 total entero
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function xlsx_content_types(int $hojas): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    for ($i = 1; $i <= $hojas; $i++) {
        $x .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    return $x . '</Types>';
}

function xlsx_workbook(array $hojas): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $usados = [];
    foreach (array_values($hojas) as $i => $h) {
        // Excel: máx. 31 caracteres, sin : \ / ? * [ ] y nombres únicos
        $nombre = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', (string) $h['nombre']) ?: 'Hoja', 0, 31);
        while (in_array(mb_strtolower($nombre), $usados, true)) {
            $nombre = mb_substr($nombre, 0, 28) . ' ' . ($i + 1);
        }
        $usados[] = mb_strtolower($nombre);
        $x .= '<sheet name="' . xlsx_xml($nombre) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
    }
    return $x . '</sheets></workbook>';
}

function xlsx_workbook_rels(int $hojas): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $hojas; $i++) {
        $x .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $x .= '<Relationship Id="rId' . ($hojas + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    return $x . '</Relationships>';
}

/**
 * Empaqueta archivos en formato ZIP (compresión "deflate" de zlib, incluida en PHP).
 * @param array<string, string> $archivos ruta interna => contenido
 */
function zip_empaquetar(array $archivos): string
{
    $datos = '';
    $directorio = '';
    [$hora, $fecha] = zip_fecha_dos(time());
    foreach ($archivos as $nombre => $contenido) {
        $crc = crc32($contenido);
        $comprimido = function_exists('gzdeflate') ? gzdeflate($contenido, 6) : false;
        $metodo = $comprimido === false ? 0 : 8;
        $cuerpo = $comprimido === false ? $contenido : $comprimido;
        $desplazamiento = strlen($datos);

        $cabecera = pack('vvvvvVVVvv', 20, 0x0800, $metodo, $hora, $fecha, $crc, strlen($cuerpo), strlen($contenido), strlen($nombre), 0);
        $datos .= "PK\x03\x04" . $cabecera . $nombre . $cuerpo;
        $directorio .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $metodo, $hora, $fecha, $crc,
                strlen($cuerpo), strlen($contenido), strlen($nombre), 0, 0, 0, 0, 0, $desplazamiento) . $nombre;
    }
    $fin = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($archivos), count($archivos), strlen($directorio), strlen($datos), 0);
    return $datos . $directorio . $fin;
}

/** Fecha y hora en el formato de MS-DOS que usa ZIP. */
function zip_fecha_dos(int $t): array
{
    $d = getdate($t);
    $hora  = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
    $fecha = (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
    return [$hora, $fecha];
}
