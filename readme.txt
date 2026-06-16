=== Enterprise Forms ===
Contributors: terrencelp
Tags: forms, form builder, payments, stripe, paypal, square, block editor, rest api, interactivity api
Requires at least: 6.5
Requires PHP: 8.2
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise Forms is a modern WordPress form builder with a full-screen admin workstation, a frontend block renderer, encrypted submissions, native payments, and REST-powered form processing.

== Description ==

Enterprise Forms brings form building into a dedicated WordPress workstation instead of forcing everything into cramped metaboxes.

Use it to create forms, publish them with a block, collect submissions through custom REST endpoints, and review entries from a focused admin interface.

= Main features =

* Full-screen React-powered admin experience for form management.
* Block-based form builder with support for common field types.
* Frontend rendering through the Enterprise Form block.
* Encrypted submission payload storage.
* File upload handling using native WordPress media tools.
* Payment Checkout block with Stripe, Braintree, PayPal, and Square support.
* Encrypted payment credential storage with secret values hidden from settings responses.
* Per-form notification controls with fallback to the site admin email.
* Per-form spam prevention controls for honeypot, rate limiting, and duplicate lock windows.
* Built-in frontend themes for different presentation styles.
* Entry viewing screen for submitted data.

= Spam prevention =

Enterprise Forms protects public submissions with a honeypot trap field, public nonce verification, one-time submission tokens, request fingerprint rate limiting, and duplicate submission suppression.

The builder now includes per-form controls for:

* Honeypot enabled or disabled.
* Submission rate limit count.
* Submission rate limit window in seconds.
* Duplicate submission lock window in seconds.

Default values are honeypot enabled, 10 submissions, 60-second rate window, and 300-second duplicate lock window.

= Included field types =

* Text
* Email
* Textarea
* Phone
* Number
* Date
* URL
* Select
* Radio
* Checkbox
* Consent
* Hidden
* Checkbox group
* Rating
* File upload
* Payment checkout
* Submit button

= Payments =

Enterprise Forms supports Stripe, Braintree, PayPal, and Square payment checkout through the Payment Checkout block.

Stripe uses PaymentIntents and Stripe Elements. Braintree uses client tokens and Drop-in payment method nonces. PayPal uses Orders and PayPal Buttons. Square uses the Web Payments SDK and server-side payment creation.

Payment amounts are calculated server-side from the saved form schema. Payment-required submissions are verified with the selected gateway before entries are stored. Public payment preparation requires the form nonce and live submission token, local payment records are bound to that token, and claimed gateway transactions are protected against replay.

Secret payment credentials are encrypted in WordPress options. Settings responses return saved-state flags for secrets instead of returning the secret values.

= Square credentials =

For Square, get the Application ID and Access Token from the Square Developer Dashboard application credentials, and get the Location ID from the application's Locations section. Use sandbox credentials in sandbox mode and production credentials in production mode.

= Developer dependencies =

Run `composer install` during development so the Stripe and Braintree PHP SDKs are available through `vendor/autoload.php`. Release zip archives include optimized Composer dependencies.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open the `Enterprise Forms` admin menu.
4. Create and save a form.
5. Edit a post or page and insert the `Enterprise Form` block.
6. Select the form you want to render.
7. Publish the page.

== Frequently Asked Questions ==

= How do I display a form on the frontend? =

Insert the `Enterprise Form` block in the block editor, then choose one of your saved forms from the block settings.

= Where are form definitions stored? =

Forms are stored as a custom post type named `ep_form`, and the saved schema is stored in post meta.

= Where are entries stored? =

Entries are stored in a dedicated custom database table using your WordPress table prefix. Submission payloads are encrypted before they are saved.

= Does the plugin support file uploads? =

Yes. Uploaded files are stored through the normal WordPress media workflow and linked to the submission payload.

= Does the plugin support payments? =

Yes. Use the Payment Checkout block after configuring Stripe, Braintree, PayPal, or Square under the Payments settings screen.

= Are payment amounts trusted from the browser? =

No. The server calculates payment amounts from the saved form schema and verifies the completed gateway transaction before saving a payment-required entry. Payment setup is tied to the form nonce and submission token, and completed gateway transactions cannot be reused for another entry.

= Are payment credentials returned to the browser? =

No secret credentials are returned to the browser. The frontend only receives public client configuration required by a gateway, such as a Stripe publishable key, PayPal client ID, or Square application and location IDs.

= Who can view entries? =

Entry viewing is restricted to privileged users in wp-admin.

= Can I tune spam controls per form? =

Yes. In the form builder, open the Spam Prevention panel to adjust honeypot behavior, rate-limit values, and duplicate lock timing for that form.

== Changelog ==

= 1.1.4 =

* Added form lifecycle support for an explicit Inactive status in the builder.
* Added WordPress post status registration for `inactive` on `ep_form` posts.
* Updated admin dashboard/table status labeling to include Inactive.
* Updated frontend renderer behavior so inactive forms output hidden markup (`display:none`) and do not render active form UI.
* Rebuilt admin and block assets with the latest status lifecycle changes.

= 1.1.0 =

* Minor version bump release.
* Updated project documentation in README files.
* Aligned plugin and package metadata version declarations.

= 1.0.3 =

* Added encryption key health checks and recovery controls in the plugin settings experience.
* Improved encryption status visibility across admin notices and settings screens.
* Updated admin settings UI and bridge wiring for more consistent crypto configuration handling.
* Added a permissions normalization helper script for shared local development environments.

= 1.0.2 =

* Added Spam Prevention builder settings for per-form honeypot, rate limit, and duplicate submission windows.
* Updated submission enforcement to read per-form spam settings with safe defaults and backward compatibility.
* Added duplicate submission window filter support for developers.

= 1.0.1 =

* Added Braintree, PayPal, and Square payment gateway wiring.
* Added encrypted payment credential storage for all supported gateways.
* Added public payment-intent protection using form nonce and submission token checks.
* Added submission-token binding for local payment records.
* Updated payment intent storage so multiple unclaimed checkout attempts can coexist while claimed transactions remain unique.
* Added migration of legacy plaintext Stripe secret settings into encrypted gateway storage.
* Added Square credential guidance in payment documentation.

= 1.0.0 =

* Initial public release.
* Added full-screen admin workstation for forms.
* Added block-based form builder and frontend renderer block.
* Added encrypted entry storage and REST submission handling.
* Added file upload support.
* Added payment adapter boundary for future gateway expansion.
* Added Stripe PaymentIntent checkout and verification.
* Added Stripe payment settings.
* Hid non-Stripe payment providers for V1.
* Added notification settings and transport status visibility.
* Added built-in frontend themes.

== Upgrade Notice ==

= 1.1.4 =

Adds explicit Inactive form status handling and hides inactive forms at frontend render time.

= 1.1.0 =

Maintenance release that bumps Enterprise Forms to 1.1.0 and refreshes documentation/version metadata.

= 1.0.3 =

Improves encryption key management and admin visibility for crypto configuration, and adds a local permissions normalization helper script.

= 1.0.2 =

Adds per-form spam prevention controls for honeypot, rate limiting, and duplicate submission lock behavior.

= 1.0.1 =

Payment settings now support Stripe, Braintree, PayPal, and Square. Legacy Stripe secrets are migrated into encrypted storage when encryption is configured.

= 1.0.0 =

Initial release of Enterprise Forms.
