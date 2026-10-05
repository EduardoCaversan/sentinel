<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark">
    <title>Sentinel · API Reference</title><meta name="description" content="Explore the Sentinel API. Organizations, HTTP monitors, incidents, notifications, analytics and public status pages.">
    <link rel="icon" href="/brand/sentinel.svg" type="image/svg+xml"><link rel="stylesheet" href="/vendor/swagger-ui.css"><link rel="stylesheet" href="/css/sentinel.css"><link rel="stylesheet" href="/css/swagger-theme.css">
</head>
<body>
<x-header />
<div class="docs-layout">
<aside class="docs-sidebar" aria-label="API sections">
    <p class="sidebar-heading">API REFERENCE</p>
    <nav>
        @foreach(['Authentication','Organizations','Members','API Keys','Monitors','Checks','Incidents','Maintenance','Notifications','Analytics','Status Pages','Operations'] as $tag)
        <a href="#/{{ rawurlencode($tag) }}">{{ $tag }}</a>
        @endforeach
    </nav>
    <div class="sidebar-divider"></div><p class="sidebar-heading">CONNECTION</p>
    <p class="sidebar-note">Base path<br><code>/api/v2</code><br><br>Authentication<br><code>Authorization: Bearer …</code><br><br>JSON requests and responses. Dates use UTC. Collections are paginated.</p>
    <div class="sidebar-divider"></div><a class="sidebar-note" href="/openapi.json">Download OpenAPI 3.0 ↗</a>
</aside>
<main id="main" class="docs-main">
    <section class="docs-hero">
        <div class="eyebrow"><span class="version-pill">API v2.0</span> DEVELOPER REFERENCE</div>
        <h1>Your services. In sight.</h1>
        <p>Monitor endpoints, coordinate incident response, and publish service health. Everything you need to operate Sentinel, through one API.</p>
        <div class="docs-meta"><span><strong>REST</strong> / JSON</span><span><strong>Bearer</strong> authentication</span><span><strong>UTC</strong> timestamps</span><span><strong>v1</strong> compatible</span></div>
    </section>
    <section class="quick-start" aria-label="Getting started">
        <article><strong><span class="number">01</span> Get a token</strong>Register or log in under Authentication. Copy <code>data.token</code> into Authorize below.</article>
        <article><strong><span class="number">02</span> Choose an organization</strong>List your organizations and use an ID in organization-scoped requests.</article>
        <article><strong><span class="number">03</span> Run your first check</strong>Create a public HTTPS monitor. Trigger a check and inspect its history.</article>
    </section>
    <div class="docs-note"><strong>Interactive reference.</strong> “Try it out” sends real requests to this instance. Authorization stays in this browser tab and is cleared on reload. API keys use the same Bearer field with explicit organization scopes.</div>
    <div id="swagger-ui" class="swagger-container"></div>
    <noscript><p>JavaScript is required for the interactive API reference. <a href="/openapi.json">Download the OpenAPI specification</a>.</p></noscript>
</main>
</div>
<footer class="site-footer"><span>SENTINEL <span class="muted">/</span> API Reference</span><span>v{{ config('sentinel.version') }} · Self-hosted documentation</span></footer>
<script src="/vendor/swagger-ui-bundle.js"></script>
<script>
    window.ui = SwaggerUIBundle({
        url: '/openapi.json', dom_id: '#swagger-ui', deepLinking: true,
        persistAuthorization: false, validatorUrl: null,
        presets: [SwaggerUIBundle.presets.apis], tryItOutEnabled: true,
        displayRequestDuration: true, filter: true, docExpansion: 'none',
        defaultModelsExpandDepth: 0, defaultModelExpandDepth: 2,
        supportedSubmitMethods: ['get', 'post', 'patch', 'delete'],
        syntaxHighlight: {activate: true, theme: 'tomorrow-night'},
        requestInterceptor: function(request) {
            request.headers.Accept = 'application/json';
            return request;
        }
    });
</script>
<script src="/js/sentinel.js" defer></script>
</body>
</html>
