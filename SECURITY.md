# Política de segurança

## Versões que recebem correções

| Versão | Correções de segurança |
|---|---|
| 0.3.x | ✅ |
| 0.2.x e anteriores | ❌ atualize para a 0.3 |

## Como relatar uma falha

**Não abra uma issue pública.** Use o relato privado do GitHub:
aba **Security → Report a vulnerability** deste repositório. Só os
mantenedores veem o relato.

Inclua, se puder:

- a versão do plugin, do WordPress, do WooCommerce e do PHP;
- os passos para reproduzir e o que um atacante consegue fazer;
- se a falha exige estar logado (e com qual papel) ou não.

**Não envie** senhas, credenciais de transportadoras nem dados de clientes
reais. Use dados de teste.

O objetivo é responder em até 7 dias e publicar a correção, com crédito a
quem relatou (se quiser), assim que ela estiver pronta.

## O que o plugin protege

- Credenciais das transportadoras guardadas **criptografadas** (libsodium),
  com chave derivada do `wp-config.php`; a senha nunca volta para a tela.
- A senha só vai para endereços `https://`, com certificado conferido, no
  cabeçalho HTTP Basic. **Redirecionamentos não são seguidos**, para que a
  senha não seja reenviada a outro endereço.
- Formulários do painel exigem permissão `manage_woocommerce` e *nonce*;
  toda saída é escapada; SQL só com `$wpdb->prepare`.
- O CPF/CNPJ do cliente vai à Braspress para cotar, mas **não fica guardado**
  pelo plugin (nem no cache nem no log).

## O que está fora do escopo

- A segurança dos servidores das transportadoras e das suas APIs.
- Sites em que um administrador já foi comprometido (quem tem
  `manage_woocommerce` pode, por definição, trocar as credenciais).
- Limite de requisições contra abuso no checkout: recomenda-se um WAF
  (ver "Recomendações para quem instala" no README).
