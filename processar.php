<?php
// ============================================
// FITLIFE ACADEMIA - PROCESSAMENTO DA MATRÍCULA
// Não utiliza banco de dados.
// Os dados existem apenas durante esta requisição.
// ============================================

declare(strict_types=1);

// Escapa dados antes de exibi-los no HTML.
function e(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Formata valores em centavos para reais brasileiros.
function reais(int $centavos): string
{
    return 'R$ ' . number_format(
        $centavos / 100,
        2,
        ',',
        '.'
    );
}

// Exibe uma mensagem de erro e oferece retorno ao formulário.
function erro(string $mensagem): never
{
    http_response_code(422);

    echo '<!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verificação de dados | FitLife</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <header class="topbar">
            <a href="index.html" class="brand">
                <span class="brand-icon">F</span>
                FIT<span class="lime">LIFE</span>
            </a>
        </header>
        <main class="container form-container">
            <section class="card error-card">
                <p class="eyebrow">NÃO FOI POSSÍVEL CONTINUAR</p>
                <h1 class="error-text">Verifique os dados</h1>
                <p>' . e($mensagem) . '</p>
                <a href="cadastro.html" class="btn btn-primary">
                    ← Voltar ao formulário
                </a>
            </section>
        </main>
    </body>
    </html>';

    exit;
}

// O formulário só pode ser processado por POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cadastro.html');
    exit;
}

// Confere se os campos recebidos são textos simples.
$campos = [
    'nome',
    'idade',
    'telefone',
    'email',
    'data_matricula',
    'plano',
    'pagamento'
];

foreach ($campos as $campo) {
    if (!isset($_POST[$campo]) || !is_string($_POST[$campo])) {
        erro('Todos os campos devem ser preenchidos corretamente.');
    }

    if (trim($_POST[$campo]) === '') {
        erro('O campo "' . $campo . '" é obrigatório.');
    }
}

// Lê os dados enviados.
$nome = trim($_POST['nome']);
$idadeTexto = trim($_POST['idade']);
$telefone = trim($_POST['telefone']);
$email = trim($_POST['email']);
$dataMatricula = trim($_POST['data_matricula']);
$plano = $_POST['plano'];
$pagamento = $_POST['pagamento'];

// Valida o nome.
if (
    mb_strlen($nome, 'UTF-8') < 3 ||
    mb_strlen($nome, 'UTF-8') > 100 ||
    preg_match('/\d/u', $nome)
) {
    erro('Informe um nome válido, com pelo menos 3 caracteres e sem números.');
}

// Valida a idade: deve ser um número inteiro entre 18 e 120.
if (!preg_match('/^\d{1,3}$/', $idadeTexto)) {
    erro('A idade deve ser um número inteiro válido.');
}

$idade = (int) $idadeTexto;

if ($idade < 18 || $idade > 120) {
    erro('A matrícula é permitida somente para pessoas entre 18 e 120 anos.');
}

// Valida o telefone, permitindo números, espaços, parênteses,
// sinal de mais e hífen. Exige de 10 a 15 dígitos.
$digitosTelefone = preg_replace('/\D/', '', $telefone);

if (
    strlen($digitosTelefone) < 10 ||
    strlen($digitosTelefone) > 15 ||
    !preg_match('/^[0-9()+ .-]+$/', $telefone)
) {
    erro('Informe um telefone válido com DDD.');
}

// Valida o e-mail.
if (
    strlen($email) > 150 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    erro('Informe um endereço de e-mail válido.');
}

// Valida a data real no formato AAAA-MM-DD.
// A data não pode ser futura nem anterior a 01/01/2000.
$dataObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $dataMatricula);
$errosData = DateTimeImmutable::getLastErrors();

$dataValida = $dataObjeto !== false
    && $dataObjeto->format('Y-m-d') === $dataMatricula
    && (
        $errosData === false ||
        (
            $errosData['warning_count'] === 0 &&
            $errosData['error_count'] === 0
        )
    );

$hoje = new DateTimeImmutable('today');
$dataMinima = new DateTimeImmutable('2000-01-01');

if (
    !$dataValida ||
    $dataObjeto > $hoje ||
    $dataObjeto < $dataMinima
) {
    erro('Informe uma data de matrícula válida, entre 01/01/2000 e hoje.');
}

// Catálogo fixo de planos. Os preços não vêm do navegador.
$planos = [
    'basico' => [
        'nome' => 'Básico',
        'descricao' => 'Musculação',
        'preco' => 8990
    ],
    'plus' => [
        'nome' => 'Plus',
        'descricao' => 'Musculação e aulas coletivas',
        'preco' => 12990
    ],
    'premium' => [
        'nome' => 'Premium',
        'descricao' => 'Todas as modalidades',
        'preco' => 17990
    ]
];

// Confere se o plano realmente existe no catálogo.
if (!isset($planos[$plano])) {
    erro('Selecione um plano válido.');
}

// Confere a forma de pagamento.
if (!in_array($pagamento, ['pix', 'cartao'], true)) {
    erro('Selecione uma forma de pagamento válida.');
}

// Define o preço original em centavos.
$planoSelecionado = $planos[$plano];
$valorOriginal = $planoSelecionado['preco'];

// Regra financeira:
// PIX concede 10%; idade igual ou superior a 60 concede 15%.
// Aplica-se somente o maior percentual, sem acumular.
$descontoPercentual = 0;

if ($pagamento === 'pix') {
    $descontoPercentual = 10;
}

if ($idade >= 60) {
    $descontoPercentual = max($descontoPercentual, 15);
}

// Calcula o desconto em centavos, arredondando para o centavo mais próximo.
$valorDesconto = intdiv(
    ($valorOriginal * $descontoPercentual) + 50,
    100
);

$valorFinal = $valorOriginal - $valorDesconto;

// Prepara os rótulos para o comprovante.
$formaPagamento = $pagamento === 'pix' ? 'PIX' : 'Cartão';

// Formata a data para exibição brasileira.
$dataExibicao = $dataObjeto->format('d/m/Y');

// Os valores exibidos abaixo são escapados para evitar
// que dados fornecidos no formulário sejam interpretados como HTML.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprovante de Matrícula | FitLife</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="topbar no-print">
        <a href="index.html" class="brand">
            <span class="brand-icon">F</span>
            FIT<span class="lime">LIFE</span>
        </a>
        <span class="admin-label">Comprovante administrativo</span>
    </header>

    <main class="container">
        <section class="card receipt">
            <div class="receipt-header">
                <span class="receipt-status">✓ MATRÍCULA PROCESSADA</span>
                <p class="eyebrow">FITLIFE ACADEMIA</p>
                <h1>Comprovante de <span class="lime">matrícula</span></h1>
                <p class="muted">
                    Confira os dados informados e os valores calculados.
                </p>
            </div>

            <section class="receipt-section">
                <h2>Dados do aluno</h2>

                <div class="receipt-row">
                    <span>Nome completo</span>
                    <strong><?= e($nome) ?></strong>
                </div>

                <div class="receipt-row">
                    <span>Idade</span>
                    <strong><?= $idade ?> anos</strong>
                </div>

                <div class="receipt-row">
                    <span>Telefone</span>
                    <strong><?= e($telefone) ?></strong>
                </div>

                <div class="receipt-row">
                    <span>E-mail</span>
                    <strong><?= e($email) ?></strong>
                </div>

                <div class="receipt-row">
                    <span>Data da matrícula</span>
                    <strong><?= e($dataExibicao) ?></strong>
                </div>
            </section>

            <section class="receipt-section">
                <h2>Plano contratado</h2>

                <div class="receipt-row">
                    <span>Plano</span>
                    <strong><?= e($planoSelecionado['nome']) ?></strong>
                </div>

                <div class="receipt-row">
                    <span>Descrição</span>
                    <strong><?= e($planoSelecionado['descricao']) ?></strong>
                </div>

                <div class="receipt-row">
                    <span>Forma de pagamento</span>
                    <strong><?= e($formaPagamento) ?></strong>
                </div>
            </section>

            <section class="total-box">
                <div class="receipt-row">
                    <span>Valor original</span>
                    <strong><?= reais($valorOriginal) ?></strong>
                </div>

                <div class="receipt-row">
                    <span>Desconto aplicado</span>
                    <strong>
                        <?= $descontoPercentual ?>%
                        (−<?= reais($valorDesconto) ?>)
                    </strong>
                </div>

                <div class="receipt-row">
                    <span>Valor final mensal</span>
                    <strong class="total-value">
                        <?= reais($valorFinal) ?>
                    </strong>
                </div>
            </section>

            <p class="muted small">
                O desconto corresponde ao maior percentual elegível.
                Os descontos não são cumulativos.
            </p>

            <p class="muted small">
                Este comprovante é demonstrativo e foi gerado durante
                esta requisição. Nenhum dado foi salvo em banco de dados.
            </p>

            <div class="form-actions no-print">
                <a href="index.html" class="btn btn-secondary">
                    Voltar ao painel
                </a>

                <a href="cadastro.html" class="btn btn-secondary">
                    Nova matrícula
                </a>

                <button type="button" class="btn btn-primary"
                    onclick="window.print()">
                    Imprimir comprovante
                </button>
            </div>
        </section>
    </main>

    <footer class="footer no-print">
        FitLife Academia · Sistema interno de matrículas
    </footer>
</body>
</html>