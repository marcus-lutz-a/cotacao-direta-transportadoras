<?php
/**
 * Conversa com a API REST da Braspress (cotação).
 *
 * ---------------------------------------------------------------------------
 * REST + JSON, EM UMA FRASE
 * ---------------------------------------------------------------------------
 * Diferente do SOAP da Total Express, aqui a pergunta é um objeto JSON simples
 * enviado por POST, e a resposta é outro JSON. Autenticação HTTP Basic.
 *
 *   POST {endpoint}            (…/v1/cotacao/calcular/json)
 *   {
 *     "cnpjRemetente": "…", "cnpjDestinatario": "CPF ou CNPJ",
 *     "modal": "R", "tipoFrete": "1",
 *     "cepOrigem": "41940430", "cepDestino": "…",
 *     "vlrMercadoria": 250.00, "peso": 20.5, "volumes": 1,
 *     "cubagem": [ { "altura": 0.30, "largura": 0.40, "comprimento": 0.50, "volumes": 1 } ]
 *   }
 *   resposta: { "id": …, "prazo": 5, "totalFrete": 83.21 }
 *   erro:     { "statusCode": …, "message": "…", "dateTime": "…", "errorList": [ … ] }
 *
 * Diferença importante: a Braspress cota o PEDIDO INTEIRO de uma vez (com a
 * lista de caixas em "cubagem"); a Total Express cota volume por volume.
 *
 * Pontos a confirmar na homologação (o manual é ambíguo):
 *   - CNPJ/CEP como texto (preserva zeros à esquerda) ou número;
 *   - se "totalFrete" já inclui ICMS e todas as taxas da proposta.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVBP_API
{
    const OPCAO_VERSAO_CACHE = 'cvbp_cache_versao';

    /**
     * Monta o corpo da requisição. Função pura.
     *
     * @param array $e [cnpj_remetente, documento, cep_origem, cep_destino, valor,
     *                  volumes => [ ['peso' kg, 'dims' => [c,l,a] cm] ], modal, tipo_frete]
     */
    public static function montar_corpo(array $e): array
    {
        // Caixas iguais viram uma linha só, com "volumes" = quantidade (como pede o manual).
        $grupos = [];
        foreach ($e['volumes'] as $v) {
            $d = CVTE_Empacotador::ordenar($v['dims']); // maior → menor, em cm
            $chave = implode('x', $d);
            $grupos[$chave] = $grupos[$chave] ?? [
                'altura'      => round($d[2] / 100, 3), // cm → metros
                'largura'     => round($d[1] / 100, 3),
                'comprimento' => round($d[0] / 100, 3),
                'volumes'     => 0,
            ];
            $grupos[$chave]['volumes']++;
        }

        return [
            'cnpjRemetente'    => (string) $e['cnpj_remetente'],
            'cnpjDestinatario' => (string) $e['documento'],
            'modal'            => (string) $e['modal'],
            'tipoFrete'        => (string) $e['tipo_frete'],
            'cepOrigem'        => (string) $e['cep_origem'],
            'cepDestino'       => (string) $e['cep_destino'],
            'vlrMercadoria'    => round(max((float) $e['valor'], 1), 2),
            'peso'             => round(array_sum(array_column($e['volumes'], 'peso')), 3),
            'volumes'          => count($e['volumes']),
            'cubagem'          => array_values($grupos),
        ];
    }

    /**
     * Cota um pedido.
     *
     * @param bool $guardar_falhas guarda a falha por 5 min (falso no teste do painel).
     * @return array { ok, valor, prazo, id, erro, cache, enviado (JSON), recebido (texto) }
     */
    public static function cotar(array $envio, array $cfg, bool $guardar_falhas = true): array
    {
        foreach (['cep_origem' => 8, 'cep_destino' => 8] as $campo => $tam) {
            if (strlen((string) ($envio[$campo] ?? '')) !== $tam) {
                return self::falha("Campo $campo inválido.");
            }
        }
        if (!CVBP_Documento::cnpj_valido((string) ($envio['cnpj_remetente'] ?? ''))) {
            return self::falha('CNPJ do remetente não configurado (WooCommerce → Braspress).');
        }
        if (!CVBP_Documento::classificar((string) ($envio['documento'] ?? ''))) {
            return self::falha('Documento do destinatário ausente ou inválido.');
        }
        if (empty($envio['volumes'])) {
            return self::falha('Nenhum volume para cotar.');
        }

        $cred = CVBP_Config::credenciais($cfg);
        if ($cred['usuario'] === '' || $cred['senha'] === '') {
            return self::falha($cred['origem'] === 'ilegivel'
                ? 'A senha salva não pôde ser lida (as chaves secretas do WordPress mudaram). Digite-a de novo em WooCommerce → Braspress.'
                : 'Credenciais não configuradas (WooCommerce → Braspress, ou CVBP_USUARIO / CVBP_SENHA no wp-config.php).');
        }

        if (!CVTE_API::endpoint_seguro($cred['endpoint'])) {
            return self::falha('O endereço da API precisa começar com https:// (confira CVBP_ENDPOINT no wp-config.php). A senha não foi enviada.');
        }

        $corpo = self::montar_corpo($envio + ['modal' => $cfg['modal'], 'tipo_frete' => $cfg['tipo_frete']]);
        $json = wp_json_encode($corpo);

        // Cache: mesma pergunta, mesma resposta. Usuário e endpoint na chave para
        // que trocar credencial ou ambiente invalide tudo. O que vai para o cache
        // passa por CVTE_API::para_cache(): sem o JSON enviado, que tem o CPF/CNPJ
        // do cliente (LGPD: o banco não guarda o documento por causa do frete).
        $versao = (int) get_option(self::OPCAO_VERSAO_CACHE, 1);
        $chave = 'cvbp_' . md5($json . '|' . $cred['usuario'] . '|' . $cred['endpoint'] . '|' . $versao);
        $ttl = CVBP_Config::segundos_de_cache($cfg, time(), self::meia_noite());
        if ($ttl > 0) {
            $guardado = get_transient($chave);
            if (is_array($guardado)) {
                $guardado['cache'] = true;
                return $guardado;
            }
        }

        // Travas de segurança (sem redirecionamento, TLS conferido, resposta limitada):
        // ver CVTE_API::opcoes_http().
        $resposta = wp_remote_post($cred['endpoint'], CVTE_API::opcoes_http((int) $cfg['timeout'], [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($cred['usuario'] . ':' . $cred['senha']),
        ], $json));

        if (is_wp_error($resposta)) {
            $r = self::falha('Falha de rede: ' . $resposta->get_error_message(), $json);
            if ($guardar_falhas) {
                set_transient($chave, CVTE_API::para_cache($r), 5 * MINUTE_IN_SECONDS);
            }
            return $r;
        }

        $http = (int) wp_remote_retrieve_response_code($resposta);
        $texto = (string) wp_remote_retrieve_body($resposta);

        if ($http === 401 || $http === 403) {
            return self::falha("Acesso negado pela Braspress (HTTP $http). Confira usuário, senha e ambiente (produção × homologação).", $json, $texto);
        }
        if ($http >= 300 && $http < 400) {
            return self::falha("A Braspress respondeu com um redirecionamento (HTTP $http). Por segurança ele não é seguido; confira o endereço da API.", $json, $texto);
        }

        $r = self::interpretar($texto, $http);
        $r['enviado'] = $json;
        $r['recebido'] = $texto;

        if ($r['ok'] && $ttl > 0) {
            set_transient($chave, CVTE_API::para_cache($r), $ttl);
        } elseif (!$r['ok'] && $guardar_falhas && $http >= 500) {
            // Só erro do lado deles (5xx) fica guardado: um 400 por dado nosso
            // errado não deve esconder a Braspress depois que o dado for corrigido.
            set_transient($chave, CVTE_API::para_cache($r), 5 * MINUTE_IN_SECONDS);
        }
        return $r;
    }

    /**
     * Lê a resposta. Tolerante: aceita números como número ou texto
     * ("83.21", "83,21"), e o formato de erro documentado.
     */
    public static function interpretar(string $texto, int $http = 200): array
    {
        $dados = json_decode($texto, true);
        if (!is_array($dados)) {
            return self::falha($http === 200 ? 'Resposta não é JSON.' : "Resposta inesperada (HTTP $http).");
        }

        // Algumas bibliotecas recebem a cotação dentro de uma lista ou de uma chave por modal.
        if (isset($dados[0]) && is_array($dados[0])) {
            $dados = $dados[0];
        }

        if (isset($dados['totalFrete'])) {
            $valor = is_numeric($dados['totalFrete']) ? (float) $dados['totalFrete'] : CVTE_API::para_numero((string) $dados['totalFrete']);
            if ($valor <= 0) {
                return self::falha('Resposta com frete zerado.');
            }
            return [
                'ok' => true, 'valor' => round($valor, 2),
                'prazo' => (int) preg_replace('/\D/', '', (string) ($dados['prazo'] ?? '0')),
                'id' => (string) ($dados['id'] ?? ''), 'erro' => '', 'cache' => false,
            ];
        }

        $mensagens = [];
        if (!empty($dados['message'])) {
            $mensagens[] = (string) $dados['message'];
        }
        foreach ((array) ($dados['errorList'] ?? []) as $item) {
            $mensagens[] = is_array($item) ? (string) ($item['message'] ?? wp_json_encode($item)) : (string) $item;
        }
        $codigo = isset($dados['statusCode']) ? ' (código ' . $dados['statusCode'] . ')' : '';
        return self::falha('Braspress recusou' . $codigo . ($mensagens ? ': ' . implode(' | ', array_unique($mensagens)) : '.'));
    }

    /** Timestamp de 23:59:59 de hoje, no fuso configurado no WordPress. */
    private static function meia_noite(): int
    {
        $fuso = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Bahia');
        return (new DateTimeImmutable('today 23:59:59', $fuso))->getTimestamp();
    }

    private static function falha(string $erro, string $enviado = '', string $recebido = ''): array
    {
        return ['ok' => false, 'valor' => 0.0, 'prazo' => 0, 'id' => '', 'erro' => $erro, 'cache' => false, 'enviado' => $enviado, 'recebido' => $recebido];
    }
}
