# Business case: commitment fees, incentives and consultant plans

This file evaluates the HealthFirst business requirements and the **Proof of Concept (PoC) and
Commercial Terms Sheet** against the code as it is in v1.1.0. It covers M-Pesa (Daraja) payments,
front-desk incentives, consultant plans, the pilot and the backend for platform admins. Nothing here
is built yet. Once the open questions at the end are answered, the work will be filed as items in
[FEATURES.md](FEATURES.md); it replaces the deferred item **F5 M-Pesa deposits**.

The requirements and the agreement were harmonised on 15 November 2026 (see [Decisions](#decisions)).
The agreement was revised to match: **v1.2, draft for review**, kept in
[poc-commercial-agreement.html](poc-commercial-agreement.html), with a PDF copy in
[poc-commercial-agreement-v1.2.pdf](poc-commercial-agreement-v1.2.pdf). The first version, v1.1.0, is what
facilities were first shown.

## The requirements as given

**Pricing for consultants**

- With commitment fee: Ksh 1,000.
- Without commitment fee: Ksh 1,500.

**Commitment fee**

- A flat Ksh 250.
- The front-desk user responsible for the booking gets Ksh 50 for each commitment fee.
- Exemptions: the fee is charged once a day and only for advance or remote bookings. Same-day
  walk-ins are exempt.

**Conditions for the front-desk incentive**

1. An advance appointment with an attended (completed) outcome.
2. Not a same-day walk-in.
3. If a front-desk user cancels an appointment and rebooks the same patient within 24 hours, the
   incentive is flagged and cancelled.

**Refunds and cancellations**

- The Ksh 250 is not refunded if the patient does not come or cancels less than 24 hours before.
- It moves with the booking if the patient reschedules more than 24 hours ahead.
- It is refunded automatically, or credited as a free rebooking voucher, if the consultant or the
  facility cancels.
- The front desk's Ksh 50 stays pending until the appointment time has passed, so no incentive is
  paid on a slot the clinic cancelled.
- When a consultant or front-desk user cancels, Ksh 100 is added to the monthly invoice, and the
  consultant is told by email.

**Backend (Yii2 backend app)**

- Sign-in for platform admins.
- An RBAC menu, used to give people the consultant admin and facility admin roles.
- Tracking of payments to front-desk users.
- Tracking of each consultant's trial period.

## The pilot agreement (v1.1.0)

Summary of the PoC and Commercial Terms Sheet as first drafted, signed between the platform and each
participating facility (for the facility, by its admin or lead consultant).

### Pilot schedule (90 days)

| Phase | What happens | Fees |
|---|---|---|
| Month 1 (days 1–30) | Onboard consultants; set facility hours, travel buffers and external blocks; train front desk. | No consultant charge. No patient fee: this month is the **no-show baseline**. |
| Month 2 (days 31–60) | Patient commitment fees (Plan A) switch on, with weekly M-Pesa payouts to the front desk. | No consultant charge. Ksh 250 patient fee; Ksh 50 per booking to the front desk, paid weekly. |
| Month 3 (days 61–90) | Full operation for cash and insurance patients; a **90-day ROI and no-show reduction report**. | No consultant charge. The move to paid plans in month 4 is confirmed from the pilot figures. |

### Standard terms from month 4

| Plan | Consultant charge | Patient fee | Front-desk incentive |
|---|---|---|---|
| **Plan A: commitment fee** (recommended) | Ksh 1,000 a month per active consultant | Ksh 250 per advance booking, paid by the patient through M-Pesa | Ksh 50 per completed booking, added up and paid weekly |
| **Plan B: flat** | Ksh 1,500 a month per active consultant | None | None |

### Safeguards in the agreement

- **The clinic keeps all its revenue.** The platform collects the Ksh 250 directly as a slot
  reservation fee. It is never taken from the consultant's fee or the hospital's payout.
- **Cash patients** pay the Ksh 250 by M-Pesa STK push when they book ahead, and pay the full
  consultation fee at the clinic. Same-day walk-ins at reception *may* be exempt.
- **Insurance patients** (SHA, Jubilee, Britam, AAR, CIC and others) say they are insured and give
  their insurer details at booking, so the front desk can get pre-authorisation (Slade360 / Smart)
  before they arrive. The Ksh 250 never goes on a claim form. Each consultant chooses whether
  insured patients pay it themselves.
- **Front-desk incentive:** earned only when the appointment is completed, and paid weekly by
  M-Pesa. Cancelled appointments and no-shows earn nothing.
- **Refunds:** if a consultant or facility cancels a session, affected patients get an automatic
  free rebooking credit or a full Ksh 250 M-Pesa refund. A patient who cancels more than 24 hours
  ahead can move the fee to a new slot.
- **Data (Kenya Data Protection Act):** consent logs, masked contact records and facility audit
  trails comply with the Act. The facility and consultants own their schedules and patient records.

## Decisions

Where the requirements and v1.1.0 of the agreement differed, these were decided on 15 November 2026.
The agreement v1.2 says the same.

| Topic | Decision | Where it is written |
|---|---|---|
| Ksh 100 cancellation penalty | **Added to the agreement.** Charged on the consultant's monthly invoice when the consultant or their front desk cancels a booking, with an email to the consultant. Not charged when the patient asked for the cancellation or the facility closed for the day. Starts on day 91 of the consultant's pilot, on both plans. Built behind `CANCELLATION_PENALTY`, and charged only to consultants at facilities that signed v1.2. | Agreement §3, §5 |
| Fee kept on a no-show or a cancellation less than 24 hours ahead | **Patient booking terms**, which the patient accepts when booking, because patients do not sign the agreement. The agreement repeats them in a schedule so facilities know them. | Agreement Schedule A; the booking page |
| Once a day | **Patient booking terms**: a patient pays at most one fee a day. | Agreement Schedule A; the booking page |
| Cancel and rebook within 24 hours | **An internal anti-gaming check.** The agreement says incentives are subject to review; the rule itself is not in it. | Agreement §5 |
| Same-day walk-ins | **A setting per facility, exempt by default.** | Agreement §4 |
| Insured patients | **A setting per consultant.** A consultant who chooses that insured patients do not pay **absorbs the Ksh 250** from their own consultation fee: the platform bills it on the consultant's monthly invoice. The patient pays nothing upfront, and the fee never goes on a claim form. | Agreement §4 |
| Incentive basis | Ksh 50 per completed Plan A booking made by the front desk, including bookings whose fee the consultant absorbs. | Agreement §3, §5 |
| Trial period | **90 days per consultant, from the first booking made with them on the platform**, not from the date the agreement is signed. Onboarding comes before day 1. A consultant who joins later gets their own 90 days. | Agreement §2 |

## What the code has today

| Need | Today |
|---|---|
| Tell a same-day walk-in from an advance booking | **Not possible yet.** `appointments.walk_in_appointment` exists, but nothing sets it. Appointments need a recorded booking channel: online, staff advance booking, booking link or walk-in. |
| Know who cancelled, and why | Only in the audit log. `Appointments::cancel()` stores a note, not the person, the party (patient, consultant, front desk, facility) or whether the patient asked. The refund and penalty rules depend on it, so it needs its own columns. |
| A consultant's first booking | Can be found from `appointments.created_at`, but it should be stored once on the consultant, so the pilot clock never moves if that appointment is deleted. |
| The 24-hour cutoff | `selfService.cutoffHours` (24) is already in `.env` and matches the refund policy. |
| No-show figures for the baseline | `ClinicStats` already gives no-show and cancellation rates per facility. The pilot report compares month 1 with months 2 and 3. |
| Cash or insurance at booking | Not captured. Consultants list the covers they accept and patient profiles have an `insurance` field, but booking ignores both (gap G5, backlog item F18). The agreement needs this by month 3. |
| Consent logs and audit trails | Built (F11, T7). "Masked contact records" needs checking: staff at other facilities already see only "busy", but phones and emails are not masked anywhere else. |
| Queue, email and WhatsApp | Already in place (`yii2-queue`, `NotificationService`). |
| RBAC menu | Already built, in the **frontend** (`RbacController`, admin layout). |
| Backend app | Only the Yii template: a login page and an empty home page. |
| Plans, pilots, invoices and payments | Nothing yet. |

## M-Pesa, set from `.env`

A `common/components/Mpesa` component holds every Daraja call: the OAuth token (cached for just under
an hour), STK push, the STK status query and B2C for refunds and the weekly front-desk payouts.
Everything that belongs to an account comes from `.env`, so moving from the sandbox to the live
paybill only changes `.env`:

```
MPESA_ENV=sandbox                # sandbox | production; picks the base URL
MPESA_CONSUMER_KEY=
MPESA_CONSUMER_SECRET=
MPESA_SHORTCODE=174379           # the sandbox test shortcode
MPESA_PASSKEY=
MPESA_CALLBACK_URL=              # a public HTTPS address; a tunnel (ngrok, cloudflared) when testing locally
MPESA_CALLBACK_TOKEN=            # a secret in the callback path, because Daraja callbacks are not signed

# B2C, for weekly front-desk payouts and refunds
MPESA_B2C_SHORTCODE=
MPESA_INITIATOR_NAME=
MPESA_SECURITY_CREDENTIAL=

# Business amounts
COMMITMENT_FEE=250
FRONTDESK_INCENTIVE=50
CANCELLATION_PENALTY=100         # charged only where the facility signed agreement v1.2
PLAN_A_MONTHLY=1000
PLAN_B_MONTHLY=1500
PILOT_DAYS=90
```

Every row stores the amount in force when it was created, so changing a price in `.env` never
changes past records.

Amounts are the same everywhere, so they live in `.env`. What differs between facilities and
consultants (the agreement version signed, pilot dates, plans, the walk-in and insurance choices)
lives in the database and is set in the backend.

Safeguards:

- A callback is accepted only if its token matches. Its result is then checked with an STK status
  query before anything is marked paid.
- Callbacks are handled once per `CheckoutRequestID`, so a repeated callback does nothing.
- A console command, `php yii mpesa/test-stk 2547…`, sends a test STK push so the sandbox can be
  checked on its own.
- **B2C goes live later than STK push.** Safaricom issues production B2C credentials separately and
  it takes time, while the agreement promises weekly payouts from month 2. Apply early. Until B2C
  is live, the weekly payout is a list the platform pays by hand, with each M-Pesa reference
  recorded.

## Design

### The agreement and the pilot

- **The facility's agreement** is recorded: the version signed (v1.1.0 or v1.2), who signed for the
  facility and for the platform, and the date. Signing gives the facility access. It does not start
  any clock.
- **Each consultant's pilot** starts on the day the first appointment is booked with them on the
  platform, by any channel. That date is stored on the consultant the first time it happens and
  never moves, even if that appointment is later cancelled. Their phases follow from it:
  - days 1–30: no fees (their no-show baseline);
  - days 31–60: the patient fee and front-desk incentive, if they are on Plan A;
  - days 61–90: full operation;
  - from day 91: standard terms, once the facility has confirmed them and a platform admin has
    approved them. Until then, consultants are not charged.
- **Confirming standard terms** (decided 16 November 2026) is per facility, on the clock of its
  earliest pilot:
  - day 81: the facility admin and front desk are emailed that standard terms start in 10 days,
    with a button to the facility's terms page;
  - day 91: the same email again if not confirmed, and a 30-day grace period starts, with a warning
    on every page for the facility's consultants, front desk and facility admin;
  - the facility's front desk or facility admin confirms on the terms page (a platform admin can
    also confirm for it, with `php yii pilot/confirm-standard`);
  - then a platform admin approves, on the terms page or with `php yii pilot/approve-standard`.
    Confirming emails the platform admins and shows them a warning until they approve; approving
    emails the facility. This is the agreement's "once the Platform Provider and the facility have
    confirmed the transition";
  - day 121, still not confirmed: platform admins are emailed and the warning stays. Nothing is
    charged and nothing is paused until the terms are confirmed.
- The **pilot report** compares each consultant's month 1 (the baseline) with their months 2 and 3,
  and adds them up per facility:
  - no-show rate;
  - late cancellations;
  - bookings made ahead versus walk-ins;
  - fees collected, absorbed, refunded and credited;
  - incentives earned.

### Booking with a commitment fee

1. A booking that needs the fee holds the slot as **awaiting payment** for a few minutes and sends an
   STK push to the patient's phone. If payment does not arrive, a timer releases the slot.

   This is the largest change in the work. Slot search (`SlotFinder`), the overlap check in
   `BookingService`, the waiting list, the calendar and confirmation messages all have to treat this
   state correctly. Confirmations go out only after payment.
2. The patient says at booking whether they pay **cash or by insurance**. If insurance, they give
   the insurer and member number for pre-authorisation, and the front desk records the
   pre-authorisation status: pending, approved or declined.
3. Who does not pay:
   - The consultant is not yet in day 31 of their pilot.
   - The consultant is on Plan B.
   - A same-day walk-in at reception, while the facility exempts them (the default). A same-day
     booking made online or through a booking link still pays.
   - A patient who has already paid that day, with any consultant.
4. **Insured patients, when the consultant does not ask them to pay:** the booking still carries a
   Ksh 250 fee, marked **absorbed by the consultant**. No STK push is sent and no slot is held. The
   absorbed fee is added to the consultant's monthly invoice and follows the same rules as a paid
   one: it moves with a reschedule, it is cancelled (not billed) if the clinic cancels, and it is
   billed on a no-show or a late cancellation.
5. The fee's states: **paid** or **absorbed**, then one of:
   - **forfeited**: the patient did not come, or cancelled less than 24 hours before;
   - **transferred**: the patient rescheduled more than 24 hours ahead;
   - **credited** (paid) or **cancelled** (absorbed): the consultant or the facility cancelled. The
     patient gets a free rebooking credit automatically, and an M-Pesa refund instead if they ask
     (**refunded**).

   These are triggered from `cancel()`, `reschedule()`, `recordOutcome()` and facility closures.
6. The patient accepts the **booking terms** (forfeit, transfer, at most one fee a day) at booking.
   This sits beside the existing consent step.

### Front-desk incentive (Ksh 50)

- Only for Plan A bookings made ahead by a front-desk user, whose fee was paid or absorbed.
- Created as **pending** when the fee is paid or absorbed.
- Becomes **earned** only when the appointment is completed (attended).
- Is **cancelled** in two cases:
  - the appointment is a no-show, or is cancelled by anyone;
  - **gaming**: the same front-desk user cancels and rebooks the same patient within 24 hours. The
    rebooking's incentive is cancelled and the pair is flagged for review.
- **Paid weekly.** A weekly job groups each front-desk user's earned incentives into one payout. It
  is paid through B2C to their phone, or marked paid by hand with an M-Pesa reference until B2C is
  live.

### Consultant plans and invoices

- Each consultant has a plan, A or B, and chooses whether insured patients pay the fee (Plan A
  only).
- A monthly console job produces each invoice:
  - the plan's price, for each **active** consultant (at least one appointment that month) past
    their pilot, once standard terms are confirmed;
  - absorbed fees for insured patients, from day 31 of the pilot (during the pilot, the invoice is
    a statement of absorbed fees only);
  - from day 91, Ksh 100 for each cancellation by the consultant or their front desk, on either
    plan, at facilities that signed v1.2, unless the patient asked for it or the facility closed.
    The consultant is emailed each time one is added.
- Invoices are paid by an **STK push** to the consultant's phone. An admin can also mark one paid
  by hand with a reference.
- An unpaid invoice gets email and WhatsApp reminders. **14 days after the due date**, patients can
  no longer book that consultant online; bookings by staff keep working. Online booking resumes
  when the invoice is paid.

### Masked contact details

- Patients' phone numbers and emails are masked (`0712***678`, `j***@mail.com`) in lists, boards,
  search results and exports.
- Staff see them in full only when they open an appointment they may handle. Each time they do,
  the audit log records it.
- Other facilities' appointments stay "busy" with no patient details, as today.

### A facility leaving

- Unused patient credits from that facility's consultants are refunded to M-Pesa.
- Earned incentives are paid in the next weekly payout. Pending ones are paid only if the
  appointment is completed before the exit date; the rest are cancelled.

### Backend for platform admins

- Sign-in limited to platform admins.
- Screens:
  - facilities' agreements: version, signatories and date, and confirming standard terms;
  - consultants' pilots: first booking, day count, phase;
  - the pilot report;
  - payments, absorbed fees and refunds;
  - weekly front-desk payouts;
  - consultant plans;
  - invoices.
- The RBAC menu is **shared** with the frontend, not copied: it moves into a module both apps load.
- The backend needs its own address on the server. The nginx config mentions it, but it has not
  been checked that it is actually served.

### New data

| Table or column | Holds |
|---|---|
| `facility_agreement` | facility, version signed, who signed and when, when standard terms were confirmed, walk-in exemption on or off |
| `consultant.first_booking_at` | the start of the consultant's pilot, set once |
| `appointments.booking_channel` | online, staff, booking link or walk-in, set by every way of booking |
| `appointments.cancelled_by`, `cancelled_party`, `cancelled_at_patient_request` | who cancelled, on whose side (patient, consultant, front desk, facility, system), and whether the patient asked |
| `appointments.payment_mode`, `insurer`, `member_number`, `preauth_status` | cash or insurance, and the pre-authorisation |
| `mpesa_transaction` | every STK push, B2C call and callback, with the raw payload and receipt number |
| `commitment_fee` | one per appointment that needs it: amount, paid or absorbed, state, payment, transfers |
| `patient_credit` | free rebooking credits from clinic cancellations |
| `front_desk_incentive` | one per qualifying booking: amount, state, flag reason, payout |
| `front_desk_payout` | weekly payouts: user, total, B2C or manual reference |
| `consultant_plan` | plan A or B, price, whether insured patients pay |
| `consultant_invoice`, `consultant_invoice_line` | monthly invoices: plan charge, absorbed fees, penalties |

## Order of work, following the pilot

Each consultant's pilot runs from their own first booking, so the deadlines count from the **first
consultant** to get a booking at the first pilot facility.

| Step | Work | Needed by |
|---|---|---|
| 1 | Record the booking channel and who cancelled (and whether the patient asked); store each consultant's first booking; record facility agreements; mask contact details; the start of the pilot report. | **Day 1**: month 1 is the no-show baseline, so the data must be right from the start. |
| 2 | The Daraja component, `.env` settings, the callback and the sandbox test command. Apply for B2C production credentials. | Before day 31 |
| 3 | The commitment fee at booking: the payment hold, the exemptions, the patient's booking terms, cash or insurance, and the consultant's choice for insured patients (fees absorbed from day 31). | **Day 31** |
| 4 | Transfer, forfeit and credit rules; refunds on request. | Day 31 |
| 5 | Front-desk incentives and the weekly payout (manual until B2C is live). Backend screens for payouts. | Day 31; first payout around day 38 |
| 6 | Monthly statements of absorbed fees, paid by STK push. | End of the first month after day 31 |
| 7 | Insurer details and pre-authorisation status at booking (covers F18 / gap G5). | **Day 61** |
| 8 | The 90-day pilot report. | Day 90 |
| 9 | Plan charges and penalties on the invoice; reminders and the online-booking pause; a facility leaving; the RBAC module shared with the backend. | Day 91 |

Step 2 only needs the sandbox credentials in `.env`, so it can start at once, alongside step 1.

## Questions answered

Answered by the agreement or the decisions above:

- **Who receives the Ksh 250:** the platform. It is never taken from the clinic's revenue, except
  where a consultant chooses to absorb it for insured patients.
- **Is the plan charge per consultant or per facility:** per active consultant per month.
- **The trial period:** 90 days per consultant, from their first booking.
- **The Ksh 100 penalty:** in the agreement from v1.2.
- **Paying front-desk staff directly:** the facility agrees to it by signing the agreement.

Answered on 15 November 2026, and written into agreement v1.2:

| # | Question | Answer |
|---|---|---|
| 1 | Who counts as an active consultant for the monthly charge? | One with **at least one appointment** (any status) on the platform that month. |
| 2 | What if standard terms are not confirmed, or an invoice goes unpaid? | **Unconfirmed terms** (revised 16 November 2026): emails on days 81 and 91, a warning in the app during a 30-day grace period, then platform admins are told; nothing is charged or paused until confirmed and approved (see Design). **An unpaid invoice: reminders by email and WhatsApp, then a pause:** 14 days after the due date, patients can no longer book that consultant online. Bookings by staff keep working. Online booking resumes when the invoice is paid. |
| 3 | Are fees absorbed in days 31–90 of the pilot billed? | **Yes, on a monthly statement from day 31**, although the plan charge is still Ksh 0. |
| 4 | Which cancellations are exempt from the Ksh 100 charge? | Those made **at the patient's request**, and those caused by a **facility closure**. |
| 5 | "Once a day": across all consultants, or per consultant? | **Across all consultants.** A patient seeing two consultants on the same day pays once. |
| 6 | Do same-day bookings through a booking link, or online for later the same day, pay? | **Both pay.** Only same-day walk-ins at reception are exempt. |
| 7 | Credit or refund when the clinic cancels? | **A credit automatically; an M-Pesa refund if the patient asks.** |
| 8 | How are invoices paid? | **An STK push to the consultant's phone**, with an admin able to mark one paid by hand with a reference. |
| 9 | Where are contact details masked? | **In lists, boards, search results and exports** (`0712***678`, `j***@mail.com`). Staff see them in full only when they open an appointment they may handle, and that is audited. |
| 10 | When does the Ksh 100 charge start, and on which plans? | **From day 91 of the consultant's pilot, on both plans.** |
| 11 | What happens if a facility leaves? | **Unused patient credits are refunded** to M-Pesa. Earned incentives are paid; pending ones are paid only if the appointment is completed before the exit date. |

No questions are open. The next step is to file the work in [FEATURES.md](FEATURES.md).
