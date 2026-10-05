<?php
/* =====================================================================
   process.php
   Recebe os dados do formulário de denúncia, valida, envia por e-mail
   (via SMTP autenticado, usando PHPMailer) e registra um protocolo.
   ===================================================================== */

// ---------------------------------------------------------------------
// 0. CONFIGURAÇÕES — AJUSTE ESTES DADOS
// ---------------------------------------------------------------------

// Conta de e-mail criada no cPanel (Contas de E-mail) que vai ENVIAR
// as notificações. Não precisa ser a mesma que recebe.
$SMTP_HOST     = 'smtp.claramail.com.br';
$SMTP_USER     = 'ti@vieiradistribuidor.com.br'; // conta configurada com redirecionamento para RH e diretoria
$SMTP_PASS     = 'teste01@';
$SMTP_PORT     = 465;   // 465 = SSL | 587 = TLS
$SMTP_SECURE   = 'ssl'; // 'ssl' para porta 465, 'tls' para porta 587

// Para quem a denúncia deve ser enviada (a própria conta ti@ já redireciona
// automaticamente para RH e diretoria, configurado no painel Claramail)
$DESTINATARIO_EMAIL = 'ti@vieiradistribuidor.com.br';
$DESTINATARIO_NOME  = 'Ouvidoria Grupo Vieira';

// Pasta (fora do acesso público direto, idealmente) onde fica o log/CSV
$LOG_FILE = __DIR__ . '/log_denuncias.csv';

// Pasta onde os anexos ficam temporariamente salvos antes de enviar
$UPLOAD_TMP_DIR = __DIR__ . '/uploads_tmp/';

// Página exibida após sucesso
$PAGINA_SUCESSO = 'sucesso.html';

// Tamanho máximo de anexo (em bytes) — 10 MB
$TAMANHO_MAX_ANEXO = 10 * 1024 * 1024;

// Extensões de anexo permitidas
$EXTENSOES_PERMITIDAS = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

// ---------------------------------------------------------------------
// 1. CARREGAR PHPMailer (arquivos baixados manualmente — ver instruções)
// ---------------------------------------------------------------------

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ---------------------------------------------------------------------
// 2. SÓ ACEITA POST
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Método não permitido.');
}

// ---------------------------------------------------------------------
// 3. ANTI-SPAM (honeypot) — campo invisível que só robôs preenchem
// ---------------------------------------------------------------------

if (!empty($_POST['campo_verificacao'])) {
    // Finge sucesso pro robô, mas não envia nada
    header('Location: ' . $PAGINA_SUCESSO);
    exit;
}

// ---------------------------------------------------------------------
// 4. FUNÇÃO AUXILIAR DE SANITIZAÇÃO
// ---------------------------------------------------------------------

function campo($nome, $obrigatorio = false) {
    $valor = isset($_POST[$nome]) ? trim($_POST[$nome]) : '';
    $valor = htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');

    if ($obrigatorio && $valor === '') {
        throw new RuntimeException("Campo obrigatório ausente: $nome");
    }

    return $valor;
}

// ---------------------------------------------------------------------
// 5. VALIDAÇÃO E COLETA DOS CAMPOS
// ---------------------------------------------------------------------

try {

    $desejaIdentificar = campo('DesejaSeIdentificar', true);

    $nome     = campo('Nome');
    $setor    = campo('Setor');
    $email    = campo('Email');
    $whatsapp = campo('WhatsApp');

    if ($desejaIdentificar === 'Sim' && ($nome === '' || $email === '')) {
        throw new RuntimeException('Dados de identificação incompletos.');
    }

    $denunciadoNome  = campo('DenunciadoNome');
    $denunciadoCargo = campo('DenunciadoCargo');
    $denunciadoSetor = campo('DenunciadoSetor');

    $dataFato  = campo('DataFato');
    $localFato = campo('LocalFato');

    $descricao = campo('Descricao', true);

    $possuiTestemunhas   = campo('PossuiTestemunhas', true);
    $testemunhas         = campo('Testemunhas');
    $contatoTestemunhas  = campo('ContatoTestemunhas');

    if ($possuiTestemunhas === 'Sim' && $testemunhas === '') {
        throw new RuntimeException('Nome das testemunhas ausente.');
    }

    $possuiEvidencia = campo('PossuiEvidencia', true);

    $jaReportou    = campo('JaReportou', true);
    $reportadoPara = campo('ReportadoPara');

    if ($jaReportou === 'Sim' && $reportadoPara === '') {
        throw new RuntimeException('Campo "Para quem reportou" ausente.');
    }

    $declaracao = campo('Declaracao', true);

} catch (RuntimeException $e) {
    http_response_code(400);
    die('Erro de validação: ' . $e->getMessage() . ' — volte e preencha corretamente.');
}

// ---------------------------------------------------------------------
// 6. TRATAMENTO DO ANEXO (se enviado)
// ---------------------------------------------------------------------

$anexoCaminho = null;
$anexoNomeOriginal = null;

if ($possuiEvidencia === 'Sim' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {

    $arquivo = $_FILES['attachment'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        die('Erro no envio do anexo.');
    }

    if ($arquivo['size'] > $TAMANHO_MAX_ANEXO) {
        http_response_code(400);
        die('Anexo maior que o limite de 10 MB.');
    }

    $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));

    if (!in_array($extensao, $EXTENSOES_PERMITIDAS, true)) {
        http_response_code(400);
        die('Tipo de arquivo não permitido.');
    }

    if (!is_dir($UPLOAD_TMP_DIR)) {
        mkdir($UPLOAD_TMP_DIR, 0755, true);
    }

    $nomeSeguro = uniqid('anexo_', true) . '.' . $extensao;
    $anexoCaminho = $UPLOAD_TMP_DIR . $nomeSeguro;

    if (!move_uploaded_file($arquivo['tmp_name'], $anexoCaminho)) {
        http_response_code(500);
        die('Falha ao salvar o anexo no servidor.');
    }

    $anexoNomeOriginal = $arquivo['name'];
}

// ---------------------------------------------------------------------
// 7. GERAR NÚMERO DE PROTOCOLO
// ---------------------------------------------------------------------

$protocolo = date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

// ---------------------------------------------------------------------
// 8. REGISTRAR NO LOG (CSV) — histórico interno da ouvidoria
// ---------------------------------------------------------------------

$novoArquivo = !file_exists($LOG_FILE);

$linhaLog = fopen($LOG_FILE, 'a');

if ($novoArquivo) {
    fputcsv($linhaLog, ['Protocolo', 'Data/Hora', 'Identificado', 'Nome', 'Setor Denunciado', 'Status']);
}

fputcsv($linhaLog, [
    $protocolo,
    date('d/m/Y H:i:s'),
    $desejaIdentificar,
    $desejaIdentificar === 'Sim' ? $nome : 'Anônimo',
    $denunciadoSetor,
    'Recebida',
]);

fclose($linhaLog);

// ---------------------------------------------------------------------
// 9. MONTAR E ENVIAR O E-MAIL
// ---------------------------------------------------------------------

$mail = new PHPMailer(true);

try {

    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;
    $mail->SMTPSecure = $SMTP_SECURE;
    $mail->Port       = $SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($SMTP_USER, 'Canal de Denúncias - Grupo Vieira');
    $mail->addAddress($DESTINATARIO_EMAIL, $DESTINATARIO_NOME);

    if ($desejaIdentificar === 'Sim' && $email !== '') {
        $mail->addReplyTo($email, $nome);
    }

    if ($anexoCaminho) {
        $mail->addAttachment($anexoCaminho, $anexoNomeOriginal);
    }

    $mail->isHTML(true);
    $mail->Subject = "Nova Denúncia [$protocolo] - Canal de Ouvidoria Grupo Vieira";

    $mail->Body = "
        <h2>Nova denúncia recebida — Protocolo: $protocolo</h2>

        <h3>1. Identificação</h3>
        <p><b>Deseja se identificar:</b> $desejaIdentificar</p>
        " . ($desejaIdentificar === 'Sim' ? "
        <p><b>Nome:</b> $nome</p>
        <p><b>Setor:</b> $setor</p>
        <p><b>E-mail:</b> $email</p>
        <p><b>WhatsApp:</b> $whatsapp</p>
        " : "") . "

        <h3>2. Denunciado</h3>
        <p><b>Nome(s):</b> $denunciadoNome</p>
        <p><b>Cargo(s):</b> $denunciadoCargo</p>
        <p><b>Setor(es):</b> $denunciadoSetor</p>

        <h3>3. Data/Local</h3>
        <p><b>Data/período:</b> $dataFato</p>
        <p><b>Local:</b> $localFato</p>

        <h3>4. Descrição</h3>
        <p>" . nl2br($descricao) . "</p>

        <h3>5. Testemunhas</h3>
        <p><b>Possui:</b> $possuiTestemunhas</p>
        " . ($possuiTestemunhas === 'Sim' ? "
        <p><b>Nome(s):</b> $testemunhas</p>
        <p><b>Contato(s):</b> $contatoTestemunhas</p>
        " : "") . "

        <h3>6. Evidências</h3>
        <p><b>Possui evidência:</b> $possuiEvidencia</p>
        " . ($anexoNomeOriginal ? "<p><b>Anexo:</b> $anexoNomeOriginal</p>" : "") . "

        <h3>7. Reportou anteriormente</h3>
        <p><b>Já reportou:</b> $jaReportou</p>
        " . ($jaReportou === 'Sim' ? "<p><b>Para quem:</b> $reportadoPara</p>" : "") . "

        <hr>
        <p><i>Declaração de veracidade aceita pelo denunciante.</i></p>
    ";

    $mail->send();

    // Apaga o anexo temporário do servidor após o envio bem-sucedido
    if ($anexoCaminho && file_exists($anexoCaminho)) {
        unlink($anexoCaminho);
    }

    header('Location: ' . $PAGINA_SUCESSO . '?protocolo=' . urlencode($protocolo));
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Não foi possível enviar a denúncia no momento. Detalhe técnico: ' . $mail->ErrorInfo);
}