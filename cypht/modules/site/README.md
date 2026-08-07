## Site

Overrides Cypht's authentication, session and user config handling so it runs
inside Dolibarr: Custom_Auth accepts a short-lived HMAC token instead of a
mailbox password, Custom_Session keeps its own session files rather than
colliding with Dolibarr's, and Custom_User_Config stores settings in
llx_cyphtwebmail_userconfig with the mailbox passwords encrypted.

This replaces the lib.php of Cypht's own site module set. It is copied into
place on every build, since Composer re-extracts the package directory
whenever the locked version changes.
