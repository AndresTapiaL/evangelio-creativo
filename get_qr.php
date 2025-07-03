<?php
/* get_qr.php  – descarga controlada de códigos QR */
declare(strict_types=1);
session_start();
require 'conexion.php';

/* ── A ──────────────────────────────────────────────────────────
   1)  Sólo vía POST + AJAX
-----------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;                         // acceso directo bloqueado
}

/* ------------------------------------------------------------------
   Garantiza que NADA se envíe antes de las cabeceras
   (elimina posibles BOM o espacios fuera de PHP).                   */
while (ob_get_level()) { ob_end_clean(); }  // cierra buffers heredados
ob_start();                                 // buffer nuevo, limpio

/* ── B ──────────────────────────────────────────────────────────
   2)  Usuario debe estar logeado y pertenecer al equipo 1
-----------------------------------------------------------------*/
if (empty($_SESSION['id_usuario'])) { http_response_code(403); exit; }
$uid = (int)$_SESSION['id_usuario'];
$ok  = $pdo->prepare('SELECT 1 FROM integrantes_equipos_proyectos
                       WHERE id_usuario=? AND id_equipo_proyecto=1
                         AND habilitado=1 LIMIT 1');
$ok->execute([$uid]);
if (!$ok->fetchColumn()) { http_response_code(403); exit; }

/* ── C ──────────────────────────────────────────────────────────
   3)  Sanitizar y validar HASH recibido (viene por POST ahora)
-----------------------------------------------------------------*/
$hash = preg_replace('/[^a-f0-9]/', '', $_POST['code'] ?? '');
if ($hash === '')              { http_response_code(400); exit; }
$q = $pdo->prepare('SELECT 1 FROM ticket_usuario
                     WHERE qr_codigo = ? LIMIT 1');
$q->execute([$hash]);
if (!$q->fetchColumn())        { http_response_code(404); exit; }

/* 4)  generar PNG en memoria y entregarlo */
require_once __DIR__.'/lib/phpqrcode/qrlib.php';

/* ── datos para construir un nombre de archivo legible ───────── */
$info = $pdo->prepare("
      SELECT tu.id_ticket_usuario,
             tu.nombre_completo,
             e.nombre_evento
        FROM ticket_usuario tu
        JOIN eventos_tickets et USING(id_evento_ticket)
        JOIN eventos         e  USING(id_evento)
       WHERE tu.qr_codigo = ?
       LIMIT 1");
$info->execute([$hash]);
$row = $info->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit; }

/* ░░░ 1.  Nombre de archivo con caracteres UTF-8  ░░░ */
function safeName(string $txt): string
{
    /*  – Permite letras acentuadas, eñes, espacios, etc.
         – Sólo elimina / \ : * ? " < > | y  NULL             */
    return preg_replace('/[\/\\\\:*?"<>|\x00]/', '', $txt);
}

$fileName = $row['id_ticket_usuario'].'___'          // ← id_ticket_usuario
          .safeName($row['nombre_completo']).'___'
          .safeName($row['nombre_evento']).'.svg';

/* ░░░ 2.  Cabeceras Content-Disposition – UTF-8 sin fallback raro ░░░ */

/* --- a) quitar comillas y caracteres prohibidos ----------------- */
$utf8 = str_replace('"', '', $fileName);              // para filename*=
$utf8Enc = rawurlencode($utf8);                       // RFC 5987

/* --- b)  solo enviamos filename*=  (suficiente para Chrome/Edge) */
$disp = "inline; filename*=UTF-8''{$utf8Enc}";

$ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $utf8);
$ascii = preg_replace('/[^A-Za-z0-9_.-]/', '_', $ascii); // limpia '
$disp  = 'inline; filename="'.$ascii.'"';
$disp .= "; filename*=UTF-8''{$utf8Enc}";

/* URL que codifica el QR */
$url = 'http://localhost/PW%20EC_Antes/scan_qr.php?code='.$hash;   // ← ajusta dominio

try {
    ob_start();
    $pixelSize = 8;                    // antes 4
    /* 2º parámetro = null  ⇒  la librería envía el PNG directamente al buffer */
    QRcode::png($url, null, QR_ECLEVEL_H, $pixelSize, 2);
    $png = ob_get_clean();
} catch (Throwable $e) {
    http_response_code(500);
    header('X-QR-Error: '.$e->getMessage());
    exit;
}

/* --- ► GENERAR SVG con el QR + logo centrado  ------------------------ */

$qrImg   = imagecreatefromstring($png);       // para saber el tamaño real
$qrSize  = imagesx($qrImg);                   // ancho = alto (cuadrado)
imagedestroy($qrImg);

/* QR como data-URI */
$qrBase64   = base64_encode($png);
$qrDataUri  = 'data:image/png;base64,'.$qrBase64;

/* LOGO opcional */
$logoDataUri = '';
$logoNewW = $logoNewH = $logoX = $logoY = 0;

$logoPath = __DIR__.'/images/LogoEC.png';
if (is_file($logoPath)) {
    $logoBin = file_get_contents($logoPath);
    $logoBase64 = base64_encode($logoBin);
    $logoDataUri = 'data:image/png;base64,'.$logoBase64;

    [$logoW, $logoH] = getimagesizefromstring($logoBin);

    $scale   = 0.25;                       // 25 % del ancho del QR
    $logoNewW = $qrSize * $scale;
    $logoNewH = $logoH * $logoNewW / $logoW;

    $logoX = ($qrSize - $logoNewW) / 2;
    $logoY = ($qrSize - $logoNewH) / 2;
}

/* Construimos el SVG con “lienzo” 1080 × 1080 px
   El viewBox mantiene la resolución real del QR        */
$outSize = 1080;   // NUEVO: tamaño de salida fijo

$svg = '<?xml version="1.0" encoding="UTF-8"?>'
     . '<svg xmlns="http://www.w3.org/2000/svg" '
     . 'width="'.$outSize.'" height="'.$outSize.'" '                 // ← cambiados
     . 'viewBox="0 0 '.$qrSize.' '.$qrSize.'">'
     . '<image href="'.$qrDataUri.'" '
     .        'x="0" y="0" width="'.$qrSize.'" height="'.$qrSize.'"/>';

if ($logoDataUri) {
    $svg .= '<image href="'.$logoDataUri.'" '
         .  'x="'.$logoX.'" y="'.$logoY.'" '
         .  'width="'.$logoNewW.'" height="'.$logoNewH.'"/>';
}
$svg .= '</svg>';

/* --- ► HEADERS  ------------------------------------------------------ */
ob_clean();                                // vacía buffers previos
header('Content-Type: image/svg+xml');
header('Content-Length: '.strlen($svg));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Disposition: '.$disp);

/* salida final */
echo $svg;
ob_end_flush();
exit;
