<?php

namespace Radiergummi\LaravelRls\Events;

/**
 * Fired whenever RLS is bypassed via Rls::withoutRls()/system(). Carries the
 * (required) reason so bypasses are observable — logged, counted, or alerted on.
 */
class RlsBypassed
{
    public function __construct(public readonly string $reason) {}
}
