<?php

namespace Radiergummi\Rls\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void push(\Radiergummi\Rls\Context\RlsContext $context)
 * @method static void pop()
 * @method static \Radiergummi\Rls\Context\RlsContext|null current()
 * @method static bool hasContext()
 * @method static array context()
 * @method static mixed get(string $key)
 * @method static void set(string $key, mixed $value)
 * @method static mixed actingAs(array $context, ?\Closure $callback = null)
 * @method static mixed withoutRls(string $reason, \Closure $callback)
 * @method static mixed system(string $reason, \Closure $callback)
 * @method static void forget()
 *
 * @see \Radiergummi\Rls\Context\RlsManager
 */
class Rls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rls';
    }
}
