# Pagou para Perfex CRM

Módulo de pagamentos da [Pagou](https://pagou.com.br) para o [Perfex CRM](https://perfexcrm.com): Pix, boleto e cartão de crédito nas faturas, com baixa automática e split de pagamento.

**Versão:** 2.2.0 · **Perfex:** 3.2.0 ou superior · **PHP:** o exigido pela versão do Perfex

## Recursos

- **Pix:** QR Code imediato ou Pix com vencimento da fatura, com multa, juros e desconto.
- **Boleto:** emissão automática ao criar a fatura, com multa, juros, desconto, carência e Pix vinculado.
- **Cartão de crédito:** checkout na própria fatura com tokenização no navegador, à vista ou parcelado, e estorno pelo admin.
- **Split:** repasse automático a recebedores, por regra global ou por cliente.
- **Baixa automática:** webhook assinado e reconciliação pelo cron, para os casos em que o webhook não chega.
- **Pix avulso:** cobranças fora de fatura, com página pública de pagamento.
- **Reembolso:** devolução de Pix pelo admin.

## Instalação

1. Copie a pasta do módulo para `modules/pagou` na instalação do Perfex.
2. Em **Setup → Modules**, ative **Pagou Integração Perfex CRM**.
3. Se o Perfex pedir para atualizar o banco de dados, confirme. Até isso ser feito, a emissão automática e os webhooks ficam pausados.
4. Configure o cron do Perfex (**Setup → Settings → Cron Job**). Ele é usado para a reconciliação e para a emissão automática de boletos.

### Atualização a partir da 1.x

Substitua os arquivos da pasta `modules/pagou` e confirme a atualização do banco de dados. As faturas já existentes ficam fora da emissão automática de boleto, para não gerar cobranças do histórico.

## Configuração

Em **Setup → Settings → Payment Gateways**, cada meio de pagamento tem a própria aba: **Pagou Pix**, **Pagou Boleto** e **Pagou Cartão**.

- **Chave de API:** use a chave do mesmo ambiente escolhido (Sandbox ou Produção). Ela é guardada criptografada.
- **Ambiente:** comece em Sandbox (`api.pagou.cloud`) e passe para Produção (`api.pagou.com.br`) depois de homologar.
- **Webhook:** a URL padrão é `https://SEU-PERFEX/pagou/pagou_webhook/process` e é enviada automaticamente em cada cobrança. A assinatura (`X-Pagou-Signature`) é verificada com a chave de API.
- **Moeda:** as faturas precisam estar em `BRL`.

### Cartão de crédito

- A conta na Pagou precisa de **credenciamento de cartão** aprovado.
- **Parcelas:** número máximo e valor mínimo por parcela.
- **IP do comprador (antifraude):**
  - `remote`: o Perfex recebe o acesso direto, sem proxy;
  - `xff`: há um nginx ou balanceador na frente. Informe as faixas dele em **proxies confiáveis**;
  - `cloudflare`: o site passa pela Cloudflare. Informe as faixas da Cloudflare em **proxies confiáveis**.

### Split

1. Habilite o split na conta Pagou e autorize os recebedores no painel da Pagou.
2. Ative a opção **SPLIT** nas abas dos meios de pagamento em que ele deve valer.
3. Em **Pagou → Split de Pagamento**, cadastre os recebedores e as regras, globais ou por cliente.

A regra do cliente prevalece sobre as globais, e o tipo "sem split" isenta o cliente. A soma dos percentuais respeita uma margem mínima para as tarifas (10% por padrão).

## Menu e permissões

O menu **Pagou** no admin traz PIX Avulso, Reembolso de Pagamentos, Cobranças de Cartão, Split de Pagamento e Configurações. O acesso é controlado em **Setup → Staff → Roles**, em **Pagou Integração**:

| Permissão | Libera |
|---|---|
| Acessar PIX Avulso | Criar e consultar Pix avulsos |
| Acessar Reembolso de Pagamentos | Devolver pagamentos Pix |
| Estornar cobranças de cartão | Estornar cobranças de cartão |
| Configurar split de pagamento | Recebedores e regras de split |

## Segurança e privacidade

- Número e CVV do cartão nunca passam pelo servidor do Perfex: a tokenização é feita no navegador.
- Os webhooks são autenticados por HMAC-SHA256, com janela de 5 minutos, e cada evento é processado uma única vez.
- Cada pagamento recebe uma única baixa, mesmo que o webhook e o cron cheguem juntos.
- CPF, CNPJ, e-mail e outros dados pessoais são mascarados nos logs.

## Testes

```bash
bash tests/integration/run.sh
```

Os testes de integração usam MariaDB em Docker e uma API simulada, sem chamadas à Pagou. Veja [tests/integration/README.md](tests/integration/README.md).

## Suporte

- Documentação da API: https://docs.pagou.com.br
- Pagou: https://pagou.com.br
