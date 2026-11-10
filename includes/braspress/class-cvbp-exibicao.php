<?php
/**
 * Como as opções de frete aparecem juntas no checkout.
 *
 * ---------------------------------------------------------------------------
 * A IDEIA (decisão de 10/11/2026)
 * ---------------------------------------------------------------------------
 * Mostrar uma opção mais cara ao lado da mais barata funciona como ÂNCORA de
 * preço: a barata parece um bom negócio. Mas uma âncora absurda (R$ 30 ao lado
 * de R$ 600) parece erro ou "loja cara". Por isso:
 *
 *   1. a mais barata vem PRIMEIRO (o WooCommerce marca a primeira opção, e o
 *      cliente não paga mais por distração);
 *   2. no modo "ancora", uma opção NOSSA (Total Express ou Braspress) que
 *      custe mais de N vezes a mais barata é escondida;
 *   3. no modo "mais_barata", das nossas só fica a mais barata.
 *
 * Opções de outros plugins (retirada na loja, Correios...) nunca são
 * escondidas por este arquivo; elas só entram na ordenação. Frete grátis e
 * retirada (custo 0) não servem de referência para a âncora.
 *
 * decidir() é pura e testada; filtrar() é a cola com o WooCommerce.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVBP_Exibicao
{
    /** Métodos que este plugin controla. */
    const NOSSOS = ['cvte_total_express', 'cvbp_braspress'];

    /**
     * @param array $taxas [id => ['metodo' => method_id, 'custo' => float]]
     * @param array $cfg   exibicao, limite_ancora
     * @return array ids que ficam, já na ordem de exibição
     */
    public static function decidir(array $taxas, array $cfg): array
    {
        // Ordena por custo; no empate, mantém a ordem original (uasort é estável no PHP 8).
        uasort($taxas, static fn($a, $b) => $a['custo'] <=> $b['custo']);

        $nossas_pagas = array_filter($taxas, static fn($t) => in_array($t['metodo'], self::NOSSOS, true) && $t['custo'] > 0);
        if (count($nossas_pagas) < 2 || $cfg['exibicao'] === 'sempre') {
            return array_keys($taxas);
        }

        $mais_barata = min(array_column($nossas_pagas, 'custo'));
        $id_mais_barata = array_key_first($nossas_pagas); // já ordenado

        foreach ($nossas_pagas as $id => $t) {
            $esconder = $cfg['exibicao'] === 'mais_barata'
                ? $id !== $id_mais_barata
                : $t['custo'] > $mais_barata * (float) $cfg['limite_ancora'];
            if ($esconder) {
                unset($taxas[$id]);
            }
        }
        return array_keys($taxas);
    }

    /** Filtro woocommerce_package_rates. */
    public static function filtrar(array $taxas, array $pacote): array
    {
        $cfg = CVBP_Config::obter();

        // Só mexe na lista quando a Braspress está de fato à vista: em
        // "desligado" e "sombra" o checkout fica exatamente como era antes do
        // plugin ter a Braspress; em "admin", só os administradores veem a mudança.
        $visivel = $cfg['modo'] === 'ativo' || ($cfg['modo'] === 'admin' && current_user_can('manage_woocommerce'));
        if (!$visivel) {
            return $taxas;
        }

        $resumo = [];
        foreach ($taxas as $id => $taxa) {
            $resumo[$id] = ['metodo' => $taxa->get_method_id(), 'custo' => (float) $taxa->get_cost()];
        }
        $ficam = self::decidir($resumo, $cfg);
        $novo = [];
        foreach ($ficam as $id) {
            $novo[$id] = $taxas[$id];
        }
        return $novo;
    }
}
