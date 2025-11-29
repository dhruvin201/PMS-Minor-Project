<?php
session_start();
require_once './db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$selectedIndex = $_GET['symbol'] ?? '';

if (empty($selectedIndex)) {
    die("No index specified.");
}

$stmt = $conn->prepare("SELECT url FROM indices WHERE symbol = ?");
$stmt->bind_param("s", $selectedIndex);
$stmt->execute();
$stmt->bind_result($csvUrl);
if (!$stmt->fetch()) {
    die("Invalid or unknown index selected.");
}
$stmt->close();

$csvContent = @file_get_contents($csvUrl);
if ($csvContent === false) {
    die("Failed to fetch the CSV from URL: " . htmlspecialchars($csvUrl));
}

// Parse CSV
$rows = array_map('str_getcsv', explode("\n", $csvContent));
$header = array_map('trim', array_shift($rows));

// Find header keys (case-insensitive)
function getHeaderKey(array $header, string $searchKey) {
    foreach ($header as $key) {
        if (strcasecmp($key, $searchKey) == 0) {
            return $key;
        }
    }
    return null;
}

$companyKey = getHeaderKey($header, 'Company Name');
$symbolKey = getHeaderKey($header, 'Symbol');

if (is_null($companyKey) || is_null($symbolKey)) {
    die("Error: CSV headers do not contain required 'Company Name' or 'Symbol' fields.");
}

$niftyStocks = [];
foreach ($rows as $row) {
    if (count($row) !== count($header)) continue;
    $entry = array_combine($header, $row);
    if (empty($entry[$companyKey]) || empty($entry[$symbolKey])) continue;
    
    $niftyStocks[] = [
        'Company Name' => $entry[$companyKey],
        'Symbol' => $entry[$symbolKey],
    ];
}

if (empty($niftyStocks)) {
    die("No stocks found in selected index.");
}

$totalStocks = count($niftyStocks);
$suggestedStocks = max(1, (int)ceil($totalStocks * 0.40)); // 20% of total stocks

$symbols = array_column($niftyStocks, 'Symbol');

// Fetch prices from database
$inList = implode(',', array_fill(0, count($symbols), '?'));
$sql = "SELECT SYMBOL, CLOSE_PRICE FROM bhavcopy_data WHERE SYMBOL IN ($inList)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('s', count($symbols)), ...$symbols);
$stmt->execute();
$result = $stmt->get_result();

$dbPrices = [];
while ($row = $result->fetch_assoc()) {
    $dbPrices[$row['SYMBOL']] = $row['CLOSE_PRICE'];
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title><?= htmlspecialchars($selectedIndex) ?> - Select Stocks</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/view_scripts.css" />
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top navbar-light bg-light shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">Portfolio System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-3">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="add_portfolio.php">Add Portfolio</a></li>
                <li class="nav-item"><a class="nav-link" href="view_portfolio.php">View Portfolio</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php" onclick="return confirmLogout()">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<main class="container" style="margin-top:50px;">
<main class="container index-layout" style="margin-top:50px;">
    <div class="row">
        <!-- Left: Selection card (sticky) -->
        <div class="col-lg-4 col-md-5 mb-4">
            <div class="stock-selection-wrapper">
                <div class="stock-selection-card sticky-selection">
                    <h4>Select Number of Stocks</h4>
                    <div class="info-badge">
                        <span class="label">Total Stocks in</span>
                        <span class="value"><?= htmlspecialchars($selectedIndex) ?>: <?= $totalStocks ?></span>
                    </div>

                    <form method="POST" action="view_selected_stocks.php" id="stockSelectionForm">
                        <input type="hidden" name="symbol" value="<?= htmlspecialchars($selectedIndex) ?>">
                        <div class="mb-3">
                            <label for="num_stocks" class="form-label fw-bold">Number of Stocks to Invest In</label>
                            <input
                                type="number"
                                class="form-control num-stocks-input"
                                id="num_stocks"
                                name="num_stocks"
                                min="1"
                                max="<?= $totalStocks ?>"
                                placeholder="Suggested: <?= $suggestedStocks ?>"
                                required
                            >
                        </div>
                        <button type="submit" class="btn btn-proceed w-100">Proceed</button>
                    </form>
                </div>
            </div>
        </div>


        <!-- Right: Table -->
        <div class="col-lg-8 col-md-7">
            <h5 class="index-stock-heading">
              Available Stocks in <?= htmlspecialchars($selectedIndex) ?>
            </h5>
            <div class="table-wrapper wide-table">
                <table class="scripts-table">
                    <thead>
                        <tr>
                            <th>Sr No.</th>
                            <th>Company Name</th>
                            <th>Symbol</th>
                            <th>Close Price (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sr = 1; ?>
                        <?php foreach ($niftyStocks as $stock):
                            $symbol = $stock['Symbol'];
                            $price  = $dbPrices[$symbol] ?? 'N/A';
                        ?>
                        <tr>
                            <td><?= $sr++; ?></td>
                            <td><?= htmlspecialchars($stock['Company Name']) ?></td>
                            <td><?= htmlspecialchars($symbol) ?></td>
                            <td><?= htmlspecialchars($price) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
function confirmLogout() {
    return confirm('Are you sure you want to logout?');
}

document.getElementById('stockSelectionForm').addEventListener('submit', function(e) {
    const input = document.getElementById('num_stocks');
    const numStocks = parseInt(input.value, 10);
    const maxStocks = <?= $totalStocks ?>;

    if (isNaN(numStocks) || numStocks < 1 || numStocks > maxStocks) {
        e.preventDefault();
        alert(`Please enter a number between 1 and ${maxStocks}.`);
        input.focus();
        return false;
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
