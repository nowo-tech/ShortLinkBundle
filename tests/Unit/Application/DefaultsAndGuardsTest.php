<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Application;

use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookupInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\ShortLinkBundle\Application\CodeGenerator;
use Nowo\ShortLinkBundle\Application\Default\BuiltInCodeBlocklist;
use Nowo\ShortLinkBundle\Application\Default\ConfigDenylist;
use Nowo\ShortLinkBundle\Application\Default\ConfigQuota;
use Nowo\ShortLinkBundle\Application\Default\ConfigReservedCodes;
use Nowo\ShortLinkBundle\Application\Default\SingleTenantContext;
use Nowo\ShortLinkBundle\Application\Guard\ChainTargetGuard;
use Nowo\ShortLinkBundle\Application\Guard\DenylistGuard;
use Nowo\ShortLinkBundle\Application\Guard\SelfLoopGuard;
use Nowo\ShortLinkBundle\Application\ManagerSettings;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\CodeBlocklistInterface;
use Nowo\ShortLinkBundle\Domain\Port\ReservedCodeProviderInterface;
use Nowo\ShortLinkBundle\Infrastructure\Guard\OutboundUrlGuardBridge;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strlen;

use const DNS_A;

final class DefaultsAndGuardsTest extends TestCase
{
    public function testReservedCodesAndBlocklist(): void
    {
        $reserved = new ConfigReservedCodes(['Billing', 'nowo']);
        self::assertTrue($reserved->isReserved('admin'));
        self::assertTrue($reserved->isReserved('well-known'));
        self::assertTrue($reserved->isReserved('billing'));
        self::assertFalse($reserved->isReserved('launch'));

        $blocklist = new BuiltInCodeBlocklist();
        self::assertTrue($blocklist->isBlocked('x9fuckq'));
        self::assertTrue($blocklist->isBlocked('mierda1'));
        self::assertFalse($blocklist->isBlocked('a1b2c3d'));
    }

    public function testDefaultPorts(): void
    {
        self::assertSame('default', (new SingleTenantContext())->current()->value);
        self::assertSame(7, (new ConfigQuota(7))->limitFor(TenantKey::default(), LinkKind::manual()));
        self::assertNull((new ConfigQuota(null))->limitFor(TenantKey::default(), LinkKind::manual()));

        $denylist = new ConfigDenylist(['bad.example', '.evil.example']);
        foreach (['https://bad.example/x' => true, 'https://sub.bad.example' => false, 'https://evil.example' => true, 'https://a.b.evil.example' => true, 'https://notevil.example' => false] as $url => $denied) {
            self::assertSame($denied, $denylist->isDenied(TargetUrl::fromString($url), GuardStage::Redirect), $url);
        }
    }

    public function testTheGeneratorSkipsReservedAndBlockedCandidates(): void
    {
        $reserved = new class implements ReservedCodeProviderInterface {
            public int $calls = 0;

            public function isReserved(string $lowercasedCode): bool
            {
                return ++$this->calls === 1;
            }
        };
        $blocklist = new class implements CodeBlocklistInterface {
            public int $calls = 0;

            public function isBlocked(string $lowercasedCode): bool
            {
                return ++$this->calls === 1;
            }
        };
        $code = (new CodeGenerator($reserved, $blocklist))->generate(9);

        self::assertSame(9, strlen($code->value));
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{9}$/', $code->value);
        self::assertSame(3, $reserved->calls);
        self::assertSame(2, $blocklist->calls);
        self::assertSame(16, strlen((new CodeGenerator(new ConfigReservedCodes(), null))->generate(16)->value));
    }

    public function testTheSelfLoopGuard(): void
    {
        $guard = new SelfLoopGuard(['links.example'], ['/s/']);
        foreach (['https://links.example/s/abc' => false, 'https://links.example/s' => false, 'https://links.example/docs' => true, 'https://other.example/s/abc' => true] as $url => $allowed) {
            self::assertSame($allowed, $guard->check(TargetUrl::fromString($url), GuardStage::Save)->allowed, $url);
        }
    }

    public function testTheSelfLoopGuardNormalisesPathsAndKnowsThePublicHost(): void
    {
        $guard = new SelfLoopGuard([], ['/s/'], 'https://Beacon.Example./');
        foreach ([
            'https://beacon.example/%73/abc'     => false,
            'https://beacon.example/%2F/abc'     => true,
            'https://beacon.example/x/../s/abc'  => false,
            'https://beacon.example/./s/abc'     => false,
            'https://beacon.example/../../s/abc' => false,
            'https://beacon.example/s/./abc/..'  => false,
            'https://beacon.example/docs/./x/.'  => true,
            'https://beacon.example/x/..'        => true,
            'https://other.example/%73/abc'      => true,
        ] as $url => $allowed) {
            self::assertSame($allowed, $guard->check(TargetUrl::fromString($url), GuardStage::Save)->allowed, $url);
        }
        self::assertSame('/s/', SelfLoopGuard::normalise('s/x/..'));
        self::assertTrue((new SelfLoopGuard(['a.example'], ['/s/']))->check(TargetUrl::fromString('https://a.example/docs'), GuardStage::Save)->allowed);
        self::assertTrue((new SelfLoopGuard([], ['/s/'], 'not a url'))->check(TargetUrl::fromString('https://a.example/s/x'), GuardStage::Save)->allowed);
    }

    public function testTheChainStopsAtTheFirstRefusal(): void
    {
        $chain = new ChainTargetGuard([
            new SelfLoopGuard(['links.example'], ['/s/']),
            new DenylistGuard(new ConfigDenylist(['.bad.example'])),
        ]);

        self::assertTrue($chain->check(TargetUrl::fromString('https://example.com'), GuardStage::Save)->allowed);
        self::assertSame(TargetRefusal::SelfLoop, $chain->check(TargetUrl::fromString('https://links.example/s/x'), GuardStage::Save)->refusal);
        self::assertSame(TargetRefusal::Denylisted, $chain->check(TargetUrl::fromString('https://x.bad.example'), GuardStage::Redirect)->refusal);
    }

    public function testTheOutboundUrlGuardBridgeResolvesDnsOnSaveAndRecheckOnly(): void
    {
        $dns = new class implements HostnameDnsLookupInterface {
            /** @var list<string> */
            public array $asked = [];

            /** @var list<string> */
            public array $answer = ['10.0.0.5'];

            public function dnsGetRecord(string $hostname, int $type): array
            {
                $this->asked[] = $hostname;

                return $type === DNS_A ? array_map(static fn (string $ip): array => ['ip' => $ip], $this->answer) : [];
            }

            public function hostByNameL(string $hostname): false
            {
                return false;
            }
        };
        $bridge = new OutboundUrlGuardBridge(new OutboundUrlGuard(false, true, $dns), true);
        $target = TargetUrl::fromString('https://intranet.example/');

        self::assertTrue($bridge->check($target, GuardStage::Redirect)->allowed, 'no DNS on the redirect');
        self::assertSame([], $dns->asked);
        self::assertSame(TargetRefusal::PrivateHost, $bridge->check($target, GuardStage::Save)->refusal);
        self::assertSame(TargetRefusal::PrivateHost, $bridge->check($target, GuardStage::Recheck)->refusal);
        $dns->answer = [];
        self::assertSame(TargetRefusal::Unresolvable, $bridge->check($target, GuardStage::Save)->refusal);
        self::assertSame(TargetRefusal::Metadata, $bridge->check(TargetUrl::fromString('http://metadata.google.internal/'), GuardStage::Redirect)->refusal);
        foreach (['http://10.1.2.3/', 'http://0x7f.1/', 'http://2130706433/', 'http://017700000001/', 'http://[::ffff:127.0.0.1]/', 'http://localhost/', 'http://a.localhost/', 'http://100.64.0.1/', 'http://169.254.10.10/'] as $literal) {
            self::assertFalse($bridge->check(TargetUrl::fromString($literal), GuardStage::Redirect)->allowed, $literal);
        }

        $basic = new OutboundUrlGuardBridge(new OutboundUrlGuard(false, true, $dns), false);
        self::assertTrue($basic->check($target, GuardStage::Save)->allowed, 'basic: no DNS');
    }

    public function testManagerSettings(): void
    {
        $settings = new ManagerSettings(kinds: [
            'manual' => ['allow_custom_code' => true, 'show_in_ui' => true, 'counted' => true],
            'email'  => ['allow_custom_code' => false, 'show_in_ui' => false, 'counted' => false],
            'qr'     => ['allow_custom_code' => true, 'show_in_ui' => true, 'counted' => false],
        ], countedKinds: ['manual', 'qr', 'email']);

        self::assertSame(['manual', 'qr'], $settings->listedKinds());
        self::assertTrue($settings->isCounted('manual'));
        self::assertFalse($settings->isCounted('qr'), 'counted: false wins');
        self::assertFalse($settings->isCounted('email'));
        self::assertFalse($settings->isCounted('unknown'));
        self::assertTrue($settings->allowsCustomCode('qr'));
        self::assertFalse($settings->allowsCustomCode('email'));
        self::assertFalse($settings->allowsCustomCode('unknown'));
        self::assertFalse((new ManagerSettings(allowCustom: false))->allowsCustomCode('manual'));
    }
}
