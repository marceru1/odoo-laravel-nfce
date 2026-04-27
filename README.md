# Odoo 18 NFC-e Middleware

Middleware construído em Laravel 11 para integrar as vendas de PDV do ERP Odoo com a API da Focus NFe. 

O Odoo não possui integração fiscal nativa completa para o Brasil (especialmente NFC-e). Alterar o core em Python geraria muito acoplamento. A solução foi extrair a responsabilidade fiscal para um serviço externo e independente. O Odoo apenas informa a venda via Webhook, e o Laravel assume as regras de negócio fiscais.

## Arquitetura e Fluxo

1. **Webhook PDV:** O caixa do Odoo finaliza a venda e dispara o payload.
2. **Adapter Layer:** O Laravel recebe, sanitiza e adapta os dados (CFOP, NCM, regras de contingência, CNPJ dinâmico por filial) para o padrão da SEFAZ/Focus.
3. **Filas (Queues):** Para evitar timeout e travamentos no caixa do operador, o disparo fiscal é enfileirado no Redis e processado em background de forma assíncrona.
4. **Contingência Offline:** Se a SEFAZ ou a internet caírem, o PDV gera a chave local (Tipo B). O middleware absorve esses dados do webhook para garantir que a nota sincronizada posteriormente tenha exatamente a mesma chave de acesso impressa no papel.
5. **Callback:** Após a autorização, o sistema envia o retorno (Chave + QR Code) de volta ao Odoo para exibição no histórico ou reimpressão.

## Tech Stack

- **PHP 8.2 / Laravel 11**
- **Laravel Octane (Swoole):** Necessário para lidar com picos de webhooks de múltiplos caixas simultâneos sem gargalo no FPM.
- **Redis:** Gerenciamento de Filas e Cache de sessão.
- **MariaDB:** Persistência do histórico fiscal.
- **Docker Compose:** Infraestrutura fully-containerized pronta para deploy.

## Setup Local

```bash
git clone https://github.com/marceru1/odoo-laravel-nfce.git
cd odoo-laravel-nfce
cp .env.example .env

# Sobe banco de dados e redis
docker-compose up -d

# Instala dependências do framework
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
```

## Deploy (Produção)

O projeto foi desenhado para rodar via Dokploy/Coolify. O arquivo `docker-compose.yml` já separa o serviço de API (`app`) do serviço de processamento de filas em background (`queue`), rodando sob a mesma rede Docker. As variáveis sensíveis como tokens e endpoints devem ser injetadas diretamente no painel do servidor.
