<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Application\Guard;

use Nowo\ShortLinkBundle\Application\Guard\OwnHostGuard;
use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function idn_to_ascii;

use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * `target.allow_own_host`: own origins come from configuration only; redirect paths, look-alikes, other ports and
 * other schemes never pass as own.
 */
final class OwnHostGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function ownTargets(): iterable
    {
        yield 'public base origin' => ['https://beacon.internal:8443/organizations/x/mail?c=1#f'];
        yield 'public base origin, root' => ['https://beacon.internal:8443'];
        yield 'mixed case' => ['HTTPS://BEACON.Internal:8443/Organizations'];
        yield 'trailing dot' => ['https://beacon.internal.:8443/x'];
        yield 'own host http' => ['http://localhost/x'];
        yield 'own host https' => ['https://localhost/x'];
        yield 'own host explicit default port' => ['http://localhost:80/x'];
        yield 'own host, mixed case and trailing dot' => ['https://LINKS.Example./docs'];
        yield 'a path that only starts like the prefix' => ['https://links.example/sx/1'];
        yield 'the prefix in the query' => ['https://links.example/docs?next=/s/abc'];
        yield 'the prefix in the fragment' => ['https://links.example/docs#/s/abc'];
    }

    #[DataProvider('ownTargets')]
    public function testOwnOriginsAreAllowedWithoutTheInnerGuard(string $url): void
    {
        $inner = $this->inner();
        foreach ([GuardStage::Save, GuardStage::Redirect] as $stage) {
            self::assertTrue($this->guard($inner)->check(TargetUrl::fromString($url), $stage)->allowed, $url);
        }
        self::assertSame([], $inner->seen, 'the inner guard is not asked');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loops(): iterable
    {
        yield 'a short link' => ['https://beacon.internal:8443/s/abc1234'];
        yield 'the prefix itself' => ['https://beacon.internal:8443/s'];
        yield 'the prefix with a slash' => ['https://beacon.internal:8443/s/'];
        yield 'mixed case host' => ['HTTPS://Beacon.INTERNAL:8443/s/abc'];
        yield 'mixed case path' => ['https://beacon.internal:8443/S/abc'];
        yield 'trailing dot' => ['https://localhost./s/abc'];
        yield 'percent-encoded' => ['https://localhost/%73/abc'];
        yield 'encoded slash' => ['https://localhost/s%2Fabc'];
        yield 'empty segments' => ['https://localhost//s//abc'];
        yield 'dot segments' => ['https://localhost/x/../s/abc'];
        yield 'second redirect prefix' => ['http://localhost/go/abc'];
        yield 'second prefix itself' => ['https://links.example/go'];
        yield 'with a query' => ['https://links.example/s/abc?x=1'];
    }

    #[DataProvider('loops')]
    public function testRedirectPathsOnOwnOriginsStayRefused(string $url): void
    {
        $inner   = $this->inner();
        $verdict = $this->guard($inner)->check(TargetUrl::fromString($url), GuardStage::Save);

        self::assertFalse($verdict->allowed, $url);
        self::assertSame(TargetRefusal::SelfLoop, $verdict->refusal, $url);
        self::assertSame([], $inner->seen);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignTargets(): iterable
    {
        yield 'base host on the default port' => ['https://beacon.internal/x'];
        yield 'base host, other port' => ['https://beacon.internal:8444/x'];
        yield 'base origin, other scheme' => ['http://beacon.internal:8443/x'];
        yield 'own host, other port' => ['http://localhost:8080/x'];
        yield 'own host, https on port 80' => ['https://localhost:80/x'];
        yield 'own host as a prefix of an evil host' => ['https://links.example.evil.test/x'];
        yield 'own host as a suffix' => ['https://evillinks.example/x'];
        yield 'subdomain of an own host' => ['https://sub.links.example/x'];
        yield 'IDN look-alike (Cyrillic i)' => ["https://l\u{0456}nks.example/x"];
        yield 'punycode look-alike' => ['https://' . self::lookAlike() . '/x'];
        yield 'encoded dot in the host' => ['https://links.example%2eevil.test/x'];
        yield 'encoded at sign in the host' => ['https://links.example%40evil.test/x'];
        yield 'suffix entry never matches' => ['https://a.suffix.example/x'];
        yield 'other host with own short path' => ['https://evil.test/s/abc'];
    }

    #[DataProvider('foreignTargets')]
    public function testEveryOtherTargetGoesToTheInnerGuardUnchanged(string $url): void
    {
        $inner   = $this->inner();
        $target  = TargetUrl::fromString($url);
        $verdict = $this->guard($inner)->check($target, GuardStage::Redirect);

        self::assertSame(TargetRefusal::PrivateHost, $verdict->refusal, $url);
        self::assertSame([[$target->toString(), GuardStage::Redirect]], $inner->seen);
    }

    /**
     * @return iterable<string, array{string, TargetRefusal}>
     */
    public static function malformed(): iterable
    {
        yield 'userinfo own@evil' => ['https://links.example@evil.test/x', TargetRefusal::Userinfo];
        yield 'userinfo evil@own' => ['https://evil.test@links.example/x', TargetRefusal::Userinfo];
        yield 'userinfo with port' => ['https://beacon.internal:8443@evil.test/', TargetRefusal::Userinfo];
        yield 'scheme-relative' => ['//links.example/x', TargetRefusal::Scheme];
        yield 'scheme-relative short link' => ['//localhost/s/abc', TargetRefusal::Scheme];
        yield 'backslash' => ['https://links.example\\@evil.test/', TargetRefusal::Scheme];
        yield 'triple slash' => ['https:///links.example/x', TargetRefusal::Scheme];
    }

    /**
     * Userinfo and scheme-relative forms never become a target, so no guard (own host or not) sees them.
     */
    #[DataProvider('malformed')]
    public function testMalformedTargetsNeverReachAGuard(string $url, TargetRefusal $refusal): void
    {
        try {
            TargetUrl::fromString($url);
            self::fail('accepted: ' . $url);
        } catch (TargetRefusedException $exception) {
            self::assertSame($refusal, $exception->refusal, $url);
        }
    }

    public function testOwnOriginsComeOnlyFromConfiguration(): void
    {
        $inner  = $this->inner();
        $target = TargetUrl::fromString('https://links.example/x');

        foreach ([null, '', 'not a url', 'ftp://links.example', 'https://own@links.example'] as $base) {
            self::assertFalse((new OwnHostGuard($inner, [], ['/s/'], $base))->check($target, GuardStage::Save)->allowed, (string) $base);
        }
        self::assertFalse((new OwnHostGuard($inner, ['', '.', '.links.example'], ['/s/']))->check($target, GuardStage::Save)->allowed, 'empty and suffix entries never match');
        self::assertTrue((new OwnHostGuard($inner, ['Links.Example.'], ['/s/']))->check($target, GuardStage::Save)->allowed, 'an env-provided entry is normalised');
        self::assertTrue((new OwnHostGuard($inner, [], ['/s/'], 'https://LINKS.example./app/'))->check($target, GuardStage::Save)->allowed, 'only the origin of the base counts');
        self::assertFalse((new OwnHostGuard($inner, [], ['/s/'], 'https://links.example:8443'))->check($target, GuardStage::Save)->allowed, 'the base port is part of the origin');
        self::assertTrue((new OwnHostGuard($inner, [], ['/s/'], "https://l\u{0456}nks.example"))->check(TargetUrl::fromString('https://' . self::lookAlike() . '/'), GuardStage::Save)->allowed, 'an IDN base matches its own punycode only');
        self::assertTrue((new OwnHostGuard($inner, [], ['/s/'], 'http://[::1]:8000'))->check(TargetUrl::fromString('http://[::1]:8000/x'), GuardStage::Save)->allowed, 'IPv6 base');
        self::assertFalse((new OwnHostGuard($inner, [], ['/s/'], 'http://[::1]:8000'))->check(TargetUrl::fromString('http://[::1]:8000/s/x'), GuardStage::Save)->allowed);
    }

    public function testTheOriginFillsTheDefaultPort(): void
    {
        self::assertSame('https://a.example:443', OwnHostGuard::origin(TargetUrl::fromString('https://A.example/x')));
        self::assertSame('http://a.example:80', OwnHostGuard::origin(TargetUrl::fromString('http://a.example:80')));
        self::assertSame('http://a.example:8080', OwnHostGuard::origin(TargetUrl::fromString('http://a.example:8080/')));
        self::assertSame('http://[::1]:80', OwnHostGuard::origin(TargetUrl::fromString('http://[::1]/')));
    }

    /**
     * Punycode of `lіnks.example` with a Cyrillic `і`.
     */
    private static function lookAlike(): string
    {
        $ascii = idn_to_ascii("l\u{0456}nks.example", IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        self::assertIsString($ascii);
        self::assertStringStartsWith('xn--', $ascii);

        return $ascii;
    }

    private function guard(TargetGuardInterface $inner): OwnHostGuard
    {
        return new OwnHostGuard($inner, ['localhost', 'links.example', '.suffix.example'], ['/s/', '/go/'], 'https://beacon.internal:8443');
    }

    /**
     * An inner guard that refuses everything (as OutboundUrlGuard refuses a private host) and records what it saw.
     *
     * @return object{seen: list<array{string, GuardStage}>}&TargetGuardInterface
     */
    private function inner(): TargetGuardInterface
    {
        return new class implements TargetGuardInterface {
            /** @var list<array{string, GuardStage}> */
            public array $seen = [];

            public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
            {
                $this->seen[] = [$target->toString(), $stage];

                return TargetVerdict::refuse(TargetRefusal::PrivateHost);
            }
        };
    }
}
