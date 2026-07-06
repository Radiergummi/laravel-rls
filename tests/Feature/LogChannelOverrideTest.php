<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;

#[TestDox('RLS Log Channel Override')]
class LogChannelOverrideTest extends TestCase
{
    #[Test]
    #[TestDox('leaves an app-defined rls channel untouched')]
    public function leaves_an_app_defined_channel_untouched(): void
    {
        $this->assertSame(
            ['driver' => 'single', 'path' => '/tmp/app-rls.log'],
            config('logging.channels.rls'),
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Configured before the provider boots, so registerLogChannel() must not overwrite it.
        config(['logging.channels.rls' => ['driver' => 'single', 'path' => '/tmp/app-rls.log']]);
    }
}
