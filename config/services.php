<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'focus' => [
        'url'   => env('FOCUS_URL', 'https://homologacao.focusnfe.com.br'),
        'token' => env('FOCUS_TOKEN'),
    ],

    'odoo' => [
        'url'            => env('ODOO_URL'),
        'token'          => env('ODOO_TOKEN'),
        'webhook_secret' => env('ODOO_WEBHOOK_SECRET'),
    ],

    'fiscal' => [
        'uf'                    => env('FISCAL_UF', '13'),
        'serie_contingencia'    => env('FISCAL_SERIE_CONTINGENCIA', '600'),
        'cnpj_emitente'         => env('FISCAL_CNPJ_EMITENTE', '00000000000000'),
        'timezone'              => env('FISCAL_TIMEZONE', 'America/Manaus'),
        'qrcode_url_producao'   => env('FISCAL_QRCODE_URL_PRODUCAO', 'https://sistemas.sefaz.am.gov.br/nfceweb/consultarNFCe.jsp'),
        'qrcode_url_homologacao' => env('FISCAL_QRCODE_URL_HOMOLOGACAO', 'https://homnfce.sefaz.am.gov.br/nfceweb/consultarNFCe.jsp'),
        'url_consulta'          => env('FISCAL_URL_CONSULTA', 'http://www.nfce.sefaz.am.gov.br/consumidor/'),
    ],

];
