<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Connection;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;
use Radiergummi\LaravelRls\Exceptions\ResolverCollision;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Tests\TestCase;

class ResolverCollisionTest extends TestCase
{
    public function test_detects_foreign_resolver_with_default_connection_class(): void
    {
        $foreign = fn () => 'foreign';

        $this->assertTrue(
            RlsServiceProvider::detectResolverCollision($foreign, null, RlsPostgresConnection::class),
        );
    }

    public function test_no_collision_when_no_existing_resolver(): void
    {
        $this->assertFalse(
            RlsServiceProvider::detectResolverCollision(null, null, RlsPostgresConnection::class),
        );
    }

    public function test_no_collision_with_our_own_lingering_resolver(): void
    {
        $own = fn () => 'ours';

        $this->assertFalse(
            RlsServiceProvider::detectResolverCollision($own, $own, RlsPostgresConnection::class),
        );
    }

    public function test_no_collision_when_connection_class_is_composed(): void
    {
        $foreign = fn () => 'foreign';

        $this->assertFalse(
            RlsServiceProvider::detectResolverCollision($foreign, null, 'App\\ComposedConnection'),
        );
    }

    public function test_boot_registration_throws_on_a_real_foreign_resolver(): void
    {
        $ref = new \ReflectionProperty(RlsServiceProvider::class, 'ownResolver');
        $prevOwn = $ref->getValue();
        $prevResolver = Connection::getResolver('pgsql');

        try {
            // Simulate a fresh process where another package registered first
            // and the user never set connection_class to compose with it.
            $ref->setValue(null, null);
            Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => 'foreign');
            config(['rls.connection_class' => RlsPostgresConnection::class]);

            $this->expectException(ResolverCollision::class);

            (new RlsServiceProvider($this->app))->registerConnectionResolver();
        } finally {
            if ($prevResolver !== null) {
                Connection::resolverFor('pgsql', $prevResolver);
            }
            $ref->setValue(null, $prevOwn);
        }
    }
}
