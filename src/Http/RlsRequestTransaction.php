<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in middleware that wraps the request in a single transaction, so RLS context is injected once
 * for the whole request instead of once per un-batched query.
 * Apply per-route to read-heavy endpoints.
 */
class RlsRequestTransaction
{
    /**
     * @param Closure(Request): mixed $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        return DB::transaction(static fn() => $next($request));
    }
}
