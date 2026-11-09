# Background services (systemd)

Appointment reminders need two things running on the server:

| Unit | What it does |
| --- | --- |
| `doctors-appointment-scheduler.timer` → `doctors-appointment-scheduler.service` | Every minute, runs `php yii notification/process` to put reminders that are now due on the queue. |
| `doctors-appointment-queue.service` | Long-running `php yii queue/listen` worker that sends the queued emails, WhatsApp, push and SMS. |
| `doctors-appointment.target` | Groups the two so they start, stop and restart together. |

## How the pieces fit

```
multi-user.target                                (normal boot)
  └─ doctors-appointment.target                  on/off switch for the group
       ├─ doctors-appointment-queue.service      always running: sends messages
       └─ doctors-appointment-scheduler.timer    fires every minute
            └─ doctors-appointment-scheduler.service   one short run: queues due reminders
```

A reminder goes through two steps:

1. Booking an appointment adds `appointment_notifications` rows with `status=pending` and a `scheduled_time`.
2. Every minute the **scheduler** finds pending rows whose `scheduled_time` has passed, puts a job on the
   queue for each one and marks the row `queued`. Rows for cancelled, deleted or past appointments are
   marked `cancelled` instead.
3. The **queue worker** picks up the job, sends the email / SMS / WhatsApp / push and marks the row
   `sent` or `failed`.

The scheduler decides *when* a reminder goes out; the worker *sends* it. They only share the queue table
in MySQL. Booking, reschedule and confirmation emails skip the scheduler: they go straight on the queue
with a 10-second delay and have no `appointment_notifications` row.

### doctors-appointment.target

Runs nothing itself; it groups the other units.

- `Wants=` starts the worker and the timer when the target starts. If one fails, the other still starts.
- `WantedBy=multi-user.target` starts the target on boot once it is enabled.
- Stop and restart reach the worker and the timer through their `PartOf=doctors-appointment.target`.

### doctors-appointment-queue.service

The long-running worker: `php yii queue/listen 5` checks the queue table every 5 seconds.

| Setting | Why |
| --- | --- |
| `After=network-online.target mysql.service mariadb.service` | The queue lives in MySQL. Whichever database unit isn't installed is ignored. |
| `Restart=always`, `RestartSec=5` | Restarts the worker 5 seconds after it exits for any reason. |
| `StartLimitIntervalSec=300`, `StartLimitBurst=10` | Gives up after 10 crashes in 5 minutes (broken deploy, database down) instead of looping forever. |
| `RuntimeMaxSec=1d` | Restarts once a day to free memory a long-running PHP process builds up. This is the `Until: … 23h left` line in `systemctl status`. |
| `KillSignal=SIGTERM`, `KillMode=mixed`, `TimeoutStopSec=310` | On stop, the worker (with `pcntl`) finishes the current job first. It is force-killed after the queue's 300 s job limit plus a margin. |
| `User=www-data` | Same owner as the web app, so `console/runtime` stays writable by both. |
| `SyslogIdentifier=doctors-queue` | Tags its lines in the journal. |

PHP loads each job class once per process, so the worker keeps running old code until it restarts.
**Restart it after every deploy.**

### doctors-appointment-scheduler.service

One run of `php yii notification/process`, started by the timer. It replaces the old cron line.

- `Type=oneshot`: runs, exits, goes inactive (`Deactivated successfully` in the journal).
- `TimeoutStartSec=55`: kills a stuck run before the next minute's run.
- No `[Install]` section and no `Restart=`: only the timer starts it, and a failed run is retried by the
  next minute's run.
- It loads fresh code on every run, so it needs no restart after a deploy.

### doctors-appointment-scheduler.timer

The every-minute clock.

- `OnCalendar=*-*-* *:*:00`: second 0 of every minute.
- `AccuracySec=1s`: by default systemd may delay a timer by up to a minute to batch wake-ups.
- `Persistent=false`: runs missed while the server was off aren't made up, because one run picks up
  everything already due.
- If a run is still going when the timer fires, systemd doesn't start a second copy (unlike cron).

Both services share the same hardening (`NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=full`, `ProtectHome`):
`/usr`, `/boot` and `/etc` are read-only to them and `/home` is hidden, while `/var/www` stays writable.

## Assumptions

The units assume the app lives at `/var/www/html/doctors-appointment`, PHP CLI is `/usr/bin/php`
(with the `pcntl` extension) and the web user is `www-data`. Edit the files if yours differ.

## Install

```bash
sudo cp deploy/systemd/doctors-appointment* /etc/systemd/system/
sudo systemd-analyze verify /etc/systemd/system/doctors-appointment*.{service,timer,target}
sudo systemctl daemon-reload
sudo systemctl enable doctors-appointment-queue.service doctors-appointment-scheduler.timer
sudo systemctl enable --now doctors-appointment.target
```

Remove any cron line for `notification/process` and any Supervisor program for `queue/listen`,
so nothing runs twice.

## Operate

```bash
sudo systemctl restart doctors-appointment.target        # after every deploy (loads new code)
sudo systemctl stop doctors-appointment.target           # pause all notifications

systemctl status doctors-appointment.target doctors-appointment-queue
systemctl list-timers doctors-appointment-scheduler.timer
journalctl -u doctors-appointment-queue -f               # worker output
journalctl -u doctors-appointment-scheduler --since -1h  # scheduler runs

php yii queue/info            # waiting / delayed / done jobs
php yii notification/stats    # pending / sent / failed reminders
```

Application-level details (SMTP transcripts, WAHA responses) are still written to
`console/runtime/logs/jobs.log` and `console/runtime/logs/mail.log`.
