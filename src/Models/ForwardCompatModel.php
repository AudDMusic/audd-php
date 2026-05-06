<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * Base for every typed model. Forward-compat: every model accepts and round-trips
 * unknown server fields via the `$extras` array, accessed transparently via __get
 * for fields the typed surface doesn't yet know about. See design spec §5.2.
 */
abstract class ForwardCompatModel
{
    /**
     * Unknown server-side keys not explicitly typed on this model.
     *
     * @var array<string, mixed>
     */
    public readonly array $extras;

    /**
     * Full unparsed JSON payload — belt-and-suspenders for users who want
     * zero-parsing dependency on us.
     *
     * @var array<string, mixed>
     */
    public readonly array $rawResponse;

    /**
     * @param array<string, mixed> $extras
     * @param array<string, mixed> $rawResponse
     */
    protected function __construct(array $extras, array $rawResponse)
    {
        $this->extras = $extras;
        $this->rawResponse = $rawResponse;
    }

    /**
     * Forward-compat magic getter: undeclared property access falls through to extras.
     *
     * If the API later adds a field this SDK doesn't know about, users can
     * still access it as `$result->newField` without waiting for an SDK release.
     *
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->extras[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->extras);
    }

    /**
     * Round-trip the model back to an associative array preserving extras.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rawResponse;
    }

    /**
     * Build the extras dict by removing all known keys from the raw payload.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $knownKeys
     *
     * @return array<string, mixed>
     */
    protected static function extractExtras(array $payload, array $knownKeys): array
    {
        $extras = $payload;
        foreach ($knownKeys as $k) {
            unset($extras[$k]);
        }
        return $extras;
    }
}
