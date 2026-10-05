# V1 audit and V2 implementation boundaries

Baseline: commit `d056841`, clean worktree, 2026-10-04. Reviewed README, verification report, all application/domain code, migrations, factories, seeders, requests/resources, routes, tests, configuration, Compose/Docker, Horizon, scheduler, OpenAPI and CI. Baseline suite: 60 tests / 187 assertions passing in the running V1 image.

## Guarantees to preserve

- PublicTarget validates syntax and every A/AAAA address; blocks local/private/reserved IPv4 and dangerous IPv6 representations.
- HttpProbe forces cURL, pins validated DNS using CURLOPT_RESOLVE, verifies TLS, disables redirects/proxies/reuse, discards bodies and sanitizes errors.
- CheckMonitorJob uses Redis execution locks and unique jobs; execution UUIDs prevent duplicate persistence.
- RecordCheck locks the monitor in a transaction; stale configuration versions are discarded; thresholds reset correctly.
- The generated incident open_slot unique index prevents duplicate open incidents independently of application code.
- Sanctum, rate limiting, JSON logging, health/readiness, minute dispatch, Horizon, self-hosted Swagger, OpenAPI validation and SQLite/MariaDB testing remain.

## Findings and decisions

- V1 ownership is exclusively user_id. V2 adds explicit organization routes and organization membership/scopes before resource lookup. Existing users and monitors receive personal organizations. V1 remains restricted to the caller's personal organization; it cannot become a bypass into shared organizations.
- V1 monitor deletion cascades from creator deletion. V2 makes creator attribution nullable; organization ownership controls resource lifetime.
- V1 execution deduplication lives only in check history. Retention must preserve a separate bounded execution receipt beyond queue retry lifetimes.
- V1 has no durable notification boundary. V2 writes delivery records with check transitions, then dispatches them from a recoverable outbox. Notification HTTP never runs inside recording transactions.
- V1 has no body bandwidth cap, quotas, history retention, public publication boundary, or scheduler freshness signal. V2 introduces bounded responses, configurable limits, batched cleanup and operational telemetry.
- Invitations use a one-time random token tied to an email/account, with expiration and a hash at rest. No outbound invitation email is required; the inviter shares the token privately.
- Maintenance is non-recurring. Checks remain visible but are excluded from uptime and do not advance failure/recovery or emit alerts. Streaks reset across maintenance, including gaps without checks.
- Analytics use SQL aggregates and nearest-rank ordered queries for percentiles; no unbounded check collections. Uptime is sample-based. Outage durations use incident wall time and are explicitly distinct from sample availability.
- Public status responses use explicit allowlists and component aliases; internal monitor URLs, notes, users, organization details and webhook settings never appear.
- Email transport is deferred: generic HTTPS webhooks, Slack and Discord cover V2 without unsafe SMTP configuration by API users.
- Larastan targets level 5 across application code without a generated ignore baseline.

Implementation and final security findings are recorded separately after regression verification.
