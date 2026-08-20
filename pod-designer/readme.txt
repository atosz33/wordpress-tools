=== POD Designer ===
Contributors: attilakis
Tags: print on demand, product designer, woocommerce, t-shirt, mug
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Let customers place their own artwork and text on a t-shirt or mug mockup, then hand the exact design to whoever produces it.

== Description ==

POD Designer adds a print-on-demand product designer to WordPress. The customer picks a product colour, uploads artwork, moves, scales and rotates it inside the printable area, optionally adds text, and submits the result. The admin sees exactly what the customer saw, plus a production sheet with millimetre positions and a print-ready PNG for every printed side.

Product types:

* Flat products (t-shirt, tote bag, poster): a 2D mockup with a print area per view, for example front and back.
* Mugs: a flat unwrapped mockup (the classic 185 x 90 mm wrap) plus an optional rotatable 3D preview that wraps the design around a cylinder. No external 3D library is used, the preview is drawn on a canvas.

Features:

* Unlimited product templates, each with its own views, printable millimetre sizes and print areas.
* Print area is drawn directly on the mockup image in the admin, and stored in percent so it survives image changes.
* The bundled t-shirt and mug artwork is available to every template as a preset, not just to the two starter ones. Picking a built-in mockup for a view also fills in its printable size, print area, non printable bands and, for the mug, the cylinder wall used by the 3D preview.
* Templates can be duplicated with one click, so a second t-shirt that only differs in its colours does not have to be rebuilt.
* Non printable bands per view: a configurable percentage at the top and the bottom of the print area, for example the rolled edges of a mug where the print does not come out well. The designer hatches them, and the print-ready file is trimmed to match.
* The designer can break out of the theme's content column: container width, wide, or the full viewport, with a per shortcode and per WooCommerce product override, plus a configurable working area height.
* Mug templates carry their real diameter and height, so the 3D preview knows that a 185 mm wrap covers only part of an 82 mm mug's circumference and leaves the rest for the handle.
* Colours are managed per template: a name plus a hex value, which tints the mockup in multiply mode. Any colour can override a view with its own uploaded mockup photo.
* Layer editor with drag, corner scaling, rotation, layer ordering, keyboard nudging and deletion. Size and rotation each have a numeric field next to the slider showing the exact value, and rotation has a one click reset to 0 degrees.
* Anything reaching outside the printable area is an error rather than a silent crop: the layer gets a red dashed outline and a warning triangle, the layer row spells out which side it overflows, a banner lists every offending layer with a jump link, and submitting is blocked until they are back inside. The tolerance is 0.05 mm, under one pixel at 300 DPI, and rotation is taken into account.
* The panel drops below the product when the designer sits in a narrow column, so the working area keeps the full width even inside a WooCommerce product summary.
* Text layers with font, millimetre size, colour and bold.
* Live DPI check: artwork below the configured minimum DPI is flagged to the customer and marked in the admin production sheet.
* Two operating modes. With WooCommerce active, a template can be assigned to a product and the design travels with the cart item and the order. Without WooCommerce (or on any page) the `[pod_designer]` shortcode renders a standalone designer with a submission form and an admin e-mail notification.
* Every submission is stored as a Design entry with preview images, print-ready files, the full layer list and a production status.
* The print-ready file downloads in three flavours: transparent (as stored), flattened onto the ordered product colour, and flattened onto white. The flattened versions are rendered on request, so they cost nothing at upload time and also work for designs saved earlier. They need the PHP GD extension, which WordPress requires anyway.

== Installation ==

1. Upload the `pod-designer` folder to `/wp-content/plugins/`.
2. Activate the plugin. Two starter templates are created: a t-shirt and a mug, both with built-in SVG mockups, so the designer works right away.
3. Go to POD Designer -> Product templates and adjust the mockups, print areas and colours.
4. Go to POD Designer -> Settings for upload limits, DPI values, fonts and the notification e-mail.

== Usage ==

Standalone mode:

1. Place `[pod_designer template="tshirt"]` on a page. The `template` attribute takes the template identifier shown on the template card; omit it to use the first active template. Add `layout="full"` (or `wide`, `container`) to override the global width for that page.
2. The customer designs the product and submits the form.
3. The design appears under POD Designer -> Designs, and a notification e-mail is sent.

WooCommerce mode:

1. Edit a product, open Product data -> General, and choose a POD Designer template.
2. The product page renders the designer inside the add to cart form. The design is saved when the customer adds the product to the cart.
3. The cart, checkout and order all show the design, and the order item in the admin links to the full production sheet with the print-ready files.

== Notes ==

* Print-ready files are generated in the browser and uploaded with the design. If your server has a low `upload_max_filesize` or `post_max_size` (2 MB is a common default), raise it to at least 16 MB, otherwise the design is still stored but without its rendered files.
* Export resolution is capped so the canvas stays inside the pixel budget mobile browsers accept. The resolution actually used is recorded on the production sheet.
* The 3D mug preview wraps the product colour and the artwork around the cylinder, deliberately without the flat mockup: the mockup's baked in edge shading is a 2D trick and would show up as dark stripes on top of the shading the renderer applies. If a mug template has several views, the ones sharing the same mockup image are composited into the same wrap.
* On a WooCommerce product page the designer defaults to the product summary column, because a full width break-out sits on top of the product gallery in the usual two column layout. Wide and full width can still be selected per product.
* Fonts are rendered with the visitor's browser, so only list fonts your theme actually loads.

== Translations ==

The plugin is written in English and ships with a full Hungarian translation, so it follows the site language on its own: a Hungarian WordPress shows the admin screens, the designer and the customer facing messages in Hungarian, any other language falls back to English. Both sides of the plugin are covered, including the labels inside the designer canvas, which PHP hands to the script rather than hard coding them in JavaScript.

* Text domain: `pod-designer`, translations in `languages/`.
* Bundled: `pod-designer-hu_HU.po` / `.mo`, plus `pod-designer.pot` as the template for further languages.
* The two starter templates (t-shirt and mug) and the default button labels are translated when they are first created, so a Hungarian site starts with Hungarian names. Renaming them later, or switching the site language after activation, does not change the values already stored in the database.

== Changelog ==

= 1.0.0 =
* Initial release.
