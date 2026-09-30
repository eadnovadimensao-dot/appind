<?php
// Cuidado pastoral: avisa o líder de célula quando um membro fica muitos domingos
// seguidos sem confirmar presença (nem no culto, nem servindo em ministério).

const ABSENCE_ALERT_WEEKS = 4; // domingos seguidos ausente pra disparar o aviso

/**
 * Chamada pelo cron, uma vez por semana (segunda-feira): pra cada igreja, olha os
 * últimos ABSENCE_ALERT_WEEKS domingos e avisa o(s) líder(es) de célula de quem
 * não esteve presente em nenhum deles. Não repete o aviso pro mesmo período de
 * ausência — só alerta de novo depois que o membro voltar e faltar de novo.
 */
function queue_absence_alerts(PDO $db): void {
    if ((int)date('N') !== 1) return; // só segunda-feira

    foreach ($db->query("SELECT id FROM churches")->fetchAll(PDO::FETCH_COLUMN) as $churchId) {
        $services = $db->prepare("
            SELECT id, service_date FROM services
            WHERE church_id = ? AND type = 'sunday' AND service_date < CURDATE()
            ORDER BY service_date DESC LIMIT " . ABSENCE_ALERT_WEEKS
        );
        $services->execute([$churchId]);
        $services = $services->fetchAll();
        if (count($services) < ABSENCE_ALERT_WEEKS) continue; // histórico de cultos ainda curto

        $serviceIds = array_column($services, 'id');
        $dates      = array_column($services, 'service_date');
        $sinceDate  = min($dates);

        // Quem esteve presente (culto ou escala de ministério) em pelo menos um desses domingos
        $presentIds = [];
        $ph = implode(',', array_fill(0, count($serviceIds), '?'));
        $q1 = $db->prepare("SELECT DISTINCT member_id FROM service_checkins WHERE checked_in_at IS NOT NULL AND service_id IN ($ph)");
        $q1->execute($serviceIds);
        foreach ($q1->fetchAll(PDO::FETCH_COLUMN) as $id) $presentIds[(int)$id] = true;

        $dh = implode(',', array_fill(0, count($dates), '?'));
        $q2 = $db->prepare("
            SELECT DISTINCT mam.member_id FROM ministry_activity_members mam
            JOIN ministry_activities ma ON ma.id = mam.activity_id
            WHERE mam.checked_in_at IS NOT NULL AND ma.church_id = ? AND ma.activity_date IN ($dh)
        ");
        $q2->execute(array_merge([$churchId], $dates));
        foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $id) $presentIds[(int)$id] = true;

        $members = $db->prepare("SELECT id, name, cell_id FROM members WHERE church_id = ? AND status = 'active' AND cell_id IS NOT NULL");
        $members->execute([$churchId]);

        foreach ($members->fetchAll() as $m) {
            $mid = (int)$m['id'];
            if (isset($presentIds[$mid])) continue;

            // Já esteve ausente e avisado antes: só alerta de novo se voltou a aparecer depois do último aviso
            $lastCheckin = $db->prepare("
                SELECT MAX(at) FROM (
                    SELECT MAX(checked_in_at) AS at FROM service_checkins WHERE member_id = ? AND checked_in_at IS NOT NULL
                    UNION ALL
                    SELECT MAX(checked_in_at) FROM ministry_activity_members WHERE member_id = ? AND checked_in_at IS NOT NULL
                ) x
            ");
            $lastCheckin->execute([$mid, $mid]);
            $lastCheckin = $lastCheckin->fetchColumn();

            $existingAlert = $db->prepare("SELECT alerted_at FROM member_absence_alerts WHERE member_id = ? ORDER BY alerted_at DESC LIMIT 1");
            $existingAlert->execute([$mid]);
            $existingAlert = $existingAlert->fetchColumn();
            if ($existingAlert && (!$lastCheckin || $existingAlert > $lastCheckin)) continue; // mesma ausência já avisada

            $leaders = $db->prepare("
                SELECT phone FROM members WHERE id IN (SELECT member_id FROM cell_leaders WHERE cell_id = ?)
                  AND phone IS NOT NULL AND phone != ''
            ");
            $leaders->execute([$m['cell_id']]);
            $leaders = $leaders->fetchAll(PDO::FETCH_COLUMN);
            if (!$leaders) continue; // sem líder com telefone, nada a fazer

            $msg = "🙏 *Cuidado pastoral*\n\n{$m['name']} não confirma presença no culto há " . ABSENCE_ALERT_WEEKS
                 . " domingos seguidos (desde " . date_pt($sinceDate) . ").\n\nQue tal dar um alô e saber como está?";
            foreach ($leaders as $phone) {
                queue_whatsapp($phone, $msg, (int)$churchId, null, 0, null, 'absence_alert');
            }

            $db->prepare("INSERT INTO member_absence_alerts (member_id, alerted_at, weeks_absent) VALUES (?, NOW(), ?)")
               ->execute([$mid, ABSENCE_ALERT_WEEKS]);
        }
    }
}
