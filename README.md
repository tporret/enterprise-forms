# Enterprise Forms

Enterprise Forms is a modern WordPress forms plugin built around a full-screen React admin workstation, a block-based form builder, and a frontend renderer powered by the WordPress Interactivity API.

It is designed for teams that want native WordPress primitives on the backend, a structured schema for forms, and a lean frontend runtime without relying on a third-party SaaS.

## Highlights

- Full-screen React admin application for dashboard, builder, and entry views.
- Block-based form composition with reusable field blocks.
- Frontend rendering through the `enterprise-forms/renderer` block.
- Submission handling over custom REST endpoints.
- Encrypted entry payload storage.
- File upload support using native WordPress media handling.
- Native payment checkout block with gateway-aware processing.
- Payment gateway settings for Stripe, Braintree, Authorize.Net, Adyen, and Square.
- Per-form notification settings with admin-email fallback.
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

Payment runtime dependencies are installed through Composer. The current checkout adapters use:

- `stripe/stripe-php` for Stripe PaymentIntents.
- `braintree/braintree_php` for Braintree client tokens and transaction capture.

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

`composer install` creates `vendor/autoload.php`, which is required for the Stripe and Braintree SDK classes.

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

## Usage Flow

1. Create a new form from the dashboard.
2. Build the schema in the workstation using the included field blocks.
3. Configure form theme and notification settings.
4. Configure payment gateways under **Settings > Payments** when the form needs checkout.
5. Add the Payment Checkout block and choose a configured gateway.
6. Save the form schema to `ep_form_schema` post meta.
7. Embed the form with the `enterprise-forms/renderer` block.
8. Accept submissions through the REST API.
9. Review entries from the admin entries screen.

## File Storage

Enterprise Forms supports local WordPress uploads and S3-compatible direct uploads for file fields. S3-compatible providers include AWS S3, Cloudflare R2, and Google Cloud Storage S3 interoperability.

When using S3-compatible storage, configure the target bucket CORS policy to allow browser `PUT` requests from the WordPress site origin. If submitted file references should resolve through a public bucket URL, custom domain, or CDN, set the provider's Public Base URL in **Settings > File Storage**; otherwise stored references use the provider endpoint URL.

## Payments

Enterprise Forms includes a gateway-agnostic payment layer so the form schema, frontend renderer, and submission pipeline do not hardcode a single provider.

Current checkout behavior:

- Stripe is fully wired through PaymentIntents, Stripe Elements, server-side amount validation, and payment verification before entry storage.
- Braintree is scaffolded with Drop-in SDK loading, client token generation, and server-side transaction capture through the Braintree PHP SDK.
- Authorize.Net, Adyen, and Square credential storage is present for the settings dashboard, but those gateways are intentionally hidden from the builder dropdown until full checkout adapters are implemented.

Payment security rules:

- The browser never sends the trusted amount.
- The server calculates the payable amount from the saved form schema.
- A payment-required submission is rejected unless the selected gateway confirms payment success.
- Stored entry payloads include payment metadata such as gateway, transaction ID, amount, currency, and receipt URL when available.

## Architecture Overview

### Backend

- `plugin.php`: plugin bootstrap and component initialization.
- `inc/PostTypes.php`: registers the `ep_form` post type and schema meta.
- `inc/AdminBridge.php`: mounts the full-screen React workstation in wp-admin.
- `inc/RestApi.php`: registers admin stats and notification status endpoints.
- `inc/class-ep-rest-entries.php`: handles public submission and admin entry retrieval.
- `inc/class-ep-rest-payments.php`: handles payment settings, intent creation, and payment verification.
- `inc/class-ep-payment-settings.php`: centralizes encrypted gateway credential storage.
- `inc/interface-ep-payment-gateway.php`: defines the payment gateway adapter contract.
- `inc/class-ep-payment-factory.php`: resolves the configured gateway from schema and creates adapters.
- `inc/class-ep-gateway-stripe.php`: Stripe PaymentIntent adapter.
- `inc/class-ep-gateway-braintree.php`: Braintree Drop-in and transaction adapter.
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
- Entry payloads are encrypted before persistence.

## REST Surface

The plugin exposes a REST namespace at `enterprise-forms/v1`.

Key routes include:

- `POST /entries/{form_id}` for frontend submissions
- `GET /entries/{form_id}` for authenticated entry viewing
- `GET /stats` for dashboard metrics
- `GET /forms/entry-counts` for per-form counts
- `GET /notifications/statuses` for notification configuration state
- `GET /payments/settings` for authenticated payment gateway settings
- `POST /payments/settings` for authenticated payment gateway settings updates
- `POST /payment-intent` for public payment intent or client-token preparation

## Notes

- The frontend renderer is a dynamic block, so displayed form output is generated from the saved schema.
- Notifications can use explicitly configured recipients or fall back to the site admin email.
- File uploads are stored as WordPress attachments.
- Payment gateway credentials are stored in WordPress options; secret values are encrypted using the plugin crypto service.
- Admin entry access is restricted to privileged users.

## License

GPL-2.0-or-later
