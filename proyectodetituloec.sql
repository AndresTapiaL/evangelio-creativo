-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 06-07-2025 a las 02:39:40
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

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_init_resumen_usuario` (IN `p_usuario` INT)   BEGIN
   /* justificaciones y periodos objetivo */
   INSERT IGNORE INTO resumen_justificaciones_integrantes_periodo
         (id_usuario,id_justificacion_inasistencia,id_periodo,total,porcentaje)
   SELECT p_usuario,
          ji.id_justificacion_inasistencia,
          pr.id_periodo,
          0,0
     FROM justificacion_inasistencia ji
     CROSS JOIN (
        SELECT id_periodo FROM periodos
         WHERE es_historico = 1
            OR nombre_periodo LIKE CONCAT(YEAR(CURDATE()),'-%')  /* año actual */
     ) pr;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_rebuild_all_resumenes` ()   BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_evt INT;
  DECLARE v_usr INT;
  DECLARE cur_evt CURSOR FOR SELECT id_evento FROM eventos;
  DECLARE cur_usr CURSOR FOR
         SELECT DISTINCT id_usuario
           FROM asistencias;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  /* ───── eventos → 2 tablas de resumen ───── */
  OPEN cur_evt;
  read_evt: LOOP
     FETCH cur_evt INTO v_evt;
     IF done = 1 THEN LEAVE read_evt; END IF;
     CALL sp_refresh_resumen_estado_eventos(v_evt);
     CALL sp_refresh_resumen_justif_evento(v_evt);
  END LOOP;
  CLOSE cur_evt;

  /* ───── usuarios → resumen por integrante ───── */
  SET done = 0;
  OPEN cur_usr;
  read_usr: LOOP
     FETCH cur_usr INTO v_usr;
     IF done = 1 THEN LEAVE read_usr; END IF;
     /* cualquier fecha dentro del periodo vale; usamos hoy */
     CALL sp_refresh_resumen_justif_usuario(v_usr, CURRENT_DATE());
  END LOOP;
  CLOSE cur_usr;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_refresh_resumen_estado_eventos` (IN `p_evento` INT)   BEGIN
  DECLARE v_periodo INT;
  /* (a) Periodo del evento */
  SELECT get_period_id(DATE(fecha_hora_inicio))
    INTO v_periodo
    FROM eventos
   WHERE id_evento = p_evento;

  /* (b) recalcular totales     */
  INSERT INTO resumen_estado_eventos_equipos_periodo
        (id_equipo_proyecto, id_estado_final, id_periodo, total, porcentaje)
  SELECT
         base.id_equipo_proyecto,
         base.id_estado_final,
         v_periodo                                     AS id_periodo,
         base.total,
         ROUND(100 * base.total / tot.total_equipo,2)  AS pct
  FROM
       /* resumen por equipo/estado_final */
       (
         SELECT ep.id_equipo_proyecto,
                e.id_estado_final,
                COUNT(*) AS total
           FROM eventos                   e
           JOIN equipos_proyectos_eventos ep ON ep.id_evento = e.id_evento
           /* solo los equipos vinculados al evento dado */
           WHERE ep.id_equipo_proyecto IN (
                   SELECT id_equipo_proyecto
                     FROM equipos_proyectos_eventos
                    WHERE id_evento = p_evento
                 )
             AND get_period_id(DATE(e.fecha_hora_inicio)) = v_periodo
           GROUP BY ep.id_equipo_proyecto, e.id_estado_final
       ) base
  JOIN
       /* total por equipo (denominador del porcentaje) */
       (
         SELECT ep.id_equipo_proyecto,
                COUNT(*) AS total_equipo
           FROM eventos                   e
           JOIN equipos_proyectos_eventos ep ON ep.id_evento = e.id_evento
           WHERE ep.id_equipo_proyecto IN (
                   SELECT id_equipo_proyecto
                     FROM equipos_proyectos_eventos
                    WHERE id_evento = p_evento
                 )
             AND get_period_id(DATE(e.fecha_hora_inicio)) = v_periodo
           GROUP BY ep.id_equipo_proyecto
       ) tot USING (id_equipo_proyecto)
  ON DUPLICATE KEY UPDATE
      total      = VALUES(total),
      porcentaje = VALUES(porcentaje);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_refresh_resumen_justif_evento` (IN `p_evento` INT)   proc: BEGIN
  DECLARE v_periodo INT;
  DECLARE v_total   INT;

  /* 0) Obtengo el período del evento */
  SELECT get_period_id(DATE(fecha_hora_inicio))
    INTO v_periodo
    FROM eventos
   WHERE id_evento = p_evento;

  /* 0.1) Si el evento NO está “Realizado” (id_estado_final ≠ 4), borro y salgo */
  IF (SELECT id_estado_final FROM eventos WHERE id_evento = p_evento) <> 4 THEN
     DELETE FROM resumen_justificaciones_eventos_periodo
      WHERE id_evento = p_evento
        AND id_periodo = v_periodo;
     LEAVE proc;
  END IF;

  /* 0.2) Si sí está “Realizado”, borro todas las filas antiguas de este evento+período */
  DELETE FROM resumen_justificaciones_eventos_periodo
   WHERE id_evento = p_evento
     AND id_periodo = v_periodo;

  /* 1) Construyo el universo de participantes elegibles */
  DROP TEMPORARY TABLE IF EXISTS tmp_uni;
  CREATE TEMPORARY TABLE tmp_uni ENGINE = MEMORY AS
    SELECT DISTINCT iep.id_usuario
      FROM equipos_proyectos_eventos  epe
      JOIN integrantes_equipos_proyectos iep
           ON iep.id_equipo_proyecto = epe.id_equipo_proyecto
      JOIN eventos ev                 ON ev.id_evento = epe.id_evento
      JOIN usuarios u                 ON u.id_usuario = iep.id_usuario
     WHERE epe.id_evento = p_evento
       AND u.fecha_registro <= DATE(ev.fecha_hora_inicio);

  SELECT COUNT(*) INTO v_total FROM tmp_uni;

  /* 1.1) Si no hay participantes elegibles, limpio y salgo */
  IF v_total = 0 THEN
     DROP TEMPORARY TABLE tmp_uni;
     LEAVE proc;
  END IF;

  /* 2) Inserto los conteos reales para cada justificación (o “No participó” = 11) */
  INSERT INTO resumen_justificaciones_eventos_periodo
        (id_evento,
         id_justificacion_inasistencia,
         id_periodo,
         total,
         porcentaje)
  SELECT p_evento,
         justif,
         v_periodo,
         COUNT(*)                     AS total,
         ROUND(100*COUNT(*)/v_total,2) AS pct
    FROM (
          SELECT tu.id_usuario,
                 COALESCE(a.id_justificacion_inasistencia,11) AS justif
            FROM tmp_uni            tu
            LEFT JOIN asistencias   a
                   ON a.id_usuario = tu.id_usuario
                  AND a.id_evento  = p_evento
         ) x
   GROUP BY justif;

  /* 3) Completo con total=0 las justificaciones que no aparecieron en el paso anterior */
  INSERT IGNORE INTO resumen_justificaciones_eventos_periodo
        (id_evento,
         id_justificacion_inasistencia,
         id_periodo,
         total,
         porcentaje)
  SELECT  p_evento,
          ji.id_justificacion_inasistencia,
          v_periodo,
          0,0
    FROM  justificacion_inasistencia ji;

  DROP TEMPORARY TABLE tmp_uni;
END proc$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_refresh_resumen_justif_usuario` (IN `p_usuario` INT, IN `p_fecha` DATE)   proc: BEGIN
  DECLARE v_periodo INT;  DECLARE v_total INT;
  SET v_periodo = get_period_id(p_fecha);

  DROP TEMPORARY TABLE IF EXISTS tmp_asist;
  CREATE TEMPORARY TABLE tmp_asist ENGINE = MEMORY AS
  SELECT COALESCE(a.id_justificacion_inasistencia,11) AS justif
    FROM asistencias               a
    JOIN eventos                   e  ON e.id_evento = a.id_evento
    JOIN equipos_proyectos_eventos ep ON ep.id_evento = e.id_evento
    JOIN integrantes_equipos_proyectos ie
                                   ON ie.id_equipo_proyecto = ep.id_equipo_proyecto
   WHERE a.id_usuario   = p_usuario
     AND ie.id_usuario  = p_usuario
     AND e.es_general   = 0
     AND e.id_estado_final = 4           -- << SOLO “Realizado”
     AND get_period_id(DATE(e.fecha_hora_inicio)) = v_periodo;

  SELECT COUNT(*) INTO v_total FROM tmp_asist;
  IF v_total = 0 THEN
     DELETE FROM resumen_justificaciones_integrantes_periodo
      WHERE id_usuario = p_usuario AND id_periodo = v_periodo;
     DROP TEMPORARY TABLE tmp_asist; LEAVE proc;
  END IF;

  INSERT INTO resumen_justificaciones_integrantes_periodo
        (id_usuario,id_justificacion_inasistencia,id_periodo,total,porcentaje)
  SELECT p_usuario, justif, v_periodo,
         COUNT(*), ROUND(100*COUNT(*)/v_total,2)
    FROM tmp_asist GROUP BY justif
  ON DUPLICATE KEY UPDATE total=VALUES(total), porcentaje=VALUES(porcentaje);

  DROP TEMPORARY TABLE tmp_asist;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_seed_prev_event` (IN `p_evento` INT)   BEGIN
  DECLARE v_start   DATETIME;
  DECLARE v_general TINYINT(1);

  SELECT fecha_hora_inicio, es_general
    INTO v_start, v_general
    FROM eventos
   WHERE id_evento = p_evento;

  /* Solo si es futuro ------------------------------------ */
  IF v_start > NOW() THEN
     IF v_general = 1 THEN
        /* Evento marcado como “General” → todos los habilitados */
        INSERT IGNORE INTO asistencias (id_usuario,id_evento,id_estado_previo_asistencia)
        SELECT id_usuario, p_evento, 3
          FROM integrantes_equipos_proyectos
         WHERE habilitado = 1;
     ELSE
        /* Evento asociado a equipos específicos              */
        INSERT IGNORE INTO asistencias (id_usuario,id_evento,id_estado_previo_asistencia)
        SELECT iep.id_usuario, p_evento, 3
          FROM equipos_proyectos_eventos epe
          JOIN integrantes_equipos_proyectos iep
                ON iep.id_equipo_proyecto = epe.id_equipo_proyecto
         WHERE epe.id_evento = p_evento
           AND iep.habilitado = 1;
     END IF;
  END IF;
END$$

--
-- Funciones
--
CREATE DEFINER=`root`@`localhost` FUNCTION `get_period_id` (`fev` DATE) RETURNS INT(11) DETERMINISTIC BEGIN
  /* 1) Declaraciones van inmediatamente tras BEGIN */
  DECLARE yy   SMALLINT;
  DECLARE mm   TINYINT;
  DECLARE nom  VARCHAR(20);
  DECLARE pid  INT;

  /* 2) Fechas nulas / 0000-00-00 → periodo Histórico               */
  IF fev IS NULL
     OR fev = '0000-00-00'
     OR YEAR(fev) = 0 THEN
     SELECT id_periodo
       INTO pid
       FROM periodos
      WHERE es_historico = 1
      LIMIT 1;
     RETURN pid;
  END IF;

  /* 3) Lógica normal de cuatrimestres                              */
  SET yy = YEAR(fev);
  SET mm = MONTH(fev);

  CASE
    WHEN mm BETWEEN 1 AND 4 THEN SET nom = CONCAT(yy,'-T1');
    WHEN mm BETWEEN 5 AND 8 THEN SET nom = CONCAT(yy,'-T2');
    ELSE                         SET nom = CONCAT(yy,'-T3');
  END CASE;

  /* Buscar (o crear) el periodo correspondiente                    */
  SELECT id_periodo
    INTO pid
    FROM periodos
   WHERE nombre_periodo = nom
   LIMIT 1;

  IF pid IS NULL THEN
     INSERT INTO periodos(nombre_periodo,fecha_inicio,fecha_termino)
     VALUES (
       nom,
       CASE
         WHEN mm BETWEEN 1 AND 4 THEN CONCAT(yy,'-01-01')
         WHEN mm BETWEEN 5 AND 8 THEN CONCAT(yy,'-05-01')
         ELSE                         CONCAT(yy,'-09-01')
       END,
       CASE
         WHEN mm BETWEEN 1 AND 4 THEN CONCAT(yy,'-04-30')
         WHEN mm BETWEEN 5 AND 8 THEN CONCAT(yy,'-08-31')
         ELSE                         CONCAT(yy,'-12-31')
       END
     );
     SET pid = LAST_INSERT_ID();
  END IF;

  RETURN pid;
END$$

DELIMITER ;

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

--
-- Volcado de datos para la tabla `admision`
--

INSERT INTO `admision` (`id_usuario`, `id_estado_admision`, `liderazgo`, `nos_conoces`, `proposito`, `motivacion`) VALUES
(4, 4, 'LO', 'Por un amigo/familiar', 'Aprender de evangelismo', 3);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admision_envios`
--

CREATE TABLE `admision_envios` (
  `id_envio` int(10) UNSIGNED NOT NULL,
  `device_id` char(36) NOT NULL,
  `fp_id` varchar(32) DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `fecha` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `admision_envios`
--

INSERT INTO `admision_envios` (`id_envio`, `device_id`, `fp_id`, `ip`, `fecha`) VALUES
(1, '', NULL, '::1', '2025-06-25'),
(2, '', NULL, '::1', '2025-06-25'),
(3, '', NULL, '::1', '2025-06-26'),
(4, '', NULL, '::1', '2025-06-26'),
(5, '', NULL, '::1', '2025-06-26'),
(6, '', NULL, '::1', '2025-06-26'),
(7, '', NULL, '::1', '2025-06-26'),
(8, '', NULL, '192.168.1.12', '2025-06-26'),
(9, '', NULL, '192.168.1.11', '2025-07-03'),
(10, '', NULL, '192.168.1.11', '2025-07-03');

--
-- Disparadores `admision_envios`
--
DELIMITER $$
CREATE TRIGGER `trg_adm_envios_BI` BEFORE INSERT ON `admision_envios` FOR EACH ROW BEGIN
    DECLARE v_tot INT DEFAULT 0;

    /* cuenta envíos del mismo día que coincidan con *cualquiera*
       de los dos identificadores */
    SELECT COUNT(*) INTO v_tot
      FROM admision_envios
     WHERE fecha       = CURDATE()
       AND (
             device_id = NEW.device_id
             OR (NEW.fp_id IS NOT NULL AND fp_id = NEW.fp_id)
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
-- Volcado de datos para la tabla `asistencias`
--

INSERT INTO `asistencias` (`id_usuario`, `id_evento`, `id_estado_previo_asistencia`, `id_estado_asistencia`, `id_justificacion_inasistencia`, `descripcion_otros`) VALUES
(1, 1, 1, 1, 10, NULL),
(1, 15, 1, 2, NULL, NULL),
(1, 25, 2, 3, NULL, NULL),
(1, 28, NULL, 1, 10, NULL),
(1, 29, 2, 1, NULL, NULL),
(1, 30, 1, 2, NULL, NULL),
(1, 31, 1, 2, NULL, NULL),
(1, 32, NULL, 2, 11, NULL),
(1, 36, NULL, 3, 7, NULL),
(1, 49, NULL, 1, 10, NULL),
(1, 50, 3, 3, 1, NULL),
(1, 53, 2, NULL, NULL, NULL),
(3, 3, 1, 2, NULL, NULL),
(3, 53, 2, NULL, NULL, NULL),
(4, 4, 2, 3, 4, NULL),
(4, 15, 1, 2, NULL, NULL),
(4, 25, 1, 2, NULL, NULL),
(4, 53, 3, NULL, NULL, NULL),
(5, 5, 1, 2, NULL, NULL),
(11, 19, 3, NULL, NULL, NULL),
(11, 53, 3, NULL, NULL, NULL),
(111, 53, 3, NULL, NULL, NULL),
(136, 19, 3, NULL, NULL, NULL),
(136, 53, 3, NULL, NULL, NULL),
(137, 19, 3, NULL, NULL, NULL),
(137, 53, 3, NULL, NULL, NULL),
(143, 19, 3, NULL, NULL, NULL),
(143, 53, 3, NULL, NULL, NULL),
(144, 19, 3, NULL, NULL, NULL),
(144, 53, 3, NULL, NULL, NULL);

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

--
-- Volcado de datos para la tabla `ciudad_comuna`
--

INSERT INTO `ciudad_comuna` (`id_ciudad_comuna`, `nombre_ciudad_comuna`, `id_region_estado`) VALUES
(1, 'Santiago', 1),
(2, 'La Plata', 2),
(3, 'Miraflores', 3),
(4, 'El Alto', 4),
(5, 'San Lorenzo', 5),
(6, 'Osorno', 6);

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
-- Volcado de datos para la tabla `correos_electronicos`
--

INSERT INTO `correos_electronicos` (`correo_electronico`, `id_usuario`, `boletin`, `verified`, `verify_token`, `token_expires`) VALUES
('2222@example.com', 110, 1, 0, NULL, NULL),
('a@example.com', 144, 1, 0, NULL, NULL),
('aa@gmexample.com', 141, 1, 0, NULL, NULL),
('and.tapia.2001@gmail.com', 1, 1, 1, NULL, NULL),
('D@gmail.com', 5, 1, 0, NULL, NULL),
('dfghjk@gmail.com', 96, 1, 0, NULL, NULL),
('fu@example.com', 137, 1, 0, NULL, NULL),
('jk@gmail.com', 105, 1, 0, NULL, NULL),
('l@example.com', 143, 1, 0, NULL, NULL),
('leonardo.tapia.2001@gmail.com', 3, 1, 1, NULL, NULL),
('leonardoelias.tapia@alumnos.ulagos.cl', 142, 1, 0, NULL, NULL),
('N@example.com', 111, 1, 0, NULL, NULL),
('p@ge.com', 140, 1, 0, NULL, NULL),
('pkk@g.com', 136, 1, 0, NULL, NULL),
('y@example.com', 4, 1, 0, NULL, NULL),
('Yayo@example.com', 90, 1, 0, NULL, NULL),
('yO@example.com', 11, 1, 0, NULL, NULL);

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

--
-- Volcado de datos para la tabla `descripcion_telefonos`
--

INSERT INTO `descripcion_telefonos` (`id_descripcion_telefono`, `nombre_descripcion_telefono`) VALUES
(1, 'Solo llamadas'),
(2, 'Solo WhatsApp'),
(3, 'Llamadas y WhatsApp'),
(4, 'Número de emergencia');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `equipos_proyectos`
--

CREATE TABLE `equipos_proyectos` (
  `id_equipo_proyecto` int(11) NOT NULL,
  `es_equipo` tinyint(1) DEFAULT NULL,
  `nombre_equipo_proyecto` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `equipos_proyectos`
--

INSERT INTO `equipos_proyectos` (`id_equipo_proyecto`, `es_equipo`, `nombre_equipo_proyecto`) VALUES
(1, 0, 'Liderazgo Nacional'),
(2, 0, 'Liderazgo'),
(3, 0, 'Misión Joven'),
(4, 1, 'Biobío'),
(5, 1, 'Los Lagos'),
(6, 0, 'Admisión'),
(16, 1, 'Coquimbo'),
(17, 1, 'Valparaíso'),
(18, 1, 'Metropolitana'),
(19, 1, 'O\'Higgins'),
(20, 1, 'Maule'),
(21, 1, 'Ñuble'),
(22, 1, 'Embajadores'),
(31, 0, 'Alabanza'),
(32, 0, 'Danza y Teatro'),
(33, 0, 'Kidsioneros'),
(34, 0, 'Finanzas'),
(35, 0, 'Comunicaciones'),
(36, 0, 'Profesionales al servicio'),
(37, 0, 'Intercesión');

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
-- Volcado de datos para la tabla `equipos_proyectos_eventos`
--

INSERT INTO `equipos_proyectos_eventos` (`id_equipos_proyectos_eventos`, `id_equipo_proyecto`, `id_evento`) VALUES
(3, 4, 4),
(46, 4, 19),
(51, 5, 15),
(88, 5, 31),
(89, 18, 31),
(105, 22, 37),
(108, 4, 25),
(109, 5, 25),
(111, 5, 41),
(112, 18, 41),
(113, 17, 42),
(114, 17, 45),
(115, 21, 46),
(116, 17, 47),
(123, 5, 28),
(124, 18, 28),
(126, 1, 49),
(128, 1, 50),
(129, 1, 5),
(130, 5, 32),
(131, 18, 32),
(132, 5, 36),
(136, 5, 52),
(137, 4, 53),
(138, 5, 53),
(140, 5, 51);

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

--
-- Volcado de datos para la tabla `estados_admision`
--

INSERT INTO `estados_admision` (`id_estado_admision`, `nombre_estado_admision`) VALUES
(1, 'Contactado'),
(2, 'Agendado'),
(3, 'Ingresado'),
(4, 'Pendiente'),
(5, 'No llegó'),
(6, 'Reagendar'),
(7, 'No ingresa');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_asistencia`
--

CREATE TABLE `estados_asistencia` (
  `id_estado_asistencia` int(11) NOT NULL,
  `nombre_estado_asistencia` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estados_asistencia`
--

INSERT INTO `estados_asistencia` (`id_estado_asistencia`, `nombre_estado_asistencia`) VALUES
(2, 'Ausente'),
(3, 'Justificado'),
(1, 'Presente');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_finales_eventos`
--

CREATE TABLE `estados_finales_eventos` (
  `id_estado_final` int(11) NOT NULL,
  `nombre_estado_final` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estados_finales_eventos`
--

INSERT INTO `estados_finales_eventos` (`id_estado_final`, `nombre_estado_final`) VALUES
(3, 'En pausa'),
(2, 'Organizando'),
(6, 'Postergado'),
(1, 'Preparado'),
(4, 'Realizado'),
(5, 'Suspendido');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_previos_asistencia`
--

CREATE TABLE `estados_previos_asistencia` (
  `id_estado_previo_asistencia` int(11) NOT NULL,
  `nombre_estado_previo_asistencia` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estados_previos_asistencia`
--

INSERT INTO `estados_previos_asistencia` (`id_estado_previo_asistencia`, `nombre_estado_previo_asistencia`) VALUES
(2, 'Ausente'),
(3, 'En espera'),
(1, 'Presente');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados_previos_eventos`
--

CREATE TABLE `estados_previos_eventos` (
  `id_estado_previo` int(11) NOT NULL,
  `nombre_estado_previo` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estados_previos_eventos`
--

INSERT INTO `estados_previos_eventos` (`id_estado_previo`, `nombre_estado_previo`) VALUES
(1, 'Aprobado'),
(3, 'En espera'),
(2, 'Rechazado');

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
-- Volcado de datos para la tabla `eventos`
--

INSERT INTO `eventos` (`id_evento`, `nombre_evento`, `lugar`, `descripcion`, `observacion`, `id_estado_previo`, `id_estado_final`, `id_tipo`, `encargado`, `es_general`, `boleteria_activa`, `fecha_hora_inicio`, `fecha_hora_termino`) VALUES
(1, 'eze', 'Zoom', 'Aquí', 'Lorem impsum', 1, 1, 1, 1, 1, 0, '2025-05-26 20:00:00', '2025-05-27 00:00:00'),
(3, 'Concierto Alabanza', 'Iglesia Central', 'Evento musical', '2', 1, 4, 1, 1, 1, 0, '2025-03-12 03:00:00', '2025-03-12 03:00:00'),
(4, 'Jornada Misión Joven', 'Auditorio', 'Día de actividades juveniles', 'jh', 1, 3, 1, 4, 0, 0, '2025-03-19 03:00:00', '2025-03-19 03:00:00'),
(5, 'Seminario Comunicaciones', 'Aula 2', 'Taller de estrategias de comunicación', 'jhj', 1, 4, 1, NULL, 0, 0, '2025-04-18 04:00:00', '2025-04-18 23:54:00'),
(15, 'Ruta de Amor', 'Terminal de Buses, Concepción', 'Evangelismo persona a persona', NULL, 3, 3, 1, 1, 0, 0, '2025-06-14 19:00:00', '2025-06-15 00:30:00'),
(19, 'Reunión Directiva', 'Zoom', '', NULL, 3, 3, 2, 3, 0, 0, '2025-08-22 00:30:00', '2025-08-22 01:30:00'),
(25, 'Equipaje de Amor', 'Terminal de Buses, Concepción', 'Evangelismo persona a persona', 'kk', 1, 3, 1, 1, 0, 0, '2025-06-14 19:00:00', '2025-06-15 00:30:00'),
(28, 'Reminders', NULL, NULL, NULL, 1, 4, 1, 1, 0, 0, '2025-05-31 04:21:00', '2025-05-31 04:36:00'),
(29, 'Equipaje de Amor 2', 'Terminal de Buses, Concepción', 'Evangelismo persona a persona', 'kk', 1, 3, 1, 1, 1, 0, '2025-06-15 19:00:00', '2025-06-15 20:00:00'),
(30, 'Reminders 2', '', '', 'jhjkl', 1, 1, 1, 1, 1, 0, '2025-05-27 04:21:00', '2025-05-27 04:21:00'),
(31, 'Reminders 3', NULL, NULL, NULL, 1, 1, 1, 1, 0, 0, '2025-05-27 04:21:00', '2025-05-27 04:21:00'),
(32, 'eze 2', 'Zoom', 'Aquí', 'Lorem impsum', 1, 6, 1, 1, 0, 0, '2025-05-26 21:00:00', '2025-05-27 03:00:00'),
(33, 'Reunión Directiva', NULL, NULL, NULL, 1, 3, 3, NULL, 1, 0, '2025-05-01 07:57:00', '2025-05-01 08:12:00'),
(36, 'Aaaa', NULL, NULL, NULL, 1, 4, 3, 1, 0, 0, '2024-11-01 04:30:00', '2024-11-02 02:59:00'),
(37, 'Ass', NULL, NULL, NULL, 3, 3, 3, NULL, 0, 0, '2025-05-27 04:00:00', '2038-01-18 06:14:00'),
(41, 'eze 3', 'Zoom', 'Aquí', 'Lorem impsum', 1, 1, 1, 1, 0, 0, '2025-05-26 21:00:00', '2025-05-27 03:00:00'),
(42, 'Reunión Directiva', '', '', '', 1, 3, 3, NULL, 0, 0, '2025-05-30 03:18:00', '2025-05-30 03:39:00'),
(43, 'Reunión Directiva', '', '', '', 1, 3, 3, NULL, 1, 0, '2025-05-30 03:19:00', '2025-05-30 03:34:00'),
(44, 'Reunión Directiva12345', '', '', '', 1, 3, 3, NULL, 1, 0, '2025-05-01 07:57:00', '2025-05-01 08:13:00'),
(45, 'Reunión DirectivaKJHGHJK', '', '', '', 1, 3, 3, NULL, 0, 0, '2025-05-30 04:33:00', '2025-05-30 04:48:00'),
(46, 'YoJH87678', '', '', NULL, 3, 3, 3, NULL, 0, 0, '2025-05-30 04:34:00', '2025-05-30 04:49:00'),
(47, 'Reunión Directiva9999', '', '', '', 1, 3, 3, NULL, 0, 0, '2025-05-30 06:25:00', '2025-05-30 06:41:00'),
(49, 'Avance 2 - Ejemplo 1', 'Ulagos', 'Tesis', 'Llevar PC', 1, 4, 1, 1, 0, 0, '2025-06-01 01:00:00', '2025-06-01 01:15:00'),
(50, 'Avance 2 - Ejemplo 2', 'Ulagos', 'Tesis', 'Llevar PC', 1, 4, 1, 1, 0, 0, '2025-06-01 02:00:00', '2025-06-01 02:30:00'),
(51, 'Ruta de Amor', 'Terminal de Buses, Concepción', 'Evangelismo persona a persona', NULL, 1, 3, 1, 1, 0, 0, '2025-06-26 19:00:00', '2025-06-26 19:15:00'),
(52, 'EJEMPLO Asistencias', 'Terminal de Buses, Concepción', 'Evangelismo persona a persona', '', 3, 3, 1, 1, 0, 0, '2025-06-14 19:00:00', '2025-06-15 00:30:00'),
(53, 'EJEMPLO Asistencias - 2', 'Terminal de Buses, Concepción', 'Evangelismo persona a persona', 'jhghjk', 1, 3, 1, NULL, 0, 1, '2025-07-17 19:00:00', '2025-07-18 00:30:00');

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

--
-- Volcado de datos para la tabla `eventos_tickets`
--

INSERT INTO `eventos_tickets` (`id_evento_ticket`, `id_evento`, `nombre_ticket`, `descripcion`, `precio_clp`, `cupo_total`, `activo`) VALUES
(7, 53, 'Semilla', 'lorem ipsun', 6000, 5, 1);

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

--
-- Volcado de datos para la tabla `historial_estados_actividad`
--

INSERT INTO `historial_estados_actividad` (`id_historial_estado_actividad`, `id_integrante_equipo_proyecto`, `id_tipo_estado_actividad`, `id_periodo`, `fecha_estado_actividad`) VALUES
(1, 1, 1, 2, '2024-08-01'),
(2, 1, 1, 3, '2024-12-31'),
(3, 1, 1, 1, '2024-04-01'),
(5, 3, 1, 2, '2024-08-01'),
(7, 6, 1, 6, '2025-04-30'),
(8, 6, 1, 7, '2025-05-15'),
(10, 1, 1, 7, '2025-08-31'),
(13, 1, 1, 6, '2025-04-30'),
(20, 9, 5, 7, '2025-06-19'),
(22, 10, 6, 7, '2025-06-15'),
(24, 4, 5, 7, '2025-06-27'),
(26, 4, 1, 6, '2025-04-30'),
(27, 6, 1, 3, '2024-12-31'),
(29, 5, 6, 7, '2025-06-16'),
(35, 11, 1, 7, '2025-08-31'),
(37, 3, 5, 7, '2025-06-16'),
(39, 12, 6, 7, '2025-06-19'),
(42, 13, 6, 7, '2025-06-16'),
(52, 14, 6, 7, '2025-06-21'),
(61, 15, 6, 7, '2025-06-26'),
(63, 16, 6, 7, '2025-06-26'),
(64, 17, 5, 7, '2025-06-26'),
(65, 18, 5, 7, '2025-06-26'),
(66, 19, 6, 7, '2025-07-03'),
(67, 20, 6, 7, '2025-07-03'),
(71, 21, 5, 7, '2025-06-26'),
(77, 24, 5, 7, '2025-06-26'),
(78, 25, 6, 7, '2025-06-26'),
(81, 26, 5, 7, '2025-06-30'),
(82, 27, 6, 7, '2025-07-03'),
(84, 28, 5, 7, '2025-07-03'),
(86, 29, 5, 7, '2025-07-03'),
(87, 30, 5, 7, '2025-07-03'),
(89, 31, 5, 7, '2025-07-03'),
(90, 32, 5, 7, '2025-07-03');

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
-- Volcado de datos para la tabla `integrantes_equipos_proyectos`
--

INSERT INTO `integrantes_equipos_proyectos` (`id_integrante_equipo_proyecto`, `id_usuario`, `id_equipo_proyecto`, `id_rol`, `habilitado`) VALUES
(1, 1, 1, 1, 1),
(3, 3, 4, 4, 1),
(4, 4, 5, 5, 1),
(5, 5, 1, 4, 0),
(6, 1, 5, 4, 1),
(9, 1, 4, 4, 1),
(10, 1, 19, 5, 0),
(11, 3, 1, 5, 1),
(12, 5, 20, 5, 0),
(13, 5, 32, 5, 0),
(14, 4, 33, 5, 0),
(15, 4, 4, 5, 0),
(16, 96, 4, 5, 0),
(17, 5, 16, 5, 1),
(18, 111, 4, 5, 1),
(19, 90, 4, 5, 0),
(20, 110, 4, 5, 0),
(21, 96, 22, 5, 1),
(24, 140, 18, 5, 1),
(25, 141, 21, 5, 0),
(26, 136, 4, 5, 1),
(27, 142, 5, 5, 0),
(28, 1, 33, 4, 1),
(29, 137, 4, 5, 1),
(30, 11, 4, 5, 1),
(31, 143, 4, 5, 1),
(32, 144, 4, 5, 1);

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
-- Estructura de tabla para la tabla `justificacion_inasistencia`
--

CREATE TABLE `justificacion_inasistencia` (
  `id_justificacion_inasistencia` int(11) NOT NULL,
  `nombre_justificacion_inasistencia` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `justificacion_inasistencia`
--

INSERT INTO `justificacion_inasistencia` (`id_justificacion_inasistencia`, `nombre_justificacion_inasistencia`) VALUES
(6, 'Académico'),
(7, 'Compromiso importante'),
(3, 'Económico'),
(2, 'Laboral'),
(8, 'Lejanía'),
(11, 'No participó'),
(4, 'No sabía'),
(9, 'Otros'),
(1, 'Salud'),
(5, 'Se avisó con poca anterioridad'),
(10, 'Si participó');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ocupaciones`
--

CREATE TABLE `ocupaciones` (
  `id_ocupacion` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `ocupaciones`
--

INSERT INTO `ocupaciones` (`id_ocupacion`, `nombre`) VALUES
(4, 'Dueño/a de casa'),
(1, 'Estudiante'),
(5, 'Sin ocupación actual'),
(2, 'Trabajador/a dependiente'),
(3, 'Trabajador/a independiente');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `paises`
--

CREATE TABLE `paises` (
  `id_pais` int(11) NOT NULL,
  `nombre_pais` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `paises`
--

INSERT INTO `paises` (`id_pais`, `nombre_pais`) VALUES
(2, 'Argentina'),
(4, 'Bolivia'),
(1, 'Chile'),
(5, 'Paraguay'),
(3, 'Perú');

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

--
-- Volcado de datos para la tabla `periodos`
--

INSERT INTO `periodos` (`id_periodo`, `nombre_periodo`, `fecha_inicio`, `fecha_termino`, `es_historico`) VALUES
(1, '2024-T1', '2024-01-01', '2024-04-30', 0),
(2, '2024-T2', '2024-05-01', '2024-08-31', 0),
(3, '2024-T3', '2024-09-01', '2024-12-31', 0),
(4, '2024-Anual', '2024-01-01', '2024-12-31', 0),
(5, 'Histórico', NULL, NULL, 1),
(6, '2025-T1', '2025-01-01', '2025-04-30', 0),
(7, '2025-T2', '2025-05-01', '2025-08-31', 0),
(8, '1970-T2', '1970-05-01', '1970-08-31', 0),
(9, '2025-Anual', '2025-01-01', '2025-12-31', 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `region_estado`
--

CREATE TABLE `region_estado` (
  `id_region_estado` int(11) NOT NULL,
  `nombre_region_estado` varchar(100) NOT NULL,
  `id_pais` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `region_estado`
--

INSERT INTO `region_estado` (`id_region_estado`, `nombre_region_estado`, `id_pais`) VALUES
(1, 'Metropolitana', 1),
(2, 'Buenos Aires', 2),
(3, 'Lima', 3),
(4, 'Santa Cruz', 4),
(5, 'Asunción', 5),
(6, 'Los Lagos', 1);

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

--
-- Volcado de datos para la tabla `resumen_estado_eventos_equipos_periodo`
--

INSERT INTO `resumen_estado_eventos_equipos_periodo` (`id_equipo_proyecto`, `id_estado_final`, `id_periodo`, `total`, `porcentaje`) VALUES
(1, 1, 7, 1, 50.00),
(1, 4, 6, 1, 100.00),
(1, 4, 7, 2, 100.00),
(3, 3, 8, 1, 100.00),
(4, 3, 6, 1, 100.00),
(4, 3, 7, 4, 100.00),
(5, 1, 7, 2, 20.00),
(5, 2, 6, 1, 100.00),
(5, 3, 7, 6, 60.00),
(5, 4, 3, 1, 100.00),
(5, 4, 6, 1, 100.00),
(5, 4, 7, 1, 10.00),
(5, 5, 7, 1, 16.67),
(5, 6, 7, 1, 10.00),
(17, 3, 7, 3, 100.00),
(18, 1, 7, 2, 50.00),
(18, 4, 7, 1, 25.00),
(18, 5, 7, 1, 25.00),
(18, 6, 7, 1, 25.00),
(21, 3, 7, 1, 100.00),
(22, 3, 7, 1, 100.00);

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

--
-- Volcado de datos para la tabla `resumen_justificaciones_eventos_periodo`
--

INSERT INTO `resumen_justificaciones_eventos_periodo` (`id_evento`, `id_justificacion_inasistencia`, `id_periodo`, `total`, `porcentaje`) VALUES
(5, 1, 6, 0, 0.00),
(5, 2, 6, 0, 0.00),
(5, 3, 6, 0, 0.00),
(5, 4, 6, 0, 0.00),
(5, 5, 6, 0, 0.00),
(5, 6, 6, 0, 0.00),
(5, 7, 6, 0, 0.00),
(5, 8, 6, 0, 0.00),
(5, 9, 6, 0, 0.00),
(5, 10, 6, 0, 0.00),
(5, 11, 6, 2, 100.00),
(28, 1, 7, 0, 0.00),
(28, 2, 7, 0, 0.00),
(28, 3, 7, 0, 0.00),
(28, 4, 7, 0, 0.00),
(28, 5, 7, 0, 0.00),
(28, 6, 7, 0, 0.00),
(28, 7, 7, 0, 0.00),
(28, 8, 7, 0, 0.00),
(28, 9, 7, 0, 0.00),
(28, 10, 7, 1, 50.00),
(28, 11, 7, 1, 50.00),
(49, 1, 7, 0, 0.00),
(49, 2, 7, 0, 0.00),
(49, 3, 7, 0, 0.00),
(49, 4, 7, 0, 0.00),
(49, 5, 7, 0, 0.00),
(49, 6, 7, 0, 0.00),
(49, 7, 7, 0, 0.00),
(49, 8, 7, 0, 0.00),
(49, 9, 7, 0, 0.00),
(49, 10, 7, 1, 50.00),
(49, 11, 7, 1, 50.00),
(50, 1, 7, 1, 50.00),
(50, 2, 7, 0, 0.00),
(50, 3, 7, 0, 0.00),
(50, 4, 7, 0, 0.00),
(50, 5, 7, 0, 0.00),
(50, 6, 7, 0, 0.00),
(50, 7, 7, 0, 0.00),
(50, 8, 7, 0, 0.00),
(50, 9, 7, 0, 0.00),
(50, 10, 7, 0, 0.00),
(50, 11, 7, 1, 50.00);

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

--
-- Volcado de datos para la tabla `resumen_justificaciones_integrantes_periodo`
--

INSERT INTO `resumen_justificaciones_integrantes_periodo` (`id_usuario`, `id_justificacion_inasistencia`, `id_periodo`, `total`, `porcentaje`) VALUES
(1, 1, 5, 0, 0.00),
(1, 1, 6, 0, 0.00),
(1, 1, 7, 1, 33.33),
(1, 1, 9, 0, 0.00),
(1, 2, 5, 0, 0.00),
(1, 2, 6, 0, 0.00),
(1, 2, 7, 0, 0.00),
(1, 2, 9, 0, 0.00),
(1, 3, 5, 0, 0.00),
(1, 3, 6, 0, 0.00),
(1, 3, 7, 0, 0.00),
(1, 3, 9, 0, 0.00),
(1, 4, 5, 0, 0.00),
(1, 4, 6, 0, 0.00),
(1, 4, 7, 1, 33.33),
(1, 4, 9, 0, 0.00),
(1, 5, 5, 0, 0.00),
(1, 5, 6, 0, 0.00),
(1, 5, 7, 0, 0.00),
(1, 5, 9, 0, 0.00),
(1, 6, 5, 0, 0.00),
(1, 6, 6, 0, 0.00),
(1, 6, 7, 0, 0.00),
(1, 6, 9, 0, 0.00),
(1, 7, 5, 0, 0.00),
(1, 7, 6, 0, 0.00),
(1, 7, 7, 0, 0.00),
(1, 7, 9, 0, 0.00),
(1, 8, 5, 0, 0.00),
(1, 8, 6, 0, 0.00),
(1, 8, 7, 0, 0.00),
(1, 8, 9, 0, 0.00),
(1, 9, 5, 0, 0.00),
(1, 9, 6, 0, 0.00),
(1, 9, 7, 0, 0.00),
(1, 9, 9, 0, 0.00),
(1, 10, 5, 0, 0.00),
(1, 10, 6, 0, 0.00),
(1, 10, 7, 2, 66.67),
(1, 10, 9, 0, 0.00),
(1, 11, 5, 0, 0.00),
(1, 11, 6, 0, 0.00),
(1, 11, 7, 1, 33.33),
(1, 11, 9, 0, 0.00),
(3, 1, 5, 0, 0.00),
(3, 1, 6, 0, 0.00),
(3, 1, 9, 0, 0.00),
(3, 2, 5, 0, 0.00),
(3, 2, 6, 0, 0.00),
(3, 2, 9, 0, 0.00),
(3, 3, 5, 0, 0.00),
(3, 3, 6, 0, 0.00),
(3, 3, 9, 0, 0.00),
(3, 4, 5, 0, 0.00),
(3, 4, 6, 0, 0.00),
(3, 4, 9, 0, 0.00),
(3, 5, 5, 0, 0.00),
(3, 5, 6, 0, 0.00),
(3, 5, 9, 0, 0.00),
(3, 6, 5, 0, 0.00),
(3, 6, 6, 0, 0.00),
(3, 6, 9, 0, 0.00),
(3, 7, 5, 0, 0.00),
(3, 7, 6, 0, 0.00),
(3, 7, 9, 0, 0.00),
(3, 8, 5, 0, 0.00),
(3, 8, 6, 0, 0.00),
(3, 8, 9, 0, 0.00),
(3, 9, 5, 0, 0.00),
(3, 9, 6, 0, 0.00),
(3, 9, 9, 0, 0.00),
(3, 10, 5, 0, 0.00),
(3, 10, 6, 0, 0.00),
(3, 10, 9, 0, 0.00),
(3, 11, 5, 0, 0.00),
(3, 11, 6, 0, 0.00),
(3, 11, 9, 0, 0.00),
(4, 1, 5, 0, 0.00),
(4, 1, 6, 0, 0.00),
(4, 1, 9, 0, 0.00),
(4, 2, 5, 0, 0.00),
(4, 2, 6, 0, 0.00),
(4, 2, 9, 0, 0.00),
(4, 3, 5, 0, 0.00),
(4, 3, 6, 0, 0.00),
(4, 3, 9, 0, 0.00),
(4, 4, 5, 0, 0.00),
(4, 4, 6, 0, 0.00),
(4, 4, 9, 0, 0.00),
(4, 5, 5, 0, 0.00),
(4, 5, 6, 0, 0.00),
(4, 5, 9, 0, 0.00),
(4, 6, 5, 0, 0.00),
(4, 6, 6, 0, 0.00),
(4, 6, 9, 0, 0.00),
(4, 7, 5, 0, 0.00),
(4, 7, 6, 0, 0.00),
(4, 7, 9, 0, 0.00),
(4, 8, 5, 0, 0.00),
(4, 8, 6, 0, 0.00),
(4, 8, 9, 0, 0.00),
(4, 9, 5, 0, 0.00),
(4, 9, 6, 0, 0.00),
(4, 9, 9, 0, 0.00),
(4, 10, 5, 0, 0.00),
(4, 10, 6, 0, 0.00),
(4, 10, 9, 0, 0.00),
(4, 11, 5, 0, 0.00),
(4, 11, 6, 0, 0.00),
(4, 11, 9, 0, 0.00),
(5, 1, 5, 0, 0.00),
(5, 1, 6, 0, 0.00),
(5, 1, 7, 0, 0.00),
(5, 1, 9, 0, 0.00),
(5, 2, 5, 0, 0.00),
(5, 2, 6, 0, 0.00),
(5, 2, 7, 0, 0.00),
(5, 2, 9, 0, 0.00),
(5, 3, 5, 0, 0.00),
(5, 3, 6, 0, 0.00),
(5, 3, 7, 0, 0.00),
(5, 3, 9, 0, 0.00),
(5, 4, 5, 0, 0.00),
(5, 4, 6, 0, 0.00),
(5, 4, 7, 0, 0.00),
(5, 4, 9, 0, 0.00),
(5, 5, 5, 0, 0.00),
(5, 5, 6, 0, 0.00),
(5, 5, 7, 0, 0.00),
(5, 5, 9, 0, 0.00),
(5, 6, 5, 0, 0.00),
(5, 6, 6, 0, 0.00),
(5, 6, 7, 0, 0.00),
(5, 6, 9, 0, 0.00),
(5, 7, 5, 0, 0.00),
(5, 7, 6, 0, 0.00),
(5, 7, 7, 0, 0.00),
(5, 7, 9, 0, 0.00),
(5, 8, 5, 0, 0.00),
(5, 8, 6, 0, 0.00),
(5, 8, 7, 0, 0.00),
(5, 8, 9, 0, 0.00),
(5, 9, 5, 0, 0.00),
(5, 9, 6, 0, 0.00),
(5, 9, 7, 0, 0.00),
(5, 9, 9, 0, 0.00),
(5, 10, 5, 0, 0.00),
(5, 10, 6, 0, 0.00),
(5, 10, 7, 0, 0.00),
(5, 10, 9, 0, 0.00),
(5, 11, 5, 0, 0.00),
(5, 11, 6, 0, 0.00),
(5, 11, 7, 0, 0.00),
(5, 11, 9, 0, 0.00),
(11, 1, 5, 0, 0.00),
(11, 1, 6, 0, 0.00),
(11, 1, 9, 0, 0.00),
(11, 2, 5, 0, 0.00),
(11, 2, 6, 0, 0.00),
(11, 2, 9, 0, 0.00),
(11, 3, 5, 0, 0.00),
(11, 3, 6, 0, 0.00),
(11, 3, 9, 0, 0.00),
(11, 4, 5, 0, 0.00),
(11, 4, 6, 0, 0.00),
(11, 4, 9, 0, 0.00),
(11, 5, 5, 0, 0.00),
(11, 5, 6, 0, 0.00),
(11, 5, 9, 0, 0.00),
(11, 6, 5, 0, 0.00),
(11, 6, 6, 0, 0.00),
(11, 6, 9, 0, 0.00),
(11, 7, 5, 0, 0.00),
(11, 7, 6, 0, 0.00),
(11, 7, 9, 0, 0.00),
(11, 8, 5, 0, 0.00),
(11, 8, 6, 0, 0.00),
(11, 8, 9, 0, 0.00),
(11, 9, 5, 0, 0.00),
(11, 9, 6, 0, 0.00),
(11, 9, 9, 0, 0.00),
(11, 10, 5, 0, 0.00),
(11, 10, 6, 0, 0.00),
(11, 10, 9, 0, 0.00),
(11, 11, 5, 0, 0.00),
(11, 11, 6, 0, 0.00),
(11, 11, 9, 0, 0.00),
(90, 1, 5, 0, 0.00),
(90, 1, 6, 0, 0.00),
(90, 1, 9, 0, 0.00),
(90, 2, 5, 0, 0.00),
(90, 2, 6, 0, 0.00),
(90, 2, 9, 0, 0.00),
(90, 3, 5, 0, 0.00),
(90, 3, 6, 0, 0.00),
(90, 3, 9, 0, 0.00),
(90, 4, 5, 0, 0.00),
(90, 4, 6, 0, 0.00),
(90, 4, 9, 0, 0.00),
(90, 5, 5, 0, 0.00),
(90, 5, 6, 0, 0.00),
(90, 5, 9, 0, 0.00),
(90, 6, 5, 0, 0.00),
(90, 6, 6, 0, 0.00),
(90, 6, 9, 0, 0.00),
(90, 7, 5, 0, 0.00),
(90, 7, 6, 0, 0.00),
(90, 7, 9, 0, 0.00),
(90, 8, 5, 0, 0.00),
(90, 8, 6, 0, 0.00),
(90, 8, 9, 0, 0.00),
(90, 9, 5, 0, 0.00),
(90, 9, 6, 0, 0.00),
(90, 9, 9, 0, 0.00),
(90, 10, 5, 0, 0.00),
(90, 10, 6, 0, 0.00),
(90, 10, 9, 0, 0.00),
(90, 11, 5, 0, 0.00),
(90, 11, 6, 0, 0.00),
(90, 11, 9, 0, 0.00),
(96, 1, 5, 0, 0.00),
(96, 1, 6, 0, 0.00),
(96, 1, 7, 0, 0.00),
(96, 1, 9, 0, 0.00),
(96, 2, 5, 0, 0.00),
(96, 2, 6, 0, 0.00),
(96, 2, 7, 0, 0.00),
(96, 2, 9, 0, 0.00),
(96, 3, 5, 0, 0.00),
(96, 3, 6, 0, 0.00),
(96, 3, 7, 0, 0.00),
(96, 3, 9, 0, 0.00),
(96, 4, 5, 0, 0.00),
(96, 4, 6, 0, 0.00),
(96, 4, 7, 0, 0.00),
(96, 4, 9, 0, 0.00),
(96, 5, 5, 0, 0.00),
(96, 5, 6, 0, 0.00),
(96, 5, 7, 0, 0.00),
(96, 5, 9, 0, 0.00),
(96, 6, 5, 0, 0.00),
(96, 6, 6, 0, 0.00),
(96, 6, 7, 0, 0.00),
(96, 6, 9, 0, 0.00),
(96, 7, 5, 0, 0.00),
(96, 7, 6, 0, 0.00),
(96, 7, 7, 0, 0.00),
(96, 7, 9, 0, 0.00),
(96, 8, 5, 0, 0.00),
(96, 8, 6, 0, 0.00),
(96, 8, 7, 0, 0.00),
(96, 8, 9, 0, 0.00),
(96, 9, 5, 0, 0.00),
(96, 9, 6, 0, 0.00),
(96, 9, 7, 0, 0.00),
(96, 9, 9, 0, 0.00),
(96, 10, 5, 0, 0.00),
(96, 10, 6, 0, 0.00),
(96, 10, 7, 0, 0.00),
(96, 10, 9, 0, 0.00),
(96, 11, 5, 0, 0.00),
(96, 11, 6, 0, 0.00),
(96, 11, 7, 0, 0.00),
(96, 11, 9, 0, 0.00),
(110, 1, 5, 0, 0.00),
(110, 1, 6, 0, 0.00),
(110, 1, 9, 0, 0.00),
(110, 2, 5, 0, 0.00),
(110, 2, 6, 0, 0.00),
(110, 2, 9, 0, 0.00),
(110, 3, 5, 0, 0.00),
(110, 3, 6, 0, 0.00),
(110, 3, 9, 0, 0.00),
(110, 4, 5, 0, 0.00),
(110, 4, 6, 0, 0.00),
(110, 4, 9, 0, 0.00),
(110, 5, 5, 0, 0.00),
(110, 5, 6, 0, 0.00),
(110, 5, 9, 0, 0.00),
(110, 6, 5, 0, 0.00),
(110, 6, 6, 0, 0.00),
(110, 6, 9, 0, 0.00),
(110, 7, 5, 0, 0.00),
(110, 7, 6, 0, 0.00),
(110, 7, 9, 0, 0.00),
(110, 8, 5, 0, 0.00),
(110, 8, 6, 0, 0.00),
(110, 8, 9, 0, 0.00),
(110, 9, 5, 0, 0.00),
(110, 9, 6, 0, 0.00),
(110, 9, 9, 0, 0.00),
(110, 10, 5, 0, 0.00),
(110, 10, 6, 0, 0.00),
(110, 10, 9, 0, 0.00),
(110, 11, 5, 0, 0.00),
(110, 11, 6, 0, 0.00),
(110, 11, 9, 0, 0.00),
(111, 1, 5, 0, 0.00),
(111, 1, 6, 0, 0.00),
(111, 1, 9, 0, 0.00),
(111, 2, 5, 0, 0.00),
(111, 2, 6, 0, 0.00),
(111, 2, 9, 0, 0.00),
(111, 3, 5, 0, 0.00),
(111, 3, 6, 0, 0.00),
(111, 3, 9, 0, 0.00),
(111, 4, 5, 0, 0.00),
(111, 4, 6, 0, 0.00),
(111, 4, 9, 0, 0.00),
(111, 5, 5, 0, 0.00),
(111, 5, 6, 0, 0.00),
(111, 5, 9, 0, 0.00),
(111, 6, 5, 0, 0.00),
(111, 6, 6, 0, 0.00),
(111, 6, 9, 0, 0.00),
(111, 7, 5, 0, 0.00),
(111, 7, 6, 0, 0.00),
(111, 7, 9, 0, 0.00),
(111, 8, 5, 0, 0.00),
(111, 8, 6, 0, 0.00),
(111, 8, 9, 0, 0.00),
(111, 9, 5, 0, 0.00),
(111, 9, 6, 0, 0.00),
(111, 9, 9, 0, 0.00),
(111, 10, 5, 0, 0.00),
(111, 10, 6, 0, 0.00),
(111, 10, 9, 0, 0.00),
(111, 11, 5, 0, 0.00),
(111, 11, 6, 0, 0.00),
(111, 11, 9, 0, 0.00),
(136, 1, 5, 0, 0.00),
(136, 1, 6, 0, 0.00),
(136, 1, 9, 0, 0.00),
(136, 2, 5, 0, 0.00),
(136, 2, 6, 0, 0.00),
(136, 2, 9, 0, 0.00),
(136, 3, 5, 0, 0.00),
(136, 3, 6, 0, 0.00),
(136, 3, 9, 0, 0.00),
(136, 4, 5, 0, 0.00),
(136, 4, 6, 0, 0.00),
(136, 4, 9, 0, 0.00),
(136, 5, 5, 0, 0.00),
(136, 5, 6, 0, 0.00),
(136, 5, 9, 0, 0.00),
(136, 6, 5, 0, 0.00),
(136, 6, 6, 0, 0.00),
(136, 6, 9, 0, 0.00),
(136, 7, 5, 0, 0.00),
(136, 7, 6, 0, 0.00),
(136, 7, 9, 0, 0.00),
(136, 8, 5, 0, 0.00),
(136, 8, 6, 0, 0.00),
(136, 8, 9, 0, 0.00),
(136, 9, 5, 0, 0.00),
(136, 9, 6, 0, 0.00),
(136, 9, 9, 0, 0.00),
(136, 10, 5, 0, 0.00),
(136, 10, 6, 0, 0.00),
(136, 10, 9, 0, 0.00),
(136, 11, 5, 0, 0.00),
(136, 11, 6, 0, 0.00),
(136, 11, 9, 0, 0.00),
(137, 1, 5, 0, 0.00),
(137, 1, 6, 0, 0.00),
(137, 1, 9, 0, 0.00),
(137, 2, 5, 0, 0.00),
(137, 2, 6, 0, 0.00),
(137, 2, 9, 0, 0.00),
(137, 3, 5, 0, 0.00),
(137, 3, 6, 0, 0.00),
(137, 3, 9, 0, 0.00),
(137, 4, 5, 0, 0.00),
(137, 4, 6, 0, 0.00),
(137, 4, 9, 0, 0.00),
(137, 5, 5, 0, 0.00),
(137, 5, 6, 0, 0.00),
(137, 5, 9, 0, 0.00),
(137, 6, 5, 0, 0.00),
(137, 6, 6, 0, 0.00),
(137, 6, 9, 0, 0.00),
(137, 7, 5, 0, 0.00),
(137, 7, 6, 0, 0.00),
(137, 7, 9, 0, 0.00),
(137, 8, 5, 0, 0.00),
(137, 8, 6, 0, 0.00),
(137, 8, 9, 0, 0.00),
(137, 9, 5, 0, 0.00),
(137, 9, 6, 0, 0.00),
(137, 9, 9, 0, 0.00),
(137, 10, 5, 0, 0.00),
(137, 10, 6, 0, 0.00),
(137, 10, 9, 0, 0.00),
(137, 11, 5, 0, 0.00),
(137, 11, 6, 0, 0.00),
(137, 11, 9, 0, 0.00),
(140, 1, 5, 0, 0.00),
(140, 1, 6, 0, 0.00),
(140, 1, 7, 0, 0.00),
(140, 1, 9, 0, 0.00),
(140, 2, 5, 0, 0.00),
(140, 2, 6, 0, 0.00),
(140, 2, 7, 0, 0.00),
(140, 2, 9, 0, 0.00),
(140, 3, 5, 0, 0.00),
(140, 3, 6, 0, 0.00),
(140, 3, 7, 0, 0.00),
(140, 3, 9, 0, 0.00),
(140, 4, 5, 0, 0.00),
(140, 4, 6, 0, 0.00),
(140, 4, 7, 0, 0.00),
(140, 4, 9, 0, 0.00),
(140, 5, 5, 0, 0.00),
(140, 5, 6, 0, 0.00),
(140, 5, 7, 0, 0.00),
(140, 5, 9, 0, 0.00),
(140, 6, 5, 0, 0.00),
(140, 6, 6, 0, 0.00),
(140, 6, 7, 0, 0.00),
(140, 6, 9, 0, 0.00),
(140, 7, 5, 0, 0.00),
(140, 7, 6, 0, 0.00),
(140, 7, 7, 0, 0.00),
(140, 7, 9, 0, 0.00),
(140, 8, 5, 0, 0.00),
(140, 8, 6, 0, 0.00),
(140, 8, 7, 0, 0.00),
(140, 8, 9, 0, 0.00),
(140, 9, 5, 0, 0.00),
(140, 9, 6, 0, 0.00),
(140, 9, 7, 0, 0.00),
(140, 9, 9, 0, 0.00),
(140, 10, 5, 0, 0.00),
(140, 10, 6, 0, 0.00),
(140, 10, 7, 0, 0.00),
(140, 10, 9, 0, 0.00),
(140, 11, 5, 0, 0.00),
(140, 11, 6, 0, 0.00),
(140, 11, 7, 0, 0.00),
(140, 11, 9, 0, 0.00),
(141, 1, 5, 0, 0.00),
(141, 1, 6, 0, 0.00),
(141, 1, 7, 0, 0.00),
(141, 1, 9, 0, 0.00),
(141, 2, 5, 0, 0.00),
(141, 2, 6, 0, 0.00),
(141, 2, 7, 0, 0.00),
(141, 2, 9, 0, 0.00),
(141, 3, 5, 0, 0.00),
(141, 3, 6, 0, 0.00),
(141, 3, 7, 0, 0.00),
(141, 3, 9, 0, 0.00),
(141, 4, 5, 0, 0.00),
(141, 4, 6, 0, 0.00),
(141, 4, 7, 0, 0.00),
(141, 4, 9, 0, 0.00),
(141, 5, 5, 0, 0.00),
(141, 5, 6, 0, 0.00),
(141, 5, 7, 0, 0.00),
(141, 5, 9, 0, 0.00),
(141, 6, 5, 0, 0.00),
(141, 6, 6, 0, 0.00),
(141, 6, 7, 0, 0.00),
(141, 6, 9, 0, 0.00),
(141, 7, 5, 0, 0.00),
(141, 7, 6, 0, 0.00),
(141, 7, 7, 0, 0.00),
(141, 7, 9, 0, 0.00),
(141, 8, 5, 0, 0.00),
(141, 8, 6, 0, 0.00),
(141, 8, 7, 0, 0.00),
(141, 8, 9, 0, 0.00),
(141, 9, 5, 0, 0.00),
(141, 9, 6, 0, 0.00),
(141, 9, 7, 0, 0.00),
(141, 9, 9, 0, 0.00),
(141, 10, 5, 0, 0.00),
(141, 10, 6, 0, 0.00),
(141, 10, 7, 0, 0.00),
(141, 10, 9, 0, 0.00),
(141, 11, 5, 0, 0.00),
(141, 11, 6, 0, 0.00),
(141, 11, 7, 0, 0.00),
(141, 11, 9, 0, 0.00),
(142, 1, 5, 0, 0.00),
(142, 1, 6, 0, 0.00),
(142, 1, 9, 0, 0.00),
(142, 2, 5, 0, 0.00),
(142, 2, 6, 0, 0.00),
(142, 2, 9, 0, 0.00),
(142, 3, 5, 0, 0.00),
(142, 3, 6, 0, 0.00),
(142, 3, 9, 0, 0.00),
(142, 4, 5, 0, 0.00),
(142, 4, 6, 0, 0.00),
(142, 4, 9, 0, 0.00),
(142, 5, 5, 0, 0.00),
(142, 5, 6, 0, 0.00),
(142, 5, 9, 0, 0.00),
(142, 6, 5, 0, 0.00),
(142, 6, 6, 0, 0.00),
(142, 6, 9, 0, 0.00),
(142, 7, 5, 0, 0.00),
(142, 7, 6, 0, 0.00),
(142, 7, 9, 0, 0.00),
(142, 8, 5, 0, 0.00),
(142, 8, 6, 0, 0.00),
(142, 8, 9, 0, 0.00),
(142, 9, 5, 0, 0.00),
(142, 9, 6, 0, 0.00),
(142, 9, 9, 0, 0.00),
(142, 10, 5, 0, 0.00),
(142, 10, 6, 0, 0.00),
(142, 10, 9, 0, 0.00),
(142, 11, 5, 0, 0.00),
(142, 11, 6, 0, 0.00),
(142, 11, 9, 0, 0.00),
(143, 1, 5, 0, 0.00),
(143, 1, 6, 0, 0.00),
(143, 1, 9, 0, 0.00),
(143, 2, 5, 0, 0.00),
(143, 2, 6, 0, 0.00),
(143, 2, 9, 0, 0.00),
(143, 3, 5, 0, 0.00),
(143, 3, 6, 0, 0.00),
(143, 3, 9, 0, 0.00),
(143, 4, 5, 0, 0.00),
(143, 4, 6, 0, 0.00),
(143, 4, 9, 0, 0.00),
(143, 5, 5, 0, 0.00),
(143, 5, 6, 0, 0.00),
(143, 5, 9, 0, 0.00),
(143, 6, 5, 0, 0.00),
(143, 6, 6, 0, 0.00),
(143, 6, 9, 0, 0.00),
(143, 7, 5, 0, 0.00),
(143, 7, 6, 0, 0.00),
(143, 7, 9, 0, 0.00),
(143, 8, 5, 0, 0.00),
(143, 8, 6, 0, 0.00),
(143, 8, 9, 0, 0.00),
(143, 9, 5, 0, 0.00),
(143, 9, 6, 0, 0.00),
(143, 9, 9, 0, 0.00),
(143, 10, 5, 0, 0.00),
(143, 10, 6, 0, 0.00),
(143, 10, 9, 0, 0.00),
(143, 11, 5, 0, 0.00),
(143, 11, 6, 0, 0.00),
(143, 11, 9, 0, 0.00),
(144, 1, 5, 0, 0.00),
(144, 1, 6, 0, 0.00),
(144, 1, 9, 0, 0.00),
(144, 2, 5, 0, 0.00),
(144, 2, 6, 0, 0.00),
(144, 2, 9, 0, 0.00),
(144, 3, 5, 0, 0.00),
(144, 3, 6, 0, 0.00),
(144, 3, 9, 0, 0.00),
(144, 4, 5, 0, 0.00),
(144, 4, 6, 0, 0.00),
(144, 4, 9, 0, 0.00),
(144, 5, 5, 0, 0.00),
(144, 5, 6, 0, 0.00),
(144, 5, 9, 0, 0.00),
(144, 6, 5, 0, 0.00),
(144, 6, 6, 0, 0.00),
(144, 6, 9, 0, 0.00),
(144, 7, 5, 0, 0.00),
(144, 7, 6, 0, 0.00),
(144, 7, 9, 0, 0.00),
(144, 8, 5, 0, 0.00),
(144, 8, 6, 0, 0.00),
(144, 8, 9, 0, 0.00),
(144, 9, 5, 0, 0.00),
(144, 9, 6, 0, 0.00),
(144, 9, 9, 0, 0.00),
(144, 10, 5, 0, 0.00),
(144, 10, 6, 0, 0.00),
(144, 10, 9, 0, 0.00),
(144, 11, 5, 0, 0.00),
(144, 11, 6, 0, 0.00),
(144, 11, 9, 0, 0.00);

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

--
-- Volcado de datos para la tabla `retirados`
--

INSERT INTO `retirados` (`id_usuario`, `fecha_retiro`, `razon`, `ex_equipo`, `es_difunto`) VALUES
(90, '2025-07-03', 'Jotita...', 'Biobío', 1),
(110, '2025-07-03', 'Jota', 'Biobío', 1),
(141, '2025-06-26', 'iuio', 'Ñuble', 0),
(142, '2025-07-03', 'JOTA', 'Los Lagos', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id_rol` int(11) NOT NULL,
  `nombre_rol` varchar(50) NOT NULL,
  `id_equipo_proyecto` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id_rol`, `nombre_rol`, `id_equipo_proyecto`) VALUES
(1, 'Líder General', 1),
(2, 'Coordinador General', 1),
(3, 'Director de Actividades', 1),
(4, 'Líder', NULL),
(5, 'Integrante', NULL),
(6, 'Coordinador/a', NULL);

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

--
-- Volcado de datos para la tabla `telefonos`
--

INSERT INTO `telefonos` (`id_usuario`, `telefono`, `es_principal`, `id_descripcion_telefono`) VALUES
(1, '+56987254060', 1, 3),
(3, '+56977557010', 1, 3),
(3, '+56984126585', 0, 4),
(4, '+56600000000', 1, 1),
(5, '+56999999998', 0, 1),
(5, '+56999999999', 1, 1),
(11, '+777777777777777', 1, 1),
(136, '+56555555555', 0, 4),
(136, '+56777777777', 0, 1),
(136, '+56999999999', 1, 1),
(137, '+56988888888', 0, 1),
(137, '+56999999999', 0, 4),
(137, '+595777777777', 1, 3),
(142, '+56977557010', 1, 3),
(143, '+56984126585', 1, 3),
(144, '+56989898989', 1, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ticket_admins`
--

CREATE TABLE `ticket_admins` (
  `id_evento` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `ticket_admins`
--

INSERT INTO `ticket_admins` (`id_evento`, `id_usuario`) VALUES
(53, 1),
(53, 3);

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

--
-- Volcado de datos para la tabla `ticket_horarios`
--

INSERT INTO `ticket_horarios` (`id_ticket_horario`, `id_evento`, `fecha_inicio`, `fecha_fin`, `nombre_horario`) VALUES
(5, 53, '2025-07-02 17:19:00', '2025-07-04 17:19:00', 'General');

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
-- Volcado de datos para la tabla `ticket_scans`
--

INSERT INTO `ticket_scans` (`id_ticket_scan`, `id_ticket_horario`, `id_ticket_usuario`, `es_ingreso`, `scan_at`) VALUES
(7, 5, 14, 1, '2025-07-03 05:14:20'),
(8, 5, 14, 0, '2025-07-03 05:15:35'),
(9, 5, 14, 1, '2025-07-03 05:20:32'),
(10, 5, 14, 0, '2025-07-03 05:22:30'),
(11, 5, 14, 1, '2025-07-03 05:30:31'),
(12, 5, 14, 0, '2025-07-03 05:30:38'),
(13, 5, 14, 1, '2025-07-03 05:30:51'),
(14, 5, 10, 1, '2025-07-03 19:00:43'),
(15, 5, 10, 0, '2025-07-03 19:01:09'),
(16, 5, 10, 1, '2025-07-03 19:01:48');

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
-- Volcado de datos para la tabla `ticket_usuario`
--

INSERT INTO `ticket_usuario` (`id_ticket_usuario`, `id_evento_ticket`, `fecha_inscripcion`, `correo_electronico`, `nombre_completo`, `contacto`, `edad`, `credencial`, `acompanantes`, `extras`, `equipo`, `alimentacion`, `hospedaje`, `enfermedad`, `alergia`, `medicamentos`, `alimentacion_especial`, `contacto_emergencia`, `qr_codigo`) VALUES
(10, 7, '2025-07-02 17:20:13', 'and.tapia.2001@gmail.com', 'Andrés Tapia', '+56987254068', 23, 'Sí', 'Leo y Pato', 'No', 'Los Lagos', 'Normal', 'Sí', 'Sí', 'Látex', 'Muchos', 'No', '+56987254068 - Leonor Loncón - Madre', '66f56cdb4bc909bea401a3580308bbab88a49468504844e312e941424a153d1b'),
(11, 7, '2025-07-03 00:26:56', 'ana.hernandez@example.com', 'Andres2', '+56977777778', 0, '', '', '', '', '', '', '', '', '', '', '', 'bd7cbf5f005ec44340894a3ce30c52951b9ef2d4a46bdbf674f966b720dcd3e2'),
(12, 7, '2025-07-03 00:27:40', 'juan.perez@example.com', 'Andres3', '+56987254068', 0, '', '', '', '', '', '', '', '', '', '', '', 'd195136949c5de0c06f0c9cfabb1f61944003e43840cec1b81a7031ffbb1363e'),
(14, 7, '2025-07-03 00:28:05', 'pedro.martinez@example.com', 'Andres5', '+56987254068', 0, '', '', '', '', '', '', '', '', '', '', '', 'd47bfc7fe982bdcc3edc6874adab740d0f69ae16e53f8898bd36e415c4b14a10');

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

--
-- Volcado de datos para la tabla `tipos_estados_actividad`
--

INSERT INTO `tipos_estados_actividad` (`id_tipo_estado_actividad`, `nombre_tipo_estado_actividad`) VALUES
(1, 'Activo'),
(7, 'Cambio'),
(4, 'En espera'),
(3, 'Inactivo'),
(5, 'Nuevo'),
(6, 'Retirado'),
(2, 'Semiactivo'),
(8, 'Sin estado');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_evento`
--

CREATE TABLE `tipos_evento` (
  `id_tipo` int(11) NOT NULL,
  `nombre_tipo` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tipos_evento`
--

INSERT INTO `tipos_evento` (`id_tipo`, `nombre_tipo`) VALUES
(4, 'Adoración pública'),
(5, 'Aniversario'),
(6, 'Ayuda social'),
(3, 'Beneficio'),
(7, 'Capacitación'),
(21, 'Convocatoria'),
(8, 'Culto presencial'),
(9, 'Difusión'),
(22, 'Ensayo'),
(10, 'Evangelización'),
(11, 'Evento nacional'),
(12, 'Externo'),
(13, 'Fe en movimiento'),
(14, 'Invitación'),
(15, 'Misión'),
(2, 'Online'),
(16, 'Otro'),
(17, 'Preparación espiritual'),
(1, 'Presencial'),
(18, 'Recordatorio'),
(19, 'Retiro'),
(20, 'Taller');

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
-- Volcado de datos para la tabla `tokens_usuarios`
--

INSERT INTO `tokens_usuarios` (`token`, `id_usuario`, `creado_en`, `expira_en`) VALUES
('8915871e1053f7637a41e4a456e85c3eea912fe941e9ff7cf630ed25f4cfabcd', 3, '2025-07-03 22:54:36', '2025-07-04 05:56:54');

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

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id_usuario`, `nombres`, `apellido_paterno`, `apellido_materno`, `foto_perfil`, `fecha_nacimiento`, `rut_dni`, `id_pais`, `id_region_estado`, `id_ciudad_comuna`, `direccion`, `iglesia_ministerio`, `profesion_oficio_estudio`, `password`, `fecha_registro`, `ultima_actualizacion`) VALUES
(1, 'Andrés Saúl', 'Tapia', 'Loncón', NULL, '2001-07-04', '206257660', 1, 6, 6, 'Polloico #2505', 'IMPCH - Iglesia Metodista Pentecostal de Chile - Rahue Alto Osorno', 'Estudiante de Ingeniería Civil en Informática', '$2y$10$1gSCMiWl0sNKkJlRgSvAoeg16qyyBNMNj6m4ex2oF03pjzWe8TmTu', '2024-01-01', '2025-07-03 21:54:54'),
(3, 'Leonardo Elías', 'Tapia', 'Loncón', 'uploads/fotos/f_681d6ad82209c1.87939487.jpg', '2001-07-04', '206257652', 1, 6, 6, 'Polloico #2505 Villa Quilacahuin', 'IMPCH Rahue Alto', 'Estudiante de Ingenieria civil en informatica', '$2y$10$TD/.EMnJ7N5jjyp/dwnI/exqzw/YlCEexMWT1GSgCB0/wn/BMvG/C', '2025-06-16', '2025-06-22 01:21:52'),
(4, 'Admi', 'tido', '', NULL, '2008-06-06', '98789', 3, NULL, NULL, 'p', 'p', 'p', '$2y$10$oVxZlKGkdi4iYeKI4fIqF.I5KL0A14kuPQJwgZHsZTJZJLL3L/gGu', '2025-06-27', '2025-06-26 07:30:22'),
(5, 'D', 'D', '', NULL, '2000-07-07', '567890123', 2, NULL, NULL, 'D', 'D', 'D', '$2y$10$oVxZlKGkdi4iYeKI4fIqF.I5KL0A14kuPQJwgZHsZTJZJLL3L/gGu', '2025-06-26', '2025-06-26 07:03:29'),
(11, 'Yo', 'No', 'K', NULL, '2008-08-09', '87', 3, 3, 3, 'lll', 'll', 'LLL', NULL, '2025-07-03', '2025-06-23 06:41:18'),
(90, 'AAA', 'jhg', '', NULL, '2000-03-22', '8789', 3, NULL, NULL, 'jhhjkl', 'jhghjkl', 'jhghjkl', NULL, '2025-06-26', '2025-06-26 05:19:46'),
(96, 'AAA', 'BBB', '', NULL, '2000-09-09', '87890', 5, NULL, NULL, 'jhghjkl', 'jhghjk', 'kjhjkl', NULL, '2025-06-26', '2025-06-23 20:57:54'),
(105, 'AAA', 'BBB', '', NULL, '2000-09-09', '8789088', 5, NULL, NULL, 'jhghjkl', 'jhghjk', 'kjhjkl', NULL, '2025-06-23', '2025-06-25 21:29:58'),
(110, 'Aaaaaaaaa', 'Bbb', 'Cccc', NULL, '2000-06-06', '878', 5, 5, 5, 'juyyuio', 'jhghu', 'kkkkkk', NULL, '2025-06-26', '2025-06-23 22:13:25'),
(111, 'NN', 'NN', '', NULL, '2012-08-08', '111111111', 5, NULL, NULL, 'NNNn', 'NNN', 'NNN', NULL, '2025-06-26', '2025-06-23 22:28:17'),
(136, 'Patricio', 'Pérez', 'DELLLA - TORRE', NULL, '2009-06-06', '678', 2, 2, 2, 'Concepción', 'IMP', 'ING', NULL, '2025-06-30', '2025-06-26 18:29:18'),
(137, 'Fernanda', 'Ugarte', 'Vega', NULL, '1871-06-06', '7898789009878', 5, 5, 5, 'Mi casa', 'Mi iglesia', 'Mi trabajo', NULL, '2025-07-03', '2025-07-03 22:19:20'),
(140, 'Am', 'Erico', '', NULL, '2007-05-05', '98890', 5, NULL, NULL, 'p', 'p', 'p', NULL, '2025-06-26', '2025-06-26 07:33:05'),
(141, 'ni', 'ño', '', NULL, '2008-05-05', '78345', 5, NULL, NULL, 'l', 'l', 'l', NULL, '2025-06-26', '2025-06-26 07:34:09'),
(142, 'Elías Saúl', 'Tapia', 'Tapia', NULL, '2004-06-26', '2737477474838', 2, 2, 2, 'Polloico', 'IMPCH', 'ICINF', NULL, '2025-07-03', '2025-06-26 17:51:29'),
(143, 'Leonor', 'Loncón', 'Guzmán', NULL, '1973-02-06', '84196312', 1, 6, 6, 'Polloico 2505', 'IMPCH', 'no tiene', NULL, '2025-07-03', '2025-07-03 22:43:06'),
(144, 'miguel', 'valladares', 'valladarea', NULL, '2001-01-01', '45678', 3, 3, 3, 'avenida miraflores', 'IUMP', 'Ingenierio', NULL, '2025-07-03', '2025-07-03 22:48:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios_ocupaciones`
--

CREATE TABLE `usuarios_ocupaciones` (
  `id_usuario` int(11) NOT NULL,
  `id_ocupacion` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios_ocupaciones`
--

INSERT INTO `usuarios_ocupaciones` (`id_usuario`, `id_ocupacion`) VALUES
(1, 1),
(3, 1),
(4, 5),
(5, 1),
(11, 5),
(90, 5),
(96, 5),
(105, 5),
(110, 1),
(111, 5),
(136, 5),
(137, 1),
(137, 2),
(137, 3),
(137, 4),
(140, 5),
(141, 5),
(142, 1),
(143, 3),
(143, 4),
(144, 1);

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
  MODIFY `id_envio` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `ciudad_comuna`
--
ALTER TABLE `ciudad_comuna`
  MODIFY `id_ciudad_comuna` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `descripcion_telefonos`
--
ALTER TABLE `descripcion_telefonos`
  MODIFY `id_descripcion_telefono` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `equipos_proyectos`
--
ALTER TABLE `equipos_proyectos`
  MODIFY `id_equipo_proyecto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT de la tabla `equipos_proyectos_eventos`
--
ALTER TABLE `equipos_proyectos_eventos`
  MODIFY `id_equipos_proyectos_eventos` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT de la tabla `estados_admision`
--
ALTER TABLE `estados_admision`
  MODIFY `id_estado_admision` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `estados_asistencia`
--
ALTER TABLE `estados_asistencia`
  MODIFY `id_estado_asistencia` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `estados_finales_eventos`
--
ALTER TABLE `estados_finales_eventos`
  MODIFY `id_estado_final` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `estados_previos_asistencia`
--
ALTER TABLE `estados_previos_asistencia`
  MODIFY `id_estado_previo_asistencia` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `estados_previos_eventos`
--
ALTER TABLE `estados_previos_eventos`
  MODIFY `id_estado_previo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `eventos`
--
ALTER TABLE `eventos`
  MODIFY `id_evento` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT de la tabla `eventos_tickets`
--
ALTER TABLE `eventos_tickets`
  MODIFY `id_evento_ticket` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `historial_estados_actividad`
--
ALTER TABLE `historial_estados_actividad`
  MODIFY `id_historial_estado_actividad` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT de la tabla `integrantes_equipos_proyectos`
--
ALTER TABLE `integrantes_equipos_proyectos`
  MODIFY `id_integrante_equipo_proyecto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT de la tabla `justificacion_inasistencia`
--
ALTER TABLE `justificacion_inasistencia`
  MODIFY `id_justificacion_inasistencia` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de la tabla `ocupaciones`
--
ALTER TABLE `ocupaciones`
  MODIFY `id_ocupacion` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `paises`
--
ALTER TABLE `paises`
  MODIFY `id_pais` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `periodos`
--
ALTER TABLE `periodos`
  MODIFY `id_periodo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=393;

--
-- AUTO_INCREMENT de la tabla `region_estado`
--
ALTER TABLE `region_estado`
  MODIFY `id_region_estado` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id_rol` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `ticket_horarios`
--
ALTER TABLE `ticket_horarios`
  MODIFY `id_ticket_horario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `ticket_scans`
--
ALTER TABLE `ticket_scans`
  MODIFY `id_ticket_scan` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `ticket_usuario`
--
ALTER TABLE `ticket_usuario`
  MODIFY `id_ticket_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT de la tabla `tipos_estados_actividad`
--
ALTER TABLE `tipos_estados_actividad`
  MODIFY `id_tipo_estado_actividad` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `tipos_evento`
--
ALTER TABLE `tipos_evento`
  MODIFY `id_tipo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

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

DELIMITER $$
--
-- Eventos
--
CREATE DEFINER=`root`@`localhost` EVENT `eliminar_tokens_expirados` ON SCHEDULE EVERY 1 DAY STARTS '2025-04-28 23:59:59' ON COMPLETION NOT PRESERVE ENABLE DO DELETE FROM tokens_usuarios
  WHERE expira_en < NOW()$$

CREATE DEFINER=`root`@`localhost` EVENT `purge_attendance_tokens` ON SCHEDULE EVERY 1 DAY STARTS '2025-05-24 21:31:31' ON COMPLETION NOT PRESERVE ENABLE COMMENT 'Elimina tokens de confirmación de asistencia expirados' DO DELETE FROM `attendance_tokens`
    WHERE `expires_at` < NOW()$$

CREATE DEFINER=`root`@`localhost` EVENT `ev_marcar_ausentes` ON SCHEDULE EVERY 1 MINUTE STARTS '2025-06-26 19:59:17' ON COMPLETION PRESERVE ENABLE DO BEGIN
  UPDATE asistencias  AS a
  JOIN   eventos      AS e USING (id_evento)
     SET a.id_estado_asistencia = 2   -- 2 = Ausente
   WHERE a.id_estado_asistencia IS NULL
     AND e.fecha_hora_inicio    <= NOW()
     AND a.id_estado_previo_asistencia IS NOT NULL;
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
