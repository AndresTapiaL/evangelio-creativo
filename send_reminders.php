<?php
if (php_sapi_name() !== 'cli') {
    // puedes también hacer http_response_code(403);
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}
date_default_timezone_set('UTC');
require 'conexion.php';

// MODO PRUEBA: sólo enviar al usuario con este ID
$testUserId = 1;

setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'spanish');
set_time_limit(0); // Permite al script correr todo el tiempo que necesite

// Incluimos PHPMailer manualmente
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Días de antelación en que queremos enviar recordatorios
$offsets = [7, 3, 1];
// Hora mínima del día en que se disparan (opcional)
$sendHour = '00:00:00';

foreach ($offsets as $days) {
    // 1) Buscamos todos los usuarios sin contestar para eventos a $days días
    $sql = <<<SQL
    SELECT
    e.id_evento,
    e.nombre_evento,
    e.lugar,
    e.descripcion,
    e.fecha_hora_inicio,
    e.fecha_hora_termino,
    tipe.nombre_tipo,
    prev.nombre_estado_previo,
    -- Todos los equipos del evento, concatenados
    CASE
      WHEN e.es_general = 1
        THEN 'General'
      ELSE GROUP_CONCAT(DISTINCT epj_all.nombre_equipo_proyecto SEPARATOR ', ')
    END AS equipos,
    u.id_usuario,
    ce.correo_electronico AS email,
    u.nombres

    FROM eventos e

    -- 1) obligatoriamente traigo al usuario que quiero notificar
    JOIN usuarios u
    ON u.id_usuario = :uid
    JOIN correos_electronicos ce
    ON ce.id_usuario = u.id_usuario

    -- 2) checkeo si el usuario está en algún equipo de este evento
    LEFT JOIN equipos_proyectos_eventos epe_user
    ON e.id_evento = epe_user.id_evento
    LEFT JOIN integrantes_equipos_proyectos iep_user
    ON epe_user.id_equipo_proyecto = iep_user.id_equipo_proyecto
    AND iep_user.id_usuario      = u.id_usuario

    -- 3) para concatenar *todos* los equipos del evento
    LEFT JOIN equipos_proyectos_eventos epe_all
    ON e.id_evento = epe_all.id_evento
    LEFT JOIN equipos_proyectos epj_all
    ON epe_all.id_equipo_proyecto = epj_all.id_equipo_proyecto

    -- 4) joins informativos
    LEFT JOIN estados_previos_eventos prev
    ON e.id_estado_previo = prev.id_estado_previo
    LEFT JOIN tipos_evento tipe
    ON e.id_tipo = tipe.id_tipo

    WHERE
    e.id_estado_previo = 1
    -- si es general o si el usuario está en uno de sus equipos
    AND (
        e.es_general   = 1
        OR iep_user.id_usuario IS NOT NULL
    )
    AND DATE(e.fecha_hora_inicio) = DATE_ADD(CURDATE(), INTERVAL :days DAY)
    AND TIME(NOW()) >= :hh
    AND NOT EXISTS (
        SELECT 1
        FROM asistencias a2
        WHERE a2.id_evento                    = e.id_evento
        AND a2.id_usuario                   = u.id_usuario
        AND a2.id_estado_previo_asistencia IN (1,2,3)
    )

    GROUP BY e.id_evento, u.id_usuario
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
    ':uid'  => $testUserId,
    ':days' => $days,
    ':hh'   => $sendHour
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        echo "-> Enviando recordatorio a {$r['nombres']} ({$r['email']}) para el evento “{$r['nombre_evento']}” a {$days} días\n";

        $mail = new PHPMailer(true);
        try {
            // Configuración SMTP
            $mail->CharSet    = 'UTF-8';
            $mail->isSMTP();
            $mail->SMTPKeepAlive = true;
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'actividades.evangeliocreativo@gmail.com';
            $mail->Password   = 'vwsgpbigmyqaknpc';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->setFrom('actividades.evangeliocreativo@gmail.com', 'Evangelio Creativo');
            $mail->addAddress($r['email'], $r['nombres']);

            $mail->Subject = "Recordatorio: faltan {$days} día(s) para «{$r['nombre_evento']}»";
            $mail->isHTML(true);

            // crea un DateTime
            $dt  = new DateTime($r['fecha_hora_inicio']);
            $dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

            $w = (int)$dt->format('w');
            $j = (int)$dt->format('j');
            $n = (int)$dt->format('n');
            $Y = $dt->format('Y');
            $H = $dt->format('H');
            $i = $dt->format('i');

            $fechaBonita = ucfirst("{$dias[$w]}, {$j} de {$meses[$n]} de {$Y} a las {$H}:{$i}");

            // 0) comprobación de token existente
            $check = $pdo->prepare(<<<SQL
              SELECT token, expires_at
                FROM attendance_tokens
              WHERE id_usuario = :uid
                AND id_evento  = :eid
                AND expires_at >= NOW()
              LIMIT 1
            SQL
            );
            $check->execute([
              ':uid' => $r['id_usuario'],
              ':eid' => $r['id_evento'],
            ]);
            $tk = $check->fetch(PDO::FETCH_ASSOC);

            if ($tk) {
              // ya hay uno activo → lo reutilizamos
              $token    = $tk['token'];
              $expires  = $tk['expires_at'];
            } else {
              // no existe → lo creamos
              $token   = bin2hex(random_bytes(32));
              // expires exactamente cuando empiece el evento
              $expires = (new DateTime($r['fecha_hora_inicio']))
                            ->format('Y-m-d H:i:s');

              $insert = $pdo->prepare(<<<SQL
                INSERT INTO attendance_tokens
                  (token, id_usuario, id_evento, expires_at)
                VALUES (?, ?, ?, ?)
              SQL
              );
              $insert->execute([
                $token, 
                $r['id_usuario'], 
                $r['id_evento'], 
                $expires
              ]);
            }

            // 1) ahora generas los enlaces con ese mismo $token
            $base = 'http://localhost/PW%20EC_Antes/marcar_asistencia.php';
            $yes  = "{$base}?token={$token}&estado=1";
            $no   = "{$base}?token={$token}&estado=2";
            $unk  = "{$base}?token={$token}&estado=3";

            $body = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; background:#f2f2f2; margin:0; padding:20px; }
    .container { background:#fff; max-width:600px; margin:auto;
      border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.1); }
    .header { background:#FF5722; color:#fff; padding:20px; text-align:center; }
    .header h1 { margin:0; font-size:24px; }
    .content { padding:20px; color:#333; }
    .content p { line-height:1.5; }
    .content ul { padding-left:1.2em; }
    .content li { margin-bottom:.5em; }
    .cta { text-align:center; margin:20px 0; }
    .btn-yes { background:#4CAF50; }
    .btn-no  { background:#F44336; }
    .btn-unk { background:#FFEB3B; }
    .footer { font-size:12px; color:#777; text-align:center; padding:10px 20px;
      background:#f9f9f9; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>¡Hola {$r['nombres']}!</h1>
    </div>
    <div class="content">
      <p>Tu evento <strong>{$r['nombre_evento']}</strong> está programado para el <strong>{$fechaBonita}</strong>.</p>
      <ul>
        <li><strong>Lugar:</strong> {$r['lugar']}</li>
        <li><strong>Descripción:</strong> {$r['descripcion']}</li>
        <li><strong>Tipo:</strong> {$r['nombre_tipo']}</li>
        <li><strong>Equipos:</strong> {$r['equipos']}</li>
      </ul>
      <p><em>Por favor, confirma tu asistencia únicamente haciendo clic en uno de los botones siguientes.</em></p>
      <div class="cta">
        <a href="$yes" class="btn btn-yes" style="display:inline-block; text-decoration:none;  padding:12px 20px; margin:0 5px; border-radius:4px; font-weight:bold; font-size:14px; background:#4CAF50; color:#ffffff;">Sí, asistiré</a>
        <a href="$no"  class="btn btn-no" style="display:inline-block; text-decoration:none;  padding:12px 20px; margin:0 5px; border-radius:4px; font-weight:bold; font-size:14px; background:#F44336; color:#ffffff;">No podré asistir</a>
        <a href="$unk" class="btn btn-unk" style="display:inline-block; text-decoration:none;  padding:12px 20px; margin:0 5px; border-radius:4px; font-weight:bold; font-size:14px; background:#FFEB3B; color:#000000;">No estoy seguro/a</a>
      </div>
    </div>
    <div class="footer">
      Este es un mensaje automático. Por favor no respondas a este correo.<br>
      Puedes sobreescribir tu asistencia presionando nuevamente otra opción.
    </div>
  </div>
</body>
</html>
HTML;

            $mail->Body = $body;
            $mail->AltBody = strip_tags(
                str_replace('</p>', "\n\n", $body)
            );
            $mail->send();
            $mail->clearAddresses();
            $mail->clearAttachments();
            $mail->smtpClose();
            echo "   ✅ ¡Correo enviado!\n";
            sleep(1); // 1 segundo de pausa entre correos
        } catch (Exception $e) {
            echo "   ❌ Error: {$mail->ErrorInfo}\n";
        }
    }

    if (empty($rows)) {
        echo "   — No hay recordatorios pendientes para {$days} días antes.\n";
    }
}

