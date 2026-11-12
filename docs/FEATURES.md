# Features and backlog

The state of the doctor-appointments app as a clinic booking system: what has been built, what was
deliberately deferred, and what is still open. Keep this file up to date when an item is done.

_Last reviewed: 12 November 2026._

## Where it stands

| | Score | Summary |
|---|---|---|
| Functional | **9 / 10** | Covers every way an appointment is made (online, by staff, walk-in, remote walk-in link), realistic availability, the full appointment lifecycle and a usable back office. Patients are told about changes and can reschedule or cancel from a link. Consent is recorded under the Kenya Data Protection Act. No payments yet. |
| Technical | **8 / 10** | Booking rules live in a tested service, locked against double booking; RBAC is the single permission system; the calendar scripts are out of the views; changes and sign-ins are audited; debug mode is off in production. There is no CI or error tracking yet. |

Doing **Next up** below should bring the app to about 9.5 functionally and 8.5 technically.

---

## Done

### Booking and patients

| Item | What it does | Commit |
|---|---|---|
| Consultants book for a patient | Consultants find a registered patient by exact email or phone and book them into their own calendar. | `7aae8aa` |
| Cancel instead of delete | Cancelling keeps the record with an optional note; who and when are recorded; reminders stop; nothing deletes appointments. | `95d5935` |
| Front desk and walk-ins | A front-desk role books, moves and cancels only for assigned consultants, and registers walk-in patients (name and phone, email optional). | `98f990b` |
| Booking links (remote walk-ins) | Staff send a link by WhatsApp and/or email offering free slots over up to 5 days; it expires after 20 minutes (`BOOKING_LINK_TTL_MINUTES`) and books once; the booking is a walk-in on the sender's behalf. | `4472301` |
| Attended / no-show | Staff record outcomes once an appointment has started (changeable for 7 days); a "needs an outcome" list; patients' no-show history is shown to staff. | `fb73722` |

### Availability

| Item | What it does | Commit |
|---|---|---|
| Weekly hours and booking window | Per-weekday hours with breaks, edited on the Availability page; at least 1 hour notice and at most 90 days ahead. | `3ce8a65` |
| Time off and public holidays | Consultants block leave (clashing appointments are listed, not cancelled); admins keep a clinic-wide holiday list seeded for 2026–2027. | `bac80fc` |
| Session length per appointment | Each appointment keeps the length it was booked with; consultants choose their length; starts fall on 5-minute marks; calendar resizing removed. | `0f5e06e` |
| Calendar blocks explained | Clicking a doctor-away, holiday or booked block explains it instead of opening a 404. | `45b3197` |

### Back office

| Item | What it does | Commit |
|---|---|---|
| Appointments board | Every consultant's appointments, today by default, with filters, counts and cancel-with-note; consultants' calendars read-only for admins. | `5dfcae6` |
| Consultant and account management | Admins edit any consultant's profile, hours and time off, add doctors, and deactivate or reactivate accounts. | `2eba9d6` |
| My account for staff | Front desk and admins get an account page (details, password) instead of a crashing profile link. | `d0568aa` |
| Dashboard numbers | Today, next 7 days, bookings this week, cancellation and no-show rates, busiest consultants; front desk sees their consultants only. | `f1e4786` |

### Patient communication and self-service

| Item | What it does | Branch |
|---|---|---|
| F1 Change notices | When an appointment is cancelled or moved, the other side is told by email and WhatsApp: the patient when the clinic changed it, the consultant when the patient did. The internal cancellation note is not sent. | `feature/notify-appointment-changes` |
| F3 Self-service link | Reminders and "moved" notices carry a signed link (`/manage/<id>/<signature>`) where the patient reschedules to another free slot or cancels, up to `SELF_SERVICE_CUTOFF_HOURS` (default 24) before. Moving the appointment invalidates older links. Needs `APP_PUBLIC_URL` (links from the queue worker) and `SELF_SERVICE_KEY`. | `feature/notify-appointment-changes` |

### Notifications and operations

| Item | What it does | Commit |
|---|---|---|
| Reminder stats | `notification/stats` counts sent reminders by send time and shows queued, cancelled and next due. | `b33a816` |
| Consultant reminder wording | WhatsApp and SMS reminders to consultants are worded for the consultant, not the patient. | `6b8673e`, `e3b183c` |
| systemd units | Queue worker and reminder scheduler run under systemd; documented in `deploy/systemd/README.md`. | `190a338`, `fe0042d` |
| Old reminder command removed | `yii send-reminders` (duplicate reminders, ignored cancellations) and its columns are gone. | `df375d0` |
| Tests all passing | The four tests left over from the Yii template were fixed; 189 frontend and 8 common tests pass. | `2f719a0` |

### Architecture and compliance

| Item | What it does | Commit |
|---|---|---|
| T8 One permission system | RBAC is the only source of permissions: checks name an action (`bookForConsultant`, `recordOutcome`…), ownership is a rule (`isTheConsultant`, `assignedConsultant`), and `user.role` is only the account type. A test fails if a role-string permission check comes back. Run `php yii rbac/init` on deploy. | `dd5b303` |
| T6 Logic out of views | `BookingService` holds the slot rules as separately tested methods, with `book()` / `reschedule()` as the ways in; `CalendarViewModel` prepares the calendar page; the calendar script is `Js/booking-calendar.js` and the booking-link and self-service pages share `Js/slot-picker.js`. | `6f538ee` |
| T7 Audit log | An append-only `audit_log`: who (staff, patient, booking link, self-service link, system), what, which record, old → new values and IP. Records appointments, accounts, consultants, time off, holidays and booking links automatically; weekly hours, front-desk assignments, sign-ins, failed sign-ins and role changes explicitly. Passwords, keys and symptom details are never stored. Admins read it under **Audit Log**; staff see an appointment's **History**. Kept 7 years (`AUDIT_RETENTION_YEARS`), purged daily by `doctors-appointment-audit-purge.timer`. | `7820f94` |
| F11 Consent (Kenya Data Protection Act) | A versioned privacy notice at `/site/privacy`, with the controller's details from `.env`. Consent to data processing (required) and to WhatsApp/SMS reminders (opt-in) is asked at email sign-up, on booking links, and by front desk for walk-ins (verbal consent, with who recorded it). Google/Microsoft/LinkedIn sign-ups, existing patients and everyone after a notice change are held on a consent page until they answer. Existing patients keep WhatsApp reminders until they answer (legacy carry-over). Answers are kept in an append-only `consent` table and in the audit log. Patients switch reminders and withdraw consent under **Privacy & messages**; withdrawing cancels upcoming appointments and opens an account-closure request for admins (**Closure Requests**). Staff see "consent not recorded" in patient lookup, and consent status on **System Users**. It can be switched off with `CONSENT_ENABLED=false` (outside Kenya, demos): nobody is asked, answers already given are kept, explicit "no" answers to WhatsApp/SMS still count, and admins see a warning on the dashboard. | `32f3cd5` |
| T2 Monitoring and alerts | Errors from the website, console and queue worker go to a self-hosted GlitchTip (Sentry-compatible), with personal data scrubbed (no request data, frame arguments, contacts or link tokens). `php yii health/check` runs every 5 minutes (`doctors-appointment-health.timer`) and emails `ALERT_EMAILS` when the queue worker stalls, the reminder scheduler stops, messages keep failing, WhatsApp logs out or the database is down: once, a reminder every 6 hours, and when resolved. Each run pings a heartbeat URL (healthchecks.io), and `/health` answers `ok` for an uptime monitor. Admins see **System health** on the dashboard. | `feature/monitoring` |

### Scheduling depth

| Item | What it does | Branch |
|---|---|---|
| F8 phase 1 Multiple facilities | Admins keep a list of **Facilities** (name, address, phone, map link, taking bookings). A consultant adds the facilities they work at on **Availability**, each with its own session length, fee, online booking on or off, and optional from/to dates, and says where they are for each range of weekly hours (ranges cannot overlap across facilities). The time of a booking decides its facility, length and fee (`BookingService`); moving it to another facility takes that facility's length and fee. Patients cannot book themselves where online booking is off; the clinic can. The calendar shows each facility in its colour with a filter; booking links are issued for one facility; self-service rescheduling stays at the same facility. Confirmations, reminders and change notices carry the facility's name, address and map link. Patients' location search also matches facilities. A facility that is not listed is requested by the consultant and created or declined by an admin (**Facility Requests**); the consultant is told either way. | `feature/facilities` |
| F8 phase 2 Front desk, history and reporting per facility | Each front-desk account works at one facility (set on **Front Desk Staff**, with the consultants who work there). They see, book, move, cancel and record outcomes only for appointments there (RBAC rule `assignedFacility` on top of `assignedConsultant`); their consultants' appointments elsewhere show on the calendar as "Booked", with no patient details. Patient history follows the viewer: a consultant sees their own appointments with a patient at every facility, never other consultants'; front desk sees their facility's; admins see everything. Admins close a facility for a day (**Facilities → Closed days**), on top of public holidays; existing bookings that day are kept and listed to move. A consultant sets a travel time between facilities on **Availability**, and the last slots before a block elsewhere are not offered. The dashboard and board filter by facility, and the dashboard has a monthly **Per facility** table (sessions, attended, no-shows, cancelled, fees due) for settling up. Audit entries record the facility. The privacy notice names the facility and its front desk. | `feature/facilities-phase2` |
| Facility admins | A new account type that runs one facility, created by consultancy admins under **Facility Admins** (consultancy admins are unchanged). A facility admin sees every consultant's appointments at their facility only (board, calendars, patient history, numbers and the monthly per-facility report on their board), opens, cancels and records outcomes there, but does not book. They create and edit their facility's **Front Desk Staff**, and see every consultant when assigning them: ticking one who does not work there yet invites them (email and WhatsApp), and front desk can book for them once they accept by adding the facility on **Availability** with their hours, length and fee; declining removes them from that facility's front desk. Under **My Facility** they edit its address, phone and map link (not its name or whether it takes bookings) and its closed days, and they read its audit log. RBAC rule `facilityAdmin` on per-facility permissions (`manageFrontDesk`, `manageFacilityClosures`, `editFacilityContact`, `viewFacilityAuditLog`, `handleAppointment`, `recordOutcome`). The same invitations apply when a consultancy admin ticks a consultant from elsewhere. | `feature/facility-admins` |
| F8 phase 3 Rotations, sessions, busy elsewhere, calendar feed (F9) | Weekly hours can run every week or only in week A / week B of a rotation, and between dates. **One-off sessions** (e.g. a Sunday outreach clinic) are bookable like weekly hours, added by the consultant, their front desk at its facility, or the facility admin; the consultant is told when someone else does; a session cannot overlap their other hours, holidays or closures, and one with bookings cannot be removed. **Busy elsewhere**: weekly hours at places that do not use the app block booking and show as "Busy at …". **Calendar feed**: a secret iCal address per consultant for Google, Apple or Outlook, with no patient details. Fees stay with the consultant. | `feature/facilities-phase3` |

---

## Deploy steps

What each item needs on the server, from T7 onwards, in the order to run it. Run them from the app
directory (`/var/www/html/doctors-appointment`) as the web user, after pulling the code. Items done
earlier are covered by the general steps: `php yii migrate`, `php yii rbac/init`, and
`sudo systemctl restart doctors-appointment.target`.

### T7 Audit log

1. **Database:** `php yii migrate` creates the `audit_log` table (`m261120_090000_create_audit_log_table`).
2. **Permissions:** `php yii rbac/init` adds the `viewAuditLog` permission and gives it to the
   `consultancy-admin` role. Without it, admins don't see **Audit Log** in the sidebar.
3. **Retention (optional):** set `AUDIT_RETENTION_YEARS` in `.env` to keep entries for a period other
   than 7 years.
4. **Daily purge timer:**
   ```bash
   sudo cp deploy/systemd/doctors-appointment-audit-purge.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-audit-purge.timer
   systemctl list-timers doctors-appointment-audit-purge.timer   # next run: 03:15
   ```
5. **Restart** the background services so the queue worker loads the new code:
   `sudo systemctl restart doctors-appointment.target`.
6. **Check:**
   - sign in as an admin and open **Audit Log**: your own sign-in is the newest entry;
   - `sudo -u www-data php yii audit/purge --dry-run` reports 0 entries to remove.

### F11 Consent

1. **Database:** `php yii migrate` creates the `consent` and `account_closure_request` tables
   (`m261121_090000_create_consent_tables`).
2. **Privacy notice details** in `.env`. They appear on `/site/privacy`; a missing one shows as
   "to be provided":
   ```
   PRIVACY_CONTROLLER_NAME="Legal name of the clinic"
   PRIVACY_CONTROLLER_ADDRESS="Postal and physical address"
   PRIVACY_CONTACT_EMAIL=privacy@example.co.ke
   PRIVACY_CONTACT_PHONE="+254 ..."
   PRIVACY_DPO_CONTACT="Name and email of the data protection contact"
   ODPC_REGISTRATION_NUMBER="..."   # or leave empty until registered
   CONSENT_ENABLED=true              # default; false switches consent recording off
   ```
   Keep `CONSENT_ENABLED` on for clinics in Kenya: the Data Protection Act requires the consent
   records. Switching it off keeps recorded answers, and switching it back on asks everyone without
   current consent again.
3. **Legal review:** have the notice wording in `frontend/views/site/privacy.php` reviewed before
   going live. Whenever its text changes, change `privacy.version` in `common/config/params.php`;
   signed-in patients are then asked to accept it again.
4. **Restart** the background services so the queue worker and scheduler respect WhatsApp/SMS consent:
   `sudo systemctl restart doctors-appointment.target`.
5. **Tell the front desk:** when booking a patient marked "consent not recorded", read them the
   summary of the notice and tick that they agreed, and ask about WhatsApp/SMS reminders. New walk-ins
   cannot be registered without this.
6. **Check:**
   - `/site/privacy` shows your details;
   - sign in as a patient created before this release: you are taken to the consent page first;
   - as an admin, **System Users** shows a Consent column and **Closure Requests** is in the sidebar.

### T2 Monitoring and alerts

1. **Code dependency:** `composer install --no-dev` installs `sentry/sentry` (the SDK GlitchTip speaks).
2. **Database:** `php yii migrate` creates `health_state` (`m261122_090000_create_health_state_table`).
3. **`.env`:**
   ```
   ALERT_EMAILS=admin@example.co.ke,owner@example.co.ke   # who gets health alerts
   HEALTHCHECK_PING_URL=https://hc-ping.com/<uuid>         # step 6; empty = no heartbeat
   SENTRY_DSN=                                              # step 5; empty = error tracking off
   SENTRY_ENVIRONMENT=production
   APP_RELEASE=                                             # optional: the deployed git commit
   # Optional thresholds (defaults shown)
   HEALTH_QUEUE_MAX_WAIT_MINUTES=5
   HEALTH_REMINDER_MAX_LATE_MINUTES=10
   HEALTH_FAILURES_PER_HOUR=3
   HEALTH_REALERT_HOURS=6
   ```
   Alerts are emailed directly through the app's SMTP settings, so check those work.
4. **Health timer:**
   ```bash
   sudo cp deploy/systemd/doctors-appointment-health.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-health.timer
   systemctl list-timers doctors-appointment-health.timer   # every 5 minutes
   ```
5. **Error tracking (GlitchTip):** follow `deploy/glitchtip/README.md` (Docker, about 1 GB RAM, ideally on
   a small separate VM). Put the project's DSN in `SENTRY_DSN`. Until then, error tracking stays off and
   everything else works.
6. **External monitors** (free tiers are enough):
   - healthchecks.io: create a check with period 5 minutes and grace 10 minutes, and put its ping URL in
     `HEALTHCHECK_PING_URL`. It emails you when the pings stop, i.e. when the server is down;
   - UptimeRobot (or similar): an HTTPS monitor on `https://<your domain>/health` expecting the keyword `ok`.
7. **Restart** PHP-FPM and the background services so they load the new settings:
   `sudo systemctl restart php*-fpm doctors-appointment.target`.
8. **Check:**
   - `sudo -u www-data php yii health/status`: every line is OK;
   - the admin dashboard shows **System health** with recent times;
   - `sudo -u www-data php yii health/test-error` appears in GlitchTip (once SENTRY_DSN is set);
   - `curl https://<your domain>/health` prints `ok`.

### F8 phase 1 Multiple facilities

1. **Database:** `php yii migrate` applies `m261123_090000_create_facility_tables`. It creates `facility`
   and `consultant_facility`, and adds `facility_id` to weekly hours, appointments and booking links
   (and `fee` to appointments). Existing data is attached automatically: one facility per distinct
   `consultant.facility` value (else the practice name, else "Main clinic"), each consultant engaged
   there with their current session length, and their hours, appointments and booking links moved to it.
   It prints how many facilities and engagements it made. It then applies
   `m261123_100000_create_facility_request_table` (consultants' requests for unlisted facilities) and
   `m261124_090000_seed_kenya_facilities`, which adds `facility.ownership` (public, private,
   faith-based) and lists 82 well-known Kenyan hospitals: national and county referral, major private
   and mission hospitals, by former province. The source is Wikipedia's "List of hospitals in Kenya"
   (revision 1379050607, read 11 Nov 2026). Addresses are area, town and county only, and there are
   no phones or map links. Hospitals already listed under the same name (ignoring case, "The" and
   punctuation) are skipped, and the migration prints how many it added.
2. **Permissions:** none new. Facilities use the existing `manageReferenceData` permission, so
   `php yii rbac/init` is not needed for this item.
3. **Settings (optional):** `CURRENCY` in `.env` sets the fee currency (default `KES`).
4. **Restart:** `sudo systemctl restart doctors-appointment.target`, so the queue worker sends the new
   reminder wording.
5. **Tidy the facilities:** as an admin, open **Reference Data → Facilities**. Merge spellings of the
   same place made from free-text profiles (rename one; move consultants by adding the right facility on
   their Availability page and removing the other), and fill in addresses, phones and map links: these
   go to patients with every confirmation and reminder. From now on, consultants request missing
   facilities; answer them under **Reference Data → Facility Requests** (the menu shows how many wait).
6. **Check:**
   - a consultant's **Availability** page lists their facility under **Facilities**, and their hours
     summary is unchanged;
   - a new booking shows the facility on the appointment page, and the reminder text in the queue
     includes its address;
   - with a second facility added, the calendar shows both colours and the facility filter.

### F8 phase 2 Front desk, history and reporting per facility

1. **Database:** `php yii migrate` applies `m261125_090000_facility_phase2`. It adds `user.facility_id`
   (the facility a front-desk account works at), `consultant.travel_buffer_minutes` (default 0, so
   nothing changes until a consultant sets it), the `facility_closure` table, and
   `audit_log.facility_id`. Each existing front-desk account gets the one facility where all its
   consultants work, when there is exactly one; it prints how many were set and how many are left.
2. **Permissions:** `php yii rbac/init`. It adds the rule `assignedFacility` and three permissions
   (`bookAtAssignedFacility`, `handleAtAssignedFacility`, `recordAtAssignedFacility`) that sit between
   the `front-desk` role and its assigned-consultant permissions, and removes the role's direct links
   to those (it prints "front-desk no longer holds … directly"). Until this runs, front desk keeps
   working as before, without the facility limit.
3. **Front desk facilities:** as an admin, open **Front Desk Staff**. Accounts showing "Not set: sees
   every facility" still see their consultants everywhere: edit each one and choose where they work.
   Only consultants engaged at that facility can be ticked.
4. **Privacy notice:** `privacy.version` is now `2026-11.2` (the notice names the facility where an
   appointment takes place and its front desk). Signed-in patients are asked to accept it again on
   their next visit. Have the wording reviewed with the rest of the notice; if it should not trigger
   a new acceptance, set the version back to `2026-11` in `common/config/params.php`.
5. **Restart:** not needed; no queue or message changes.
6. **Check:**
   - a front-desk account with a facility sees only that facility's appointments on the board, and on
     a consultant's calendar sees their appointments elsewhere as "Booked";
   - **Facilities → Closed days** on a facility: closing a day with bookings shows how many are left
     to move, and the consultant's calendar shades that facility's hours that day;
   - a consultant with two facilities sees **Travel time between facilities** on Availability;
   - the dashboard shows **Per facility** for this month and filters by facility; the audit log has a
     **Facility** filter.

### Facility admins

1. **Database:** `php yii migrate` applies `m261126_090000_create_facility_invitation_table` (invitations
   to consultants to work at a facility). Facility admin accounts need no new columns: they are users
   with `role = facility_admin` and their facility in `user.facility_id`.
2. **Permissions:** `php yii rbac/init`. It adds the role `facility-admin`, the rule `facilityAdmin`,
   four permissions consultancy admins now hold for every facility (`manageFrontDesk`,
   `manageFacilityClosures`, `editFacilityContact`, `viewFacilityAuditLog`) and the ruled permissions
   facility admins hold for their own. Nothing existing changes for consultancy admins.
3. **Accounts:** as a consultancy admin, open **Facility Admins** and add one per facility (name,
   email, initial password, facility). They sign in with the email; their RBAC role is given
   automatically.
4. **Queue:** invitations are sent by email and WhatsApp through the queue; the worker must be running
   (it already is for reminders). No restart is needed.
5. **Check:**
   - a facility admin's sidebar shows Appointments Board, Front Desk Staff, My Facility and Audit Log,
     and the board lists only their facility's appointments with its numbers and monthly report;
   - on **Front Desk Staff → Add**, every consultant is listed, marked "works here", "invited, waiting"
     or "not here yet"; ticking one not there sends them an invitation, which they see under
     **Facilities** on their Availability page;
   - **My Facility** shows only the address, phone and map link, with a link to **Closed days**.

### F8 phase 3 Rotations, one-off sessions, busy elsewhere and the calendar feed (F9)

1. **Database:** `php yii migrate` applies `m261127_090000_facility_phase3`. It adds `weeks`,
   `from_date` and `to_date` to weekly hours (empty: every week, open-ended, so existing hours are
   unchanged), `consultant.rotation_week_a` and `consultant.calendar_feed_hash`, and creates
   `consultant_session` and `consultant_external_block`.
2. **Permissions:** `php yii rbac/init`. It adds `manageConsultantSessions` (consultancy admins) and
   the ruled permissions consultants (`manageOwnSessions`), front desk
   (`manageAssignedSessionsAtFacility`) and facility admins (`manageFacilitySessions`) hold.
3. **Public address:** the calendar feed address is built from `APP_PUBLIC_URL` (already needed for
   self-service links); without it, from the request's host. nginx needs no change: `/calendar/<secret>.ics`
   goes to the app like any other page. The feed is not behind a sign-in, by design.
4. **Assets:** `frontend/web/Js/booking-calendar.js` changed (alternate weeks and sessions); browsers
   pick it up on the next load, as asset URLs are not versioned.
5. **Check:**
   - on Availability, a range set to "Week A only" with a day in week A shows "(week A)" in "Patients
     see", and the calendar shades it only in A weeks;
   - **One-off sessions → Add** a Sunday session: it shows on the consultant's calendar in the
     facility's colour and can be booked; front desk see **Sessions** next to each consultant on the
     board, facility admins next to each consultant at their facility;
   - **Busy elsewhere**: an added block shows as "Busy at …" on the calendar;
   - **Calendar feed → Make my feed address**, then add it to a Google or Apple calendar: appointments
     appear as "Appointment · <facility>" within the provider's refresh time (often hours).

### Fix: unticking a front desk's last consultant

Unticking every consultant on **Front Desk Staff → Edit** and saving kept the old assignments: with
nothing ticked, no consultant field reached the server, and the form read that as "unchanged". It now
reads it as "none". Code only: no migration, no `rbac/init`, no restart.

### Fix: front desk calendar days, and the calendar keeping its place

Front desk's calendar showed only the weekdays their own facility is open (so on a Thursday at a
Mon–Thu facility nothing after today was visible). It now shows every day the consultant works,
anywhere; hours elsewhere stay greyed out and unbookable, with appointments there shown as "Booked".
The calendar also returned to this week's week view after every booking or move (the page reloads);
it now reopens on the view and date last shown, per browser tab. Code and
`frontend/web/Js/booking-calendar.js` only: no migration, no `rbac/init`, no restart. Users may need a
hard refresh (Ctrl+F5) once, as the script's address does not change.

### Patients confirm they are coming

The self-service page (`/manage/<id>/<signature>`) has a **Yes, I'm coming** button while an appointment
is still only scheduled; it marks it confirmed, as when the consultant confirms it, and the audit log
records it as the patient's self-service link. Confirming is not a change, so it works right up to the
start, inside the `SELF_SERVICE_CUTOFF_HOURS` cut-off that still limits moving and cancelling. It only
happens on the button (a POST): opening the link, or a messaging app previewing it, changes nothing.

Reminders (WhatsApp, SMS, email) and "moved" notices now carry the link whenever there is something
left to do with it, worded for it: "To confirm you are coming, choose another time or cancel", "To
confirm you are coming" (inside the cut-off), or "To choose another time or cancel" (already
confirmed). Before, they carried it only outside the cut-off.

1. **Restart:** `sudo systemctl restart doctors-appointment.target`, so the queue worker sends the new
   wording. No migration, no `rbac/init`.
2. **Check:** move a scheduled appointment to tomorrow from the calendar: the patient's WhatsApp ends
   "To confirm you are coming: <link>"; the button on that page marks it confirmed on the board.

### Fix: signing in with LinkedIn

LinkedIn sign-in could not work. The library's LinkedIn client asks for the `r_liteprofile` and
`r_emailaddress` scopes, which LinkedIn retired in 2023; a hand edit inside `vendor/` (not in git, so
undone by every `composer install`) changed only part of it; and the sign-in read field names from
LinkedIn's old API. The app now has its own client, `common\authclient\LinkedIn`, for LinkedIn's
OpenID Connect sign-in (scopes `openid profile email`, the `userinfo` endpoint, a Bearer token), and
reads both providers' answers in one place (`common\authclient\OauthProfile`).

An email now counts as verified only when the provider says so (before, anything other than Google
counted as verified), so a provider login takes over an existing account with the same email only
when the provider has verified it. Google or LinkedIn is offered on the login and signup pages only
when both its keys are set in `.env`.

1. **LinkedIn app** (linkedin.com/developers → your app):
   - **Products:** add **Sign In with LinkedIn using OpenID Connect**;
   - **Auth → Authorized redirect URLs:** `https://<your host>/site/auth?authclient=linkedin`.
2. **`.env`:** keep `LINKEDIN_CLIENT_ID` and `LINKEDIN_CLIENT_SECRET`; `LINKEDIN_ACCESS_TOKEN` is not
   used and can go. Google is unchanged (its redirect URL is `.../site/auth?authclient=google`).
3. **Deploy:** pull, then `composer install`. Code only: no migration, no `rbac/init`. If a hand-edited
   `vendor/yiisoft/yii2-authclient` was copied to the server, `composer reinstall yiisoft/yii2-authclient`
   puts back the original (the app no longer uses its LinkedIn client).
4. **Check:** **Log in → LinkedIn** signs in, or creates a patient account for a new email.

---

## Deferred

| Item | Why | Needs before starting |
|---|---|---|
| M-Pesa deposits (STK push) | A project of its own. | Safaricom Daraja credentials (shortcode, passkey, API keys), a public callback URL, and a refund / expiry policy. |
| F10 Kiswahili translation | Deferred on 11 Nov 2026. Approach agreed in outline: Yii message files, a per-user language, patient-facing pages and all outgoing messages first, then staff screens. | A translator or reviewer for medical and legal wording, the default language for walk-ins, and the PHP `intl` extension on the server. |

---

## Open

Priority: **P1** do next, **P2** soon, **P3** when there is time. Impact is the expected change to the scores above.

### Functional

#### Patient communication

| # | Item | Priority | Impact |
|---|---|---|---|
| F2 | WhatsApp booking confirmations for every booking, not only booking links. | P2 | |

#### Self-service

| # | Item | Priority | Impact |
|---|---|---|---|
| F4 | Waitlist: notify patients when a slot frees up. | P3 | |

#### Payments

| # | Item | Priority | Impact |
|---|---|---|---|
| F5 | M-Pesa deposits for new or no-show-prone patients (see **Deferred**). | P2 | +0.5 |

#### Scheduling depth

| # | Item | Priority | Impact |
|---|---|---|---|
| F6 | Visit types with their own lengths (first consultation, review…); the unused `service_id` column is available. | P2 | |
| F7 | Recurring appointments (the `recurring_appointment` field exists but nothing uses it). | P3 | |
| F8 | Consultants engaged at several facilities: one calendar, hours, session lengths, fees and front desk per facility, patient history scoped to a facility. In three phases; see **F8 Multiple facilities** below. **All three phases done** (`feature/facilities`, `feature/facilities-phase2`, `feature/facilities-phase3`), plus facility admins (`feature/facility-admins`). | P3 | Done |
| F9 | Calendar sync (iCal / Google) for consultants. **Done** with F8 phase 3: a read-only iCal feed. Two-way sync (Google Calendar API) is not planned. | P3 | Done |
| F10 | Swahili translation (see **Deferred**). | P3 | |

#### F8 Multiple facilities

A consultant can hold engagements at several facilities (for example, a hospital on Monday and Wednesday
mornings and a private clinic on Tuesday and Thursday afternoons) and manage them all from one calendar.
Each facility sets its own session length and fees, and patient history is scoped to the facility it
was made at.

**Today:** a consultant has one free-text `consultant.facility`; weekly hours, time off and appointments
carry no place (`appointments.location` and `provider_id` exist but are unused); front desk is assigned
to a consultant, so it sees that consultant's appointments everywhere. Booking is already locked per
consultant, so a consultant cannot be double-booked across facilities, and that stays.

**Decisions taken (11 Nov 2026):**

- Facilities set their own session length and fees for each consultant they engage.
- Patient history (past appointments, outcomes, no-show record, appointment **History**) is scoped to a
  facility. Staff at one facility see appointments at other facilities only as "busy", with no patient
  details. Admins see every facility.

##### Phase 1: facilities, engagements and booking — done

Built as planned below, with these choices made while building it:

- The facility is not picked separately: a block of hours belongs to one facility and blocks never
  overlap, so the start time decides it. Every way of booking (calendar, staff, booking link,
  self-service) gets the facility, length and fee the same way.
- "Online booking off" stops patients booking or moving into that facility themselves; consultants,
  their front desk and booking links they send still can. Self-service rescheduling only offers
  facilities open to online booking.
- Only admins create facilities; consultants choose from the list (decided 11 Nov 2026). A facility
  that is not listed is requested: "Not listed: request it…" on the list opens a request form (name,
  address, phone, map link, note). Admins answer under **Reference Data → Facility Requests** with only
  **Create** (check the details, list the facility, and add it to the consultant's facilities with
  their usual length) or **Decline** (optional reason). The consultant is told by email and WhatsApp
  and sees the answer on their Availability page. A listed name, or one they already asked for, cannot
  be requested; at most 5 requests wait per consultant.
- A new consultant's profile facility is still matched by name or created, so self-registration is
  not blocked. Names match regardless of case, a leading "The" and punctuation ("nairobi hospital" is
  The Nairobi Hospital), here, for requests and for admins adding a facility.
- 82 well-known Kenyan hospitals are seeded (see the deploy steps), with ownership where it is clear.
  Consultants see the list grouped as Public, Private, Faith-based and Other (no ownership set).
  Phones and map links are left to admins, because they reach patients and could not be checked.
- `consultant.facility` and `consultant.appointment_session_duration` are kept: the first names a new
  consultant's first facility, the second is the default length for facilities they add.
- The calendar grid steps by the largest length that divides every facility's length (30 and 45 → 15),
  never under 5 minutes, so every facility's start times fall on a grid line (left to Claude's
  discretion, 11 Nov 2026).

| Part | What it does |
|---|---|
| Facilities | A `facility` table (name, address, phone, map link, active). Admins manage them. |
| Engagements | `consultant_facility`: consultant, facility, from and to dates, online booking on or off, session length and fee for that facility. Replaces `consultant.facility`. |
| Hours per facility | `consultant_working_hours` gains `facility_id`; each block of hours belongs to one engagement. The Availability page gets a facility for each row, and blocks may not overlap across facilities. |
| Slots and booking | `SlotFinder` / `BookingService` label each free slot with its facility and use that facility's session length. `appointments.facility_id` is stored on booking (replacing the free-text `location`), and the fee in force is recorded on the appointment. Patients choose a facility first or see slots grouped by facility. |
| Links | Booking links are issued for one facility. Self-service rescheduling offers slots at the same facility only. |
| Calendar | The consultant's calendar shows all their facilities in one view, colour-coded, with a facility filter. |
| Messages | Confirmations, reminders and change notices carry the facility name, address and map link. |
| Migration | One `facility` per distinct `consultant.facility` value; each consultant gets an engagement there with their current session length; existing hours and past appointments are attached to it. Single-facility consultants notice no change. |

##### Phase 2: front desk, patient history and reporting per facility — done

Built as planned below, with these choices made on 12 Nov 2026:

- A front-desk account works at **one** facility (`user.facility_id`), and the admin ticks consultants
  engaged there. `assignedFacility` sits on top of `assignedConsultant` (both rules must pass). An
  account with no facility set is not limited, so existing front desk keeps working until an admin
  chooses; the Front Desk Staff page and their board say so.
- Front desk books only into their facility's hours: the calendar offers only those, and
  `BookingService` refuses the rest once the time has settled the facility. Booking links they send
  are for their facility only.
- History follows the viewer (`FrontDesk::historyCondition()`): a consultant sees **all their own**
  appointments with a patient at every facility (one calendar, same doctor), never other consultants';
  front desk sees their consultants' at their facility; admins see everything. This replaces the
  earlier assumption that consultants' history would be scoped too. Patients are still found by exact
  email or phone; only the history shown with them is scoped.
- The travel buffer is **per consultant** (0, 15, 30, 45, 60 or 90 minutes on Availability, shown with
  two or more facilities): a session must end that long before a block at another facility starts
  the same day. Only the side before the move is affected.
- Facility closures are added by **admins only**, from the facility list (**Closed days**). Bookings
  already made that day are kept and listed (with a link to the board) for staff to move.
- Reporting: the dashboard has a facility filter and a monthly **Per facility** table. "Fees due" adds
  up the fee recorded on attended appointments (the fee in force when booked); attended ones with no
  fee set are counted separately.
- The privacy notice version changed, so patients accept it again (see the deploy steps).

| Part | What it does |
|---|---|
| Front desk per facility | Front desk is assigned to a facility (and, within it, to consultants). A new RBAC rule `assignedFacility` sits next to `assignedConsultant`; front desk books only into their facility's blocks. |
| History scoped to a facility | Patient lookup, no-show history, outcomes and appointment **History** only show records from the viewer's facility. Other facilities' appointments show as "busy" on the calendar. The audit log records the facility. |
| Consent | The privacy notice names the facility as a place data is processed; no change to how consent is asked. |
| Reporting | The dashboard and appointments board can be filtered per facility: sessions, attendance, no-shows and fees due per facility, for settling up with each one. |
| Travel buffer | An optional gap (for example 30 minutes) between back-to-back blocks at different facilities, so the last slot before a move is not offered. |
| Facility closures | A facility can close days of its own on top of the public holiday list. Consultant time off stays consultant-wide. |

##### Phase 3: rotations and sync — done

Built as planned below, with these choices made on 12 Nov 2026:

- Fees stay with the consultant (Availability); facility admins see them in their report only.
- Rotations: each weekly range runs every week, or in week A or week B only, and optionally from and
  until a date. Week A is counted from a day the consultant picks (saved as that week's Monday,
  `consultant.rotation_week_a`), not from ISO week numbers, so it survives 53-week years. Ranges may
  overlap when they can never run on the same day (week A and week B, or dates that do not meet).
  `Consultant::blocksOn($date)` gives the hours that run on a date; booking, slots, closures, travel
  time and the calendar all use it.
- One-off sessions (`consultant_session`) are added by the consultant, by front desk for their
  consultants at their facility, and by facility admins at theirs (permission
  `manageConsultantSessions`); the consultant is told by email and WhatsApp when someone else adds or
  removes one. A session cannot overlap their other hours that day, nor fall on a public holiday or a
  day the facility is closed, and one with bookings cannot be removed. Weekly hours cannot be moved
  onto an upcoming session.
- External engagements (`consultant_external_block`) are weekly, with optional dates, and only the
  consultant adds them. They may not overlap hours at facilities in the app.
- Calendar feed (F9): one secret address per consultant, shown once (only its sha256 is stored); a new
  one stops the old. Events show "Appointment", the facility and the time, never the patient or the
  visit reason; sessions show as free time. Only the consultant can make or stop it.

| Part | What it does |
|---|---|
| Rotations | Hours with effective-from and to dates, and alternate-week patterns (week A / week B), for rotating engagements. |
| One-off sessions | A dated session at a facility outside the weekly pattern (for example a Saturday outreach clinic). |
| External engagements | A "busy at <facility>" block for work at facilities that do not use the app, shown as such instead of as time off. |
| Calendar sync | F9: an iCal feed per consultant, each event with its facility as the location (patient names left out by default). |


#### Compliance (Kenya Data Protection Act)

| # | Item | Priority | Impact |
|---|---|---|---|
| ~~F11~~ | ~~Explicit consent at sign-up and walk-in registration.~~ **Done.** | — | +0.5 (with T7) |
| F12 | Patients can export and delete their data; retention rules for old records. | P2 | |

### Technical

#### Security and operations

| # | Item | Priority | Impact |
|---|---|---|---|
| ~~T1~~ | ~~Turn off debug mode in production.~~ **Done on the server (10 Nov 2026).** | — | +0.5 |
| ~~T2~~ | ~~Error tracking (e.g. Sentry) and an alert when the queue worker stops or notifications keep failing.~~ **Done.** | — | +0.5 (with T4) |
| T3 | Move WhatsApp from WAHA (unofficial WhatsApp Web bridge) to the official WhatsApp Business API before relying on it at scale. | P2 | |

#### Quality and delivery

| # | Item | Priority | Impact |
|---|---|---|---|
| T4 | CI that runs the test suites (and PHPStan) on every push. A full local run takes about 10 minutes. | P1 | +0.5 (with T2) |
| T5 | Static analysis (PHPStan) at a modest level, raised over time. | P2 | |

#### Architecture

| # | Item | Priority | Impact |
|---|---|---|---|
| ~~T6~~ | ~~Move the calendar's JavaScript out of `views/appointments/calendar.php` into its own file, and booking rules into a tested service class.~~ **Done.** | — | +0.5 (with T8) |
| ~~T7~~ | ~~An audit log of who changed what on appointments and accounts, beyond `created_by` / `updated_by`.~~ **Done.** | — | +0.5 (with F11) |
| ~~T8~~ | ~~Use one permission system: checks mix the `user.role` column and RBAC roles.~~ **Done.** | — | |

#### Scalability

| # | Item | Priority | Impact |
|---|---|---|---|
| T9 | The booking-link rate limit uses the file cache, which only works on one server; use Redis if the app runs on more than one. | P3 | |

## Next up

1. **T4** CI on every push.
2. **F12** Patients export and delete their data (completes the closure requests from F11).
3. **F5** M-Pesa deposits, once Daraja credentials are available.
