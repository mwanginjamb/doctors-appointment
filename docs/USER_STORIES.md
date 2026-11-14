# User Stories: Hub and Spoke

These user stories are taken from the code as it is in v1.1.0 (November 2026). Each story links to
the file and line that implements it. Things the product needs but the code does not do yet are
listed under [System Gaps](#system-gaps), not written as stories.

- **The Hub** is the `Medical Consultant`. Their hours, facilities, time off and booking rules
  decide what can be booked.
- **The Spokes** are the roles that use those rules: **Potential Patients (Clients)**, **Front Desk
  Users** and **Facility Admins**.

---

## Entity-Relationship Overview

```
                               ┌──────────────────────────┐
                               │   Facility               │  name, address, phone, map, active
                               │   FacilityClosure (day)  │
                               └───────────▲──────────────┘
                                           │ facility_id
 ┌────────────────────────┐   ┌────────────┴──────────────┐   ┌──────────────────────────────┐
 │ ConsultantWorkingHours │──▶│  ConsultantFacility       │◀──│ FacilityInvitation /         │
 │ (weekly blocks, A/B    │   │  "engagement": session    │   │ FacilityRequest              │
 │  weeks, date bounds)   │   │  length, fee, booking_open│   └──────────────────────────────┘
 ├────────────────────────┤   └────────────▲──────────────┘
 │ ConsultantTimeOff      │                │ consultant_id
 │ ConsultantSession      │   ┌────────────┴──────────────┐      user_id    ┌──────────────┐
 │ ConsultantExternalBlock│──▶│      CONSULTANT (Hub)     │────────────────▶│ User (role:  │
 │ travel_buffer_minutes  │   │ names, specialty, KMPDC,  │                 │ consultant)  │
 │ calendar_feed_hash     │   │ rotation_week_a           │                 └──────────────┘
 └────────────────────────┘   └──┬──────────┬──────────┬──┘
                                 │          │          │
        consultant_id (user id)  │          │          │ consultant_user_id
 ┌───────────────────────────────▼─┐  ┌─────▼────────┐ ┌▼──────────────────────────────────┐
 │ Appointments                    │  │ BookingLink  │ │ FrontDeskAssignment               │
 │ patient_id → User (client)      │  │ WaitlistEntry│ │ user_id → User (front_desk),      │
 │ facility_id, duration, fee,     │  └──────────────┘ │ facility via AssignedFacilityRule │
 │ status: scheduled · confirmed · │                   └───────────────────────────────────┘
 │ completed · no_show · cancelled │
 └─────────────────────────────────┘
```

**How each spoke reaches the Hub**

| Spoke | Account (`user.role`) | RBAC role | How the code limits what they can reach |
|---|---|---|---|
| Potential Patients | `client` (or empty) | `patient` | Only their own appointments (`patient_id` = the signed-in user). Online booking only where the consultant's `booking_open` is on. Signed links let them act without signing in. |
| Front Desk Users | `front_desk` | `front-desk` | Only the consultants they are assigned to (`assignedConsultant` rule), and only at their own facility (`assignedFacility` rule). |
| Facility Admins | `facility_admin` | `facility-admin` | Only their own facility (`facilityAdmin` rule), but every consultant who works there. |
| *(Hub)* Consultant | `consultant` | `consultant` | Only their own calendar, availability and profile (`isTheConsultant` rule). |

- Roles and permissions are defined in `[common/components/RbacHelper.php:108]`, and the rules are
  in `[common/rbac/]`.
- The shortcuts the controllers use (`scope`, `canBookFor`, `canHandle`, `historyCondition`) are in
  `[frontend/components/FrontDesk.php]`.
- **Every booking rule is checked when an appointment is saved**
  `[frontend/services/BookingService.php:103]`, whichever role made the booking.

---

## The Hub: Medical Consultant

The stories in this section set the rules the spokes depend on.

### H1. Weekly hours at each facility
As a **Consultant**, I want to set weekly opening hours per facility, with breaks, alternate
(A/B) weeks and start/end dates, so that patients and staff can only book times when I am
actually in that place.
`[frontend/models/WeeklyScheduleForm.php:243]` `[frontend/controllers/AvailabilityController.php:119]` `[frontend/models/Consultant.php:459]`

<details><summary>Acceptance criteria</summary>

- **Given** two ranges that can fall on the same day, even at different facilities, **when** I
  save, **then** the overlap is refused, because I can only be in one place at a time.
- **Given** I change my hours and booked appointments now fall outside them, **when** I save,
  **then** the page lists those appointments for me to move or cancel
  `[frontend/models/WeeklyScheduleForm.php:277]`.
- **Given** a range set to week A only, **when** someone books in a week B, **then** that time is
  not offered `[frontend/models/Consultant.php:378]`.
</details>

### H2. Different terms at each facility
As a **Consultant**, I want my own session length, fee and online-booking setting at each facility
I work at, so that each facility can run on its own commercial terms.
`[frontend/models/ConsultantFacility.php:48]` `[frontend/controllers/AvailabilityController.php:164]` `[frontend/controllers/AvailabilityController.php:279]`

<details><summary>Acceptance criteria</summary>

- **Given** a session length that is not on the allowed list (`booking.sessionLengths`),
  **when** I save, **then** it is refused.
- **Given** I change my fee or session length, **when** I save, **then** appointments already booked
  keep the length and fee they were booked with.
- **Given** a facility is my only one, still has weekly hours at it, or has upcoming appointments,
  **when** I try to remove it, **then** I am told what to do first
  `[frontend/controllers/AvailabilityController.php:301]`.
</details>

### H3. Time off
As a **Consultant**, I want to block whole days or part of a day as time off, so that nobody can
book me while I am away.
`[frontend/models/TimeOffForm.php:75]` `[frontend/models/ConsultantTimeOff.php:96]`

<details><summary>Acceptance criteria</summary>

- **Given** time off is set, **when** anyone tries to book inside it, **then** the booking is
  refused with "away {period}", and the reason stays private
  `[frontend/services/BookingService.php:270]`.
- **Given** appointments are already booked in that period, **when** I add the time off, **then** it
  is saved anyway and those appointments are listed for me to move or cancel
  `[frontend/controllers/AvailabilityController.php:145]`.
</details>

### H4. Busy elsewhere and travel time
As a **Consultant**, I want to mark weekly hours at places that don't use the app, and set a travel
buffer between facilities, so that I am never double-booked or expected somewhere I can't reach
in time.
`[frontend/controllers/AvailabilityController.php:182]` `[frontend/controllers/AvailabilityController.php:251]` `[frontend/models/Consultant.php:539]`

<details><summary>Acceptance criteria</summary>

- **Given** an external block (e.g. KNH, Wednesday mornings), **when** someone tries to book in it,
  **then** they are told "busy at {place}" `[frontend/services/BookingService.php:270]`.
- **Given** a 30-minute travel buffer, **when** a session would end less than 30 minutes before my
  hours at another facility, **then** the booking is refused
  `[frontend/services/BookingService.php:167]`.
- **Given** an external block overlaps my hours at a facility in the app, **when** I save it,
  **then** it is refused.
</details>

### H5. One-off sessions
As a **Consultant**, I want to add a one-off session at a facility (e.g. a Saturday outreach
clinic), so that I can take patients outside my weekly pattern.
`[frontend/controllers/SessionController.php:46]` `[frontend/models/ConsultantSession.php:139]`

<details><summary>Acceptance criteria</summary>

- **Given** the session overlaps my other hours that day, a public holiday, or a day the facility is
  closed, **when** I save, **then** it is refused `[frontend/models/ConsultantSession.php:119]`.
- **Given** appointments are booked in a session, **when** I try to remove it, **then** removal is
  blocked `[frontend/models/ConsultantSession.php:177]`.
</details>

### H6. Bookings checked against my rules
As a **Consultant**, I want every booking and move, from any role, checked against my rules while
my calendar is locked, so that I am never double-booked and my rules apply to everyone.
`[frontend/services/BookingService.php:71]` `[frontend/services/BookingService.php:103]`

<details><summary>Acceptance criteria</summary>

- **Given** two requests for the same slot at the same moment, **when** both save, **then** the
  first wins and the second gets "The doctor is not available at this time"
  `[frontend/services/BookingService.php:356]`.
- **Given** a patient books, **when** the start is under 60 minutes away
  (`booking.minNoticeMinutes`) or more than 90 days ahead, **then** it is refused. Staff booking
  for the clinic skip the notice rule `[frontend/services/BookingService.php:244]`.
- **Given** a start time that is not on a 5-minute mark (e.g. 10:07), **when** someone books it,
  **then** it is refused `[frontend/services/BookingService.php:227]`.
</details>

### H7. Booking for patients and registering walk-ins
As a **Consultant**, I want to book patients into my own calendar, finding them by exact email or
phone or registering a new walk-in, so that people who call or walk in get a time straight away.
`[frontend/controllers/ApiController.php:151]` `[frontend/controllers/ApiController.php:235]` `[frontend/models/RegisterPatientForm.php:83]`

<details><summary>Acceptance criteria</summary>

- **Given** I search for a patient, **when** I enter a name, **then** nothing is found: search
  works by exact email or phone only, and contact details come back masked.
- **Given** an active patient already uses the phone number, **when** I register a walk-in,
  **then** I am shown that patient so I don't create a duplicate.
- **Given** the patient has no consent on record, **when** I book, **then** I must confirm their
  verbal consent first, and it is recorded as `front_desk` `[frontend/controllers/ApiController.php:182]`.
</details>

### H8. Confirming appointments and recording who came
As a **Consultant**, I want to confirm appointments and record whether each patient attended,
so that my day is planned and no-shows are tracked.
`[frontend/controllers/AppointmentsController.php:232]` `[frontend/controllers/AppointmentsController.php:180]` `[frontend/models/Appointments.php:257]`

<details><summary>Acceptance criteria</summary>

- **Given** an appointment is `scheduled`, **when** I confirm it, **then** it becomes `confirmed`.
  Only the appointment's own consultant can confirm (`confirmOwnAppointment`).
- **Given** an appointment has not started yet, **when** anyone tries to record an outcome, **then**
  it is refused.
- **Given** an outcome was recorded, **when** someone changes it within 7 days, **then** the change
  is allowed. After 7 days it is fixed.
</details>

### H9. Booking links
As a **Consultant**, I want to send a potential patient a one-time link that offers my free
slots, so that they can book themselves without an account.
`[frontend/controllers/BookingLinkController.php:58]` `[frontend/models/BookingLinkForm.php:117]` `[frontend/models/BookingLink.php:59]`

<details><summary>Acceptance criteria</summary>

- **Given** a date range longer than 5 days (`bookingLink.maxDays`), **when** I send the link,
  **then** it is refused.
- **Given** a link was sent, **when** 20 minutes pass, it is used once, or I revoke it, **then** it
  stops working. Only a hash of its token is stored.
- **Given** the link was sent to a phone or email, **when** the person books, **then** that contact
  cannot be changed, so a forwarded link still books for the person addressed
  `[frontend/models/RemoteWalkInForm.php:88]`.
</details>

### H10. Hearing about patient changes
As a **Consultant**, I want to be told when a patient cancels or moves their own appointment, so
that I know when a slot has opened.
`[frontend/services/AppointmentChangeNotifier.php:28]`

<details><summary>Acceptance criteria</summary>

- **Given** a patient cancels or moves through their link, **when** the change saves, **then** I get
  an email and a WhatsApp message. If the clinic made the change, the patient is told instead.
- **Given** the clinic cancelled with an internal note, **when** the notice is sent, **then** the
  note is left out.
</details>

### H11. My calendar in other apps
As a **Consultant**, I want a private iCal feed of my appointments, so that they show in my phone
or Google calendar.
`[frontend/controllers/AvailabilityController.php:213]` `[frontend/controllers/CalendarFeedController.php:19]`

<details><summary>Acceptance criteria</summary>

- **Given** I create a feed, **when** I am shown its address, **then** only a hash of the secret is
  stored. Creating a new feed address makes the old one stop working.
</details>

### H12. Choosing where I work
As a **Consultant**, I want to accept or decline invitations to facilities, and to request a
facility that isn't listed yet, so that I decide where my calendar can be booked.
`[frontend/controllers/AvailabilityController.php:232]` `[frontend/controllers/AvailabilityController.php:330]` `[frontend/models/FacilityInvitation.php:106]`

<details><summary>Acceptance criteria</summary>

- **Given** a pending invitation, **when** I add that facility on my Availability page, **then** the
  invitation is accepted and front desk there can book for me.
- **Given** I decline it, **when** the decline saves, **then** I am removed from that facility's
  front desk.
- **Given** I request a new facility, **when** an admin approves or declines it, **then** I am told
  by email and WhatsApp.
</details>

### H13. My waiting list
As a **Consultant**, I want to see who is waiting for a time with me, and add patients myself, so
that cancelled slots get filled.
`[frontend/controllers/WaitlistController.php:165]` `[frontend/controllers/WaitlistController.php:181]`

<details><summary>Acceptance criteria</summary>

- **Given** I am a consultant, **when** I open the waiting list, **then** I see only my own entries
  `[frontend/models/WaitlistEntry.php:86]`.
- **Given** I add a patient, **when** the entry saves, **then** the patient is sent their link.
</details>

---

## Spoke 1: Potential Patients (Clients)

Patients see the consultant's free time and book themselves into it, within the limits the
consultant has set.

### P1. Finding a consultant
As a **Potential Patient**, I want to search for consultants by specialty, sub-specialty or
location, so that I can find the right doctor near me.
`[frontend/controllers/SiteController.php:113]` `[frontend/models/SearchForm.php]`

<details><summary>Acceptance criteria</summary>

- **Given** an empty search, **when** I submit it, **then** I am asked to choose at least one of
  specialty, sub-specialty or location.
- **Given** a consultant has been deactivated, **when** I search, **then** they don't appear
  `[frontend/controllers/SiteController.php:409]`.
</details>

### P2. Booking myself on the calendar
As a **Potential Patient**, I want to pick a free time on a consultant's calendar and book it
myself, so that I don't have to phone the clinic.
`[frontend/controllers/AppointmentsController.php:201]` `[frontend/controllers/ApiController.php:151]` `[frontend/services/BookingService.php:210]`

<details><summary>Acceptance criteria</summary>

- **Given** the consultant has online booking off at a facility, **when** I try to book there,
  **then** I am told to call that facility's phone number.
- **Given** I book, **when** the request reaches the server, **then** the appointment is always
  booked for me, whatever patient the request names.
- **Given** another patient's booking, **when** I view the calendar, **then** it shows only as
  "Booked", with no details `[frontend/controllers/ApiController.php:57]`.
</details>

### P3. Signing up and signing in
As a **Potential Patient**, I want to sign up or sign in with a password, Google (including One
Tap), LinkedIn, or my phone number and a one-time code, so that getting in is quick even if I
rarely use email.
`[frontend/services/SocialSignIn.php:21]` `[frontend/services/PhoneSignIn.php:53]` `[frontend/services/PhoneSignIn.php:167]`

<details><summary>Acceptance criteria</summary>

- **Given** a Google or LinkedIn account whose email the provider has not verified, **when** I sign
  in with it, **then** it is not linked to an existing account with that email.
- **Given** a one-time code, **when** 10 minutes pass, it is used once, or it is entered wrong 5
  times, **then** it stops working. Requests are limited to 3 per number per 15 minutes and 10 per
  day `[frontend/services/PhoneSignIn.php:110]`.
- **Given** several patients share one phone number, **when** I prove the number, **then** I choose
  which account to sign in to. Only patient accounts can be reached this way.
</details>

### P4. Consent and messages
As a **Potential Patient**, I want to agree to the privacy notice and choose whether I get
WhatsApp/SMS messages, so that my health information is used only as I agreed (Kenya Data
Protection Act).
`[frontend/components/ConsentGate.php:40]` `[frontend/controllers/ConsentController.php:90]` `[common/models/Consent.php:152]`

<details><summary>Acceptance criteria</summary>

- **Given** I haven't accepted the current notice version, **when** I open any signed-in page,
  **then** I am taken to the consent page first.
- **Given** I turned WhatsApp/SMS off, **when** reminders, notices or waiting-list messages go out,
  **then** I get email only.
- **Given** I withdraw consent, **when** it is saved, **then** an account-closure request is opened
  for the clinic `[frontend/controllers/ConsentController.php:105]`.
</details>

### P5. Changing a booking without signing in
As a **Potential Patient**, I want to confirm, move or cancel my appointment from the link in my
reminder, so that I can change plans without signing in.
`[frontend/controllers/ManageController.php:36]` `[frontend/services/SelfServiceLink.php:105]` `[frontend/services/SelfServiceLink.php:118]`

<details><summary>Acceptance criteria</summary>

- **Given** my appointment is less than 24 hours away (`SELF_SERVICE_CUTOFF_HOURS`), **when** I try
  to move or cancel it, **then** I am asked to call the clinic. I can still confirm I'm coming.
- **Given** my appointment was moved, **when** I open an older link, **then** it no longer works:
  the signature covers the date and time.
- **Given** the link is only opened, for example by a WhatsApp link preview, **then** nothing
  changes: changes require a POST.
</details>

### P6. Booking from a link the clinic sent
As a **Potential Patient** without an account, I want to book from a link the clinic sent me, so
that I can get an appointment without registering first.
`[frontend/controllers/BookController.php:21]` `[frontend/models/RemoteWalkInForm.php:105]`

<details><summary>Acceptance criteria</summary>

- **Given** I open the link, **when** I pick a time, agree to the notice and answer the reminders
  question, **then** I am booked under the patient who owns that contact, or a new walk-in account
  is created.
- **Given** two browser tabs submit at once, **when** both try to book, **then** only one booking is
  made: the link is locked while it books.
</details>

### P7. Joining a waiting list
As a **Potential Patient**, I want to join a consultant's waiting list for a range of days (any
time, mornings or afternoons), so that I hear when a time frees up.
`[frontend/controllers/WaitlistController.php:126]` `[frontend/models/WaitlistForm.php:165]` `[frontend/services/Waitlist.php:111]`

<details><summary>Acceptance criteria</summary>

- **Given** a range longer than 30 days or starting in the past, **when** I join, **then** it is
  refused.
- **Given** I am already on 5 waiting lists, **when** I join another, **then** I am asked to leave
  one first. Joining the same consultant and facility again updates my existing entry.
- **Given** I join, **when** times are already free, **then** they are shown to me straight away,
  and I am messaged only about times that open later.
</details>

### P8. Being told when a time frees up
As a **Potential Patient** on a waiting list, I want a message with a booking link as soon as a
time I'm waiting for frees up, so that I can take it before someone else does.
`[frontend/services/Waitlist.php:257]` `[frontend/services/Waitlist.php:328]`

<details><summary>Acceptance criteria</summary>

- **Given** an appointment in my window is cancelled or moved, **when** the queue worker checks the
  list, **then** everyone waiting is messaged at once, and the first to book gets the time.
- **Given** someone booked the time before me, **when** I submit, **then** I see "just been taken"
  and the other free times.
- **Given** it is between 21:00 and 07:00, or I've already had 3 messages today, **when** a time
  frees up, **then** I am not messaged until the morning or the next day.
</details>

### P9. Asking for an earlier time
As a **Potential Patient** with a booking, I want to ask to be told if an earlier time opens, so
that I can be seen sooner without losing my current place.
`[frontend/controllers/ManageController.php:137]` `[frontend/services/Waitlist.php:328]`

<details><summary>Acceptance criteria</summary>

- **Given** I choose an earlier time from my link, **when** it books, **then** my existing
  appointment moves; no second appointment is created. My old time is offered to the next person
  waiting.
- **Given** I cancel the appointment I was waiting to move, **when** the cancellation saves,
  **then** my waiting-list entry ends.
</details>

### P10. Being told when the clinic changes my booking
As a **Potential Patient**, I want to be told when the clinic cancels or moves my appointment, so
that I don't turn up at the wrong time.
`[frontend/services/AppointmentChangeNotifier.php:28]`

<details><summary>Acceptance criteria</summary>

- **Given** staff move my appointment, **when** the move saves, **then** I get the new time and
  place. My message includes a link to confirm or change it again if that's still possible.
</details>

---

## Spoke 2: Front Desk Users

Front desk handle the day: they book, move, cancel and record outcomes in the calendars of the
consultants they are assigned to, at their own facility only.

### F1. A board limited to my consultants and facility
As a **Front Desk User**, I want a board of my assigned consultants' appointments at my
facility, so that I see exactly the work I'm responsible for.
`[frontend/controllers/BoardController.php:39]` `[frontend/components/FrontDesk.php:95]` `[common/rbac/AssignedFacilityRule.php]`

<details><summary>Acceptance criteria</summary>

- **Given** I am assigned to Dr A at Facility X, **when** I open the board, **then** I see only
  Dr A's appointments at Facility X.
- **Given** Dr A also works at Facility Y, **when** I open Dr A's calendar, **then** Facility Y
  appointments show only as busy, with no patient details
  `[frontend/controllers/ApiController.php:57]`.
- **Given** a front desk with no facility set, **when** they open the board, **then** they see a
  warning to ask an admin to set it.
</details>

### F2. Booking and registering walk-ins
As a **Front Desk User**, I want to book assigned consultants' patients into any future week and
register walk-ins, so that phone and walk-in demand fills the calendar.
`[frontend/services/BookingService.php:145]` `[frontend/models/CalendarViewModel.php:84]` `[frontend/controllers/ApiController.php:276]`

<details><summary>Acceptance criteria</summary>

- **Given** the consultant's hours at another facility, **when** I try to book there, **then** it is
  refused with "You book only into the hours at {facility}".
- **Given** I book for the clinic, **when** the slot is less than 60 minutes away, **then** it is
  allowed, because walk-ins need no notice. Up to 90 days ahead is allowed.
- **Given** I tick *Also put the patient on the waiting list for an earlier time*, **when** the
  booking saves, **then** the patient waits for anything sooner and is sent their link
  `[frontend/controllers/ApiController.php:194]`.
</details>

### F3. Moving and cancelling
As a **Front Desk User**, I want to drag appointments to another time, and cancel them with an
internal note, so that I can handle changes during the day.
`[frontend/controllers/ApiController.php:211]` `[frontend/controllers/AppointmentsController.php:159]` `[frontend/models/Appointments.php:236]`

<details><summary>Acceptance criteria</summary>

- **Given** an appointment at another facility, **when** I try to move it, **then** I get "not
  found".
- **Given** a past or already-finished appointment, **when** I try to cancel it, **then** it is
  refused. Only upcoming `scheduled` or `confirmed` appointments can be cancelled.
- **Given** I cancel with a note, **when** the patient is told, **then** the note is not included,
  and the cancelled appointment stays in the history.
</details>

### F4. Recording who came
As a **Front Desk User**, I want to mark patients as attended or no-show, and see each patient's
no-show count, so that our records are accurate and repeat no-shows are visible.
`[frontend/controllers/AppointmentsController.php:180]` `[frontend/models/Appointments.php:330]`

<details><summary>Acceptance criteria</summary>

- **Given** an appointment has started, **when** I record an outcome, **then** it is saved with my
  name and the time, and can be corrected within 7 days.
- **Given** a patient missed appointments in the last 12 months, **when** I open one of their
  appointments, **then** I see their no-show count, counting only appointments I am allowed to see.
</details>

### F5. Sending booking links
As a **Front Desk User**, I want to send booking links for my assigned consultants at my facility,
so that callers can choose their own time.
`[frontend/models/BookingLinkForm.php:49]`

<details><summary>Acceptance criteria</summary>

- **Given** I work at Facility X, **when** I send a link, **then** it can only offer Facility X.
</details>

### F6. One-off sessions for my consultants
As a **Front Desk User**, I want to add one-off sessions for my assigned consultants at my
facility, so that extra clinics can be opened when there's demand.
`[frontend/controllers/SessionController.php:46]` `[common/components/RbacHelper.php:88]`

<details><summary>Acceptance criteria</summary>

- **Given** a session at another facility, **when** I try to add it, **then** it is refused, because
  my permission is limited to my own facility (`manageAssignedSessionsAtFacility`).
</details>

### F7. Running the waiting list
As a **Front Desk User**, I want to add patients to a consultant's waiting list, see everyone
waiting, and take people off it, so that freed slots go to the people who want them.
`[frontend/controllers/WaitlistController.php:165]` `[frontend/controllers/WaitlistController.php:181]` `[frontend/controllers/WaitlistController.php:206]`

<details><summary>Acceptance criteria</summary>

- **Given** I enter a phone number that several patients share, **when** I add the patient, **then**
  I am asked to use their email instead.
- **Given** the board, **when** I open it, **then** *Waiting list (n)* counts only entries for my
  consultants at my facility.
</details>

### F8. Headline numbers
As a **Front Desk User**, I want today's bookings, the next 7 days, cancellations and outcomes for
my consultants, so that I can plan staffing and follow up.
`[frontend/models/ClinicStats.php:36]` `[frontend/models/ClinicStats.php:53]` `[frontend/models/ClinicStats.php:73]`

<details><summary>Acceptance criteria</summary>

- **Given** I am front desk at Facility X, **when** the numbers are worked out, **then** they cover
  only my assigned consultants at Facility X.
</details>

---

## Spoke 3: Facility Admins

Facility admins run one facility: who works its front desk, which days it is closed, its contact
details and its audit trail, across every consultant who works there.

### A1. The whole facility on one board
As a **Facility Admin**, I want the appointments board and numbers for my facility across all
its consultants, so that I can see how the facility is being used.
`[frontend/controllers/BoardController.php:39]` `[frontend/components/FrontDesk.php:53]` `[frontend/components/FrontDesk.php:72]`

<details><summary>Acceptance criteria</summary>

- **Given** I run Facility X, **when** I open the board, **then** I see every consultant's
  appointments at Facility X and none from elsewhere.
- **Given** I have no facility set, **when** I open the board, **then** I see nothing: an unset
  facility is treated as id 0.
- **Given** an appointment at my facility, **when** I cancel it or record its outcome, **then** it is
  allowed (`handleFacilityAppointment`, `recordFacilityOutcome`).
</details>

### A2. Front-desk staff
As a **Facility Admin**, I want to create and edit front-desk accounts for my facility and choose
which consultants each one books for, so that bookings are handled by the right people.
`[frontend/controllers/FrontDeskController.php:76]` `[frontend/models/FrontDeskStaffForm.php:164]`

<details><summary>Acceptance criteria</summary>

- **Given** I create a front-desk account, **when** I save, **then** its facility is fixed to mine.
- **Given** I tick a consultant who doesn't work at my facility yet, **when** I save, **then** the
  consultant is invited `[frontend/models/FacilityInvitation.php:154]`. Front desk can book for them
  once they accept and add hours here.
</details>

### A3. Closing the facility for a day
As a **Facility Admin**, I want to close my facility for a day with a reason, so that nobody books
it when it's shut (e.g. maintenance or a staff day).
`[frontend/controllers/FacilityController.php:231]` `[frontend/models/FacilityClosure.php:46]` `[frontend/models/Consultant.php:564]`

<details><summary>Acceptance criteria</summary>

- **Given** a date in the past, or a day already closed, **when** I save, **then** it is refused.
- **Given** a closed day, **when** anyone tries to book there, **then** they are told "{facility} is
  closed on {date} ({reason})". Consultants' bookings at other facilities that day are not affected.
- **Given** appointments were already booked that day, **when** I close it, **then** they are kept
  for staff to move. *(No automatic notice: see Gaps.)*
</details>

### A4. Contact details
As a **Facility Admin**, I want to update my facility's address, phone and map link, so that
patients' confirmations and reminders send them to the right place.
`[frontend/controllers/FacilityController.php:165]`

<details><summary>Acceptance criteria</summary>

- **Given** I am a facility admin, **when** I edit a facility, **then** I can change only the
  contact fields, and only for my own facility. Other fields need the consultancy admin's
  `manageReferenceData` permission.
</details>

### A5. One-off sessions at my facility
As a **Facility Admin**, I want to add one-off sessions for any consultant at my facility, so that
I can schedule outreach or overflow clinics.
`[frontend/controllers/SessionController.php:46]` `[frontend/models/ConsultantSession.php:139]`

<details><summary>Acceptance criteria</summary>

- **Given** a consultant who works at my facility, **when** I add a session for them here, **then**
  the same overlap, holiday and closure checks apply, and the consultant is told
  `[frontend/models/ConsultantSession.php:200]`.
</details>

### A6. The facility's audit trail
As a **Facility Admin**, I want to read the audit log for my facility, so that I can see who
changed which appointment and when.
`[frontend/controllers/AuditController.php:55]`

<details><summary>Acceptance criteria</summary>

- **Given** I am a facility admin, **when** I open the audit log, **then** it is limited to entries
  about my facility. Symptom details and secrets are never stored in it.
</details>

### A7. Accounts created by the consultancy
As a **Facility Admin**, I want my account created and tied to my facility by the consultancy
admin, so that I sign in with only my facility's powers.
`[frontend/controllers/FacilityAdminController.php:37]` `[frontend/models/FacilityAdminForm.php:88]`

<details><summary>Acceptance criteria</summary>

- **Given** the consultancy admin creates my account, **when** I sign in with my email, **then** I
  get the `facility-admin` role, limited to that one facility.
</details>

---

## Story count

| Group | Stories |
|---|---|
| The Hub: Medical Consultant | 13 |
| Spoke 1: Potential Patients | 10 |
| Spoke 2: Front Desk Users | 8 |
| Spoke 3: Facility Admins | 7 |
| **Total** | **38** |

---

## System Gaps

These workflows matter for the roles above but are missing or only half-built in the code. They
are listed here so they are not mistaken for working features.

| # | Gap | Affects | What the code has today |
|---|---|---|---|
| G1 | **No day-of check-in or queue.** There is no "arrived", "in consultation" or waiting-room state. | Front desk | Statuses are only `scheduled`, `confirmed`, `completed`, `no_show` and `cancelled`, and an outcome can only be recorded once the start time has passed `[frontend/models/Appointments.php:48]`. |
| G2 | **Front desk cannot confirm appointments**, for example after a patient confirms by phone. | Front desk | `confirmAppointment` is granted only to the appointment's own consultant (`confirmOwnAppointment`) `[common/components/RbacHelper.php:149]`. Patients can confirm through their link. |
| G3 | **No triage or priority.** | Front desk | Bookings are first come, first served. There is no urgency field or override. |
| G4 | **No structured intake form.** | Consultant, patients | Only a free-text `symptoms_brief` is collected. |
| G5 | **Insurance is not checked at booking.** | Patients, front desk | Consultants list `covers_supported` and patient profiles have `insurance`, but booking neither captures nor checks it `[frontend/views/consultant/_form.php:107]`. |
| G6 | **Recurring appointments don't work.** | Consultant, front desk | A `recurring_appointment` checkbox is on the legacy form `[frontend/views/appointments/_form.php:42]`, but nothing creates the series. |
| G7 | **Closing a facility or adding time off doesn't tell affected patients.** | Facility admin, consultant | Clashing appointments are listed for staff to move one by one `[frontend/models/ConsultantTimeOff.php:96]`. `FacilityClosure::afterSave` only clears a cache `[frontend/models/FacilityClosure.php:90]`. There is no bulk reschedule or notice. |
| G8 | **No physical resources.** There are no rooms, equipment or chairs. | Facility admin | A facility is a location with contact details. Capacity is the consultants' hours only. |
| G9 | **No capacity-use figures.** | Facility admin | `ClinicStats` gives counts, cancellation and no-show rates, the busiest consultants and per-facility sessions and fees, but not booked minutes as a share of available minutes `[frontend/models/ClinicStats.php:136]`. |
| G10 | **Facility admins can't onboard a consultant directly or edit their hours** at the facility. | Facility admin | Invitations go out only when a consultant is ticked for a front-desk account `[frontend/models/FrontDeskStaffForm.php]`. Hours are edited only by the consultant or a consultancy admin (`manageAvailability`). |
| G11 | **Waiting-list notices for new hours wait for the sweep.** | Patients | Cancellations and moves notify within seconds. New hours, a removed time off or a new session are picked up by the 15-minute `waitlist/sweep` timer `[frontend/services/Waitlist.php:302]`. |
| G12 | **No payments or deposits.** | Patients, facility admin | M-Pesa (STK push) is deferred until credentials and a refund policy exist (`docs/FEATURES.md`, Deferred). |
| G13 | **English only.** | Patients | Kiswahili translation (F10) is deferred. |
