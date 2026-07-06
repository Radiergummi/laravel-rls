<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;

#[TestDox('RLS Log Channel')]
class LogChannelTest extends TestCase
{
    #[Test]
    #[TestDox('registers a default rls channel forwarding to the default channel')]
    public function registers_a_default_rls_channel(): void
    {
        $this->assertSame(
            [
                'driver' => 'stack',
                'channels' => [config('logging.default')],
                'ignore_exceptions' => false,
            ],
            config('logging.channels.rls'),
        );
    }
}
