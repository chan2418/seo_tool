<?php
session_start();
require_once __DIR__ . '/../services/PlanEnforcementService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AccountStatusMiddleware.php';

$auth = AuthMiddleware::requireLogin(false);
AccountStatusMiddleware::ensureActive(false);

$userId = (int) ($auth['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$planService = new PlanEnforcementService();
$userName = (string) ($auth['user_name'] ?? ($_SESSION['user_name'] ?? 'User'));
$planType = $planService->getEffectivePlan($userId, (string) ($_SESSION['plan_type'] ?? 'free'));
$planLabel = ucfirst($planType);
$featureAccess = $planService->assertFeatureAccess($userId, 'multi_page_crawler');
$isAgency = !empty($featureAccess['allowed']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-page Crawler - SEO SaaS</title>
    <script>
        (function () {
            var storedTheme = localStorage.getItem('seo-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { brand: { 500: '#4F46E5', 400: '#6366F1' } },
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    boxShadow: { soft: '0 18px 45px -25px rgba(15,23,42,0.35)' }
                }
            }
        };
    </script>
    <style>
        .surface-card { border-radius: 1rem; border: 1px solid rgba(255,255,255,.65); background: rgba(255,255,255,.86); backdrop-filter: blur(10px); }
        .dark .surface-card { border-color: rgba(51,65,85,.8); background: rgba(30,41,59,.82); }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 border-b border-white/60 bg-slate-100/80 px-4 py-4 backdrop-blur-xl dark:border-slate-700 dark:bg-slate-950/70 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between"><div class="flex items-center gap-3"><button id="sidebar-open" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden" aria-label="Open sidebar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg></button><div><p class="text-xs uppercase tracking-[0.2em] text-slate-500">Technical Crawl</p><h1 class="text-xl font-bold">Multi-page SEO Crawler</h1></div></div><div class="flex items-center gap-3"><button id="theme-toggle" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">🌓</button><span class="rounded-xl px-3 py-2 text-xs font-bold <?php echo $isAgency ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'; ?>"><?php echo htmlspecialchars($planLabel); ?></span><span class="hidden text-sm font-semibold sm:block"><?php echo htmlspecialchars($userName); ?></span></div></div>
        </header>

        <main class="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-10">
            <section class="surface-card p-6 shadow-soft sm:p-8">
                <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]"><div><h2 class="text-2xl font-extrabold">Crawl up to 10 internal pages</h2><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Depth limit 1. Detect duplicate titles, missing H1/meta description, broken links, and thin content.</p></div><div class="rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 p-5 text-white"><p class="text-xs uppercase tracking-[0.2em] text-indigo-100">Mode</p><p class="mt-2 text-lg font-bold"><?php echo $isAgency ? 'Agency Active' : 'Agency Required'; ?></p><p class="mt-2 text-xs text-indigo-100">Async crawl simulation with progress polling.</p></div></div>

                <form id="crawl-form" class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <input id="url-input" type="text" maxlength="200" placeholder="https://example.com" class="w-full flex-1 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-medium outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:focus:border-brand-400 dark:focus:ring-brand-500/30" <?php echo $isAgency ? '' : 'disabled'; ?>>
                    <button id="start-btn" type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-brand-500 to-brand-400 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60" <?php echo $isAgency ? '' : 'disabled'; ?>>
                        <svg id="start-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 12a8 8 0 0 1 8-8m0 16a8 8 0 0 1-8-8"/></svg>
                        Start Crawl
                    </button>
                </form>

                <div id="message" class="mt-4 hidden rounded-2xl border p-4 text-sm font-medium"></div>
                <div class="mt-5">
                    <div class="mb-2 flex items-center justify-between text-xs text-slate-500"><span>Progress</span><span id="progress-text">0%</span></div>
                    <div class="h-3 rounded-full bg-slate-200 dark:bg-slate-700"><div id="progress-bar" class="h-3 rounded-full bg-gradient-to-r from-brand-500 to-brand-400" style="width:0%"></div></div>
                </div>
            </section>

            <?php if (!$isAgency): ?>
            <section class="surface-card p-6 shadow-soft sm:p-8"><h3 class="text-lg font-bold">Upgrade to Agency</h3><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Crawler and white-label reporting are available on Agency plan.</p><div class="mt-4 flex gap-3"><a href="subscription.php" class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-400 px-4 py-2 text-sm font-semibold text-white">View Plans</a></div></section>
            <?php endif; ?>

            <section id="score-grid" class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Final Score</p><p id="score-final" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Technical</p><p id="score-technical" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Content</p><p id="score-content" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Authority</p><p id="score-authority" class="mt-2 text-2xl font-extrabold">0</p></article>
                <article class="surface-card p-5 shadow-soft"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Keyword Opt</p><p id="score-keyword" class="mt-2 text-2xl font-extrabold">0</p></article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-lg font-bold">Error Summary</h3>
                    <div id="issue-grid" class="mt-4 grid gap-3 sm:grid-cols-2"></div>
                </article>
                <article class="surface-card p-6 shadow-soft sm:p-8">
                    <h3 class="text-lg font-bold">Analyzed URLs</h3>
                    <ul id="url-list" class="mt-4 space-y-2 text-sm"></ul>
                </article>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var runId = null;
            var pollTimer = null;
            var form = document.getElementById('crawl-form');
            var input = document.getElementById('url-input');
            var startBtn = document.getElementById('start-btn');
            var spinner = document.getElementById('start-spinner');
            var msg = document.getElementById('message');
            var hasAccess = <?php echo $isAgency ? 'true' : 'false'; ?>;

            function setLoading(loading) {
                startBtn.disabled = loading;
                if (loading) spinner.classList.remove('hidden'); else spinner.classList.add('hidden');
            }

            function showMessage(type, text) {
                var classes = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
                    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
                    info: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-200'
                };
                msg.className = 'mt-4 rounded-2xl border p-4 text-sm font-medium';
                msg.classList.remove('hidden');
                (classes[type] || classes.info).split(' ').forEach(function (c) { msg.classList.add(c); });
                msg.textContent = text;
            }

            function updateProgress(percent) {
                percent = Math.max(0, Math.min(100, Number(percent || 0)));
                document.getElementById('progress-bar').style.width = percent + '%';
                document.getElementById('progress-text').textContent = Math.round(percent) + '%';
            }

            function renderRun(run, pages) {
                updateProgress(run.progress || 0);
                document.getElementById('score-grid').classList.remove('hidden');
                document.getElementById('score-final').textContent = String(run.final_score || 0);
                document.getElementById('score-technical').textContent = String(run.technical_score || 0);
                document.getElementById('score-content').textContent = String(run.content_score || 0);
                document.getElementById('score-authority').textContent = String(run.authority_score || 0);
                document.getElementById('score-keyword').textContent = String(run.keyword_score || 0);

                var issues = run.issues || {};
                var issueGrid = document.getElementById('issue-grid');
                issueGrid.innerHTML = '';
                [
                    ['Duplicate Titles', issues.duplicate_titles || 0],
                    ['Missing H1', issues.missing_h1 || 0],
                    ['Missing Meta Description', issues.missing_meta_description || 0],
                    ['Broken Links', issues.broken_links || 0],
                    ['Thin Content', issues.thin_content || 0]
                ].forEach(function (pair) {
                    var card = document.createElement('div');
                    card.className = 'rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900';
                    card.innerHTML = '<p class="text-xs uppercase tracking-wide text-slate-500">' + pair[0] + '</p><p class="mt-1 text-xl font-bold">' + Number(pair[1]) + '</p>';
                    issueGrid.appendChild(card);
                });

                var list = document.getElementById('url-list');
                list.innerHTML = '';
                (pages || []).forEach(function (page) {
                    var li = document.createElement('li');
                    li.className = 'rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900';
                    li.innerHTML = '<p class="truncate font-semibold" title="' + escapeHtml(page.url || '') + '">' + escapeHtml(page.url || '') + '</p>' +
                        '<p class="mt-1 text-xs text-slate-500">Words: ' + Number(page.word_count || 0) + ' | Broken links: ' + Number(page.broken_links || 0) + '</p>';
                    list.appendChild(li);
                });
            }

            async function pollStatus() {
                if (!runId) return;
                try {
                    var response = await fetch('crawl-status.php?run_id=' + encodeURIComponent(runId));
                    var payload = await response.json();
                    if (!response.ok || !payload.success) throw payload;

                    renderRun(payload.run || {}, payload.pages || []);

                    if ((payload.run || {}).status === 'completed') {
                        clearInterval(pollTimer);
                        pollTimer = null;
                        showMessage('success', 'Crawl complete. Technical SEO score calculated.');
                        setLoading(false);
                        return;
                    }

                    if ((payload.run || {}).status === 'failed') {
                        clearInterval(pollTimer);
                        pollTimer = null;
                        showMessage('error', (payload.run || {}).error_message || 'Crawl failed.');
                        setLoading(false);
                    }
                } catch (error) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                    showMessage('error', error.error || 'Unable to fetch crawl status.');
                    setLoading(false);
                }
            }

            async function startCrawl(url) {
                setLoading(true);
                showMessage('info', 'Crawl started. Processing pages...');
                updateProgress(0);

                try {
                    var response = await fetch('crawl-start.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ url: url })
                    });

                    var raw = await response.text();
                    var payload = null;
                    try {
                        payload = JSON.parse(raw);
                    } catch (parseError) {
                        payload = {
                            success: false,
                            error: 'Server returned an invalid response (' + response.status + ').',
                            raw_response: raw
                        };
                    }
                    if (!response.ok || !payload.success) throw payload;

                    runId = payload.run_id;
                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(pollStatus, 1300);
                    pollStatus();
                } catch (error) {
                    setLoading(false);
                    showMessage('error', error.error || ('Unable to start crawl (HTTP ' + (error.status || 'unknown') + ').'));
                }
            }

            function escapeHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                if (!hasAccess) {
                    showMessage('info', 'Multi-page crawler requires Agency plan. Upgrade to continue.');
                    return;
                }

                var url = input.value.trim();
                if (url.length < 4) {
                    showMessage('error', 'Enter a valid URL.');
                    return;
                }
                startCrawl(url);
            });

            document.getElementById('theme-toggle').addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('seo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });
        })();
    </script>
</body>
</html>
