# JF Self Defense — staff admin

One page Jeffrey signs into to answer three questions: **who is coming to class, did
they show up, and have they paid.**

Live at `https://jfselfdefense.com/admin/`.

Plain PHP. No Composer, no database, no framework, no build step. Data is three
JSON files in a directory outside the web root.

---

## 1. ⚠️ The admin is LOCKED until you change the password

`users.php` still holds the bcrypt hash this admin shipped with. That hash was
published alongside the password that produces it, so it is not a lock — anyone
who has seen the repo can open it.

**The admin knows, and refuses to let anyone sign in while it is there.** The
login page says so in plain words instead of quietly handing over every student's
name, phone number, email address and payment record. Replacing the hash switches
sign-in back on; there is no other step, and no flag to flip.

Re-hashing the same old word is detected and refused too, so it cannot be
reinstated by accident. Pick something that has never appeared anywhere else.

### How to change it

```bash
cd site/public/admin
php tools/hash-password.php 'the new password'
```

It prints a line like:

```
    'jeffrey.f@jfselfdefense.com' => '$2y$12$....................................',
```

Paste that over the existing line in `users.php`, keeping the single quotes, then
deploy. The old password stops working the moment the deploy lands.

Run it with no argument (`php tools/hash-password.php`) and it prompts without
echoing what you type.

Rules the helper enforces: at least 12 characters, at most 72 bytes (bcrypt
silently ignores anything past 72, so a longer password would be a lie).

### Adding another user

Add another lowercase line to the array in `users.php`:

```php
return [
    'jeffrey.f@jfselfdefense.com' => '$2y$12$...',
    'chia@example.com'          => '$2y$12$...',
];
```

Email keys **must be lowercase** — sign-in lowercases the submitted address before
looking it up, so a capitalised key can never match. Generate each hash separately;
never share one hash between two people or two sites.

There is no password-reset flow and no "forgot password" link. Rotating a password
means editing this file and deploying. That is a deliberate trade for a two-person
studio; if the admin ever has five users, it needs rethinking.

---

## 2. Server setup — do this once, over SSH

All data lives **outside** `public_html`, in the domain-private directory:

```
/home/u778119288/domains/jfselfdefense.com/private/
```

Create it before first use:

```bash
ssh -p 65002 u778119288@145.79.25.15
mkdir -p /home/u778119288/domains/jfselfdefense.com/private
chmod 700 /home/u778119288/domains/jfselfdefense.com/private
```

Then open `/admin/` in a browser. On first load the admin creates and seeds:

```
private/
├── students.json      the roster
├── sessions.json      one row per class that has actually happened
├── attendance.json    one row per (class, student)
├── payments.json      one row per payment received
├── .sessions/         login sessions
├── .rate-limit/       failed-login counters
└── .write.lock        lock file, held during every save
```

If the directory is missing or unwritable, **every page shows a red banner** telling
Jeffrey to phone someone, with the `mkdir` / `chmod` command folded into a
"Technical details" disclosure underneath it — that half is addressed to a
developer, not to him. Nothing fatals, nothing is silently lost; the admin just
refuses to save until the folder exists.

If a JSON file exists but cannot be read or parsed, that is a different and worse
case: the admin refuses every write **and stops drawing the page**, because an
empty roster and a damaged roster look identical on screen and only one of them
means "add your students again".

### Why outside the web root — this matters

The deploy is `scp -r dist/* .../public_html/`. It has **no exclude list and never
deletes**, so anything shipped by the build overwrites the live copy on every deploy.
If the roster lived at `public/admin/data/students.json`, then:

> Jeffrey adds ten students → someone publishes an article → CI deploys →
> **the ten students are gone**, no error, exit code 0.

Because the data lives in `private/`, which the deploy never touches, **the data
survives every deploy.** Deploy as often as you like.

Do not "helpfully" move the data folder inside `public_html`, and do not commit a
seed `students.json` anywhere under `public/`.

### To change the data location

Set the `JFSD_PRIVATE_DIR` environment variable, or edit the fallback path in
`config.php`. Everything else follows from that one value.

---

## 3. What is real and what is stubbed

| Area | Status |
|---|---|
| Sign-in, sessions, rate limiting | **Real** |
| Student roster | **Real** |
| Attendance registers | **Real** |
| Payment recording and session balances | **Real** |
| Dashboard counts (attention, active, attended, money in) | **Real** — computed from your data |
| Website traffic pane | **STUB. Invented numbers.** |
| Top pages pane | **STUB. Invented numbers.** |
| Search queries pane | **STUB. Invented numbers.** |

The three analytics panes are drawn with dashed amber borders, diagonal stripes, an
amber **"DEMO DATA — NOT CONNECTED"** pill, and a sentence under each saying the
numbers are not real. **GA4 and Google Search Console are not provisioned for
jfselfdefense.com.** There is no API call behind those panes and no key to add.

Do not tone the badges down until the data is genuinely live. When it is: replace
`$demoTraffic` / `$demoPages` / `$demoQueries` in `index.php` with the API response,
remove the `adm-demo` class and the `adm-demo-note` paragraphs, and delete the
`JFSD_ANALYTICS_ARE_FAKE` marker. The table and card markup does not need to change.

---

## 4. How it is meant to be used

**Before class** — dashboard tells you who has run out. Chase them.

**At class** — Attendance opens on a month calendar. Red means a register still
needs taking; a number means that day is done and is how many were in class. Today's
classes also sit under the grid with a button straight to the register. Tap each
name, or "Mark everyone present" and correct the few who were not. Save. Anyone
marked present or late loses one session automatically.

**The venue moved a class, or somebody wants a one-off** — open the day, then
"Add a class at another time". Start, finish, optional note. It affects that day
only, and a day can carry as many classes as it needs to.

**When money arrives** — Payments → pick the student, type the amount, pick what it
covers. Leave "sessions to add" blank and the usual number for that plan is used
(8-pack → 8). The balance updates immediately.

**Someone stops coming** — open them from the roster, "Mark as left". They vanish
from *future* registers but **nothing is deleted** — their history stays, they remain
on any register they were already marked on so a mistake can still be corrected, and
you can set them back to Active any time.

Nothing double-counts. Saving the same register twice, or hitting refresh after
saving, changes nothing — each attendance row remembers whether it already cost a
session. Marking someone back to absent gives the session back, once. Tapping
"Record payment" twice on slow wifi records it once: each form carries a one-shot
token. Half-marking a register and then navigating away asks first.

---

## 5. Files

| File | What it is |
|---|---|
| `index.php` | Dashboard. Today's classes, needs-attention list, stub analytics panes. |
| `students.php` | Roster: list, search, add, edit, soft-delete. |
| `attendance.php` | Month calendar, one day, and the register. Three screens, one file. |
| `payments.php` | Needs-attention list, record a payment, payment history. |
| `login.php` | Sign-in form, bcrypt verify, rate limiting. |
| `logout.php` | Clears the session. |
| `auth.php` | *include* — sessions, CSRF, redirect guards. |
| `config.php` | *include* — paths, brand, timezone. |
| `users.php` | *include* — email → bcrypt hash. **The password lives here.** |
| `_store.php` | *include* — class schedule, plans, and the locked JSON store. |
| `_ui.php` | *include* — escaping, formatting, flash messages, page chrome. |
| `admin.css` | Admin-only layout. Colours and type come from the public site. |
| `.htaccess` | Denies includes, JSON, dotfiles, `tools/`; sets noindex + security headers. |
| `tools/hash-password.php` | CLI only. Prints a bcrypt hash. |

Every include refuses to run unless the constant `JFSD_ADMIN` is defined, so
requesting one directly returns 404 **even if `.htaccess` is ignored**. Verified: on
a bare PHP server with no `.htaccess` at all, `auth.php`, `config.php`, `users.php`,
`_store.php`, `_ui.php` and `tools/hash-password.php` all return 404.

---

## 6. Data model

All dates are ISO (`YYYY-MM-DD`) in storage and friendly on screen. Timestamps are
ISO 8601 in `Asia/Singapore`. Money is a plain JSON number; the `S$` and the
thousands separator are added at display time only.

**`students.json`**

| Field | Notes |
|---|---|
| `id` | `stu_` + 14 hex. Never reused. |
| `name` | Required. |
| `email`, `phone` | Optional. Email is lowercased and validated if present. |
| `joined_date` | ISO date. |
| `plan` | `trial` · `drop-in` · `4-pack` · `8-pack` · `12-pack` · `1-on-1` · `corporate` |
| `sessions_remaining` | Integer, may go negative. |
| `notes` | Free text, newlines allowed. |
| `status` | `active` · `paused` · `left` |
| `created_at`, `updated_at` | ISO 8601. |

**`sessions.json`** — one row per class that actually happened.

| Field | Notes |
|---|---|
| `id` | `ses_` + 14 hex. |
| `date` | ISO date. |
| `start`, `end` | `HH:MM`, 24h, Asia/Singapore. **Frozen at the moment the record is written.** |
| `label` | Free text, usually empty. Shown as-is: "Makeup class", "Moved by the venue". |
| `source` | `template` (the weekly pattern suggested it) · `adhoc` (put on the calendar by hand) |
| `created_at`, `created_by` | |

A record appears in two ways. An **adhoc** class exists the moment it is added. A
**template** class is written the first time a register is saved on it — see "The
class schedule" below.

**`attendance.json`** — one row per student per class.

| Field | Notes |
|---|---|
| `id` | `att_` + 14 hex. |
| `session_id` | The class this mark belongs to. |
| `student_id` | |
| `status` | `present` · `late` · `absent` · `excused` |
| `counted` | **Whether this row has already deducted a session.** This is what makes saving idempotent. |
| `marked_at`, `marked_by` | |

`(session_id, student_id)` is the natural key. Re-saving updates the existing row.

**A mark carries no date of its own.** The class record owns the date, so there is
exactly one place it can be read from and nothing that can drift out of step.
Anything that counts marks per day joins through `jfsd_session_dates()`.

**`payments.json`**

| Field | Notes |
|---|---|
| `id` | `pay_` + 14 hex. |
| `student_id` | |
| `date_received` | ISO date — when the money arrived, not when it was typed in. |
| `amount_sgd` | Number. |
| `method` | `bank_transfer` · `paynow` · `cash` |
| `reference` | Bank / PayNow reference. Optional. |
| `covers` | A plan name, or `adjustment` for a balance correction. |
| `sessions_granted` | Added to the student's balance on save. Signed — an adjustment may be negative. |
| `note` | Optional. |
| `recorded_at`, `recorded_by` | |
| `voided_at`, `voided_by` | Present only on a voided row. |

**Nothing in this admin deletes a money row.** "Void" stamps `voided_at`, takes the
sessions back off the balance, and leaves the record in place — struck through,
still readable, excluded from every total. Voiding twice is refused.

**Every balance movement is written here.** A payment, an opening balance typed when
a student is created, and a manual correction typed on the student form all produce
a row, so this file explains the whole of `sessions_remaining`:

```
sessions_remaining  ==  Σ sessions_granted (live rows)  −  count(attendance rows with counted = true)
```

The dashboard checks that identity on every load and shows a **"Balances that do not
add up"** panel for any student where it fails, with a one-tap fix that writes the
ledger figure back. That panel should always be empty.

### One deliberate exclusion from "needs attention"

Students on the **`corporate`** plan are left out of the needs-attention list even at
zero sessions, because corporate work is invoiced per engagement rather than sold in
class packs — a zero balance is normal for them and would be permanent noise on the
list Jeffrey is meant to act on. They still appear on the roster and on registers,
and marking them present still decrements (so the count is available if wanted). If
that turns out to be wrong, it is one condition in `jfsd_needs_attention()` in
`_store.php`.

### The class schedule

`JFSD_TEMPLATE` in `_store.php` is the weekly pattern:

| When | Time |
|---|---|
| Monday | 19:00–20:00 |
| Wednesday | 19:00–20:00 |
| Saturday | 09:00–10:00 |
| Sunday | 09:30–10:30 |

**It is a suggestion, not an identity.** All it does is let the calendar show what
ought to be on a date nobody has touched yet. There is no group tag here on purpose —
that is a public-site concern, and internally people just turn up.

A class becomes real in `sessions.json` at one of two moments:

- **a register is saved on it** — the times are copied from the pattern *as it
  stands at that instant* and frozen into the record;
- **it is added by hand** from the day screen, for a time the venue moved or a
  one-off somebody asked for.

So editing the table above changes what is *suggested* from now on, and **cannot
reach back and re-time a register that has already been taken.** That is the whole
reason for the split: if Wednesday ever moves from 7pm to 8pm, every past Wednesday
has to keep saying 7pm, because 7pm is when those people were actually in the room.
`jfsd_classes_on_date()` matches a suggestion to a stored class by start time first,
and failing that by `source: 'template'`, so a time change does not sprout a phantom
never-taken class on every past date either.

A date can carry any number of classes. The public Astro pages repeat these times
and still need updating separately; that is outside this folder.

---

## 7. Security notes

Ported from the CGull admin stack, with its known weaknesses fixed rather than
inherited:

- **Sessions and rate-limit counters live outside the web root.** CGull kept them in
  `public/admin/.sessions/`, protected only by `.htaccess`. One `AllowOverride` change
  and session files — which are live credentials — become fetchable.
- **The idle timeout is actually enforced.** CGull wrote `last_active` on every
  request and never read it. Here: 7-day sliding idle window, 30-day absolute cookie.
  Time out and you land on the login page with an explanation.
- **CSRF tokens on every state-changing POST**, including sign-in, verified with
  `hash_equals`. CGull had none; `SameSite=Strict` was the only thing standing there.
- **Rate limiting is per-IP *and* per-email**, written under a lock, with stale
  counters pruned. CGull's was per-IP only, unlocked (so parallel attempts could lose
  increments) and grew one file per attacking IP forever.
  The **per-IP** counter is a hard block: 5 failures per 15 minutes from one address.
  The **per-email** counter is only ever a progressive delay, capped at 5 seconds, and
  never blocks. Blocking on the submitted address alone meant any stranger, from any
  IP, could lock the studio's only operator out of his own admin indefinitely — five
  wrong passwords and then one more every few minutes — with no self-service reset and
  no unlock link. A correct password must always get through.
- **Anonymous requests never create a session file.** A session is only started when
  the request already carries the session cookie; the sign-in form's CSRF token is a
  signed double-submit cookie instead of a session value. Otherwise every unauthenticated
  GET of the login page wrote a file that PHP kept for a month, and a plain request loop
  could exhaust the account's inode quota — which takes down the public site, every
  future deploy, and the admin's ability to save `students.json`, all at once.
- **The return-path guard is an allow-list** of known admin pages, not a `^/admin/`
  prefix regex.
- **Includes carry a `JFSD_ADMIN` sentinel**, so the security model does not depend
  entirely on Apache.
- **Every save holds one exclusive lock across all three files**, and every write
  return value is checked. CGull locked writes but not reads, and its delete path
  reported success even when the save failed.

Also: identical "Invalid credentials." for unknown-user and wrong-password, with a
150–350 ms random pause so the bcrypt cost cannot leak which it was. That part of
CGull was already right and is kept.

Session cookie: name `JFSDADMIN`, path `/admin/`, `httponly`, `SameSite=Strict`, and
**always** `secure`. Not "whenever the request is HTTPS" — the request that matters is
a downgraded first navigation, and that was exactly the one being handed a readable
cookie. `/admin/.htaccess` redirects http to https and sets HSTS to back this up. For a
plain-HTTP local checkout, and nowhere else, set `JFSD_ALLOW_INSECURE_COOKIES=1`.

### Before going live, check these three things

```bash
curl -o /dev/null -w '%{http_code}\n' https://jfselfdefense.com/admin/users.php      # want 403
curl -o /dev/null -w '%{http_code}\n' https://jfselfdefense.com/admin/README.md      # want 403
curl -o /dev/null -w '%{http_code}\n' https://jfselfdefense.com/admin/tools/hash-password.php  # want 403
```

`.htaccess` is honoured by LiteSpeed on this host, but `AllowOverride` is not under
our control. If any of those returns 200, stop and fix it before adding real data.
(They return 404 from the PHP sentinel even if `.htaccess` is ignored — but a 403 is
what tells you the file rules are actually in force.)

### Known limits, stated honestly

- **One password, no MFA, no audit log.** Sign-in times are recorded in the session
  but never surfaced.
- **No idle-timeout warning** — you are simply signed out after a week idle.
- **JSON files, not a database.** Every save rewrites a whole file. Fine to a few
  thousand rows and one or two operators; not the right shape for ten.
- **`.htaccess` `php_flag` directives are deliberately absent.** Hostinger runs PHP
  under LSAPI, not `mod_php`, so they are inert — and inside `<IfModule>` they fail
  *silently*, which is worse than not being there. Set `display_errors` in hPanel or
  a `.user.ini` if you need it.
- **PHP 8.0+.** No 8.1+ syntax is used (no enums, no `readonly`, no `never`), so it
  runs on anything the rest of the site runs on.

---

## 8. Deploying

Nothing special. `public/admin/` is copied verbatim into `dist/admin/` by the Astro
build and then `scp`'d to `public_html/admin/`, exactly like `submit-trial.php`.

`.htaccess` **must stay at `public/admin/.htaccess`** and must never be moved to
`public/.htaccess`. The CI deploy uses the shell glob `dist/*`, which does not match
leading-dot entries at the top level — a top-level `dist/.htaccess` is silently
dropped, with no error and a green build. Nested under `admin/` it ships, because
`admin` matches the glob and `scp -r` then recurses the whole directory.

Do not add anything writable under `public/admin/`. See section 2.
