<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkMetadata;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Contract §3.1 value objects and the target normalisation / refusal matrix of §9.
 */
final class ValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function normalisedTargets(): iterable
    {
        yield 'uppercase scheme and host, default port' => ['HTTPS://Example.COM:443/A?b=C#D', 'https://example.com/A?b=C#D', 'example.com'];
        yield 'http default port' => ['http://example.com:80', 'http://example.com', 'example.com'];
        yield 'other port kept' => ['https://example.com:8443/x', 'https://example.com:8443/x', 'example.com'];
        yield 'trailing dot' => ['https://example.com./', 'https://example.com/', 'example.com'];
        yield 'IDN to punycode' => ['https://bücher.example/ü', 'https://xn--bcher-kva.example/ü', 'xn--bcher-kva.example'];
        yield 'IPv6 literal' => ['http://[2001:DB8::1]/', 'http://[2001:db8::1]/', '2001:db8::1'];
        yield 'surrounding whitespace trimmed' => ["  https://example.com/  \n", 'https://example.com/', 'example.com'];
    }

    #[DataProvider('normalisedTargets')]
    public function testTargetsAreNormalised(string $input, string $expected, string $host): void
    {
        $target = TargetUrl::fromString($input);

        self::assertSame($expected, $target->toString());
        self::assertSame($expected, $target->value);
        self::assertSame($host, $target->host());
    }

    /**
     * @return iterable<string, array{string, TargetRefusal}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'empty' => ['', TargetRefusal::Scheme];
        yield 'javascript' => ['javascript:alert(1)', TargetRefusal::Scheme];
        yield 'mixed-case javascript' => ['JaVaScRiPt:alert(1)', TargetRefusal::Scheme];
        yield 'data' => ['data:text/html,<script>', TargetRefusal::Scheme];
        yield 'ftp' => ['ftp://example.com/', TargetRefusal::Scheme];
        yield 'mailto' => ['mailto:a@example.com', TargetRefusal::Scheme];
        yield 'relative' => ['/path', TargetRefusal::Scheme];
        yield 'protocol-relative' => ['//evil.example', TargetRefusal::Scheme];
        yield 'backslashes' => ['http:\\\\evil.example', TargetRefusal::Scheme];
        yield 'backslash in path' => ['https://example.com\\@evil.example', TargetRefusal::Scheme];
        yield 'tab inside' => ["https://exa\tmple.com", TargetRefusal::Scheme];
        yield 'newline inside (header injection)' => ["https://example.com/\r\nSet-Cookie: x=1", TargetRefusal::Scheme];
        yield 'space inside' => ['https://example.com/a b', TargetRefusal::Scheme];
        yield 'no-break space inside' => ["https://example.com/a\u{00A0}b", TargetRefusal::Scheme];
        yield 'no host' => ['https:///path', TargetRefusal::Scheme];
        yield 'bad port' => ['https://example.com:99999/', TargetRefusal::Scheme];
        yield 'bad host characters' => ['https://exa"mple.com/', TargetRefusal::Scheme];
        yield 'bad IDN' => ['https://' . str_repeat('ü', 70) . '.example/', TargetRefusal::Scheme];
        yield 'userinfo' => ['https://user:pass@example.com/', TargetRefusal::Userinfo];
        yield 'user only' => ['https://user@example.com/', TargetRefusal::Userinfo];
        yield 'too long' => ['https://example.com/' . str_repeat('a', 2030), TargetRefusal::Length];
    }

    #[DataProvider('refusedTargets')]
    public function testUnsafeTargetsAreRefusedWithTheirReason(string $input, TargetRefusal $refusal): void
    {
        try {
            TargetUrl::fromString($input);
            self::fail('Refused: ' . $input);
        } catch (TargetRefusedException $exception) {
            self::assertSame($refusal, $exception->refusal);
            self::assertSame($refusal->message(), $exception->getMessage());
        }
    }

    public function testTheConfiguredMaximumLengthApplies(): void
    {
        self::assertSame('/', TargetUrl::fromString('https://example.com')->path());
        self::assertSame('/s/x', TargetUrl::fromString('https://example.com/s/x?y')->path());
        $this->expectException(TargetRefusedException::class);
        TargetUrl::fromString('https://example.com/' . str_repeat('a', 100), 64);
    }

    public function testEveryRefusalHasAMessage(): void
    {
        foreach (TargetRefusal::cases() as $refusal) {
            self::assertNotSame('', $refusal->message());
        }
        self::assertTrue(TargetVerdict::allow()->allowed);
        self::assertSame(TargetRefusal::SelfLoop, TargetVerdict::refuse(TargetRefusal::SelfLoop)->refusal);
    }

    public function testShortCodes(): void
    {
        self::assertSame('aB3xY9k', ShortCode::random('aB3xY9k')->value);
        self::assertFalse(ShortCode::random('aB3xY9k')->isCustom());
        self::assertSame('launch-2026', ShortCode::custom('Launch-2026')->value);
        self::assertTrue(ShortCode::fromStored('launch', true)->isCustom());
        self::assertFalse(ShortCode::fromStored('AbCdEf1', false)->isCustom());
        self::assertTrue(ShortCode::random('aB3xY9k')->equals(ShortCode::random('aB3xY9k')));
        self::assertFalse(ShortCode::random('aB3xY9k')->equals(ShortCode::random('AB3XY9K')));

        foreach (['-abc', 'abc-', 'a--b', 'ab', str_repeat('a', 33), '_admin', '.env', 'ab c', 'añb'] as $custom) {
            $this->assertInvalid(static fn (): ShortCode => ShortCode::custom($custom), $custom);
        }
        foreach (['abc12', str_repeat('a', 17), 'abc-123'] as $random) {
            $this->assertInvalid(static fn (): ShortCode => ShortCode::random($random), $random);
        }
        $this->assertInvalid(static fn (): ShortCode => ShortCode::fromStored('Bad--Code', true), 'stored custom');
    }

    public function testIdentifiers(): void
    {
        $id = ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001');
        self::assertTrue($id->equals(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001')));
        $this->assertInvalid(static fn (): ShortLinkId => ShortLinkId::fromString('0190A6D0-0000-7000-8000-000000000001'), 'uppercase id');

        self::assertSame('default', TenantKey::default()->value);
        self::assertTrue(TenantKey::fromString('org:1')->equals(TenantKey::fromString('org:1')));
        self::assertFalse(TenantKey::fromString('Org')->equals(TenantKey::fromString('org')));
        foreach (['', ' org', str_repeat('a', 65), 'a/b'] as $tenant) {
            $this->assertInvalid(static fn (): TenantKey => TenantKey::fromString($tenant), $tenant);
        }

        self::assertTrue(ActorRef::fromString('UserA')->equals(ActorRef::fromString('UserA')));
        self::assertFalse(ActorRef::fromString('UserA')->equals(ActorRef::fromString('usera')));
        foreach (['', str_repeat('a', 129), "a\nb"] as $actor) {
            $this->assertInvalid(static fn (): ActorRef => ActorRef::fromString($actor), 'actor');
        }

        self::assertSame('qr_code', LinkKind::fromString('qr_code')->value);
        self::assertTrue(LinkKind::email()->equals(LinkKind::fromString('email')));
        self::assertFalse(LinkKind::manual()->equals(LinkKind::email()));
        $this->assertInvalid(static fn (): LinkKind => LinkKind::fromString('QR'), 'kind');
        self::assertSame('manual', (new CreateShortLink('https://example.com'))->kind()->value);
    }

    public function testSourcesTitlesAndQueries(): void
    {
        $source = new LinkSource('send-1', "  Button\u{0007}  ", 0);
        self::assertSame('Button', $source->label);
        self::assertNull((new LinkSource('send-1', "\u{202E}"))->label);
        self::assertNull((new LinkSource('send-1'))->label);
        $this->assertInvalid(static fn (): LinkSource => new LinkSource('send 1'), 'ref');
        $this->assertInvalid(static fn (): LinkSource => new LinkSource('s', null, 1000), 'position');
        $this->assertInvalid(static fn (): LinkSource => new LinkSource('s', null, -1), 'negative position');
        $this->assertInvalid(static fn (): LinkSource => new LinkSource('s', str_repeat('é', 129)), 'label');

        self::assertNull(LinkTitle::fromString(null));
        self::assertNull(LinkTitle::fromString("  \u{0000} "));
        self::assertNull(LinkTitle::fromString("bad \xC3\x28 utf8"));
        self::assertSame(str_repeat('é', 255), LinkTitle::fromString(str_repeat('é', 255))?->value);
        $this->assertInvalid(static fn (): ?LinkTitle => LinkTitle::fromString(str_repeat('é', 256)), 'title');

        self::assertNull((new ShortLinkListQuery(search: '  '))->search);
        self::assertSame('abc', (new ShortLinkListQuery(search: ' abc '))->search);
        $this->assertInvalid(static fn (): ShortLinkListQuery => new ShortLinkListQuery(limit: 0), 'limit 0');
        $this->assertInvalid(static fn (): ShortLinkListQuery => new ShortLinkListQuery(limit: 101), 'limit 101');
        $this->assertInvalid(static fn (): ShortLinkListQuery => new ShortLinkListQuery(search: str_repeat('a', 101)), 'search');
    }

    public function testMetadata(): void
    {
        self::assertSame('{}', LinkMetadata::fromArray([])->toJson());
        self::assertSame('{"a":1,"b":"x/y","c":null,"d":true}', LinkMetadata::fromArray(['a' => 1, 'b' => 'x/y', 'c' => null, 'd' => true])->toJson());

        $many = [];
        for ($i = 0; $i < 17; ++$i) {
            $many['k' . $i] = $i;
        }
        $this->assertInvalid(static fn (): LinkMetadata => LinkMetadata::fromArray($many), 'too many keys');
        $this->assertInvalid(static fn (): LinkMetadata => LinkMetadata::fromArray(['Bad' => 1]), 'key');
        $this->assertInvalid(static fn (): LinkMetadata => LinkMetadata::fromArray([0 => 1]), 'int key');
        $this->assertInvalid(static fn (): LinkMetadata => LinkMetadata::fromArray(['a' => [1]]), 'array value');
        $this->assertInvalid(static fn (): LinkMetadata => LinkMetadata::fromArray(['a' => str_repeat('x', 2100)]), 'size');
        $this->assertInvalid(static fn (): LinkMetadata => LinkMetadata::fromArray(['a' => "\xC3\x28"]), 'utf-8');
    }

    public function testExpiryIsUtcWithSecondPrecision(): void
    {
        $expiry = Expiry::fromDateTime(new DateTimeImmutable('2026-11-12 12:00:00.750', new DateTimeZone('Europe/Madrid')));

        self::assertSame('2026-11-12 11:00:00.000000 UTC', $expiry->at->format('Y-m-d H:i:s.u e'));
        self::assertTrue($expiry->isReachedAt(new DateTimeImmutable('2026-11-12 11:00:00', new DateTimeZone('UTC'))));
        self::assertFalse($expiry->isReachedAt(new DateTimeImmutable('2026-11-12 10:59:59', new DateTimeZone('UTC'))));
    }

    private function assertInvalid(Closure $build, string $label): void
    {
        try {
            $build();
            self::fail('Invalid: ' . $label);
        } catch (InvalidShortLinkArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
