=== Really Simple SSL pro ===
Contributors: RogierLankhorst
Donate link: https://www.paypal.me/reallysimplessl
Tags: mixed content, insecure content, secure website, website security, ssl, https, tls, security, secure socket layers, hsts
Requires at least: 4.2
License: GPL2
Tested up to: 4.9
Stable tag: 1.1.4

Premium support and features for Really Simple SSL

== Description ==
Really Simple SSL offers the option to activate SSL on a per page basis.

= Installation =
* If you have the free Really Simple SSL plugin installed, deactivate it
* Go to “plugins” in your Wordpress Dashboard, and click “add new”
* Click “upload”, and select the zip file you downloaded after the purchase.
* Activate
* Navigate to “settings”, “SSL”.
* Click “license”
* Enter your license key, and activate.


For more information: go to the [website](https://www.really-simple-ssl.com/), or
[contact](https://www.really-simple-ssl.com/contact/) me if you have any questions or suggestions.

== Frequently Asked Questions ==

== Changelog ==
= 1.1.4 =
* Tweak: moved enabling and disabling of https to the posts/pages overview page, bulk edit mode
* Fix: missing function contains_hsts caused compatibility issue with pro plugin

= 1.1.3 =
* Fix: added class server to the per page plugin

= 1.1.2=
* Fix: deprecated wp_get_sites()
* Fix: pro plugin multisite compatibility fixes

= 1.1.1 =
* Tweak: updated the Easy Digital Downloads plugin updater to version 1.6.14

= 1.1.0 =
* Removed yoast conflict notice, as this no longer applies
* Added the option to separately force the homepage over SSL or not.

= 1.0.9 =
* Bug fix in mixed content fixer.

= 1.0.8 =
* Fixed issue with mixed content fixer

= 1.0.7 =
* Restructured plugin to work with updated pro plugin

= 1.0.6 =
* Added option to set a page as http or https in the page or post itself
* Added default wp contstants Force Admin and Force login over SSL
* updated mixed content fixer to latest version

= 1.0.5 =
* minor bug fixes

= 1.0.4 =
* Fixed a bug where in some cases the homepage was not detected as homepage.

= 1.0.3 =
* Fixed bug in updater

= 1.0.2 =
* Upgraded mixed content fixer to the same as the Really Simple SSL free version

= 1.0.1 =
* Added possibility to include homepage in SSL, with exclude pages option.

== Upgrade notice ==
If you upgrade to 1.1.0, please check the new homepage setting in settings/ssl.

== Screenshots ==
* If SSL is detected you can enable SSL after activation.
* The Really Simple SSL configuration tab.
* List of detected items with mixed content.

== Frequently asked questions ==
* Really Simple SSL maintains an extensive knowledge-base at https://www.really-simple-ssl.com.

== Upgrade notice ==
On settings page load, the .htaccess file is no rewritten. If you have made .htaccess customizations to the RSSSL block and have not blocked the plugin from editing it, do so before upgrading.
Always back up before any upgrade. Especially .htaccess, wp-config.php and the plugin folder. This way you can easily roll back.
