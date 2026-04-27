<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the Odoo webhook endpoint using HMAC-SHA256 signature verification.
 *
 * Odoo must send a shared secret in the `X-Webhook-Token` header.
 * This is configurable via ODOO_WEBHOOK_SECRET in .env.
 *
 * Usage in routes/api.php:
 *   Route::post('/odoo/webhook', ...)->middleware('webhook.verify');
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.odoo.webhook_secret');

        // If no secret is configured, skip verification (dev/local environment)
        if (empty($secret)) {
            Log::warning('[WEBHOOK-SECURITY] ODOO_WEBHOOK_SECRET not set. Skipping signature verification.');

            return $next($request);
        }

        $token = $request->header('X-Webhook-Token');

        if (! $token || ! hash_equals($secret, $token)) {
            Log::warning('[WEBHOOK-SECURITY] Unauthorized webhook attempt rejected.', [
                'ip'     => $request->ip(),
                'header' => $token ? 'present-but-invalid' : 'missing',
            ]);

            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
