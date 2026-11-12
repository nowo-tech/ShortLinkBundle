# Doctrine XML mapping

The bundle-owned `ShortLink` entity is mapped here in XML (contract §5.1, the 221 convention: XML by default, an
attribute twin with `doctrine.mapping: attribute`). Empty in S0: the mapping lands with S1. The click tables
(`nowo_short_link_click`, `nowo_short_link_click_daily`, `nowo_short_link_rollup_day`) are DBAL tables created by a
schema listener, not ORM entities.
