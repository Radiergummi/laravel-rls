<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Context\RlsContext;
use Radiergummi\LaravelRls\Context\RlsManager;

/**
 * @method static void               push(RlsContext $context)
 * @method static void               pop()
 * @method static RlsContext|null    current()
 * @method static bool               hasContext()
 * @method static array              context()
 * @method static mixed              get(string $key)
 * @method static void               set(string $key, mixed $value)
 * @method static mixed              actingAs(array $context, ?Closure $callback = null)
 * @method static mixed              withoutRls(string $reason, Closure $callback)
 * @method static mixed              system(string $reason, Closure $callback)
 * @method static void               resolveContextUsing(Closure $resolver)
 * @method static void               establishFromUser(mixed $user)
 * @method static void               defineContext(Closure $callback)
 * @method static ContextSchema|null schema()
 * @method static void               forget()
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
