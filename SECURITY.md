# NutriPlan security notes

Last review: 25 October 2026. Before any real user signs up, work through the launch checklist at the end of this file.

## Fixed in this round

| # | Severity | Problem | Fix |
|---|----------|---------|-----|
| 1 | Critical | PHP runtime RCE bugs CVE-2026-109618 / CVE-2026-109619 ("PHP2Shell"), all PHP before 8.3.36 | Dockerfile refuses to build on PHP < 8.3.36, and the container refuses to start on it. Rebuild with `--pull`. |
| 2 | High | No limit on sign-in attempts (password guessing) | Sign-ins limited per email+IP (10 / 15 min), per email from anywhere (50 / 15 min, so a stranger can't easily lock the owner out) and per IP (50 / 15 min); sign-ups 10 per IP per hour. Attempts are counted before the password check, so parallel guesses can't race past. Counters are hashed. |
| 3 | High | PHP warnings printed into API responses, showing server file paths; `X-Powered-By` header showed the PHP version | Production php.ini, `display_errors` off, `expose_php` off, header removed. |
| 4 | High | Account deletion needed only the token, so a lost phone or leaked token could wipe the account | `DELETE /api/account` now needs the password again (rate-limited). |
| 5 | High | Plan generation had no daily cap, so one account (or many throwaway accounts) could spend all Claude credit | 10 plans per account per 24 h and 100 for the whole server (`DAILY_PLAN_LIMIT`); photo scans 20 per account and 300 for the server (`DAILY_SCAN_LIMIT`). A lock makes a double tap start only one build. |
| 6 | Medium | Sign-out only forgot the token on the phone; it kept working on the server for 30 days | New `POST /api/logout` revokes it; the app calls it. Expired tokens are cleaned up, at most 10 devices stay signed in, and the expiry now uses the database clock (it was 2 h off). |
| 7 | Medium | Weak default DB passwords (`rootpass`, `nutriplan`) | Compose now requires `DB_PASSWORD` and `DB_ROOT_PASSWORD` in `.env`. |
| 8 | Medium | `APP_DEBUG=1` and `CORS_ORIGIN=*` hard-coded in compose | Both off by default, set from `.env` only when needed. |
| 9 | Medium | No request size limit; bad JSON silently treated as empty | 256 KB limit (15 MB for photo scans), `413` / `400` errors. |
| 10 | Medium | Malformed input (arrays instead of text) caused PHP errors; an allergy sent as plain text was silently dropped | Strict input helpers; a text allergy is kept as an allergy; field errors instead of crashes. |
| 10b | Low | Parallel saves could create two account holders or a 7th family member | The household is locked while a member is saved. |
| 10c | Low | Claude's meal names and prep text were stored unbounded | Plain single-line text, 80 / 300 characters, known meal slots only. |
| 11 | Low | Sign-in timing showed whether an email has an account | A password hash is always checked. |
| 12 | Low | Weak passwords accepted; passwords over 72 bytes silently cut by bcrypt | Common/one-character/email passwords refused; 72-byte maximum. |
| 13 | Low | cURL error text returned to the app | Logged only. Redirects disabled and TLS checks explicit for Claude calls. |
| 14 | Low | API container ran as root with a writable code mount | Runs as `www-data`, read-only filesystem and code mount, all Linux capabilities dropped, `no-new-privileges`, 1 GB memory and 200-process limits. |
| 15 | Low | Missing security headers | `Cache-Control: no-store`, `nosniff`, CSP `default-src 'none'`, `X-Frame-Options`, `Referrer-Policy`. |
| 16 | Low | A release build of the app could talk plain HTTP | Release builds refuse a non-HTTPS `API_URL`. |

Already good, kept as is: PDO prepared statements everywhere (no SQL injection found), every member/plan route checks the owner (no cross-account access found), tokens stored as SHA-256 hashes, bcrypt passwords, token kept in secure storage on the phone, Claude key server-side only, no names/emails sent to Claude, photo-scan output whitelisted against the catalogue.

## Launch checklist (not done yet)

- [ ] HTTPS in front of the API (a reverse proxy with a certificate). Never forward port 8000 to the internet.
- [ ] Replace `php -S` with nginx + php-fpm on a patched image; keep OS and PHP updates automatic. The proxy should cap body size (16 MB) and use short timeouts: `php -S` reads the whole body before PHP can refuse it, and 4 slow clients can stall it.
- [x] Password reset (emailed 6-digit code, 15 min, 5 tries) and change password; both sign out other devices and send a notice email. (Nov 2026)
- [x] Optional two-step sign-in with an emailed code at every sign-in (Settings > Sign-in and security). (Nov 2026)
- [ ] Set up real email sending (SMTP_* in .env) before anyone relies on reset or two-step sign-in. Without it, anything that needs an email is refused; MAIL_LOG_ONLY=1 (testing only) writes codes to the server log instead.
- [ ] If email stops working, people with two-step sign-in cannot sign in. Watch `docker compose logs api` for "Email ... failed"; an admin can turn two-step off for one person with SQL (see Contextfile).
- [ ] Consider an authenticator-app option later: email codes are only as safe as the person's inbox, and reset uses the same inbox.
- [ ] Email verification before the first plan (stops throwaway accounts), and then the same reply for "email already registered" so sign-up stops revealing who has an account.
- [ ] Behind a proxy, read the real client IP from it (today the limits use the connecting address; on Docker Desktop every phone shares one).
- [ ] Give the database user only SELECT/INSERT/UPDATE/DELETE once schema changes move to migrations (today it needs CREATE for the auto-created tables).
- [ ] Privacy policy, POPIA registration of the Information Officer, consent wording reviewed (health data and children's data are special personal information).
- [ ] Encrypted, tested database backups; decide how long old plans are kept.
- [ ] Android release config: no cleartext traffic, `android:allowBackup="false"`, R8/obfuscation on.
- [ ] Rotate any API key that has ever been in a screenshot, chat or zip.
- [ ] Monitoring/alerts on 429s and 5xx in the API logs; Anthropic spend alerts.
- [ ] Re-run `flutter pub outdated` and update packages before each release.
