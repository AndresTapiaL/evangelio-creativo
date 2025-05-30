<?php
require 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

try{
  session_start();
  if (empty($_SESSION['id_usuario'])) throw new Exception('No autorizado');

  /* ───── Datos recibidos ───── */
  $projArr   = $_POST['id_equipo_proyecto'] ?? [];                 // check-boxes
  $esGeneral = in_array('', $projArr, true) ? 1 : 0;               // "" ⇒ general
  $projArr   = array_filter($projArr, fn($v) => $v !== '');        // limpia los ""

  // ── convertir "" a null ──
  if (isset($_POST['encargado']) && $_POST['encargado'] !== '') {
      $encargado = (int) $_POST['encargado'];
  } else {
      $encargado = null;
  }

  // ───────────── Validaciones ─────────────
  $errors = [];

  // 1) Longitud máxima de campos de texto
  $max = [
    'nombre_evento' => 100,
    'lugar'         => 100,
    'descripcion'   => 500,
    'observacion'   => 500
  ];

  // Patrón: letras (incluye acentos), números, espacios, y . , - ( ) 
  $pattern = '/^[\p{L}\p{N} .,#¿¡!?\(\)\/\-\r\n]+$/u';
  
  foreach ($max as $f => $len) {
      $v = trim($_POST[$f] ?? '');
      if ($f === 'nombre_evento' && $v === '') {
          $errors[$f] = 'El nombre es obligatorio.';
      } elseif (mb_strlen($v) > $len) {
          $errors[$f] = "Máximo $len caracteres.";
      }
  }

  // 2) Formato de caracteres permitidos en texto
  foreach (['nombre_evento','lugar','descripcion','observacion'] as $field) {
      $valor = trim($_POST[$field] ?? '');
      if ($valor !== '' && !preg_match($pattern, $valor)) {
          $errors[$field] = 'Sólo se permiten letras, números, espacios y . , ( ) -';
      }
  }

  // 2) Formato y existencia de las fechas
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
      $errors['fecha_hora_inicio'] = 'Formato o fecha-hora de inicio inválido.';
  }
  if (!($dtEnd = parseLocalDT($endRaw))) {
      $errors['fecha_hora_termino'] = 'Formato o fecha-hora de término inválido.';
  }

  // después de parsear $dtStart y $dtEnd...
  $minAllowed = DateTime::createFromFormat('Y-m-d H:i', '1970-01-01 00:00');
  $maxAllowed = DateTime::createFromFormat('Y-m-d H:i', '2037-12-31 23:59');

  // 3bis) Validar rango 1970–2037
  if ($dtStart < $minAllowed || $dtStart > $maxAllowed) {
      $errors['fecha_hora_inicio'] = 'La fecha de inicio debe estar entre 1970 y 2037.';
  }
  if ($dtEnd   < $minAllowed || $dtEnd   > $maxAllowed) {
      $errors['fecha_hora_termino'] = 'La fecha de término debe estar entre 1970 y 2037.';
  }

  if (!empty($errors)) {
      http_response_code(400);
      echo json_encode(['error' => $errors]);
      exit;
  }

  // 3) El término no puede ser anterior al inicio
  if (isset($dtStart, $dtEnd) && $dtEnd < $dtStart) {
      $errors['fecha_hora_termino'] = 'La fecha-hora término debe ser ≥ inicio.';
  }

  // 4) Si son el mismo día debe haber ≥15 min
  if ($dtStart && $dtEnd &&
      $dtStart->format('Y-m-d') === $dtEnd->format('Y-m-d') &&
      ($dtEnd->getTimestamp() - $dtStart->getTimestamp()) < 15*60
  ){
      $errors['fecha_hora_termino'] =
          'Debe haber al menos 15 minutos entre inicio y término cuando son el mismo día.';
  }

  if (!empty($errors)) {
      http_response_code(400);
      echo json_encode(['error' => $errors]);
      exit;
  }
  // ─────────────────────────────────────────

  /* 1. Insertar evento */
  $stmt = $pdo->prepare("
    INSERT INTO eventos(
      nombre_evento,lugar,descripcion,observacion,
      fecha_hora_inicio,fecha_hora_termino,
      id_estado_previo,id_tipo,id_estado_final,
      encargado,es_general
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
  ");
  $stmt->execute([
    $_POST['nombre_evento']      ?? null,
    $_POST['lugar']              ?? null,
    $_POST['descripcion']        ?? null,
    $_POST['observacion']        ?? null,
    $_POST['fecha_hora_inicio']  ?? null,
    $_POST['fecha_hora_termino'] ?? null,
    $_POST['id_estado_previo']   ?? null,
    $_POST['id_tipo']            ?? null,
    $_POST['id_estado_final']    ?? null,
    $encargado,
    $esGeneral                          // ← ahora correcto
  ]);
  $newId = $pdo->lastInsertId();

  /* 2. Insertar equipos/proyectos (solo si NO es general) */
  if ($esGeneral === 0 && !empty($projArr)) {
    $ins = $pdo->prepare("
      INSERT INTO equipos_proyectos_eventos(id_evento,id_equipo_proyecto)
      VALUES (?,?)
    ");
    foreach ($projArr as $ep) {
      $ins->execute([$newId, $ep]);
    }
  }

  echo json_encode(['mensaje' => 'OK']);
}catch(Exception $e){
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
