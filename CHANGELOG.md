# Histórico de versões

## 0.3.2 — 2026-11-17
Correção urgente: a instalação ao lado da 0.2.0 derrubava o site.

- **Erro fatal com a 0.2.0 ainda ativa.** As duas versões usam as mesmas constantes. Quando a 0.2.0 carregava primeiro, a 0.3.x procurava os arquivos da Braspress na pasta da 0.2.0 (`cotacao-volumes-total-express/includes/braspress/...`), que não existem: erro fatal em todas as páginas (aconteceu em produção, 17/11). Agora, enquanto a versão antiga estiver ativa, esta **não carrega nada** e mostra um aviso no painel; a antiga segue funcionando sozinha. E esta **se recusa a ser ativada** enquanto a antiga estiver ativa, explicando o que fazer.

## 0.3.1 — 2026-11-17
Correções da revisão de segurança de 17/11/2026.

- **Sobrecarga pelo carrinho (gravidade média, existia desde a 0.2.0).** Com milhares de unidades de um produto volumoso, o empacotador gastava ~8 s de CPU por cálculo de frete, e qualquer visitante podia repetir isso trocando o CEP. Agora há um teto de **30 volumes por pedido** (até ~900 kg; 100 kg de malte em pacotes de 100 g dão 4 volumes). Acima disso, a cotação automática não aparece, e as outras opções de frete seguem. O empacotador também descarta pelo peso, antes do cálculo caro, as caixas já cheias. Pior caso: de ~8 s para centésimos de segundo.
- **Braspress:** o nome do cache passa a ser um HMAC com a chave secreta do site, em vez de um md5 do pedido (que contém o CPF/CNPJ); o número da cotação, gravado no pedido, aceita só dígitos; um `statusCode` fora do formato não quebra a mensagem de erro.
- 8 testes novos (Total Express: 74; Braspress: 89), incluindo um que garante o tempo baixo no pior caso.
- Atualizando da 0.3.0: envie o zip por cima (Plugins → Adicionar novo → Enviar plugin → "Substituir a atual"). Atualizando da 0.2.0: siga as instruções da 0.3.0 abaixo.

## 0.3.0 — 2026-11-10
Braspress e novo nome: **Cotação Direta de Transportadoras**.

- **Novo nome.** O plugin deixa de ser só da Total Express: passa a se chamar *Cotação Direta de Transportadoras*, com o objetivo explícito de dispensar intermediários de cotação como a Frenet. Pasta, arquivo principal e text domain mudam para `cotacao-direta-transportadoras`; o repositório no GitHub é renomeado (o endereço antigo redireciona).
- **Atualizando da 0.2.0:** como a pasta mudou, o WordPress vê um plugin novo. 1) **Desative** a "Cotação por Volumes para Total Express"; 2) apague **só a pasta** `wp-content/plugins/cotacao-volumes-total-express` (FTP ou gerenciador de arquivos). **Não use "Excluir" no painel**: ele roda o `uninstall.php` e apaga configurações e credenciais; 3) envie a nova versão e ative. As configurações e as credenciais são mantidas, porque os nomes internos (`cvte_*`, `cvbp_*`) não mudaram. As duas versões não podem ficar ativas juntas.
- **Checkup de segurança** (12/11/2026), nas duas transportadoras:
  - a conexão **não segue redirecionamentos** (o WordPress reenviaria o cabeçalho com a senha ao novo endereço, até para outro site ou `http://`);
  - a senha só vai para endereços `https://` (constantes `*_ENDPOINT` com `http://` são recusadas), com o certificado conferido;
  - respostas limitadas a 256 KB;
  - o cache de cotações **não guarda mais o JSON/XML enviado**, que na Braspress continha o CPF/CNPJ do cliente (LGPD);
  - a tela de teste ignora campos enviados em formato de lista;
  - 18 testes novos (Total Express: 70; Braspress: 85).
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
