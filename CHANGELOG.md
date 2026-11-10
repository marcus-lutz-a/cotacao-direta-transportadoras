# Histórico de versões

## 0.3.0 — 2026-11-10
Braspress.

- Novo método de entrega **Braspress**, cotando na API REST oficial (`/v1/cotacao/calcular/json`), com tela própria (WooCommerce → Braspress), credenciais criptografadas e ambientes de produção e homologação.
- Uma cotação por pedido, com as caixas montadas pelo mesmo empacotador da Total Express (limite de peso por caixa configurável) e agrupadas em `cubagem` (metros).
- CPF/CNPJ do destinatário lido do checkout ou do cadastro do cliente, validado pelos dígitos e colocado no pacote para forçar nova cotação quando muda; sem documento, a Braspress fica oculta ou usa um CPF de referência do lojista.
- Cache que respeita a validade da cotação (até 23:59 do dia); erros 4xx não ficam em cache, 5xx ficam 5 minutos.
- Exibição conjunta: mais barata primeiro e modos "sempre", "âncora" (esconde opção deste plugin acima de N× a mais barata) e "mais barata". Inativa nos modos desligado e sombra.
- Mesmos modos de implantação (desligado, admin, sombra, ativo) e log próprio (`cvbp-braspress`).
- 77 testes novos (`tests/testes-braspress.php`). Nenhuma classe da Total Express foi alterada.

## 0.2.0 — 2026-10-05
Primeira versão pública.

- Cotação direta no webservice SOAP da Total Express (`calcularFrete`), sem depender de `ext-soap`.
- Divisão do pedido em volumes de até 30 kg e 120 × 80 × 60 cm, com caixa mínima e folga configuráveis.
- Regras de preço opcionais: embalagem por volume e repasse do Simples Nacional (modos "dividir" e "multiplicar").
- Prazo do pedido = maior prazo entre os volumes + prazo adicional configurável; sem prazo na resposta, o rótulo não inventa um número.
- Cache por volume (6 h), cache curto de falhas (5 min) e "Limpar cache" compatível com Redis/Memcached.
- Limite de tempo por consulta e por pedido.
- Modos de implantação: desligado, admin, sombra, ativo.
- Credenciais pelo painel, com senha criptografada (libsodium, chave derivada do `wp-config.php`), ou por constantes no `wp-config.php`.
- Tela de teste de cotação com o XML enviado e recebido; botão de migração da Frenet.
- Teto de 2.000 unidades por pedido; `uninstall.php`; testes só pela linha de comando.
- 60 testes automáticos executáveis sem WordPress.
