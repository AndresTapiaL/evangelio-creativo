<?php
/* ─── Funciones de autorización comunes a todo el sistema ─── */

function user_is_lider_nac(PDO $pdo,int $uid): bool {
    $q = $pdo->prepare(
        "SELECT 1 FROM integrantes_equipos_proyectos
         WHERE id_usuario=? AND id_equipo_proyecto=1 LIMIT 1");
    $q->execute([$uid]);
    return (bool)$q->fetchColumn();
}

function user_is_privileged(PDO $pdo,int $uid): bool {
    // rol 4 = Líder, 6 = Coordinador/a
    $q = $pdo->prepare(
        "SELECT 1 FROM integrantes_equipos_proyectos
         WHERE id_usuario=? AND id_rol IN (4,6) LIMIT 1");
    $q->execute([$uid]);
    return (bool)$q->fetchColumn();
}

function user_can_use_reports(PDO $pdo,int $uid): bool {
    return user_is_lider_nac($pdo,$uid) || user_is_privileged($pdo,$uid);
}

function user_allowed_teams(PDO $pdo,int $uid): array {
    if (user_is_lider_nac($pdo,$uid)) {           // ve todos
        return $pdo->query(
            "SELECT id_equipo_proyecto FROM equipos_proyectos")
            ->fetchAll(PDO::FETCH_COLUMN);
    }
    $q = $pdo->prepare(
        "SELECT id_equipo_proyecto
           FROM integrantes_equipos_proyectos
          WHERE id_usuario=? AND id_rol IN (4,6)");
    $q->execute([$uid]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

function assert_team_allowed(int $teamId,array $myTeams,bool $isLN){
    if ($isLN) return;                // Lider Nac ve todo
    if(!in_array($teamId,$myTeams,true)){
        http_response_code(403);
        echo json_encode(['error'=>'team-forbidden']); exit;
    }
}
