<?php
/**
 * A tela "WooCommerce → Braspress".
 *
 * Mesma estrutura e as mesmas três portas de segurança da tela da Total
 * Express (permissão, nonce, sanitização) e o padrão Post/Redirect/Get.
 *
 *   1. Situação e credenciais
 *   2. Remetente, regras de preço e exibição
 *   3. Testar uma cotação (mostra o JSON enviado e recebido)
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVBP_Admin
{
    const PAGINA = 'cvbp-braspress';
    const NONCE  = 'cvbp_admin';

    public static function iniciar(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'processar']);
    }

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'Cotação Braspress', 'Braspress', 'manage_woocommerce', self::PAGINA, [self::class, 'renderizar']);
    }

    public static function processar(): void
    {
        if (empty($_POST['cvbp_acao'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer(self::NONCE);

        $acao = sanitize_key($_POST['cvbp_acao']);
        $dados = wp_unslash($_POST);

        switch ($acao) {
            case 'salvar':
                update_option(CVBP_Config::OPCAO, CVBP_Config::sanitizar($dados), false);
                $cfg = CVBP_Config::obter();
                $faltas = [];
                if ($cfg['cnpj_remetente'] === '') {
                    $faltas[] = 'CNPJ do remetente inválido ou vazio';
                }
                if ($cfg['cep_origem'] === '') {
                    $faltas[] = 'CEP de origem inválido ou vazio';
                }
                self::avisar($faltas ? 'Salvo, mas atenção: ' . implode('; ', $faltas) . '.' : 'Configurações salvas.', $faltas ? 'error' : 'success');
                break;

            case 'salvar_credenciais':
                $erro = CVBP_Config::salvar_credenciais(
                    sanitize_text_field((string) ($dados['cred_usuario'] ?? '')),
                    substr((string) ($dados['cred_senha'] ?? ''), 0, 200)
                );
                self::avisar($erro ?: 'Credenciais salvas (senha criptografada).', $erro ? 'error' : 'success');
                break;

            case 'apagar_credenciais':
                CVBP_Config::apagar_credenciais();
                self::avisar('Credenciais apagadas.');
                break;

            case 'testar':
                set_transient('cvbp_teste_' . get_current_user_id(), self::executar_teste($dados), 300);
                break;

            case 'adicionar_areas':
                $n = self::adicionar_nas_areas();
                self::avisar($n ? "Braspress adicionada a $n área(s) de entrega." : 'Nenhuma área nova: a Braspress já está em todas as áreas que têm a Total Express.');
                break;

            case 'limpar_cache':
                update_option(CVBP_API::OPCAO_VERSAO_CACHE, (int) get_option(CVBP_API::OPCAO_VERSAO_CACHE, 1) + 1, true);
                self::avisar('Cache de cotações da Braspress invalidado.');
                break;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGINA));
        exit;
    }

    /** Cotação avulsa, sem cache e sem guardar falha. */
    private static function executar_teste(array $d): array
    {
        $cfg = CVBP_Config::obter();
        $cfg['cache_horas'] = 0;
        $num = static fn($k, $padrao) => is_numeric(str_replace(',', '.', (string) ($d[$k] ?? ''))) ? (float) str_replace(',', '.', (string) $d[$k]) : $padrao;

        $entrada = [
            'cep'   => CVBP_Documento::limpar((string) ($d['t_cep'] ?? '')),
            'doc'   => CVBP_Documento::limpar((string) ($d['t_doc'] ?? '')),
            'peso'  => $num('t_peso', 10.0),
            'valor' => $num('t_valor', 150.0),
            'dims'  => [$num('t_comp', 40), $num('t_larg', 30), $num('t_alt', 20)],
            'vols'  => max(1, min(20, (int) $num('t_vols', 1))),
        ];
        $volumes = array_fill(0, $entrada['vols'], ['peso' => $entrada['peso'] / $entrada['vols'], 'dims' => $entrada['dims']]);

        $inicio = microtime(true);
        $r = CVBP_API::cotar([
            'cnpj_remetente' => $cfg['cnpj_remetente'],
            'documento'      => $entrada['doc'],
            'cep_origem'     => $cfg['cep_origem'],
            'cep_destino'    => $entrada['cep'],
            'valor'          => $entrada['valor'],
            'volumes'        => $volumes,
        ], $cfg, false);
        $r['ms'] = (int) round((microtime(true) - $inicio) * 1000);
        if ($r['ok']) {
            $r['preco_cliente'] = CVBP_Config::preco_final($r['valor'], $entrada['vols'], $cfg);
        }
        $tipo = CVBP_Documento::classificar($entrada['doc']);
        $entrada['tipo'] = $tipo['tipo'] ?? '?';
        return ['entrada' => $entrada, 'resultado' => $r];
    }

    /** Adiciona a Braspress às áreas que já têm a Total Express deste plugin. */
    private static function adicionar_nas_areas(): int
    {
        $n = 0;
        $areas = WC_Shipping_Zones::get_zones();
        $areas[] = ['zone_id' => 0];
        foreach ($areas as $dados) {
            $area = new WC_Shipping_Zone($dados['zone_id']);
            $ids = array_map(static fn($m) => $m->id, $area->get_shipping_methods());
            if (in_array('cvte_total_express', $ids, true) && !in_array('cvbp_braspress', $ids, true) && $area->add_shipping_method('cvbp_braspress')) {
                $n++;
            }
        }
        return $n;
    }

    private static function avisar(string $texto, string $tipo = 'success'): void
    {
        set_transient('cvbp_aviso_' . get_current_user_id(), ['texto' => $texto, 'tipo' => $tipo], 60);
    }

    private static function consumir(string $prefixo)
    {
        $chave = $prefixo . get_current_user_id();
        $valor = get_transient($chave);
        delete_transient($chave);
        return $valor;
    }

    public static function renderizar(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $cfg = CVBP_Config::obter();
        $cred = CVBP_Config::credenciais($cfg);
        $aviso = self::consumir('cvbp_aviso_');
        $teste = self::consumir('cvbp_teste_');
        $modos = [
            'desligado' => 'Desligado',
            'admin'     => 'Só administradores veem (teste lado a lado)',
            'sombra'    => 'Sombra: calcula e registra no log, ninguém vê',
            'ativo'     => 'Ativo: todos os clientes veem',
        ];
        $campo = static function (string $nome, $valor, string $passo = '0.01') {
            printf('<input type="number" step="%s" name="%s" value="%s" class="small-text">', esc_attr($passo), esc_attr($nome), esc_attr($valor));
        };
        $texto = static function (string $nome, $valor, string $classe = 'regular-text') {
            printf('<input type="text" name="%s" value="%s" class="%s">', esc_attr($nome), esc_attr($valor), esc_attr($classe));
        };
        ?>
        <div class="wrap">
            <h1>Cotação Braspress <small style="font-weight:400">v<?php echo esc_html(CVTE_VERSAO); ?></small></h1>

            <?php if ($aviso) : ?>
                <div class="notice notice-<?php echo $aviso['tipo'] === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($aviso['texto']); ?></p></div>
            <?php endif; ?>

            <h2>1. Situação</h2>
            <table class="widefat striped" style="max-width:820px">
                <tr><th style="width:240px">Credenciais</th><td><?php
                    $s = ['wp-config' => '✅ Definidas no wp-config.php', 'painel' => '✅ Definidas nesta tela (senha criptografada)',
                          'ilegivel' => '❌ A senha salva não pôde ser lida — digite-a de novo', 'nenhuma' => '❌ Não definidas'];
                    echo esc_html($s[$cred['origem']] ?? $cred['origem']); ?></td></tr>
                <tr><th>Endereço da API</th><td><code><?php echo esc_html($cred['endpoint']); ?></code></td></tr>
                <tr><th>Remetente</th><td><?php echo $cfg['cnpj_remetente'] && $cfg['cep_origem'] ? '✅ CNPJ e CEP de origem configurados' : '❌ Falta CNPJ e/ou CEP de origem (seção 2)'; ?></td></tr>
                <tr><th>Modo atual</th><td><strong><?php echo esc_html($modos[$cfg['modo']] ?? $cfg['modo']); ?></strong></td></tr>
                <tr><th>Registros (log)</th><td><a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>">WooCommerce → Status → Logs</a>, origem <code>cvbp-braspress</code></td></tr>
            </table>

            <h3>Credenciais da API</h3>
            <?php if ($cred['origem'] === 'wp-config') : ?>
                <p>Em uso: as constantes <code>CVBP_USUARIO</code> / <code>CVBP_SENHA</code> do <code>wp-config.php</code>.</p>
            <?php else : $salvas = CVBP_Config::credenciais_salvas(); ?>
                <form method="post" autocomplete="off" style="max-width:820px">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <input type="hidden" name="cvbp_acao" value="salvar_credenciais">
                    <table class="form-table">
                        <tr><th>Usuário</th><td><input type="text" name="cred_usuario" value="<?php echo esc_attr($salvas['usuario']); ?>" class="regular-text" autocomplete="off"></td></tr>
                        <tr><th>Senha</th><td><input type="password" name="cred_senha" value="" class="regular-text" autocomplete="new-password"
                            placeholder="<?php echo $salvas['origem'] === 'painel' ? esc_attr('•••••••• (deixe em branco para manter)') : ''; ?>">
                            <p class="description">Liberadas pela filial Braspress. Homologação e produção têm credenciais diferentes: escolha o ambiente na seção 2.</p></td></tr>
                    </table>
                    <?php submit_button('Salvar credenciais', 'primary', 'submit', false); ?>
                </form>
                <?php if ($salvas['origem'] !== 'nenhuma') : ?>
                    <form method="post" style="margin-top:8px">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="cvbp_acao" value="apagar_credenciais">
                        <?php submit_button('Apagar credenciais salvas', 'delete', 'submit', false, ['onclick' => "return confirm('Apagar as credenciais da Braspress?');"]); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" style="margin-top:12px">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvbp_acao" value="adicionar_areas">
                <?php submit_button('Adicionar a Braspress às áreas que têm a Total Express', 'secondary', 'submit', false); ?>
            </form>

            <h2>2. Remetente, regras e exibição</h2>
            <form method="post">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvbp_acao" value="salvar">
                <table class="form-table">
                    <tr><th>Modo</th><td><select name="modo"><?php foreach ($modos as $v => $r) : ?>
                        <option value="<?php echo esc_attr($v); ?>" <?php selected($cfg['modo'], $v); ?>><?php echo esc_html($r); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Ambiente</th><td><select name="ambiente">
                        <option value="producao" <?php selected($cfg['ambiente'], 'producao'); ?>>Produção</option>
                        <option value="homologacao" <?php selected($cfg['ambiente'], 'homologacao'); ?>>Homologação (testes)</option></select></td></tr>
                    <tr><th>Nome mostrado ao cliente</th><td><?php $texto('titulo', $cfg['titulo']); ?></td></tr>
                    <tr><th>CNPJ do remetente</th><td><?php $texto('cnpj_remetente', $cfg['cnpj_remetente']); ?>
                        <p class="description">O CNPJ da loja cadastrado no contrato Braspress. Só dígitos ou com pontuação.</p></td></tr>
                    <tr><th>CEP de origem</th><td><?php $texto('cep_origem', $cfg['cep_origem'], 'small-text'); ?></td></tr>
                    <tr><th>Modal</th><td><select name="modal">
                        <option value="R" <?php selected($cfg['modal'], 'R'); ?>>Rodoviário</option>
                        <option value="A" <?php selected($cfg['modal'], 'A'); ?>>Aéreo</option></select></td></tr>
                    <tr><th>Quem paga o frete</th><td><select name="tipo_frete">
                        <option value="1" <?php selected($cfg['tipo_frete'], 1); ?>>CIF — a loja paga (padrão do e-commerce)</option>
                        <option value="2" <?php selected($cfg['tipo_frete'], 2); ?>>FOB — o destinatário paga</option>
                        <option value="3" <?php selected($cfg['tipo_frete'], 3); ?>>Consignado</option></select></td></tr>
                    <tr><th>Sem CPF/CNPJ do cliente</th><td><select name="documento_desconhecido">
                        <option value="ocultar" <?php selected($cfg['documento_desconhecido'], 'ocultar'); ?>>Não mostrar a Braspress até o documento ser informado</option>
                        <option value="cpf_referencia" <?php selected($cfg['documento_desconhecido'], 'cpf_referencia'); ?>>Estimar como pessoa física, com o CPF de referência</option></select>
                        <br>CPF de referência <?php $texto('cpf_referencia', $cfg['cpf_referencia'], 'regular-text'); ?>
                        <p class="description">Um CPF real, de quem autorizou o uso (ex.: o seu). Ele só serve para a Braspress devolver o preço de pessoa física antes de o cliente digitar o dele.</p></td></tr>
                    <tr><th>Campos de CPF/CNPJ no checkout</th><td><?php $texto('campos_documento', $cfg['campos_documento'], 'large-text'); ?>
                        <p class="description">Nomes dos campos, separados por vírgula, na ordem em que devem ser procurados.</p></td></tr>
                    <tr><th>Exibição junto com a Total Express</th><td><select name="exibicao">
                        <option value="sempre" <?php selected($cfg['exibicao'], 'sempre'); ?>>Sempre as duas (mais barata primeiro)</option>
                        <option value="ancora" <?php selected($cfg['exibicao'], 'ancora'); ?>>As duas, escondendo a que passar do limite da âncora</option>
                        <option value="mais_barata" <?php selected($cfg['exibicao'], 'mais_barata'); ?>>Só a mais barata</option></select>
                        Limite da âncora <?php $campo('limite_ancora', $cfg['limite_ancora'], '0.1'); ?> × a mais barata</td></tr>
                    <tr><th>Simples Nacional (%)</th><td><?php $campo('simples_pct', $cfg['simples_pct']); ?>
                        <select name="simples_modo">
                            <option value="dividir" <?php selected($cfg['simples_modo'], 'dividir'); ?>>dividir: frete ÷ (1 − %)</option>
                            <option value="multiplicar" <?php selected($cfg['simples_modo'], 'multiplicar'); ?>>multiplicar: frete × (1 + %)</option></select></td></tr>
                    <tr><th>Embalagem por volume (R$)</th><td><?php $campo('embalagem_volume', $cfg['embalagem_volume']); ?></td></tr>
                    <tr><th>Prazo adicional (dias úteis)</th><td><?php $campo('prazo_adicional', $cfg['prazo_adicional'], '1'); ?></td></tr>
                    <tr><th>Peso máximo por caixa (kg)</th><td><?php $campo('peso_max_volume', $cfg['peso_max_volume'], '0.1'); ?></td></tr>
                    <tr><th>Caixa mínima (cm)</th><td><?php $campo('dim_min_0', $cfg['dim_min'][0], '1'); ?> × <?php $campo('dim_min_1', $cfg['dim_min'][1], '1'); ?> × <?php $campo('dim_min_2', $cfg['dim_min'][2], '1'); ?></td></tr>
                    <tr><th>Folga da caixa com 2+ itens (%)</th><td><?php $campo('folga_caixa_pct', $cfg['folga_caixa_pct'], '1'); ?></td></tr>
                    <tr><th>Peso para produto sem peso (kg)</th><td><?php $campo('peso_padrao', $cfg['peso_padrao']); ?></td></tr>
                    <tr><th>Guardar cotações por (horas)</th><td><?php $campo('cache_horas', $cfg['cache_horas'], '1'); ?>
                        <p class="description">Nunca além de 23:59 do dia (validade da cotação Braspress).</p></td></tr>
                    <tr><th>Esperar a Braspress até (s)</th><td><?php $campo('timeout', $cfg['timeout'], '1'); ?></td></tr>
                </table>
                <?php submit_button('Salvar regras'); ?>
            </form>

            <h2>3. Testar uma cotação</h2>
            <form method="post">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvbp_acao" value="testar">
                CEP <?php $texto('t_cep', $teste['entrada']['cep'] ?? '', 'small-text'); ?>
                CPF/CNPJ do destinatário <?php $texto('t_doc', $teste['entrada']['doc'] ?? '', 'regular-text'); ?><br>
                Peso total (kg) <?php $campo('t_peso', $teste['entrada']['peso'] ?? 20); ?>
                Valor (R$) <?php $campo('t_valor', $teste['entrada']['valor'] ?? 250); ?>
                Volumes <?php $campo('t_vols', $teste['entrada']['vols'] ?? 1, '1'); ?>
                Medidas de cada caixa (cm) <?php $campo('t_comp', $teste['entrada']['dims'][0] ?? 50, '1'); ?>
                × <?php $campo('t_larg', $teste['entrada']['dims'][1] ?? 40, '1'); ?>
                × <?php $campo('t_alt', $teste['entrada']['dims'][2] ?? 30, '1'); ?>
                <?php submit_button('Consultar', 'primary', 'submit', false); ?>
            </form>

            <?php if ($teste) : $r = $teste['resultado']; ?>
                <div class="notice <?php echo $r['ok'] ? 'notice-success' : 'notice-error'; ?>" style="max-width:820px"><p>
                    <?php if ($r['ok']) : ?>
                        <strong>Custo Braspress (<?php echo esc_html($teste['entrada']['tipo']); ?>): R$ <?php echo esc_html(number_format($r['valor'], 2, ',', '.')); ?></strong>
                        · prazo <?php echo (int) $r['prazo']; ?> dias
                        · preço ao cliente: <strong>R$ <?php echo esc_html(number_format($r['preco_cliente'], 2, ',', '.')); ?></strong>
                        <?php if ($r['id'] !== '') : ?>· cotação nº <?php echo esc_html($r['id']); ?><?php endif; ?>
                    <?php else : ?>
                        <strong>Sem cotação:</strong> <?php echo esc_html($r['erro']); ?>
                    <?php endif; ?> · <?php echo (int) $r['ms']; ?> ms</p></div>
                <details style="max-width:820px"><summary>Ver JSON enviado e recebido</summary>
                    <p>Enviado (a senha vai no cabeçalho HTTP, não aqui):</p>
                    <pre style="white-space:pre-wrap;background:#fff;padding:8px"><?php echo esc_html($r['enviado'] ?? ''); ?></pre>
                    <p>Recebido:</p>
                    <pre style="white-space:pre-wrap;background:#fff;padding:8px"><?php echo esc_html($r['recebido'] ?? ''); ?></pre>
                </details>
            <?php endif; ?>

            <form method="post" style="margin-top:16px">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvbp_acao" value="limpar_cache">
                <?php submit_button('Limpar cache de cotações da Braspress', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
