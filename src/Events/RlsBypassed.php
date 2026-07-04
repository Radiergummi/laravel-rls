<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Events;

/**
 * Fired whenever RLS is bypassed via {@see Rls::withoutRls()}/{@see Rls::system()}.
 *
 * Carries the (required) reason, so bypasses are observable: logged, counted, or alerted on.
 */
readonly class RlsBypassed
{
    public function __construct(public string $reason) {}
}
