<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;
use Nowo\ShortLinkBundle\Domain\Port\UserAgentClassifierInterface;

use function preg_match;
use function substr;

/**
 * Default User-Agent classifier (contract §7.3): an ordered, bounded list of case-insensitive patterns over the first
 * 512 bytes. E-mail security scanners and link previews are matched before generic bots, browsers last (Edge, Opera and
 * Samsung before Chrome; Chrome before Safari). Immutable (worker-safe).
 */
final readonly class BuiltInUserAgentClassifier implements UserAgentClassifierInterface
{
    /** Ordered patterns: the first match wins. */
    public const PATTERNS = [
        'scanner'       => '/proofpoint|mimecast|barracuda|safelinks|safe ?links|microsoft office|ms-office|outlook-ios|bitdefender|trendmicro|forcepoint|cisco ?ironport|sophos|zscaler/i',
        'preview'       => '/slackbot|slack-imgproxy|twitterbot|facebookexternalhit|facebookcatalog|whatsapp|telegrambot|discordbot|linkedinbot|skypeuripreview|iframely|embedly|redditbot|pinterestbot|vkshare|viber|line\/|snapchat|google-pagerenderer|bingpreview|mastodon/i',
        'bot'           => '/bot\b|bot\/|crawl|spider|slurp|curl\/|wget\/|python-requests|python-urllib|aiohttp|go-http-client|java\/|okhttp|libwww|httpclient|axios\/|node-fetch|undici|headlesschrome|phantomjs|scrapy|postman|insomnia|httpie/i',
        'edge'          => '/edg(?:e|a|ios)?\//i',
        'opera'         => '/opr\/|opera/i',
        'samsung'       => '/samsungbrowser/i',
        'firefox'       => '/firefox\/|fxios\//i',
        'chrome'        => '/chrome\/|crios\/|chromium\//i',
        'safari'        => '/version\/[\d.]+.*safari\//i',
        'other_browser' => '/^mozilla\//i',
    ];

    /**
     * The family of the User-Agent.
     */
    public function classify(?string $userAgent): UserAgentFamily
    {
        if ($userAgent === null || $userAgent === '') {
            return UserAgentFamily::Unknown;
        }
        $head = substr($userAgent, 0, 512);
        foreach (self::PATTERNS as $family => $pattern) {
            if (preg_match($pattern, $head) === 1) {
                return UserAgentFamily::from($family);
            }
        }

        return UserAgentFamily::Unknown;
    }
}
