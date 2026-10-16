# Enterprise Forms

Enterprise Forms is a modern WordPress forms plugin built around a full-screen React admin workstation, a block-based form builder, and a frontend renderer powered by the WordPress Interactivity API.

Current plugin version: 1.2.4.

## What's New in 1.2.4

- Inbox, Unread, Read, Spam, and Trash views with submission counts.
- Page-level selection and bulk status changes, Trash, restore, and confirmed permanent deletion.
- Responsive entry details with previous/next navigation and explicit read-status controls.
- Consistent dashboard counts, filtered exports, and per-entry action feedback.

## What's New in 1.2.3

- Search indexing stores keyed tokens rather than plaintext submission values, and removes existing plaintext index entries during upgrade.
- Webhook endpoints must use HTTPS to protect submission data in transit.

It is designed for teams that want native WordPress primitives on the backend, a structured schema for forms, and a lean frontend runtime without relying on a third-party SaaS.

## Highlights

- Full-screen React admin application for dashboard, builder, and entry views.
- Block-based form composition with reusable field blocks.
- Frontend rendering through the `enterprise-forms/renderer` block.
- Submission handling over custom REST endpoints.
- Encrypted entry payload storage.
- Protected file upload storage with single-use upload tokens and randomized file names.
- Native payment checkout with Stripe, Braintree, PayPal, and Square adapters.
- Encrypted payment credential storage with public client config exposure only where required.
- Per-form notification settings with admin-email fallback.
- Per-form spam prevention settings for honeypot, rate limiting, and duplicate lock windows.
- Retention policy controls for anonymizing or deleting stored entries after a configured age.
- Disabled-by-default outbound submission webhooks with encrypted signing secret support.
- Themeable frontend output with included `chameleon` and `itsm` themes.
- Custom `ep_form` post type with REST support.

## Supported Field Types

The builder currently includes these field blocks:

- Text
- Email
- Textarea
- Phone
- Number
- Date
- URL
- Select
- Radio
- Checkbox
- Consent
- Hidden
- Checkbox group
- Rating
- File upload
- Payment checkout
- Submit button

## Requirements

- WordPress 6.5+
- PHP 8.2+
- Node.js for asset builds
- Composer for PHP autoloading in development

Payment runtime dependencies are installed through Composer. The checkout path uses:

- `stripe/stripe-php` for Stripe PaymentIntents.
- `braintree/braintree_php` for Braintree client tokens and transaction sale calls.
- WordPress HTTP APIs for PayPal Orders and Square Payments requests.

## Installation

### Production-style install

1. Copy the plugin into `wp-content/plugins/enterprise-forms`.
2. Activate **Enterprise Forms** from the WordPress admin.
3. Open the **Enterprise Forms** top-level admin menu.
4. Create a form in the builder.
5. Insert the **Enterprise Form** block into a post or page and select the saved form.

### Local development setup

```bash
composer install
npm install
npm run build
```

`composer install` creates `vendor/autoload.php`, which is required for the Stripe and Braintree SDK classes in development. Release zips include optimized Composer dependencies.

For active development:

```bash
npm start
```

Available scripts:

```bash
npm run build
npm run lint:js
npm run lint:css
npm run format
```

#### Permissions normalization

If local edits are split across users (for example `terrencelp` and `www-data`), normalize repository ownership and modes with:

```bash
tools/normalize-perms.sh
tools/normalize-perms.sh --apply
sudo tools/normalize-perms.sh --apply --fix-owner --user terrencelp --group www-data
```

The script is dry-run by default and applies collaborative permissions (`2775` on directories, `775` on executable files, `664` on non-executable files).

#### Draw.io PNG Export (Reusable Docker Service)

For local diagram exports without a desktop draw.io install, use the included renderer service wrapper:

```bash
tools/drawio-renderer.sh start
tools/drawio-renderer.sh status
tools/drawio-renderer.sh export artifacts/enterprise-forms-architecture/enterprise-forms-architecture.drawio
tools/drawio-renderer.sh stop
```

Notes:

- Default API endpoint is `http://127.0.0.1:3333`.
- Export defaults to transparent PNG output and width `2400`.
- Override output and size as needed:

```bash
tools/drawio-renderer.sh export input.drawio output.png --width 3000
tools/drawio-renderer.sh export input.drawio output.png --scale 2
```

If you prefer Docker Compose directly, use `tools/drawio-renderer-compose.yml`.

## Usage Flow

1. Create a new form from the dashboard.
2. Build the schema in the workstation using the included field blocks.
3. Configure form theme, notification settings, and spam prevention settings.
4. Configure global retention and webhook settings under **Settings** when needed.
5. Configure the selected gateway under **Settings > Payments** when the form needs checkout.
6. Add the Payment Checkout block.
7. Save the form schema to `ep_form_schema` post meta.
8. Embed the form with the `enterprise-forms/renderer` block.
9. Accept submissions through the REST API.
10. Review entries from the admin entries screen.

### Reviewing and managing entries

- **Inbox**, **Unread**, **Read**, **Spam**, and **Trash** views separate the review queue. Inbox and dashboard counts exclude Spam and Trash. View counts cover the whole form, before search/date filters.
- Select individual entries or all entries **on the current page** (up to 100), then apply a bulk status change or move them to Trash. Moving spam back to Read or Unread returns it to Inbox.
- Open **View details** to review a submission in the details panel, with previous/next navigation within the page. Opening details does not automatically mark it read; mark it read explicitly when review is complete. Submitted field values remain read-only.
- Trash preserves the submission until it is restored or permanently deleted. **Restore to Inbox** returns it as Unread, not its former status. Existing retention policies still apply to stored entries.
- Permanent deletion is only available in Trash and requires confirmation. It removes the entry and search index, but not associated payment records or uploaded files. Audit history remains.
- Search, date filters, and CSV download apply to the current view. CSV column customization is collapsed until needed. Refresh reloads the current view; Clear filters resets search and dates.
- Bulk actions report successes and individual failures, and selection clears on page/filter changes or refresh. Entry management requires the plugin's form-editing capability; exports require its export capability.

Entry-management regression checks against a local WordPress installation:

```bash
php tests/entries/entry-management-smoke.php
# For the local Docker service:
docker exec wordpress php /var/www/html/wp-content/plugins/enterprise-forms/tests/entries/entry-management-smoke.php
```

The checks create their own draft forms and encrypted fixture entries and remove them in a `finally` block. They do not alter existing submissions or persist capability changes.

## File Storage

Enterprise Forms supports local WordPress uploads and S3-compatible direct uploads for file fields. S3-compatible providers include AWS S3, Cloudflare R2, and Google Cloud Storage S3 interoperability.

When using S3-compatible storage, configure the target bucket CORS policy to allow browser `PUT` requests from the WordPress site origin. If submitted file references should resolve through a public bucket URL, custom domain, or CDN, set the provider's Public Base URL in **Settings > File Storage**; otherwise stored references use the provider endpoint URL.

Local upload security:

- Uploads are written to `wp-content/uploads/ep-uploads/{form_id}/` and the base directory is provisioned with a deny-all `.htaccess` and an `index.php` on activation and on first upload.
- Stored file names are randomized UUIDs with the detected extension, so submitted files stay unguessable even on servers that ignore `.htaccess`. The original file name is retained as metadata for the entry view and notification emails.
- Upload intents are bound to an existing published form and to a file field that actually exists on that form; forms without a file field are rejected.
- Upload tokens are single-use, are transmitted in the `X-EP-Upload-Token` request header rather than the query string, and are stored only as a keyed hash.
- Uploaded content is re-checked server-side with `wp_check_filetype_and_ext()` against the field's accepted extensions, and the body length must match the validated intent.

On nginx, `.htaccess` is not evaluated. Add an equivalent rule to the server config to block direct access:

```nginx
location ~* /uploads/ep-uploads/ {
    deny all;
}
```

## Payments

Enterprise Forms includes a payment adapter boundary so each gateway can prepare browser checkout, verify or capture the payment server-side, and return a normalized payment record before an entry is stored.

Current checkout behavior:

- Stripe is fully wired through PaymentIntents, Stripe Elements, server-side amount validation, and payment verification before entry storage.
- Braintree is wired through client token generation, Drop-in payment method nonces, server-side transaction sale calls, and verification before entry storage.
- PayPal is wired through Orders, PayPal Buttons, client-side approval/capture, and server-side order verification before entry storage.
- Square is wired through Web Payments SDK tokenization and server-side payment creation before entry storage.

Gateway credential handling:

- Secret credentials are encrypted before being stored in WordPress options.
- Settings responses expose saved-state flags for secret fields, not the secret values themselves.
- Frontend config only exposes public browser values such as Stripe publishable key, PayPal client ID, and Square application/location IDs.
- Legacy plaintext Stripe secret options are migrated into encrypted gateway storage when read and encryption is configured.

Square credential setup:

- `application_id` comes from the Square Developer Dashboard application credentials.
- `location_id` comes from the application's **Locations** section.
- `access_token` comes from the same Square application credentials page; use sandbox credentials for sandbox mode and production credentials for production mode.

Payment security rules:

- The browser never sends the trusted amount.
- The server calculates the payable amount from the saved form schema.
- Public payment-intent preparation requires the form's public nonce and live submission token.
- Local payment records are bound to the submission token and expire after one hour.
- Unclaimed payment records keep a `NULL` transaction ID so multiple checkout attempts can exist safely, while claimed transactions remain unique per gateway to prevent replay.
- A payment-required submission is rejected unless the selected gateway confirms payment success.
- Stored entry payloads include payment metadata such as gateway, transaction ID, amount, currency, and receipt URL when available.

## Spam Prevention

Enterprise Forms includes layered submission protection and now exposes per-form tuning controls in the builder.

Builder controls:

- Enable or disable the honeypot trap field.
- Set submission rate limit count.
- Set submission rate limit window in seconds.
- Set duplicate submission lock window in seconds.

Default values for new and existing forms:

- Honeypot enabled: `true`
- Submission rate limit: `10`
- Submission rate window: `60` seconds
- Duplicate lock window: `300` seconds

Runtime behavior:

- Honeypot checks only run when enabled for the form.
- Rate limiting is applied per form and request fingerprint.
- Duplicate submission blocking uses the configured lock window.
- Public nonce and submission-token replay protection remain enforced.

Public endpoints outside the entry route are rate limited per IP as well:

- File upload intent and upload delivery default to `20` requests per `300` seconds.
- Submission-context requests, which mint the public nonce and submission token, default to `30` requests per `300` seconds.

Request fingerprints are derived from `REMOTE_ADDR` only, because forwarded-for headers are client controlled. Sites behind a reverse proxy that rewrites `REMOTE_ADDR` to a shared address should raise these limits through the filters below.

Developer hooks:

- `ep_forms_honeypot_enabled`
- `ep_forms_submission_rate_limit`
- `ep_forms_submission_rate_window`
- `ep_forms_duplicate_submission_window`
- `ep_forms_upload_rate_limit`
- `ep_forms_upload_rate_window`
- `ep_forms_submission_context_rate_limit`
- `ep_forms_submission_context_rate_window`

## Data Governance

Enterprise Forms includes global retention controls under **Settings > Retention** for stored entry data.

Retention controls:

- Enable or disable the scheduled retention policy.
- Configure how many days entries should be retained.
- Choose whether expired entries are anonymized or deleted.

Retention runs on the daily WordPress cron event `ep_forms_run_retention_policy`. Anonymized entries keep the entry row for operational reporting while replacing sensitive payload/search data; deleted entries are removed from the entry tables.

## Webhooks

Enterprise Forms includes disabled-by-default outbound submission webhooks under **Settings > Webhooks**.

Webhook controls:

- Enable or disable webhook delivery.
- Save one or more endpoint URLs.
- Save an encrypted signing secret for payload authentication.

Webhook delivery is queued after a successful submission. Endpoint URLs are sanitized and validated by WordPress HTTP APIs, and signing secrets require the plugin encryption service to be configured before they can be stored.

## Architecture Overview

### Backend

- `enterprise-forms.php`: plugin bootstrap and component initialization.
- `inc/PostTypes.php`: registers the `ep_form` post type and schema meta.
- `inc/AdminBridge.php`: mounts the full-screen React workstation in wp-admin.
- `inc/RestApi.php`: registers admin stats and notification status endpoints.
- `inc/class-ep-rest-entries.php`: handles public submission and admin entry retrieval.
- `inc/class-ep-rest-payments.php`: handles payment settings, intent creation, and payment verification.
- `inc/DataGovernance.php`: manages retention settings and scheduled anonymization/deletion.
- `inc/WebhookIntegrations.php`: queues and delivers outbound submission webhooks.
- `inc/class-ep-payment-settings.php`: centralizes encrypted gateway credential storage.
- `inc/interface-ep-payment-gateway.php`: defines the payment gateway adapter contract.
- `inc/class-ep-payment-factory.php`: resolves the configured gateway from schema and creates adapters.
- `inc/class-ep-gateway-stripe.php`: Stripe PaymentIntent adapter.
- `inc/class-ep-gateway-braintree.php`: Braintree client-token, sale, and verification adapter.
- `inc/class-ep-gateway-paypal.php`: PayPal Orders adapter.
- `inc/class-ep-gateway-square.php`: Square Payments adapter.
- `inc/Database.php`: manages the custom entries table and aggregate queries.
- `inc/NotificationService.php`: resolves recipients and dispatches email notifications.
- `inc/class-ep-theme-engine.php`: registers and injects frontend theme tokens.

### Frontend and admin app

- `src/admin/`: React workstation for dashboard, builder, and entries.
- `src/admin/builder/epFormRegistry.tsx`: field block registrations.
- `src/admin/routes/SettingsPayments.tsx`: gateway credential settings screen.
- `src/blocks/form/`: block editor integration and frontend renderer.
- `src/styles/form-base.css`: shared frontend form styling.

## Data Model

- Forms are stored as the custom post type `ep_form`.
- Form schema is stored in the `ep_form_schema` post meta key.
- Submissions are stored in the custom database table `wp_ep_entries` using the site prefix at runtime.
- Payment setup and claim state is stored in `wp_ep_payment_intents` using the site prefix at runtime.
- Entry payloads are encrypted before persistence.

## REST Surface

The plugin exposes a REST namespace at `enterprise-forms/v1`.

Key routes include:

- `POST /entries/{form_id}` for frontend submissions
- `GET /entries/{form_id}` for authenticated entry viewing
- `POST /entries/{entry_id}/status` for single-entry status changes
- `POST /entries/{form_id}/bulk` for up to 100 selected IDs: `read`, `unread`, `spam`, `trash`, `restore`, or `delete` (the latter requires `confirm_delete: true` and entries already in Trash). Responses contain `updated` IDs and per-entry `errors`.
- `GET /entries/{form_id}/export` for filtered CSV export
- `GET /stats` for dashboard metrics
- `GET /forms/entry-counts` for per-form counts
- `GET /notifications/statuses` for notification configuration state
- `GET /payments/settings` for authenticated payment gateway settings
- `POST /payments/settings` for authenticated payment gateway settings updates
- `GET /governance/settings` for authenticated retention settings
- `POST /governance/settings` for authenticated retention settings updates
- `GET /integrations/webhooks` for authenticated webhook settings
- `POST /integrations/webhooks` for authenticated webhook settings updates
- `POST /payment-intent` for public payment intent, client-token, or order preparation
- `GET /submission-context/{form_id}` for the public submission nonce and one-time submission token
- `POST /upload-intent` for file upload preparation
- `PUT /upload` for local upload delivery, authorized by the `X-EP-Upload-Token` header

## Notes

- The frontend renderer is a dynamic block, so displayed form output is generated from the saved schema.
- Notifications can use explicitly configured recipients or fall back to the site admin email.
- Notification attachments are resolved through `realpath()` and must sit inside the uploads directory.
- Entry exports escape leading `=`, `+`, `-`, `@`, tab, and carriage-return characters so spreadsheet applications do not evaluate submitted values as formulas.
- File uploads are stored under the plugin's protected uploads directory with randomized file names.
- Payment credentials are stored in WordPress options; secret values are encrypted using the plugin crypto service.
- Webhook signing secrets are stored encrypted using the plugin crypto service.
- Admin entry access is restricted to privileged users.

## Release Packaging

Production archives are built by [`.github/workflows/release.yml`](.github/workflows/release.yml) when a GitHub release is published or the workflow is run manually against an existing tag. The archive is rooted at `enterprise-forms/` and includes compiled `build/` assets plus optimized Composer `vendor/` dependencies, while excluding development files such as `.github/`, `.agents/`, `.docs/`, `node_modules/`, package manifests, Composer manifests, and local tooling config.

## License

GPL-2.0-or-later
