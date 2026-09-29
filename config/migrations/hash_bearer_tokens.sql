-- Bearer tokens are now stored as sha256 digests instead of plaintext.
--
-- auth_helper.php:hashBearerToken() is applied to password_resets.token and
-- remember_tokens.token. Every token row written before this change holds the
-- raw value, so none of them can ever match a digest lookup again: the rows
-- are dead weight, and leaving them in place keeps unused 30-day remember
-- tokens and 24-hour reset tokens sitting in the database.
--
-- Clearing them is the correct migration. It signs out anyone using a
-- remember-me cookie and invalidates any outstanding reset link, which is the
-- intended effect of rotating the storage format.
--
--   mysql -u root mcc_helpdesk < config/migrations/hash_bearer_tokens.sql

DELETE FROM password_resets;
DELETE FROM remember_tokens;
