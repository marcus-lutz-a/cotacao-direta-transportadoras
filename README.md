# Cotação Direta de Transportadoras

Plugin para WooCommerce que cota o frete **direto nas transportadoras**, com o
**contrato da própria loja**, sem intermediário. A ideia é **não precisar de um
gateway de cotação como a Frenet**: se a loja já tem contrato com a
transportadora, o checkout pode perguntar o preço a ela diretamente.

Transportadoras suportadas:

| Transportadora | Integração | Desde |
|---|---|---|
| **Total Express** | webservice SOAP de cálculo de frete | 0.2.0 |
| **Braspress** | API REST oficial (`/v1/cotacao/calcular/json`) | 0.3.0 |

O pedido é **dividido em volumes** (caixas) antes da cotação, e as regras de
preço da loja (embalagem, repasse do Simples, prazo extra) são aplicadas por
cima do custo da transportadora.

> Projeto independente, sem vínculo com a Total Express, a Braspress ou a
> Frenet; os nomes são marcas dos seus titulares. Você precisa ter contrato com
> cada transportadora que quiser usar e as credenciais da API dela.

---

## Por que existe

Muitas lojas com contrato direto com uma transportadora usam um serviço
intermediário de frete só para mostrar o preço no checkout. Na prática, isso
acrescenta mais um elo que pode falhar entre a loja e a transportadora. Os
problemas que motivaram este plugin:

| Problema | Efeito para o cliente |
|---|---|
| O pedido é cotado como **uma caixa só** | Acima do limite por volume da transportadora (30 kg na Total Express), a opção some, justamente nos pedidos maiores |
| Falhas silenciosas de configuração ou de tempo de resposta | A opção some sem nenhum registro do motivo |
| Mais um serviço entre a loja e a transportadora | Mais uma dependência externa no checkout, e mais uma conta a manter |

Este plugin:

1. **Divide o pedido em volumes** respeitando os limites de cada
   transportadora. Uma mesma nota fiscal pode ter vários volumes;
2. **Cota direto na transportadora**, com as credenciais do contrato da loja;
3. **Aplica as regras de preço** da loja: embalagem por volume, repasse do
   Simples Nacional e prazo adicional (opcionais);
4. **Nunca derruba o checkout:** se a transportadora falhar ou demorar, a opção
   não aparece, os demais fretes continuam e o motivo fica no log;
5. **Facilita a troca:** um botão coloca o método nas mesmas áreas de entrega
   da Frenet, para comparar os preços lado a lado antes de desativá-la.

## Recursos

- Divisão em volumes por *first-fit decreasing*, com caixa mínima e folga
  configuráveis.
- Prazo do pedido = maior prazo entre os volumes, mais um prazo adicional
  configurável.
- Cache por volume (padrão 6 h) e cache curto de falhas (5 min).
- Limite de tempo por consulta e por pedido: o checkout não trava.
- **Quatro modos de implantação:** `desligado` → `admin` (só administradores
  veem, marcado como [TESTE]) → `sombra` (calcula e registra, ninguém vê) → `ativo`.
- Tela de teste de cotação com o XML enviado e recebido.
- **Saída da Frenet:** um botão adiciona o método a todas as áreas de entrega
  que já têm a Frenet, para comparar lado a lado antes de desativá-la.
- Log de cada cálculo em WooCommerce → Status → Logs, origem `cvte-total-express`.

## Braspress (desde a 0.3.0)

Segundo método de entrega, independente da Total Express, com tela própria em
**WooCommerce → Braspress**. Cota na **API REST oficial** da Braspress
(`POST /v1/cotacao/calcular/json`, HTTP Basic) com o contrato da loja.

| | Total Express | Braspress |
|---|---|---|
| Protocolo | SOAP/XML | REST/JSON |
| Cotação | uma por volume | **uma por pedido** (lista de caixas em `cubagem`, em metros) |
| Limite por volume | 30 kg | configurável (a Braspress não tem o teto de 30 kg) |
| Depende do destinatário | não | **sim**: CPF (pessoa física) e CNPJ têm preços diferentes |
| Validade da cotação | — | até 23:59 do dia (o cache respeita isso) |

**CPF/CNPJ do destinatário.** O plugin procura o documento nos campos do
checkout (configuráveis; o padrão cobre o *Brazilian Market on WooCommerce*) e
no cadastro do cliente logado, e o coloca no pacote de entrega — assim o
WooCommerce recota quando o cliente digita ou troca o documento. Sem documento
(carrinho, página do produto), a Braspress fica oculta ou é estimada com um CPF
de referência informado pelo lojista. O plugin nunca inventa um documento.

**Exibição junto com a Total Express.** Com a Braspress visível, as opções são
ordenadas da mais barata para a mais cara e, no modo "âncora", uma opção deste
plugin que custe mais de N vezes a mais barata é escondida (padrão 3×). Opções
de outros plugins nunca são escondidas. Nos modos `desligado` e `sombra` o
checkout fica exatamente como antes.

Credenciais pelo painel (senha criptografada) ou no `wp-config.php`:

```php
define('CVBP_USUARIO', 'usuario-da-api');
define('CVBP_SENHA',   'senha-da-api');
// opcional: define('CVBP_ENDPOINT', 'https://...');
```

## Requisitos

- WordPress 6.4+, WooCommerce 8.0+, PHP 8.0+ (com libsodium, padrão no PHP).
- Contrato com pelo menos uma das transportadoras e as credenciais da API dela
  (Total Express: usuário/senha do webservice de cálculo de frete; Braspress:
  usuário/senha da API, liberados pela filial).
- Compatível com HPOS (tabelas de pedidos do WooCommerce).

## Instalação

1. Em **Plugins → Adicionar novo → Enviar plugin**, envie o zip
   (`cotacao-direta-transportadoras.zip`) e ative.
2. Em **WooCommerce → Total Express → Credenciais do webservice**, digite o
   usuário e a senha e clique em **Salvar credenciais**.
   Alternativa (tem prioridade), no `wp-config.php`:
   ```php
   define('CVTE_USUARIO', 'usuario-do-webservice');
   define('CVTE_SENHA',   'senha-do-webservice');
   ```
3. Em **Regras**, informe o **código do serviço contratado** (`STD`, `EXP`...).
   Um código fora do contrato é recusado com "Tipo de Servico nao utilizado".
4. Use **Testar uma cotação** para validar a credencial.
5. Adicione o método às áreas de entrega: pelo botão de migração da Frenet ou
   em WooCommerce → Configurações → Entrega.
6. Avance o modo: `admin` → `sombra` → `ativo`.

> **Saindo da Frenet:** adicione o método às áreas **antes** de desativar a
> Frenet (o botão encontra as áreas procurando a Frenet nelas), compare os
> preços no modo **admin**, mude para **ativo** e só então desative a Frenet.

> **Atualizando da 0.2.0** (que se chamava *Cotação por Volumes para Total
> Express*): desative a antiga, apague só a pasta `cotacao-volumes-total-express`
> por FTP e instale a nova. **Não use "Excluir" no painel** na versão antiga: isso
> apaga as configurações e as credenciais salvas. Detalhes no `CHANGELOG.md`.

## Regras de preço

```
base  = soma do frete de cada volume + embalagem × nº de volumes
final = base ÷ (1 − Simples)   modo "dividir"
      = base × (1 + Simples)   modo "multiplicar"
```

Com Simples 0% e embalagem R$ 0 (o padrão), o cliente paga o custo da Total
Express. O modo **"dividir"** faz a loja receber exatamente o custo depois do
imposto; o **"multiplicar"** reproduz a regra da Frenet.

## Segurança

- **Senha nunca em texto puro no banco.** Digitada no painel, ela é guardada
  criptografada (libsodium), com uma chave derivada de `AUTH_KEY` e
  `SECURE_AUTH_KEY` do `wp-config.php`. Um backup do banco sozinho não revela
  a senha, e uma cópia do banco em outro site não consegue lê-la. A senha nunca
  volta para a tela. Sem essas chaves, o plugin se recusa a guardá-la.
- Se as chaves secretas do WordPress forem trocadas, a tela avisa e a senha
  precisa ser digitada de novo.
- Formulários protegidos por permissão (`manage_woocommerce`), *nonce* e
  sanitização; saída escapada (`esc_html`/`esc_attr`); SQL só via
  `$wpdb->prepare`; valores do XML escapados.
- Senha enviada só no cabeçalho HTTP Basic, nunca no corpo nem no log.
- **A senha só vai para endereços `https://`**, com o certificado conferido. O
  plugin **não segue redirecionamentos**: o WordPress, ao seguir um, reenviaria
  o cabeçalho com a senha para o novo endereço, mesmo que fosse outro site ou
  `http://`. Uma constante `CVTE_ENDPOINT`/`CVBP_ENDPOINT` com `http://` é recusada.
- Respostas das transportadoras limitadas a 256 KB.
- Teto de 2.000 unidades e de 30 volumes por pedido contra carrinhos absurdos (que serviriam para sobrecarregar o servidor).
- `uninstall.php` remove configurações, credenciais salvas e cache ao excluir.

### Privacidade (LGPD)

- Para cotar na **Braspress**, o CPF ou CNPJ do cliente é enviado à
  transportadora, porque o preço depende dele. Informe isso na política de
  privacidade da loja.
- O plugin **não guarda o documento do cliente**: o cache de cotações tem só
  preço, prazo e número da cotação, e o log registra apenas "PF" ou "PJ".
- O "CPF de referência" (estimativa no carrinho) deve ser de alguém que
  autorizou o uso, por exemplo o do próprio lojista.

### Recomendações para quem instala

- Como qualquer cálculo de frete, cada CEP (e, na Braspress, cada documento)
  novo gera uma consulta à transportadora. O cache e os limites de tempo
  protegem o checkout, mas em lojas muito visadas vale um limite de requisições
  no firewall (WAF/Cloudflare) para `?wc-ajax=update_order_review`.
- Prefira as credenciais no `wp-config.php` quando o servidor permitir, e
  restrinja o acesso ao painel (`manage_woocommerce`) a quem precisa.

## Decisões de projeto

**SOAP montado à mão, sem `ext-soap`.** Nem toda hospedagem tem a extensão. O
envelope segue o WSDL oficial e é enviado com `wp_remote_post()`.

**Leitura tolerante da resposta.** Campos lidos por nome, ignorando prefixos de
namespace: uma mudança cosmética do lado da transportadora não derruba o frete.

**Conversão monetária defensiva.** `"22.80"` e `"22,80"` viram 22,80, e
`"1.234,56"` vira 1234,56: decide-se pelo último separador.

**Funções puras.** O empacotador e o cálculo de preço recebem números e
devolvem números: são testados sem WordPress, sem banco e sem internet.

## Testes

```bash
php tests/testes.php            # Total Express (74 verificações)
php tests/testes-braspress.php  # Braspress (89 verificações)
```

Total Express: conversão monetária, leitura de respostas (sucesso, recusa,
erro SOAP, HTML inesperado), validade e escape do XML, empacotamento, folga da
caixa, teto de unidades, cálculo de preço, cache, autenticação, cache de falhas
criptografia das credenciais e as travas de segurança da conexão
(redirecionamento, https, tamanho da resposta, cache sem dados pessoais). Os
arquivos só rodam pela linha de comando.

## Limitações conhecidas

- O empacotamento é uma heurística: estima as caixas pelo volume, não simula o
  encaixe físico. A folga padrão (15%) deve ser calibrada com etiquetas reais.
- Cota um serviço por vez (o contratado).
- Somente cotação: registro de coleta e rastreio não estão incluídos.
- Textos em português.
- Em multisite, desinstalar limpa só o site atual.

## Licença

GPL-2.0-or-later.

Desenvolvido por Marcus Lutz, com Claude (Anthropic) como par de programação.
