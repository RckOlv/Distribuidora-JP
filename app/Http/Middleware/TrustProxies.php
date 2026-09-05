<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

class TrustProxies extends \Illuminate\Http\Middleware\TrustProxies
{
    /**
     * Setters de confianza amplia detrás de un túnel/proxy (p. ej. Dev Tunnels).
     * Solo en entorno local/desarrollo; en producción no se confía ningún proxy.
     *
     * El valor `'*'` es interpretado por el middleware como "confiar la IP que
     * llamó al servidor", que es exactamente el caso de una relay de túnel.
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * {@inheritdoc}
     */
    protected function proxies(): string|array|null
    {
        return app()->isLocal() ? '*' : null;
    }
}