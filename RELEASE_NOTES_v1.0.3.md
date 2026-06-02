# Enterprise Forms 1.0.3

## Summary

This release improves encryption-key operational safety and admin observability, and includes local tooling to keep shared development file permissions consistent.

## Changes

- Added encryption key management improvements in the plugin settings flow.
- Improved crypto configuration status visibility in admin notices and settings pages.
- Updated admin bridge and settings route wiring related to encryption/payment settings UX.
- Added `tools/normalize-perms.sh` to help normalize permissions in collaborative local environments.

## Upgrade Notes

- No schema/data migration is required for normal upgrades.
- Existing installations should verify encryption status from the plugin settings page after updating.

## Suggested Release Body

Release v1.0.3.

- Added encryption key management improvements in the plugin settings flow.
- Improved crypto configuration status visibility in admin notices and settings pages.
- Updated admin bridge and settings route wiring related to encryption/payment settings UX.
- Added `tools/normalize-perms.sh` to help normalize permissions in collaborative local environments.
