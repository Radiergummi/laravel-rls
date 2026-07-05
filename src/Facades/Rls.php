<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Context\RlsContext;
use Radiergummi\LaravelRls\Context\RlsManager;

/**
 * @method static void                 push(RlsContext $context)
 * @method static void                 pop()
 * @method static RlsContext|null      current()
 * @method static bool                 hasContext()
 * @method static array<string, mixed> context()
 * @method static mixed                get(string $key)
 * @method static void                 set(string $key, mixed $value)
 * @method static mixed                isolateTo(array<string, mixed> $context, ?Closure(): mixed $callback = null)
 * @method static mixed                withoutIsolation(string $reason, Closure(): mixed $callback)
 * @method static mixed                system(string $reason, Closure(): mixed $callback)
 * @method static void                 resolveContextUsing(Closure(mixed): mixed $resolver)
 * @method static void                 establishFromUser(mixed $user)
 * @method static void                 defineContext(Closure(ContextSchema): mixed $callback)
 * @method static ContextSchema|null   schema()
 * @method static void                 forget()
 *
 * @see RlsManager
 */
class Rls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RlsManager::class;
    }
}
