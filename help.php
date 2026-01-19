<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Help & Tutorial</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

  <link rel="stylesheet" href="help.css" />
</head>

<body class="page-help">
  <?php require __DIR__ . '/navigator.php'; ?>

  <!-- Global contract: all pages put content inside #content -->
  <div id="content">
    <div id="helpPage">
      <main class="help">

        <!-- Left navigation -->
        <aside class="help-nav" aria-label="Help navigation">
          <div class="help-nav__search">
            <input id="helpSearch" class="input" type="search" placeholder="Search help..." />
          </div>

          <div class="help-nav__role">
            <div class="segmented" role="tablist" aria-label="Role toggle">
              <button class="segmented__btn is-active" data-role="all" type="button">All</button>
              <button class="segmented__btn" data-role="admin" type="button">Admin</button>
              <button class="segmented__btn" data-role="reader" type="button">Reader</button>
            </div>
            <p class="help-nav__hint">Choose a role to hide irrelevant actions.</p>
          </div>

          <nav class="help-nav__links">
            <a class="help-link" href="#getting-started">Getting Started</a>
            <a class="help-link" href="#login">Login</a>
            <a class="help-link" href="#dashboard">Dashboard</a>
            <a class="help-link" href="#menu">Menu & Navigation</a>
            <a class="help-link" href="#notifications">Notifications</a>
            <a class="help-link" href="#schoolyear">School Year & Limits</a>
          </nav>

          <div class="help-nav__footer">
            <small>Tip: Use the search to jump faster.</small>
          </div>
        </aside>

        <!-- Main content -->
        <section class="help-content">

          <!-- HERO -->
          <section class="hero card">
            <div class="hero__text">
              <h1>Help & Tutorial</h1>
              <p class="muted">
                This page explains every screen, action, and warning in plain language.
              </p>
              <div class="hero__chips">
                <span class="chip">Beginner-friendly</span>
                <span class="chip">Step-by-step</span>
                <span class="chip">Role-aware</span>
              </div>
            </div>
          </section>

          <!-- Getting Started -->
          <article id="getting-started" class="help-section card" data-title="getting started">
            <header class="help-section__header">
              <h2>Getting Started</h2>
              <p class="muted">What the system does and how the workflow works.</p>
            </header>

            <div class="grid2">
              <div class="panel">
                <h3>What this system is</h3>
                <p>
                This system manages all student payments in a centralized and automated way.
                Incoming transactions are analyzed and, whenever possible, automatically assigned to the correct student account and balance — reducing manual work and errors.
                </p>
                <h3>What makes it reliable</h3>
                <p>
                If a payment cannot be confirmed with full certainty, it is clearly marked for review instead of being assigned blindly.
                This ensures that every balance remains accurate and traceable.
                </p>
                <h3> Notifications & late payments</h3>
                <p>
                The system continuously monitors payment status and deadlines.
                When payments are missing or delayed, notifications are generated automatically for the administration and, where applicable, for the paying party (students or parents).
                Late payments are handled according to a defined late-fee logic, so rules are applied consistently and transparently.
                </p>
                <h3>Overview & control</h3>
                <p>
                In addition to individual student balances, the system provides statistics and overviews that show the current state of payments across all students — including totals, outstanding amounts, and critical cases.
                </p>
              

                <div class="callout callout--info">
                  <strong>Role-based access:</strong> Only authorized users can confirm, adjust, or override payment data.
                </div>
              </div>

              <div class="panel flow">
                <h3>Payment lifecycle</h3>
                <ul class="flow-list">
                  <li><span class="flow-dot"></span><span>Payment received</span></li>
                  <li><span class="flow-dot"></span><span>Automatic matching</span></li>
                  <li><span class="flow-dot"></span><span>Review if uncertain</span></li>
                  <li><span class="flow-dot"></span><span>Balance updated</span></li>
                  <li><span class="flow-dot"></span><span>Monitoring & notifications</span></li>
                </ul>
              </div>

              <div class="panel role-split">
                <h3>Who does what</h3>

                <div class="role role-admin">
                  <strong>Administrator</strong>
                  <ul>
                    <li>Confirms or corrects payments</li>
                    <li>Manages students and imports</li>
                    <li>Handles notifications and limits</li>
                  </ul>
                </div>

                <div class="role role-reader">
                  <strong>Reader</strong>
                  <ul>
                    <li>Views balances and transactions</li>
                    <li>Monitors payment status</li>
                    <li>Cannot change data</li>
                  </ul>
                </div>
              </div>

              <div class="panel guidance">
                <h3>How to work with the system</h3>

                <ul class="checklist">
                  <li>Nothing is assigned blindly</li>
                  <li>Unconfirmed cases always require review</li>
                  <li>Critical issues generate notifications</li>
                  <li>All actions are traceable</li>
                </ul>

                <p class="muted small">
                  If something needs your attention, the system will clearly indicate it.
                </p>
              </div> 
          </article>

          <!-- Login -->
          <article id="login" class="help-section card" data-title="login">
            <header class="help-section__header">
              <h2>Login</h2>
              <p class="muted">How to access the system and what the fields mean.</p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="assets/help/login.png" alt="Login page screenshot" />
                <span class="marker m1">1</span>
                <span class="marker m2">2</span>
                <span class="marker m3">3</span>
              </figure>

              <div class="legend">
                <h3>On this screen</h3>
                <ul class="legend__list">
                  <li><span class="dot">1</span> Username or Email</li>
                  <li><span class="dot">2</span> Password (eye icon shows/hides it)</li>
                  <li><span class="dot">3</span> Log in button</li>
                </ul>

                <div class="callout callout--warning">
                  <strong>Roles:</strong> Admin can confirm/override; Reader can only view.
                </div>
              </div>
            </div>

            <details class="details">
              <summary>Common login issues</summary>
              <ul>
                <li>Wrong username/email → try the other format</li>
                <li>Caps Lock → password is case-sensitive</li>
                <li>Too many attempts → wait for lockout to end</li>
              </ul>
            </details>
          </article>

          <!-- Dashboard -->
          <article id="dashboard" class="help-section card" data-title="dashboard">
            <header class="help-section__header">
              <h2>Dashboard</h2>
              <p class="muted">Your overview: what is happening right now.</p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="assets/help/dashboard.png" alt="Dashboard screenshot" />
                <span class="marker m1">1</span>
                <span class="marker m2">2</span>
                <span class="marker m3">3</span>
                <span class="marker m4">4</span>
                <span class="marker m5">5</span>
              </figure>

              <div class="legend">
                <h3>What the dashboard shows</h3>
                <ul class="legend__list">
                  <li><span class="dot">1</span> Payments chart (month-by-month)</li>
                  <li><span class="dot">2</span> Number of students</li>
                  <li><span class="dot">3</span> Critical cases</li>
                  <li><span class="dot">4</span> Total transactions</li>
                  <li><span class="dot">5</span> Left to pay (outstanding total)</li>
                </ul>

                <div class="callout callout--info">
                  Dashboard is <strong>informational</strong>. It does not change data.
                </div>
              </div>
            </div>
          </article>

          <!-- Menu -->
          <article id="menu" class="help-section card" data-title="menu navigation">
            <header class="help-section__header">
              <h2>Menu & Navigation</h2>
              <p class="muted">How to access each module from the sidebar.</p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="assets/help/menu.png" alt="Sidebar menu screenshot" />
              </figure>

              <div class="legend">
                <h3>Menu items</h3>
                <div class="table">
                  <div class="table__row table__head">
                    <div>Page</div><div>Purpose</div>
                  </div>
                  <div class="table__row"><div>Add Transaction</div><div>Create a payment manually</div></div>
                  <div class="table__row"><div>Transactions</div><div>View all payments</div></div>
                  <div class="table__row"><div>Add Students</div><div>Register students</div></div>
                  <div class="table__row"><div>Import File</div><div>Upload bank data</div></div>
                  <div class="table__row"><div>Unconfirmed</div><div>Review uncertain matches</div></div>
                  <div class="table__row"><div>Student State</div><div>See balance per student</div></div>
                  <div class="table__row"><div>Latencies</div><div>Late payment overview</div></div>
                  <div class="table__row"><div>Notifications</div><div>System alerts</div></div>
                </div>
              </div>
            </div>
          </article>

          <!-- Notifications -->
          <article id="notifications" class="help-section card" data-title="notifications">
            <header class="help-section__header">
              <h2>Notifications</h2>
              <p class="muted">Warnings and system messages you must review.</p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="assets/help/notifications.png" alt="Notifications screenshot" />
              </figure>

              <div class="legend">
                <h3>What to do here</h3>
                <ul class="legend__list">
                  <li><span class="dot">✓</span> Use search to find a student or invoice</li>
                  <li><span class="dot">✓</span> Check urgency: Info / Warning / Critical</li>
                  <li><span class="dot">✓</span> Mark selected rows as read</li>
                </ul>

                <div class="callout callout--danger">
                  Marking as read does <strong>not</strong> undo the cause. It only confirms you saw it.
                </div>
              </div>
            </div>
          </article>

          <!-- Schoolyear -->
          <article id="schoolyear" class="help-section card" data-title="school year limits">
            <header class="help-section__header">
              <h2>School Year & Limits</h2>
              <p class="muted">The total amount is a hard system limit.</p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="assets/help/schoolyear.png" alt="School year popup screenshot" />
              </figure>

              <div class="legend">
                <h3>Editing the total amount</h3>
                <ol class="steps">
                  <li>Open the School Year popup</li>
                  <li>Click “Edit amount”</li>
                  <li>Enter the new total</li>
                  <li>Save</li>
                </ol>

                <div class="callout callout--danger" data-role="admin">
                  <strong>Admin only:</strong> This changes the allowed maximum for transactions.
                </div>
              </div>
            </div>
          </article>

          <footer class="help-footer">
            <p class="muted">
              If something looks different than this guide, it may be because of your role (Admin/Reader) or because the page has no data yet.
            </p>
          </footer>

        </section>
      </main>
    </div>
  </div>

  <script>
    const roleBtns = document.querySelectorAll('#helpPage .segmented__btn');
    const roleBlocks = document.querySelectorAll('#helpPage [data-role]');
    roleBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        roleBtns.forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        const role = btn.dataset.role;

        roleBlocks.forEach(el => {
          const r = el.dataset.role;
          el.style.display = (role === 'all' || r === role) ? '' : 'none';
        });
      });
    });

    const search = document.getElementById('helpSearch');
    const sections = document.querySelectorAll('#helpPage .help-section');
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      sections.forEach(sec => {
        const title = (sec.dataset.title || '').toLowerCase();
        const text = sec.innerText.toLowerCase();
        sec.style.display = (!q || title.includes(q) || text.includes(q)) ? '' : 'none';
      });
    });

    const links = document.querySelectorAll('#helpPage .help-link');
    const ids = Array.from(links).map(a => a.getAttribute('href').slice(1));
    const obs = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (!e.isIntersecting) return;
        links.forEach(a => a.classList.toggle('is-active', a.getAttribute('href') === '#' + e.target.id));
      });
    }, { rootMargin: '-40% 0px -55% 0px', threshold: 0.01 });

    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) obs.observe(el);
    });
  </script>
</body>
</html>