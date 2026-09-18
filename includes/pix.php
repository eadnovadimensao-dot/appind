<?php
// Gera o payload "Pix Copia e Cola" (padrão BR Code / EMVCo do Banco
// Central) a partir da chave Pix da igreja. Não depende de nenhum gateway
// de pagamento — é só o formato que qualquer banco/carteira já lê. Sem
// confirmação automática: quem recebe a doação confere pelo próprio banco.

function pix_emv_field(string $id, string $value): string {
    return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

// CRC16-CCITT (falso), poly 0x1021, init 0xFFFF — exigido pelo padrão EMV
function pix_crc16(string $payload): string {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($payload); $i++) {
        $crc ^= (ord($payload[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

// O padrão EMV só aceita um alfabeto simples (sem acento) nesses campos
function pix_sanitize(string $s, int $maxLen): string {
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N',
    ]);
    $s = preg_replace('/[^A-Za-z0-9 ]/', '', $s) ?? '';
    return mb_substr(trim($s), 0, $maxLen);
}

/**
 * Monta o código Pix copia-e-cola. $amount em reais (null ou 0 = sem valor
 * fixo, quem paga digita quanto quer dar no próprio banco). $txid opcional
 * (até 25 caracteres alfanuméricos) — só um rótulo no comprovante, não gera
 * confirmação automática nenhuma no sistema.
 */
function pix_generate_code(string $pixKey, string $receiverName, string $receiverCity, ?float $amount = null, string $txid = ''): string {
    $name = pix_sanitize($receiverName, 25) ?: 'IGREJA';
    $city = pix_sanitize($receiverCity, 15) ?: 'BRASIL';
    $tx   = preg_replace('/[^A-Za-z0-9]/', '', $txid) ?? '';
    $tx   = $tx !== '' ? mb_substr($tx, 0, 25) : '***';

    $merchantAccount = pix_emv_field('00', 'BR.GOV.BCB.PIX') . pix_emv_field('01', $pixKey);

    $payload = pix_emv_field('00', '01')          // Payload Format Indicator
             . pix_emv_field('01', '11')           // Point of Initiation (reutilizável)
             . pix_emv_field('26', $merchantAccount)
             . pix_emv_field('52', '0000')         // Merchant Category Code
             . pix_emv_field('53', '986');          // Moeda (BRL)

    if ($amount !== null && $amount > 0) {
        $payload .= pix_emv_field('54', number_format($amount, 2, '.', ''));
    }

    $payload .= pix_emv_field('58', 'BR')
              . pix_emv_field('59', $name)
              . pix_emv_field('60', $city)
              . pix_emv_field('62', pix_emv_field('05', $tx));

    $payload .= '6304';
    $payload .= pix_crc16($payload);

    return $payload;
}

// Lê o Pix SÓ da própria igreja/filial. setting() herda da sede quando a
// filial não tem nenhuma configuração, e pra dinheiro isso mandaria a oferta
// da filial pra conta da sede sem ninguém ter escolhido isso.
function pix_settings(int $churchId): array {
    $stmt = db()->prepare("SELECT `key`, `value` FROM church_settings WHERE church_id = ? AND `key` IN ('pix_key','pix_key_type','pix_receiver_name','pix_receiver_city')");
    $stmt->execute([$churchId]);
    $s = ['pix_key' => '', 'pix_key_type' => '', 'pix_receiver_name' => '', 'pix_receiver_city' => ''];
    foreach ($stmt->fetchAll() as $row) $s[$row['key']] = (string)$row['value'];
    return $s;
}
