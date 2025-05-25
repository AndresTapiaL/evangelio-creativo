<?php
// 1) Configuración inicial
date_default_timezone_set('UTC');
require 'conexion.php';
session_start();

// ————————————————————————————————————————————————
// BLOQUE A: SOLO GET & ?token=… → flujo de token
//————————————————————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['token'])) {
    // 1.1) Leer y validar estado
    $token  = $_GET['token'];
    $estado = filter_input(INPUT_GET, 'estado', FILTER_VALIDATE_INT);
    if (!in_array($estado, [1,2,3], true)) {
        http_response_code(400);
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
          <meta charset="UTF-8">
          <title>Estado inválido</title>
          <style>
            body {margin:0;padding:0;font-family:Arial,sans-serif;background:#f0f2f5;
              display:flex;align-items:center;justify-content:center;height:100vh;}
            .card {background:#fff;padding:2rem;border-radius:8px;
              box-shadow:0 4px 12px rgba(0,0,0,0.1);text-align:center;max-width:320px;}
            .card .icon {font-size:4rem;color:#E53935;margin-bottom:.5rem;}
            .card p {color:#333;line-height:1.4;}
          </style>
        </head>
        <body>
          <div class="card">
            <div class="icon">⚠️</div>
            <p>El estado proporcionado no es válido.</p>
          </div>
        </body>
        </html>
        <?php
        exit;
    }

    // 1.2) Buscar token activo en la BD
    $tq = $pdo->prepare("
      SELECT id_usuario, id_evento
        FROM attendance_tokens
       WHERE token = ? AND expires_at >= NOW()
       LIMIT 1
    ");
    $tq->execute([$token]);
    $tk = $tq->fetch(PDO::FETCH_ASSOC);
    if (!$tk) {
      http_response_code(403);
      ?>
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <title>Enlace inválido</title>
        <style>
          body {margin:0;padding:0;font-family:Arial,sans-serif;background:#f0f2f5;
            display:flex;align-items:center;justify-content:center;height:100vh;}
          .card {background:#fff;padding:2rem;border-radius:8px;
            box-shadow:0 4px 12px rgba(0,0,0,0.1);text-align:center;max-width:320px;}
          .card .icon {font-size:4rem;color:#E53935;margin-bottom:.5rem;}
          .card p {color:#333;line-height:1.4;}
        </style>
      </head>
      <body>
        <div class="card">
          <div class="icon">⚠️</div>
          <p>Enlace inválido o expirado.</p>
        </div>
      </body>
      </html>
      <?php
      exit;
    }

    // 1.3) Insert/Update asistencia
    $stmt = $pdo->prepare("
      INSERT INTO asistencias (id_evento, id_usuario, id_estado_previo_asistencia)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE
        id_estado_previo_asistencia = VALUES(id_estado_previo_asistencia)
    ");
    $stmt->execute([$tk['id_evento'], $tk['id_usuario'], $estado]);

    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>Asistencia registrada</title>
      <style>
        body {
          margin: 0;
          padding: 0;
          font-family: Arial, sans-serif;
          background: #f0f2f5;
          display: flex;
          align-items: center;
          justify-content: center;
          height: 100vh;
        }
        .card {
          background: #fff;
          padding: 2rem;
          border-radius: 8px;
          box-shadow: 0 4px 12px rgba(0,0,0,0.1);
          text-align: center;
          max-width: 320px;
          width: 100%;
        }
        .card .icon {
          font-size: 4rem;
          color: #4CAF50;
          margin-bottom: 0.5rem;
        }
        .card p {
          margin: 1rem 0;
          color: #333;
          line-height: 1.4;
        }
        .card .countdown {
          font-weight: bold;
          color: #555;
        }
      </style>
    </head>
    <body>
      <div class="card">
        <div class="icon">✅</div>
        <p>Tu asistencia ha quedado registrada<br>correctamente.</p>
        <p>Esta ventana se cerrará en <span class="countdown">3</span> segundos…</p>
      </div>
      <script>
        let sec = 3;
        const el = document.querySelector('.countdown');
        const iv = setInterval(() => {
          sec--;
          if (sec <= 0) {
            clearInterval(iv);
            window.close();
          } else {
            el.textContent = sec;
          }
        }, 1000);
      </script>
    </body>
    </html>
    <?php
    exit;
}

// BLOQUE B: SOLO POST con sesión válida → flujo de botones “¿Asistirás?”
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2.1) Comprobar sesión
    if (empty($_SESSION['id_usuario'])) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['ok'=>false,'error'=>'No autorizado', 'msg'   => 'Debes iniciar sesión para confirmar tu asistencia.']);
        exit;
    }
    $id_usuario = $_SESSION['id_usuario'];

    // 2.2) Leer y validar inputs de POST
    $id_evento = filter_input(INPUT_POST,'id_evento',FILTER_VALIDATE_INT);
    $estado    = filter_input(INPUT_POST,'id_estado_previo_asistencia',FILTER_VALIDATE_INT);
    if (!$id_evento || !in_array($estado, [1,2,3], true)) {
        header('Content-Type: application/json', true, 400);
        echo json_encode(['ok'=>false,'error'=>'Datos inválidos', 'msg'   => 'Los datos enviados no son correctos. Por favor inténtalo de nuevo.']);
        exit;
    }

    // 2.3) Verificar permiso sobre el evento
    $chk = $pdo->prepare("
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
    $chk->execute([$id_usuario, $id_evento]);
    if (!$chk->fetchColumn()) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['ok'=>false,'error'=>'Sin permiso para este evento', 'msg'   => 'No tienes permiso para confirmar asistencia a este evento.']);
        exit;
    }

    // 2.4) Insert/Update asistencia
    $stmt = $pdo->prepare("
      INSERT INTO asistencias (id_evento, id_usuario, id_estado_previo_asistencia)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE
        id_estado_previo_asistencia = VALUES(id_estado_previo_asistencia)
    ");
    $stmt->execute([$id_evento, $id_usuario, $estado]);

    // 2.5) Calcular cnt_presente y total_integrantes
    $cnt = $pdo->prepare("
      SELECT COUNT(*) FROM asistencias
       WHERE id_evento = ? AND id_estado_previo_asistencia = 1
    ");
    $cnt->execute([$id_evento]);
    $cnt_presente = (int)$cnt->fetchColumn();

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

    // 2.6) Devolver JSON
    header('Content-Type: application/json');
    echo json_encode([
      'ok'                => true,
      'cnt_presente'      => $cnt_presente,
      'total_integrantes' => $total_integrantes
    ]);
    exit;
}

// BLOQUE C: cualquier otro método o GET sin token → prohibido
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Operación no permitida</title>
  <style>
    body {
      margin:0; padding:0;
      font-family:Arial,sans-serif;
      background:#f0f2f5;
      display:flex; align-items:center; justify-content:center;
      height:100vh;
    }
    .card {
      background:#fff; padding:2rem; border-radius:8px;
      box-shadow:0 4px 12px rgba(0,0,0,0.1);
      text-align:center; max-width:320px; width:90%;
    }
    .icon { font-size:4rem; color:#E53935; margin-bottom:.5rem; }
    p { color:#333; line-height:1.4; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">🚫</div>
    <p>No tienes permiso para esta operación.<br>Si crees que es un error, vuelve a intentarlo.</p>
  </div>
</body>
</html>
<?php
exit;
