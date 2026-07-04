<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Radiergummi\Rls\Http\RlsRequestTransaction;
use Radiergummi\Rls\Tests\TestCase;

class RequestTransactionMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/rls-bare', fn () => (string) DB::transactionLevel());
        $router->middleware(RlsRequestTransaction::class)
            ->get('/rls-wrapped', fn () => (string) DB::transactionLevel());
    }

    public function test_middleware_opens_a_transaction_for_the_request(): void
    {
        // RefreshDatabase holds the connection at level 1 as a baseline.
        $this->get('/rls-bare')->assertOk()->assertSee('1');

        // The middleware adds exactly one transaction boundary.
        $this->get('/rls-wrapped')->assertOk()->assertSee('2');
    }
}
