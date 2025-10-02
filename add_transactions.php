<?php require 'navigator.html'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>Add Transaction</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        :root{
            --red-dark:#B31E32;
            --red-main:#D4463B;
            --red-light:#FAE4D5;
            --off-white:#FFF8EB;
            --gray-light:#E3E5E0;
        }
        body{ margin:0; font-family:'Roboto',sans-serif; background:#fff; color:#222; }

        #content{ margin-top:90px; padding:30px; }

        h1{
            font-family:'Space Grotesk',sans-serif;
            color:var(--red-dark);
            margin-bottom:20px;
            text-align:center;
        }

        .form-card{
            background:#FFF8EB;
            border:1px solid var(--gray-light);
            border-radius:10px;
            padding:30px;
            max-width:800px;
            margin:0 auto;
            box-shadow:0 1px 3px rgba(0,0,0,.08);
        }

        .form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:20px;
            margin-bottom:20px;
        }

        label{
            font-family:'Montserrat',sans-serif;
            font-weight:600;
            display:block;
            margin-bottom:6px;
            color:#333;
        }

        .input-group{
            display:flex;
            align-items:center;
            border:1px solid var(--gray-light);
            border-radius:6px;
            background:#fff;
            padding:0 10px;
        }
        .input-group .material-icons-outlined{
            color:#888;
            margin-right:6px;
        }
        .input-group input,
        .input-group textarea{
            border:none;
            outline:none;
            flex:1;
            padding:10px;
            font-size:14px;
            font-family:'Roboto',sans-serif;
            background:transparent;
        }
        textarea{ resize:vertical; min-height:90px; }

        .full-width{ grid-column:1 / span 2; }

        .save-btn{
            display:block;
            width:100%;
            padding:12px;
            border:none;
            border-radius:6px;
            background:var(--red-main);
            color:#fff;
            font-family:'Montserrat',sans-serif;
            font-weight:600;
            cursor:pointer;
            font-size:15px;
            transition:.2s;
        }
        .save-btn:hover{ background:var(--red-dark); }
    </style>
</head>
<body>
<div id="content">
    <h1>Add Transaction</h1>

    <div class="form-card">
        <form method="post" action="">
            <div class="form-grid">
                <!-- Reference Number -->
                <div>
                    <label for="ref_number">Reference Number</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">tag</span>
                        <input type="text" id="ref_number" name="ref_number" placeholder="Enter Reference Number" required>
                    </div>
                </div>

                <!-- Ordering Name -->
                <div>
                    <label for="ordering_name">Ordering Name</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">person</span>
                        <input type="text" id="ordering_name" name="ordering_name" placeholder="Enter Ordering Name" required>
                    </div>
                </div>

                <!-- Transaction Date -->
                <div>
                    <label for="transaction_date">Transaction Date</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">event</span>
                        <input type="date" id="transaction_date" name="transaction_date" required>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label for="description">Description</label>
                    <div class="input-group" style="align-items:flex-start;">
                        <span class="material-icons-outlined">description</span>
                        <textarea id="description" name="description" placeholder="Enter Description..."></textarea>
                    </div>
                </div>

                <!-- Amount Paid -->
                <div class="full-width">
                    <label for="amount">Amount Paid</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">euro</span>
                        <input type="number" step="0.01" id="amount" name="amount" placeholder="Enter Amount" required>
                    </div>
                </div>
            </div>

                <button type="submit" class="save-btn">Save Transaction</button>
        </form>
    </div>
</div>

<script>
    const sidebar   = document.getElementById("sidebar");
    const content   = document.getElementById("content");

    function openSidebar(){
        sidebar.classList.add("open");
        content.classList.add("shifted");
        filterPanel.classList.add("shifted");
        overlay.classList.add("show");
    }
    function closeSidebar(){
        sidebar.classList.remove("open");
        content.classList.remove("shifted");
        filterPanel.classList.remove("shifted");
        overlay.classList.remove("show");
    }
    function toggleSidebar(){ sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); }
</script>
</body>
</html>

