<?php
/**
 * Plugin Name:       Cotação Direta de Transportadoras
 * Description:       Cota o frete direto nas transportadoras, com o contrato da própria loja e sem
 *                    intermediário (dispensa a Frenet): Total Express (webservice) e Braspress (API REST),
 *                    dividindo o pedido em volumes.
 * Version:           0.3.3
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Marcus Lutz
 * Text Domain:       cotacao-direta-transportadoras
 * License:           GPL-2.0-or-later
 *
 * WC requires at least: 8.0
 *
 * Projeto independente, sem vínculo com a Total Express, a Braspress ou a Frenet.
 *
 * ---------------------------------------------------------------------------
 * COMO O PLUGIN ESTÁ ORGANIZADO
 * ---------------------------------------------------------------------------
 *   cotacao-direta-transportadoras.php   este arquivo: carrega as peças e registra o método
 *   includes/class-cvte-config.php       lê e valida as configurações
 *   includes/class-cvte-api.php          conversa com o webservice (SOAP)
 *   includes/class-cvte-empacotador.php  divide o carrinho em volumes
 *   includes/class-cvte-metodo.php       o método de entrega do WooCommerce
 *   includes/class-cvte-admin.php        a tela de configuração e de teste
 *
 *   includes/braspress/                  a Braspress (desde a 0.3.0), prefixo CVBP_:
 *     class-cvbp-config.php              regras, credenciais e preço ao cliente
 *     class-cvbp-documento.php           CPF/CNPJ do destinatário (muda o preço)
 *     class-cvbp-api.php                 conversa com a API REST (JSON)
 *     class-cvbp-metodo.php              o método de entrega "Braspress"
 *     class-cvbp-exibicao.php            ordem e âncora entre Total Express e Braspress
 *     class-cvbp-admin.php               a tela WooCommerce → Braspress
 *
 * A parte da Total Express não depende da Braspress: as classes CVTE_ não
 * foram alteradas. A Braspress reaproveita o empacotador e a criptografia.
 *
 * Cada classe faz uma coisa só. Assim dá para testar o empacotador sem
 * internet, e trocar a API sem mexer no cálculo do preço.
 */

if (!defined('ABSPATH')) {
    exit; // Acesso direto ao arquivo pelo navegador: não faz nada.
}

/*
 * CONVIVÊNCIA COM A VERSÃO ANTIGA (0.2.0, pasta cotacao-volumes-total-express)
 *
 * As duas usam as mesmas classes e constantes. Com as duas ativas, a que
 * carregasse primeiro "ganhava" o caminho dos arquivos, e esta procurava os
 * arquivos da Braspress na pasta da antiga: erro fatal e site fora do ar
 * (aconteceu em produção em 17/11/2026). Agora, enquanto a antiga estiver
 * ativa, esta não carrega nada: só mostra um aviso no painel, e a antiga
 * segue funcionando sozinha. Na ativação, esta se recusa a ativar e explica.
 */
$cvte_antiga = 'cotacao-volumes-total-express/cotacao-volumes-total-express.php';

register_activation_hook(__FILE__, static function () use ($cvte_antiga) {
    if (in_array($cvte_antiga, (array) get_option('active_plugins', []), true)) {
        wp_die(
            '<p><strong>Cotação Direta de Transportadoras não foi ativada.</strong></p>'
            . '<p>A versão antiga, "Cotação por Volumes para Total Express", ainda está ativa. Desative-a primeiro '
            . '(<strong>não use "Excluir"</strong>, que apagaria as configurações e a senha da Total Express) e depois ative esta.</p>',
            'Desative a versão antiga primeiro',
            ['back_link' => true]
        );
    }
});

if (defined('CVTE_VERSAO') || in_array($cvte_antiga, (array) get_option('active_plugins', []), true)) {
    add_action('admin_notices', static function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p><strong>Cotação Direta de Transportadoras está em pausa:</strong> '
                . 'a versão antiga, "Cotação por Volumes para Total Express", ainda está ativa, e só ela está funcionando. '
                . 'Desative a antiga em Plugins (<strong>não use "Excluir"</strong>, que apagaria as configurações e a senha) '
                . 'para esta assumir com as mesmas configurações.</p></div>';
        }
    });
    unset($cvte_antiga);
    return; // não define constantes nem carrega classes: a antiga segue sozinha
}
unset($cvte_antiga);

define('CVTE_VERSAO', '0.3.3');
define('CVTE_DIR', plugin_dir_path(__FILE__));

/*
 * HPOS (tabelas novas de pedidos do WooCommerce): este plugin não lê nem grava
 * pedidos, então é compatível. Declarar evita o aviso amarelo no painel.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/*
 * As classes só são carregadas depois que todos os plugins subiram, porque o
 * método de entrega herda de WC_Shipping_Method — que só existe se o
 * WooCommerce estiver ativo.
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return; // Sem WooCommerce, o plugin fica quieto em vez de quebrar o site.
    }

    require_once CVTE_DIR . 'includes/class-cvte-config.php';
    require_once CVTE_DIR . 'includes/class-cvte-api.php';
    require_once CVTE_DIR . 'includes/class-cvte-empacotador.php';
    require_once CVTE_DIR . 'includes/class-cvte-metodo.php';

    // Braspress
    require_once CVTE_DIR . 'includes/braspress/class-cvbp-config.php';
    require_once CVTE_DIR . 'includes/braspress/class-cvbp-documento.php';
    require_once CVTE_DIR . 'includes/braspress/class-cvbp-api.php';
    require_once CVTE_DIR . 'includes/braspress/class-cvbp-metodo.php';
    require_once CVTE_DIR . 'includes/braspress/class-cvbp-exibicao.php';

    // Registra os métodos na lista de "métodos de entrega" das áreas de entrega.
    add_filter('woocommerce_shipping_methods', function ($metodos) {
        $metodos['cvte_total_express'] = 'CVTE_Metodo';
        $metodos['cvbp_braspress']     = 'CVBP_Metodo';
        return $metodos;
    });

    // Braspress: o CPF/CNPJ entra no pacote (o WooCommerce recota quando ele muda)...
    add_filter('woocommerce_cart_shipping_packages', [CVBP_Documento::class, 'no_pacote']);
    // ...e sair do campo no checkout dispara a atualização do resumo.
    add_action('wp_enqueue_scripts', [CVBP_Documento::class, 'script_checkout'], 20);
    // Ordem das opções e âncora de preço entre Total Express e Braspress.
    add_filter('woocommerce_package_rates', [CVBP_Exibicao::class, 'filtrar'], 100, 2);

    if (is_admin()) {
        require_once CVTE_DIR . 'includes/class-cvte-admin.php';
        require_once CVTE_DIR . 'includes/braspress/class-cvbp-admin.php';
        CVTE_Admin::iniciar();
        CVBP_Admin::iniciar();
    }
}, 20);
