=== Govtech Backup ===
Contributors: WONS
Tags: backup, s3, webhook, govtech
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates backups of wp-content and database, triggered by a webhook, and uploads to S3. Requires a valid license obtained from a central server which also provides S3 configuration.

== Description ==

This plugin provides a mechanism to:
*   Receive a webhook trigger to initiate a site backup.
*   Verify a license from a configured license server (`https://wons.bt/GovTech_Backup/license/`).
*   Fetch S3 configuration details and a webhook secret key from the license server payload.
*   If the license is valid, create a ZIP archive containing the `wp-content` directory and a database dump (`init.sql`).
*   Upload the generated ZIP file to the S3 bucket specified in the license configuration.
*   Provide an admin page to view license status, S3 configuration (partially masked), and list/download/delete existing backups from S3.

== Installation ==

1. Upload the `govtech_backup` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure the license server at `https://wons.bt/GovTech_Backup/license/` is accessible and configured correctly for your site's IP address.
4. Configure your webhook source to send a POST request to `[your-site-url]/wp-json/govtech-backup/v1/trigger-backup` with the correct `X-WONS-Backup-Key` header matching the secret provided by the license server.

== Frequently Asked Questions ==

= How is the S3 configuration managed? =
The S3 access key, secret key, bucket name, region, path prefix, and optional endpoint URL are provided by the license server as part of a signed payload. The plugin does not store these directly in WordPress options permanently, but caches them temporarily.

= How is the webhook secured? =
The webhook requires a specific header (`X-WONS-Backup-Key`) whose value must match the secret key provided by the license server payload.

= Where are backups stored locally? =
Backups are only stored locally temporarily during the creation process in the `wp-content/backups/` directory. They are deleted after a successful upload to S3. Failed uploads may leave local files.

== Changelog ==

= 1.0.0 =
* Initial release.
