=== lunexia SMTP Pro ===
Contributors: lunexiait
Tags: smtp, gmail, oauth2, email, phpmailer
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight Gmail OAuth2 and custom SMTP plugin for WordPress. Easy to set up, with secure authentication and email logging.

== Description ==

lunexia SMTP Pro is a simple, modern, and secure WordPress plugin that enables you to send all outgoing WordPress emails through Gmail using OAuth 2.0 authentication or via custom SMTP. It integrates seamlessly with WordPress's PHPMailer, providing reliable email delivery without exposing sensitive passwords.

Features:
* OAuth 2.0 authentication with Gmail (no app passwords required)
* Standard custom SMTP support (TLS/SSL encryption)
* Real-time email logging with status tracking (sent/failed)
* Instant test email utility with delivery reports
* Clean and intuitive modern admin dashboard
* Lightweight, secure, and developer-friendly

== External services ==

This plugin connects to external services provided by Google to handle OAuth 2.0 authentication and send emails through Gmail:

1. Google OAuth 2.0 Service:
* Purpose: Authenticates your WordPress site with your Google account using secure OAuth 2.0.
* Data sent: Your Client ID, Client Secret, redirect URI, and authorization code are sent to `https://accounts.google.com/o/oauth2/v2/auth` and `https://oauth2.googleapis.com/token` when you initiate the connection in the plugin settings and during automatic access token refreshes.
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

2. Google UserInfo API:
* Purpose: Retrieves the authenticated user's email address to verify connection and set the default sender address.
* Data sent: An OAuth 2.0 Bearer access token is sent in the HTTP authorization header to `https://www.googleapis.com/oauth2/v1/userinfo` immediately after successful OAuth authorization.
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

3. Gmail REST API:
* Purpose: Sends outgoing emails from WordPress through Gmail.
* Data sent: Recipient email address, email subject, email body (HTML/plain text), and email headers are transmitted to `https://gmail.googleapis.com/gmail/v1/users/me/messages/send` whenever WordPress dispatches an email (e.g. notifications, test emails, form submissions, password resets).
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

== Installation ==

1. Upload the `lunexia-smtp-pro` folder to the `/wp-content/plugins/` directory, or install the plugin directly through the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Settings > lunexia SMTP in the WordPress admin dashboard.
4. Choose Gmail OAuth2 or Custom SMTP, enter your credentials, and test your connection.

== Frequently Asked Questions ==

= How do I configure Gmail OAuth2? =

1. Visit the Google Cloud Console (https://console.cloud.google.com/).
2. Create a new project or select an existing project.
3. Enable the Gmail API.
4. Configure the OAuth Consent Screen and create OAuth 2.0 Client Credentials (Web application).
5. Copy the Authorized Redirect URI shown in the lunexia SMTP Connection settings into your Google Cloud credentials.
6. Enter the Client ID and Client Secret in the plugin settings and click Authenticate.

= Can I use standard SMTP instead of Gmail? =

Yes, you can configure any standard SMTP server (such as SendGrid, Mailgun, Amazon SES, or your web host's SMTP) in the Connection tab.

= Is this plugin secure? =

Yes. Gmail OAuth 2.0 uses tokens instead of storing plain-text passwords. All admin requests and AJAX endpoints are verified using WordPress nonces and capability checks.

== Screenshots ==

1. General settings and status overview.
2. Gmail OAuth2 and Custom SMTP configuration.
3. Test email sender.
4. Email delivery logs.

== Changelog ==

= 1.0.0 =
* Initial release.
* Gmail OAuth 2.0 integration.
* Custom SMTP support.
* Transactional email logging.
* Test email verification.

== Upgrade Notice ==

= 1.0.0 =
Initial release of lunexia SMTP Pro.