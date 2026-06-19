# Enterprise Forms 1.2.0

## Summary

This release exposes the new backend governance and webhook settings in the admin Settings screen and aligns release metadata for version 1.2.0.

## Changes

- Added a Retention settings panel for enabling the scheduled retention policy, setting retention age, and choosing anonymize or delete behavior.
- Added a Webhooks settings panel for enabling outbound submission webhooks, managing endpoint URLs, and saving an encrypted signing secret.
- Loaded and saved retention settings through `/enterprise-forms/v1/governance/settings`.
- Loaded and saved webhook settings through `/enterprise-forms/v1/integrations/webhooks`.
- Documented data governance and webhook behavior in `README.md` and `readme.txt`.
- Synced release metadata to `1.2.0` in plugin header/constants, package metadata, block metadata, and readme files.
- Rebuilt admin and block assets with WordPress experimental module output enabled.

## Upgrade Notes

- No schema migration required.
- Retention remains disabled until an administrator enables it under Settings > Retention.
- Webhooks remain disabled until an administrator enables delivery and saves at least one endpoint under Settings > Webhooks.
- Saving a webhook signing secret requires Enterprise Forms encryption to be configured.

## Suggested Release Body

Release v1.2.0.

- Added admin controls for retention settings.
- Added admin controls for outbound submission webhooks.
- Documented governance and webhook behavior.
- Updated release metadata and rebuilt assets.