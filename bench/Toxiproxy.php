<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal Toxiproxy admin-API client for the latency sweep. Network calls are exercised only when
 * the proxy is up; payload() is pure and unit-tested.
 */
final class Toxiproxy
{
    private const TOXIC = 'latency_downstream';

    public function __construct(private readonly string $admin = 'http://127.0.0.1:8474') {}

    public function available(): bool
    {
        try {
            return Http::timeout(2)->get("{$this->admin}/version")->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function reset(string $name, string $listen, string $upstream): void
    {
        // Idempotent: drop any existing proxy (a 404 when absent is expected and fine), then
        // create it. The create must succeed — throw so a broken admin API surfaces instead of a
        // silent no-op that would let the sweep run with no proxy and report meaningless numbers.
        Http::delete("{$this->admin}/proxies/{$name}");
        Http::post("{$this->admin}/proxies", [
            'name' => $name,
            'listen' => $listen,
            'upstream' => $upstream,
            'enabled' => true,
        ])->throw();
    }

    public function setLatency(string $name, int $ms, int $jitterMs): void
    {
        // Idempotent: drop any existing latency toxic (404 when absent is fine), then (re)create it
        // with the new attributes. The create must succeed — throw so a failed injection surfaces
        // rather than silently measuring zero injected latency.
        Http::delete("{$this->admin}/proxies/{$name}/toxics/" . self::TOXIC);
        Http::post("{$this->admin}/proxies/{$name}/toxics", $this->payload($ms, $jitterMs))->throw();
    }

    public function clear(string $name): void
    {
        Http::delete("{$this->admin}/proxies/{$name}/toxics/" . self::TOXIC);
    }

    /**
     * The downstream latency toxic body.
     *
     * @return array{name:string,type:string,stream:string,attributes:array{latency:int,jitter:int}}
     */
    public function payload(int $ms, int $jitterMs): array
    {
        return [
            'name' => self::TOXIC,
            'type' => 'latency',
            'stream' => 'downstream',
            'attributes' => ['latency' => $ms, 'jitter' => $jitterMs],
        ];
    }
}
