<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
$errors     = [];

// Buscar ministério de qualquer filial da sede
$stmt = $db->prepare("
    SELECT mn.*, ch.id AS branch_church_id
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$stmt->execute([$ministryId, SEDE_ID, SEDE_ID]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$churchId = $mn['church_id']; // usa a church_id DO MINISTÉRIO, não do usuário

// Membros do ministério filtrados pela mesma filial
$members = $db->prepare("
    SELECT m.id, m.name FROM member_ministries mm
    JOIN members m ON m.id = mm.member_id
    WHERE mm.ministry_id = ? AND m.church_id = ?
    ORDER BY m.name
");
$members->execute([$ministryId, $churchId]);
$members = $members->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']         ?? '');
    $description = trim($_POST['description']   ?? '');
    $date        = trim($_POST['activity_date'] ?? '');
    $timeStart   = trim($_POST['time_start']    ?? '') ?: null;
    $timeEnd     = trim($_POST['time_end']      ?? '') ?: null;
    $location    = trim($_POST['location']      ?? '');
    $scaledIds   = $_POST['scaled_ids'] ?? [];
    $roles       = $_POST['roles']      ?? [];

    if ($title === '') $errors[] = 'Título é obrigatório.';
    if ($date  === '') $errors[] = 'Data é obrigatória.';

    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO ministry_activities
              (ministry_id, church_id, title, description, activity_date, time_start, time_end, location, status)
            VALUES (:ministry_id,:church_id,:title,:description,:date,:time_start,:time_end,:location,'scheduled')
        ");
        $stmt->execute([
            ':ministry_id' => $ministryId,
            ':church_id'   => $churchId,
            ':title'       => $title,
            ':description' => $description ?: null,
            ':date'        => $date,
            ':time_start'  => $timeStart,
            ':time_end'    => $timeEnd,
            ':location'    => $location ?: null,
        ]);
        $activityId = $db->lastInsertId();

        // Escalar membros e notificar
        if (!empty($scaledIds)) {
            $ss = $db->prepare("INSERT IGNORE INTO ministry_activity_members (activity_id, member_id, role, status, confirm_token, token_expires_at, notified_at) VALUES (?,?,?,'pending',?,?,NOW())");
            foreach ($scaledIds as $mid) {
                $role  = trim($roles[$mid] ?? '');
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime($date . ' -2 days'));
                $ss->execute([$activityId, (int)$mid, $role ?: null, $token, $expires]);
            }

            // Notificar cada membro escalado com links de one-click
            foreach ($scaledIds as $mid) {
                $memberName = $db->query("SELECT name FROM members WHERE id=".(int)$mid)->fetchColumn();

                // Buscar token gerado
                $tokenRow = $db->prepare("SELECT confirm_token FROM ministry_activity_members WHERE activity_id=? AND member_id=?");
                $tokenRow->execute([$activityId, (int)$mid]);
                $token = $tokenRow->fetchColumn();

                $confirmUrl = APP_URL . '/respond.php?token=' . $token . '&action=confirm';
                $refuseUrl  = APP_URL . '/respond.php?token=' . $token . '&action=refuse';
                $prazo      = date('d/m/Y', strtotime($date . ' -2 days'));

                $tpl = notification_template('scale_invited', [
                    'nome'       => $memberName,
                    'ministerio' => $mn['name'],
                    'data'       => date('d/m/Y (l)', strtotime($date)),
                    'prazo'      => $prazo,
                ], $churchId);

                $fullContent = $tpl['content']
                    . "\n\n✅ Confirmar presença: $confirmUrl"
                    . "\n❌ Não posso ir: $refuseUrl";

                $db->prepare("
                    INSERT INTO announcements (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
                    VALUES (?,?,?,'general','member',?,'internal,push','sent',?,NOW())
                ")->execute([
                    $churchId,
                    $tpl['title'],
                    $fullContent,
                    (int)$mid,
                    auth_member_id()
                ]);

                // Enviar e-mail se o membro tiver e-mail cadastrado
                $memberEmail = $db->query("SELECT email FROM members WHERE id=".(int)$mid)->fetchColumn();
                if ($memberEmail) {
                    $accentColor  = setting('accent_color',  '#1D9E75', $churchId);
                    $primaryColor = setting('primary_color', '#012a36', $churchId);
                    $dateFormatted = date('d/m/Y', strtotime($date));
                    $dayName = ['Sunday'=>'Domingo','Monday'=>'Segunda-feira','Tuesday'=>'Terça-feira',
                                'Wednesday'=>'Quarta-feira','Thursday'=>'Quinta-feira',
                                'Friday'=>'Sexta-feira','Saturday'=>'Sábado'][date('l', strtotime($date))] ?? '';

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

                    $html = email_template(
                        "Você foi escalado(a) para {$mn['name']} em {$dateFormatted}",
                        $emailBody,
                        $churchId
                    );

                    send_email($memberEmail, $memberName, $tpl['title'], $html, $churchId);
                }
            }
        }

        // ── Integração automática com a agenda ──
        // Buscar location_id pelo nome do campo location (texto livre → tenta casar com locations)
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

        header('Location: /pages/ministries/view.php?id=' . $ministryId . '&saved=1');
        exit;
    }
}

$pageTitle  = 'Nova atividade · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($mn['name']) ?>
  </a>
</div>

<form method="POST" style="width:100%">

  <!-- Dados da atividade -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados da atividade</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control"
               placeholder="Ex: Ensaio, Culto de domingo, Treinamento…"
               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Local</label>
        <input type="text" name="location" class="form-control"
               placeholder="Sala, templo, endereço…"
               value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="activity_date" class="form-control"
               value="<?= htmlspecialchars($_POST['activity_date'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control"
               value="<?= htmlspecialchars($_POST['time_start'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control"
               value="<?= htmlspecialchars($_POST['time_end'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Descrição / Observações</label>
      <textarea name="description" class="form-control" rows="2"
                placeholder="Detalhes, repertório, materiais necessários…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- Escala -->
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Escala — quem vai participar</p>
    <?php if (empty($members)): ?>
      <p style="font-size:13px;color:var(--text-muted)">
        Nenhum membro vinculado ao ministério ainda.
        <a href="/pages/ministries/view.php?id=<?= $ministryId ?>">Vincular membros</a>
      </p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px">
        <?php foreach ($members as $m): ?>
          <div style="border:1px solid var(--border);border-radius:7px;padding:10px 12px;display:flex;align-items:center;gap:10px">
            <input type="checkbox" name="scaled_ids[]" value="<?= $m['id'] ?>"
                   id="m<?= $m['id'] ?>"
                   class="scale-cb" data-id="<?= $m['id'] ?>"
                   <?= in_array($m['id'], $_POST['scaled_ids']??[]) ? 'checked' : '' ?>>
            <div class="avatar" style="width:28px;height:28px;font-size:10px;flex-shrink:0">
              <?= strtoupper(substr($m['name'],0,2)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <label for="m<?= $m['id'] ?>" style="font-size:13px;font-weight:500;cursor:pointer;display:block">
                <?= htmlspecialchars($m['name']) ?>
              </label>
              <input type="text" name="roles[<?= $m['id'] ?>]"
                     class="form-control role-input" id="role<?= $m['id'] ?>"
                     placeholder="Função (ex: vocal, guitarra…)"
                     style="margin-top:4px;font-size:12px;padding:5px 8px;display:<?= in_array($m['id'], $_POST['scaled_ids']??[]) ? 'block' : 'none' ?>"
                     value="<?= htmlspecialchars($_POST['roles'][$m['id']] ?? '') ?>">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar atividade</button>
    <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
// Mostrar/ocultar campo de função ao marcar/desmarcar
document.querySelectorAll('.scale-cb').forEach(cb => {
  cb.addEventListener('change', function() {
    const roleInput = document.getElementById('role' + this.dataset.id);
    roleInput.style.display = this.checked ? 'block' : 'none';
    if (!this.checked) roleInput.value = '';
  });
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
