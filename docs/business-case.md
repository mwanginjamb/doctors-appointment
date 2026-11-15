# Business case: commitment fees, incentives and consultant plans

This file evaluates the HealthFirst business requirements against the code as it is in v1.1.0
(15 November 2026). It covers M-Pesa (Daraja) payments, front-desk incentives, consultant plans and
the backend for platform admins. Nothing here is built yet. Once the open questions at the end are
answered, the work will be filed as items in [FEATURES.md](FEATURES.md); it replaces the deferred
item **F5 M-Pesa deposits**.

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

## What the code has today

| Need | Today |
|---|---|
| Tell a same-day walk-in from an advance booking | **Not possible yet.** `appointments.walk_in_appointment` exists, but nothing sets it. Appointments need a recorded booking channel: online, staff advance booking, booking link or walk-in. |
| Know who cancelled | Only in the audit log. `Appointments::cancel()` stores a note, not the person or the party (patient, consultant, front desk, facility). The refund and penalty rules depend on it, so it needs its own column. |
| The 24-hour cutoff | `selfService.cutoffHours` (24) is already in `.env` and matches the refund policy. |
| Queue, email and WhatsApp | Already in place (`yii2-queue`, `NotificationService`). |
| RBAC menu | Already built, in the **frontend** (`RbacController`, admin layout). |
| Backend app | Only the Yii template: a login page and an empty home page. |
| Plans, trials, invoices and payments | Nothing yet. |

## M-Pesa, set from `.env`

A `common/components/Mpesa` component holds every Daraja call: the OAuth token (cached for just under
an hour), STK push, the STK status query and, later, B2C for refunds and payouts. Everything that
belongs to an account comes from `.env`, so moving from the sandbox to the live paybill only changes
`.env`:

```
MPESA_ENV=sandbox                # sandbox | production; picks the base URL
MPESA_CONSUMER_KEY=
MPESA_CONSUMER_SECRET=
MPESA_SHORTCODE=174379           # the sandbox test shortcode
MPESA_PASSKEY=
MPESA_CALLBACK_URL=              # a public HTTPS address; a tunnel (ngrok, cloudflared) when testing locally
MPESA_CALLBACK_TOKEN=            # a secret in the callback path, because Daraja callbacks are not signed

# Later, for B2C refunds and payouts
MPESA_B2C_SHORTCODE=
MPESA_INITIATOR_NAME=
MPESA_SECURITY_CREDENTIAL=

# Business amounts
COMMITMENT_FEE=250
FRONTDESK_INCENTIVE=50
CANCELLATION_PENALTY=100
PLAN_WITH_FEE=1000
PLAN_WITHOUT_FEE=1500
TRIAL_DAYS=
```

Every row stores the amount in force when it was created, so changing a price in `.env` never
changes past records.

Safeguards:

- A callback is accepted only if its token matches. Its result is then checked with an STK status
  query before anything is marked paid.
- Callbacks are handled once per `CheckoutRequestID`, so a repeated callback does nothing.
- A console command, `php yii mpesa/test-stk 2547…`, sends a test STK push so the sandbox can be
  checked on its own.

## Design

### Booking with a commitment fee

1. A booking that needs the fee holds the slot as **awaiting payment** for a few minutes and sends an
   STK push to the patient's phone. If payment does not arrive, a timer releases the slot.

   This is the largest change in the work. Slot search (`SlotFinder`), the overlap check in
   `BookingService`, the waiting list, the calendar and confirmation messages all have to treat this
   state correctly. Confirmations go out only after payment.
2. Who is exempt:
   - Same-day walk-ins.
   - A patient who has already paid that day.
   - Patients of consultants on the plan without commitment fees (Ksh 1,500).
3. The fee's states: **paid**, then one of:
   - **forfeited**: the patient did not come, or cancelled less than 24 hours before;
   - **transferred**: the patient rescheduled more than 24 hours ahead;
   - **refunded or credited**: the consultant or the facility cancelled.

   These are triggered from `cancel()`, `reschedule()`, `recordOutcome()` and facility closures.

### Front-desk incentive (Ksh 50)

- Created as **pending** when a front-desk user makes an advance booking and its fee is paid.
- Becomes **earned** only when the appointment time has passed and the outcome is attended.
- Is **cancelled** in two cases:
  - the appointment is a no-show, or is cancelled by anyone;
  - **gaming**: the same front-desk user cancels and rebooks the same patient within 24 hours. The
    rebooking's incentive is cancelled and the pair is flagged for review.
- Earned incentives are grouped into payout batches. A batch is marked paid with an M-Pesa
  reference, or paid through B2C later.

### Consultant plans, trials and invoices

- Each consultant has a plan (with commitment fee: Ksh 1,000; without: Ksh 1,500), a trial end date
  and a status: trial, active or past due.
- A monthly console job produces each invoice: the plan fee plus a Ksh 100 line for each
  cancellation by the consultant or their front desk.
- The consultant is emailed about each penalty.

### Backend for platform admins

- Sign-in limited to platform admins.
- Screens for payments, incentives and payouts, plans and trials, and invoices.
- The RBAC menu is **shared** with the frontend, not copied: it moves into a module both apps load.
- The backend needs its own address on the server. The nginx config mentions it, but it has not
  been checked that it is actually served.

### New data

| Table or column | Holds |
|---|---|
| `appointments.booking_channel` | online, staff, booking link or walk-in, set by every way of booking |
| `appointments.cancelled_by`, `cancelled_party` | who cancelled, and on whose side (patient, consultant, front desk, facility, system) |
| `mpesa_transaction` | every STK push, B2C call and callback, with the raw payload and receipt number |
| `commitment_fee` | one per appointment that needs it: amount, state, payment, transfers |
| `patient_credit` | free rebooking credits from clinic cancellations |
| `front_desk_incentive` | one per qualifying booking: amount, state, flag reason, payout |
| `front_desk_payout` | payout batches and their payment reference |
| `consultant_plan` | plan, price, trial end date, status |
| `consultant_invoice`, `consultant_invoice_line` | monthly invoices: the plan fee and penalty lines |

## Suggested order of work

1. The Daraja component, `.env` settings, the callback and the sandbox test command.
2. Record the booking channel and who cancelled. Everything after this depends on it.
3. The commitment fee at booking: the payment hold and the exemptions.
4. Refund, transfer, forfeit and credit rules.
5. Front-desk incentives and payouts.
6. Plans, trials, monthly invoices and penalties.
7. The backend screens, with the RBAC module shared.

Step 1 only needs the sandbox credentials in `.env`, so it can start before the questions below are
answered.

## Open questions

These change the design, so they need answers before steps 3 to 6.

1. **Who receives the Ksh 250?** HealthFirst's paybill, keeping Ksh 200 after the front desk's
   Ksh 50? Or the clinic, perhaps taken off the consultation fee? If it is the clinic, HealthFirst
   also has to pay money out to clinics.
2. **Is the Ksh 1,000 or Ksh 1,500 per consultant per month,** or per facility they work at? How
   long is the trial, and what happens when it ends unpaid?
3. **"Once a day":** is that per patient across all consultants, or per patient per consultant?
4. **A same-day booking through a booking link:** is it a remote booking (pays) or a walk-in
   (exempt)? Does a patient booking online for later the same day pay?
5. **Refund or credit by default?** Automatic refunds need B2C credentials, which take longer to
   get. The suggestion is a free rebooking credit by default, with a refund on request.
6. **The Ksh 100 penalty:** if the front desk cancels because the patient phoned to ask, does it
   still apply? If not, staff need to choose a reason (patient asked, or clinic side). Is a facility
   closure exempt?
7. **Whose invoice?** Is the penalty charged to the consultant's invoice even when their front desk
   cancelled? The brief says "their monthly invoice", read here as the consultant's.
8. **How are payments made?** Are invoices paid by an STK push to the consultant, or recorded by
   hand? Are front-desk payouts made through B2C, or by hand with a reference?
9. **Paying clinic staff directly:** front-desk users are clinic employees. Paying them directly may
   need each facility's agreement, and possibly tax handling. Raise this with the business owner.
