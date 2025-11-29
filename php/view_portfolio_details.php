<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id      = $_SESSION['user_id'];
$portfolio_id = intval($_GET['portfolio_id'] ?? 0);

if ($portfolio_id <= 0) {
    die("Invalid portfolio.");
}

// verify user owns/subscribes to portfolio
$stmt = $conn->prepare("
    SELECT p.portfolio_name, p.description
    FROM user_portfolios up
    JOIN portfolios p ON up.portfolio_id = p.portfolio_id
    WHERE up.user_id = ? AND p.portfolio_id = ?
");
$stmt->bind_param("ii", $user_id, $portfolio_id);
$stmt->execute();
$portfolioInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$portfolioInfo) {
    die("Portfolio not found for this user.");
}

// sorting
$allowedSorts = [
    'symbol',
    'company_name',
    'date_of_purchase',
    'buy_price',
    'current_price',
    'quantity',
    'invested_amount',
    'current_value',
    'pl_amount',
    'pl_percent'
];

$sort  = 'symbol';
$order = 'asc';

if (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts, true)) {
    $sort = $_GET['sort'];
}
if (isset($_GET['order']) && in_array(strtolower($_GET['order']), ['asc', 'desc'], true)) {
    $order = strtolower($_GET['order']);
}

function sortArrowsDetails($column, $currentSort, $currentOrder, $portfolio_id) {
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $common  = ['portfolio_id' => $portfolio_id];

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

// fetch holdings
$stmt = $conn->prepare("
    SELECT
        symbol,
        company_name,
        date_of_purchase,
        buy_price,
        current_price,
        quantity,
        invested_amount,
        current_value,
        pl_amount,
        pl_percent
    FROM portfolio_holdings
    WHERE portfolio_id = ?
    ORDER BY `$sort` $order
");
$stmt->bind_param("i", $portfolio_id);
$stmt->execute();
$res = $stmt->get_result();

$holdings = [];
$total_invested = 0;
$total_current  = 0;
while ($row = $res->fetch_assoc()) {
    $holdings[] = $row;
    $total_invested += $row['invested_amount'];
    $total_current  += $row['current_value'];
}
$stmt->close();

$net_pl     = $total_current - $total_invested;
$net_pl_pct = ($total_invested > 0) ? ($net_pl / $total_invested) * 100 : 0.0;

$current_value_class = ($total_current >= $total_invested) ? 'text-success-thick' : 'text-danger-thick';
$pl_class            = ($net_pl >= 0) ? 'text-success-thick' : 'text-danger-thick';
$plp_class           = ($net_pl_pct >= 0) ? 'text-success-thick' : 'text-danger-thick';

date_default_timezone_set('Asia/Kolkata');
$yesterday = date('d M Y', strtotime('-1 day'));

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Portfolio Details - <?= htmlspecialchars($portfolioInfo['portfolio_name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/view_portfolio_details.css" />
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
                <li class="nav-item"><a class="nav-link active" href="view_portfolio.php">View Portfolio</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php" onclick="return confirm('Are you sure you want to logout?');">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<main class="main-content container my-4">

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h2 class="mb-0"><?= htmlspecialchars($portfolioInfo['portfolio_name']); ?></h2>

        <?php $can_update = true; // later replace with 30‑day check if you want ?>
        <form method="post" action="rebalance_portfolio.php"
            onsubmit="return <?= $can_update ? "confirm('Rebalance this portfolio based on latest 30-day returns?')" : "false"; ?>;">
            <input type="hidden" name="portfolio_id" value="<?= (int)$portfolio_id; ?>">
            <input type="hidden" name="no_of_stocks" value="<?= count($holdings); ?>">
            <button type="submit"
                    class="btn-update-pf <?= $can_update ? 'btn-update-available' : 'btn-update-disabled'; ?>"
                    <?= $can_update ? '' : 'disabled'; ?>>
                Update Portfolio
            </button>
        </form>
    </div>

    </h2>
    <p class="portfolio-desc mb-3"><?= nl2br(htmlspecialchars($portfolioInfo['description'] ?? '')); ?></p>

    <div class="summary-section mb-4">
        <div class="summary-row">
            <div class="summary-card">
                <h5>Total Invested</h5>
                <p>₹<?= number_format($total_invested, 2); ?></p>
            </div>
            <div class="summary-card">
                <h5 class="<?= $current_value_class; ?>">Current Value</h5>
                <p class="<?= $current_value_class; ?>">₹<?= number_format($total_current, 2); ?></p>
                <small>as of <?= $yesterday; ?></small>
            </div>
        </div>
        <div class="summary-row">
            <div class="summary-card">
                <h5 class="<?= $pl_class; ?>">Net P/L</h5>
                <p class="<?= $pl_class; ?>">₹<?= number_format($net_pl, 2); ?></p>
            </div>
            <div class="summary-card">
                <h5 class="<?= $plp_class; ?>">Net P/L (%)</h5>
                <p class="<?= $plp_class; ?>"><?= number_format($net_pl_pct, 2); ?>%</p>
            </div>
        </div>
    </div>

    <?php if (count($holdings) > 0): ?>
        <div class="table-responsive">
            <table class="portfolio-table">
                <thead>
                    <tr>
                        <th>Sr No.</th>
                        <th>Symbol<?= sortArrowsDetails('symbol', $sort, $order, $portfolio_id); ?></th>
                        <th>Company<?= sortArrowsDetails('company_name', $sort, $order, $portfolio_id); ?></th>
                        <th>Date of Purchase<?= sortArrowsDetails('date_of_purchase', $sort, $order, $portfolio_id); ?></th>
                        <th>Buy Price (₹)<?= sortArrowsDetails('buy_price', $sort, $order, $portfolio_id); ?></th>
                        <th>Current Price (₹)<?= sortArrowsDetails('current_price', $sort, $order, $portfolio_id); ?></th>
                        <th>Quantity<?= sortArrowsDetails('quantity', $sort, $order, $portfolio_id); ?></th>
                        <th>Invested Amount (₹)<?= sortArrowsDetails('invested_amount', $sort, $order, $portfolio_id); ?></th>
                        <th>Current Value (₹)<?= sortArrowsDetails('current_value', $sort, $order, $portfolio_id); ?></th>
                        <th>P/L (₹)<?= sortArrowsDetails('pl_amount', $sort, $order, $portfolio_id); ?></th>
                        <th>P/L (%)<?= sortArrowsDetails('pl_percent', $sort, $order, $portfolio_id); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sr = 1; ?>
                    <?php foreach ($holdings as $h):
                        $row_pl_class  = ($h['pl_amount'] >= 0)  ? 'text-success-thick' : 'text-danger-thick';
                        $row_plp_class = ($h['pl_percent'] >= 0) ? 'text-success-thick' : 'text-danger-thick';
                        $row_cv_class  = ($h['current_value'] >= $h['invested_amount']) ? 'text-success-thick' : 'text-danger-thick';
                    ?>
                        <tr>
                            <td><?= $sr++; ?></td>
                            <td><?= htmlspecialchars($h['symbol']); ?></td>
                            <td><?= htmlspecialchars($h['company_name']); ?></td>
                            <td><?= htmlspecialchars($h['date_of_purchase']); ?></td>
                            <td><?= number_format($h['buy_price'], 2); ?></td>
                            <td><?= number_format($h['current_price'], 2); ?></td>
                            <td><?= (int)$h['quantity']; ?></td>
                            <td><?= number_format($h['invested_amount'], 2); ?></td>
                            <td class="<?= $row_cv_class; ?>"><?= number_format($h['current_value'], 2); ?></td>
                            <td class="<?= $row_pl_class; ?>"><?= number_format($h['pl_amount'], 2); ?></td>
                            <td class="<?= $row_plp_class; ?>"><?= number_format($h['pl_percent'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No holdings in this portfolio.</p>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
