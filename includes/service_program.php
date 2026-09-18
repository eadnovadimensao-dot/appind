<?php
// Envia a programação do culto por WhatsApp pra todos os envolvidos: escalados
// (service_scale), quem confirmou/está escalado em atividade de ministério na
// data, líderes desses ministérios, pregador e supervisor. Substitui o
// trabalho manual do supervisor de mandar a programação pros líderes.

/**
 * Quem é envolvido nesse culto: [member_id => ['name','phone','roles'=>[...]]].
 */
function service_program_recipients(PDO $db, array $service): array {
    $churchId = (int)$service['church_id'];
    $people   = [];
    $add = function (int $memberId, string $name, ?string $phone, ?string $role) use (&$people) {
        if (!isset($people[$memberId])) $people[$memberId] = ['name' => $name, 'phone' => $phone, 'roles' => []];
        if ($role !== null && $role !== '' && !in_array($role, $people[$memberId]['roles'], true)) $people[$memberId]['roles'][] = $role;
    };

    // Escala direta do culto
    $q = $db->prepare("SELECT m.id, m.name, m.phone, ss.role FROM service_scale ss JOIN members m ON m.id = ss.member_id WHERE ss.service_id = ?");
    $q->execute([$service['id']]);
    foreach ($q->fetchAll() as $r) $add((int)$r['id'], $r['name'], $r['phone'], $r['role']);

    // Escalados em atividades de ministério nessa data
    $q = $db->prepare("
        SELECT m.id, m.name, m.phone, mam.role, mn.name AS ministry_name
        FROM ministry_activity_members mam
        JOIN ministry_activities ma ON ma.id = mam.activity_id
        JOIN ministries mn ON mn.id = ma.ministry_id
        JOIN members m ON m.id = mam.member_id
        WHERE ma.activity_date = ? AND ma.church_id = ? AND ma.status != 'cancelled'
          AND ma.activity_type = 'culto' AND mam.status IN ('confirmed','pending')
    ");
    $q->execute([$service['service_date'], $churchId]);
    foreach ($q->fetchAll() as $r) $add((int)$r['id'], $r['name'], $r['phone'], $r['role'] ? $r['role'] . ' (' . $r['ministry_name'] . ')' : $r['ministry_name']);

    // Líderes dos ministérios escalados nessa data
    $q = $db->prepare("
        SELECT DISTINCT m.id, m.name, m.phone, mn.name AS ministry_name
        FROM ministry_activities ma
        JOIN ministries mn ON mn.id = ma.ministry_id
        JOIN ministry_leaders ml ON ml.ministry_id = mn.id
        JOIN members m ON m.id = ml.member_id
        WHERE ma.activity_date = ? AND ma.church_id = ? AND ma.status != 'cancelled' AND ma.activity_type = 'culto'
    ");
    $q->execute([$service['service_date'], $churchId]);
    foreach ($q->fetchAll() as $r) $add((int)$r['id'], $r['name'], $r['phone'], 'Líder de ' . $r['ministry_name']);

    // Pregador
    if (!empty($service['preacher_id'])) {
        $q = $db->prepare("SELECT id, name, phone FROM members WHERE id = ?");
        $q->execute([$service['preacher_id']]);
        if ($r = $q->fetch()) $add((int)$r['id'], $r['name'], $r['phone'], 'Pregador');
    }

    // Supervisor do culto
    if (!empty($service['supervisor_id'])) {
        $q = $db->prepare("SELECT m.id, m.name, m.phone FROM supervisors sv JOIN members m ON m.id = sv.member_id WHERE sv.id = ?");
        $q->execute([$service['supervisor_id']]);
        if ($r = $q->fetch()) $add((int)$r['id'], $r['name'], $r['phone'], 'Supervisor do culto');
    }

    return $people;
}

function service_program_text(PDO $db, array $service): string {
    $typeLabels = [
        'welcome' => 'Boas-vindas', 'worship' => 'Louvor', 'prayer' => 'Oração', 'reading' => 'Leitura Bíblica',
        'sermon' => 'Pregação', 'offering' => 'Oferta', 'announcement' => 'Avisos', 'closing' => 'Encerramento', 'other' => 'Outro',
    ];
    $items = $db->prepare("SELECT type, title, duration FROM service_items WHERE service_id = ? ORDER BY position");
    $items->execute([$service['id']]);

    $lines = [];
    foreach ($items->fetchAll() as $i => $it) {
        $label   = trim((string)$it['title']) !== '' ? $it['title'] : ($typeLabels[$it['type']] ?? $it['type']);
        $lines[] = ($i + 1) . '. ' . $label . ($it['duration'] ? " · {$it['duration']} min" : '');
    }

    $when = date_pt($service['service_date']);
    if ($service['time_start']) {
        $when .= ' · ' . substr($service['time_start'], 0, 5) . ($service['time_end'] ? ' às ' . substr($service['time_end'], 0, 5) : '');
    }

    $text = "📋 *Programação: {$service['title']}*\n📅 {$when}";
    if (!empty($service['preacher_id'])) {
        $p = $db->prepare("SELECT name FROM members WHERE id = ?");
        $p->execute([$service['preacher_id']]);
        if ($name = $p->fetchColumn()) $text .= "\n🎤 Pregador: {$name}";
    }
    if (!empty($service['sermon_title'])) $text .= "\n🎙️ Tema: {$service['sermon_title']}";
    if (!empty($service['sermon_text']))  $text .= "\n📖 Texto: {$service['sermon_text']}";
    if ($lines) $text .= "\n\n*Ordem do culto*\n" . implode("\n", $lines);
    return $text;
}

/**
 * Enfileira a programação pra todos os envolvidos. Cancela antes os envios
 * de programação ainda pendentes desse culto (reenvio não duplica na fila).
 * Retorna quantas mensagens foram enfileiradas.
 */
function queue_service_program(PDO $db, int $serviceId): int {
    $s = $db->prepare("SELECT * FROM services WHERE id = ?");
    $s->execute([$serviceId]);
    $service = $s->fetch();
    if (!$service) return 0;
    $churchId = (int)$service['church_id'];

    $db->prepare("DELETE FROM whatsapp_queue WHERE service_id = ? AND kind = 'program' AND status = 'pending'")->execute([$serviceId]);

    $body       = service_program_text($db, $service);
    $churchName = setting('church_name', 'Igreja', $churchId);
    $count      = 0;

    foreach (service_program_recipients($db, $service) as $p) {
        if (!$p['phone']) continue;
        $firstName = explode(' ', trim($p['name']))[0];
        $message   = "Olá, {$firstName}! 👋\n\n{$body}";
        if (!empty($p['roles'])) $message .= "\n\n🎯 *Sua função:* " . implode('; ', $p['roles']);
        $message .= "\n\n_{$churchName}_";
        queue_whatsapp($p['phone'], $message, $churchId, null, 0, $serviceId, 'program');
        $count++;
    }

    $db->prepare("UPDATE services SET program_sent_at = NOW() WHERE id = ?")->execute([$serviceId]);
    return $count;
}
