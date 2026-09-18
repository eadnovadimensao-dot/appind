<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();

$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');

// Só a igreja selecionada
$where  = ['m.church_id = :church_id'];
$params = [':church_id' => $churchId];
if ($search !== '') { $where[] = '(m.name LIKE :q OR m.phone LIKE :q OR m.email LIKE :q)'; $params[':q'] = "%$search%"; }
if ($status !== '') { $where[] = 'm.status = :status'; $params[':status'] = $status; }

$stmt = $db->prepare("
    SELECT m.name, m.phone, m.email, m.cpf, m.birth_date, m.status,
           c.name AS cell_name, ch.name AS branch_name, m.address, m.number, m.neighborhood, m.city
    FROM members m
    LEFT JOIN cells c     ON c.id = m.cell_id
    LEFT JOIN churches ch ON ch.id = m.church_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ch.name ASC, m.name ASC
");
$stmt->execute($params);
$members = $stmt->fetchAll();

$statusLabels = [
    'active' => 'Ativo', 'visitor' => 'Visitante', 'inactive' => 'Afastado',
    'discipline' => 'Disciplina', 'transferred' => 'Transferido', 'deceased' => 'Falecido',
];

$rows = [];
foreach ($members as $m) {
    $rows[] = [
        'Nome'      => $m['name'],
        'Telefone'  => $m['phone'] ?? '',
        'E-mail'    => $m['email'] ?? '',
        'CPF'       => $m['cpf'] ?? '',
        'Nascimento'=> $m['birth_date'] ? date('d/m/Y', strtotime($m['birth_date'])) : '',
        'Célula'    => $m['cell_name'] ?? '',
        'Filial'    => $m['branch_name'] ?? '',
        'Endereço'  => trim(($m['address'] ?? '') . ($m['number'] ? ', ' . $m['number'] : '')),
        'Bairro'    => $m['neighborhood'] ?? '',
        'Cidade'    => $m['city'] ?? '',
        'Status'    => $statusLabels[$m['status']] ?? $m['status'],
    ];
}

csv_download('membros_' . date('Y-m-d') . '.csv', $rows);
