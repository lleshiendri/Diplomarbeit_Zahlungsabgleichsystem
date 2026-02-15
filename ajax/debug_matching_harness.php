<?php
/**
 * Minimal client harness for matching pipeline debug endpoint.
 * Requires auth; Admin or MATCHING_DEBUG for the endpoint to return data.
 */
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../navigator.php';
$pageTitle = 'Matching pipeline debug';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        pre { background: #f5f5f5; padding: 1rem; overflow: auto; max-height: 70vh; }
        .row { margin: 0.5rem 0; }
        input[type="number"] { width: 120px; }
        button { padding: 0.3rem 0.8rem; }
    </style>
</head>
<body>
<?php if (isset($nav)) echo $nav; ?>
<h1>Matching pipeline debug</h1>
<div class="row">
    <label for="invoice_id">Invoice / Transaction ID:</label>
    <input type="number" id="invoice_id" name="invoice_id" min="1" placeholder="e.g. 42">
</div>
<div class="row">
    <label><input type="checkbox" id="persist" name="persist" value="1"> Persist (run Stage 6, write DB)</label>
</div>
<div class="row">
    <button id="run">Run pipeline</button>
</div>
<pre id="output">Click "Run pipeline" to run for the given invoice_id. Result will appear here.</pre>

<script>
(function() {
    var output = document.getElementById('output');
    var invoiceIdEl = document.getElementById('invoice_id');
    var persistEl = document.getElementById('persist');
    var runBtn = document.getElementById('run');

    function run() {
        var invoiceId = (invoiceIdEl && invoiceIdEl.value) ? parseInt(invoiceIdEl.value, 10) : 0;
        if (!invoiceId || invoiceId < 1) {
            output.textContent = 'Please enter a valid invoice_id (integer >= 1).';
            return;
        }
        output.textContent = 'Loading...';
        runBtn.disabled = true;

        var persist = (persistEl && persistEl.checked) ? 1 : 0;
        var url = 'debug_matching.php?invoice_id=' + encodeURIComponent(invoiceId) + '&persist=' + persist;

        fetch(url, { method: 'GET', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                output.textContent = JSON.stringify(data, null, 2);
            })
            .catch(function(err) {
                output.textContent = 'Error: ' + err.message;
            })
            .finally(function() {
                runBtn.disabled = false;
            });
    }

    if (runBtn) runBtn.addEventListener('click', run);
})();
</script>
</body>
</html>
