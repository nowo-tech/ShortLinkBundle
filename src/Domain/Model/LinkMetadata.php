<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use JsonException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function count;
use function is_int;
use function is_scalar;
use function json_encode;
use function preg_match;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Free host data on a link: at most 16 keys `^[a-z][a-z0-9_]{0,31}$`, scalar or null values, at most 2 KiB as JSON.
 * Never queried and never shown unescaped.
 */
final readonly class LinkMetadata
{
    /**
     * @param array<string, scalar|null> $values
     */
    private function __construct(public array $values)
    {
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidShortLinkArgumentException when a key, a value or the size breaks the rule
     */
    public static function fromArray(array $values): self
    {
        if (count($values) > 16) {
            throw new InvalidShortLinkArgumentException('Link metadata has at most 16 keys.');
        }
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_int($key) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) !== 1) {
                throw new InvalidShortLinkArgumentException('Link metadata keys match ^[a-z][a-z0-9_]{0,31}$.');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidShortLinkArgumentException('Link metadata values are scalars or null.');
            }
            $clean[$key] = $value;
        }
        if (strlen(self::encode($clean)) > 2048) {
            throw new InvalidShortLinkArgumentException('Link metadata is at most 2 KiB as JSON.');
        }

        return new self($clean);
    }

    /**
     * JSON of the values (`{}` when empty).
     */
    public function toJson(): string
    {
        return self::encode($this->values);
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @throws InvalidShortLinkArgumentException when a string is not valid UTF-8
     */
    private static function encode(array $values): string
    {
        if ($values === []) {
            return '{}';
        }
        try {
            return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidShortLinkArgumentException('Link metadata strings are valid UTF-8 and numbers are finite.');
        }
    }
}
