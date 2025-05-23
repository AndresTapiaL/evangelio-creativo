<?php
// export.php
// Descarga de eventos en Excel o PDF con permisos y filtros

// 0) Configuración de errores y buffer para evitar salidas inesperadas
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ob_start();

// 1) Sesión y conexión
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/fpdf/fpdf.php';

class PDF_Row extends FPDF
{
    // Cuenta cuántas líneas ocupará el texto en un ancho $w
    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s    = str_replace("\r", '', $txt);
        $nb   = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;  $sep = -1;  $j = $i;  $l = 0;  $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;  $j = $i;  $l = 0;  $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    // Dibuja una fila de celdas con auto-ajuste de altura
    function Row(array $data, array $widths)
    {
        // calcular altura de la fila
        $nb = 0;
        foreach ($data as $i => $txt) {
            $nb = max($nb, $this->NbLines($widths[$i], $txt));
        }
        $h = 5 * $nb;
        $this->CheckPageBreak($h);
        // dibujar celdas
        foreach ($data as $i => $txt) {
            $x = $this->GetX();
            $y = $this->GetY();
            $this->Rect($x, $y, $widths[$i], $h);
            $this->MultiCell($widths[$i], 5, $txt, 0, 'L');
            $this->SetXY($x + $widths[$i], $y);
        }
        $this->Ln($h);
    }

    // Si la fila no cabe, añade página
    function CheckPageBreak($h)
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }
}

// 2) Validar usuario logueado
if (empty($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id_usuario = $_SESSION['id_usuario'];

// 3) Permisos y roles
// 3.1) Liderazgo nacional?
$stmt = $pdo->prepare(
    "SELECT 1 FROM integrantes_equipos_proyectos
     WHERE id_usuario = ? AND id_equipo_proyecto = 1 LIMIT 1"
);
$stmt->execute([$id_usuario]);
$isLiderNacional = (bool)$stmt->fetchColumn();

// 3.2) Equipos del usuario
$q = $pdo->prepare(
    "SELECT id_equipo_proyecto
       FROM integrantes_equipos_proyectos
      WHERE id_usuario = ?"
);
$q->execute([$id_usuario]);
$userTeamIds = $q->fetchAll(PDO::FETCH_COLUMN);

// 3.3) Equipos donde es Líder/Coordinador
$leadStmt = $pdo->prepare(
    "SELECT DISTINCT id_equipo_proyecto
       FROM integrantes_equipos_proyectos
      WHERE id_usuario = ? AND id_rol IN (4,6)"
);
$leadStmt->execute([$id_usuario]);
$myLeadTeams = $leadStmt->fetchAll(PDO::FETCH_COLUMN);

// 4) Leer parámetros de export
$filtro    = $_REQUEST['filtro']   ?? 'calendario';
$busqueda  = trim($_REQUEST['busqueda'] ?? '');
$showAprob = ($_REQUEST['aprobados'] ?? '') === '1';
$mesStart  = $_REQUEST['mesStart'] ?? date('Y-m');
$mesEnd    = $_REQUEST['mesEnd']   ?? date('Y-m');
$format    = ($_REQUEST['format'] ?? 'excel') === 'pdf' ? 'pdf' : 'excel';

// 5) Construir fechas de rango
list($ys,$ms) = explode('-', $mesStart);
list($ye,$me) = explode('-', $mesEnd);
$startDate = sprintf('%04d-%02d-01 00:00:00', $ys, $ms);
$endDate   = date('Y-m-t 23:59:59', strtotime("{$ye}-{$me}-01"));

// 6) Construir condiciones WHERE
$where  = [];
$params = [];

// 6.1) Permisos por estado previo (usando sub‐select para no recortar el JOIN)
if (! $isLiderNacional) {
    if (!empty($myLeadTeams)) {
        $inLeads = implode(',', array_map('intval',$myLeadTeams));
        $where[] = "(
            e.id_estado_previo = 1
          OR (
               e.id_estado_previo IN (2,3)
            AND e.es_general = 0
            AND e.id_evento IN (
                 SELECT id_evento
                   FROM equipos_proyectos_eventos
                  WHERE id_equipo_proyecto IN ({$inLeads})
            )
          )
        )";
    } else {
        $where[] = "e.id_estado_previo = 1";
    }
}

// 6.2) Aplicar filtro calendario/general/equipo
if ($filtro === 'general') {
    $where[] = 'e.es_general = 1';
} elseif ($filtro !== 'calendario') {
    $where[] = 'ep.id_equipo_proyecto = :filtro';
    $params['filtro'] = (int)$filtro;
}

// 6.3) Búsqueda inteligente (LIKE en varios campos)
if ($busqueda !== '') {
    $where[] = "(
      e.nombre_evento LIKE :busqueda
      OR CONCAT(u.nombres,' ',u.apellido_paterno) LIKE :busqueda
      OR e.lugar LIKE :busqueda
      OR e.descripcion LIKE :busqueda
      OR e.observacion LIKE :busqueda
    )";
    $params['busqueda'] = "%{$busqueda}%";
}

// 6.4) Rango de fechas
$where[] = 'e.fecha_hora_inicio BETWEEN :startDate AND :endDate';
$params['startDate'] = $startDate;
$params['endDate']   = $endDate;

// ——————————————————————————————————————————
// 1) Etiqueta del filtro
if ($filtro === 'calendario') {
    $filterLabel = 'Calendario';
} elseif ($filtro === 'general') {
    $filterLabel = 'Eventos Generales';
} else {
    // nombre del equipo/proyecto
    $stmt = $pdo->prepare("
      SELECT nombre_equipo_proyecto
        FROM equipos_proyectos
       WHERE id_equipo_proyecto = ?
    ");
    $stmt->execute([$filtro]);
    $filterLabel = $stmt->fetchColumn() ?: "Equipo #{$filtro}";
}

// Mapa de meses en español
$meses = [
  '01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril',
  '05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto',
  '09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'
];

// $mesStart y $mesEnd vienen en formato 'YYYY-MM'
list($sy, $sm) = explode('-', $mesStart);
list($ey, $em) = explode('-', $mesEnd);

// Etiquetas para cada extremo
$startLabel = $meses[$sm] . ' ' . $sy;
$endLabel   = $meses[$em] . ' ' . $ey;

// Lógica de formateo
if ($sm === $em && $sy === $ey) {
    // mismo mes y año: "Marzo 2025"
    $dateRange = $startLabel;
}
elseif ($sy === $ey) {
    // mismo año distinto mes: "Marzo - Abril 2025"
    $dateRange = $meses[$sm] . ' - ' . $meses[$em] . ' ' . $sy;
}
else {
    // distinto año: "Diciembre 2024 - Enero 2025"
    $dateRange = $startLabel . ' - ' . $endLabel;
}

// 6.5) Toggle aprobar eventos
if ($isLiderNacional && $showAprob) {
    $where[] = 'e.id_estado_previo = 3';
}

// 7) Preparar y ejecutar SQL
$sql = "
  SELECT
    e.id_evento,
    e.es_general,
    e.fecha_hora_inicio,
    e.fecha_hora_termino,
    e.nombre_evento,
    e.lugar,
    COALESCE(
      GROUP_CONCAT(DISTINCT epj.nombre_equipo_proyecto SEPARATOR ', '),
      'General'
    ) AS equipos,
    GROUP_CONCAT(DISTINCT ep.id_equipo_proyecto) AS equipo_ids,

    prev.nombre_estado_previo,
    fin.nombre_estado_final,
    tipe.nombre_tipo,

    CONCAT(u.nombres,' ',u.apellido_paterno,' ',u.apellido_materno) AS encargado,

    e.descripcion,
    e.observacion,

    COALESCE(ap.cnt_presente, 0) AS cnt_presente,

    /* total_integrantes según es_general */
    COALESCE(
    CASE
        WHEN e.es_general = 1 THEN allu.cnt_all
        ELSE tu.total_integrantes
    END,
    0
    ) AS total_integrantes
  FROM eventos e

  LEFT JOIN usuarios u ON e.encargado = u.id_usuario
  LEFT JOIN estados_previos_eventos prev ON e.id_estado_previo = prev.id_estado_previo
  LEFT JOIN estados_finales_eventos fin ON e.id_estado_final  = fin.id_estado_final
  LEFT JOIN tipos_evento tipe ON e.id_tipo          = tipe.id_tipo

  LEFT JOIN (
    SELECT id_evento, COUNT(*) AS cnt_presente
      FROM asistencias
     WHERE id_estado_previo_asistencia = 1
     GROUP BY id_evento
  ) ap ON ap.id_evento = e.id_evento

  LEFT JOIN (
    SELECT epe.id_evento,
           COUNT(DISTINCT iep.id_usuario) AS total_integrantes
      FROM equipos_proyectos_eventos epe
      JOIN integrantes_equipos_proyectos iep
        ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
     GROUP BY epe.id_evento
  ) tu ON tu.id_evento = e.id_evento

  /* —— Subconsulta 3: total global de usuarios con ≥1 equipo —— */
  LEFT JOIN (
    SELECT COUNT(DISTINCT id_usuario) AS cnt_all
      FROM integrantes_equipos_proyectos
  ) allu ON 1 = 1

  LEFT JOIN equipos_proyectos_eventos ep ON e.id_evento = ep.id_evento
  LEFT JOIN equipos_proyectos epj ON ep.id_equipo_proyecto = epj.id_equipo_proyecto

  " . (count($where) ? 'WHERE '.implode(' AND ', $where) : '') . "
  GROUP BY e.id_evento
  ORDER BY e.fecha_hora_inicio ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Misma lógica que en eventos.php para show_observacion y equipos_usuario ───
$obsStmt = $pdo->prepare("
  SELECT DISTINCT epe.id_evento
    FROM equipos_proyectos_eventos epe
    JOIN integrantes_equipos_proyectos iep
      ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
   WHERE iep.id_usuario = :uid
     AND iep.id_rol IN (4,6)
");
$obsStmt->execute(['uid'=>$id_usuario]);
$obsEventIds = $obsStmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($rows as &$e) {
    // 1) ocultar o mostrar Observación
    $e['show_observacion'] =
         $isLiderNacional
      || in_array((int)$e['id_evento'], $obsEventIds, true);

    // recalcular equipos_usuario (idéntico al fragmento anterior)
    $rawNames = $e['equipos'] ?: 'General';
    if ($isLiderNacional) {
        $e['equipos_usuario'] = $rawNames;
    } else {
        if ($e['es_general']) {
            $e['equipos_usuario'] = 'General';
        } else {
            $allIds = array_filter(explode(',', $e['equipo_ids']));
            $common = array_intersect($allIds, $userTeamIds);
            $names  = [];
            foreach ($userTeams as $t) {
                if (in_array($t['id_equipo_proyecto'], $common, true)) {
                    $names[] = $t['nombre_equipo_proyecto'];
                }
            }
            $e['equipos_usuario'] = $names ? implode(', ', $names) : 'General';
        }
    }
}
unset($e);

// 8) Exportar a Excel
if ($format === 'excel') {
    // BOM para Excel y tildes
    echo "\xEF\xBB\xBF";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=eventos_{$_REQUEST['mesStart']}_a_{$_REQUEST['mesEnd']}.xls");

    echo "<table border='1'><thead><tr>";
    $headers = [
      'Inicio','Término','Evento','Lugar','Equipo/Proyecto',
      'Estado previo','Estado final','Tipo','Encargado','Asist. previa',
      'Descripción','Observación'
    ];
    foreach ($headers as $h) {
        echo "<th>".htmlspecialchars($h)."</th>";
    }
    echo "</tr></thead><tbody>";

    foreach ($rows as $e) {
        $asistText = htmlspecialchars("{$e['cnt_presente']} de {$e['total_integrantes']}");
        echo '<tr>',
             "<td>".htmlspecialchars($e['fecha_hora_inicio'])."</td>",
             "<td>".htmlspecialchars($e['fecha_hora_termino'])."</td>",
             "<td>".htmlspecialchars($e['nombre_evento'])."</td>",
             "<td>".htmlspecialchars($e['lugar'])."</td>",
             "<td>".htmlspecialchars($e['equipos'])."</td>",
             "<td>".htmlspecialchars($e['nombre_estado_previo'])."</td>",
             "<td>".htmlspecialchars($e['nombre_estado_final'])."</td>",
             "<td>".htmlspecialchars($e['nombre_tipo'])."</td>",
             "<td>".htmlspecialchars($e['encargado'])."</td>",
             "<td>{$asistText}</td>",
             "<td>".htmlspecialchars($e['descripcion']   ?? '')."</td>",
             "<td>".htmlspecialchars($e['show_observacion'] ? ($e['observacion'] ?? '') : '')."</td>",
             '</tr>';
    }
    echo "</tbody></table>";
    exit;
}

// 9) Exportar a PDF
// Limpiar cualquier buffer previo
if (ob_get_length()) ob_end_clean();

$pdf = new PDF_Row('L','mm','A3');
$pdf->AddPage();

// — Cabecera con filtro y rango de fechas —
// Fuente más grande para el título
$pdf->SetFont('Arial','B',12);
// Escribe el título centrado a lo ancho de la página
$pdf->Cell(
  0,                        // ancho completo
  10,                       // altura del bloque
  mb_convert_encoding(
    "{$filterLabel} | {$dateRange}",
    'ISO-8859-1','UTF-8'
  ),
  0,                        // sin borde
  1,                        // saltar línea tras imprimir
  'C'                       // alineación centrada
);
// Un pequeño espacio
$pdf->Ln(2);

// — Volver a la fuente de la cabecera de tabla —
$pdf->SetFont('Arial','B',8);

// Definir anchos y cabeceras
$w = [30,30,40,30,40,25,25,25,35,25,45,45];
$head = [
  'Inicio','Término','Evento','Lugar','Equipo/Proyecto',
  'Previo','Final','Tipo','Encargado','Asist. previa',
  'Descripción','Observación'
];
foreach ($head as $i => $h) {
    $pdf->Cell($w[$i],6, mb_convert_encoding($h,'ISO-8859-1','UTF-8'),1);
}
$pdf->Ln();

$pdf->SetFont('Arial','',7);
foreach ($rows as $e) {
    // prepara cada campo como string (null→'')
    $fila = [
      $e['fecha_hora_inicio']   ?? '',
      $e['fecha_hora_termino']   ?? '',
      $e['nombre_evento']        ?? '',
      $e['lugar']                ?? '',
      $e['equipos']              ?? '',
      $e['nombre_estado_previo'] ?? '',
      $e['nombre_estado_final']  ?? '',
      $e['nombre_tipo']          ?? '',
      $e['encargado']            ?? '',
      "{$e['cnt_presente']} de {$e['total_integrantes']}",
      $e['descripcion']          ?? '',
      $e['show_observacion'] ? ($e['observacion'] ?? '') : ''
    ];
    // aplicar encoding WYSIWYG
    $fila = array_map(
      fn($t)=> mb_convert_encoding($t,'ISO-8859-1','UTF-8'),
      $fila
    );
    $pdf->Row($fila, $w);
}

$pdf->Output('D', "eventos_{$mesStart}_a_{$mesEnd}.pdf");
exit;
