# Background services (systemd)

Appointment reminders need two things running on the server:

| Unit | What it does |
| --- | --- |
| `doctors-appointment-scheduler.timer` → `doctors-appointment-scheduler.service` | Every minute, runs `php yii notification/process` to put reminders that are now due on the queue. |
| `doctors-appointment-queue.service` | Long-running `php yii queue/listen` worker that sends the queued emails, WhatsApp, push and SMS. |
| `doctors-appointment.target` | Groups the two so they start, stop and restart together. |

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
