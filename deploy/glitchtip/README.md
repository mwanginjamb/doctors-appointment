# Error tracking with self-hosted GlitchTip

The app sends its errors (website, console commands and the queue worker) to a Sentry-compatible
server. [GlitchTip](https://glitchtip.com) is an open-source one you run yourself, so error reports
never leave your infrastructure. It groups repeated errors, keeps stack traces, and emails you once
per new kind of error.

What the app sends: the error, its stack trace, the route, the HTTP method, the account id and
type (patient/staff), the environment and the release. **No personal data:** request bodies,
cookies, headers and URLs are dropped, and email addresses, phone numbers and link tokens are
masked in error messages (`common\log\ErrorTrackerTarget::scrub()`).

## Where to run it

GlitchTip needs Docker and about 1 GB of RAM (PostgreSQL, Redis, web and worker containers).

- **A small separate VM (recommended):** it keeps working, and keeps receiving reports, when the app
  server is struggling.
- **The app server:** fine if it has the memory to spare.

## Set up

1. Install Docker and the compose plugin.
2. Copy this folder to the GlitchTip host, for example `/opt/glitchtip`, then:
   ```bash
   cd /opt/glitchtip
   echo "POSTGRES_PASSWORD=$(openssl rand -hex 24)" > .env
   cp glitchtip.env.example glitchtip.env
   # edit glitchtip.env: SECRET_KEY, the same password in DATABASE_URL, GLITCHTIP_DOMAIN, EMAIL_URL
   docker compose up -d
   docker compose logs -f migrate     # wait for the migrations to finish
   ```
3. Serve it over HTTPS with `glitchtip.nginx.conf` (change the server name) and certbot.
4. Open the GlitchTip address, **create your account**, an organisation and a project (platform:
   PHP). Then set `ENABLE_USER_REGISTRATION=false` in `glitchtip.env` and run `docker compose up -d`.
5. Copy the project's **DSN** (Project settings → Client keys) into the app's `.env`:
   ```
   SENTRY_DSN=https://<key>@errors.example.co.ke/<project id>
   SENTRY_ENVIRONMENT=production
   APP_RELEASE=$(git rev-parse --short HEAD)    # set on each deploy, or leave empty
   ```
6. Restart the app's background services (`sudo systemctl restart doctors-appointment.target`) and
   PHP-FPM, so both the website and the queue worker pick up the DSN.
7. In GlitchTip, add the admins to the project and turn on email alerts for new issues.

## Check it works

```bash
cd /var/www/html/doctors-appointment
sudo -u www-data php yii health/test-error    # sends a test error
```

It should appear in GlitchTip within a few seconds.

## Operate

```bash
docker compose ps                     # all four containers up (migrate exits once done)
docker compose pull && docker compose up -d   # update, after pinning a newer image version
docker compose exec postgres pg_dump -U glitchtip glitchtip > glitchtip.sql   # back up
```

Error reports are kept for `GLITCHTIP_MAX_EVENT_LIFE_DAYS` (90 by default).
