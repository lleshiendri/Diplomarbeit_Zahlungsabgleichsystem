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

  <link rel="stylesheet" href="help.css"?v=<?= time() ?>/>
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
          
          <nav class="help-nav__links">
            <a class="help-link" href="#getting-started">Getting Started</a>
            <a class="help-link" href="#login">Login</a>
            <a class="help-link" href="#schoolyear">Top Navigation & School Year</a>
            <a class="help-link" href="#dashboard">Dashboard</a>
            <a class="help-link" href="#notifications">Notifications</a>
            <a class="help-link" href="#add_transactions">Add Transactions</a>
            <a class="help-link" href="#transactions">Transactions</a>
            <a class="help-link" href="#add_students">Add Students</a>
            <a class="help-link" href="#import_files">Import Files</a>
            <a class="help-link" href="#unconfirmed">Unconfirmed</a>
            <a class="help-link" href="#student_state">Student State</a>
            <a class="help-link" href="#latencies">Latencies</a>
            
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

              <div class="stack">
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
              </div>
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
                <img src="/help_page_images/Login_page.png" alt="Login page screenshot" />
              </figure>

              <div class="legend">
                <h3>On this screen</h3>
                <ul class="legend__list">
                  <li><span class="dot">1</span> Put your username or email</li>
                  <li><span class="dot">2</span> Put your password <br>(The eye icon shows/hides it)</li>
                  <li><span class="dot">3</span> Press to log in</li>
                </ul>

                <div class="callout callout--warning">
                  <strong>Roles:</strong> <br> Admin can confirm/override<br>Reader can only view.
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

           <!-- Icons and Schoolyear --> 
           <article id="schoolyear" class="help-section card" data-title="school year limits">
            <header class="help-section__header">
              <h2>Top Navigation & School Year</h2>
              <p class="muted">The top bar contains quick system actions and the school year controls.</p>
            </header>

            <div class="media media--component">
              <!-- LEFT -->
              <div class="shots">
                <figure class="shot shot--component">
                  <img src="/help_page_images/school_year.png" alt="School Year popup." />
                  <figcaption>School Year popup: view and edit the total amount.</figcaption>
                </figure>
              </div>

              <!-- RIGHT -->
              <div class="legend legend--component">
                <h3>Top navigation icons</h3>

                <ul class="icon-list">
                  <li><span class="icon material-icons-outlined">menu</span> Opens the main navigation.</li>
                  <li><span class="icon material-icons-outlined">notifications</span> Shows unread notifications and warnings.</li>
                  <li><span class="icon material-icons-outlined">priority_high</span> Displays critical system alerts.</li>
                  <li><span class="icon material-icons-outlined">calendar_month</span> Opens the School Year settings.</li>
                </ul>

                <h3>School year popup</h3>

                <p>The School Year popup allows you to view and edit the yearly School Fee for the current school year.</p>
                <ul class="steps">
                  <li><span class="dot">1</span> Select <strong>Edit amount</strong></li>
                  <li><span class="dot">2</span> Enter the new total amount (Lek)</li>
                  <li><span class="dot">3</span> Press <strong>Save</strong></li>
                </ul>

                <div class="callout callout--danger" data-role="admin">
                  <strong>Admin only:</strong> This changes the system’s maximum transaction limit.
                </div>
              </div>
            </div>
          </article>

          <!-- Dashboard -->
          <article id="dashboard" class="help-section card" data-title="dashboard">
            <header class="help-section__header">
              <h2>Dashboard</h2>
              <p class="muted">Your overview: what is happening right now.</p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/dashboard.png" alt="Dashboard screenshot" />
              </figure>

              <div class="legend">
              <h3>What the dashboard shows</h3>
              <ul class="legend__list">

                <li>
                  <div>
                    <span class="dot">1</span>
                    <strong>Payments chart (month-by-month)</strong><br>
                    This chart shows how much money has been processed throughout the year. Each bar represents the total payment amount for a month, helping you spot trends, increases, or unusual drops in payment activity.
                  </div>
                </li>

                <li>
                  
                  <div>
                    <span class="dot">2</span>
                    <strong>Number of students</strong><br>
                    Displays the total number of students currently registered in the system. It gives context to financial data and shows how many student records are actively being managed.
                  </div>
                </li>

                <li>
                  <div>
                    <span class="dot">3</span>
                    <strong>Critical cases</strong><br>
                    Highlights students or situations that require attention, such as payment issues or transaction matching problems. This acts as an alert area so administrators can quickly identify problems that need action.
                  </div>
                </li>

                <li>
                  <div>
                    <span class="dot">4</span>
                    <strong>Total transactions</strong><br>
                    Shows the total number of processed payment transactions in the system. It reflects overall system activity and how frequently payments are being recorded and matched.
                  </div>
                </li>

                <li>
                  <div>
                    <span class="dot">5</span>
                    <strong>Left to pay (outstanding total)</strong><br>
                    Indicates the total remaining unpaid amount across all students for the current school year. This is a key financial indicator that helps monitor how much money is still expected to be collected.
                  </div>
                </li>

                <li>
                  <div>
                    <span class="dot">6</span>
                    <strong>Transaction summary</strong><br>
                    This section gives a quick financial overview of the current month. It shows how many payments have been processed and the total amount collected so far. It helps administrators track monthly progress at a glance without checking detailed reports.
                  </div>
                </li>

                <li>
                  <div>
                    <span class="dot">7</span>
                    <strong>Messages</strong><br>
                    Displays important system status information. This includes payments waiting for administrator confirmation, whether transactions were successfully calculated, and the date of the last data import. It acts as a system health and activity indicator.
                    </div>
                  </div>
                </li>

              </ul>

              <div class="callout callout--info">
                The dashboard is <strong>informational</strong> and gives a real-time overview. It does not modify any data.
              </div>
            </div>
          </article>

         <!-- Notifications -->
          <article id="notifications" class="help-section card" data-title="notifications">
            <header class="help-section__header">
              <h2>Notifications</h2>
              <p class="muted">
                Your notifications center: new matches, late-fee events, and data issues information.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/notifications.png" alt="Notifications page screenshot" />
              </figure>

              <div class="legend">
                <h3>How to use this page</h3>

                <ul class="legend__list">

                  <li>
                    <span class="dot">1</span>
                    <strong>Notifications table:</strong>
                    Each row shows what happened (Description), 
                    who it affects (Student ID), 
                    which transaction is involved (Invoice ID), 
                    how serious it is (Urgency), 
                    and when it occurred (Timestamp).
                  </li>

                  <li>
                    <span class="dot">2</span>  
                    <strong>Search bar:</strong>
                    Use the search bar to narrow results by Student ID, Invoice ID,
                    Urgency (<em>Critical / Warning / Info</em>), or keywords (e.g. <code>HTL-…</code>).
                  </li>

                  <li>
                      <span class="dot">3</span> 
                      <strong>Mark as read (checkbox):</strong>
                      Tick the box on a row to select that notification. Selecting does not change the notification.
                  </li>

                  <li>
                    <span class="dot">4</span>
                    <strong>Mark all selected as read (button):</strong>
                    Click <em>“Mark all selected as read”</em> to confirm you reviewed the selected notifications.
                    This will hide them from the default “unread” view.
                  </li>
          
                </ul>

                <div class="callout callout--danger">
                  <strong>Mark as read ≠ fixed.</strong> “Mark as read only acknowledges the message. Fixing the invoice/match must be done in the relevant page.”
                </div>
              </div>
            </div>
          </article>

          <!-- Add transactions -->
          <article id="add_transactions" class="help-section card" data-title="add transactions">
            <header class="help-section__header">
              <h2>Add Transaction</h2>
              <p class="muted">
                Manually record a payment. The better the reference/details, the more accurate the automatic matching.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/add_transactions.png" alt="Add Transaction page screenshot" />
              </figure>

              <div class="legend">
                <h3>How to fill the form</h3>

                <ul class="legend__list">
                  <li><span class="dot">1</span> <strong>Reference</strong>: enter the student reference ID (<code>HTL-…</code>) if available</li>
                  <li><span class="dot">2</span> <strong>Reference Number</strong>: keep the bank/payment identifier exactly as given</li>
                  <li><span class="dot">3</span> <strong>Ordering Name</strong>: payer name (parent/student) — helps fallback matching</li>
                  <li><span class="dot">4</span> <strong>Processing Date</strong>: real booking date (affects lateness/fees and timeline)</li>
                  <li><span class="dot">5</span> <strong>Amount</strong>: paid amount (use the correct currency and decimals)</li>
                </ul>

                <div class="callout callout--info">
                  <strong>Best practice:</strong> If you have a valid reference ID, use it. It’s the strongest signal for correct assignment.
                </div>

                <div class="callout callout--warning">
                  <strong>Common mistake:</strong> Missing/incorrect reference → payment may land in <em>Unconfirmed</em> and require admin review.
                </div>
              </div>
            </div>
          </article>


          <!-- Transactions -->
          <article id="transactions" class="help-section card" data-title="transactions">
            <header class="help-section__header">
              <h2>Transactions</h2>
              <p class="muted">
                Complete list of all recorded payments, with tools to review, edit, and audit financial data.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/transactions.png" alt="Transactions overview screenshot" />
              </figure>

              <div class="legend">
                <h3>Table columns</h3>

                <ul class="legend__list">
                  <li>
                    <span class="dot">1</span>
                    <strong>Beneficiary:</strong>
                    Name of the person who made the payment.
                  </li>

                  <li>
                    <span class="dot">2</span>
                    <strong>Reference:</strong>
                    The structured payment reference used for automatic matching.
                  </li>

                  <li>
                    <span class="dot">3</span>
                    <strong>Reference Nr:</strong>
                    Bank-provided transaction identifier.
                  </li>

                  <li>
                    <span class="dot">4</span>
                    <strong>Description:</strong>
                    Payment note imported from the bank.
                  </li>

                  <li>
                    <span class="dot">5</span>
                    <strong>Processing Date:</strong>
                    Date the transaction was processed.
                  </li>

                  <li>
                    <span class="dot">6</span>
                    <strong>Amount:</strong>
                    Total payment value.
                  </li>

                  <li>
                    <span class="dot">7</span>
                    <strong>Actions:</strong>
                    <strong>Pen</strong> = edit the transaction.  
                    <strong>Trash bin</strong> = delete the transaction from the database.
                  </li>
                </ul>
              </div>
            </div>


            <div class="media">
              <div class="legend">
                <h3>Editing a transaction</h3>
                  <figure class="shot" id="transactions_edit">
                    <img src="/help_page_images/transactions_edit.png" alt="Transaction edit screenshot" />
                  </figure>
                <ul class="legend__list">
                  <li>
                    <span class="dot">1</span>
                    <strong>Amount:</strong>
                    Update the payment value if it was recorded incorrectly.
                  </li>

                  <li>
                    <span class="dot">2</span>
                    <strong>Description:</strong>
                    Modify the payment note for clarity or correction.
                  </li>

                  <li>
                    <span class="dot">3</span>
                    <strong>Save:</strong>
                    Applies the changes immediately.
                  </li>

                  <li>
                    <span class="dot">4</span>
                    <strong>Cancel:</strong>
                    Discards all changes.
                  </li>
                </ul>

                <div class="callout callout--warning">
                  Editing a transaction directly impacts balances, matching results, and financial records.
                  Only modify entries when you are certain the change is correct.
                </div>
              </div>
            </div>

          </article>



          <!-- Add Students -->
          <article id="add_students" class="help-section card" data-title="add_students">
            <header class="help-section__header">
              <h2>Add Students</h2>
              <p class="muted">
                Create a new student record with its payments, balances, and notifications.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/add_student.png" alt="Add student form screenshot" />
              </figure>

              <div class="legend">
                <h3>How to fill this form</h3>

                <ul class="legend__list">
                  <li><span class="dot">1</span> Enter the student’s Name </li>
                  <li><span class="dot">2</span> Use Long Name for full legal or descriptive naming</li>
                  <li><span class="dot">3</span> Assign the correct class</li>
                  <li><span class="dot">4</span> Set the remaining amount of payments for this student</li>
                </ul>

                <div class="callout callout--info">
                  Student records are the anchor point for transactions, balances, late fees, and notifications.
                </div>

                <div class="callout callout--warning">
                  Incorrect names or classes may cause <strong>failed or inaccurate payment matching</strong>.
                  Always double-check before submitting.
                </div>
              </div>
            </div>
          </article>


           <!-- Import Files -->
           <article id="import_files" class="help-section card" data-title="import_files">
            <header class="help-section__header">
              <h2>Import Files</h2>
              <p class="muted">
                Upload structured CSV files into the system and access previously imported files for auditing.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/import_files.png" alt="Import files screenshot" />
              </figure>

              <div class="legend">
                <h3>What you see here</h3>

                <p>
                  The screenshot shows the <strong>Transactions</strong> import tab. The <strong>Students</strong> and
                  <strong>Legal Guardians</strong> tabs work the exact same way. To switch, use the tabs above the table.
                </p>

                <h3>Table columns</h3>
                <ul class="legend__list">
                  <li>
                    <span class="dot">1</span>
                    <strong>Filename:</strong> Name of the uploaded CSV file stored by the system.
                  </li>
                  <li>
                    <span class="dot">2</span>
                    <strong>Import Date:</strong> When the file was uploaded (used for tracking and auditing).
                  </li>
                  <li>
                    <span class="dot">3</span>
                    <strong>Imported By:</strong> User account that performed the import.
                  </li>
                  <li>
                    <span class="dot">4</span>
                    <strong>Import File:</strong> Upload a new CSV into the currently selected tab (Transactions / Students / Legal Guardians).
                  </li>
                  <li>
                    <span class="dot">5</span>
                    <strong>Download:</strong> Download the original imported file again for verification or documentation.
                  </li>
                </ul>

                <div class="callout callout--warning">
                  <strong>Duplicate prevention:</strong>
                  The system blocks files that were already imported to avoid duplicate database entries.
                </div>

                <div class="callout callout--danger">
                  <strong>Strict CSV validation:</strong>
                  Only the expected CSV format is accepted for the selected tab. If the file type or structure does not match,
                  the import stops immediately with an error.
                </div>
              </div>
            </div>
          </article>


          <!-- Student State -->
          <article id="student_state" class="help-section card" data-title="student_state">
            <header class="help-section__header">
              <h2>Student State</h2>
              <p class="muted">
                Overview of each student’s current payment status: paid amount, remaining balance, and the latest activity.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/student_state.png" alt="Student State table screenshot" />
              </figure>

              <div class="legend">
                <h3>What each column means</h3>

                <ul class="legend__list">
                  <li><span class="dot">1</span> <strong>Student ID</strong> — Unique identifier used internally for linking payments and history.</li>
                  <li><span class="dot">2</span> <strong>Student Name</strong> — The student record this balance belongs to.</li>
                  <li><span class="dot">3</span> <strong>Amount Paid</strong> — Total confirmed payments assigned to this student.</li>
                  <li><span class="dot">4</span> <strong>Left to Pay</strong> — Remaining amount the student still owes (0 means fully paid).</li>
                  <li><span class="dot">5</span> <strong>Last Transaction</strong> — Date of the most recent confirmed payment (helps spot inactive cases).</li>
                  <li><span class="dot">6</span> <strong>Additional Payment Status</strong> — Extra/alternative payments tracked separately (depends on your school rules).</li>
                  <li><span class="dot">7</span> <strong>Actions</strong> — Manual tools for admins (edit / delete).</li>
                </ul>

                <h3 style="margin-top:14px;">Actions (Admin)</h3>
                <ul class="legend__list">
                  <li><span class="dot">✎</span> <strong>Edit</strong> — Correct values when a case was handled outside the normal flow (audit-required).</li>
                  <li><span class="dot">🗑</span> <strong>Delete</strong> — Removes the student entry (use only if the record is wrong/duplicate).</li>
                </ul>

                <div class="callout callout--warning">
                  <strong>Important:</strong> Only edit balances if you know exactly why the automatic matching is incorrect.
                  Manual changes should be rare, otherwise your system becomes untrustworthy.
                </div>

                <div class="callout callout--info">
                  Tip: Sort/filter by <strong>Left to Pay</strong> and <strong>Last Transaction</strong> to find overdue or inactive accounts fast.
                </div>
              </div>
            </div>
          </article>



           <!-- Latencies -->
           <article id="latencies" class="help-section card" data-title="latencies">
            <header class="help-section__header">
              <h2>Latencies</h2>
              <p class="muted">
                Overview of how late each student is based on the most recent recorded transaction compared to the latest import.
              </p>
            </header>

            <div class="media">
              <figure class="shot">
                <img src="/help_page_images/latencies.png" alt="Latencies table screenshot" />
              </figure>

              <div class="legend">
                <h3>Table columns</h3>

                <ul class="legend__list">
                  <li>
                    <span class="dot">1</span>
                    <strong>Student Name:</strong>
                    The student this row refers to.
                  </li>

                  <li>
                    <span class="dot">2</span>
                    <strong>Last Transaction Date:</strong>
                    The date of the student’s most recent payment recorded in the system.
                    If it shows <strong>—</strong>, no transaction exists for that student yet.
                  </li>

                  <li>
                    <span class="dot">3</span>
                    <strong>Last Import Date:</strong>
                    The timestamp of the latest import run (the moment the system last received new bank data).
                  </li>

                  <li>
                    <span class="dot">4</span>
                    <strong>Days Late:</strong>
                    The number of days between the last transaction date and the last import date.
                    If the student is not late, it shows <strong>Paid on time</strong>.
                  </li>
                </ul>
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


