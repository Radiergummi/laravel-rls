<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\EndpointConfig;

#[TestDox('Bench EndpointConfig')]
class EndpointConfigTest extends TestCase
{
    #[Test]
    #[TestDox('matrix() yields six configs and flags pgbouncer-session unsafe by construction')]
    public function matrix_flags_the_unsafe_config(): void
    {
        $matrix = EndpointConfig::matrix();

        $this->assertCount(6, $matrix);

        // Direct configs are safe and measured.
        $this->assertFalse($matrix[0]->unsafe);
        $this->assertSame('direct·transaction·wrap', $matrix[0]->label);
        $this->assertFalse($matrix[0]->oneTransaction);
        $this->assertTrue($matrix[1]->oneTransaction); // request boundary = one txn

        // Config 6 is unsafe by construction.
        $this->assertTrue($matrix[5]->unsafe);
        $this->assertSame('session', $matrix[5]->strategy);
        $this->assertSame('pgsql_pgbouncer', $matrix[5]->connectionName);
    }
}
