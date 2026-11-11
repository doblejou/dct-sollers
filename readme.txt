=== DCT Sollers ===
Contributors: doblejou
Tags: security, login, migration, cdn
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.2.23
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular tools for IP-based login protection, WP-CLI site migration, and static resource rewriting for your own CDN.

== Description ==

DCT Sollers provides independent modules configured under Settings → DCT Sollers. It uses WordPress APIs without mandatory commercial services or external PHP libraries.

* Login protection: limits failed attempts per IP and temporarily blocks all access to wp-login.php. Enabled by default.
* CLI migration: exports and imports sites using its own ZIP format, exclusively through WP-CLI. Enabled by default.
* CDN: rewrites local public resource URLs to an administrator-configured hostname. Disabled by default.
* Frontend: optional emoji, RSD, shortlink, visitor Dashicons, REST/oEmbed discovery, HTML generator and separate content/comment feed discovery cleanup, resource hints, WOFF2 preloads and one front-page image preload. Disabled by default; all settings initially off or empty.

Disabling a module preserves its settings and data. The plugin requires a single-site installation; multisite is not supported.

= Login protection and privacy =

Defaults: 3 failed attempts within 1 minute, followed by a 15-minute block. Further requests do not extend a block. REST, XML-RPC, and external authentication forms are not protected.

IP addresses and timestamps for failed attempts and blocks are stored locally, without credentials. Hourly cleanup removes expired records and depends on WP-Cron. Blocks expire on time even if cleanup is delayed. Shared IP addresses share their attempt counter and block.

The IP address comes exclusively from REMOTE_ADDR. When using a proxy or CDN, configure the server to resolve the real visitor IP correctly; the plugin does not interpret proxy headers. Site operators should disclose this processing in their privacy policy and determine its legal basis.

= Migration through WP-CLI =

Examples:

    wp dct-sollers export --file=/ruta/privada/sitio.zip
    wp dct-sollers import --file=/ruta/privada/sitio.zip --mode=merge --dry-run
    wp dct-sollers import --file=/ruta/privada/sitio.zip --mode=merge
    wp dct-sollers import --file=/ruta/privada/sitio.zip --mode=mirror --yes

Importing overwrites data, users, and code. It does not create a backup or guarantee rollback. Make an independent backup before importing and only use trusted packages. Packages contain unencrypted sensitive data: store them outside public directories with restricted access.

Merge replaces complete included tables and matching files, preserving additional items. Mirror also removes additional items within the non-excluded migration scope. WordPress core, wp-config.php, and server configuration are not migrated. The package format is not compatible with .wpress.

Requires WP-CLI, 64-bit PHP, ZipArchive, mysqli, InnoDB tables, and standard paths under wp-content. Import uses pcntl_exec to isolate the process; when unavailable, it stops before writing and provides an explicit alternative startup command. Views, triggers, partitions, and symbolic links in the migrated scope are not supported. A failure during the destructive phase may leave a partial state; maintenance mode remains enabled.

= CDN and external connections =

The administrator must configure a CDN separately. The plugin does not configure providers, upload files, or purge caches. It rewrites resources in public HTML and content.rendered and excerpt.rendered fields for REST post types, not arbitrary JSON or external CSS/JS files.

When this module is enabled, visitors request rewritten resources from the selected CDN hostname. Its provider may receive visitor IP addresses and the usual HTTP request data. Review the provider's terms and privacy policy before configuring the hostname.

Saving settings makes no external requests. The Check CDN button explicitly sends a HEAD request to the saved hostname at wp-includes/images/blank.gif, with the site origin as Referer and without following redirects. HTTP 200 does not certify all resources or their CORS headers. Fonts require appropriate CORS configuration at the CDN/origin. Invalidate page caches after changing module settings or status. Do not use this module simultaneously with CDN Enabler.

= Frontend optimization and external connections =

Each cleanup option explains what it removes, what it preserves and its precautions. REST/oEmbed discovery cleanup removes announcements, not endpoints or existing embedded content. Content and comment RSS discovery are controlled separately; feeds remain accessible directly, but automatic subscription discovery may stop working. Removing the HTML generator tag is cosmetic, not a security measure; feed generators are preserved. These new cleanup settings remain off when upgrading existing configurations. Removing discovery metadata offers only small performance savings.

Frontend uses native WordPress resource-hint and preload APIs. Configure up to 10 HTTP(S) preconnect origins, 10 DNS-prefetch hostnames, 5 absolute WOFF2 font URLs and one absolute image URL for the non-paginated front page. Fonts use anonymous CORS. Do not preload unnecessary resources: this can make performance worse. Visitor Dashicons cleanup may affect theme icons; dependency styles can still require Dashicons.

No resources are downloaded by the server. Configured hints and preloads may cause visitors' browsers to connect to third parties before those resources are used. Use trusted destinations consistent with your privacy and consent policy. No third-party destinations are configured automatically. Match actual theme/CDN resource URLs, including query parameters, and invalidate page caches after changes. CDN HTML rewriting also applies to eligible preload URLs. The module does not generate critical CSS, defer scripts globally, or remove other plugins' assets automatically.

== Installation ==

This plugin is independent of DCT WP Hero and does not import its data or archives.

When switching from DCTWP Sollers, back up the site, deactivate that plugin,
replace its directory with dct-sollers/ and activate DCT Sollers. Remove the old
directory manually without running WordPress uninstall: both names share internal
classes, settings, storage and scheduled cleanup. Do not keep both copies installed.
Existing Sollers settings, blocks and migration archives are preserved. Use
wp dct-sollers for CLI operations; the old command is not registered.

1. Upload the dct-sollers folder to wp-content/plugins/ or install the ZIP through Plugins → Add Plugin → Upload Plugin.
2. Activate DCT Sollers.
3. Open Settings → DCT Sollers and configure the modules you need.
4. Before enabling CDN rewriting, configure its hostname and verify the service and CORS.

To update, replace the plugin files without uninstalling. Deactivation preserves data; uninstalling removes the IP table, plugin settings, and scheduled cleanup event. Export files created outside the plugin are not deleted on uninstall.

== Frequently Asked Questions ==

= How can I unblock an administrator? =

From another IP address, use Settings → DCT Sollers → Login protection. Alternatively, use WP-CLI:

    wp eval 'DCTWPSollers_Store::unlock("192.0.2.10");'

= Does this replace a firewall? =

No. Protection is IP-based and limited to wp-login.php. It does not replace server-side protection or a WAF against distributed attacks.

= Can I run a migration from a browser? =

No. The migration tab only displays instructions; exporting and importing require WP-CLI.

== Changelog ==

= Unreleased =

* Rename the public plugin identity, installation directory, text domain and CLI command to DCT Sollers / dct-sollers, preserving internal data identifiers and archive format.

= 0.2.23 =

* Group each Frontend cleanup option's explanations in its own collapsed accordion while keeping checkboxes visible.

= 0.2.22 =

* Package the current development changes for distribution.

= 0.2.21 =

* Establish independent settings, storage and CLI commands for the Sollers plugin.
* Install separately without importing previous plugin settings, login blocks or migration archives.
* Document narrowly scoped CLI diagnostic exceptions and test that URL conflicts identify the relevant configuration constant.

= 0.2.20 =

* Move login storage initialization and scheduled cleanup into module lifecycle callbacks.
* Separate login and CDN settings into dedicated classes with consistent defaults, validation and read methods.
* Split migration destination resolution, planning and file restoration from CLI coordination while preserving safety checks.
* Add independent module test runners using shared disposable-site infrastructure.

= 0.2.19 =

* Redesigned the administration interface with responsive module cards, clearer navigation and visible saved module states.
* Added scoped, dependency-free CSS loaded only on the plugin settings screen, without custom JavaScript.
* Improved keyboard focus, narrow-screen tables and cleanup option layout while preserving visible help.
* Removed duplicate HTML identifiers from CDN and IP unlock forms without changing nonce protection.

= 0.2.18 =

* Added independent REST/oEmbed discovery, HTML generator and content/comment RSS discovery cleanup options, all off by default.
* Added visible explanations of what each cleanup removes, preserves and may affect, with accessible help for every checkbox.
* Preserve existing settings when adding the new cleanup options and keep REST, oEmbed and feed endpoints available.
* Prefix frontend template variables and document narrowly scoped Plugin Check false positives.

= 0.2.17 =

* Added the optional Frontend module with emoji, RSD, shortlink and visitor Dashicons cleanup.
* Added configurable preconnect, DNS prefetch, WOFF2 font preloads and a front-page image preload using native WordPress APIs.
* Added frontend settings validation and HTTP integration tests, including CDN rewriting, permissions and persistence.

= 0.2.16 =

* Validate DOCUMENT_ROOT types during export while preserving WP-CLI support for absent or empty values.
* Prefix migration finalization and verification helper variables.

= 0.2.15 =

* Added author and contributor metadata.
* Use WordPress-compatible writable checks during migration.
* Escape control characters in CLI diagnostics without HTML-encoding terminal output.
* Document scoped SQL, streaming, filesystem, and early-bootstrap review exceptions.

= 0.2.14 =

* Metadata and documentation preparation for the WordPress directory.
* Translator comments, read-only admin navigation handling, and prefixed template variables.
* WordPress URL parsing and explicit WordPress/CLI guards for migration helper scripts.

= 0.2.13 =

* Login protection, CLI migration, and CDN modules.
