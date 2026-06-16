# Enterprise Forms 1.1.4

## Summary

This release adds explicit lifecycle handling for inactive forms and keeps release metadata aligned across plugin assets and docs.

## Changes

- Added `inactive` as an explicit form post status for `ep_form`.
- Added Inactive option in builder Form Status settings.
- Updated admin form listings and dashboard status summaries to include Inactive.
- Updated frontend form renderer block behavior: inactive forms render hidden output (`display:none`) and do not output active form UI.
- Synced release metadata to `1.1.4` in plugin header/constants, package metadata, and readme files.
- Rebuilt admin and block assets.

## Upgrade Notes

- No schema migration required.
- Existing forms can now be set to Inactive from the builder status selector.
- Frontend pages embedding inactive forms will keep block placement but render hidden output.

## Suggested Release Body

Release v1.1.4.

- Added explicit Inactive form lifecycle status.
- Builder now supports Draft, Published, and Inactive statuses.
- Inactive forms are hidden on frontend render output.
- Updated dashboard/table labels and release metadata.
