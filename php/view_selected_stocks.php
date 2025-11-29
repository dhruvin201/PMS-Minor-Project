<?php
session_start();
require_once './db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id = $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];

$indexSymbol      = $method === 'POST' ? ($_POST['symbol'] ?? '')      : ($_GET['symbol'] ?? '');
$numStocks        = $method === 'POST' ? intval($_POST['num_stocks'] ?? 0) : intval($_GET['num_stocks'] ?? 0);
$investmentAmount = $method === 'POST'
    ? floatval($_POST['total_capital'] ?? 0)
    : floatval($_GET['total_capital'] ?? 0);

if ($investmentAmount <= 0 && isset($_SESSION['investment_amount'])) {
    $investmentAmount = floatval($_SESSION['investment_amount']);
}

if (empty($indexSymbol) || $numStocks < 1 || $investmentAmount <= 0) {
    die("Invalid input.");
}

// Sorting
$allowedSorts = ['SYMBOL', 'LTP', 'QUANTITY', 'INVESTED_AMOUNT', 'ONE_YEAR_RETURN_PCT'];
$sort  = 'SYMBOL';
$order = 'asc';

if (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts, true)) {
    $sort = $_GET['sort'];
}
if (isset($_GET['order']) && in_array(strtolower($_GET['order']), ['asc', 'desc'], true)) {
    $order = strtolower($_GET['order']);
}

function sortArrowsPortfolio($column, $currentSort, $currentOrder) {
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');

    $common = [
        'symbol'        => $_GET['symbol']        ?? ($_POST['symbol']        ?? ''),
        'num_stocks'    => $_GET['num_stocks']    ?? ($_POST['num_stocks']    ?? ''),
        'total_capital' => $_GET['total_capital'] ?? ($_POST['total_capital'] ?? ($_SESSION['investment_amount'] ?? 0))
    ];

    $highlight = "#174EA6";
    $normal    = "inherit";

    $paramsAsc  = array_merge($common, ['sort' => $column, 'order' => 'asc']);
    $paramsDesc = array_merge($common, ['sort' => $column, 'order' => 'desc']);

    $styleUp = ($currentSort === $column && $currentOrder === 'asc')
        ? "color: $highlight; font-weight: bold;"
        : "color: $normal;";
    $styleDown = ($currentSort === $column && $currentOrder === 'desc')
        ? "color: $highlight; font-weight: bold;"
        : "color: $normal;";

    return
        '<span class="sort-arrows">' .
        '<a href="' . $baseUrl . '?' . http_build_query($paramsAsc)  . '" style="text-decoration:none; ' . $styleUp   . '" title="Sort ascending">&#9650;</a>' .
        '<a href="' . $baseUrl . '?' . http_build_query($paramsDesc) . '" style="text-decoration:none; margin-left:3px; ' . $styleDown . '" title="Sort descending">&#9660;</a>' .
        '</span>';
}

// ---------- Call FastAPI (cached in session) ----------
$apiUrl = "http://127.0.0.1:8000/scrape_index_csv";

if ($method === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'save_portfolio') ||
    !isset($_SESSION['last_pf']) ||
    $_SESSION['last_pf_index'] !== $indexSymbol ||
    $_SESSION['last_pf_amount'] != $investmentAmount ||
    $_SESSION['last_pf_n'] != $numStocks) {

    $payload = json_encode([
        "index_symbol"  => $indexSymbol,
        "no_of_stocks"  => $numStocks,
        "total_capital" => $investmentAmount
    ]);

    $opts = [
        "http" => [
            "method"  => "POST",
            "header"  => "Content-Type: application/json\r\n",
            "content" => $payload,
            "timeout" => 180
        ]
    ];

    $context  = stream_context_create($opts);
    $response = @file_get_contents($apiUrl, false, $context);

    if ($response === false) {
        die("Error calling Python API.");
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['success'])) {
        $err = $data['detail'] ?? 'Unknown error from API';
        die("Portfolio generation failed: " . htmlspecialchars($err));
    }

    $_SESSION['last_pf']        = $data;
    $_SESSION['last_pf_index']  = $indexSymbol;
    $_SESSION['last_pf_amount'] = $investmentAmount;
    $_SESSION['last_pf_n']      = $numStocks;
} else {
    $data = $_SESSION['last_pf'];
}

$portfolio      = $data['portfolio']       ?? [];
$totalInvested  = $data['total_invested']  ?? 0;
$freeCash       = $data['free_cash']       ?? 0;
$noOfStocksUsed = $data['no_of_stocks']    ?? $numStocks;

// Sort in PHP
if (!empty($portfolio)) {
    usort($portfolio, function ($a, $b) use ($sort, $order) {
        $av = $a[$sort];
        $bv = $b[$sort];
        if (is_numeric($av) && is_numeric($bv)) $cmp = $av <=> $bv;
        else $cmp = strcmp((string)$av, (string)$bv);
        return $order === 'asc' ? $cmp : -$cmp;
    });
}

// ---------- Save portfolio action ----------
if (isset($_POST['action']) && $_POST['action'] === 'save_portfolio') {
    $portfolio_name = $indexSymbol . " - " . date('Y-m-d H:i:s');
    $desc = "Auto-generated portfolio for index " . $indexSymbol;

    // 1. portfolios
    $stmt = $conn->prepare("INSERT INTO portfolios (portfolio_name, description, created_by) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $portfolio_name, $desc, $user_id);
    $stmt->execute();
    $new_portfolio_id = $stmt->insert_id;
    $stmt->close();

    // 2. user_portfolios
    $stmt = $conn->prepare("INSERT INTO user_portfolios (user_id, portfolio_id, total_invested) VALUES (?, ?, ?)");
    $stmt->bind_param("iid", $user_id, $new_portfolio_id, $totalInvested);
    $stmt->execute();
    $stmt->close();

    // 3. holdings
    $stmt = $conn->prepare("
        INSERT INTO portfolio_holdings
        (portfolio_id, symbol, company_name, date_of_purchase,
         buy_price, current_price, quantity,
         invested_amount, current_value, pl_amount, pl_percent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($portfolio as $row) {
        $symbol        = $row['SYMBOL'];
        $company_name  = null;
        $date_purchase = $row['DATE_OF_PURCHASE'];
        $buy_price     = $row['LTP'];
        $current_price = $row['LTP'];
        $quantity      = (int)$row['QUANTITY'];
        $invested_amt  = (float)$row['INVESTED_AMOUNT'];
        $current_value = $invested_amt;
        $pl_amount     = 0.0;
        $pl_percent    = 0.0;

        $stmt->bind_param(
            "isssddidddd",
            $new_portfolio_id,
            $symbol,
            $company_name,
            $date_purchase,
            $buy_price,
            $current_price,
            $quantity,
            $invested_amt,
            $current_value,
            $pl_amount,
            $pl_percent
        );
        $stmt->execute();
    }
    $stmt->close();
    $conn->close();

    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Generated Portfolio - <?= htmlspecialchars($indexSymbol) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/view_selected_stocks.css" />
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
                <li class="nav-item"><a class="nav-link" href="logout.php" onclick="return confirm('Are you sure you want to logout?');">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<main class="container my-4">
    <h3>Generated Portfolio for <?= htmlspecialchars($indexSymbol) ?></h3>

    <div class="portfolio-cards">
        <div class="summary-card">
            <div class="summary-label">Investment amount</div>
            <div class="summary-value primary">₹<?= number_format($investmentAmount, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Number of stocks selected</div>
            <div class="summary-value primary"><?= (int)$noOfStocksUsed ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total invested</div>
            <div class="summary-value primary">₹<?= number_format($totalInvested, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Uninvested cash</div>
            <div class="summary-value primary">₹<?= number_format($freeCash, 2) ?></div>
        </div>
    </div>

    <form method="post"
        action="view_selected_stocks.php"
        class="mb-4 text-center"
        onsubmit="return confirmSavePortfolio();">
        <input type="hidden" name="symbol" value="<?= htmlspecialchars($indexSymbol) ?>">
        <input type="hidden" name="num_stocks" value="<?= (int)$noOfStocksUsed ?>">
        <input type="hidden" name="total_capital" value="<?= htmlspecialchars($investmentAmount) ?>">
        <input type="hidden" name="action" value="save_portfolio">
        <button type="submit" class="btn-proceed">Save Portfolio</button>
    </form>

    <div class="table-responsive">
        <table class="scripts-table">
            <thead>
            <tr>
                <th>Sr No.</th>
                <th>Symbol<?= sortArrowsPortfolio('SYMBOL', $sort, $order); ?></th>
                <th>LTP (₹)<?= sortArrowsPortfolio('LTP', $sort, $order); ?></th>
                <th>Quantity<?= sortArrowsPortfolio('QUANTITY', $sort, $order); ?></th>
                <th>Invested Amount (₹)<?= sortArrowsPortfolio('INVESTED_AMOUNT', $sort, $order); ?></th>
                <th>1Y Return (%)<?= sortArrowsPortfolio('ONE_YEAR_RETURN_PCT', $sort, $order); ?></th>
                <th>Date of Purchase</th>
            </tr>
            </thead>
            <tbody>
            <?php $sr = 1; ?>
            <?php foreach ($portfolio as $row): ?>
                <tr>
                    <td><?= $sr++; ?></td>
                    <td><?= htmlspecialchars($row['SYMBOL']) ?></td>
                    <td><?= htmlspecialchars(number_format($row['LTP'], 2)) ?></td>
                    <td><?= htmlspecialchars((int)$row['QUANTITY']) ?></td>
                    <td><?= htmlspecialchars(number_format($row['INVESTED_AMOUNT'], 2)) ?></td>
                    <td><?= htmlspecialchars(number_format($row['ONE_YEAR_RETURN_PCT'], 2)) ?></td>
                    <td><?= htmlspecialchars($row['DATE_OF_PURCHASE']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmSavePortfolio() {
    return confirm('Do you want to save this portfolio to your account?');
}
</script>
</body>
</html>
