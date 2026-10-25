<?php

namespace Glitter\Connect\Services;

final class ConnectorRegistry
{
    private array $connectors = [];

    public function register(string $name, object $connector): void
    {
        $this->connectors[$name] = $connector;
    }

    public function get(string $name): ?object
    {
        return $this->connectors[$name] ?? null;
    }

    public function names(): array
    {
        return array_keys($this->connectors);
    }
}
