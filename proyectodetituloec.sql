-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 23-07-2025 a las 20:50:26
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `proyectodetituloec`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admision`
--

CREATE TABLE `admision` (
  `id_usuario` int(11) NOT NULL,
  `id_estado_admision` int(11) NOT NULL,
  `liderazgo` varchar(255) DEFAULT NULL,
  `nos_conoces` varchar(255) DEFAULT NULL,
  `proposito` varchar(255) DEFAULT NULL,
  `motivacion` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admision_envios`
--

CREATE TABLE `admision_envios` (
  `id_envio` int(10) UNSIGNED NOT NULL,
  `device_id` char(36) NOT NULL,
  `fp_id` varchar(32) DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `fecha` date NOT NULL DEFAULT curdate()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `admision_envios`
--
DELIMITER $$
CREATE TRIGGER `trg_adm_envios_BI` BEFORE INSERT ON `admision_envios` FOR EACH ROW BEGIN
    DECLARE v_tot INT DEFAULT 0;

    /* 3-a)  fp_id no puede ser NULL ni vacío
            (evita saltarse el límite)                     */
    IF NEW.fp_id IS NULL OR NEW.fp_id = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'fp_id requerido.';
    END IF;

    /* 3-b)  cuenta los envíos del día que compartan
            cualquiera de los dos identificadores          */
    SELECT COUNT(*)
      INTO v_tot
      FROM admision_envios
     WHERE fecha       = CURDATE()
       AND (
              device_id = NEW.device_id    /* mismo UUID/navegador   */
           OR fp_id     = NEW.fp_id        /* misma “huella”         */
           );

    IF v_tot >= 5 THEN
        SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Máx 5 envíos por dispositivo al día.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asistencias`
--

CREATE TABLE `asistencias` (
  `id_usuario` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL,
  `id_estado_previo_asistencia` int(11) DEFAULT NULL,
  `id_estado_asistencia` int(11) DEFAULT NULL,
  `id_justificacion_inasistencia` int(11) DEFAULT NULL,
  `descripcion_otros` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `asistencias`
--
DELIMITER $$
CREATE TRIGGER `trg_asist_ad` AFTER DELETE ON `asistencias` FOR EACH ROW BEGIN
  CALL sp_refresh_resumen_justif_evento(OLD.id_evento);
  CALL sp_refresh_resumen_justif_usuario(OLD.id_usuario,
        (SELECT DATE(fecha_hora_inicio) FROM eventos WHERE id_evento = OLD.id_evento));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_asist_ai` AFTER INSERT ON `asistencias` FOR EACH ROW BEGIN
  CALL sp_refresh_resumen_justif_evento(NEW.id_evento);
  CALL sp_refresh_resumen_justif_usuario(NEW.id_usuario,
        (SELECT DATE(fecha_hora_inicio) FROM eventos WHERE id_evento = NEW.id_evento));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_asist_au` AFTER UPDATE ON `asistencias` FOR EACH ROW BEGIN
  IF NEW.id_justificacion_inasistencia <> OLD.id_justificacion_inasistencia THEN
     CALL sp_refresh_resumen_justif_evento(NEW.id_evento);
     CALL sp_refresh_resumen_justif_usuario(NEW.id_usuario,
           (SELECT DATE(fecha_hora_inicio) FROM eventos WHERE id_evento = NEW.id_evento));
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_asistencias_presente_ausente_insert` BEFORE INSERT ON `asistencias` FOR EACH ROW BEGIN
    -- Regla 1: Si Presente, debe ser "Si participó"
    IF NEW.id_estado_asistencia = 1 AND NEW.id_justificacion_inasistencia <> 10 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: Si el estado es "Presente", la justificación debe ser "Si participó".';
    END IF;

    -- Regla 2: Si Ausente, no puede ser "Si participó"
    IF NEW.id_estado_asistencia = 2 AND NEW.id_justificacion_inasistencia = 10 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: No puede ser "Si participó" si el estado es "Ausente".';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_asistencias_presente_ausente_update` BEFORE UPDATE ON `asistencias` FOR EACH ROW BEGIN
    -- Regla 1: Si Presente, debe ser "Si participó"
    IF NEW.id_estado_asistencia = 1 AND NEW.id_justificacion_inasistencia <> 10 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: Si el estado es "Presente", la justificación debe ser "Si participó".';
    END IF;

    -- Regla 2: Si Ausente, no puede ser "Si participó"
    IF NEW.id_estado_asistencia = 2 AND NEW.id_justificacion_inasistencia = 10 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: No puede ser "Si participó" si el estado es "Ausente".';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `attendance_tokens`
--

CREATE TABLE `attendance_tokens` (
  `token` char(64) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ciudad_comuna`
--

CREATE TABLE `ciudad_comuna` (
  `id_ciudad_comuna` int(11) NOT NULL,
  `nombre_ciudad_comuna` varchar(100) NOT NULL,
  `id_region_estado` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `correos_electronicos`
--

CREATE TABLE `correos_electronicos` (
  `correo_electronico` varchar(320) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `boletin` tinyint(1) DEFAULT 1,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_token` varchar(64) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `correos_electronicos`
--
DELIMITER $$
CREATE TRIGGER `trg_correo_reset_verified` BEFORE UPDATE ON `correos_electronicos` FOR EACH ROW BEGIN
    /* ¿cambió el correo? */
    IF NEW.correo_electronico <> OLD.correo_electronico THEN
        SET NEW.verified       = 0,
            NEW.verify_token   = NULL,   -- opcional: obligar nueva verificación
            NEW.token_expires  = NULL;   -- opcional: idem
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `descripcion_telefonos`
--

CREATE TABLE `descripcion_telefonos` (
  `id_descripcion_telefono` int(11) NOT NULL,
  `nombre_descripcion_telefono` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `equipos_proyectos`
--

CREATE TABLE `equipos_proyectos` (
  `id_equipo_proyecto` int(11) NOT NULL,
  `es_equipo` tinyint(1) DEFAULT NULL,
  `nombre_equipo_proyecto` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `equipos_proyectos_eventos`
--

CREATE TABLE `equipos_proyectos_eventos` (
  `id_equipos_proyectos_eventos` int(11) NOT NULL,
  `id_equipo_proyecto` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `equipos_proyectos_eventos`
--
DELIMITER $$
CREATE TRIGGER `trg_epe_ad` AFTER DELETE ON `equipos_proyectos_eventos` FOR EACH ROW BEGIN
  CALL sp_refresh_resumen_estado_eventos(OLD.id_evento);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_epe_ai` AFTER INSERT ON `equipos_proyectos_eventos` FOR EACH ROW BEGIN
  CALL sp_refresh_resumen_estado_eventos(NEW.id_evento);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_epe_init_prev_ai` AFTER INSERT ON `equipos_proyectos_eventos` FOR EACH ROW BEGIN
  DECLARE v_start DATETIME;
  SELECT fecha_hora_inicio INTO v_start
    FROM eventos WHERE id_evento = NEW.id_evento;

  IF v_start > NOW() THEN
      INSERT IGNORE INTO asistencias (id_usuario,id_evento,id_estado_previo_asistencia)
      SELECT iep.id_usuario, NEW.id_evento, 3
        FROM integrantes_equipos_proyectos iep
       WHERE iep.id_equipo_proyecto = NEW.id_equipo_proyecto
         AND iep.habilitado = 1;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_no_evento_general_insert` BEFORE INSERT ON `equipos_proyectos_eventos` FOR EACH ROW BEGIN
    DECLARE es_general_evento BOOLEAN;

    SELECT es_general INTO es_general_evento
    FROM eventos
    WHERE id_evento = NEW.id_evento;

    IF es_general_evento = 1 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No se puede asociar un evento general a un equipo.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_no_evento_general_update` BEFORE UPDATE ON `equipos_proyectos_eventos` FOR EACH ROW BEGIN
    DECLARE es_general_evento BOOLEAN;

    SELECT es_general INTO es_general_evento
    FROM eventos
    WHERE id_evento = NEW.id_evento;

    IF es_general_evento = 1 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No se puede asociar un evento general a un equipo.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_admision`
--

CREATE TABLE `estados_admision` (
  `id_estado_admision` int(11) NOT NULL,
  `nombre_estado_admision` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_asistencia`
--

CREATE TABLE `estados_asistencia` (
  `id_estado_asistencia` int(11) NOT NULL,
  `nombre_estado_asistencia` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_finales_eventos`
--

CREATE TABLE `estados_finales_eventos` (
  `id_estado_final` int(11) NOT NULL,
  `nombre_estado_final` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_previos_asistencia`
--

CREATE TABLE `estados_previos_asistencia` (
  `id_estado_previo_asistencia` int(11) NOT NULL,
  `nombre_estado_previo_asistencia` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_previos_eventos`
--

CREATE TABLE `estados_previos_eventos` (
  `id_estado_previo` int(11) NOT NULL,
  `nombre_estado_previo` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `eventos`
--

CREATE TABLE `eventos` (
  `id_evento` int(11) NOT NULL,
  `nombre_evento` varchar(100) NOT NULL,
  `lugar` varchar(100) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `id_estado_previo` int(11) DEFAULT NULL,
  `id_estado_final` int(11) DEFAULT NULL,
  `id_tipo` int(11) DEFAULT NULL,
  `encargado` int(11) DEFAULT NULL,
  `es_general` tinyint(1) DEFAULT 0,
  `boleteria_activa` tinyint(1) DEFAULT NULL COMMENT '1 = boletería habilitada; 0/NULL = deshabilitada',
  `fecha_hora_inicio` timestamp NULL DEFAULT NULL,
  `fecha_hora_termino` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `eventos`
--
DELIMITER $$
CREATE TRIGGER `trg_evento_boleteria_off_AU` AFTER UPDATE ON `eventos` FOR EACH ROW BEGIN
    /* 1) boletería pasa de “activa” a “inactiva” */
    IF OLD.boleteria_activa = 1 AND NEW.boleteria_activa = 0 THEN

        /* — Tickets, horarios, scans y usuarios —
           (cascada ya configurada)                       */
        DELETE FROM eventos_tickets
         WHERE id_evento = NEW.id_evento;

        /* — NUEVO —  
           borra todos los admins ligados a la boletería  */
        DELETE FROM ticket_admins
         WHERE id_evento = NEW.id_evento;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_eventos_ai` AFTER INSERT ON `eventos` FOR EACH ROW BEGIN
  CALL sp_refresh_resumen_estado_eventos(NEW.id_evento);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_eventos_au` AFTER UPDATE ON `eventos` FOR EACH ROW BEGIN
  IF NEW.id_estado_final    <> OLD.id_estado_final
     OR NEW.fecha_hora_inicio <> OLD.fecha_hora_inicio THEN
       CALL sp_refresh_resumen_estado_eventos(NEW.id_evento);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_eventos_estado_au` AFTER UPDATE ON `eventos` FOR EACH ROW BEGIN
  /* ─── 1) Declaraciones (siempre van primero) ────────────────── */
  DECLARE done   INT DEFAULT 0;
  DECLARE v_usr  INT;

  DECLARE cur CURSOR FOR
      SELECT DISTINCT id_usuario
        FROM asistencias
       WHERE id_evento = NEW.id_evento;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  /* ─── 2) Lógica: solo si cambió el estado final ─────────────── */
  IF NEW.id_estado_final <> OLD.id_estado_final THEN

     /* 2-a)  refresco del resumen del evento */
     CALL sp_refresh_resumen_justif_evento(NEW.id_evento);

     /* 2-b)  refresco de cada usuario que tenga asistencia       */
     OPEN cur;
       read_loop: LOOP
         FETCH cur INTO v_usr;
         IF done THEN LEAVE read_loop; END IF;

         CALL sp_refresh_resumen_justif_usuario(
               v_usr,
               DATE(NEW.fecha_hora_inicio));   -- la fecha determina el periodo
       END LOOP;
     CLOSE cur;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_eventos_update_fecha_inicio` AFTER UPDATE ON `eventos` FOR EACH ROW BEGIN
  IF NEW.fecha_hora_inicio <> OLD.fecha_hora_inicio THEN
    UPDATE `attendance_tokens`
       SET `expires_at` = NEW.fecha_hora_inicio
     WHERE `id_evento` = NEW.id_evento;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_evt_init_prev_ai` AFTER INSERT ON `eventos` FOR EACH ROW BEGIN
  CALL sp_seed_prev_event(NEW.id_evento);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_evt_init_prev_au` AFTER UPDATE ON `eventos` FOR EACH ROW BEGIN
  IF DATE(NEW.fecha_hora_inicio) <> DATE(OLD.fecha_hora_inicio) THEN
     /* 1) elimino preparaciones antiguas que aún no se confirmaron */
     DELETE FROM asistencias
      WHERE id_evento            = NEW.id_evento
        AND id_estado_asistencia IS NULL;

     /* 2) vuelvo a sembrar                                         */
     CALL sp_seed_prev_event(NEW.id_evento);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_fecha_evento_insert` BEFORE INSERT ON `eventos` FOR EACH ROW BEGIN
    IF NEW.fecha_hora_termino < NEW.fecha_hora_inicio THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La fecha y hora de término no puede ser anterior a la de inicio.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_fecha_evento_update` BEFORE UPDATE ON `eventos` FOR EACH ROW BEGIN
    IF NEW.fecha_hora_termino < NEW.fecha_hora_inicio THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La fecha y hora de término no puede ser anterior a la de inicio.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `eventos_tickets`
--

CREATE TABLE `eventos_tickets` (
  `id_evento_ticket` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL,
  `nombre_ticket` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `precio_clp` int(10) UNSIGNED DEFAULT 0,
  `cupo_total` int(10) UNSIGNED DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_estados_actividad`
--

CREATE TABLE `historial_estados_actividad` (
  `id_historial_estado_actividad` int(11) NOT NULL,
  `id_integrante_equipo_proyecto` int(11) NOT NULL,
  `id_tipo_estado_actividad` int(11) NOT NULL,
  `id_periodo` int(11) NOT NULL,
  `fecha_estado_actividad` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `integrantes_equipos_proyectos`
--

CREATE TABLE `integrantes_equipos_proyectos` (
  `id_integrante_equipo_proyecto` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_equipo_proyecto` int(11) NOT NULL,
  `id_rol` int(11) NOT NULL,
  `habilitado` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Activado, 0 = Desactivado'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `integrantes_equipos_proyectos`
--
DELIMITER $$
CREATE TRIGGER `trg_iep_init_prev_ai` AFTER INSERT ON `integrantes_equipos_proyectos` FOR EACH ROW BEGIN
  IF NEW.habilitado = 1 THEN
     INSERT IGNORE INTO asistencias (id_usuario,id_evento,id_estado_previo_asistencia)
     SELECT NEW.id_usuario, epe.id_evento, 3
       FROM equipos_proyectos_eventos epe
       JOIN eventos e ON e.id_evento = epe.id_evento
      WHERE epe.id_equipo_proyecto = NEW.id_equipo_proyecto
        AND e.fecha_hora_inicio > NOW();
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_iep_init_prev_au` AFTER UPDATE ON `integrantes_equipos_proyectos` FOR EACH ROW BEGIN
  /* 1) se habilitó */
  IF OLD.habilitado = 0 AND NEW.habilitado = 1 THEN
     INSERT IGNORE INTO asistencias (id_usuario,id_evento,id_estado_previo_asistencia)
     SELECT NEW.id_usuario, epe.id_evento, 3
       FROM equipos_proyectos_eventos epe
       JOIN eventos e ON e.id_evento = epe.id_evento
      WHERE epe.id_equipo_proyecto = NEW.id_equipo_proyecto
        AND e.fecha_hora_inicio > NOW();
  END IF;

  /* 2) se deshabilitó antes de la fecha */
  IF OLD.habilitado = 1 AND NEW.habilitado = 0 THEN
     DELETE a
       FROM asistencias a
       JOIN eventos e ON e.id_evento = a.id_evento
      WHERE a.id_usuario            = NEW.id_usuario
        AND a.id_estado_asistencia IS NULL     -- no está confirmada
        AND e.fecha_hora_inicio     > NOW()    -- evento futuro
        AND (
            e.es_general = 1
            OR EXISTS (
                  SELECT 1
                    FROM equipos_proyectos_eventos epe
                   WHERE epe.id_evento = e.id_evento
                     AND epe.id_equipo_proyecto = NEW.id_equipo_proyecto
                )
        );
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_iep_init_resumen` AFTER INSERT ON `integrantes_equipos_proyectos` FOR EACH ROW BEGIN
  CALL sp_init_resumen_usuario(NEW.id_usuario);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_rol_equipo_insert` BEFORE INSERT ON `integrantes_equipos_proyectos` FOR EACH ROW trig: BEGIN
    DECLARE rol_equipo INT;

    /* ¿A qué equipo pertenece el rol? (NULL → rol “general”) */
    SELECT id_equipo_proyecto
      INTO rol_equipo
      FROM roles
     WHERE id_rol = NEW.id_rol;

    /* 1)  Rol general ⇒ siempre permitido */
    IF rol_equipo IS NULL THEN
        LEAVE trig;
    END IF;

    /* 2)  Rol específico ⇒ debe coincidir con el equipo destino */
    IF rol_equipo <> NEW.id_equipo_proyecto THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El rol seleccionado no pertenece a este equipo.';
    END IF;
END trig
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_rol_equipo_update` BEFORE UPDATE ON `integrantes_equipos_proyectos` FOR EACH ROW trig: BEGIN
    /* ─── 1) Declaraciones – SIEMPRE al inicio ─── */
    DECLARE rol_equipo INT;

    /* ─── 2) Si no cambió ni el rol ni el equipo, salimos ─── */
    IF NEW.id_rol = OLD.id_rol
       AND NEW.id_equipo_proyecto = OLD.id_equipo_proyecto THEN
        LEAVE trig;
    END IF;

    /* ─── 3) ¿A qué equipo pertenece el rol? (NULL → rol general) ─── */
    SELECT id_equipo_proyecto
      INTO rol_equipo
      FROM roles
     WHERE id_rol = NEW.id_rol;

    /* Rol general ⇒ permitido en cualquier equipo */
    IF rol_equipo IS NULL THEN
        LEAVE trig;
    END IF;

    /* Rol exclusivo ⇒ sólo en su propio equipo */
    IF rol_equipo <> NEW.id_equipo_proyecto THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El rol actualizado no pertenece a este equipo.';
    END IF;
END trig
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `intentos_login`
--

CREATE TABLE `intentos_login` (
  `id_intento` int(10) UNSIGNED NOT NULL,
  `device_id` char(36) NOT NULL,
  `fp_id` varchar(32) DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `intento_at` datetime NOT NULL DEFAULT utc_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `intentos_login`
--
DELIMITER $$
CREATE TRIGGER `trg_intentos_BI` BEFORE INSERT ON `intentos_login` FOR EACH ROW BEGIN
    DECLARE v_tot INT DEFAULT 0;

    /* 1)  fp_id obligatorio (mismo criterio que admision_envios) */
    IF NEW.fp_id IS NULL OR NEW.fp_id = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'fp_id requerido.';
    END IF;

    /* 2)  cuenta intentos en las últimas 6 h por device_id O fp_id */
    SELECT COUNT(*)
      INTO v_tot
      FROM intentos_login
     WHERE intento_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 HOUR)
       AND (
              device_id = NEW.device_id
           OR fp_id     = NEW.fp_id
           );

    IF v_tot >= 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Dispositivo bloqueado por exceso de intentos.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `justificacion_inasistencia`
--

CREATE TABLE `justificacion_inasistencia` (
  `id_justificacion_inasistencia` int(11) NOT NULL,
  `nombre_justificacion_inasistencia` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ocupaciones`
--

CREATE TABLE `ocupaciones` (
  `id_ocupacion` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `paises`
--

CREATE TABLE `paises` (
  `id_pais` int(11) NOT NULL,
  `nombre_pais` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `periodos`
--

CREATE TABLE `periodos` (
  `id_periodo` int(11) NOT NULL,
  `nombre_periodo` varchar(50) NOT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_termino` date DEFAULT NULL,
  `es_historico` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `region_estado`
--

CREATE TABLE `region_estado` (
  `id_region_estado` int(11) NOT NULL,
  `nombre_region_estado` varchar(100) NOT NULL,
  `id_pais` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `resumen_estado_eventos_equipos_periodo`
--

CREATE TABLE `resumen_estado_eventos_equipos_periodo` (
  `id_equipo_proyecto` int(11) NOT NULL,
  `id_estado_final` int(11) NOT NULL,
  `id_periodo` int(11) NOT NULL,
  `total` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `resumen_justificaciones_eventos_periodo`
--

CREATE TABLE `resumen_justificaciones_eventos_periodo` (
  `id_evento` int(11) NOT NULL,
  `id_justificacion_inasistencia` int(11) NOT NULL,
  `id_periodo` int(11) NOT NULL,
  `total` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `resumen_justificaciones_integrantes_periodo`
--

CREATE TABLE `resumen_justificaciones_integrantes_periodo` (
  `id_usuario` int(11) NOT NULL,
  `id_justificacion_inasistencia` int(11) NOT NULL,
  `id_periodo` int(11) NOT NULL,
  `total` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `retirados`
--

CREATE TABLE `retirados` (
  `id_usuario` int(11) NOT NULL,
  `fecha_retiro` date NOT NULL,
  `razon` varchar(255) DEFAULT NULL,
  `ex_equipo` varchar(50) DEFAULT NULL,
  `es_difunto` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id_rol` int(11) NOT NULL,
  `nombre_rol` varchar(50) NOT NULL,
  `id_equipo_proyecto` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `telefonos`
--

CREATE TABLE `telefonos` (
  `id_usuario` int(11) NOT NULL,
  `telefono` varchar(16) NOT NULL,
  `es_principal` tinyint(1) DEFAULT NULL,
  `id_descripcion_telefono` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ticket_admins`
--

CREATE TABLE `ticket_admins` (
  `id_evento` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ticket_horarios`
--

CREATE TABLE `ticket_horarios` (
  `id_ticket_horario` int(11) NOT NULL,
  `id_evento` int(11) DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL,
  `fecha_fin` datetime NOT NULL,
  `nombre_horario` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ticket_scans`
--

CREATE TABLE `ticket_scans` (
  `id_ticket_scan` bigint(20) NOT NULL,
  `id_ticket_horario` int(11) NOT NULL,
  `id_ticket_usuario` int(11) NOT NULL,
  `es_ingreso` tinyint(1) NOT NULL,
  `scan_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `ticket_scans`
--
DELIMITER $$
CREATE TRIGGER `trg_scan_before_insert` BEFORE INSERT ON `ticket_scans` FOR EACH ROW BEGIN
    DECLARE v_last_ingreso TINYINT(1);
    DECLARE v_ini DATETIME;
    DECLARE v_fin DATETIME;

    /* 1)  horario válido ---------------------------------------- */
    SELECT th.fecha_inicio, th.fecha_fin
      INTO v_ini, v_fin
      FROM ticket_horarios th
     WHERE th.id_ticket_horario = NEW.id_ticket_horario
     LIMIT 1;

    IF NEW.scan_at IS NULL THEN
        SET NEW.scan_at = NOW();
    END IF;

    IF NEW.scan_at NOT BETWEEN v_ini AND v_fin THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Fuera del horario autorizado.';
    END IF;

    /* 2)  secuencia ingreso / salida ----------------------------- */
    SELECT es_ingreso
      INTO v_last_ingreso
      FROM ticket_scans
     WHERE id_ticket_horario = NEW.id_ticket_horario
       AND id_ticket_usuario = NEW.id_ticket_usuario
  ORDER BY scan_at DESC
     LIMIT 1;

    IF v_last_ingreso IS NULL THEN
        /* primer registro ⇒ forzar ingreso                         */
        SET NEW.es_ingreso = 1;
    ELSEIF v_last_ingreso = 1 AND NEW.es_ingreso = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Ya hay ingreso; marque salida antes.';
    ELSEIF v_last_ingreso = 0 AND NEW.es_ingreso = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Primero debe registrar el ingreso.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ticket_usuario`
--

CREATE TABLE `ticket_usuario` (
  `id_ticket_usuario` int(11) NOT NULL,
  `id_evento_ticket` int(11) NOT NULL,
  `fecha_inscripcion` datetime DEFAULT current_timestamp(),
  `correo_electronico` varchar(320) NOT NULL,
  `nombre_completo` varchar(120) NOT NULL,
  `contacto` varchar(16) NOT NULL,
  `edad` int(10) UNSIGNED DEFAULT NULL,
  `credencial` varchar(100) DEFAULT NULL,
  `acompanantes` varchar(255) DEFAULT NULL,
  `extras` varchar(255) DEFAULT NULL,
  `equipo` varchar(100) DEFAULT NULL,
  `alimentacion` varchar(100) DEFAULT NULL,
  `hospedaje` varchar(50) DEFAULT NULL,
  `enfermedad` varchar(255) DEFAULT NULL,
  `alergia` varchar(255) DEFAULT NULL,
  `medicamentos` varchar(255) DEFAULT NULL,
  `alimentacion_especial` varchar(255) DEFAULT NULL,
  `contacto_emergencia` varchar(100) DEFAULT NULL,
  `qr_codigo` char(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `ticket_usuario`
--
DELIMITER $$
CREATE TRIGGER `trg_ticket_usuario_cap_BI` BEFORE INSERT ON `ticket_usuario` FOR EACH ROW BEGIN
    DECLARE v_cupo   INT;
    DECLARE v_actual INT;

    SELECT cupo_total
      INTO v_cupo
      FROM eventos_tickets
     WHERE id_evento_ticket = NEW.id_evento_ticket
     LIMIT 1;

    SELECT COUNT(*)
      INTO v_actual
      FROM ticket_usuario
     WHERE id_evento_ticket = NEW.id_evento_ticket;

    IF v_actual >= v_cupo THEN
        SIGNAL SQLSTATE '45000'
           SET MESSAGE_TEXT = 'Cupo agotado para este ticket.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ticket_usuario_cap_BU` BEFORE UPDATE ON `ticket_usuario` FOR EACH ROW BEGIN
    /* DECLAREs SIEMPRE al inicio del bloque */
    DECLARE v_cupo   INT;
    DECLARE v_actual INT;

    IF NEW.id_evento_ticket <> OLD.id_evento_ticket THEN
        SELECT cupo_total
          INTO v_cupo
          FROM eventos_tickets
         WHERE id_evento_ticket = NEW.id_evento_ticket
         LIMIT 1;

        SELECT COUNT(*)
          INTO v_actual
          FROM ticket_usuario
         WHERE id_evento_ticket = NEW.id_evento_ticket;

        IF v_actual >= v_cupo THEN
            SIGNAL SQLSTATE '45000'
               SET MESSAGE_TEXT = 'El ticket de destino está lleno.';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_estados_actividad`
--

CREATE TABLE `tipos_estados_actividad` (
  `id_tipo_estado_actividad` int(11) NOT NULL,
  `nombre_tipo_estado_actividad` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_evento`
--

CREATE TABLE `tipos_evento` (
  `id_tipo` int(11) NOT NULL,
  `nombre_tipo` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tokens_usuarios`
--

CREATE TABLE `tokens_usuarios` (
  `token` varchar(64) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `expira_en` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `tokens_usuarios`
--
DELIMITER $$
CREATE TRIGGER `trg_evitar_token_a_retirado_insert` BEFORE INSERT ON `tokens_usuarios` FOR EACH ROW BEGIN
    IF EXISTS (
        SELECT 1 FROM retirados WHERE id_usuario = NEW.id_usuario
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No se puede crear un token para un usuario retirado.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_evitar_token_a_retirado_update` BEFORE UPDATE ON `tokens_usuarios` FOR EACH ROW BEGIN
    IF EXISTS (
        SELECT 1 FROM retirados WHERE id_usuario = NEW.id_usuario
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No se puede asignar este token a un usuario retirado.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int(11) NOT NULL,
  `nombres` varchar(60) NOT NULL,
  `apellido_paterno` varchar(30) NOT NULL,
  `apellido_materno` varchar(30) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `rut_dni` varchar(13) NOT NULL,
  `id_pais` int(11) NOT NULL,
  `id_region_estado` int(11) DEFAULT NULL,
  `id_ciudad_comuna` int(11) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `iglesia_ministerio` varchar(255) DEFAULT NULL,
  `profesion_oficio_estudio` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `fecha_registro` date NOT NULL,
  `ultima_actualizacion` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios_ocupaciones`
--

CREATE TABLE `usuarios_ocupaciones` (
  `id_usuario` int(11) NOT NULL,
  `id_ocupacion` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_horarios_activos`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_horarios_activos` (
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_last_estado_periodo`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_last_estado_periodo` (
`id_integrante_equipo_proyecto` int(11)
,`id_periodo` int(11)
,`id_tipo_estado_actividad` int(11)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_rep_estados_equipo_periodo`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_rep_estados_equipo_periodo` (
`id_equipo_proyecto` int(11)
,`id_periodo` int(11)
,`activos` decimal(23,0)
,`semiactivos` decimal(23,0)
,`nuevos` decimal(23,0)
,`inactivos` decimal(23,0)
,`en_espera` decimal(23,0)
,`retirados` decimal(23,0)
,`cambios` decimal(23,0)
,`total_integrantes` bigint(21)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_rep_eventos_estadofinal`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_rep_eventos_estadofinal` (
`id_equipo_proyecto` int(11)
,`id_periodo` int(11)
,`id_estado_final` int(11)
,`total` bigint(21)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_rep_justif_eventos`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_rep_justif_eventos` (
`id_periodo` int(11)
,`nombre_periodo` varchar(50)
,`id_evento` int(11)
,`nombre_evento` varchar(100)
,`fecha_evento` date
,`id_justificacion_inasistencia` int(11)
,`nombre_justificacion_inasistencia` varchar(50)
,`total` int(11)
,`porcentaje` decimal(5,2)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_rep_justif_integrantes`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_rep_justif_integrantes` (
`id_periodo` int(11)
,`nombre_periodo` varchar(50)
,`id_usuario` int(11)
,`nombre_completo` varchar(122)
,`id_justificacion_inasistencia` int(11)
,`nombre_justificacion_inasistencia` varchar(50)
,`total` int(11)
,`porcentaje` decimal(5,2)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `v_horarios_activos`
--
DROP TABLE IF EXISTS `v_horarios_activos`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_horarios_activos`  AS SELECT `h`.`id_ticket_horario` AS `id_ticket_horario`, `h`.`id_evento_ticket` AS `id_evento_ticket`, `h`.`fecha_inicio` AS `fecha_inicio`, `h`.`fecha_fin` AS `fecha_fin`, `h`.`nombre_horario` AS `nombre_horario`, `et`.`nombre_ticket` AS `nombre_ticket`, `e`.`nombre_evento` AS `nombre_evento` FROM ((`ticket_horarios` `h` join `eventos_tickets` `et` on(`h`.`id_evento_ticket` = `et`.`id_evento_ticket`)) join `eventos` `e` on(`et`.`id_evento` = `e`.`id_evento`)) WHERE `e`.`boleteria_activa` = 1 AND `et`.`activo` = 1 AND `h`.`fecha_fin` >= current_timestamp() ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_last_estado_periodo`
--
DROP TABLE IF EXISTS `v_last_estado_periodo`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_last_estado_periodo`  AS SELECT `h`.`id_integrante_equipo_proyecto` AS `id_integrante_equipo_proyecto`, `pr`.`id_periodo` AS `id_periodo`, `h`.`id_tipo_estado_actividad` AS `id_tipo_estado_actividad` FROM (`periodos` `pr` join `historial_estados_actividad` `h` on(`h`.`fecha_estado_actividad` = (select max(`h2`.`fecha_estado_actividad`) from `historial_estados_actividad` `h2` where `h2`.`id_integrante_equipo_proyecto` = `h`.`id_integrante_equipo_proyecto` and `h2`.`fecha_estado_actividad` between `pr`.`fecha_inicio` and `pr`.`fecha_termino`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_rep_estados_equipo_periodo`
--
DROP TABLE IF EXISTS `v_rep_estados_equipo_periodo`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_rep_estados_equipo_periodo`  AS SELECT `iep`.`id_equipo_proyecto` AS `id_equipo_proyecto`, `lep`.`id_periodo` AS `id_periodo`, sum(`lep`.`id_tipo_estado_actividad` = 1) AS `activos`, sum(`lep`.`id_tipo_estado_actividad` = 2) AS `semiactivos`, sum(`lep`.`id_tipo_estado_actividad` = 5) AS `nuevos`, sum(`lep`.`id_tipo_estado_actividad` = 3) AS `inactivos`, sum(`lep`.`id_tipo_estado_actividad` = 4) AS `en_espera`, sum(`lep`.`id_tipo_estado_actividad` = 6) AS `retirados`, sum(`lep`.`id_tipo_estado_actividad` = 7) AS `cambios`, count(0) AS `total_integrantes` FROM (`integrantes_equipos_proyectos` `iep` join `v_last_estado_periodo` `lep` on(`lep`.`id_integrante_equipo_proyecto` = `iep`.`id_integrante_equipo_proyecto`)) GROUP BY `iep`.`id_equipo_proyecto`, `lep`.`id_periodo` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_rep_eventos_estadofinal`
--
DROP TABLE IF EXISTS `v_rep_eventos_estadofinal`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_rep_eventos_estadofinal`  AS SELECT `ep`.`id_equipo_proyecto` AS `id_equipo_proyecto`, `get_period_id`(cast(`e`.`fecha_hora_inicio` as date)) AS `id_periodo`, `e`.`id_estado_final` AS `id_estado_final`, count(0) AS `total` FROM (`eventos` `e` join `equipos_proyectos_eventos` `ep` on(`ep`.`id_evento` = `e`.`id_evento`)) GROUP BY `ep`.`id_equipo_proyecto`, `get_period_id`(cast(`e`.`fecha_hora_inicio` as date)), `e`.`id_estado_final` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_rep_justif_eventos`
--
DROP TABLE IF EXISTS `v_rep_justif_eventos`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_rep_justif_eventos`  AS SELECT `rje`.`id_periodo` AS `id_periodo`, `p`.`nombre_periodo` AS `nombre_periodo`, `e`.`id_evento` AS `id_evento`, `e`.`nombre_evento` AS `nombre_evento`, cast(`e`.`fecha_hora_inicio` as date) AS `fecha_evento`, `rje`.`id_justificacion_inasistencia` AS `id_justificacion_inasistencia`, `j`.`nombre_justificacion_inasistencia` AS `nombre_justificacion_inasistencia`, `rje`.`total` AS `total`, `rje`.`porcentaje` AS `porcentaje` FROM (((`resumen_justificaciones_eventos_periodo` `rje` join `eventos` `e` on(`rje`.`id_evento` = `e`.`id_evento`)) join `justificacion_inasistencia` `j` on(`rje`.`id_justificacion_inasistencia` = `j`.`id_justificacion_inasistencia`)) join `periodos` `p` on(`rje`.`id_periodo` = `p`.`id_periodo`)) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_rep_justif_integrantes`
--
DROP TABLE IF EXISTS `v_rep_justif_integrantes`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_rep_justif_integrantes`  AS SELECT `rji`.`id_periodo` AS `id_periodo`, `p`.`nombre_periodo` AS `nombre_periodo`, `rji`.`id_usuario` AS `id_usuario`, concat(`u`.`nombres`,' ',`u`.`apellido_paterno`,' ',`u`.`apellido_materno`) AS `nombre_completo`, `rji`.`id_justificacion_inasistencia` AS `id_justificacion_inasistencia`, `j`.`nombre_justificacion_inasistencia` AS `nombre_justificacion_inasistencia`, `rji`.`total` AS `total`, `rji`.`porcentaje` AS `porcentaje` FROM (((`resumen_justificaciones_integrantes_periodo` `rji` join `usuarios` `u` on(`rji`.`id_usuario` = `u`.`id_usuario`)) join `justificacion_inasistencia` `j` on(`rji`.`id_justificacion_inasistencia` = `j`.`id_justificacion_inasistencia`)) join `periodos` `p` on(`rji`.`id_periodo` = `p`.`id_periodo`)) ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `admision`
--
ALTER TABLE `admision`
  ADD PRIMARY KEY (`id_usuario`),
  ADD KEY `fk_admision_estado` (`id_estado_admision`);

--
-- Indices de la tabla `admision_envios`
--
ALTER TABLE `admision_envios`
  ADD PRIMARY KEY (`id_envio`),
  ADD KEY `device_fecha` (`device_id`,`fecha`),
  ADD KEY `fp_fecha` (`fp_id`,`fecha`);

--
-- Indices de la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD PRIMARY KEY (`id_usuario`,`id_evento`),
  ADD KEY `id_evento` (`id_evento`),
  ADD KEY `id_estado_previo_asistencia` (`id_estado_previo_asistencia`),
  ADD KEY `id_justificacion_inasistencia` (`id_justificacion_inasistencia`),
  ADD KEY `idx_asist_nullprev` (`id_estado_asistencia`,`id_estado_previo_asistencia`);

--
-- Indices de la tabla `attendance_tokens`
--
ALTER TABLE `attendance_tokens`
  ADD PRIMARY KEY (`token`),
  ADD UNIQUE KEY `ux_user_event` (`id_usuario`,`id_evento`),
  ADD KEY `idx_user_event` (`id_usuario`,`id_evento`),
  ADD KEY `attendance_tokens_fk_event` (`id_evento`),
  ADD KEY `idx_attendance_expires` (`expires_at`);

--
-- Indices de la tabla `ciudad_comuna`
--
ALTER TABLE `ciudad_comuna`
  ADD PRIMARY KEY (`id_ciudad_comuna`),
  ADD KEY `id_region_estado` (`id_region_estado`);

--
-- Indices de la tabla `correos_electronicos`
--
ALTER TABLE `correos_electronicos`
  ADD PRIMARY KEY (`correo_electronico`),
  ADD KEY `id_usuario` (`id_usuario`);

--
-- Indices de la tabla `descripcion_telefonos`
--
ALTER TABLE `descripcion_telefonos`
  ADD PRIMARY KEY (`id_descripcion_telefono`);

--
-- Indices de la tabla `equipos_proyectos`
--
ALTER TABLE `equipos_proyectos`
  ADD PRIMARY KEY (`id_equipo_proyecto`),
  ADD UNIQUE KEY `nombre_equipo_proyecto` (`nombre_equipo_proyecto`);

--
-- Indices de la tabla `equipos_proyectos_eventos`
--
ALTER TABLE `equipos_proyectos_eventos`
  ADD PRIMARY KEY (`id_equipos_proyectos_eventos`),
  ADD KEY `id_equipo_proyecto` (`id_equipo_proyecto`),
  ADD KEY `id_evento` (`id_evento`);

--
-- Indices de la tabla `estados_admision`
--
ALTER TABLE `estados_admision`
  ADD PRIMARY KEY (`id_estado_admision`);

--
-- Indices de la tabla `estados_asistencia`
--
ALTER TABLE `estados_asistencia`
  ADD PRIMARY KEY (`id_estado_asistencia`),
  ADD UNIQUE KEY `nombre_estado_asistencia` (`nombre_estado_asistencia`);

--
-- Indices de la tabla `estados_finales_eventos`
--
ALTER TABLE `estados_finales_eventos`
  ADD PRIMARY KEY (`id_estado_final`),
  ADD UNIQUE KEY `nombre_estado_final` (`nombre_estado_final`);

--
-- Indices de la tabla `estados_previos_asistencia`
--
ALTER TABLE `estados_previos_asistencia`
  ADD PRIMARY KEY (`id_estado_previo_asistencia`),
  ADD UNIQUE KEY `nombre_estado_previo_asistencia` (`nombre_estado_previo_asistencia`);

--
-- Indices de la tabla `estados_previos_eventos`
--
ALTER TABLE `estados_previos_eventos`
  ADD PRIMARY KEY (`id_estado_previo`),
  ADD UNIQUE KEY `nombre_estado_previo` (`nombre_estado_previo`);

--
-- Indices de la tabla `eventos`
--
ALTER TABLE `eventos`
  ADD PRIMARY KEY (`id_evento`),
  ADD KEY `encargado` (`encargado`),
  ADD KEY `id_estado_previo` (`id_estado_previo`),
  ADD KEY `id_estado_final` (`id_estado_final`),
  ADD KEY `id_tipo` (`id_tipo`),
  ADD KEY `idx_evento_fechainicio` (`fecha_hora_inicio`,`es_general`);

--
-- Indices de la tabla `eventos_tickets`
--
ALTER TABLE `eventos_tickets`
  ADD PRIMARY KEY (`id_evento_ticket`),
  ADD KEY `fk_evt_ticket_evento` (`id_evento`);

--
-- Indices de la tabla `historial_estados_actividad`
--
ALTER TABLE `historial_estados_actividad`
  ADD PRIMARY KEY (`id_historial_estado_actividad`),
  ADD UNIQUE KEY `unq_iep_periodo` (`id_integrante_equipo_proyecto`,`id_periodo`),
  ADD KEY `id_tipo_estado_actividad` (`id_tipo_estado_actividad`);

--
-- Indices de la tabla `integrantes_equipos_proyectos`
--
ALTER TABLE `integrantes_equipos_proyectos`
  ADD PRIMARY KEY (`id_integrante_equipo_proyecto`),
  ADD UNIQUE KEY `unq_usuario_equipo` (`id_usuario`,`id_equipo_proyecto`),
  ADD KEY `id_equipo_proyecto` (`id_equipo_proyecto`),
  ADD KEY `id_rol` (`id_rol`),
  ADD KEY `idx_iep_habilitado` (`habilitado`);

--
-- Indices de la tabla `intentos_login`
--
ALTER TABLE `intentos_login`
  ADD PRIMARY KEY (`id_intento`),
  ADD KEY `idx_dev_time` (`device_id`,`intento_at`),
  ADD KEY `idx_device_time` (`device_id`,`intento_at`),
  ADD KEY `idx_ip_time` (`ip`,`intento_at`),
  ADD KEY `idx_fp_time` (`fp_id`,`intento_at`);

--
-- Indices de la tabla `justificacion_inasistencia`
--
ALTER TABLE `justificacion_inasistencia`
  ADD PRIMARY KEY (`id_justificacion_inasistencia`),
  ADD UNIQUE KEY `nombre_justificacion_inasistencia` (`nombre_justificacion_inasistencia`);

--
-- Indices de la tabla `ocupaciones`
--
ALTER TABLE `ocupaciones`
  ADD PRIMARY KEY (`id_ocupacion`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `paises`
--
ALTER TABLE `paises`
  ADD PRIMARY KEY (`id_pais`),
  ADD UNIQUE KEY `nombre_pais` (`nombre_pais`);

--
-- Indices de la tabla `periodos`
--
ALTER TABLE `periodos`
  ADD PRIMARY KEY (`id_periodo`),
  ADD UNIQUE KEY `nombre_periodo` (`nombre_periodo`);

--
-- Indices de la tabla `region_estado`
--
ALTER TABLE `region_estado`
  ADD PRIMARY KEY (`id_region_estado`),
  ADD KEY `id_pais` (`id_pais`);

--
-- Indices de la tabla `resumen_estado_eventos_equipos_periodo`
--
ALTER TABLE `resumen_estado_eventos_equipos_periodo`
  ADD PRIMARY KEY (`id_equipo_proyecto`,`id_estado_final`,`id_periodo`),
  ADD KEY `id_estado_final` (`id_estado_final`),
  ADD KEY `id_periodo` (`id_periodo`);

--
-- Indices de la tabla `resumen_justificaciones_eventos_periodo`
--
ALTER TABLE `resumen_justificaciones_eventos_periodo`
  ADD PRIMARY KEY (`id_evento`,`id_justificacion_inasistencia`,`id_periodo`),
  ADD KEY `fk_rje_justificacion` (`id_justificacion_inasistencia`),
  ADD KEY `fk_rje_periodo` (`id_periodo`);

--
-- Indices de la tabla `resumen_justificaciones_integrantes_periodo`
--
ALTER TABLE `resumen_justificaciones_integrantes_periodo`
  ADD PRIMARY KEY (`id_usuario`,`id_justificacion_inasistencia`,`id_periodo`),
  ADD KEY `fk_rji_justificacion` (`id_justificacion_inasistencia`),
  ADD KEY `fk_rji_periodo` (`id_periodo`);

--
-- Indices de la tabla `retirados`
--
ALTER TABLE `retirados`
  ADD PRIMARY KEY (`id_usuario`),
  ADD KEY `idx_retirados_fecha` (`fecha_retiro`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id_rol`),
  ADD UNIQUE KEY `nombre_rol` (`nombre_rol`),
  ADD KEY `id_equipo_proyecto` (`id_equipo_proyecto`);

--
-- Indices de la tabla `telefonos`
--
ALTER TABLE `telefonos`
  ADD PRIMARY KEY (`id_usuario`,`telefono`),
  ADD KEY `fk_telefono_descripcion` (`id_descripcion_telefono`);

--
-- Indices de la tabla `ticket_admins`
--
ALTER TABLE `ticket_admins`
  ADD PRIMARY KEY (`id_evento`,`id_usuario`),
  ADD KEY `fk_admin_user` (`id_usuario`);

--
-- Indices de la tabla `ticket_horarios`
--
ALTER TABLE `ticket_horarios`
  ADD PRIMARY KEY (`id_ticket_horario`),
  ADD KEY `idx_hor_evento` (`id_evento`);

--
-- Indices de la tabla `ticket_scans`
--
ALTER TABLE `ticket_scans`
  ADD PRIMARY KEY (`id_ticket_scan`),
  ADD KEY `fk_scan_horario` (`id_ticket_horario`),
  ADD KEY `idx_scan_fast` (`id_ticket_usuario`,`id_ticket_horario`,`scan_at`);

--
-- Indices de la tabla `ticket_usuario`
--
ALTER TABLE `ticket_usuario`
  ADD PRIMARY KEY (`id_ticket_usuario`),
  ADD UNIQUE KEY `qr_codigo` (`qr_codigo`),
  ADD KEY `fk_ticket_usr_event` (`id_evento_ticket`);

--
-- Indices de la tabla `tipos_estados_actividad`
--
ALTER TABLE `tipos_estados_actividad`
  ADD PRIMARY KEY (`id_tipo_estado_actividad`),
  ADD UNIQUE KEY `nombre_tipo_estado_actividad` (`nombre_tipo_estado_actividad`);

--
-- Indices de la tabla `tipos_evento`
--
ALTER TABLE `tipos_evento`
  ADD PRIMARY KEY (`id_tipo`),
  ADD UNIQUE KEY `nombre_tipo` (`nombre_tipo`);

--
-- Indices de la tabla `tokens_usuarios`
--
ALTER TABLE `tokens_usuarios`
  ADD PRIMARY KEY (`token`),
  ADD KEY `id_usuario` (`id_usuario`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `rut_dni` (`rut_dni`,`id_pais`),
  ADD KEY `id_region_estado` (`id_region_estado`),
  ADD KEY `id_ciudad_comuna` (`id_ciudad_comuna`),
  ADD KEY `usuarios_ibfk_2` (`id_pais`);

--
-- Indices de la tabla `usuarios_ocupaciones`
--
ALTER TABLE `usuarios_ocupaciones`
  ADD PRIMARY KEY (`id_usuario`,`id_ocupacion`),
  ADD KEY `id_ocupacion` (`id_ocupacion`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `admision_envios`
--
ALTER TABLE `admision_envios`
  MODIFY `id_envio` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ciudad_comuna`
--
ALTER TABLE `ciudad_comuna`
  MODIFY `id_ciudad_comuna` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `descripcion_telefonos`
--
ALTER TABLE `descripcion_telefonos`
  MODIFY `id_descripcion_telefono` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `equipos_proyectos`
--
ALTER TABLE `equipos_proyectos`
  MODIFY `id_equipo_proyecto` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `equipos_proyectos_eventos`
--
ALTER TABLE `equipos_proyectos_eventos`
  MODIFY `id_equipos_proyectos_eventos` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `estados_admision`
--
ALTER TABLE `estados_admision`
  MODIFY `id_estado_admision` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `estados_asistencia`
--
ALTER TABLE `estados_asistencia`
  MODIFY `id_estado_asistencia` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `estados_finales_eventos`
--
ALTER TABLE `estados_finales_eventos`
  MODIFY `id_estado_final` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `estados_previos_asistencia`
--
ALTER TABLE `estados_previos_asistencia`
  MODIFY `id_estado_previo_asistencia` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `estados_previos_eventos`
--
ALTER TABLE `estados_previos_eventos`
  MODIFY `id_estado_previo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `eventos`
--
ALTER TABLE `eventos`
  MODIFY `id_evento` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `eventos_tickets`
--
ALTER TABLE `eventos_tickets`
  MODIFY `id_evento_ticket` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `historial_estados_actividad`
--
ALTER TABLE `historial_estados_actividad`
  MODIFY `id_historial_estado_actividad` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `integrantes_equipos_proyectos`
--
ALTER TABLE `integrantes_equipos_proyectos`
  MODIFY `id_integrante_equipo_proyecto` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `intentos_login`
--
ALTER TABLE `intentos_login`
  MODIFY `id_intento` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `justificacion_inasistencia`
--
ALTER TABLE `justificacion_inasistencia`
  MODIFY `id_justificacion_inasistencia` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ocupaciones`
--
ALTER TABLE `ocupaciones`
  MODIFY `id_ocupacion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `paises`
--
ALTER TABLE `paises`
  MODIFY `id_pais` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `periodos`
--
ALTER TABLE `periodos`
  MODIFY `id_periodo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `region_estado`
--
ALTER TABLE `region_estado`
  MODIFY `id_region_estado` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id_rol` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ticket_horarios`
--
ALTER TABLE `ticket_horarios`
  MODIFY `id_ticket_horario` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ticket_scans`
--
ALTER TABLE `ticket_scans`
  MODIFY `id_ticket_scan` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ticket_usuario`
--
ALTER TABLE `ticket_usuario`
  MODIFY `id_ticket_usuario` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tipos_estados_actividad`
--
ALTER TABLE `tipos_estados_actividad`
  MODIFY `id_tipo_estado_actividad` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tipos_evento`
--
ALTER TABLE `tipos_evento`
  MODIFY `id_tipo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `admision`
--
ALTER TABLE `admision`
  ADD CONSTRAINT `admision_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
  ADD CONSTRAINT `admision_ibfk_2` FOREIGN KEY (`id_estado_admision`) REFERENCES `estados_admision` (`id_estado_admision`),
  ADD CONSTRAINT `fk_admision_estado` FOREIGN KEY (`id_estado_admision`) REFERENCES `estados_admision` (`id_estado_admision`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_admision_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD CONSTRAINT `asistencias_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `asistencias_ibfk_2` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `asistencias_ibfk_3` FOREIGN KEY (`id_estado_previo_asistencia`) REFERENCES `estados_previos_asistencia` (`id_estado_previo_asistencia`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `asistencias_ibfk_4` FOREIGN KEY (`id_estado_asistencia`) REFERENCES `estados_asistencia` (`id_estado_asistencia`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `asistencias_ibfk_5` FOREIGN KEY (`id_justificacion_inasistencia`) REFERENCES `justificacion_inasistencia` (`id_justificacion_inasistencia`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `attendance_tokens`
--
ALTER TABLE `attendance_tokens`
  ADD CONSTRAINT `attendance_tokens_fk_event` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `attendance_tokens_fk_user` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `ciudad_comuna`
--
ALTER TABLE `ciudad_comuna`
  ADD CONSTRAINT `ciudad_comuna_ibfk_1` FOREIGN KEY (`id_region_estado`) REFERENCES `region_estado` (`id_region_estado`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `correos_electronicos`
--
ALTER TABLE `correos_electronicos`
  ADD CONSTRAINT `correos_electronicos_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `equipos_proyectos_eventos`
--
ALTER TABLE `equipos_proyectos_eventos`
  ADD CONSTRAINT `equipos_proyectos_eventos_ibfk_1` FOREIGN KEY (`id_equipo_proyecto`) REFERENCES `equipos_proyectos` (`id_equipo_proyecto`) ON UPDATE CASCADE,
  ADD CONSTRAINT `equipos_proyectos_eventos_ibfk_2` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `eventos`
--
ALTER TABLE `eventos`
  ADD CONSTRAINT `eventos_ibfk_1` FOREIGN KEY (`encargado`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `eventos_ibfk_2` FOREIGN KEY (`id_estado_previo`) REFERENCES `estados_previos_eventos` (`id_estado_previo`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `eventos_ibfk_3` FOREIGN KEY (`id_estado_final`) REFERENCES `estados_finales_eventos` (`id_estado_final`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `eventos_ibfk_4` FOREIGN KEY (`id_tipo`) REFERENCES `tipos_evento` (`id_tipo`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `eventos_tickets`
--
ALTER TABLE `eventos_tickets`
  ADD CONSTRAINT `fk_evt_ticket_evento` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE;

--
-- Filtros para la tabla `historial_estados_actividad`
--
ALTER TABLE `historial_estados_actividad`
  ADD CONSTRAINT `historial_estados_actividad_ibfk_1` FOREIGN KEY (`id_integrante_equipo_proyecto`) REFERENCES `integrantes_equipos_proyectos` (`id_integrante_equipo_proyecto`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `historial_estados_actividad_ibfk_2` FOREIGN KEY (`id_tipo_estado_actividad`) REFERENCES `tipos_estados_actividad` (`id_tipo_estado_actividad`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `integrantes_equipos_proyectos`
--
ALTER TABLE `integrantes_equipos_proyectos`
  ADD CONSTRAINT `integrantes_equipos_proyectos_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `integrantes_equipos_proyectos_ibfk_2` FOREIGN KEY (`id_equipo_proyecto`) REFERENCES `equipos_proyectos` (`id_equipo_proyecto`) ON UPDATE CASCADE,
  ADD CONSTRAINT `integrantes_equipos_proyectos_ibfk_3` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `region_estado`
--
ALTER TABLE `region_estado`
  ADD CONSTRAINT `region_estado_ibfk_1` FOREIGN KEY (`id_pais`) REFERENCES `paises` (`id_pais`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `resumen_estado_eventos_equipos_periodo`
--
ALTER TABLE `resumen_estado_eventos_equipos_periodo`
  ADD CONSTRAINT `resumen_estado_eventos_equipos_periodo_ibfk_1` FOREIGN KEY (`id_equipo_proyecto`) REFERENCES `equipos_proyectos` (`id_equipo_proyecto`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `resumen_estado_eventos_equipos_periodo_ibfk_2` FOREIGN KEY (`id_estado_final`) REFERENCES `estados_finales_eventos` (`id_estado_final`) ON UPDATE CASCADE,
  ADD CONSTRAINT `resumen_estado_eventos_equipos_periodo_ibfk_3` FOREIGN KEY (`id_periodo`) REFERENCES `periodos` (`id_periodo`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `resumen_justificaciones_eventos_periodo`
--
ALTER TABLE `resumen_justificaciones_eventos_periodo`
  ADD CONSTRAINT `fk_rje_evento` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rje_justificacion` FOREIGN KEY (`id_justificacion_inasistencia`) REFERENCES `justificacion_inasistencia` (`id_justificacion_inasistencia`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rje_periodo` FOREIGN KEY (`id_periodo`) REFERENCES `periodos` (`id_periodo`) ON UPDATE CASCADE,
  ADD CONSTRAINT `resumen_justificaciones_eventos_periodo_ibfk_1` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`),
  ADD CONSTRAINT `resumen_justificaciones_eventos_periodo_ibfk_2` FOREIGN KEY (`id_justificacion_inasistencia`) REFERENCES `justificacion_inasistencia` (`id_justificacion_inasistencia`),
  ADD CONSTRAINT `resumen_justificaciones_eventos_periodo_ibfk_3` FOREIGN KEY (`id_periodo`) REFERENCES `periodos` (`id_periodo`);

--
-- Filtros para la tabla `resumen_justificaciones_integrantes_periodo`
--
ALTER TABLE `resumen_justificaciones_integrantes_periodo`
  ADD CONSTRAINT `fk_rji_justificacion` FOREIGN KEY (`id_justificacion_inasistencia`) REFERENCES `justificacion_inasistencia` (`id_justificacion_inasistencia`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rji_periodo` FOREIGN KEY (`id_periodo`) REFERENCES `periodos` (`id_periodo`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rji_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `resumen_justificaciones_integrantes_periodo_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
  ADD CONSTRAINT `resumen_justificaciones_integrantes_periodo_ibfk_2` FOREIGN KEY (`id_justificacion_inasistencia`) REFERENCES `justificacion_inasistencia` (`id_justificacion_inasistencia`),
  ADD CONSTRAINT `resumen_justificaciones_integrantes_periodo_ibfk_3` FOREIGN KEY (`id_periodo`) REFERENCES `periodos` (`id_periodo`);

--
-- Filtros para la tabla `retirados`
--
ALTER TABLE `retirados`
  ADD CONSTRAINT `retirados_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`id_equipo_proyecto`) REFERENCES `equipos_proyectos` (`id_equipo_proyecto`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `telefonos`
--
ALTER TABLE `telefonos`
  ADD CONSTRAINT `fk_telefono_descripcion` FOREIGN KEY (`id_descripcion_telefono`) REFERENCES `descripcion_telefonos` (`id_descripcion_telefono`) ON UPDATE CASCADE,
  ADD CONSTRAINT `telefonos_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `ticket_admins`
--
ALTER TABLE `ticket_admins`
  ADD CONSTRAINT `fk_admin_evento` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_admin_user` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ticket_horarios`
--
ALTER TABLE `ticket_horarios`
  ADD CONSTRAINT `fk_hor_evento` FOREIGN KEY (`id_evento`) REFERENCES `eventos` (`id_evento`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `ticket_scans`
--
ALTER TABLE `ticket_scans`
  ADD CONSTRAINT `fk_scan_horario` FOREIGN KEY (`id_ticket_horario`) REFERENCES `ticket_horarios` (`id_ticket_horario`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_scan_usuario` FOREIGN KEY (`id_ticket_usuario`) REFERENCES `ticket_usuario` (`id_ticket_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ticket_usuario`
--
ALTER TABLE `ticket_usuario`
  ADD CONSTRAINT `fk_ticket_usr_event` FOREIGN KEY (`id_evento_ticket`) REFERENCES `eventos_tickets` (`id_evento_ticket`) ON DELETE CASCADE;

--
-- Filtros para la tabla `tokens_usuarios`
--
ALTER TABLE `tokens_usuarios`
  ADD CONSTRAINT `tokens_usuarios_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `usuarios_ibfk_2` FOREIGN KEY (`id_pais`) REFERENCES `paises` (`id_pais`) ON UPDATE CASCADE,
  ADD CONSTRAINT `usuarios_ibfk_3` FOREIGN KEY (`id_region_estado`) REFERENCES `region_estado` (`id_region_estado`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `usuarios_ibfk_4` FOREIGN KEY (`id_ciudad_comuna`) REFERENCES `ciudad_comuna` (`id_ciudad_comuna`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `usuarios_ocupaciones`
--
ALTER TABLE `usuarios_ocupaciones`
  ADD CONSTRAINT `usuarios_ocupaciones_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `usuarios_ocupaciones_ibfk_2` FOREIGN KEY (`id_ocupacion`) REFERENCES `ocupaciones` (`id_ocupacion`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
