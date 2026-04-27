# Odoo Laravel NFC-e Middleware 🚀

Este é um projeto construído em **Laravel 11** que atua como um "Middleware" (uma ponte de comunicação) entre o ERP **Odoo** (Ponto de Venda) e a **Focus NFe** (API de emissão fiscal).

## 💡 Por que este projeto existe?
Sistemas gringos como o Odoo são excelentes para gerenciar a loja, mas muitas vezes não possuem a integração nativa com as regras fiscais de todos os estados do Brasil (como a emissão de NFC-e para o estado do Amazonas). 

Ao invés de tentar alterar o código fonte do Odoo (o que daria muita dor de cabeça em futuras atualizações), eu decidi criar essa API em Laravel para agir de forma independente. O Odoo apenas "avisa" o Laravel que uma venda foi feita, e o Laravel cuida de toda a burocracia fiscal pesada.

## ⚙️ Como funciona? (O Fluxo)
1. **Webhook:** O Caixa finaliza a venda no Odoo POS e manda um JSON via Webhook pra cá.
2. **Tratamento:** O Laravel recebe os dados, faz as validações e converte para o formato que a SEFAZ e a Focus exigem (lidando com CFOP, NCM, etc).
3. **Filas (Queues):** Para o caixa não ficar "travado" esperando a nota ser emitida, usamos filas com Redis. O disparo para a Focus acontece em background.
4. **Contingência Offline:** Se o sistema da Sefaz cair, o sistema tem regras locais para gerar a chave de acesso e salvar o payload para reenvio automático depois.
5. **Retorno (Callback):** Assim que a Focus aprova, devolvemos a Chave de Acesso e o QR Code em Base64 de volta pro Odoo imprimir no cupom térmico do cliente.

## 🛠️ Tecnologias Utilizadas
* **PHP 8.2 / Laravel 11:** Framework principal pela velocidade de desenvolvimento e estrutura limpa.
* **Redis:** Para processamento assíncrono de notas (Queues/Jobs).
* **MariaDB:** Para manter um histórico de "backup" das notas enviadas.
* **Docker / Docker Compose:** Para garantir que o projeto rode em qualquer lugar exatamente do mesmo jeito, facilitando o deploy.

## 🚀 Como rodar o projeto
Para subir o ambiente completo localmente, basta usar o Docker:

```bash
# 1. Clone o repositório
git clone https://github.com/SEU-USER/odoo-laravel-nfce.git

# 2. Copie o arquivo de variáveis de ambiente
cp .env.example .env

# 3. Suba os containers do Docker
docker-compose up -d

# 4. Instale as dependências
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
```

## 📝 O que eu aprendi
Durante a criação deste projeto, tive que lidar com desafios interessantes de comunicação assíncrona (webhooks e polling), manipulação de strings em payloads complexos (como preenchimentos matemáticos e regras do dígito verificador fiscal), e orquestração de containers com redes independentes no Docker.
