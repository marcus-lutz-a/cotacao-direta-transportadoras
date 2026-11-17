<?php
/**
 * Braspress — configurações, credenciais e cálculo do preço ao cliente.
 *
 * Mesma filosofia da parte da Total Express (CVTE_Config):
 *   - uma única opção do WordPress ("cvbp_config") com as regras;
 *   - credenciais fora dela: no wp-config.php ou criptografadas numa opção
 *     própria ("cvbp_credenciais"), reaproveitando a criptografia de CVTE_Config;
 *   - funções puras (sem banco e sem rede) para tudo que dá para testar.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVBP_Config
{
    const OPCAO = 'cvbp_config';
    const OPCAO_CREDENCIAIS = 'cvbp_credenciais';

    /**
     * Endereços da cotação, conforme o manual público (api.braspress.com/home).
     * O de homologação ("hml-api") aparece na documentação, mas a Braspress
     * ainda precisa confirmar se é ele que acompanha as credenciais de teste.
     */
    const ENDPOINTS = [
        'producao'    => 'https://api.braspress.com/v1/cotacao/calcular/json',
        'homologacao' => 'https://hml-api.braspress.com/v1/cotacao/calcular/json',
    ];

    /** Valores de fábrica. Os comentários documentam as regras de negócio. */
    public static function padroes(): array
    {
        return [
            // desligado | admin | sombra | ativo — igual à Total Express.
            'modo'                  => 'admin',

            // producao | homologacao (credenciais diferentes em cada um).
            'ambiente'              => 'producao',

            // CNPJ de quem envia (cadastrado na Braspress) e CEP de coleta.
            'cnpj_remetente'        => '',
            'cep_origem'            => '',

            // R = rodoviário, A = aéreo (só se estiver no contrato).
            'modal'                 => 'R',

            // Quem paga o frete: 1 = CIF (a loja), 2 = FOB, 3 = consignado.
            'tipo_frete'            => 1,

            /*
             * O preço da Braspress depende de o destinatário ser pessoa física
             * (CPF) ou jurídica (CNPJ). No carrinho e na página do produto o
             * documento ainda é desconhecido. O que fazer nesse caso:
             *   cnpj_loja      → (padrão desde a 0.3.3) cota com o CNPJ da
             *                     própria loja como destinatário. A API exige um
             *                     documento e aceita este; nos testes de 17/11 o
             *                     preço foi igual ao de CPF (Manaus: maior, ou
             *                     seja, a estimativa nunca fica abaixo). Assim a
             *                     Braspress aparece na página do produto e no
             *                     carrinho; no checkout, com o CPF/CNPJ digitado,
             *                     a cotação é refeita com o documento real;
             *   ocultar        → a Braspress só aparece quando há documento
             *                     (no checkout, ou cliente logado com CPF/CNPJ salvo);
             *   cpf_referencia → cota com o "CPF de referência" abaixo, para
             *                     mostrar já no carrinho o preço de pessoa física
             *                     (o cenário mais caro, nunca subestima).
             */
            'documento_desconhecido' => 'cnpj_loja',
            'cpf_referencia'        => '',

            // Campos do checkout onde procurar o CPF/CNPJ, em ordem.
            // Os dois primeiros são os do "Brazilian Market on WooCommerce".
            'campos_documento'      => 'billing_cpf,billing_cnpj,billing_document,billing_cpf_cnpj',

            // Regras de preço (mesmas da Total Express, mas independentes).
            'simples_pct'           => 0,
            'simples_modo'          => 'dividir',
            'embalagem_volume'      => 0,
            'prazo_adicional'       => 0,

            // Montagem dos volumes. A Braspress não tem o teto de 30 kg da
            // Total Express; o limite aqui é o da caixa que a loja usa.
            'peso_max_volume'       => 30.0,
            'dim_max'               => [150, 100, 100],
            'dim_min'               => [20, 16, 10],
            'folga_caixa_pct'       => 15,
            'peso_padrao'           => 1.0,

            // A cotação da Braspress vale até 23:59:59 do dia. O cache usa o
            // menor entre estas horas e o tempo que falta para a meia-noite.
            'cache_horas'           => 6,
            'timeout'               => 8,

            /*
             * Como mostrar as opções no checkout (vale para Total Express e
             * Braspress juntas — ver CVBP_Exibicao):
             *   sempre      → mostra as duas, a mais barata primeiro;
             *   ancora      → mostra as duas, mas esconde a que custar mais de
             *                 "limite_ancora" vezes a mais barata;
             *   mais_barata → só a mais barata das duas.
             */
            'exibicao'              => 'sempre',
            'limite_ancora'         => 3.0,

            'titulo'                => 'Braspress',
        ];
    }

    public static function obter(): array
    {
        $salvo = get_option(self::OPCAO, []);
        return array_merge(self::padroes(), is_array($salvo) ? $salvo : []);
    }

    /** Valida o formulário. Tudo que vem do navegador é suspeito. */
    public static function sanitizar(array $bruto): array
    {
        $p = self::padroes();
        $num = static function ($valor, $padrao, $min, $max) {
            $valor = is_string($valor) ? str_replace(',', '.', $valor) : $valor;
            return is_numeric($valor) ? max($min, min($max, (float) $valor)) : $padrao;
        };
        $lista = static fn($valor, array $opcoes, $padrao) => in_array($valor, $opcoes, true) ? $valor : $padrao;

        $cnpj = CVBP_Documento::limpar((string) ($bruto['cnpj_remetente'] ?? ''));
        $cep  = CVBP_Documento::limpar((string) ($bruto['cep_origem'] ?? ''));
        $cpf  = CVBP_Documento::limpar((string) ($bruto['cpf_referencia'] ?? ''));
        $campos = implode(',', array_filter(array_map(
            static fn($c) => preg_replace('/[^a-z0-9_]/', '', strtolower(trim($c))),
            explode(',', (string) ($bruto['campos_documento'] ?? $p['campos_documento']))
        )));

        return [
            'modo'                   => $lista($bruto['modo'] ?? '', ['desligado', 'admin', 'sombra', 'ativo'], $p['modo']),
            'ambiente'               => $lista($bruto['ambiente'] ?? '', array_keys(self::ENDPOINTS), $p['ambiente']),
            'cnpj_remetente'         => CVBP_Documento::cnpj_valido($cnpj) ? $cnpj : '',
            'cep_origem'             => strlen($cep) === 8 ? $cep : '',
            'modal'                  => $lista($bruto['modal'] ?? '', ['R', 'A'], $p['modal']),
            'tipo_frete'             => (int) $lista((int) ($bruto['tipo_frete'] ?? 0), [1, 2, 3], $p['tipo_frete']),
            'documento_desconhecido' => $lista($bruto['documento_desconhecido'] ?? '', ['cnpj_loja', 'ocultar', 'cpf_referencia'], $p['documento_desconhecido']),
            'cpf_referencia'         => CVBP_Documento::cpf_valido($cpf) ? $cpf : '',
            'campos_documento'       => $campos !== '' ? $campos : $p['campos_documento'],
            'simples_pct'            => $num($bruto['simples_pct'] ?? null, $p['simples_pct'], 0, 30),
            'simples_modo'           => $lista($bruto['simples_modo'] ?? '', ['dividir', 'multiplicar'], $p['simples_modo']),
            'embalagem_volume'       => $num($bruto['embalagem_volume'] ?? null, $p['embalagem_volume'], 0, 100),
            'prazo_adicional'        => (int) $num($bruto['prazo_adicional'] ?? null, $p['prazo_adicional'], 0, 30),
            'peso_max_volume'        => $num($bruto['peso_max_volume'] ?? null, $p['peso_max_volume'], 1, 200),
            'dim_max'                => $p['dim_max'],
            'dim_min'                => [
                (int) $num($bruto['dim_min_0'] ?? null, $p['dim_min'][0], 1, 150),
                (int) $num($bruto['dim_min_1'] ?? null, $p['dim_min'][1], 1, 100),
                (int) $num($bruto['dim_min_2'] ?? null, $p['dim_min'][2], 1, 100),
            ],
            'folga_caixa_pct'        => $num($bruto['folga_caixa_pct'] ?? null, $p['folga_caixa_pct'], 0, 100),
            'peso_padrao'            => $num($bruto['peso_padrao'] ?? null, $p['peso_padrao'], 0.01, 200),
            'cache_horas'            => (int) $num($bruto['cache_horas'] ?? null, $p['cache_horas'], 0, 24),
            'timeout'                => (int) $num($bruto['timeout'] ?? null, $p['timeout'], 2, 20),
            'exibicao'               => $lista($bruto['exibicao'] ?? '', ['sempre', 'ancora', 'mais_barata'], $p['exibicao']),
            'limite_ancora'          => $num($bruto['limite_ancora'] ?? null, $p['limite_ancora'], 1.1, 20),
            'titulo'                 => sanitize_text_field($bruto['titulo'] ?? $p['titulo']) ?: $p['titulo'],
        ];
    }

    /* ------------------------------------------------------------------------
     * Credenciais
     * --------------------------------------------------------------------- */

    /**
     * Ordem: constantes CVBP_USUARIO / CVBP_SENHA no wp-config.php, depois a
     * tela do plugin (senha criptografada com a mesma chave da Total Express).
     *
     * @return array usuario, senha, endpoint, origem ('wp-config'|'painel'|'ilegivel'|'nenhuma')
     */
    public static function credenciais(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::obter();
        $endpoint = defined('CVBP_ENDPOINT') ? (string) CVBP_ENDPOINT : self::ENDPOINTS[$cfg['ambiente']] ?? self::ENDPOINTS['producao'];

        if (defined('CVBP_USUARIO') && defined('CVBP_SENHA')) {
            return ['usuario' => (string) CVBP_USUARIO, 'senha' => (string) CVBP_SENHA, 'endpoint' => $endpoint, 'origem' => 'wp-config'];
        }
        return self::credenciais_salvas() + ['endpoint' => $endpoint];
    }

    public static function credenciais_salvas(): array
    {
        $salvo = get_option(self::OPCAO_CREDENCIAIS, []);
        if (!is_array($salvo) || empty($salvo['usuario']) || empty($salvo['senha'])) {
            return ['usuario' => '', 'senha' => '', 'origem' => 'nenhuma'];
        }
        $senha = CVTE_Config::decifrar((string) $salvo['senha']);
        if ($senha === null) {
            return ['usuario' => (string) $salvo['usuario'], 'senha' => '', 'origem' => 'ilegivel'];
        }
        return ['usuario' => (string) $salvo['usuario'], 'senha' => $senha, 'origem' => 'painel'];
    }

    /** @return string vazio se deu certo; senão, a explicação. */
    public static function salvar_credenciais(string $usuario, string $senha): string
    {
        $usuario = trim($usuario);
        if ($usuario === '') {
            return 'Informe o usuário da API Braspress.';
        }
        if (CVTE_Config::chave() === null) {
            return 'As chaves secretas do WordPress (AUTH_KEY e SECURE_AUTH_KEY) não estão definidas. Sem elas a senha não pode ser guardada com segurança.';
        }
        $atual = get_option(self::OPCAO_CREDENCIAIS, []);
        if ($senha === '') {
            if (!is_array($atual) || empty($atual['senha'])) {
                return 'Informe a senha da API Braspress.';
            }
            $cifrada = (string) $atual['senha'];
        } else {
            $cifrada = CVTE_Config::cifrar($senha);
        }
        update_option(self::OPCAO_CREDENCIAIS, ['usuario' => $usuario, 'senha' => $cifrada], false);
        return '';
    }

    public static function apagar_credenciais(): void
    {
        delete_option(self::OPCAO_CREDENCIAIS);
    }

    /* ------------------------------------------------------------------------
     * Funções puras
     * --------------------------------------------------------------------- */

    /**
     * Preço ao cliente a partir do custo da Braspress.
     *
     * Diferença para a Total Express: a Braspress devolve UM valor para o
     * pedido inteiro, então a embalagem é multiplicada pelo nº de volumes
     * que nós mesmos montamos.
     *
     *   base  = custo + embalagem × volumes
     *   final = base ÷ (1 − Simples)  [dividir]   |   base × (1 + Simples)  [multiplicar]
     */
    public static function preco_final(float $custo, int $volumes, array $cfg): float
    {
        $base = $custo + (float) $cfg['embalagem_volume'] * max(1, $volumes);
        $taxa = (float) $cfg['simples_pct'] / 100;
        $final = $cfg['simples_modo'] === 'multiplicar' ? $base * (1 + $taxa) : ($taxa < 1 ? $base / (1 - $taxa) : $base);
        return round($final, 2);
    }

    /**
     * Por quantos segundos guardar uma cotação: o menor entre "cache_horas"
     * e o tempo que falta até 23:59:59 (validade informada pela Braspress).
     *
     * @param int $agora     timestamp atual
     * @param int $meia_noite timestamp de 23:59:59 de hoje no fuso da loja
     */
    public static function segundos_de_cache(array $cfg, int $agora, int $meia_noite): int
    {
        $horas = (int) $cfg['cache_horas'] * 3600;
        return max(0, min($horas, $meia_noite - $agora));
    }
}
