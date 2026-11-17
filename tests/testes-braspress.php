<?php
/**
 * Testes da parte Braspress — sem WordPress, sem banco e sem internet.
 *
 *   Como rodar:   php tests/testes-braspress.php
 *
 * Mesma técnica de tests/testes.php: as funções do WordPress usadas são
 * imitadas aqui, e as "respostas da Braspress" são textos que nós controlamos.
 * Os documentos usados são números de EXEMPLO amplamente divulgados
 * (válidos pelos dígitos, de ninguém em particular).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('CVBP_USUARIO', 'usuario-teste');
define('CVBP_SENHA', 'senha-teste');
define('AUTH_KEY', str_repeat('a1B2c3D4', 8));
define('SECURE_AUTH_KEY', str_repeat('Z9y8X7w6', 8));

$GLOBALS['opcoes'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['chamadas_http'] = 0;
$GLOBALS['resposta_falsa'] = ['code' => 200, 'body' => ''];

function get_option($n, $p = false) { return $GLOBALS['opcoes'][$n] ?? $p; }
function update_option($n, $v, $a = null) { $GLOBALS['opcoes'][$n] = $v; return true; }
function delete_option($n) { unset($GLOBALS['opcoes'][$n]); return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; $GLOBALS['ttl'][$k] = $t; return true; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function is_wp_error($x) { return false; }
function wp_timezone() { return new DateTimeZone('America/Bahia'); }
function wp_remote_post($url, $args) {
    $GLOBALS['chamadas_http']++;
    $GLOBALS['ultimo_url'] = $url;
    $GLOBALS['ultimo_envio'] = $args;
    return $GLOBALS['resposta_falsa'];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

require __DIR__ . '/../includes/class-cvte-config.php';
require __DIR__ . '/../includes/class-cvte-api.php';
require __DIR__ . '/../includes/class-cvte-empacotador.php';
require __DIR__ . '/../includes/braspress/class-cvbp-config.php';
require __DIR__ . '/../includes/braspress/class-cvbp-documento.php';
require __DIR__ . '/../includes/braspress/class-cvbp-api.php';
require __DIR__ . '/../includes/braspress/class-cvbp-exibicao.php';

$falhas = 0;
$total = 0;
function verificar(string $descricao, $obtido, $esperado): void
{
    global $falhas, $total;
    $total++;
    $ok = is_float($esperado) ? abs($obtido - $esperado) < 0.005 : $obtido === $esperado;
    if (!$ok) {
        $falhas++;
    }
    printf("  %s %s%s\n", $ok ? '✔' : '✘', $descricao,
        $ok ? '' : '  → obtido: ' . var_export($obtido, true) . ' · esperado: ' . var_export($esperado, true));
}
function secao(string $t): void { echo "\n$t\n"; }

const CPF_EXEMPLO  = '52998224725';     // 529.982.247-25
const CNPJ_EXEMPLO = '11222333000181';  // 11.222.333/0001-81

// ---------------------------------------------------------------------------
secao('1. CPF e CNPJ');
verificar('CPF de exemplo é válido', CVBP_Documento::cpf_valido('529.982.247-25'), true);
verificar('CPF com dígito errado é recusado', CVBP_Documento::cpf_valido('52998224724'), false);
verificar('CPF repetido (111…) é recusado', CVBP_Documento::cpf_valido('11111111111'), false);
verificar('CPF curto é recusado', CVBP_Documento::cpf_valido('1234567890'), false);
verificar('CNPJ de exemplo é válido', CVBP_Documento::cnpj_valido('11.222.333/0001-81'), true);
verificar('CNPJ com dígito errado é recusado', CVBP_Documento::cnpj_valido('11222333000182'), false);
verificar('CNPJ repetido é recusado', CVBP_Documento::cnpj_valido('00000000000000'), false);
verificar('classificar CPF → PF', CVBP_Documento::classificar(CPF_EXEMPLO)['tipo'] ?? null, 'PF');
verificar('classificar CNPJ → PJ', CVBP_Documento::classificar(CNPJ_EXEMPLO)['tipo'] ?? null, 'PJ');
verificar('classificar lixo → null', CVBP_Documento::classificar('abc'), null);

$campos = 'billing_cpf,billing_cnpj';
verificar('escolher: pega o CPF do formulário', CVBP_Documento::escolher(['billing_cpf' => '529.982.247-25'], $campos)['tipo'] ?? null, 'PF');
verificar('escolher: CPF inválido cai para o CNPJ', CVBP_Documento::escolher(['billing_cpf' => '123', 'billing_cnpj' => CNPJ_EXEMPLO], $campos)['tipo'] ?? null, 'PJ');
verificar('escolher: "pessoa jurídica" prefere o CNPJ', CVBP_Documento::escolher(['billing_cpf' => CPF_EXEMPLO, 'billing_cnpj' => CNPJ_EXEMPLO], $campos, '2')['tipo'] ?? null, 'PJ');
verificar('escolher: nada válido → null', CVBP_Documento::escolher(['billing_cpf' => ''], $campos), null);
verificar('escolher: ignora valor que não é texto', CVBP_Documento::escolher(['billing_cpf' => ['x']], $campos), null);

// ---------------------------------------------------------------------------
secao('2. Corpo da requisição');
$envio = [
    'cnpj_remetente' => CNPJ_EXEMPLO, 'documento' => CPF_EXEMPLO,
    'cep_origem' => '41940430', 'cep_destino' => '01310100', 'valor' => 600,
    'modal' => 'R', 'tipo_frete' => 1,
    'volumes' => [
        ['peso' => 25, 'dims' => [30, 50, 15]],   // ordem bagunçada de propósito
        ['peso' => 25, 'dims' => [50, 30, 15]],
        ['peso' => 2.5, 'dims' => [20, 16, 10]],
    ],
];
$c = CVBP_API::montar_corpo($envio);
verificar('peso total somado', $c['peso'], 52.5);
verificar('nº de volumes', $c['volumes'], 3);
verificar('caixas iguais agrupadas (2 linhas de cubagem)', count($c['cubagem']), 2);
verificar('grupo com 2 volumes', $c['cubagem'][0]['volumes'], 2);
verificar('medidas em METROS (50 cm → 0.5)', $c['cubagem'][0]['comprimento'], 0.5);
verificar('altura = menor lado', $c['cubagem'][0]['altura'], 0.15);
verificar('CEP vai como texto (preserva zero à esquerda)', $c['cepDestino'], '01310100');
verificar('tipoFrete como texto', $c['tipoFrete'], '1');
verificar('documento no campo cnpjDestinatario', $c['cnpjDestinatario'], CPF_EXEMPLO);
verificar('valor mínimo de R$ 1', CVBP_API::montar_corpo(['valor' => 0] + $envio)['vlrMercadoria'], 1.0);

// ---------------------------------------------------------------------------
secao('3. Leitura da resposta');
$r = CVBP_API::interpretar('{"id":987,"prazo":5,"totalFrete":83.21}');
verificar('sucesso reconhecido', $r['ok'], true);
verificar('valor lido', $r['valor'], 83.21);
verificar('prazo lido', $r['prazo'], 5);
verificar('id da cotação lido', $r['id'], '987');
$r = CVBP_API::interpretar('{"id":"<script>1</script>99","prazo":5,"totalFrete":83.21}');
verificar('id da cotação: só dígitos (vai para o pedido)', $r['id'], '199');
$r = CVBP_API::interpretar('{"id":{"x":1},"prazo":5,"totalFrete":83.21}');
verificar('id em formato estranho vira vazio, sem erro', $r['id'], '');
$r = CVBP_API::interpretar('{"statusCode":["x"],"message":"falhou"}', 500);
verificar('statusCode em formato estranho não quebra a mensagem', $r['erro'], 'Braspress recusou: falhou');
verificar('valor como texto "83,21"', CVBP_API::interpretar('{"prazo":"5","totalFrete":"83,21"}')['valor'], 83.21);
verificar('resposta dentro de lista', CVBP_API::interpretar('[{"prazo":3,"totalFrete":40}]')['valor'], 40.0);
$r = CVBP_API::interpretar('{"statusCode":400,"message":"CEP destino inválido","dateTime":"x","errorList":[{"message":"cepDestino"}]}', 400);
verificar('erro documentado reconhecido', $r['ok'], false);
verificar('mensagem e lista de erros preservadas', str_contains($r['erro'], 'CEP destino inválido') && str_contains($r['erro'], 'cepDestino'), true);
verificar('HTML não vira preço', CVBP_API::interpretar('<html>manutenção</html>', 502)['ok'], false);
verificar('frete zero não vira preço', CVBP_API::interpretar('{"totalFrete":0}')['ok'], false);

// ---------------------------------------------------------------------------
secao('4. Cotação completa (HTTP falso)');
$cfg = CVBP_Config::padroes();
$envio_api = ['cnpj_remetente' => CNPJ_EXEMPLO, 'documento' => CPF_EXEMPLO, 'cep_origem' => '41940430',
              'cep_destino' => '01310100', 'valor' => 250, 'volumes' => [['peso' => 20, 'dims' => [50, 40, 30]]]];

$GLOBALS['resposta_falsa'] = ['code' => 200, 'body' => '{"id":1,"prazo":6,"totalFrete":141.84}'];
$r = CVBP_API::cotar($envio_api, $cfg);
verificar('cota com sucesso', $r['ok'], true);
verificar('vai ao endpoint de produção', $GLOBALS['ultimo_url'], CVBP_Config::ENDPOINTS['producao']);
verificar('autenticação Basic no cabeçalho', $GLOBALS['ultimo_envio']['headers']['Authorization'], 'Basic ' . base64_encode('usuario-teste:senha-teste'));
verificar('corpo é JSON', is_array(json_decode($GLOBALS['ultimo_envio']['body'], true)), true);
verificar('senha não aparece no corpo', str_contains($GLOBALS['ultimo_envio']['body'], 'senha-teste'), false);

$antes = $GLOBALS['chamadas_http'];
$r = CVBP_API::cotar($envio_api, $cfg);
verificar('2ª cotação igual vem do cache', $r['cache'] && $GLOBALS['chamadas_http'] === $antes, true);

// Travas de segurança da conexão (checkup de segurança, 12/11/2026)
verificar('não segue redirecionamentos (a senha não vai para outro endereço)', $GLOBALS['ultimo_envio']['redirection'], 0);
verificar('certificado TLS sempre conferido', $GLOBALS['ultimo_envio']['sslverify'], true);
verificar('tamanho da resposta limitado', $GLOBALS['ultimo_envio']['limit_response_size'], CVTE_API::LIMITE_RESPOSTA);
verificar('o cache guarda o preço', in_array(141.84, array_column($GLOBALS['transients'], 'valor'), true), true);
verificar('o cache não guarda o CPF/CNPJ do cliente (LGPD)', str_contains(json_encode($GLOBALS['transients']), CPF_EXEMPLO), false);
// Revisão de 17/11/2026 (0.3.1)
$nome_cache = array_key_first($GLOBALS['transients']);
verificar('nome do cache é HMAC (com segredo), não md5 do pedido', $nome_cache !== 'cvbp_' . md5($GLOBALS['ultimo_envio']['body'] . '|usuario-teste|' . CVBP_Config::ENDPOINTS['producao'] . '|1') && strlen($nome_cache) === 5 + 64, true);

$cfg_h = ['ambiente' => 'homologacao', 'cache_horas' => 0] + $cfg;
CVBP_API::cotar($envio_api, $cfg_h);
verificar('ambiente homologação usa o outro endereço', $GLOBALS['ultimo_url'], CVBP_Config::ENDPOINTS['homologacao']);

$GLOBALS['transients'] = [];
$GLOBALS['resposta_falsa'] = ['code' => 401, 'body' => ''];
$r = CVBP_API::cotar($envio_api, $cfg);
verificar('401 vira mensagem de credencial', str_contains($r['erro'], 'Acesso negado'), true);
verificar('401 não fica em cache', count($GLOBALS['transients']), 0);

$GLOBALS['resposta_falsa'] = ['code' => 400, 'body' => '{"statusCode":400,"message":"x"}'];
CVBP_API::cotar($envio_api, $cfg);
verificar('erro 400 (dado nosso) não fica em cache', count($GLOBALS['transients']), 0);

$GLOBALS['resposta_falsa'] = ['code' => 503, 'body' => 'fora do ar'];
CVBP_API::cotar($envio_api, $cfg);
verificar('erro 503 (deles) fica guardado 5 min', count($GLOBALS['transients']), 1);
verificar('falha guardada também sem o CPF/CNPJ', str_contains(json_encode($GLOBALS['transients']), CPF_EXEMPLO), false);

$GLOBALS['transients'] = [];
$GLOBALS['resposta_falsa'] = ['code' => 302, 'body' => ''];
$r = CVBP_API::cotar($envio_api, $cfg);
verificar('redirecionamento (HTTP 302) vira erro claro', str_contains($r['erro'], 'redirecionamento'), true);
verificar('redirecionamento não fica em cache', count($GLOBALS['transients']), 0);

$antes = $GLOBALS['chamadas_http'];
verificar('sem CNPJ do remetente: nem chama a API', CVBP_API::cotar(['cnpj_remetente' => ''] + $envio_api, $cfg)['ok'] || $GLOBALS['chamadas_http'] !== $antes, false);
verificar('documento inválido: recusado antes da API', str_contains(CVBP_API::cotar(['documento' => '123'] + $envio_api, $cfg)['erro'], 'Documento'), true);
verificar('CEP curto: recusado antes da API', CVBP_API::cotar(['cep_destino' => '123'] + $envio_api, $cfg)['ok'], false);

// ---------------------------------------------------------------------------
secao('5. Preço ao cliente e validade do cache');
$regras = ['simples_pct' => 6.7, 'simples_modo' => 'dividir', 'embalagem_volume' => 3.0];
verificar('dividir: (100 + 3×2) ÷ 0,933', CVBP_Config::preco_final(100.0, 2, $regras), round(106 / 0.933, 2));
verificar('multiplicar: (100 + 3) × 1,067', CVBP_Config::preco_final(100.0, 1, ['simples_modo' => 'multiplicar'] + $regras), round(103 * 1.067, 2));
verificar('sem regras: custo puro', CVBP_Config::preco_final(83.21, 1, ['simples_pct' => 0, 'simples_modo' => 'dividir', 'embalagem_volume' => 0]), 83.21);
verificar('cache não passa da meia-noite (10 min restantes)', CVBP_Config::segundos_de_cache(['cache_horas' => 6], 1000, 1600), 600);
verificar('cache de 6 h quando sobra o dia', CVBP_Config::segundos_de_cache(['cache_horas' => 6], 0, 86000), 21600);
verificar('cache 0 h = sem cache', CVBP_Config::segundos_de_cache(['cache_horas' => 0], 0, 86000), 0);

// ---------------------------------------------------------------------------
secao('6. Exibição (ordem e âncora)');
$taxas = [
    'tex'  => ['metodo' => 'cvte_total_express', 'custo' => 30.0],
    'bp'   => ['metodo' => 'cvbp_braspress', 'custo' => 150.0],
    'ret'  => ['metodo' => 'local_pickup', 'custo' => 0.0],
    'pac'  => ['metodo' => 'correios_pac', 'custo' => 200.0],
];
verificar('sempre: mostra tudo, mais barata primeiro', CVBP_Exibicao::decidir($taxas, ['exibicao' => 'sempre', 'limite_ancora' => 3]), ['ret', 'tex', 'bp', 'pac']);
verificar('âncora 3×: esconde a Braspress (5× mais cara)', CVBP_Exibicao::decidir($taxas, ['exibicao' => 'ancora', 'limite_ancora' => 3]), ['ret', 'tex', 'pac']);
verificar('âncora 6×: mostra as duas', CVBP_Exibicao::decidir($taxas, ['exibicao' => 'ancora', 'limite_ancora' => 6]), ['ret', 'tex', 'bp', 'pac']);
verificar('mais barata: só a Total Express das nossas', CVBP_Exibicao::decidir($taxas, ['exibicao' => 'mais_barata', 'limite_ancora' => 3]), ['ret', 'tex', 'pac']);
verificar('outros plugins (PAC) nunca são escondidos', in_array('pac', CVBP_Exibicao::decidir($taxas, ['exibicao' => 'mais_barata', 'limite_ancora' => 1.1]), true), true);
$so_uma = ['bp' => ['metodo' => 'cvbp_braspress', 'custo' => 900.0], 'ret' => ['metodo' => 'local_pickup', 'custo' => 0.0]];
verificar('só uma nossa: nada é escondido', CVBP_Exibicao::decidir($so_uma, ['exibicao' => 'ancora', 'limite_ancora' => 3]), ['ret', 'bp']);
$norte = ['tex' => ['metodo' => 'cvte_total_express', 'custo' => 788.0], 'bp' => ['metodo' => 'cvbp_braspress', 'custo' => 126.0]];
verificar('Norte: Braspress primeiro, Total Express (6×) escondida', CVBP_Exibicao::decidir($norte, ['exibicao' => 'ancora', 'limite_ancora' => 3]), ['bp']);

// Cola com o WooCommerce: taxas falsas com a mesma interface de WC_Shipping_Rate.
class Taxa_Falsa { public function __construct(public string $m, public float $c) {} public function get_method_id() { return $this->m; } public function get_cost() { return $this->c; } }
function current_user_can($c) { return $GLOBALS['eh_admin'] ?? false; }
$lista = ['bp' => new Taxa_Falsa('cvbp_braspress', 150), 'tex' => new Taxa_Falsa('cvte_total_express', 30)];
$GLOBALS['opcoes'][CVBP_Config::OPCAO] = ['modo' => 'desligado'];
verificar('modo desligado: checkout intocado (mesma ordem)', array_keys(CVBP_Exibicao::filtrar($lista, [])), ['bp', 'tex']);
$GLOBALS['opcoes'][CVBP_Config::OPCAO] = ['modo' => 'sombra'];
verificar('modo sombra: checkout intocado', array_keys(CVBP_Exibicao::filtrar($lista, [])), ['bp', 'tex']);
$GLOBALS['opcoes'][CVBP_Config::OPCAO] = ['modo' => 'admin'];
verificar('modo admin, cliente comum: intocado', array_keys(CVBP_Exibicao::filtrar($lista, [])), ['bp', 'tex']);
$GLOBALS['eh_admin'] = true;
verificar('modo admin, administrador: âncora aplicada', array_keys(CVBP_Exibicao::filtrar($lista, [])), ['tex']);
$GLOBALS['opcoes'][CVBP_Config::OPCAO] = ['modo' => 'ativo', 'exibicao' => 'sempre'];
verificar('modo ativo, "sempre": reordena', array_keys(CVBP_Exibicao::filtrar($lista, [])), ['tex', 'bp']);
unset($GLOBALS['opcoes'][CVBP_Config::OPCAO]);

// ---------------------------------------------------------------------------
secao('7. Configuração');
$s = CVBP_Config::sanitizar(['cnpj_remetente' => '11.222.333/0001-81', 'cep_origem' => '41940-430', 'modo' => 'hackeado', 'limite_ancora' => '2,5', 'campos_documento' => 'billing_cpf, <script>x']);
verificar('CNPJ com pontuação é limpo e aceito', $s['cnpj_remetente'], CNPJ_EXEMPLO);
verificar('CEP com hífen é aceito', $s['cep_origem'], '41940430');
verificar('modo inválido volta ao padrão', $s['modo'], 'admin');
verificar('vírgula decimal aceita', $s['limite_ancora'], 2.5);
verificar('nomes de campos são higienizados', $s['campos_documento'], 'billing_cpf,scriptx');
verificar('CNPJ inválido é descartado', CVBP_Config::sanitizar(['cnpj_remetente' => '123'])['cnpj_remetente'], '');

$GLOBALS['opcoes'] = [];
verificar('credenciais do wp-config têm prioridade', CVBP_Config::credenciais($cfg)['origem'], 'wp-config');
$erro = CVBP_Config::salvar_credenciais('u', 'segredo');
verificar('salvar credenciais no painel funciona', $erro, '');
verificar('senha guardada criptografada (não em texto puro)', str_contains(json_encode($GLOBALS['opcoes']), 'segredo'), false);
verificar('senha volta decifrada', CVBP_Config::credenciais_salvas()['senha'], 'segredo');

// ---------------------------------------------------------------------------
printf("\n%s %d de %d verificações passaram.\n", $falhas ? '✘' : '✔', $total - $falhas, $total);
exit($falhas ? 1 : 0);
