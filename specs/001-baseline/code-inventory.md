# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/short-link-bundle`  
**Last audited**: 2026-11-12 (S2)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **108**.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Application/Click/ClickPayload.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Application/Click/ClickRecorder.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Application/Click/RedirectResolver.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Application/CodeGenerator.php` | Codes | FR-CODE-001, FR-CODE-002 |
| `src/Application/Default/BuiltInCodeBlocklist.php` | Codes | FR-CODE-001, FR-CODE-002 |
| `src/Application/Default/BuiltInUserAgentClassifier.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Application/Default/ConfigDenylist.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Application/Default/ConfigQuota.php` | Quota | FR-QUOTA-001 |
| `src/Application/Default/ConfigReservedCodes.php` | Codes | FR-CODE-001, FR-CODE-002 |
| `src/Application/Default/NullCountryResolver.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Application/Default/SingleTenantContext.php` | Access and tenancy | FR-ACCESS-001 |
| `src/Application/Guard/ChainTargetGuard.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Application/Guard/DenylistGuard.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Application/Guard/SelfLoopGuard.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Application/ManagerSettings.php` | Manager | FR-MANAGE-001, FR-BULK-001, FR-ACCESS-001, FR-QUOTA-001 |
| `src/Application/ShortLinkManager.php` | Manager | FR-MANAGE-001, FR-BULK-001, FR-ACCESS-001, FR-QUOTA-001 |
| `src/Application/ShortUrlBuilderInterface.php` | Short URLs | FR-URL-001 |
| `src/Application/Store/ClickStoreInterface.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Application/Store/ShortLinkStoreInterface.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Application/Store/StoredLink.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/DependencyInjection/Compiler/ShortLinkCompilerPass.php` | Configuration and wiring | FR-CONFIG-001, FR-CONFIG-002, FR-WIRING-001 |
| `src/DependencyInjection/ConfigNodes.php` | Configuration and wiring | FR-CONFIG-001, FR-CONFIG-002, FR-WIRING-001 |
| `src/DependencyInjection/Configuration.php` | Configuration and wiring | FR-CONFIG-001, FR-CONFIG-002, FR-WIRING-001 |
| `src/DependencyInjection/NowoShortLinkExtension.php` | Configuration and wiring | FR-CONFIG-001, FR-CONFIG-002, FR-WIRING-001 |
| `src/Domain/Event/ShortLinkClickedEvent.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Event/ShortLinkCreatedEvent.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ShortLinkDeletedEvent.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ShortLinkStateChangedEvent.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ShortLinkTargetRefusedEvent.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ShortLinkUpdatedEvent.php` | Events | FR-EVENT-001 |
| `src/Domain/Exception/AccessDeniedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/CodeReservedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/CodeTakenException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/InvalidShortLinkArgumentException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/QuotaExceededException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ShortLinkExceptionInterface.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ShortLinkNotFoundException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/TargetRefusedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Model/ActorRef.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ClickWriteMode.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Model/ClientIpPrefix.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Model/CountryCode.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Model/CreateShortLink.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/Expiry.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/GuardStage.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Domain/Model/LinkKind.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/LinkMetadata.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/LinkSource.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/LinkTitle.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ReferrerHost.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Model/ResolvedLink.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Model/ShortCode.php` | Codes | FR-CODE-001, FR-CODE-002 |
| `src/Domain/Model/ShortLinkId.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ShortLinkListQuery.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ShortLinkPage.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ShortLinkPermission.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ShortLinkState.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/ShortLinkView.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/TargetRefusal.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Domain/Model/TargetUrl.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Domain/Model/TargetVerdict.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Domain/Model/TenantKey.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/Text.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/UpdateShortLink.php` | Value objects | FR-VO-001 |
| `src/Domain/Model/UserAgentFamily.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Port/ClickCountryResolverInterface.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Port/CodeBlocklistInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/ReservedCodeProviderInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/ShortLinkAccessInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/ShortLinkIdGeneratorInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/ShortLinkQuotaInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/ShortLinkTenantContextInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/TargetDenylistInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/TargetGuardInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/TenantLockInterface.php` | Ports | FR-PORT-001 |
| `src/Domain/Port/UserAgentClassifierInterface.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Domain/Service/ShortLinkManagerInterface.php` | Manager | FR-MANAGE-001, FR-BULK-001, FR-ACCESS-001, FR-QUOTA-001 |
| `src/Infrastructure/Doctrine/ClickTablesSchemaListener.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Infrastructure/Doctrine/Entity/ShortLinkRecord.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Doctrine/ShortLinkMetadataListener.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Guard/OutboundUrlGuardBridge.php` | Target guard | FR-TARGET-001, FR-GUARD-001 |
| `src/Infrastructure/Http/ClickTerminateListener.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Infrastructure/Http/RedirectController.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Infrastructure/Lock/NoTenantLock.php` | Quota | FR-QUOTA-001 |
| `src/Infrastructure/Lock/SymfonyTenantLock.php` | Quota | FR-QUOTA-001 |
| `src/Infrastructure/Messenger/RecordShortLinkClick.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Infrastructure/Messenger/RecordShortLinkClickHandler.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Infrastructure/Persistence/AfterCommitConnection.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Persistence/AfterCommitMiddleware.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Persistence/AfterCommitQueue.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Persistence/AfterCommitQueueResetListener.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Persistence/DbalClickStore.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Infrastructure/Persistence/DbalShortLinkStore.php` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Infrastructure/Routing/ShortUrlBuilder.php` | Short URLs | FR-URL-001 |
| `src/Infrastructure/Security/RoleBasedAccess.php` | Access and tenancy | FR-ACCESS-001 |
| `src/Infrastructure/Uid/UuidV7IdGenerator.php` | Value objects | FR-VO-001 |
| `src/NowoShortLinkBundle.php` | Bundle | FR-BUNDLE-001 |
| `src/Resources/config/doctrine-mapping/README.md` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Resources/config/doctrine-mapping/ShortLinkRecord.orm.xml` | Persistence | FR-DB-001, FR-DB-002, FR-EVENT-001 |
| `src/Resources/config/routes/redirect.php` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |
| `src/Resources/translations/NowoShortLinkBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.pt.yaml` | Translations | FR-I18N-001 |
| `src/Resources/views/redirect/not_found.html.twig` | Redirect and click log | FR-REDIRECT-001, FR-CLICK-001 |

| Total production sources | 108 |
| --- | --- |
