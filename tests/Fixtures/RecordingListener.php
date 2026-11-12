<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

/**
 * Records every short-link event it is subscribed to (test-only state).
 */
final class RecordingListener
{
    /** @var list<object> */
    public array $events = [];

    public function onEvent(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $found[] = $event;
            }
        }

        return $found;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
