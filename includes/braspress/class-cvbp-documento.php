<?php
/**
 * CPF e CNPJ do destinatário: validação e descoberta no checkout.
 *
 * ---------------------------------------------------------------------------
 * POR QUE O DOCUMENTO IMPORTA
 * ---------------------------------------------------------------------------
 * A Braspress cobra diferente para pessoa física (CPF) e jurídica (CNPJ): a
 * entrega em domicílio de pessoa física pode ter taxa extra (TAD). A API
 * recebe o documento no campo "cnpjDestinatario" e já devolve o preço certo.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA DO CACHE DO WOOCOMMERCE
 * ---------------------------------------------------------------------------
 * O WooCommerce guarda as cotações na sessão do cliente e só recalcula quando
 * o "pacote" muda (itens, CEP...). O CPF não faz parte do pacote: digitá-lo
 * não provocaria nova cotação. A solução é o filtro
 * woocommerce_cart_shipping_packages (ver no_pacote()), que acrescenta o
 * documento ao pacote. Pacote diferente → WooCommerce recota.
 *
 * As funções de validação são puras e testadas em tests/testes-braspress.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVBP_Documento
{
    /** Só os dígitos. */
    public static function limpar(string $texto): string
    {
        return preg_replace('/\D/', '', $texto);
    }

    /**
     * CPF válido pelos dígitos verificadores.
     *
     * Os dois últimos dígitos são calculados a partir dos 9 primeiros (pesos
     * 10..2 e depois 11..2, resto da divisão por 11). Sequências repetidas
     * (111.111.111-11) passam na conta, mas não existem: são recusadas.
     */
    public static function cpf_valido(string $cpf): bool
    {
        $cpf = self::limpar($cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    /** CNPJ válido pelos dígitos verificadores (pesos 5..2,9..2 e 6..2,9..2). */
    public static function cnpj_valido(string $cnpj): bool
    {
        $cnpj = self::limpar($cnpj);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12, 13] as $t) {
            $pesos = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cnpj[$i] * $pesos[$i];
            }
            $resto = $soma % 11;
            $dv = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $cnpj[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    /**
     * Classifica um texto: ['numero' => dígitos, 'tipo' => 'PF'|'PJ'] ou null.
     */
    public static function classificar(string $texto): ?array
    {
        $d = self::limpar($texto);
        if (self::cpf_valido($d)) {
            return ['numero' => $d, 'tipo' => 'PF'];
        }
        if (self::cnpj_valido($d)) {
            return ['numero' => $d, 'tipo' => 'PJ'];
        }
        return null;
    }

    /**
     * Escolhe o primeiro documento válido entre vários campos.
     *
     * @param array  $dados  campos do formulário (nome => valor)
     * @param string $campos nomes separados por vírgula, em ordem de preferência
     * @param string $tipo_pessoa valor de "billing_persontype" (1 = PF, 2 = PJ), quando houver:
     *        com ele, o campo do tipo certo vem primeiro (quem escolhe "empresa"
     *        pode ter um CPF antigo esquecido no outro campo).
     */
    public static function escolher(array $dados, string $campos, string $tipo_pessoa = ''): ?array
    {
        $nomes = array_filter(array_map('trim', explode(',', $campos)));
        if ($tipo_pessoa === '2') {
            usort($nomes, static fn($a, $b) => (int) !str_contains($a, 'cnpj') <=> (int) !str_contains($b, 'cnpj'));
        }
        foreach ($nomes as $nome) {
            if (!empty($dados[$nome]) && is_scalar($dados[$nome])) {
                $doc = self::classificar((string) $dados[$nome]);
                if ($doc) {
                    return $doc;
                }
            }
        }
        return null;
    }

    /**
     * Qual documento usar na cotação. Função pura.
     *
     * @param array|null $do_cliente documento do cliente (do pacote), ou null
     * @param array      $cfg        documento_desconhecido, cpf_referencia, cnpj_remetente
     * @return array|null ['numero', 'tipo', 'estimado' => bool], ou null = não cotar
     */
    public static function para_cotacao(?array $do_cliente, array $cfg): ?array
    {
        if ($do_cliente && !empty($do_cliente['numero'])) {
            return $do_cliente + ['estimado' => false];
        }
        $modo = $cfg['documento_desconhecido'] ?? 'ocultar';
        if ($modo === 'cnpj_loja' && self::cnpj_valido((string) ($cfg['cnpj_remetente'] ?? ''))) {
            return ['numero' => self::limpar($cfg['cnpj_remetente']), 'tipo' => 'PJ', 'estimado' => true];
        }
        if ($modo === 'cpf_referencia' && self::cpf_valido((string) ($cfg['cpf_referencia'] ?? ''))) {
            return ['numero' => self::limpar($cfg['cpf_referencia']), 'tipo' => 'PF', 'estimado' => true];
        }
        return null;
    }

    /* ------------------------------------------------------------------------
     * Integração com o WooCommerce
     * --------------------------------------------------------------------- */

    /**
     * Procura o documento do cliente, nesta ordem:
     *   1. o formulário do checkout (o WooCommerce envia todos os campos em
     *      "post_data" a cada atualização do resumo do pedido);
     *   2. o envio final do pedido ($_POST direto);
     *   3. o cadastro do cliente logado (user meta).
     */
    public static function do_cliente(array $cfg): ?array
    {
        $campos = (string) $cfg['campos_documento'];

        // phpcs:disable WordPress.Security.NonceVerification -- só leitura de dígitos para cotação; o WooCommerce valida o nonce do checkout
        if (!empty($_POST['post_data']) && is_string($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $form);
            $doc = self::escolher((array) $form, $campos, (string) ($form['billing_persontype'] ?? ''));
            if ($doc) {
                return $doc;
            }
        }
        if (!empty($_POST)) {
            $doc = self::escolher(wp_unslash($_POST), $campos, (string) ($_POST['billing_persontype'] ?? ''));
            if ($doc) {
                return $doc;
            }
        }
        // phpcs:enable

        $usuario = get_current_user_id();
        if ($usuario) {
            $meta = [];
            foreach (explode(',', $campos) as $nome) {
                $meta[trim($nome)] = (string) get_user_meta($usuario, trim($nome), true);
            }
            return self::escolher($meta, $campos, (string) get_user_meta($usuario, 'billing_persontype', true));
        }
        return null;
    }

    /**
     * Filtro woocommerce_cart_shipping_packages: coloca o documento no pacote.
     * Assim, trocar o CPF por um CNPJ no checkout faz o WooCommerce recotar.
     */
    public static function no_pacote(array $pacotes): array
    {
        $cfg = CVBP_Config::obter();
        if ($cfg['modo'] === 'desligado') {
            return $pacotes;
        }
        $doc = self::do_cliente($cfg);
        foreach ($pacotes as &$pacote) {
            $pacote['cvbp_documento'] = $doc; // null = ainda desconhecido
        }
        unset($pacote);
        return $pacotes;
    }

    /**
     * No checkout, sair do campo de CPF/CNPJ dispara a atualização do resumo
     * (e, com ela, a nova cotação). O WooCommerce só faz isso sozinho para os
     * campos de endereço.
     */
    public static function script_checkout(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || CVBP_Config::obter()['modo'] === 'desligado') {
            return;
        }
        $seletor = implode(',', array_map(
            static fn($c) => '[name="' . esc_attr(trim($c)) . '"]',
            array_merge(explode(',', CVBP_Config::obter()['campos_documento']), ['billing_persontype'])
        ));
        wp_add_inline_script('wc-checkout', sprintf(
            'jQuery(function($){$(document.body).on("change",%s,function(){$(document.body).trigger("update_checkout");});});',
            wp_json_encode($seletor)
        ));
    }
}
