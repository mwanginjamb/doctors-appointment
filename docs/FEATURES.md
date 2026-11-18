# Features and backlog

The state of the doctor-appointments app as a clinic booking system: what has been built, what was
deliberately deferred, and what is still open. Keep this file up to date when an item is done.

This is the only place status is tracked. The System Gaps in [USER_STORIES.md](USER_STORIES.md#system-gaps)
are each filed here as an item marked "(from Gn)", or listed under **Deferred**. When an item that
came from a gap is done:

1. Strike it through here, add it to **Done** and write its deploy steps.
2. In USER_STORIES.md, mark the gap's row "Fixed by Fn" in the **Tracked as** column.
3. Rewrite the stories the gap affected so they describe the new behaviour.

_Last reviewed: 15 November 2026._

## Where it stands

| | Score | Summary |
|---|---|---|
| Functional | **9 / 10** | Covers every way an appointment is made (online, by staff, walk-in, remote walk-in link), realistic availability, the full appointment lifecycle and a usable back office. Patients are told about changes and can reschedule or cancel from a link. Consent is recorded under the Kenya Data Protection Act. No payments yet. |
| Technical | **8.5 / 10** | Booking rules live in a tested service, locked against double booking; RBAC is the single permission system; the calendar scripts are out of the views; changes and sign-ins are audited; debug mode is off in production. Errors are tracked and alerted on, and CI runs every test on each push and deploys release tags. There is no static analysis yet. |

Doing **Next up** below should bring the app to about 9.5 functionally.

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
| F4 Waiting list | Patients wait for a time with a consultant (a window of dates, optionally mornings or afternoons). When a time frees up, everyone waiting for it gets their link and the first to book gets it. "Something earlier" moves the patient's existing appointment. | `feature/waitlist` |

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
| T4 CI and deploys | Gitea Actions (`.gitea/workflows/ci.yml`) runs every Codeception suite on each push and pull request: MySQL 8 and PHP 8.3 as on the server, a test database built from scratch (migrations, RBAC, `rbac/init`). A pushed `vX.Y.Z` tag that passes is deployed by the **deploy** job over SSH with a key that can only run `deploy/deploy.sh`: it checks the tag is on `main`, backs up the database, then runs composer, migrations and `rbac/init`, restarts the services and checks `/health`. A full run takes about 25 minutes. | `feature/ci` |
| T11 M-Pesa (Daraja) | `Yii::$app->mpesa` sends STK pushes, queries their outcome and makes B2C payments, every setting from `.env` (`MPESA_*`), so going live only changes `.env`. Each request is a row in `mpesa_transaction`. Safaricom answers at `POST /mpesa/{stk,b2c-result,b2c-timeout}/<token>`: a wrong token is a 404, a payment is settled once, an STK success counts only when a status query confirms it, and a callback that arrives after a query still records the receipt. `mpesa/reconcile` (every 5 minutes) settles payments whose callback never came. `Mpesa::EVENT_SETTLED` is for the commitment-fee features. | `feature/mpesa` |

### Commercial pilot

| Item | What it does | Branch |
|---|---|---|
| F20 Pilot groundwork | Every new booking records its channel (`online`, `link`, `staff` for a later day, `walk_in` for the same day), worked out by `BookingService::channelOf()` when it is saved. Every cancellation records who cancelled, on whose side (patient, consultant, front desk, facility, system) and whether the patient asked: patients and the manage link count as the patient asking; staff must answer "Why is it cancelled?" (the patient asked, the facility is closed, the clinic decided), with the facility answer pre-selected on a closed day. Each consultant's first booking is stored once in `consultant.first_booking_at` and starts their 90-day pilot; `frontend\services\Pilot` gives the day and phase. Facility agreements (version, signatories, date, walk-in exemption) are in `facility_agreement`, recorded with `php yii pilot/agreement` until the admin screens (F28); `pilot/status` lists pilots and agreements. | `feature/pilot-groundwork` |
| F21 Masked contacts | Patients' phone numbers and emails are partly hidden (`0712***678`, `j***@mail.com`, by `common\helpers\Contact`) on the board, the waiting list, booking links, closure requests, the users list (patients only; staff accounts are shown) and the calendar's patient search. The appointment page shows them in full to the patient and to staff who may handle it; each staff member's view is audited once a day per appointment ("Patient's contact viewed"). | `feature/masked-contacts` |
| Standard terms confirmation | A facility's move to standard terms follows its earliest pilot, in two steps: the facility confirms, then a platform admin approves. On day 81 and day 91 its facility admin and front desk are emailed, with a button to the facility's terms page (`/terms/<facility id>`): the plans, the Ksh 100 charge where the agreement includes it, and **Confirm standard terms** for its front desk or facility admin (or a platform admin for it). From day 91 until confirmed, its consultants, front desk and facility admin see a warning on every page linking there; after the 30-day grace period (day 121) platform admins are emailed and the warning stays. Confirming emails the platform admins and shows them a warning until they **Approve standard terms**; approving emails the facility. Nothing is charged until approved: `Pilot::onStandardTerms()` needs the consultant past their own day 90 and their facility's terms approved. `php yii pilot/notify` (daily timer) sends the day 81, 91 and 121 emails; `pilot/confirm-standard` and `pilot/approve-standard` do the two steps from the console. | `feature/standard-terms` |
| F22 Commitment fee at booking | Platform admins set each consultant's plan (`pilot/plan`; everyone starts on Plan A, insured patients paying). From day 31 of a Plan A consultant's pilot, a booking asks cash or insurance, the M-Pesa number that pays (the patient's, editable) and the booking terms, wherever it is made (calendar, booking link, waiting list, appointment form). `FeeRules::decide()` settles it: nothing on Plan B, in days 1–30, for a same-day walk-in where the facility exempts them, or when the patient already paid that day; absorbed by the consultant for an insured patient when they chose so (confirmed at once, no prompt); otherwise charged. A charged booking is saved **awaiting payment**, holds its slot for 10 minutes, and the M-Pesa prompt goes out once it is committed; the "Check your phone" page (`/pay/<id>/<signature>`) follows the payment, sends the prompt again and cancels. When M-Pesa reports the payment the booking is scheduled and the usual confirmation and reminders go out. `php yii fee/release` (every minute) asks M-Pesa about holds that ran out and releases the unpaid ones as **unpaid** (not a cancellation: no notices, not in the figures); the patient is emailed (and WhatsApped with consent) a link to book again. A payment arriving after release books the time again if it is still free, else is noted for a refund. Held bookings can be cancelled, not moved. Off until `COMMITMENT_FEE_ENABLED=1`. | `feature/commitment-fee-rules`, `feature/commitment-fee-payment` |
| F23 Fee after booking | When the clinic cancels (anything the patient did not ask for, a facility closure included), a paid fee becomes a **refundable credit** and an absorbed one is not billed. When the patient cancels more than 24 hours ahead, a paid fee becomes a credit (not refundable); later, or a no-show, forfeits it (a no-show corrected to attended restores it). A moved booking keeps its fee; a patient cannot move a paid booking themselves in its last 24 hours. A credit (`patient_credit`) pays the fee of the next booking with any consultant within 90 days, confirming it at once; `fee/release` expires old ones. The patient is emailed (and WhatsApped with consent) a link to the credit's page (`/credit/<id>/<signature>`), where a refundable one can be asked back to an M-Pesa number. A payment that arrives after its time was taken also becomes a refundable credit. `FeeLifecycle` approves refunds (sent by B2C when `MPESA_B2C_PAYOUTS_ENABLED=1`), records ones paid by hand with their M-Pesa reference, and declines them (the credit stays usable). | `feature/fee-after-booking` |
| F24 Front-desk incentive | Ksh 50 (`FRONTDESK_INCENTIVE`) for each booking a front-desk user made ahead (a staff booking, not a same-day walk-in) whose commitment fee was paid or absorbed: **pending** from then, **earned** when the patient attends, **cancelled** on a no-show or any cancellation (a no-show corrected to attended earns it again). When the same front desk cancelled an appointment of the same patient in the 24 hours before rebooking, the new incentive is cancelled at once and **flagged**. Every Monday, `php yii incentive/payouts` groups each front-desk user's earned incentives up to Sunday into one payout to the phone on their account (**held** without a Kenyan number until the account is fixed). A platform admin sends it by B2C (`MPESA_B2C_PAYOUTS_ENABLED=1`) or pays by hand and records the M-Pesa reference; the incentives are then paid, and no longer cancelled by later changes. | `feature/frontdesk-incentive` |
| Admin area, first screens (F28 started) | The backend app, at `/admin`, is the platform admins' area. It shares the `user` table and passwords with the frontend but has its own session, and signs in **only platform admins** (others get "Incorrect username or password", and a staff member already signed in is signed out and refused). Home shows what waits: refund requests and payouts to send. **Refunds** (F23): approve (sent by B2C when `MPESA_B2C_PAYOUTS_ENABLED=1`), record one paid by hand with its M-Pesa reference, or decline with a reason. **Weekly payouts** (F24): each front-desk user's week with its bookings; send by B2C, record a manual payment, check a held one's phone again, or build last week's now. | `feature/admin-backend` |
| Admin area: pilots, plans and payments (F28) | **Pilots** in `/admin`: every facility with its current agreement, consultants, pilot day and standard-terms status, approving confirmed terms from the list. A facility's page records a signed agreement (a new one replaces the current one and keeps the history; the facility confirms standard terms again for it, and notices already sent are not sent again), records that the facility left, confirms standard terms on the facility's word, approves them, and lists its consultants. **Consultants and plans**: each consultant's first booking, pilot day and phase; change Plan A/B and whether insured patients pay, or restart a pilot started by test bookings (all audited). **Payments**: commitment fees with their M-Pesa receipts (phones masked), filtered by state, facility, consultant and dates, with totals per state; patients' credits. Home also shows standard terms waiting for approval and facilities taking bookings with no agreement. The two admin areas link to each other (the main site keeps consultants, facilities, staff, RBAC and the audit log). On the main site, a facility admin's **Consultants** page invites a consultant who works elsewhere (they accept on Availability) or adds a new consultant account that starts at their facility with the default week (permission `manageFacilityConsultants`, rule `facilityAdmin`; consultancy admins use it with `?facility=<id>`). The console's `pilot/plan` and `pilot/restart` use the same code. | `feature/admin-pilots` |

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

### Patients sign in with their phone number

Login and signup have **Sign in (up) with your phone number**: the patient enters their number, gets a
6-digit code by WhatsApp (SMS if WhatsApp cannot send, or on request after a minute), and enters it.
Then:

- **one or more patient accounts on the number** (families share numbers): they pick theirs from a
  list of first names and initials ("Mary W."), and are signed in;
- **none**: they give their name and a patient account is created (no email, no password), followed by
  the consent page as for any new patient.

Rules:

- **Patients only.** Staff accounts (consultants, front desk, facility admins, admins) are never
  offered; they sign in with a password, Google or LinkedIn.
- **Which accounts a number reaches:** accounts whose number was proven with a code before, and
  accounts without an email (walk-ins, whose number the clinic entered). An account a patient
  registered with an email carries whatever number they typed, so it is not offered until its owner
  signs in once with their email (or Google/LinkedIn) right after proving the number; the page says so.
- **Codes:** only a keyed hash is stored (`login_code`); valid 10 minutes, once; 5 wrong tries end a
  code; a new code cancels the old one.
- **Limits:** 3 codes per number per 15 minutes, 10 per day, 10 per IP address per hour (from the
  `login_code` table, so they hold across servers).
- **Numbers:** `user.phone_key` holds each number in one form (`254712345678`) to find accounts; the
  number as typed stays in `phone_number`. Changing the number clears its proof.
- The audit log records these sign-ins "via phone", and wrong or expired codes as failed sign-ins.
- The option shows only when phone sign-in is on (`PHONE_SIGN_IN`) and WhatsApp or SMS can send.

SMS now goes through `common\components\SmsClient` with Africa's Talking (the usual provider in Kenya)
or Twilio, configured in `.env`; the SMS reminder job uses it too. The placeholder Twilio values in
`params.php` are gone, so SMS stays off until a provider is configured.

1. **Migrate:** `php yii migrate` (`m261128_090000_phone_sign_in`: `user.phone_key`,
   `user.phone_verified_at`, the `login_code` table; fills `phone_key` for existing accounts and prints
   how many numbers could not be read). No `rbac/init`.
2. **`.env` (optional, defaults shown):**
   - `PHONE_SIGN_IN=1` (0 hides it);
   - `LOGIN_CODE_TTL_MINUTES=10`, `LOGIN_CODE_PER_PHONE_15MIN=3`, `LOGIN_CODE_PER_PHONE_DAY=10`,
     `LOGIN_CODE_PER_IP_HOUR=10`;
   - **SMS fallback:** `SMS_PROVIDER=africastalking` with `AT_USERNAME`, `AT_API_KEY` and, if you have a
     registered sender ID, `AT_SENDER_ID` (`AT_USERNAME=sandbox` uses Africa's Talking's test API); or
     `SMS_PROVIDER=twilio` with `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM`. Without one, codes go by
     WhatsApp only.
3. **Restart:** `sudo systemctl restart doctors-appointment.target` (the queue worker's SMS jobs use the
   new SMS client).
4. **Check:**
   - **Log in → Sign in with your phone number** with a walk-in patient's number: the WhatsApp code
     signs in to that patient (several on one number: a list to pick from);
   - with a new number: **Create my account**, then the consent page;
   - stop WAHA (or use a number not on WhatsApp) with SMS configured: the code comes by SMS.

### Google One Tap

On the login and signup pages, a browser signed in to Google shows Google's **Continue as …**
prompt: one tap signs in (or creates a patient account), without the trip to Google's page. The
Google button stays, for everyone else (not signed in to Google; Safari limits the prompt).

The prompt hands the page a Google ID token, which it posts with our CSRF token to
`google-one-tap/sign-in`. The server checks it is signed by Google (Google's published keys, cached
as long as Google says, at most a day), issued by Google, for our client ID and not expired
(`common\authclient\GoogleIdToken`, with `firebase/php-jwt`). Then it signs in exactly as the Google
button does (`frontend\services\SocialSignIn`): the same Google account id, so accounts already
linked to Google are the same accounts, and an unverified email never takes over an account.

Google's script now loads on the login and signup pages, so Google sees those visits; the privacy
notice says so (one line, version unchanged).

1. **Google Cloud console** → APIs & Services → Credentials → the OAuth client used for
   `GOOGLE_CLIENT_ID` → **Authorized JavaScript origins:** add `https://<your host>` (the redirect URI
   for the button stays as it is).
2. **nginx:** the security headers snippet changed (Content-Security-Policy allows
   `accounts.google.com/gsi` for the script, its style, its frame and its calls; Cross-Origin-Opener-Policy
   is now `same-origin-allow-popups`, as Google's prompt may finish in a popup). Copy
   `deploy/nginx/snippets/appointments-security-headers.conf` to the server, then
   `sudo nginx -t && sudo systemctl reload nginx`.
3. **Deploy:** pull, `composer install` (adds `firebase/php-jwt`). No migration, no `rbac/init`.
   `GOOGLE_ONE_TAP=0` in `.env` hides the prompt.
4. **Check:** open the login page in a browser signed in to Google: the prompt shows top right
   (bottom on phones); **Continue** signs in. The browser console shows no CSP errors.

### Fix: the calendar's arrows and views did nothing

For a consultant at two or more facilities (or with alternate weeks or one-off sessions), the
calendar's ‹ › arrows and the month / week / day / list buttons did nothing: front desk could not
move to next week. The coloured facility blocks have no description, and the hover tooltip
(Bootstrap 5) throws on an empty title; the error stopped the calendar mid-render. Tooltips now use
the description, reason or title, are skipped when there is none, and can never stop the calendar
(`addEventTooltip` in `Js/custom.js`, used by both calendars).

Our scripts and styles now carry `?v=<file time>`, so browsers pick up changes after a deploy.

1. **Deploy:** pull. Nothing else.
2. **Check:** as front desk, open the calendar of a consultant who works at two facilities: › moves
   to next week, **month** shows the month, and clicking a free time next week opens the booking
   form.

### F4 Waiting list

A patient who cannot find a suitable time waits for one, and is told when it frees up.

- **Joining.** Patients join from a doctor's calendar (**Notify me when a time frees up**: a facility,
  a window of up to 30 days, any time / mornings / afternoons), or ask for **something earlier**
  than one of their appointments (from the appointment page or their self-service link). The
  consultant and their front desk add patients who phone in (**Add to the waiting list** on the
  calendar, or **Wants an earlier time** on an appointment); the patient is sent their link.
- **Telling patients.** When an appointment is cancelled or moved, a queue job checks that
  consultant's waiting list; everyone whose window has a new free time is told at once, by email
  and (with their consent, F11) WhatsApp. The first to book gets the time (the booking lock settles
  it); latecomers see "just taken" and the other free times. A time is announced once per patient,
  at least 2 hours ahead, not between 21:00 and 07:00, and at most 3 times a day per patient.
- **The link** (`/waitlist/<id>/<signature>`, no sign-in, signed like self-service links) shows the
  free times in the window, books one, or leaves the list. "Something earlier" moves the
  appointment instead of booking a second one, which frees its old time for the next patient.
- **Ending.** An entry ends when the patient books (through the link or any other way, inside the
  window), leaves, or its window passes, or when the appointment it would move is cancelled.
- **Walk-ins.** When the consultant or front desk books on the calendar, **Also put the patient on
  the waiting list for an earlier time** books the next free slot and puts the patient on the list
  for anything sooner. For an appointment today the patient is already at (or near) the clinic,
  so times are offered from 15 minutes ahead (`waitlist.sameDayLeadMinutes`), not 2 hours.
- **Staff** see who is waiting (**Waiting list (n)** on the board) and can remove entries.
  Patients see theirs under **My waiting list**.

The privacy notice has one more line (version unchanged).

1. **Deploy:** pull, then `php yii migrate` (adds `waitlist_entry`). No `rbac/init`.
2. **Sweep timer:** every 15 minutes it sends notices held over the night, catches times that open
   other ways (new hours, a one-off session, time off removed) and ends old entries:
   ```bash
   sudo cp deploy/systemd/doctors-appointment-waitlist.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-waitlist.timer
   systemctl list-timers doctors-appointment-waitlist.timer   # every 15 minutes
   ```
   Then `sudo systemctl restart doctors-appointment.target`, so the queue worker loads the new code.
3. **.env:** `APP_PUBLIC_URL` and `SELF_SERVICE_KEY` must be set (already needed for self-service
   links): the queue worker builds the links. `WAITLIST=0` switches the waiting list off.
4. **Check:** as a patient, open a doctor's calendar → **Notify me when a time frees up** → join.
   Cancel an appointment with that doctor in the window: within a minute the patient gets an email
   with their link, and can book the freed time.

### T4 CI on Gitea Actions

The workflow is `.gitea/workflows/ci.yml`. On every push and pull request it starts MySQL 8, installs
PHP 8.3 (as on the server), runs `php init`, builds the test database (RBAC tables, the app's and the
queue's migrations, `rbac/init`) and runs every Codeception suite. Nothing changes on the app server.

1. **Actions on the Gitea server** (`git.kemri.go.ke`): Actions is on by default from Gitea 1.21
   (`[actions] ENABLED = true` in `app.ini`). In the repository, **Settings → Repository → Actions**
   must be ticked.
2. **A runner**, on any Linux machine with Docker and about 2 GB of free memory (MySQL and PHP run side
   by side). Get a registration token from **Settings → Actions → Runners → Create new runner**, then:
   ```bash
   docker run -d --name healthfirst-runner --restart unless-stopped      -e GITEA_INSTANCE_URL=https://git.kemri.go.ke      -e GITEA_RUNNER_REGISTRATION_TOKEN=<token>      -e GITEA_RUNNER_NAME=healthfirst-ci      -e GITEA_RUNNER_LABELS=ubuntu-latest:docker://gitea/runner-images:ubuntu-latest      -v /var/run/docker.sock:/var/run/docker.sock      -v healthfirst-runner-data:/data      gitea/act_runner:latest
   ```
   The `ubuntu-latest` label must be there: the workflow asks for it.
3. **Network:** the runner downloads Docker images (`mysql:8.0`, the runner image), the actions from
   github.com, PHP from the setup-php sources, and packages from packagist.org. If a firewall blocks
   any of them, the first run fails at that step.
4. **Check:** push a branch. The repository's **Actions** tab shows **CI** running; the first run takes
   longer while images and packages download, later ones about 25 minutes. Every step goes green.

#### Deploying from CI

Pushing a `vX.Y.Z` tag runs the tests on it and, if they pass, the **deploy** job. That job connects to
the server over SSH with a key that can do only one thing: run `deploy/deploy.sh`, installed as
`/usr/local/bin/healthfirst-deploy`, with the tag. The script:

1. checks that the tag exists and is on `main`, and that nobody changed tracked files on the server;
2. backs up the database (`/var/backups/healthfirst`, the newest 10 kept);
3. checks out the tag, runs `composer install --no-dev` (going back to the previous release if that
   fails), `php yii migrate` and `php yii rbac/init`;
4. reloads PHP-FPM, restarts `doctors-appointment.target`, and checks that `/health` answers `ok`.

If a step fails, the job turns red and the log names the step, the previous commit and the backup.

**One-time setup on the server:**

1. **A deploy user** that does nothing else: `sudo adduser --disabled-password --gecos "" deploy`.
2. **The script**, owned by root so the CI key cannot change it:
   ```bash
   sudo install -o root -g root -m 755 deploy/deploy.sh /usr/local/bin/healthfirst-deploy
   ```
   Run this again whenever `deploy/deploy.sh` changes; CI never updates it.
3. **Permission to run it**, and nothing more:
   ```bash
   echo 'deploy ALL=(root) NOPASSWD: /usr/local/bin/healthfirst-deploy' | sudo tee /etc/sudoers.d/healthfirst-deploy
   sudo chmod 440 /etc/sudoers.d/healthfirst-deploy && sudo visudo -c
   ```
4. **A key for CI.** Make a key pair anywhere (`ssh-keygen -t ed25519 -f healthfirst-ci -N "" -C healthfirst-ci`)
   and put the public key in `/home/deploy/.ssh/authorized_keys` (directory `700`, file `600`, owned by
   `deploy`) with the command fixed:
   ```
   restrict,command="sudo /usr/local/bin/healthfirst-deploy \"$SSH_ORIGINAL_COMMAND\"" ssh-ed25519 AAAA… healthfirst-ci
   ```
5. **Where the server fetches from.** The script fetches the tag from the app checkout's `origin`. Point
   it at Gitea with read-only access (a deploy key under the repository's **Settings → Deploy Keys**, for
   the user that owns `/var/www/html/doctors-appointment`), or keep GitHub, as long as tags are pushed
   there too. Check: `sudo -u <owner> git -C /var/www/html/doctors-appointment fetch --tags`.
6. **The MySQL client** for the backup: `mysqldump --version` (install `mysql-client` if missing). The
   script reads the database settings from `.env`.
7. **Optional settings** in `/etc/default/healthfirst-deploy`, using the names at the top of the
   script: for example `HEALTH_URL`, `PHP_FPM_SERVICE` or `RELEASE_BRANCH`.
8. **Try it by hand** with the release already running, which changes nothing:
   `sudo healthfirst-deploy $(git -C /var/www/html/doctors-appointment describe --tags --abbrev=0)`.

**In Gitea** (**Settings → Actions → Secrets** of the repository):

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | the server's address, as the runner reaches it |
| `DEPLOY_PORT` | the SSH port; leave unset for 22 |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | the whole private key file `healthfirst-ci` |
| `DEPLOY_KNOWN_HOSTS` | the output of `ssh-keyscan -p <port> <host>`; compare its fingerprint with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server |

The runner must be able to reach the server's SSH port.

**Releasing:**

```bash
git checkout main && git merge --no-ff develop
git tag -a v1.2.0 -m "v1.2.0"
git push origin main v1.2.0
```

The tag's run shows **tests**, then **deploy**. To go back, push or re-run an older tag: the code goes
back, but migrations do not, so restore the backup by hand if a migration must be undone.

### T11 M-Pesa (Daraja)

`common\components\Mpesa` (`Yii::$app->mpesa`) sends STK pushes (a patient pays the platform from
their phone), asks Safaricom how one ended, and makes B2C payments (refunds, front-desk payouts).
Each request is a row in `mpesa_transaction`. Safaricom answers at `POST /mpesa/stk/<token>`,
`/mpesa/b2c-result/<token>` and `/mpesa/b2c-timeout/<token>`:

- a wrong token gets a 404;
- each transaction is settled once, so a repeated callback changes nothing;
- an STK success counts only when a status query confirms it;
- `php yii mpesa/reconcile` settles payments whose callback never came.

When a transaction is settled, `Mpesa::EVENT_SETTLED` is raised. The commitment-fee features (F22 to
F24) listen for it. Nothing asks patients to pay yet.

1. **Database:** `php yii migrate` creates `mpesa_transaction` (`m261130_090000_create_mpesa_transaction_table`).
2. **`.env`:**
   ```
   MPESA_ENV=sandbox                      # production once Safaricom approves go-live
   MPESA_CONSUMER_KEY=                    # from the Daraja app
   MPESA_CONSUMER_SECRET=
   MPESA_SHORTCODE=174379                 # sandbox paybill; the live paybill or till after go-live
   MPESA_PASSKEY=                         # sandbox passkey from the Daraja simulator; Safaricom emails the live one
   MPESA_TRANSACTION_TYPE=CustomerPayBillOnline   # CustomerBuyGoodsOnline for a till
   MPESA_CALLBACK_URL=https://appointments.gumzosystems.tech   # this site's public address; empty = APP_PUBLIC_URL
   MPESA_CALLBACK_TOKEN=                  # a long random secret: php -r 'echo bin2hex(random_bytes(24)), "\n";'
   # B2C (refunds, payouts)
   MPESA_B2C_SHORTCODE=                   # sandbox: the 600xxx shortcode on Daraja's test credentials page
   MPESA_INITIATOR_NAME=                  # sandbox: testapi
   MPESA_SECURITY_CREDENTIAL=             # the initiator password encrypted with Safaricom's certificate
   MPESA_CA_FILE=                         # leave empty on Linux; only for a PHP with no CA bundle
   ```
   Values with spaces need double quotes. Changing the token changes the callback addresses, so
   transactions still waiting for an answer are settled by `mpesa/reconcile` instead.
3. **Timer** for `mpesa/reconcile`:
   ```bash
   sudo cp deploy/systemd/doctors-appointment-mpesa.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-mpesa.timer
   sudo systemctl restart doctors-appointment.target
   ```
4. **Reachable callbacks:** Safaricom posts to the site over HTTPS from the internet. Nothing in
   nginx may block `POST /mpesa/…`.
5. **Check:**
   - `sudo -u www-data php yii mpesa/check`: settings (secrets hidden), the callback address, and
     "Access token: OK";
   - on the sandbox, `sudo -u www-data php yii mpesa/test-stk 07XXXXXXXX 1`: the phone shows a
     prompt for KES 1 (no money moves on the sandbox), and the command reports **succeeded** with a
     receipt, or **cancelled** if the prompt is declined;
   - `sudo -u www-data php yii mpesa/status <id>` shows one transaction.
6. **Going live** (when the pilot's fees start): apply for go-live on the Daraja portal for the
   paybill. Then set the live consumer key and secret, shortcode, passkey and B2C credentials,
   `MPESA_ENV=production`, and run `mpesa/check` again. Production B2C credentials take longer, so
   apply early.

### F20 Pilot groundwork

What the 90-day pilot needs recorded from the first booking: month 1 is each consultant's no-show
baseline. Nothing is charged yet.

1. **Database:** `php yii migrate` runs `m261201_090000_pilot_groundwork`: `booking_channel` and the
   `cancelled_*` columns on `appointments`, `first_booking_at` on `consultant`, and the
   `facility_agreement` table. Existing appointments keep an empty channel, and existing consultants
   an empty pilot start: their pilot starts at their next booking.
2. **Test bookings:** a test booking made after the deploy starts that consultant's pilot. Undo it
   with `sudo -u www-data php yii pilot/restart <consultant user id>`.
3. **Agreements:** record each facility's signed agreement:
   ```bash
   sudo -u www-data php yii pilot/agreement <facility id> "Jane Doe, Medical Director" "Gumzo HealthFirst" --version=1.2 --signedOn=2026-11-20
   ```
   Add `--walkInExempt=0` if the facility chose to charge same-day walk-ins.
4. **Check:** `sudo -u www-data php yii pilot/status` lists each consultant's first booking, pilot day
   and phase, and each facility's agreement. As staff, cancelling an appointment now asks why.

### Standard terms confirmation

1. **Database:** `php yii migrate` runs `m261202_090000_standard_terms_confirmation` (who confirmed,
   and which notices went out, on `facility_agreement`).
2. **Timer** for the daily notices:
   ```bash
   sudo cp deploy/systemd/doctors-appointment-pilot.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-pilot.timer
   sudo systemctl restart doctors-appointment.target
   ```
3. **Who gets the emails:** active facility admin and front-desk accounts whose facility is set, with
   an email. Check each facility has at least one.
4. **Check:** `sudo -u www-data php yii pilot/status` shows each facility's pilot day and where its
   standard terms stand; `sudo -u www-data php yii pilot/notify` sends what is due (safe to repeat).
5. **The two steps:** the facility confirms on `/terms/<facility id>`; a platform admin then approves
   there (they see a warning on every page until they do). From the console:
   `php yii pilot/confirm-standard <facility id>` (for the facility) and
   `php yii pilot/approve-standard <facility id>`.

### Admin area: pilots, plans and payments (F28)

1. **Permissions:** `php yii rbac/init` adds `manageFacilityConsultants` (consultancy admins) and
   `manageOwnFacilityConsultants` (facility admins, rule `facilityAdmin`). Without it, facility admins
   don't see **Consultants** in the sidebar.
2. **No migration.** Restart the workers so they load the new code:
   `sudo systemctl restart doctors-appointment.target`.
3. **Agreements:** record each facility's signed agreement under **Pilots** in `/admin` (instead of
   `php yii pilot/agreement`). Home lists the facilities taking bookings that have none.
4. **Check:** `/admin` shows Pilots and Payments in the menu, and the main site's sidebar shows
   **Admin Area** for platform admins.

### Admin area at /admin

1. **nginx:** the site config now has an `/admin` section serving `backend/web`
   ([deploy/nginx/appointments.gumzosystems.tech.conf](../deploy/nginx/appointments.gumzosystems.tech.conf)).
   Copy the section into the server's config, with the same path as the rest (on the current
   server the app is in `/var/www/html/doctors-appointment`), then `sudo nginx -t && sudo systemctl
   reload nginx`.
2. **Files:** `php init --env=Production` already made `backend/web/index.php` and
   `backend/config/*-local.php`. The web server writes to `backend/runtime` and
   `backend/web/assets`: `sudo chown -R www-data:www-data backend/runtime backend/web/assets`.
3. **Who gets in:** platform admins (`php yii rbac/assign-admin <username>` makes one; `rbac/list-admins` lists them). They sign in
   at `https://appointments.gumzosystems.tech/admin/` with their usual username and password.
4. **Check:** sign in as a platform admin and see "Waiting for you"; signing in as anyone else says
   "Incorrect username or password".

### F24 Front-desk incentive

1. **Database:** `php yii migrate` runs `m261206_090000_frontdesk_incentive` (`frontdesk_incentive`,
   `incentive_payout`).
2. **`.env`:** `FRONTDESK_INCENTIVE=50` (already there).
3. **Timer** building the weekly payouts every Monday at 06:00:
   ```bash
   sudo cp deploy/systemd/doctors-appointment-incentives.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-incentives.timer
   sudo systemctl restart doctors-appointment.target
   ```
4. **Front-desk phones:** each front-desk account needs a Kenyan phone number, or its payout is held.
   Payouts are sent or recorded in the backend.

### F23 Fee after booking

1. **Database:** `php yii migrate` runs `m261205_090000_patient_credit`.
2. **`.env`:** `MPESA_B2C_PAYOUTS_ENABLED=0` until live B2C credentials are in (see T11). With it
   off, approved refunds are paid by hand and recorded with their M-Pesa reference.
3. Nothing else: the fee timer (`fee/release`) also expires credits. Refunds are approved in the
   backend (see the next item).

### F22 Commitment fee, part 2: paying at booking

1. **Database:** `php yii migrate` runs `m261204_090000_commitment_fee_payment` (`hold_expires_at` on
   `appointments`, `terms_accepted_at` on `commitment_fee`).
2. **Timer** releasing unpaid bookings every minute:
   ```bash
   sudo cp deploy/systemd/doctors-appointment-fees.* deploy/systemd/doctors-appointment.target /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now doctors-appointment-fees.timer
   sudo systemctl restart doctors-appointment.target
   ```
3. **Switching fees on** (only with live M-Pesa credentials in `.env`, see T11 "Going live"):
   `COMMITMENT_FEE_ENABLED=1`, then reload PHP-FPM and restart `doctors-appointment.target`. Nothing
   is charged before a consultant's day 31, and never on Plan B.
4. **Check on a test server** with the Daraja sandbox and `COMMITMENT_FEE_ENABLED=1`: give a test
   consultant a pilot past day 30, book as a patient with a sandbox number, answer the prompt, and
   see the booking confirmed; book again and ignore the prompt, and see it released after 10
   minutes (`journalctl -u doctors-appointment-fees`).

### F22 Commitment fee, part 1: plans and rules

Nothing is charged yet: this part stores each consultant's plan and decides which bookings would
carry the fee. Payment at booking comes in part 2.

1. **Database:** `php yii migrate` runs `m261203_090000_commitment_fee`: `plan` (A) and `insured_pay`
   (yes) on `consultant`, `payment_mode` on `appointments`, and the `commitment_fee` table.
2. **`.env`** (fees stay off until live M-Pesa credentials are in):
   ```
   COMMITMENT_FEE_ENABLED=0          # 1 to charge; leave 0 while M-Pesa is on the sandbox
   COMMITMENT_FEE=250
   COMMITMENT_FEE_HOLD_MINUTES=10
   ```
3. **Plans:** every consultant starts on Plan A with insured patients paying. Change one with
   `sudo -u www-data php yii pilot/plan <consultant user id> B`, or keep Plan A and absorb the fee for
   insured patients with `pilot/plan <consultant user id> A --insuredPay=0`.
4. **Check:** `sudo -u www-data php yii pilot/status` lists each consultant's plan, and whether fees
   are on.

### F21 Masked contacts

No migration or settings. After deploying, check:

- the board and the users list show patients' numbers as `0712***678` and emails as `j***@mail.com`;
- opening an appointment as staff shows them in full and adds "Patient's contact viewed" to the
  audit log, once a day per staff member and appointment.

---

## Deferred

| Item | Why | Needs before starting |
|---|---|---|
| G3 Triage and priority (from USER_STORIES.md) | Deferred on 15 Nov 2026: bookings are first come, first served, and it is not yet clear the clinics want an override. | A decision on who sets urgency (patient, front desk or consultant) and what it changes: queue order on the day, or access to held-back slots. |
| G8 Rooms and equipment (from USER_STORIES.md) | Deferred on 15 Nov 2026: capacity is the consultants' hours, which is enough while each consultant has a room. | A facility that shares rooms or equipment between consultants, and how it books them today. |
| F10 Kiswahili translation | Deferred on 11 Nov 2026. Approach agreed in outline: Yii message files, a per-user language, patient-facing pages and all outgoing messages first, then staff screens. | A translator or reviewer for medical and legal wording, the default language for walk-ins, and the PHP `intl` extension on the server. |

---

## Open

Priority: **P1** do next, **P2** soon, **P3** when there is time. Impact is the expected change to the scores above.

### Functional

#### Patient communication

| # | Item | Priority | Impact |
|---|---|---|---|
| F2 | WhatsApp booking confirmations for every booking, not only booking links. | P2 | |
| F14 | Tell affected patients when a facility closes a day or a consultant adds time off, with a link to pick a new time, and let staff move the clashing bookings in bulk instead of one by one. (from G7) | P1 | +0.5 |

#### Front desk and the day of the visit

| # | Item | Priority | Impact |
|---|---|---|---|
| F13 | Front desk can confirm appointments for their consultants, for example after a patient confirms by phone. Today only the consultant can. (from G2) | P1 | |
| F15 | Check-in on the day: "arrived" and "in consultation" states and a waiting-room queue for the front desk. (from G1) | P2 | +0.5 |
| F18 | Capture the patient's insurer and member number at booking, check them against the covers the consultant accepts, and let the front desk record pre-authorisation (pending, approved, declined). Cash or insurance itself is asked from day 31 under F22. Needed by **day 61** of the pilot ([business case](business-case.md)). (from G5) | P1 | |
| F19 | A structured intake form (reason, history, medication, allergies) in place of the free-text symptoms brief. (from G4) | P3 | |

#### Facility admins

| # | Item | Priority | Impact |
|---|---|---|---|
| F16 | Capacity use: booked minutes as a share of available minutes, per consultant and per facility, on the dashboard. (from G9) | P2 | |
| F17 | Facility admins invite a consultant to their facility directly and edit that consultant's hours there. Needs a decision on what the consultant must approve. (from G10) | P2 | |

#### Commercial pilot

From [business-case.md](business-case.md) and pilot agreement v1.2. Each consultant's 90-day pilot runs
from their first booking; "day N" below counts from the first consultant to get a booking at the first
pilot facility. Amounts and M-Pesa credentials come from `.env`.

| # | Item | Priority | Impact |
|---|---|---|---|
| ~~F5~~ | ~~M-Pesa deposits for new or no-show-prone patients.~~ **Replaced by F23–F25** (from G12). | — | |
| ~~F20~~ | ~~Pilot groundwork: every booking records its channel (online, staff, booking link, walk-in); every cancellation records who, on whose side and whether the patient asked; each consultant's first booking is stored once; facility agreements (version, signatories, date) are recorded.~~ **Done** (see Done, Commercial pilot). | — | |
| ~~F21~~ | ~~Mask patients' phone numbers and emails in lists, boards, search results and exports; show them in full when staff open an appointment they may handle, and audit that view.~~ **Done** (see Done, Commercial pilot). | — | |
| ~~F22~~ | ~~Commitment fee at booking: Ksh 250 by STK push with the slot held while the patient pays; exemptions; patient booking terms; cash or insurance, and each consultant's choice to absorb the fee for insured patients.~~ **Done** (see Done, Commercial pilot). Switched on per server with `COMMITMENT_FEE_ENABLED=1`. | — | |
| ~~F23~~ | ~~Fee after booking: moves with a reschedule more than 24 hours ahead; kept on a no-show or a later cancellation; a free rebooking credit when the clinic cancels, refunded to M-Pesa if the patient asks.~~ **Done** (see Done, Commercial pilot); the refund approval screen comes with the backend. | — | |
| ~~F24~~ | ~~Front-desk incentive: Ksh 50 per completed Plan A booking, pending until then; cancelled on a no-show, a cancellation, or a cancel-and-rebook of the same patient within 24 hours (flagged); paid weekly, by hand with an M-Pesa reference until B2C is live.~~ **Done** (see Done, Commercial pilot); the payout screen comes with the backend. | — | |
| F25 | Consultant invoices: monthly statements of absorbed fees from day 31; from day 91, the plan charge per active consultant (one with an appointment that month) and Ksh 100 per cancellation by the consultant or their front desk (not at the patient's request or for a closure), emailed each time. Paid by STK push; reminders, then online booking paused 14 days after the due date. | P1 | |
| F26 | The 90-day pilot report: each consultant's month 1 against months 2 and 3 (no-shows, late cancellations, advance bookings against walk-ins, fees collected, absorbed, credited and refunded, incentives), added up per facility. Needed by **day 90**. | P2 | |
| F27 | A facility leaving: unused patient credits refunded, earned incentives paid, pending ones paid only if completed before the exit date. Needed by **day 91**. | P3 | |
| F28 | **Mostly done**: the backend at `/admin` with platform-admin sign-in, refunds and weekly payouts, pilots and agreements, consultants' plans and payments; facility admins invite and add consultants at their facility (see Done). Still to come: invoices with F25 and the pilot report with F26. Moving the main site's admin pages (consultants, facilities, staff, RBAC, audit log) into `/admin` as one module is deferred; the two link to each other. | P1 | |

#### Scheduling depth

| # | Item | Priority | Impact |
|---|---|---|---|
| F6 | Visit types with their own lengths (first consultation, review…); the unused `service_id` column is available. | P2 | |
| F7 | Recurring appointments (the `recurring_appointment` field exists but nothing uses it). (from G6) | P3 | |
| F8 | Consultants engaged at several facilities: one calendar, hours, session lengths, fees and front desk per facility, patient history scoped to a facility. In three phases; see **F8 Multiple facilities** below. **All three phases done** (`feature/facilities`, `feature/facilities-phase2`, `feature/facilities-phase3`), plus facility admins (`feature/facility-admins`). | P3 | Done |
| F9 | Calendar sync (iCal / Google) for consultants. **Done** with F8 phase 3: a read-only iCal feed. Two-way sync (Google Calendar API) is not planned. | P3 | Done |
| F10 | Swahili translation (see **Deferred**). (from G13) | P3 | |

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
| ~~T4~~ | ~~CI that runs the test suites on every push.~~ **Done** on Gitea Actions, with deploys of release tags. PHPStan joins it under T5. | — | +0.5 (with T2) |
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
| T10 | Waiting-list notices for new hours, removed time off and new sessions go out when the change is saved, not on the 15-minute `waitlist/sweep`. (from G11) | P3 | |

#### Payments integration

| # | Item | Priority | Impact |
|---|---|---|---|
| ~~T11~~ | ~~M-Pesa (Daraja) component: STK push, status query and B2C, set from `.env`.~~ **Done** in v1.2.0; tested on production against the Daraja sandbox on 16 Nov 2026. | — | |

## Next up

1. **F22, F23, F24, F28** (payouts) The fee, its rules and incentives, by day 31 of the pilot (F20 and F21, needed by day 1, are done).
2. **F13** Front desk confirms appointments (a small permission change).
3. **F14** Patients are told when a closure or time off clashes with their booking.
4. **F25, F18, F26** Invoices, insurer details and the pilot report, on the pilot's dates.
5. **F12** Patients export and delete their data (completes the closure requests from F11).
