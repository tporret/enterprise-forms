=== Enterprise Forms ===
Contributors: terrencelp
Tags: forms, form builder, payments, stripe, braintree, block editor, rest api, interactivity api
Requires at least: 6.5
Requires PHP: 8.2
Stable tag: 1.0.0
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
* Payment Checkout block with gateway-aware server verification.
* Payment settings for Stripe, Braintree, Authorize.Net, Adyen, and Square credentials.
* Per-form notification controls with fallback to the site admin email.
* Built-in frontend themes for different presentation styles.
* Entry viewing screen for submitted data.

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

Enterprise Forms includes a gateway-agnostic payment architecture. Stripe is wired through PaymentIntents and Stripe Elements. Braintree is scaffolded through Braintree Drop-in, client token generation, and server-side transaction capture. Authorize.Net, Adyen, and Square credential storage is available in the payment settings screen, with checkout adapters intentionally hidden from the builder until their processing layers are implemented.

Payment amounts are calculated server-side from the saved form schema. Payment-required submissions are verified with the selected gateway before entries are stored.

= Developer dependencies =

Run `composer install` before using payment gateways so the Stripe and Braintree PHP SDKs are available through `vendor/autoload.php`.

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

Yes. Use the Payment Checkout block after configuring gateway credentials under the Payments settings screen. Stripe checkout is fully wired, and Braintree support includes the Drop-in and server-side transaction path when the Braintree PHP SDK is installed.

= Are payment amounts trusted from the browser? =

No. The server calculates payment amounts from the saved form schema and verifies the completed gateway transaction before saving a payment-required entry.

= Who can view entries? =

Entry viewing is restricted to privileged users in wp-admin.

== Changelog ==

= 1.0.0 =

* Initial public release.
* Added full-screen admin workstation for forms.
* Added block-based form builder and frontend renderer block.
* Added encrypted entry storage and REST submission handling.
* Added file upload support.
* Added gateway-agnostic payment architecture.
* Added Stripe PaymentIntent checkout and verification.
* Added Braintree gateway scaffold with Drop-in support.
* Added payment gateway settings for Stripe, Braintree, Authorize.Net, Adyen, and Square.
* Added notification settings and transport status visibility.
* Added built-in frontend themes.

== Upgrade Notice ==

= 1.0.0 =

Initial release of Enterprise Forms.
