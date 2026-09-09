<?php
// Criação + notificação de atividades de ministério (culto/ensaio/etc).
// Extraído de pages/ministries/activity_create.php pra poder ser reaproveitado
// na geração automática do ensaio-espelho de uma escala de culto.
require_once __DIR__ . '/music_roles.php';

/**
 * Cria uma atividade de ministério, escala os membros informados, salva o
 * repertório e notifica cada escalado (anúncio interno, push real e e-mail).
 * Também integra com a agenda quando há horário de término.
 *
 * @param array $roles     member_id => função (string)
 * @param array $songs     lista de ['title'=>, 'key_tone'=>, 'reference_link'=>]
 * @return int  id da atividade criada
 */
function create_ministry_activity(
    PDO $db,
    array $mn,
    int $ministryId,
    int $churchId,
    string $activityType,
    string $title,
    ?string $description,
    string $date,
    ?string $timeStart,
    ?string $timeEnd,
    ?string $location,
    array $scaledIds,
    array $roles,
    array $songs,
    int $createdBy
): int {
    $stmt = $db->prepare("
        INSERT INTO ministry_activities
          (ministry_id, church_id, activity_type, title, description, activity_date, time_start, time_end, location, status)
        VALUES (:ministry_id,:church_id,:activity_type,:title,:description,:date,:time_start,:time_end,:location,'scheduled')
    ");
    $stmt->execute([
        ':ministry_id'   => $ministryId,
        ':church_id'     => $churchId,
        ':activity_type' => $activityType,
        ':title'         => $title,
        ':description'   => $description ?: null,
        ':date'          => $date,
        ':time_start'    => $timeStart,
        ':time_end'      => $timeEnd,
        ':location'      => $location ?: null,
    ]);
    $activityId = (int)$db->lastInsertId();

    // Repertório
    if (!empty($songs)) {
        $sg = $db->prepare("INSERT INTO ministry_activity_songs (activity_id, title, key_tone, reference_link, position) VALUES (?,?,?,?,?)");
        $pos = 0;
        foreach ($songs as $song) {
            $songTitle = trim($song['title'] ?? '');
            if ($songTitle === '') continue;
            $sg->execute([
                $activityId,
                $songTitle,
                trim($song['key_tone'] ?? '') ?: null,
                trim($song['reference_link'] ?? '') ?: null,
                $pos++,
            ]);
        }
    }

    // Escalar membros e notificar
    if (!empty($scaledIds)) {
        $ss = $db->prepare("INSERT IGNORE INTO ministry_activity_members (activity_id, member_id, role, status, confirm_token, token_expires_at, notified_at) VALUES (?,?,?,'pending',?,?,NOW())");
        foreach ($scaledIds as $mid) {
            $role    = trim($roles[$mid] ?? '');
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime($date . ' -2 days'));
            $ss->execute([$activityId, (int)$mid, $role ?: null, $token, $expires]);
        }

        // Preparar envio de push (mesma lib usada em services/index.php e communication/create.php)
        $webPush = null;
        $vapidPublic  = setting('vapid_public_key', '', $churchId);
        $vapidPrivate = setting('vapid_private_key', '', $churchId);
        $pushAutoload = __DIR__ . '/../vendor/autoload.php';
        if ($vapidPublic && $vapidPrivate && file_exists($pushAutoload)) {
            require_once $pushAutoload;
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br', $churchId),
                    'publicKey'  => $vapidPublic,
                    'privateKey' => $vapidPrivate,
                ],
            ]);
        }

        foreach ($scaledIds as $mid) {
            $memberName = $db->query("SELECT name FROM members WHERE id=".(int)$mid)->fetchColumn();

            $tokenRow = $db->prepare("SELECT confirm_token FROM ministry_activity_members WHERE activity_id=? AND member_id=?");
            $tokenRow->execute([$activityId, (int)$mid]);
            $token = $tokenRow->fetchColumn();

            $confirmUrl = APP_URL . '/respond.php?token=' . $token . '&action=confirm';
            $refuseUrl  = APP_URL . '/respond.php?token=' . $token . '&action=refuse';
            $prazo      = date('d/m/Y', strtotime($date . ' -2 days'));

            // Funções "sempre inclui" (ex: Pastor(a) da Base de Adoração) recebem um
            // pedido de oração pela equipe em vez do convite com confirmar/recusar presença.
            $isPrayerRole = in_array(trim($roles[$mid] ?? ''), MUSIC_ALWAYS_INCLUDE_ROLES, true);

            if ($isPrayerRole) {
                $tpl = notification_template('scale_prayer_request', [
                    'nome'       => $memberName,
                    'ministerio' => $mn['name'],
                    'data'       => date('d/m/Y (l)', strtotime($date)),
                ], $churchId);
                $fullContent = $tpl['content'];
            } else {
                $tpl = notification_template('scale_invited', [
                    'nome'       => $memberName,
                    'ministerio' => $mn['name'],
                    'data'       => date('d/m/Y (l)', strtotime($date)),
                    'prazo'      => $prazo,
                ], $churchId);
                $fullContent = $tpl['content']
                    . "\n\n✅ Confirmar presença: $confirmUrl"
                    . "\n❌ Não posso ir: $refuseUrl";
            }

            $db->prepare("
                INSERT INTO announcements (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
                VALUES (?,?,?,'general','member',?,'internal,push','sent',?,NOW())
            ")->execute([
                $churchId,
                $tpl['title'],
                $fullContent,
                (int)$mid,
                $createdBy
            ]);

            // Push real
            if ($webPush) {
                $subsStmt = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id = ?");
                $subsStmt->execute([(int)$mid]);
                foreach ($subsStmt->fetchAll() as $sub) {
                    $webPush->queueNotification(
                        \Minishlink\WebPush\Subscription::create([
                            'endpoint'        => $sub['endpoint'],
                            'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
                            'contentEncoding' => 'aesgcm',
                        ]),
                        json_encode([
                            'title' => $tpl['title'],
                            'body'  => "{$mn['name']} · " . date('d/m/Y', strtotime($date)),
                            'url'   => $isPrayerRole ? APP_URL . '/pages/ministries/activity_view.php?id=' . $activityId : $confirmUrl,
                            'tag'   => 'scale-' . $activityId,
                        ])
                    );
                }
            }

            // E-mail
            $memberEmail = $db->query("SELECT email FROM members WHERE id=".(int)$mid)->fetchColumn();
            if ($memberEmail) {
                $accentColor   = setting('accent_color',  '#1D9E75', $churchId);
                $dateFormatted = date('d/m/Y', strtotime($date));
                $dayName = ['Sunday'=>'Domingo','Monday'=>'Segunda-feira','Tuesday'=>'Terça-feira',
                            'Wednesday'=>'Quarta-feira','Thursday'=>'Quinta-feira',
                            'Friday'=>'Sexta-feira','Saturday'=>'Sábado'][date('l', strtotime($date))] ?? '';

                if ($isPrayerRole) {
                    $emailBody = "
                    <div style='text-align:center;margin-bottom:28px'>
                      <div style='font-size:48px;margin-bottom:12px'>🙏</div>
                      <h1 style='font-size:22px;font-weight:600;color:#1a2332;margin:0 0 8px'>{$tpl['title']}</h1>
                      <p style='color:#6b7280;font-size:15px;margin:0'>Olá, <strong style='color:#1a2332'>{$memberName}</strong>!</p>
                    </div>

                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f9fafb;border-radius:12px;margin-bottom:28px'>
                      <tr><td style='padding:20px'>
                        <table width='100%' cellpadding='0' cellspacing='0'>
                          <tr>
                            <td style='padding:6px 0'>
                              <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Ministério</span><br>
                              <strong style='color:#1a2332;font-size:15px'>" . htmlspecialchars($mn['name']) . "</strong>
                            </td>
                          </tr>
                          <tr><td style='padding:6px 0;border-top:1px solid #e5e7eb'>
                            <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Atividade</span><br>
                            <strong style='color:#1a2332;font-size:15px'>" . htmlspecialchars($title) . "</strong>
                          </td></tr>
                          <tr><td style='padding:6px 0;border-top:1px solid #e5e7eb'>
                            <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Data</span><br>
                            <strong style='color:#1a2332;font-size:15px'>{$dayName}, {$dateFormatted}</strong>
                          </td></tr>
                        </table>
                      </td></tr>
                    </table>

                    <p style='color:#6b7280;font-size:14px;text-align:center;line-height:1.6;white-space:pre-line'>" . htmlspecialchars($tpl['content']) . "</p>";
                } else {
                    $emailBody = "
                    <div style='text-align:center;margin-bottom:28px'>
                      <div style='font-size:48px;margin-bottom:12px'>🎵</div>
                      <h1 style='font-size:22px;font-weight:600;color:#1a2332;margin:0 0 8px'>{$tpl['title']}</h1>
                      <p style='color:#6b7280;font-size:15px;margin:0'>Olá, <strong style='color:#1a2332'>{$memberName}</strong>! Você foi escalado(a).</p>
                    </div>

                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f9fafb;border-radius:12px;margin-bottom:28px'>
                      <tr><td style='padding:20px'>
                        <table width='100%' cellpadding='0' cellspacing='0'>
                          <tr>
                            <td style='padding:6px 0'>
                              <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Ministério</span><br>
                              <strong style='color:#1a2332;font-size:15px'>" . htmlspecialchars($mn['name']) . "</strong>
                            </td>
                          </tr>
                          <tr><td style='padding:6px 0;border-top:1px solid #e5e7eb'>
                            <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Atividade</span><br>
                            <strong style='color:#1a2332;font-size:15px'>" . htmlspecialchars($title) . "</strong>
                          </td></tr>
                          <tr><td style='padding:6px 0;border-top:1px solid #e5e7eb'>
                            <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Data</span><br>
                            <strong style='color:#1a2332;font-size:15px'>{$dayName}, {$dateFormatted}</strong>
                          </td></tr>
                          " . ($timeStart ? "<tr><td style='padding:6px 0;border-top:1px solid #e5e7eb'>
                            <span style='font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Horário</span><br>
                            <strong style='color:#1a2332;font-size:15px'>" . substr($timeStart,0,5) . "</strong>
                          </td></tr>" : "") . "
                        </table>
                      </td></tr>
                    </table>

                    <p style='color:#6b7280;font-size:14px;text-align:center;margin-bottom:20px'>
                      ⏰ Prazo para responder: <strong style='color:#1a2332'>{$prazo}</strong>
                    </p>

                    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px'>
                      <tr>
                        <td style='padding-right:6px'>
                          <a href='{$confirmUrl}' style='display:block;text-align:center;background:{$accentColor};color:white;padding:14px;border-radius:10px;text-decoration:none;font-size:15px;font-weight:600'>
                            ✅ Confirmar presença
                          </a>
                        </td>
                        <td style='padding-left:6px'>
                          <a href='{$refuseUrl}' style='display:block;text-align:center;background:#f3f4f6;color:#374151;padding:14px;border-radius:10px;text-decoration:none;font-size:15px;font-weight:600'>
                            ❌ Não posso ir
                          </a>
                        </td>
                      </tr>
                    </table>

                    <p style='color:#9ca3af;font-size:12px;text-align:center;margin:0'>
                      Sua resposta ajuda a equipe a se organizar melhor. Obrigado! 🙏
                    </p>";
                }

                $html = email_template(
                    $tpl['title'] . " · {$mn['name']} em {$dateFormatted}",
                    $emailBody,
                    $churchId
                );

                send_email($memberEmail, $memberName, $tpl['title'], $html, $churchId);
            }
        }

        // Disparar todos os pushes enfileirados e limpar inscrições expiradas
        if ($webPush) {
            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
                       ->execute([$report->getRequest()->getUri()->__toString()]);
                }
            }
        }
    }

    // Integração automática com a agenda
    $locId = null;
    if ($location) {
        $locStmt = $db->prepare("SELECT id FROM locations WHERE church_id = ? AND name LIKE ? LIMIT 1");
        $locStmt->execute([$churchId, '%' . $location . '%']);
        $locRow = $locStmt->fetch();
        $locId  = $locRow ? $locRow['id'] : null;
    }
    if ($timeEnd) { // só insere na agenda se tiver horário de fim
        $db->prepare("
            INSERT INTO agenda_events
              (church_id, title, description, location_id, event_date, time_start, time_end,
               ministry_id, ministry_activity_id, status, type, color)
            VALUES (?,?,?,?,?,?,?,?,'approved','ministry_activity','#185FA5')
        ")->execute([$churchId, $title, $description?:null, $locId, $date,
                     $timeStart, $timeEnd, $ministryId, $activityId]);
    }

    return $activityId;
}

/**
 * Data (Y-m-d) da última ocorrência de $weekday estritamente ANTES de $baseDate.
 * $weekday: 'monday'..'sunday'. Usado pra achar "a sexta-feira antes deste domingo".
 */
function previous_weekday_before(string $baseDate, string $weekday): string {
    $map = ['sunday'=>0,'monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6];
    $target = $map[strtolower($weekday)] ?? null;
    $base = new DateTime($baseDate);
    if ($target === null) return $base->format('Y-m-d');

    $baseDow = (int)$base->format('w');
    $diff = ($baseDow - $target + 7) % 7;
    if ($diff === 0) $diff = 7; // mesmo dia da semana → volta uma semana inteira
    $base->modify("-{$diff} days");
    return $base->format('Y-m-d');
}
