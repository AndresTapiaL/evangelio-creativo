<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    if (empty($_SESSION['id_usuario'])) {
        throw new Exception('No autorizado');
    }

    /* ───── Datos recibidos ───── */
    $idEvento  = $_POST['id_evento']               ?? null;
    $projArr   = $_POST['id_equipo_proyecto']      ?? [];  // check‐boxes
    $esGeneral = in_array('', $projArr, true) ? 1 : 0;    // “” ⇒ general
    $projArr   = array_filter($projArr, fn($v)=>$v !== '');

    // encargado: “” → null
    if (isset($_POST['encargado']) && $_POST['encargado'] !== '') {
        $encargado = (int) $_POST['encargado'];
    } else {
        $encargado = null;
    }

    /* ───────────── Validaciones ───────────── */
    $errors = [];

    // 1) Longitudes máximas
    $max = [
      'nombre_evento' => 100,
      'lugar'         => 100,
      'descripcion'   => 500,
      'observacion'   => 500
    ];
    foreach ($max as $field => $len) {
        $val = trim($_POST[$field] ?? '');
        if ($field === 'nombre_evento' && $val === '') {
            $errors[$field] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($val) > $len) {
            $errors[$field] = "Máximo $len caracteres.";
        }
    }

    // 2) Función para parsear “datetime-local” y descartar fechas imposibles
    function parseLocalDT(string $s) {
    foreach (['Y-m-d H:i:s','Y-m-d H:i'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d && $d->format($fmt) === $s) {
        return $d;
        }
    }
    return false;
    }

    $startRaw = $_POST['fecha_hora_inicio']  ?? '';
    $endRaw   = $_POST['fecha_hora_termino'] ?? '';

    if (!($dtStart = parseLocalDT($startRaw))) {
        $errors['fecha_hora_inicio'] = 'Formato o fecha‐hora de inicio inválido.';
    }
    if (!($dtEnd   = parseLocalDT($endRaw))) {
        $errors['fecha_hora_termino'] = 'Formato o fecha‐hora de término inválido.';
    }

    // después de parsear $dtStart y $dtEnd...
    $minAllowed = DateTime::createFromFormat('Y-m-d H:i', '1970-01-01 00:00');
    $maxAllowed = DateTime::createFromFormat('Y-m-d H:i', '2037-12-31 23:59');

    // 4) Rango razonable
    if ($dtStart < $minAllowed || $dtStart > $maxAllowed) {
        $errors['fecha_hora_inicio'] = 'La fecha de inicio debe estar entre 1900 y 2100.';
    }
    if ($dtEnd < $minAllowed || $dtEnd > $maxAllowed) {
        $errors['fecha_hora_termino'] = 'La fecha de término debe estar entre 1900 y 2100.';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['error' => $errors]);
        exit;
    }

    // 3) Término ≥ inicio
    if (isset($dtStart, $dtEnd) && $dtEnd < $dtStart) {
        $errors['fecha_hora_termino'] = 'La fecha‐hora término debe ser ≥ inicio.';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['errors' => $errors]);
        exit;
    }
    /* ───────────────────────────────────────── */

    /* 1) Actualizar tabla eventos */
    $f = fn($k) => ($_POST[$k] ?? null) !== '' ? $_POST[$k] : null;
    $stmt = $pdo->prepare("
      UPDATE eventos SET
        nombre_evento      = :nombre,
        lugar              = :lugar,
        descripcion        = :desc,
        observacion        = :obs,
        fecha_hora_inicio  = :ini,
        fecha_hora_termino = :fin,
        id_estado_previo   = :prev,
        id_tipo            = :tipo,
        id_estado_final    = :finest,
        encargado          = :enc,
        es_general         = :gen
      WHERE id_evento = :id
    ");
    $stmt->execute([
      ':nombre' => $f('nombre_evento'),
      ':lugar'  => $f('lugar'),
      ':desc'   => $f('descripcion'),
      ':obs'    => $f('observacion'),
      ':ini'    => $f('fecha_hora_inicio'),
      ':fin'    => $f('fecha_hora_termino'),
      ':prev'   => $f('id_estado_previo'),
      ':tipo'   => $f('id_tipo'),
      ':finest' => $f('id_estado_final'),
      ':enc'    => $encargado,
      ':gen'    => $esGeneral,
      ':id'     => $idEvento
    ]);

    /* 2) Actualizar relación N-a-N */
    $pdo->prepare("DELETE FROM equipos_proyectos_eventos WHERE id_evento = ?")
        ->execute([$idEvento]);
    if (!$esGeneral) {
        $ins = $pdo->prepare("
          INSERT INTO equipos_proyectos_eventos
            (id_evento, id_equipo_proyecto)
          VALUES (?, ?)
        ");
        foreach ($projArr as $eid) {
            $ins->execute([$idEvento, $eid]);
        }
    }

    echo json_encode(['mensaje' => 'OK']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
