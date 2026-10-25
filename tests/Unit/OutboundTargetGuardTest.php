<?php

namespace Glitter\Connect\Tests\Unit;

use Glitter\Connect\Services\OutboundTargetGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OutboundTargetGuardTest extends TestCase
{
    public function test_trailing_dot_hostname_is_rejected_before_dns_resolution(): void
    {
        $resolverCalled = false;
        $guard = new OutboundTargetGuard();

        try {
            $guard->resolvePublicTarget('https://receiver.example./hook', static function () use (&$resolverCalled): array {
                $resolverCalled = true;

                return [];
            });
            self::fail('Trailing-dot hostname was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Outbound hostname must not end with a dot', $exception->getMessage());
        }

        self::assertFalse($resolverCalled);
    }

    public function test_public_https_hostname_is_allowed_and_pinned_to_resolved_address(): void
    {
        $resolvedHost = null;
        $guard = new OutboundTargetGuard();

        $target = $guard->resolvePublicTarget('https://Receiver.Example/hook', static function (string $host, int $type) use (&$resolvedHost): array {
            $resolvedHost = $host;

            return [['ip' => '8.8.8.8', 'type' => 'A']];
        });

        self::assertSame('receiver.example', $resolvedHost);
        self::assertSame(['host' => 'receiver.example', 'ip' => '8.8.8.8', 'port' => 443], $target);
    }

    public function test_noncanonical_hostname_forms_are_rejected_before_dns_resolution(): void
    {
        foreach (['https://127.1/hook', 'https://example%2ecom/hook', 'https://münich.example/hook'] as $url) {
            $resolverCalled = false;
            try {
                (new OutboundTargetGuard())->resolvePublicTarget($url, static function () use (&$resolverCalled): array {
                    $resolverCalled = true;

                    return [];
                });
                self::fail('Noncanonical hostname was accepted: '.$url);
            } catch (RuntimeException) {
                self::assertFalse($resolverCalled);
            }
        }
    }
}
