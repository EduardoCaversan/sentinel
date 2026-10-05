'use strict';
document.querySelectorAll('.docs-sidebar nav a').forEach(link => {
    link.addEventListener('click', event => {
        const tag = decodeURIComponent(link.hash.slice(2));
        const heading = [...document.querySelectorAll('.swagger-ui .opblock-tag')].find(element => element.dataset.tag === tag);
        if (!heading) return;
        event.preventDefault();
        if (!heading.closest('.opblock-tag-section').classList.contains('is-open')) heading.click();
        heading.scrollIntoView({block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    });
});
document.querySelectorAll('[data-system-state]').forEach(async element => {
    try {
        const response = await fetch('/health/ready', {headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(8000)});
        element.classList.add(response.ok ? 'is-healthy' : 'is-degraded');
        element.querySelector('span').textContent = response.ok ? 'SYSTEM READY' : 'DEPENDENCIES DEGRADED';
        element.title = 'Database and Redis readiness. Worker and scheduler health are inspected separately.';
    } catch {
        element.classList.add('is-degraded');
        element.querySelector('span').textContent = 'STATUS UNAVAILABLE';
    }
});
