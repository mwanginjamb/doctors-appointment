# Features and backlog

The state of the doctor-appointments app as a clinic booking system: what has been built, what was
deliberately deferred, and what is still open. Keep this file up to date when an item is done.

_Last reviewed: 10 November 2026._

## Where it stands

| | Score | Summary |
|---|---|---|
| Functional | **8.5 / 10** | Covers every way an appointment is made (online, by staff, walk-in, remote walk-in link), realistic availability, the full appointment lifecycle and a usable back office. Patients are told about changes and can reschedule or cancel from a link. No payments yet. |
| Technical | **7 / 10** | Booking rules are central, locked against double booking and covered by passing tests; debug mode is off in production. Two permission systems sit side by side, a lot of logic lives in views, and there is no CI or error tracking. |

Doing **Next up** below should bring the app to about 9 functionally and 8 technically.

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

---

## Deferred

| Item | Why | Needs before starting |
|---|---|---|
| M-Pesa deposits (STK push) | A project of its own. | Safaricom Daraja credentials (shortcode, passkey, API keys), a public callback URL, and a refund / expiry policy. |

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
| F8 | Rooms and multiple locations. | P3 | |
| F9 | Calendar sync (iCal / Google) for consultants. | P3 | |
| F10 | Swahili translation. | P3 | |

#### Compliance (Kenya Data Protection Act)

| # | Item | Priority | Impact |
|---|---|---|---|
| F11 | Explicit consent at sign-up and walk-in registration. | P2 | +0.5 (with T7) |
| F12 | Patients can export and delete their data; retention rules for old records. | P2 | |

### Technical

#### Security and operations

| # | Item | Priority | Impact |
|---|---|---|---|
| ~~T1~~ | ~~Turn off debug mode in production.~~ **Done on the server (10 Nov 2026).** | — | +0.5 |
| T2 | Error tracking (e.g. Sentry) and an alert when the queue worker stops or notifications keep failing. | P1 | +0.5 (with T4) |
| T3 | Move WhatsApp from WAHA (unofficial WhatsApp Web bridge) to the official WhatsApp Business API before relying on it at scale. | P2 | |

#### Quality and delivery

| # | Item | Priority | Impact |
|---|---|---|---|
| T4 | CI that runs the test suites (and PHPStan) on every push. A full local run takes about 10 minutes. | P1 | +0.5 (with T2) |
| T5 | Static analysis (PHPStan) at a modest level, raised over time. | P2 | |

#### Architecture

| # | Item | Priority | Impact |
|---|---|---|---|
| T6 | Move the calendar's JavaScript out of `views/appointments/calendar.php` into its own file, and booking rules into a tested service class. | P2 | +0.5 (with T8) |
| T7 | An audit log of who changed what on appointments and accounts, beyond `created_by` / `updated_by`. | P2 | +0.5 (with F11) |
| T8 | Use one permission system: checks mix the `user.role` column and RBAC roles. | P2 | |

#### Scalability

| # | Item | Priority | Impact |
|---|---|---|---|
| T9 | The booking-link rate limit uses the file cache, which only works on one server; use Redis if the app runs on more than one. | P3 | |

## Next up

1. **T2** Error tracking and queue alerts (approach to be agreed).
2. **T4** CI on every push.
3. **F5** M-Pesa deposits, once Daraja credentials are available.
