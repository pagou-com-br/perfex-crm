# Testes de integração

Exercitam o processador de eventos (webhooks, reconciliação, migration 200) e os gateways Pix e Boleto contra um banco MariaDB real e um servidor simulado da API Pagou. Nenhuma chamada vai para a API real.

## Requisitos

- PHP 8 com `mysqli` ou `pdo_mysql`
- Docker
- `curl` e `unzip`

## Executar

```bash
tests/integration/run.sh
```

O script baixa o CodeIgniter 3.1.13 para `tests/integration/.ci3`, sobe o container `pagou-perfex-test-db` (MariaDB na porta 33071) e o mock da API em `127.0.0.1:8099`.

## Limites

O núcleo do Perfex não é distribuído com o módulo. Por isso `bootstrap.php` traz stubs das funções e modelos usados (`get_option`, `payments_model->add`, `invoices_model`, `App_gateway` etc.). Eles são simplificações; antes de publicar, homologue também numa instalação real do Perfex com a conta de Sandbox da Pagou.
