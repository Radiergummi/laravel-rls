<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Http\RlsRequestTransaction;
use Radiergummi\LaravelRls\Tests\TestCase;

class RequestTransactionMiddlewareTest extends TestCase
{
    #[Test]
    public function middleware_opens_a_transaction_for_the_request(): void
    {
        // RefreshDatabase holds the connection at level 1 as a baseline.
        $this->get('/rls-bare')->assertOk()->assertSee('1');

        // The middleware adds exactly one transaction boundary.
        $this->get('/rls-wrapped')->assertOk()->assertSee('2');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/rls-bare', fn() => (string) DB::transactionLevel());
        $router
            ->middleware(RlsRequestTransaction::class)
            ->get('/rls-wrapped', fn() => (string) DB::transactionLevel());
    }
}
