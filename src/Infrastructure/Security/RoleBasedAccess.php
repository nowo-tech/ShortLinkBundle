<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Security;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * Default access port: `access.view_role` / `access.manage_role` through Symfony's authorization checker, the same
 * for every tenant (single-tenant hosts). Without SecurityBundle, or without a token, everything is denied. The actor
 * is the user identifier of the current token (when it passes the {@see ActorRef} rule). Reads the token storage on every call.
 */
final readonly class RoleBasedAccess implements ShortLinkAccessInterface
{
    /**
     * @param AuthorizationCheckerInterface|null $checker null when SecurityBundle is not installed
     * @param TokenStorageInterface|null $tokens null when SecurityBundle is not installed
     */
    public function __construct(
        private ?AuthorizationCheckerInterface $checker,
        private ?TokenStorageInterface $tokens,
        private string $viewRole = 'ROLE_ADMIN',
        private string $manageRole = 'ROLE_ADMIN',
    ) {
    }

    /**
     * Whether the current user has the role of the permission.
     */
    public function isGranted(ShortLinkPermission $permission, TenantKey $tenant): bool
    {
        if (!$this->checker instanceof AuthorizationCheckerInterface) {
            return false;
        }
        try {
            return $this->checker->isGranted($permission === ShortLinkPermission::View ? $this->viewRole : $this->manageRole);
        } catch (AuthenticationCredentialsNotFoundException) {
            return false;
        }
    }

    /**
     * The current user's identifier, or null (read from the token storage on every call).
     *
     * @phpstan-impure
     */
    public function currentActor(): ?ActorRef
    {
        $identifier = $this->tokens?->getToken()?->getUserIdentifier();
        if ($identifier === null) {
            return null;
        }
        try {
            return ActorRef::fromString($identifier);
        } catch (InvalidShortLinkArgumentException) {
            // An identifier the actor rule refuses (empty, too long, padded, control characters) is not recorded.
            return null;
        }
    }
}
