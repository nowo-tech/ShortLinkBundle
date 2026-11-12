# Doctrine XML mapping

`ShortLinkRecord.orm.xml` maps the bundle-owned `nowo_short_link` table (contract §5.1, the 221 convention: XML by
default, the attribute twin on the same class with `doctrine.mapping: attribute`). The extension registers it on
`doctrine.entity_manager` when the host uses the ORM; a DDD host may register this directory on its own entity
manager instead. The class is never hydrated: the bundle reads and writes the table through DBAL. The click tables
(`nowo_short_link_click`, `nowo_short_link_click_daily`, `nowo_short_link_rollup_day`) are DBAL tables added by a
schema listener, not ORM entities.
