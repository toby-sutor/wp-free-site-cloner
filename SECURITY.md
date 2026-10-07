# Security

## Reporting a vulnerability

Report vulnerabilities privately through GitHub's private vulnerability
reporting:
https://github.com/toby-sutor/wp-free-site-cloner/security/advisories/new
(Security tab > Report a vulnerability). Please do not open a public
issue for a security report until a fix is available.

Include the plugin version, your WordPress/PHP/MySQL versions, and steps
to reproduce.

## Supported versions

Only the latest release on
https://github.com/toby-sutor/wp-free-site-cloner/releases receives
security fixes.

## Scope

This plugin runs entirely inside the WordPress admin, gated behind
`manage_options`, with one exception documented in README.md: the import
step endpoint, which is guarded by a random per-job secret token (32
bytes) instead of a session, because the import replaces the users table
mid-job. The token works only for that import, expires 2 hours after the
last import step and is revoked 2 minutes after the import finishes or
fails.

Importing an archive is, by design, equivalent to installing the code
(plugins, themes, mu-plugins) and user accounts it contains. Only import
archives you created or fully trust. A report that "importing a malicious
archive runs its code" is expected behaviour; a way for an archive to
reach outside the plugin's temporary tables or outside `wp-content` while
it is being imported is in scope.

Archives are not encrypted; protecting downloaded copies is up to the
site owner. See "Archive storage" in README.md.
