<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Domain;

use Nowo\ShortLinkBundle\Application\Default\BuiltInUserAgentClassifier;
use Nowo\ShortLinkBundle\Application\Default\NullCountryResolver;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\ClientIpPrefix;
use Nowo\ShortLinkBundle\Domain\Model\CountryCode;
use Nowo\ShortLinkBundle\Domain\Model\ReferrerHost;
use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Click data: truncated IPs, referrer hosts, countries and the User-Agent families (contract §7.3, F7, F9).
 */
final class ClickValuesTest extends TestCase
{
    public function testIpPrefixes(): void
    {
        self::assertSame('203.0.113.0/24', ClientIpPrefix::fromIp('203.0.113.77')?->value);
        self::assertSame('203.0.0.0/16', ClientIpPrefix::fromIp('203.0.113.77', 16)?->value);
        self::assertSame('203.0.113.77/32', ClientIpPrefix::fromIp('203.0.113.77', 32)?->value);
        self::assertSame('2001:db8:1234::/48', ClientIpPrefix::fromIp('2001:db8:1234:5678::1')?->value);
        self::assertSame('2001:db8:1234:5600::/56', ClientIpPrefix::fromIp('2001:db8:1234:5678::1', 24, 56)?->value);
        self::assertSame('192.0.2.0/24', ClientIpPrefix::fromIp('::ffff:192.0.2.9')?->value, 'IPv4-mapped IPv6 is IPv4');
        self::assertSame('192.0.2.0/24', ClientIpPrefix::fromIp('2002:c000:0209::1')?->value, '6to4 is its embedded IPv4');
        self::assertSame('2001::/32', ClientIpPrefix::fromIp('2001:0:4136:e378:8000:63bf:3fff:fdd2')?->value, 'Teredo keeps /32');
        self::assertSame('2001:db8:1234:5678::/64', ClientIpPrefix::fromIp('2001:db8:1234:5678:9::1', 32, 64)?->value);
        self::assertNull(ClientIpPrefix::fromIp(null));
        self::assertNull(ClientIpPrefix::fromIp('not-an-ip'));
    }

    public function testReferrerHosts(): void
    {
        self::assertSame('news.example.com', ReferrerHost::fromReferer('https://News.Example.COM./path?token=secret#x')?->value);
        self::assertSame('xn--bcher-kva.example', ReferrerHost::fromReferer('https://bücher.example/')?->value);
        self::assertSame('2001:db8::1', ReferrerHost::fromReferer('http://[2001:db8::1]/')?->value);
        foreach ([null, '', 'android-app://com.example', '/relative', 'https://' . str_repeat('a', 300) . '.example/', 'https://exa mple.com/', str_repeat('x', 5000), 'https://' . str_repeat('ü', 70) . '.example/'] as $referer) {
            self::assertNull(ReferrerHost::fromReferer($referer), (string) $referer);
        }
    }

    public function testCountryCodes(): void
    {
        self::assertNull((new NullCountryResolver())->countryFor('203.0.113.1'));
        self::assertSame('ES', CountryCode::fromString('ES')->value);
        $this->expectException(InvalidShortLinkArgumentException::class);
        CountryCode::fromString('es');
    }

    /**
     * @return iterable<string, array{string|null, UserAgentFamily}>
     */
    public static function userAgents(): iterable
    {
        yield 'chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', UserAgentFamily::Chrome];
        yield 'chrome ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0 Mobile/15E148 Safari/604.1', UserAgentFamily::Chrome];
        yield 'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', UserAgentFamily::Edge];
        yield 'opera' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/116.0.0.0', UserAgentFamily::Opera];
        yield 'samsung' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0 Mobile Safari/537.36', UserAgentFamily::Samsung];
        yield 'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0', UserAgentFamily::Firefox];
        yield 'safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', UserAgentFamily::Safari];
        yield 'other browser' => ['Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1)', UserAgentFamily::OtherBrowser];
        yield 'slackbot' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', UserAgentFamily::Preview];
        yield 'facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', UserAgentFamily::Preview];
        yield 'twitter' => ['Twitterbot/1.0', UserAgentFamily::Preview];
        yield 'whatsapp' => ['WhatsApp/2.23.20.0 A', UserAgentFamily::Preview];
        yield 'microsoft office' => ['Mozilla/5.0 (Windows NT 10.0; Microsoft Outlook 16.0.17928; Pro; ms-office; MSOffice 16)', UserAgentFamily::Scanner];
        yield 'proofpoint' => ['Mozilla/5.0 Proofpoint URL Defense', UserAgentFamily::Scanner];
        yield 'curl' => ['curl/8.5.0', UserAgentFamily::Bot];
        yield 'python requests' => ['python-requests/2.32.3', UserAgentFamily::Bot];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', UserAgentFamily::Bot];
        yield 'empty' => ['', UserAgentFamily::Unknown];
        yield 'missing' => [null, UserAgentFamily::Unknown];
        yield 'garbage' => ['xyz', UserAgentFamily::Unknown];
    }

    #[DataProvider('userAgents')]
    public function testUserAgentFamilies(?string $userAgent, UserAgentFamily $family): void
    {
        self::assertSame($family, (new BuiltInUserAgentClassifier())->classify($userAgent));
    }

    public function testMachineFamilies(): void
    {
        $machine = [];
        foreach (UserAgentFamily::cases() as $family) {
            if ($family->isMachine()) {
                $machine[] = $family;
            }
        }
        self::assertSame([UserAgentFamily::Bot, UserAgentFamily::Preview, UserAgentFamily::Scanner], $machine);
        self::assertSame(UserAgentFamily::Unknown, (new BuiltInUserAgentClassifier())->classify(str_repeat(' ', 600) . 'curl/8.0'), 'only the first 512 bytes are read');
    }
}
