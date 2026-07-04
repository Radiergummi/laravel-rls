<?php

namespace Radiergummi\Rls\Http;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in middleware that wraps the request in a single transaction, so RLS
 * context is injected once for the whole request instead of once per
 * un-batched query. Apply per-route to read-heavy endpoints.
 */
class RlsRequestTransaction
{
    public function handle($request, Closure $next): mixed
    {
        return DB::transaction(fn () => $next($request));
    }
}
