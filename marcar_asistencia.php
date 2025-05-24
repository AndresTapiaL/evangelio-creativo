<?php
date_default_timezone_set('UTC');
require 'conexion.php';
session_start();

$isGet = $_SERVER['REQUEST_METHOD'] === 'GET';

// 1) Autorización —> NUNCA dejamos pasar nada sin sesión
if (empty($_SESSION['id_usuario'])) {
    if ($isGet) {
        // GET → HTML de “No autorizado” + auto-cerrar
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html><head>
          <meta charset="utf-8">
          <title>No autorizado</title>
          <style>
            body { font-family:sans-serif; padding:2rem; text-align:center }
            .err { color:#c00; font-size:1.2rem }
          </style>
        </head>
        <body>
          <p class="err">😕 No estás autorizado para hacer esto.</p>
          <p>Esta ventana se cerrará en 3 segundos…<br>
             Si no se cierra automáticamente, por favor cierra tú mismo/a esta pestaña.</p>

          <script>
            setTimeout(() => window.close(), 3000);
          </script>
        </body>
        </html>
        <?php
    } else {
        // POST → JSON de error
        header('Content-Type: application/json; charset=utf-8', true, 403);
        echo json_encode(['ok'=>false,'error'=>'No autorizado']);
    }
    exit;
}

// A partir de aquí ya sabemos que EXISTE $_SESSION['id_usuario']
$id_usuario = $_SESSION['id_usuario'];
$isGet      = $_SERVER['REQUEST_METHOD'] === 'GET';

// 2) Leer y validar parámetros
$id_evento = filter_input(INPUT_GET,  'id_evento', FILTER_VALIDATE_INT)
           ?: filter_input(INPUT_POST, 'id_evento', FILTER_VALIDATE_INT);
$estado    = filter_input(INPUT_GET,  'id_estado_previo_asistencia', FILTER_VALIDATE_INT)
           ?: filter_input(INPUT_POST, 'id_estado_previo_asistencia', FILTER_VALIDATE_INT);

if (!$id_evento || ! in_array($estado, [1,2,3], true)) {
    if ($isGet) {
        // Error en GET → HTML
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><title>Datos inválidos</title></head>
        <body style="font-family:sans-serif;padding:2rem;text-align:center">
          <p style="color:#c00;font-size:1.1rem">
            ¡Uy! Los datos de tu enlace no son válidos.
          </p>
          <p>Esta ventana intentará cerrarse en 3 segundos.<br>
             Si no se cierra automáticamente, por favor cierra tú mismo/a esta pestaña.</p>
          <script>
            let count = 3;
            const timer = setInterval(()=>{
              count--;
              if (count<=0) {
                clearInterval(timer);
                window.close(); // sólo funciona si la pestaña fue abierta por JS
              }
            }, 1000);
          </script>
        </body></html>
        <?php
        exit;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Datos inválidos']);
        exit;
    }
}

// 3) Comprobación de permiso sobre el evento
$stmtOk = $pdo->prepare("
  SELECT 1
    FROM eventos e
    LEFT JOIN equipos_proyectos_eventos epe
      ON e.id_evento = epe.id_evento
    LEFT JOIN integrantes_equipos_proyectos iep
      ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
     AND iep.id_usuario = ?
   WHERE e.id_evento = ?
     AND (e.es_general = 1 OR iep.id_usuario IS NOT NULL)
   LIMIT 1
");
$stmtOk->execute([$id_usuario, $id_evento]);
if (! $stmtOk->fetchColumn()) {
    if ($isGet) {
        // GET → HTML de “sin permiso”
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><title>Sin permiso</title></head>
        <body style="font-family:sans-serif;padding:2rem;text-align:center">
          <p style="color:#c00;font-size:1.1rem">
            Lo sentimos, no tienes permiso para confirmar asistencia a este evento.
          </p>
          <p>Esta ventana se cerrará en 3 segundos o ciérrala manualmente.</p>
          <script>
            setTimeout(()=> window.close(), 3000);
          </script>
        </body></html>
        <?php
        exit;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Sin permiso para este evento']);
        exit;
    }
}

// 4) Insert / Update
$stmt = $pdo->prepare("
  INSERT INTO asistencias
    (id_evento, id_usuario, id_estado_previo_asistencia)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE
    id_estado_previo_asistencia = VALUES(id_estado_previo_asistencia)
");
$stmt->execute([$id_evento, $id_usuario, $estado]);

// 5) Recuento “Presente”
$stmt2 = $pdo->prepare("
  SELECT COUNT(*) AS cnt_presente
    FROM asistencias
   WHERE id_evento = ? AND id_estado_previo_asistencia = 1
");
$stmt2->execute([$id_evento]);
$cnt_presente = (int)$stmt2->fetchColumn();

// 6) Recuento total integrantes (general vs. específico)
$stmtGen = $pdo->prepare("SELECT es_general FROM eventos WHERE id_evento = ?");
$stmtGen->execute([$id_evento]);
$isGeneral = (bool)$stmtGen->fetchColumn();

if ($isGeneral) {
    $total_integrantes = (int)$pdo
      ->query("SELECT COUNT(DISTINCT id_usuario) FROM integrantes_equipos_proyectos")
      ->fetchColumn();
} else {
    $stmt3 = $pdo->prepare("
      SELECT COUNT(DISTINCT iep.id_usuario) AS total
        FROM equipos_proyectos_eventos epe
        JOIN integrantes_equipos_proyectos iep
          ON epe.id_equipo_proyecto = iep.id_equipo_proyecto
       WHERE epe.id_evento = ?
    ");
    $stmt3->execute([$id_evento]);
    $total_integrantes = (int)$stmt3->fetchColumn();
}

// 7) Responder
if ($isGet) {
    // GET → HTML de “¡Gracias!”
    ?>
    <!DOCTYPE html>
    <html><head>
      <meta charset="utf-8">
      <title>¡Gracias!</title>
      <style>
        body { font-family:sans-serif;padding:2rem;text-align:center }
        .msg  { font-size:1.2rem; color:#333 }
      </style>
    </head>
    <body>
      <p class="msg">✅ Tu respuesta ha sido registrada correctamente.</p>
      <p>Se cerrará esta ventana en <span id="c">3</span> segundos.<br>
        Si no, cierra tú esta pestaña.</p>
      <script>
        let s = 3;
        const el = document.getElementById('c');
        const iv = setInterval(()=>{
          s--;
          if (s <= 0) {
            clearInterval(iv);
            window.close();
          } else {
            el.textContent = s;
          }
        }, 1000);
      </script>
    </body></html>
    <?php
    exit;
} else {
    // POST → JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok'                => true,
      'cnt_presente'      => $cnt_presente,
      'total_integrantes' => $total_integrantes
    ]);
    exit;
}
