<?php

declare(strict_types=1);

namespace AudD\Models;

/**
 * Base for every typed model. Forward-compat: every model accepts and round-trips
 * unknown server fields via the `$extras` array, accessed transparently via __get
 * for fields the typed surface doesn't yet know about.
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

    /**
     * Coerce an arbitrary payload value to a string, or null.
     *
     * A successful response must never throw or warn on a wrong-typed field:
     * arrays/objects and null degrade to null instead of triggering an
     * "Array to string conversion" warning (which `failOnWarning` test
     * configs and warning-to-exception handlers turn into a throw).
     */
    protected static function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        // Arrays, objects, resources: not representable as a scalar string.
        return null;
    }

    /**
     * Coerce an arbitrary payload value to an int, or null.
     *
     * Non-numeric strings, arrays, objects and null degrade to null rather
     * than coercing to a misleading 0.
     */
    protected static function asInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Coerce an arbitrary payload value to a float, or null.
     *
     * Non-numeric strings, arrays, objects and null degrade to null rather
     * than coercing to a misleading 0.0.
     */
    protected static function asFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }

    /**
     * Coerce an arbitrary payload value to a bool, or null.
     */
    protected static function asBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '' || $v === 'false' || $v === '0' || $v === 'no') {
                return false;
            }
            return true;
        }
        return null;
    }
}
