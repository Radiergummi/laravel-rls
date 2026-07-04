<?php

namespace Radiergummi\LaravelRls\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void push(\Radiergummi\LaravelRls\Context\RlsContext $context)
 * @method static void pop()
 * @method static \Radiergummi\LaravelRls\Context\RlsContext|null current()
 * @method static bool hasContext()
 * @method static array context()
 * @method static mixed get(string $key)
 * @method static void set(string $key, mixed $value)
 * @method static mixed actingAs(array $context, ?\Closure $callback = null)
 * @method static mixed withoutRls(string $reason, \Closure $callback)
 * @method static mixed system(string $reason, \Closure $callback)
 * @method static void resolveContextUsing(\Closure $resolver)
 * @method static void establishFromUser(mixed $user)
 * @method static void defineContext(\Closure $callback)
 * @method static \Radiergummi\LaravelRls\Context\ContextSchema|null schema()
 * @method static void forget()
 *
 * @see \Radiergummi\LaravelRls\Context\RlsManager
 */
class Rls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rls';
    }
}
