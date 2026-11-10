<?php
/**
 * O método de entrega "Braspress" das áreas de entrega do WooCommerce.
 *
 * Fluxo (compare com CVTE_Metodo, da Total Express):
 *   pacote → documento do cliente (CPF/CNPJ) → CVTE_Empacotador (caixas)
 *   → CVBP_API (UMA cotação para o pedido inteiro) → preço ao cliente → add_rate()
 *
 * Qualquer problema faz a Braspress simplesmente não aparecer; as outras
 * opções seguem, e o motivo vai para o log "cvbp-braspress".
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVBP_Metodo extends WC_Shipping_Method
{
    const LOG = 'cvbp-braspress';

    public function __construct($instance_id = 0)
    {
        $this->id                 = 'cvbp_braspress';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'Braspress (cotação pela API)';
        $this->method_description = 'Cotação direta na API da Braspress, com o contrato da loja. '
                                  . 'As regras ficam em WooCommerce → Braspress.';
        $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = [
            'enabled' => ['title' => 'Ativar nesta área', 'type' => 'checkbox', 'default' => 'yes'],
        ];
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title   = CVBP_Config::obter()['titulo'];
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    public function is_available($pacote): bool
    {
        $modo = CVBP_Config::obter()['modo'];
        if ($modo === 'desligado' || $this->enabled !== 'yes') {
            return false;
        }
        if ($modo === 'admin' && !current_user_can('manage_woocommerce')) {
            return false;
        }
        return ($pacote['destination']['country'] ?? 'BR') === 'BR' && parent::is_available($pacote);
    }

    public function calculate_shipping($pacote = []): void
    {
        $cfg = CVBP_Config::obter();
        $cep = preg_replace('/\D/', '', $pacote['destination']['postcode'] ?? '');
        if (strlen($cep) !== 8) {
            return;
        }

        // 1. Documento do destinatário (colocado no pacote por CVBP_Documento::no_pacote).
        $doc = $pacote['cvbp_documento'] ?? null;
        $estimado = false;
        if (!$doc) {
            if ($cfg['documento_desconhecido'] === 'cpf_referencia' && CVBP_Documento::cpf_valido($cfg['cpf_referencia'])) {
                $doc = ['numero' => $cfg['cpf_referencia'], 'tipo' => 'PF'];
                $estimado = true;
            } else {
                return; // sem documento: a Braspress aparece no checkout, quando o CPF/CNPJ for digitado
            }
        }

        // 2. Caixas.
        $emb = CVTE_Empacotador::empacotar(self::itens_do_pacote($pacote, $cfg, [$this, 'log']), $cfg);
        if (!$emb['ok']) {
            $this->log("CEP $cep: indisponível — " . $emb['erro']);
            return;
        }

        // 3. Uma cotação para o pedido inteiro.
        $r = CVBP_API::cotar([
            'cnpj_remetente' => $cfg['cnpj_remetente'],
            'documento'      => $doc['numero'],
            'cep_origem'     => $cfg['cep_origem'],
            'cep_destino'    => $cep,
            'valor'          => array_sum(array_column($emb['volumes'], 'valor')),
            'volumes'        => $emb['volumes'],
        ], $cfg);

        if (!$r['ok']) {
            $this->log(sprintf('CEP %s (%s): sem cotação — %s', $cep, $doc['tipo'], $r['erro']));
            return;
        }

        $n = count($emb['volumes']);
        $preco = CVBP_Config::preco_final($r['valor'], $n, $cfg);
        $prazo = $r['prazo'] > 0 ? $r['prazo'] + (int) $cfg['prazo_adicional'] : 0;

        $this->log(sprintf(
            'CEP %s [%s] %s%s: %d volume(s), %.2f kg → custo Braspress R$ %s → cliente R$ %s, %s%s',
            $cep, $cfg['modo'], $doc['tipo'], $estimado ? ' (CPF de referência)' : '',
            $n, array_sum(array_column($emb['volumes'], 'peso')),
            number_format($r['valor'], 2, ',', '.'), number_format($preco, 2, ',', '.'),
            $prazo ? $prazo . ' dias' : 'SEM PRAZO', $r['cache'] ? ' (cache)' : ''
        ));

        if ($cfg['modo'] === 'sombra') {
            return;
        }

        $rotulo = $prazo ? sprintf('%s (Entrega em %d dias úteis)', $this->title, $prazo) : $this->title;
        if ($cfg['modo'] === 'admin') {
            $rotulo = '[TESTE] ' . $rotulo;
        }

        $meta = ['Volumes' => $n, 'Destinatário' => $doc['tipo'] === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física'];
        if ($prazo) {
            $meta['Prazo (dias úteis)'] = $prazo;
        }
        if ($r['id'] !== '') {
            $meta['Cotação Braspress'] = $r['id'];
        }

        $this->add_rate(['id' => $this->get_rate_id(), 'label' => $rotulo, 'cost' => $preco, 'meta_data' => $meta]);
    }

    /**
     * Carrinho → itens para o empacotador (kg, cm, valor pago por unidade).
     * Mesma regra da Total Express; repetida aqui de propósito para não mexer
     * no código dela nesta versão. Candidata a virar uma classe comum.
     */
    public static function itens_do_pacote(array $pacote, array $cfg, ?callable $log = null): array
    {
        $itens = [];
        foreach ($pacote['contents'] ?? [] as $linha) {
            $produto = $linha['data'] ?? null;
            if (!$produto || !$produto->needs_shipping()) {
                continue;
            }
            $qtd = max(1, (int) $linha['quantity']);
            $peso = (float) wc_get_weight((float) $produto->get_weight(), 'kg');
            if ($peso <= 0) {
                $peso = (float) $cfg['peso_padrao'];
                if ($log) {
                    $log(sprintf('Produto sem peso: "%s" (ID %d) — usando %.2f kg.', $produto->get_name(), $produto->get_id(), $peso));
                }
            }
            $dims = array_map(static fn($d) => $d > 0 ? $d : 1.0, [
                (float) wc_get_dimension((float) $produto->get_length(), 'cm'),
                (float) wc_get_dimension((float) $produto->get_width(), 'cm'),
                (float) wc_get_dimension((float) $produto->get_height(), 'cm'),
            ]);
            $itens[] = ['nome' => $produto->get_name(), 'peso' => $peso, 'dims' => $dims, 'valor' => (float) $linha['line_total'] / $qtd, 'qtd' => $qtd];
        }
        return $itens;
    }

    public function log(string $mensagem): void
    {
        wc_get_logger()->info($mensagem, ['source' => self::LOG]);
    }
}
