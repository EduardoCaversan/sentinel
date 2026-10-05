<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><meta name="description" content="Sentinel monitors HTTP services, detects outages, and manages incident recovery."><title>Sentinel · Monitoring &amp; Incident Management</title><link rel="icon" href="/brand/sentinel.svg" type="image/svg+xml"><link rel="stylesheet" href="/css/sentinel.css"></head>
<body class="landing">
<x-header />
<main id="main" class="landing-main">
    <section class="landing-hero">
        <div class="eyebrow"><span class="version-pill">API v2.0</span> OBSERVABILITY, WITHOUT THE GUESSWORK</div>
        <h1>Know when it breaks.<br><span>Know when it’s back.</span></h1>
        <p class="hero-copy">HTTP monitoring with automatic incident detection and recovery. Built for the people who keep services running.</p>
        <div class="hero-actions"><a class="button primary" href="/docs">Explore the API <span aria-hidden="true">→</span></a><a class="button secondary" href="/openapi.json">OpenAPI specification</a></div>
        <div class="technical-line"><span>Laravel 12</span><span>MariaDB</span><span>Redis + Horizon</span><span>Self-hosted</span></div>
    </section>
    <section class="signal-panel" aria-labelledby="flow-title">
        <div class="panel-heading"><h2 id="flow-title">FROM SIGNAL TO RESOLUTION</h2><span class="muted">Monitoring lifecycle</span></div>
        <ol class="signal-flow"><li><span class="step-number">01</span><div><strong>Observe</strong><p>Queued HTTP checks, on your schedule.</p></div><span class="flow-code">GET /health</span></li><li><span class="step-number amber">02</span><div><strong>Detect</strong><p>Consecutive failures open an incident.</p></div><span class="flow-code amber-text">incident.opened</span></li><li><span class="step-number">03</span><div><strong>Respond</strong><p>Notify your team. Acknowledge and investigate.</p></div><span class="flow-code">async delivery</span></li><li><span class="step-number green">04</span><div><strong>Recover</strong><p>Consistent recovery resolves the incident.</p></div><span class="flow-code green-text">incident.resolved</span></li></ol>
    </section>
    <section class="feature-grid" aria-label="Platform capabilities">
        <article><span class="feature-index">01 / COLLABORATION</span><h2>A boundary for every team.</h2><p>Organizations, clear roles, and scoped API keys keep automation and human access under control.</p></article>
        <article><span class="feature-index">02 / RELIABILITY</span><h2>Every transition accounted for.</h2><p>Incident timelines, maintenance windows, durable notification deliveries, and configurable retention.</p></article>
        <article><span class="feature-index">03 / VISIBILITY</span><h2>Share the right signals.</h2><p>Uptime, latency percentiles, error budgets, and public status pages with explicitly published components.</p></article>
    </section>
    <section class="operations-strip" aria-label="Operational endpoints"><div><span class="eyebrow">SYSTEM ENDPOINTS</span><p>Inspect this instance.</p></div><a href="/health"><span class="endpoint-label">Liveness</span><code>/health</code><span>↗</span></a><a href="/health/ready"><span class="endpoint-label">Dependency readiness</span><code>/health/ready</code><span>↗</span></a><a href="/openapi.json"><span class="endpoint-label">API contract</span><code>/openapi.json</code><span>↗</span></a></section>
</main>
<footer class="site-footer"><span>SENTINEL <span class="muted">/</span> HTTP Monitoring &amp; Incident Management</span><span>v{{ config('sentinel.version') }} · MIT License</span></footer>
<script src="/js/sentinel.js" defer></script>
</body></html>
