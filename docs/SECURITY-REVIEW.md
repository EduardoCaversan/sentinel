# Sentinel V2 security review

## Scope

Reviewed authentication, tenant bindings, roles, key scopes, mass assignment, SQL usage, outgoing HTTP, queues, retention, public projections, Blade rendering, secrets, logging and container startup. This is an application review with regression tests, not an independent penetration test or an infrastructure security certification.

The baseline audit is in [V2-AUDIT.md](V2-AUDIT.md). Executed checks and versions are in [VERIFICATION.md](VERIFICATION.md).

## Preserved guarantees

| Boundary | Implementation / evidence |
| --- | --- |
| SSRF validation | `PublicTarget` and existing `SsrfTest` cover local/private/reserved IPv4/IPv6, alternative encodings and mixed DNS answers |
| Rebinding and transport | DNS is checked at execution; cURL pins the approved address, verifies TLS, disables redirects/proxy/reuse |
| Tenant isolation | `OrganizationAccess` authorizes role/scope before tenant-scoped resource binding; foreign IDs return 404 |
| Concurrent checks | Redis unique jobs and execution locks; row-locked check transaction; UUID receipt and check uniqueness |
| Incident consistency | Generated open slot enforces one open incident; failure/recovery thresholds and stale configuration guard retained |
| Secret storage | Password hashing/Sanctum unchanged; API keys and invitations hashed; channel endpoints/HMAC secrets encrypted |
| Public output | Explicit projection, component aliases, publication check before cache, escaped Blade text |
| Resource bounds | Validation, rate limits, locked quotas, batch retention, max timeouts/body bytes, bounded analytics ranges |

## Findings and fixes

1. **V1 ownership needed a migration boundary.** Existing users receive personal organizations and keep monitor/check/incident IDs. V1 routes resolve only the caller's personal organization. Shared organization membership cannot be bypassed through V1. Creator deletion now sets `user_id` to null instead of deleting shared monitor history.
2. **Retention could weaken replay protection.** A separate execution receipt survives check pruning for seven days. Jobs older than 24h are discarded. A retained UUID cannot cause another probe or transition after its historical check is pruned.
3. **Quota checks required serialization.** Resource creation locks the organization before counting and inserting. Member quotas include pending invitations. A separate-process MariaDB verification races two creations for one available slot.
4. **Webhook URLs contain credentials.** Both URL and signing secret are encrypted and hidden from serialization. Transport errors are reduced to a fixed error type. Failed receiver responses are discarded. Slack uses plain text; Discord suppresses mentions.
5. **External effects must survive queue failures without affecting checks.** Outbox rows commit with monitoring. A separate dispatcher queues delivery after commit. Retry state is persisted. Rollback tests verify checks/outbox disappear together; remote failures leave incident state intact.
6. **Omitting the interval could bypass configured minimums.** Monitor creation supplies the instance minimum, and subsequent scheduling applies it to existing monitors too.
7. **Large responses could waste transfer resources.** Both monitoring and webhook transport abort above the configured byte limit, in addition to bounded timeouts and null response sinks.
8. **Administrative invitations needed the same role boundary as members.** Admins cannot invite, modify, remove or cancel an invitation for another administrator. Owner transfer is a separate operation.
9. **Raw query exceptions can include sensitive bindings.** Database failures now log only exception class and SQLSTATE. API uniqueness races return a sanitized 409 instead of leaking database details. Regression tests inspect both API output and logger arguments.
10. **Local key fallback could cross into production.** Only local mode reads the generated shared key. Container startup outside local requires explicit `APP_KEY`. Local CLI commands can read the same generated key without manually exporting it.
11. **Extreme expiration dates could exceed database timestamp limits.** Optional API key expiry is validated within the next year; null means no expiry. Invalid dates return 422.
12. **New projections/contracts needed integration coverage.** Tests found and corrected the status-page pivot table name and timeline serialization. Timeline timestamps are normalized to UTC and maintenance flags to booleans.

## Authorization review

- No API input can change organization ownership, monitor health, execution UUID or configuration version.
- Organization keys have explicit permissions and no wildcard/admin scope. Keys cannot call account, organization, membership or key-management endpoints.
- Viewer/member restrictions are tested as a role/action matrix.
- Monitor/check/incident/analytics, channel/delivery/status-page and invitation boundaries are covered.
- Personal token and organization key manual-check limits share the same organization counter. Invalid foreign IDs are bound before consuming that counter.
- Owner removal/leave is blocked until transfer. Personal ownership cannot transfer. Organization creator and monitor creator are separate concepts.

## Data and transport review

- All variable SQL values use Laravel bindings. SQL metric expressions contain fixed column/function choices, not user input.
- Collections paginate; status pages have at most ten components, bounded incident/window previews, and 30-second caching.
- No arbitrary PHP or remote response object is serialized into jobs. Jobs carry identifiers and execution metadata; workers load current database state.
- Public pages never expose monitor URLs, private names unless explicitly used as public aliases, notes, channel secrets, organization membership or internal resource IDs.
- Authentication credentials are not persisted by Swagger UI across reloads; assets are self-hosted.
- Historical maintenance remains immutable through the API so users cannot rewrite previously classified maintenance samples.

## Remaining operational limits

- Use network egress controls and trusted DNS. Public IP validation cannot prove that an operator's routing/proxy infrastructure is safe.
- Public registration is not email-verified and has no CAPTCHA. For a public demonstration, disable registration after provisioning controlled accounts. Rate limits alone do not prevent creation of multiple accounts.
- Invitations are bearer secrets distributed manually through a trusted channel; matching an email string is not independent proof of email ownership.
- An already-authorized in-flight request or delivery may finish after membership/channel changes. Revocation applies to subsequent authentication checks; there is no global cancellation barrier.
- Webhooks are at least once. A crash after remote acceptance may result in duplicate delivery; receivers must use the stable idempotency key.
- Incident summaries, human events, revoked key metadata and completed maintenance are not archived automatically. Plan archival/backup policies for long-lived installations.
- A status page can show cached component health for 30 seconds. Unpublishing is checked independently of cache on every request.
- No external Slack/Discord workspace delivery was sent during verification. Provider payloads and transport are verified with HTTP fakes.
- Public deployment, TLS termination, backup restoration, load testing and remote CI execution remain environment-specific work.
